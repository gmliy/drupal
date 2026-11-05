<?php

declare(strict_types=1);

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_tags\TagCatalog;
use Drupal\xedc_tags\TagMerger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Preview and execute a tag merge. Source terms stay in place.
 */
final class MergeForm extends AdminFormBase {

  public function __construct(
    private TagMerger $merger,
    private TagCatalog $catalog,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.merger'),
      $container->get('xedc_tags.catalog'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_merge';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->chrome($form, [
      'title' => $this->t('标签合并'),
      'description' => $this->t('分步预览影响后再执行。默认不创建 301，来源标签不会被删除。'),
    ]);
    $options = [];
    foreach ($this->catalog->terms() as $term) {
      $options[(int) $term['tid']] = $term['name'] . ' (' . $term['tid'] . ')';
    }
    $request = $this->getRequest();
    $defaultSources = array_filter(array_map('intval', explode(',', (string) $request->query->get('sources', ''))));
    $defaultTarget = (int) $request->query->get('target', 0);
    $preview = $form_state->get('preview');
    $hasPreview = is_array($preview);
    $canExecute = $hasPreview && !empty($preview['ok']);

    $form['steps'] = [
      '#markup' => $this->stepsMarkup($hasPreview, $canExecute),
    ];

    $form['intro'] = $this->callout((string) $this->t('合并只迁移内容上的标签引用。目标标签的 URL 保持不变，描述也不会被覆盖。危险操作必须先预览、再勾选确认。'), 'warning');

    $form['select_panel'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xt-merge-panel']],
    ];
    $form['select_panel']['sources'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('第一步 · 选择来源标签'),
      '#description' => $this->t('来源标签的引用会被迁移到目标标签；术语本身保留。'),
      '#options' => $options,
      '#default_value' => $defaultSources,
      '#required' => TRUE,
      '#prefix' => '<div class="xt-merge-sources">',
      '#suffix' => '</div>',
    ];
    $form['select_panel']['target'] = [
      '#type' => 'select',
      '#title' => $this->t('第二步 · 选择目标标签'),
      '#options' => ['' => $this->t('请选择')] + $options,
      '#default_value' => $defaultTarget ?: '',
      '#required' => TRUE,
    ];
    $form['select_panel']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('第三步 · 预览影响'),
      '#submit' => ['::preview'],
      '#limit_validation_errors' => [['target'], ['sources']],
      '#attributes' => ['class' => ['button--primary']],
    ];

    if ($hasPreview) {
      $form['preview_panel'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['xt-merge-panel', $canExecute ? '' : 'xt-merge-panel--danger']],
      ];
      $form['preview_panel']['heading'] = [
        '#markup' => '<h2 class="xt-admin__section-title">' . $this->t('第四步 · 合并预览与影响范围') . '</h2>',
      ];
      $form['preview_panel']['report'] = $this->report($preview);

      if ($canExecute) {
        $form['confirm_panel'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['xt-merge-panel', 'xt-merge-panel--danger']],
        ];
        $form['confirm_panel']['heading'] = [
          '#markup' => '<h2 class="xt-admin__section-title">' . $this->t('第五步 · 人工确认后执行') . '</h2>',
        ];
        $form['confirm_panel']['add_redirects'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('为来源标签的现有 path alias 创建到目标标签的 301（默认关闭；不覆盖他人别名；来源术语仍保留）'),
          '#default_value' => FALSE,
          '#access' => \Drupal::moduleHandler()->moduleExists('redirect'),
        ];
        $form['confirm_panel']['confirm_redirect'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('我确认需要创建 301 跳转'),
          '#states' => [
            'visible' => [
              ':input[name="add_redirects"]' => ['checked' => TRUE],
            ],
            'required' => [
              ':input[name="add_redirects"]' => ['checked' => TRUE],
            ],
          ],
          '#access' => \Drupal::moduleHandler()->moduleExists('redirect'),
        ];
        $form['confirm_panel']['confirm'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('我已核对来源、目标、语言、URL、SEO 和影响数量，确认执行合并'),
        ];
        $form['confirm_panel']['execute'] = [
          '#type' => 'submit',
          '#value' => $this->t('执行合并'),
          '#submit' => ['::executeMerge'],
          '#attributes' => ['class' => ['button--danger']],
        ];
        $history = Url::fromRoute('xedc_tags.admin_history')->toString();
        $form['confirm_panel']['rollback_hint'] = [
          '#markup' => '<p class="xt-admin__note">' . $this->t('第六步 · 执行完成后可在 <a href="@url">操作历史</a> 查看结果并进入回滚。', ['@url' => $history]) . '</p>',
        ];
      }
    }

    $form['#cache']['tags'] = $this->catalog->cacheTags();
    return $form;
  }

  public function preview(array &$form, FormStateInterface $form_state): void {
    $preview = $this->merger->preview($this->sources($form_state), (int) $form_state->getValue('target'));
    unset($preview['entity_map']);
    $form_state->set('preview', $preview);
    $form_state->setRebuild(TRUE);
  }

  public function executeMerge(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->getValue('confirm')) {
      $form_state->setErrorByName('confirm', $this->t('请先确认影响范围。'));
      $form_state->setRebuild(TRUE);
      return;
    }
    $addRedirects = (bool) $form_state->getValue('add_redirects');
    if ($addRedirects && !$form_state->getValue('confirm_redirect')) {
      $form_state->setErrorByName('confirm_redirect', $this->t('创建 301 需要二次确认。'));
      $form_state->setRebuild(TRUE);
      return;
    }
    $result = $this->merger->execute(
      $this->sources($form_state),
      (int) $form_state->getValue('target'),
      (int) $this->currentUser()->id(),
      $addRedirects,
    );
    if ($result['ok']) {
      $this->messenger()->addStatus($result['message']);
      $form_state->setRedirect('xedc_tags.admin_history');
      return;
    }
    $this->messenger()->addError($result['message']);
    $form_state->setRebuild(TRUE);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * @return int[]
   */
  private function sources(FormStateInterface $form_state): array {
    return array_values(array_filter(array_map('intval', (array) $form_state->getValue('sources'))));
  }

  private function stepsMarkup(bool $hasPreview, bool $canExecute): string {
    $steps = [
      [TRUE, $this->t('选择来源')],
      [TRUE, $this->t('选择目标')],
      [$hasPreview, $this->t('预览影响')],
      [$canExecute, $this->t('确认执行')],
    ];
    $html = '<div class="xt-merge-steps" role="list">';
    foreach ($steps as $i => [$active, $label]) {
      $class = $active ? 'xt-merge-step is-active' : 'xt-merge-step';
      $html .= '<div class="' . $class . '" role="listitem">';
      $html .= '<span class="xt-merge-step__n">' . $this->t('步骤 @n', ['@n' => $i + 1]) . '</span>';
      $html .= '<span class="xt-merge-step__t">' . $label . '</span>';
      $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
  }

  /**
   * @param array<string, mixed> $preview
   */
  private function report(array $preview): array {
    $lines = [];
    foreach ($preview['errors'] as $error) {
      $lines[] = $this->t('不能执行：@text', ['@text' => $error]);
    }
    foreach ($preview['warnings'] as $warning) {
      $lines[] = $warning;
    }
    $target = $preview['target']['name'] ?? '';
    $sourceNames = array_map(static fn(array $term): string => (string) ($term['name'] ?? $term['tid']), $preview['sources']);
    $items = [
      $this->t('来源：@names', ['@names' => implode('、', $sourceNames)]),
      $this->t('目标：@name', ['@name' => $target]),
      $this->t('来源使用量合计见下方分布。目标已发布内容 @count。', ['@count' => (int) ($preview['target']['published_any'] ?? 0)]),
      $this->t('图片 已发布 @pub / 未发布 @draft', ['@pub' => $preview['photo'], '@draft' => $preview['photo_unpublished']]),
      $this->t('视频 已发布 @pub / 未发布 @draft', ['@pub' => $preview['video'], '@draft' => $preview['video_unpublished']]),
      $this->t('文章 已发布 @pub / 未发布 @draft', ['@pub' => $preview['article'], '@draft' => $preview['article_unpublished']]),
      $this->t('将改写 @count 个实体。其中 @already 个已经带有目标标签，不会写成重复引用。', [
        '@count' => $preview['entities'],
        '@already' => $preview['already'],
      ]),
      $this->t('Redirect 计划：默认不创建 301；勾选后才会为来源别名写入跳转。'),
    ];
    foreach ($preview['aliases'] as $tid => $aliases) {
      $text = is_array($aliases) ? implode('，', $aliases) : (string) $aliases;
      $items[] = $this->t('来源 @tid 的现有别名：@alias。这些别名不会批量修改。', ['@tid' => $tid, '@alias' => $text]);
    }
    return [
      '#theme' => 'item_list',
      '#items' => array_merge($items, $lines),
      '#prefix' => '<div class="xt-merge-preview">',
      '#suffix' => '</div>',
    ];
  }

}
