<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_tags\TagAudit;
use Drupal\xedc_tags\TagCatalog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tags with no published content.
 */
final class UnusedActionForm extends AdminFormBase {

  public function __construct(
    private TagCatalog $catalog,
    private TagAudit $audit,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.catalog'),
      $container->get('xedc_tags.audit'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_unused';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->chrome($form, [
      'title' => $this->t('未使用标签'),
      'description' => $this->t('清理前请确认没有任何内容引用。'),
    ]);
    $ignored = [];
    foreach ($this->audit->decisions('unused') as $decision) {
      $ignored[(int) $decision->subject] = TRUE;
    }
    $rows = [];
    $cutoff = \Drupal::time()->getRequestTime() - 90 * 86400;
    foreach ($this->catalog->terms() as $term) {
      if ((int) $term['published_any'] > 0 || isset($ignored[(int) $term['tid']])) {
        continue;
      }
      $tid = (int) $term['tid'];
      $kind = (int) $term['all'] === 0 ? $this->t('没有关联内容') : $this->t('只关联未发布内容');
      if ((int) $term['created'] > 0 && (int) $term['created'] < $cutoff) {
        $kind .= '，' . $this->t('超过 90 天没有公开内容');
      }
      $rows[$tid] = [
        $term['name'],
        $kind,
        $this->date((int) $term['created']),
        (string) $term['all'],
        $term['latest'] ? $this->date((int) $term['latest']) : $this->t('无'),
        !empty($term['description']) ? $this->t('有') : $this->t('无'),
        $term['alias'],
        ['data' => ['#markup' => $this->operations($tid)]],
      ];
    }
    $form['intro'] = $this->callout((string) $this->t('删除只能在单独确认页进行。只要还有内容、菜单、跳转或父子引用，就不能删除。'), 'warning');
    foreach ($rows as $tid => &$row) {
      $name = (string) $row[0];
      $row[0] = ['data' => ['#markup' => $this->tagNameMarkup($name, (int) $tid)]];
    }
    unset($row);
    $form['table_wrap'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['xt-card', 'xt-card--flush', 'xt-table-wrap']],
      'tags' => [
        '#type' => 'tableselect',
        '#attributes' => ['class' => ['xt-table']],
        '#header' => [
          $this->t('标签'),
          $this->t('情况'),
          $this->t('创建时间'),
          $this->t('关联内容'),
          $this->t('最近关联内容'),
          $this->t('人工描述'),
          $this->t('公开 URL'),
          $this->t('操作'),
        ],
        '#options' => $rows,
        '#empty' => $this->t('没有待处理的未使用标签。'),
      ],
    ];
    if ($this->currentUser()->hasPermission('manage xedc tags') && $rows) {
      $form['batch'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['xt-batch-bar']],
        'confirm' => [
          '#type' => 'checkbox',
          '#title' => $this->t('我确认忽略选中的未使用建议'),
        ],
        'ignore' => [
          '#type' => 'submit',
          '#value' => $this->t('忽略选中'),
        ],
      ];
    }
    $form['#cache']['tags'] = $this->catalog->cacheTags();
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!array_filter((array) $form_state->getValue(['table_wrap', 'tags']))) {
      $form_state->setError($form['table_wrap']['tags'], $this->t('请先选择标签。'));
    }
    if (!$form_state->getValue(['batch', 'confirm'])) {
      $form_state->setError($form['batch']['confirm'], $this->t('请先确认。'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    foreach (array_filter(array_map('intval', (array) $form_state->getValue(['table_wrap', 'tags']))) as $tid) {
      $this->audit->decide('unused', (string) $tid, 'ignore');
    }
    $this->messenger()->addStatus($this->t('已忽略选中的未使用建议。'));
  }

  private function operations(int $tid): string {
    $links = [
      '<a href="' . Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $tid])->toString() . '">' . $this->t('编辑') . '</a>',
      '<a href="' . Url::fromRoute('xedc_tags.admin_term', ['taxonomy_term' => $tid])->toString() . '">' . $this->t('查看关联内容') . '</a>',
    ];
    if ($this->currentUser()->hasPermission('delete xedc tags')) {
      $links[] = '<a class="xt-ops--danger" href="' . Url::fromRoute('xedc_tags.admin_delete', ['taxonomy_term' => $tid])->toString() . '">' . $this->t('确认删除') . '</a>';
    }
    return '<div class="xt-ops">' . implode('', $links) . '</div>';
  }

}
