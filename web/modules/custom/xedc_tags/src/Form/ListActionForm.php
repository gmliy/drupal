<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_tags\TagCatalog;
use Drupal\xedc_tags\TagDuplicateDetector;
use Drupal\xedc_tags\TagHealthInspector;
use Drupal\xedc_tags\TagSeoInspector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filterable tag table. Deletes are not available here.
 */
final class ListActionForm extends AdminFormBase {

  public function __construct(
    private TagCatalog $catalog,
    private TagDuplicateDetector $duplicates,
    private TagSeoInspector $seo,
    private TagHealthInspector $health,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.catalog'),
      $container->get('xedc_tags.duplicates'),
      $container->get('xedc_tags.seo'),
      $container->get('xedc_tags.health'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_list';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->chrome($form, [
      'title' => $this->t('标签列表'),
      'description' => $this->t('搜索、筛选和管理现有标签。数量包含未发布内容；列表里不能直接删除。'),
    ]);
    $request = $this->getRequest()->query;
    $q = trim((string) $request->get('q', ''));
    $lang = (string) $request->get('lang', '');
    $sort = (string) $request->get('sort', 'count');
    $order = $request->get('order') === 'asc' ? 'asc' : 'desc';
    $rows = $this->rows($q, $lang, $sort, $order);
    $total = count($rows);
    $pager = \Drupal::service('pager.manager')->createPager($total, 20);
    $slice = array_slice($rows, $pager->getCurrentPage() * 20, 20);

    $form['intro'] = $this->callout((string) $this->t('当前筛选共 @count 个标签。数量含未发布内容；删除请从详情/未使用页单独确认。', ['@count' => $total]));

    $bundle = (string) $request->get('bundle', '');
    $health = (string) $request->get('health', '');
    $seoStatus = (string) $request->get('seo', '');
    $minUse = (string) $request->get('min_use', '');

    $form['filters'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['xt-filter-bar']],
    ];
    $form['filters']['q'] = [
      '#type' => 'textfield',
      '#title' => $this->t('关键词'),
      '#default_value' => $q,
      '#size' => 20,
      '#attributes' => ['placeholder' => $this->t('名称或 ID')],
    ];
    $form['filters']['lang'] = [
      '#type' => 'select',
      '#title' => $this->t('语言'),
      '#options' => ['' => $this->t('全部语言'), 'zh-hans' => '简体中文', 'en' => 'English', 'ja' => '日本語', 'ru' => 'Русский'],
      '#default_value' => $lang,
    ];
    $form['filters']['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('内容类型'),
      '#options' => ['' => $this->t('全部'), 'photo' => $this->t('图片'), 'video' => $this->t('视频'), 'article' => $this->t('文章')],
      '#default_value' => $bundle,
    ];
    $form['filters']['health'] = [
      '#type' => 'select',
      '#title' => $this->t('健康'),
      '#options' => ['' => $this->t('全部'), 'ok' => $this->t('正常'), 'warning' => $this->t('警告'), 'error' => $this->t('异常')],
      '#default_value' => $health,
    ];
    $form['filters']['seo'] = [
      '#type' => 'select',
      '#title' => $this->t('SEO'),
      '#options' => ['' => $this->t('全部'), 'ok' => $this->t('正常'), 'improve' => $this->t('建议完善'), 'noindex' => $this->t('建议 noindex'), 'merge' => $this->t('建议合并'), 'manual' => $this->t('需人工检查')],
      '#default_value' => $seoStatus,
    ];
    $form['filters']['min_use'] = [
      '#type' => 'number',
      '#title' => $this->t('最少使用'),
      '#default_value' => $minUse,
      '#min' => 0,
      '#size' => 5,
    ];
    $form['filters']['sort'] = [
      '#type' => 'select',
      '#title' => $this->t('排序'),
      '#options' => [
        'count' => $this->t('使用数量'),
        'created' => $this->t('创建时间'),
        'changed' => $this->t('更新时间'),
        'name' => $this->t('名称'),
      ],
      '#default_value' => $sort,
    ];
    $form['filters']['order'] = [
      '#type' => 'select',
      '#title' => $this->t('方向'),
      '#options' => ['desc' => $this->t('降序'), 'asc' => $this->t('升序')],
      '#default_value' => $order,
    ];
    $form['filters']['filter'] = [
      '#type' => 'submit',
      '#value' => $this->t('筛选'),
      '#submit' => ['::applyFilter'],
      '#limit_validation_errors' => [
        ['filters', 'q'],
        ['filters', 'lang'],
        ['filters', 'bundle'],
        ['filters', 'health'],
        ['filters', 'seo'],
        ['filters', 'min_use'],
        ['filters', 'sort'],
        ['filters', 'order'],
      ],
    ];
    $form['links'] = ['#markup' => $this->quickLinks()];

    $header = [
      'name' => $this->t('标签名称'),
      'all' => $this->t('使用数量'),
      'types' => $this->t('内容类型'),
      'status' => $this->t('健康状态'),
      'seo' => $this->t('SEO 状态'),
      'changed' => $this->t('更新时间'),
      'ops' => $this->t('操作'),
    ];
    $seoMap = $this->seo->all();
    $options = [];
    foreach ($slice as $term) {
      $tid = (int) $term['tid'];
      $seoRow = $seoMap[$tid] ?? NULL;
      $options[$tid] = [
        'name' => ['data' => ['#markup' => $this->tagNameMarkup((string) $term['name'], $tid)]],
        'all' => ['data' => ['#markup' => '<span class="xt-num">' . (int) $term['all'] . '</span>']],
        'types' => $this->typeSummary($term),
        'status' => ['data' => ['#markup' => $this->statusBadge($term)]],
        'seo' => ['data' => ['#markup' => $this->seoBadge($seoRow)]],
        'changed' => $this->date((int) ($term['changed'] ?? $term['created'])),
        'ops' => ['data' => ['#markup' => $this->operations($tid)]],
      ];
    }
    $form['table_wrap'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['xt-card', 'xt-card--flush', 'xt-table-wrap']],
    ];
    $form['table_wrap']['tags'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $options,
      '#empty' => $this->t('没有符合条件的标签。'),
      '#attributes' => ['class' => ['xt-table']],
    ];
    $form['pager'] = ['#type' => 'pager'];
    $form['batch'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['xt-batch-bar']],
    ];
    $form['batch']['action'] = [
      '#type' => 'select',
      '#title' => $this->t('对当前页选中的标签'),
      '#options' => [
        'merge' => $this->t('进入合并预览'),
        'not_duplicate' => $this->t('标记为非重复'),
      ],
    ];
    $form['batch']['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('我确认对当前页选中的标签执行这个操作'),
    ];
    $form['batch']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('执行'),
    ];
    $form['#cache']['tags'] = $this->catalog->cacheTags();
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $parents = $form_state->getTriggeringElement()['#parents'] ?? [];
    if (!in_array('submit', $parents, TRUE)) {
      return;
    }
    $selected = array_filter(array_map('intval', (array) $form_state->getValue(['table_wrap', 'tags'])));
    if (!$selected) {
      $form_state->setError($form['table_wrap']['tags'], $this->t('请先选择标签。'));
    }
    if (!$form_state->getValue(['batch', 'confirm'])) {
      $form_state->setError($form['batch']['confirm'], $this->t('请先确认。'));
    }
    if ($form_state->getValue(['batch', 'action']) === 'not_duplicate' && count($selected) < 2) {
      $form_state->setError($form['table_wrap']['tags'], $this->t('标记为非重复至少要选择两个标签。'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_values(array_filter(array_map('intval', (array) $form_state->getValue(['table_wrap', 'tags']))));
    if ($form_state->getValue(['batch', 'action']) === 'merge') {
      $query = ['sources' => implode(',', $selected)];
      if ($this->currentUser()->hasPermission('merge xedc tags')) {
        $form_state->setRedirect('xedc_tags.admin_merge', [], ['query' => $query]);
        return;
      }
      $this->messenger()->addError($this->t('当前账号不能执行合并。'));
      return;
    }
    $audit = \Drupal::service('xedc_tags.audit');
    $pairs = 0;
    $count = count($selected);
    for ($i = 0; $i < $count; $i++) {
      for ($j = $i + 1; $j < $count; $j++) {
        $audit->decide('duplicate', $audit->pairSubject($selected[$i], $selected[$j]), 'not_duplicate');
        $pairs++;
      }
    }
    $this->messenger()->addStatus($this->t('已把 @count 对标签标记为非重复。', ['@count' => $pairs]));
  }

  public function applyFilter(array &$form, FormStateInterface $form_state): void {
    $filters = (array) $form_state->getValue('filters');
    $query = array_filter([
      'q' => trim((string) ($filters['q'] ?? '')),
      'lang' => (string) ($filters['lang'] ?? ''),
      'bundle' => (string) ($filters['bundle'] ?? ''),
      'health' => (string) ($filters['health'] ?? ''),
      'seo' => (string) ($filters['seo'] ?? ''),
      'min_use' => (string) ($filters['min_use'] ?? ''),
      'sort' => (string) ($filters['sort'] ?? ''),
      'order' => (string) ($filters['order'] ?? ''),
    ], static fn($value): bool => $value !== '' && $value !== NULL);
    $kept = array_intersect_key($this->getRequest()->query->all(), array_flip(['unused', 'anomaly', 'used', 'created', 'changed', 'pending', 'freq']));
    $form_state->setRedirect('xedc_tags.admin_list', [], ['query' => $query + $kept]);
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function rows(string $q, string $lang, string $sort, string $order): array {
    $request = $this->getRequest()->query;
    $duplicate = [];
    foreach ($this->duplicates->groups() as $group) {
      foreach ($group['terms'] as $term) {
        $duplicate[(int) $term['tid']] = TRUE;
      }
    }
    $pending = array_flip($this->seo->openTids());
    $seoMap = $this->seo->all();
    $month = $this->catalog->monthStart();
    $summary = $this->catalog->summary();
    $threshold = $summary['frequency_threshold'] ?? ['high' => 0, 'low' => 0];
    $bundle = (string) $request->get('bundle', '');
    $healthFilter = (string) $request->get('health', '');
    $seoFilter = (string) $request->get('seo', '');
    $minUse = $request->get('min_use');
    $rows = [];
    foreach ($this->catalog->terms() as $term) {
      if ($q !== '') {
        $matched = (string) $term['tid'] === $q;
        foreach ($term['names'] as $name) {
          if (mb_stripos((string) $name, $q) !== FALSE) {
            $matched = TRUE;
          }
        }
        if (!$matched) {
          continue;
        }
      }
      if ($lang !== '' && !in_array($lang, $term['langcodes'], TRUE)) {
        continue;
      }
      if ($bundle !== '' && (int) ($term[$bundle] ?? 0) < 1) {
        continue;
      }
      if ($request->get('unused') && (int) $term['all'] > 0) {
        continue;
      }
      if ($request->get('used') && (int) $term['all'] < 1) {
        continue;
      }
      if ($request->get('anomaly') && !$this->seo->anomaly($term)) {
        continue;
      }
      if ($request->get('created') === 'month' && (int) $term['created'] < $month) {
        continue;
      }
      if ($request->get('changed') === 'month' && (int) $term['changed'] < $month) {
        continue;
      }
      if ($request->get('pending') && !isset($pending[(int) $term['tid']])) {
        continue;
      }
      if ($request->get('freq') === 'high' && ((int) $term['published_any'] < (int) $threshold['high'] || (int) $threshold['high'] <= 0)) {
        continue;
      }
      if ($request->get('freq') === 'low' && ((int) $term['published_any'] < 1 || (int) $term['published_any'] > (int) $threshold['low'])) {
        continue;
      }
      if ($minUse !== NULL && $minUse !== '' && (int) $term['all'] < (int) $minUse) {
        continue;
      }
      $healthStatus = $this->health->status($term);
      if ($healthFilter !== '' && $healthStatus !== $healthFilter) {
        continue;
      }
      if ($seoFilter !== '' && ($seoMap[(int) $term['tid']]['status'] ?? '') !== $seoFilter) {
        continue;
      }
      $term['_duplicate'] = isset($duplicate[(int) $term['tid']]);
      $term['_health'] = $healthStatus;
      $rows[] = $term;
    }
    usort($rows, function (array $a, array $b) use ($sort, $order): int {
      $result = match ($sort) {
        'created' => ((int) $a['created']) <=> ((int) $b['created']),
        'changed' => ((int) $a['changed']) <=> ((int) $b['changed']),
        'name' => strcmp((string) $a['name'], (string) $b['name']),
        default => ((int) $a['all']) <=> ((int) $b['all']),
      };
      return $order === 'asc' ? $result : -$result;
    });
    return $rows;
  }

  /**
   * @param array<string, mixed> $term
   */
  private function typeSummary(array $term): string {
    $parts = [];
    if ((int) $term['photo'] > 0) {
      $parts[] = $this->t('图 @n', ['@n' => $term['photo']]);
    }
    if ((int) $term['video'] > 0) {
      $parts[] = $this->t('视 @n', ['@n' => $term['video']]);
    }
    if ((int) $term['article'] > 0) {
      $parts[] = $this->t('文 @n', ['@n' => $term['article']]);
    }
    return $parts ? implode(' · ', $parts) : (string) $this->t('无');
  }

  /**
   * @param array<string, mixed> $term
   */
  private function statusBadge(array $term): string {
    $health = $term['_health'] ?? $this->health->status($term);
    if ($health === 'error') {
      return $this->badge((string) $this->t('异常'), 'danger');
    }
    if ($health === 'warning' || !empty($term['_duplicate'])) {
      return $this->badge((string) $this->t('警告'), 'warning');
    }
    if ((int) $term['all'] === 0) {
      return $this->badge((string) $this->t('未使用'), 'neutral');
    }
    return $this->badge((string) $this->t('正常'), 'success');
  }

  /**
   * @param array<string, mixed>|null $seo
   */
  private function seoBadge(?array $seo): string {
    if (!$seo) {
      return $this->badge((string) $this->t('未知'), 'neutral');
    }
    $tone = match ((string) ($seo['status'] ?? '')) {
      'ok' => 'success',
      'improve' => 'info',
      'noindex', 'merge' => 'warning',
      'manual' => 'danger',
      default => 'neutral',
    };
    return $this->badge((string) ($seo['label'] ?? $this->t('未知')), $tone);
  }

  private function operations(int $tid): string {
    $links = [
      $this->link('查看', 'xedc_tags.admin_term', $tid),
    ];
    $edit = Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $tid]);
    if ($edit->access($this->currentUser())) {
      $links[] = '<a href="' . $edit->toString() . '">' . $this->t('编辑') . '</a>';
    }
    $links[] = '<a href="' . Url::fromRoute('xedc_tags.admin_relations', [], ['query' => ['tid' => $tid]])->toString() . '">' . $this->t('关联') . '</a>';
    $links[] = '<a href="' . Url::fromRoute('xedc_tags.admin_seo', [], ['query' => ['tid' => $tid]])->toString() . '">' . $this->t('SEO') . '</a>';
    return '<div class="xt-ops">' . implode('', $links) . '</div>';
  }

  private function link(string $text, string $route, int $tid): string {
    return '<a href="' . Url::fromRoute($route, ['taxonomy_term' => $tid])->toString() . '">' . $this->t($text) . '</a>';
  }

  private function quickLinks(): string {
    $items = [
      'used' => $this->t('已使用'),
      'unused' => $this->t('未使用'),
      'anomaly' => $this->t('异常'),
      'pending' => $this->t('待处理'),
    ];
    $links = ['<a class="xt-chip" href="' . Url::fromRoute('xedc_tags.admin_list')->toString() . '">' . $this->t('全部') . '</a>'];
    foreach ($items as $key => $label) {
      $links[] = '<a class="xt-chip" href="' . Url::fromRoute('xedc_tags.admin_list', [], ['query' => [$key => 1]])->toString() . '">' . $label . '</a>';
    }
    $links[] = '<a class="xt-chip" href="' . Url::fromRoute('xedc_tags.admin_list', [], ['query' => ['created' => 'month']])->toString() . '">' . $this->t('本月新增') . '</a>';
    return '<div class="xt-chip-row">' . implode('', $links) . '</div>';
  }

}
