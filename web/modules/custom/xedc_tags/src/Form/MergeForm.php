<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
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
    $form = $this->chrome($form);
    $options = [];
    foreach ($this->catalog->terms() as $term) {
      $options[(int) $term['tid']] = $term['name'] . ' (' . $term['tid'] . ')';
    }
    $request = $this->getRequest();
    $defaultSources = array_filter(array_map('intval', explode(',', (string) $request->query->get('sources', ''))));
    $defaultTarget = (int) $request->query->get('target', 0);
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('合并只迁移内容上的标签引用。来源标签不会被删除，目标标签的 URL 保持不变，描述也不会被覆盖。') . '</p>',
    ];
    $form['target'] = [
      '#type' => 'select',
      '#title' => $this->t('目标标签'),
      '#options' => ['' => $this->t('请选择')] + $options,
      '#default_value' => $defaultTarget ?: '',
      '#required' => TRUE,
    ];
    $form['sources'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('来源标签'),
      '#options' => $options,
      '#default_value' => $defaultSources,
      '#required' => TRUE,
    ];
    $form['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('预览影响'),
      '#submit' => ['::preview'],
      '#limit_validation_errors' => [['target'], ['sources']],
    ];
    $preview = $form_state->get('preview');
    if (is_array($preview)) {
      $form['report'] = $this->report($preview);
      if (!empty($preview['ok'])) {
        $form['add_redirects'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('为来源标签的现有 path alias 创建到目标标签的 301（不覆盖他人别名；来源术语仍保留）'),
          '#default_value' => FALSE,
          '#access' => \Drupal::moduleHandler()->moduleExists('redirect'),
        ];
        $form['confirm'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('我已核对来源、目标、语言、URL、SEO 和影响数量，确认执行合并'),
        ];
        $form['execute'] = [
          '#type' => 'submit',
          '#value' => $this->t('执行合并'),
          '#submit' => ['::executeMerge'],
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
    $result = $this->merger->execute(
      $this->sources($form_state),
      (int) $form_state->getValue('target'),
      (int) $this->currentUser()->id(),
      (bool) $form_state->getValue('add_redirects'),
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
    ];
    foreach ($preview['aliases'] as $tid => $aliases) {
      $text = is_array($aliases) ? implode('，', $aliases) : (string) $aliases;
      $items[] = $this->t('来源 @tid 的现有别名：@alias。这些别名不会批量修改。', ['@tid' => $tid, '@alias' => $text]);
    }
    $build = [
      '#theme' => 'item_list',
      '#items' => array_merge($items, $lines),
    ];
    return $build;
  }

}
