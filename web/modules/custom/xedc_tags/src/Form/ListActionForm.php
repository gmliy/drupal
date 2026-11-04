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
    $form = $this->chrome($form);
    $request = $this->getRequest()->query;
    $q = trim((string) $request->get('q', ''));
    $lang = (string) $request->get('lang', '');
    $sort = (string) $request->get('sort', 'count');
    $order = $request->get('order') === 'asc' ? 'asc' : 'desc';
    $rows = $this->rows($q, $lang, $sort, $order);
    $total = count($rows);
    $pager = \Drupal::service('pager.manager')->createPager($total, 20);
    $slice = array_slice($rows, $pager->getCurrentPage() * 20, 20);

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('共 @count 个标签。数量包含未发布内容。列表里不能直接删除。', ['@count' => $total]) . '</p>',
    ];
    $form['q'] = [
      '#type' => 'textfield',
      '#title' => $this->t('名称或 ID'),
      '#default_value' => $q,
      '#size' => 24,
    ];
    $bundle = (string) $request->get('bundle', '');
    $health = (string) $request->get('health', '');
    $seoStatus = (string) $request->get('seo', '');
    $minUse = (string) $request->get('min_use', '');
    $form['lang'] = [
      '#type' => 'select',
      '#title' => $this->t('语言'),
      '#options' => ['' => $this->t('全部语言'), 'zh-hans' => '简体中文', 'en' => 'English', 'ja' => '日本語', 'ru' => 'Русский'],
      '#default_value' => $lang,
    ];
    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('内容类型'),
      '#options' => ['' => $this->t('全部'), 'photo' => $this->t('图片'), 'video' => $this->t('视频'), 'article' => $this->t('文章')],
      '#default_value' => $bundle,
    ];
    $form['health'] = [
      '#type' => 'select',
      '#title' => $this->t('健康状态'),
      '#options' => ['' => $this->t('全部'), 'ok' => $this->t('正常'), 'warning' => $this->t('警告'), 'error' => $this->t('异常')],
      '#default_value' => $health,
    ];
    $form['seo'] = [
      '#type' => 'select',
      '#title' => $this->t('SEO 状态'),
      '#options' => ['' => $this->t('全部'), 'ok' => $this->t('正常'), 'improve' => $this->t('建议完善'), 'noindex' => $this->t('建议 noindex'), 'merge' => $this->t('建议合并'), 'manual' => $this->t('需人工检查')],
      '#default_value' => $seoStatus,
    ];
    $form['min_use'] = [
      '#type' => 'number',
      '#title' => $this->t('最少使用次数'),
      '#default_value' => $minUse,
      '#min' => 0,
      '#size' => 6,
    ];
    $form['sort'] = [
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
    $form['order'] = [
      '#type' => 'select',
      '#title' => $this->t('方向'),
      '#options' => ['desc' => $this->t('降序'), 'asc' => $this->t('升序')],
      '#default_value' => $order,
    ];
    $form['filter'] = [
      '#type' => 'submit',
      '#value' => $this->t('筛选'),
      '#submit' => ['::applyFilter'],
      '#limit_validation_errors' => [['q'], ['lang'], ['bundle'], ['health'], ['seo'], ['min_use'], ['sort'], ['order']],
    ];
    $form['links'] = ['#markup' => $this->quickLinks()];

    $header = [
      'name' => $this->t('标签名称'),
      'tid' => $this->t('ID'),
      'alias' => $this->t('别名'),
      'lang' => $this->t('语言'),
      'all' => $this->t('使用内容'),
      'photo' => $this->t('图片'),
      'video' => $this->t('视频'),
      'article' => $this->t('文章'),
      'created' => $this->t('创建时间'),
      'changed' => $this->t('更新时间'),
      'seo' => $this->t('SEO'),
      'status' => $this->t('健康'),
      'ops' => $this->t('操作'),
    ];
    $seoMap = $this->seo->all();
    $options = [];
    foreach ($slice as $term) {
      $tid = (int) $term['tid'];
      $options[$tid] = [
        'name' => $term['name'],
        'tid' => (string) $tid,
        'alias' => $term['alias'],
        'lang' => implode(', ', $term['langcodes']),
        'all' => (string) $term['all'],
        'photo' => (string) $term['photo'],
        'video' => (string) $term['video'],
        'article' => (string) $term['article'],
        'created' => $this->date((int) $term['created']),
        'changed' => $this->date((int) $term['changed']),
        'seo' => (string) ($seoMap[$tid]['label'] ?? $this->t('未知')),
        'status' => $this->statusLabel($term),
        'ops' => ['data' => ['#markup' => $this->operations($tid)]],
      ];
    }
    $form['tags'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $options,
      '#empty' => $this->t('没有符合条件的标签。'),
    ];
    $form['pager'] = ['#type' => 'pager'];
    $form['action'] = [
      '#type' => 'select',
      '#title' => $this->t('对当前页选中的标签'),
      '#options' => [
        'merge' => $this->t('进入合并预览'),
        'not_duplicate' => $this->t('标记为非重复'),
      ],
    ];
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('我确认对当前页选中的标签执行这个操作'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('执行'),
    ];
    $form['#cache']['tags'] = $this->catalog->cacheTags();
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement()['#parents'][0] ?? '';
    if ($trigger !== 'submit') {
      return;
    }
    $selected = array_filter(array_map('intval', (array) $form_state->getValue('tags')));
    if (!$selected) {
      $form_state->setErrorByName('tags', $this->t('请先选择标签。'));
    }
    if (!$form_state->getValue('confirm')) {
      $form_state->setErrorByName('confirm', $this->t('请先确认。'));
    }
    if ($form_state->getValue('action') === 'not_duplicate' && count($selected) < 2) {
      $form_state->setErrorByName('tags', $this->t('标记为非重复至少要选择两个标签。'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_values(array_filter(array_map('intval', (array) $form_state->getValue('tags'))));
    if ($form_state->getValue('action') === 'merge') {
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
    $query = array_filter([
      'q' => trim((string) $form_state->getValue('q')),
      'lang' => (string) $form_state->getValue('lang'),
      'bundle' => (string) $form_state->getValue('bundle'),
      'health' => (string) $form_state->getValue('health'),
      'seo' => (string) $form_state->getValue('seo'),
      'min_use' => (string) $form_state->getValue('min_use'),
      'sort' => (string) $form_state->getValue('sort'),
      'order' => (string) $form_state->getValue('order'),
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
  private function statusLabel(array $term): string {
    $health = $term['_health'] ?? $this->health->status($term);
    if ($health === 'error') {
      return (string) $this->t('异常');
    }
    if ($health === 'warning' || !empty($term['_duplicate'])) {
      return (string) $this->t('警告');
    }
    if ((int) $term['all'] === 0) {
      return (string) $this->t('未使用');
    }
    return (string) $this->t('正常');
  }

  private function operations(int $tid): string {
    $links = [
      $this->link('查看', 'xedc_tags.admin_term', $tid),
    ];
    $edit = Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $tid]);
    if ($edit->access($this->currentUser())) {
      $links[] = '<a href="' . $edit->toString() . '">' . $this->t('编辑') . '</a>';
    }
    $links[] = $this->link('关联内容', 'xedc_tags.admin_term', $tid);
    $links[] = '<a href="' . Url::fromRoute('xedc_tags.admin_relations', [], ['query' => ['tid' => $tid]])->toString() . '">' . $this->t('关联标签') . '</a>';
    $links[] = '<a href="' . Url::fromRoute('xedc_tags.admin_seo', [], ['query' => ['tid' => $tid]])->toString() . '">' . $this->t('SEO') . '</a>';
    $links[] = '<a href="' . Url::fromRoute('xedc_tags.admin_history', [], ['query' => ['tid' => $tid]])->toString() . '">' . $this->t('历史') . '</a>';
    return implode(' · ', $links);
  }

  private function link(string $text, string $route, int $tid): string {
    return '<a href="' . Url::fromRoute($route, ['taxonomy_term' => $tid])->toString() . '">' . $this->t($text) . '</a>';
  }

  private function quickLinks(): string {
    $items = [
      'used' => $this->t('只看已使用'),
      'unused' => $this->t('只看未使用'),
      'anomaly' => $this->t('只看异常'),
      'pending' => $this->t('只看待处理'),
    ];
    $links = ['<a href="' . Url::fromRoute('xedc_tags.admin_list')->toString() . '">' . $this->t('全部') . '</a>'];
    foreach ($items as $key => $label) {
      $links[] = '<a href="' . Url::fromRoute('xedc_tags.admin_list', [], ['query' => [$key => 1]])->toString() . '">' . $label . '</a>';
    }
    $links[] = '<a href="' . Url::fromRoute('xedc_tags.admin_list', [], ['query' => ['created' => 'month']])->toString() . '">' . $this->t('本月新增') . '</a>';
    return '<p class="xt-admin-links">' . implode(' · ', $links) . '</p>';
  }

}
