<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_tags\TagAudit;
use Drupal\xedc_tags\TagCatalog;
use Drupal\xedc_tags\TagDuplicateDetector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Review duplicate groups. Nothing is merged from this form.
 */
final class DuplicateActionForm extends AdminFormBase {

  public function __construct(
    private TagDuplicateDetector $duplicates,
    private TagCatalog $catalog,
    private TagAudit $audit,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.duplicates'),
      $container->get('xedc_tags.catalog'),
      $container->get('xedc_tags.audit'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_duplicates';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->chrome($form, [
      'title' => $this->t('重复标签检测'),
      'description' => $this->t('发现近似重复标签，并在确认后进入合并预览。'),
    ]);
    $form['intro'] = $this->callout((string) $this->t('完全相同和规范化后相同的名称会建议合并，但仍要人工确认。词序、包含关系和字符相近只作提示，不代表可以合并。本页不调用语义模型。'));
    $groups = $this->duplicates->groups();
    if (!$groups) {
      $form['empty'] = ['#markup' => '<div class="xt-card"><p class="xt-empty">' . $this->t('当前没有待处理的重复或相似组。') . '</p></div>'];
      return $form;
    }
    $previews = $this->previews($groups);
    $canMerge = $this->currentUser()->hasPermission('merge xedc tags');
    $canManage = $this->currentUser()->hasPermission('manage xedc tags');
    foreach ($groups as $group) {
      $id = preg_replace('/[^a-z0-9_-]/i', '', (string) $group['id']) ?: 'group';
      $rows = [];
      foreach ($group['terms'] as $term) {
        $tid = (int) $term['tid'];
        $rows[] = [
          ['data' => ['#markup' => $this->tagNameMarkup((string) $term['name'], $tid)]],
          ['data' => ['#markup' => '<span class="xt-num">' . (int) $term['all'] . '</span>']],
          $this->t('图 @p · 视 @v · 文 @a', [
            '@p' => (int) $term['photo'],
            '@v' => (int) $term['video'],
            '@a' => (int) $term['article'],
          ]),
          implode('、', array_slice($previews[$tid] ?? [], 0, 3)) ?: '—',
          $group['keep_tid'] === $tid ? ['data' => ['#markup' => $this->badge((string) $this->t('建议保留'), 'success')]] : '—',
        ];
      }
      $form['groups'][$id] = [
        '#type' => 'details',
        '#title' => implode('、', $group['reasons']),
        '#open' => TRUE,
      ];
      $form['groups'][$id]['meta'] = $this->callout((string) $this->t('风险：@risk', ['@risk' => $group['risk']]), 'warning');
      $form['groups'][$id]['table'] = [
        '#type' => 'table',
        '#attributes' => ['class' => ['xt-table']],
        '#header' => [$this->t('标签'), $this->t('使用数量'), $this->t('内容类型'), $this->t('内容预览'), $this->t('建议')],
        '#rows' => $rows,
      ];
      $tids = array_map(static fn(array $term): int => (int) $term['tid'], $group['terms']);
      if ($canMerge) {
        $query = ['sources' => implode(',', array_diff($tids, [(int) $group['keep_tid']]))];
        if ($group['keep_tid']) {
          $query['target'] = (int) $group['keep_tid'];
          $query['sources'] = implode(',', array_values(array_diff($tids, [(int) $group['keep_tid']])));
        }
        else {
          $query = ['sources' => implode(',', $tids)];
        }
        $form['groups'][$id]['merge'] = [
          '#type' => 'link',
          '#title' => $this->t('进入合并'),
          '#url' => Url::fromRoute('xedc_tags.admin_merge', [], ['query' => $query]),
        ];
      }
      if ($canManage) {
        $form['groups'][$id]['pair'] = [
          '#type' => 'hidden',
          '#value' => implode(',', $tids),
        ];
        $form['groups'][$id]['ignore'] = [
          '#type' => 'submit',
          '#name' => 'ignore_' . $id,
          '#value' => $this->t('忽略建议'),
          '#group_tids' => $tids,
          '#decision' => 'ignore',
          '#submit' => ['::decide'],
          '#limit_validation_errors' => [],
        ];
        $form['groups'][$id]['keep'] = [
          '#type' => 'submit',
          '#name' => 'keep_' . $id,
          '#value' => $this->t('标记为非重复'),
          '#group_tids' => $tids,
          '#decision' => 'not_duplicate',
          '#submit' => ['::decide'],
          '#limit_validation_errors' => [],
        ];
      }
      foreach ($tids as $tid) {
        $form['groups'][$id]['content_' . $tid] = [
          '#type' => 'link',
          '#title' => $this->t('查看 @name 的内容', ['@name' => $this->catalog->terms()[$tid]['name'] ?? $tid]),
          '#url' => Url::fromRoute('xedc_tags.admin_term', ['taxonomy_term' => $tid]),
        ];
      }
    }
    $form['#cache']['tags'] = $this->catalog->cacheTags();
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

  public function decide(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $tids = array_map('intval', $trigger['#group_tids'] ?? []);
    $decision = (string) ($trigger['#decision'] ?? 'ignore');
    $count = count($tids);
    for ($i = 0; $i < $count; $i++) {
      for ($j = $i + 1; $j < $count; $j++) {
        $this->audit->decide('duplicate', $this->audit->pairSubject($tids[$i], $tids[$j]), $decision);
      }
    }
    $this->messenger()->addStatus($decision === 'not_duplicate' ? $this->t('已标记为非重复。') : $this->t('已忽略这组建议。'));
  }

  /**
   * @param array<int, array<string, mixed>> $groups
   * @return array<int, string[]>
   */
  private function previews(array $groups): array {
    $tids = [];
    foreach ($groups as $group) {
      foreach ($group['terms'] as $term) {
        $tids[] = (int) $term['tid'];
      }
    }
    $tids = array_values(array_unique($tids));
    if (!$tids) {
      return [];
    }
    $query = \Drupal::database()->select('node__field_tags', 't');
    $query->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.status = 1');
    $query->condition('t.deleted', 0);
    $query->condition('t.field_tags_target_id', $tids, 'IN');
    $query->fields('n', ['nid', 'title']);
    $query->addField('t', 'field_tags_target_id', 'tid');
    $query->orderBy('n.created', 'DESC');
    $query->range(0, 200);
    $out = [];
    foreach ($query->execute() as $row) {
      $tid = (int) $row->tid;
      if (count($out[$tid] ?? []) >= 3) {
        continue;
      }
      $out[$tid][] = (string) $row->title;
    }
    return $out;
  }

}
