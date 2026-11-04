<?php

namespace Drupal\xedc_tags\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Drupal\xedc_tags\TagAiAnalyzer;
use Drupal\xedc_tags\TagAudit;
use Drupal\xedc_tags\TagCatalog;
use Drupal\xedc_tags\TagDuplicateDetector;
use Drupal\xedc_tags\TagGuard;
use Drupal\xedc_tags\TagHealthInspector;
use Drupal\xedc_tags\TagRelations;
use Drupal\xedc_tags\TagSeoInspector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only pages for the tag center.
 */
final class AdminController extends ControllerBase {

  public function __construct(
    private TagCatalog $catalog,
    private TagDuplicateDetector $duplicates,
    private TagSeoInspector $seo,
    private TagRelations $relations,
    private TagAudit $audit,
    private TagGuard $guard,
    private TagHealthInspector $health,
    private TagAiAnalyzer $ai,
    private DateFormatterInterface $dates,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.catalog'),
      $container->get('xedc_tags.duplicates'),
      $container->get('xedc_tags.seo'),
      $container->get('xedc_tags.relations'),
      $container->get('xedc_tags.audit'),
      $container->get('xedc_tags.guard'),
      $container->get('xedc_tags.health'),
      $container->get('xedc_tags.ai'),
      $container->get('date.formatter'),
    );
  }

  public function overview(): array {
    $summary = $this->catalog->summary();
    $groups = $this->duplicates->groups();
    $open = $this->seo->openTids();
    $health = $this->health->report();
    $pendingMerge = 0;
    foreach ($groups as $group) {
      if (!empty($group['recommend_merge'])) {
        $pendingMerge++;
      }
    }
    $pendingAi = 0;
    foreach ($this->ai->stored(0, 100) as $item) {
      if (($item['status'] ?? '') === 'pending') {
        $pendingAi++;
      }
    }
    $anomalies = (int) $health['totals']['issues'];
    $metrics = [
      [$this->t('标签总数'), $summary['total'], 'xedc_tags.admin_list', []],
      [$this->t('已使用标签'), $summary['used'], 'xedc_tags.admin_list', ['used' => 1]],
      [$this->t('未使用标签'), $summary['unused'], 'xedc_tags.admin_unused', []],
      [$this->t('图片标签'), $summary['photo_tags'], 'xedc_tags.admin_list', ['bundle' => 'photo']],
      [$this->t('视频标签'), $summary['video_tags'], 'xedc_tags.admin_list', ['bundle' => 'video']],
      [$this->t('文章标签'), $summary['article_tags'], 'xedc_tags.admin_list', ['bundle' => 'article']],
      [$this->t('高频标签'), $summary['high_frequency'], 'xedc_tags.admin_list', ['freq' => 'high']],
      [$this->t('低频标签'), $summary['low_frequency'], 'xedc_tags.admin_list', ['freq' => 'low']],
      [$this->t('本月新增'), $summary['month_new'], 'xedc_tags.admin_list', ['created' => 'month']],
      [$this->t('本月修改'), $summary['month_changed'], 'xedc_tags.admin_list', ['changed' => 'month']],
      [$this->t('待处理异常'), $anomalies, 'xedc_tags.admin_health', []],
      [$this->t('待确认合并'), $pendingMerge, 'xedc_tags.admin_duplicates', []],
      [$this->t('待处理 SEO'), count($open), 'xedc_tags.admin_seo', []],
      [$this->t('待处理智能推荐'), $pendingAi, 'xedc_tags.admin_ai', []],
    ];
    $build = $this->page();
    $build['metrics'] = [
      '#theme' => 'xedc_tags_metrics',
      '#items' => [],
    ];
    foreach ($metrics as [$label, $value, $route, $query]) {
      $build['metrics']['#items'][] = [
        'label' => $label,
        'value' => $value,
        'url' => Url::fromRoute($route, [], ['query' => $query])->toString(),
      ];
    }
    $build['trend'] = [
      '#type' => 'table',
      '#caption' => $this->t('近 6 个月。标签数是新建术语，内容数是新发布且带标签的内容。没有记录的月份是 0。'),
      '#header' => [$this->t('月份'), $this->t('新建标签'), $this->t('新发布的带标签内容')],
      '#rows' => array_map(static fn(array $row): array => [$row['label'], $row['tags'], $row['content']], $summary['trend']),
    ];
    $terms = $this->catalog->terms();
    $popular = array_values(array_filter($terms, static fn(array $term): bool => (int) $term['published_any'] > 0));
    usort($popular, static fn(array $a, array $b): int => ((int) $b['published_any']) <=> ((int) $a['published_any']));
    $build['top'] = $this->termTable($this->t('已发布内容最多的标签'), array_slice($popular, 0, 10), 'published_any');
    $unused = array_values(array_filter($terms, static fn(array $term): bool => (int) $term['all'] === 0));
    $build['unused'] = $this->termTable($this->t('未使用标签'), array_slice($unused, 0, 10), 'all');
    $recent = $terms;
    usort($recent, static fn(array $a, array $b): int => ((int) $b['created']) <=> ((int) $a['created']));
    $build['recent'] = $this->termTable($this->t('最近新增标签'), array_slice($recent, 0, 8), 'created');
    $mergeRows = [];
    foreach ($this->audit->merges(8) as $row) {
      $mergeRows[] = [
        $this->dates->format((int) $row->created, 'custom', 'Y-m-d H:i'),
        $row->sources,
        $row->target_tid,
        $row->status,
        $row->moved,
      ];
    }
    $build['merges'] = [
      '#type' => 'table',
      '#caption' => $this->t('最近合并记录'),
      '#header' => [$this->t('时间'), $this->t('来源'), $this->t('目标'), $this->t('状态'), $this->t('迁移数')],
      '#rows' => $mergeRows,
      '#empty' => $this->t('还没有合并记录。'),
    ];
    $issueRows = [];
    $seo = $this->seo->all();
    foreach (array_slice($open, 0, 12) as $tid) {
      $row = $seo[$tid] ?? NULL;
      if (!$row) {
        continue;
      }
      $issueRows[] = [
        Link::fromTextAndUrl($row['name'], Url::fromRoute('xedc_tags.admin_term', ['taxonomy_term' => $tid])),
        $row['label'],
        implode('；', array_slice($row['reasons'], 0, 2)),
      ];
    }
    $build['issues'] = [
      '#type' => 'table',
      '#caption' => $this->t('待处理问题'),
      '#header' => [$this->t('标签'), $this->t('建议'), $this->t('原因')],
      '#rows' => $issueRows,
      '#empty' => $this->t('没有待处理问题。'),
    ];
    return $build;
  }

  public function relations(): array {
    $tid = (int) $this->getRequest()->query->get('tid', 0);
    $build = $this->page();
    $links = [];
    foreach ($this->catalog->terms() as $term) {
      $links[] = [
        '#type' => 'link',
        '#title' => $term['name'],
        '#url' => Url::fromRoute('xedc_tags.admin_relations', [], ['query' => ['tid' => $term['tid']]]),
        '#prefix' => '<span class="xt-admin-chip">',
        '#suffix' => '</span>',
      ];
    }
    $build['picker'] = $links;
    $build['note'] = [
      '#markup' => '<p>' . $this->t('关联强度是 Jaccard：共同内容数 / 两边内容并集。数字来自实际内容，后台这里包含未发布内容。') . '</p>',
    ];
    if ($tid <= 0) {
      $rows = [];
      $terms = $this->catalog->terms();
      foreach ($this->relations->topPairs(30, FALSE) as $pair) {
        $rows[] = [
          $terms[$pair['left']]['name'] ?? $pair['left'],
          $terms[$pair['right']]['name'] ?? $pair['right'],
          $pair['shared'],
          number_format($pair['jaccard'], 2),
        ];
      }
      $build['pairs'] = [
        '#type' => 'table',
        '#caption' => $this->t('关联较强的标签对'),
        '#header' => [$this->t('标签'), $this->t('关联标签'), $this->t('共现内容'), $this->t('Jaccard')],
        '#rows' => $rows,
        '#empty' => $this->t('还没有可计算的共现。'),
      ];
      return $build;
    }
    $terms = $this->catalog->terms();
    $rows = [];
    foreach ($this->relations->forTerm($tid, 20, FALSE) as $item) {
      $other = (int) $item['tid'];
      $rows[] = [
        $terms[$tid]['name'] ?? $tid,
        Link::fromTextAndUrl($terms[$other]['name'] ?? (string) $other, Url::fromRoute('xedc_tags.admin_relations', [], ['query' => ['tid' => $other]])),
        $item['shared'],
        number_format($item['jaccard'], 2),
        implode('、', $this->sharedTitles($tid, $other)),
      ];
    }
    $build['one'] = [
      '#type' => 'table',
      '#caption' => $this->t('@name 的关联', ['@name' => $terms[$tid]['name'] ?? $tid]),
      '#header' => [$this->t('当前标签'), $this->t('关联标签'), $this->t('共现内容'), $this->t('Jaccard'), $this->t('内容预览')],
      '#rows' => $rows,
      '#empty' => $this->t('没有关联标签。'),
    ];
    return $build;
  }

  public function seo(): array {
    $only = (int) $this->getRequest()->query->get('tid', 0);
    $status = (string) $this->getRequest()->query->get('status', '');
    $build = $this->page();
    $build['note'] = [
      '#markup' => '<p>' . $this->t('这里只给出建议，不会修改 Metatag、别名、canonical 或 sitemap。内容少本身不会被判成没有 SEO 价值；只有没有已发布内容时才建议人工考虑 noindex。') . '</p>',
    ];
    $rows = [];
    foreach ($this->seo->all() as $row) {
      if ($only && (int) $row['tid'] !== $only) {
        continue;
      }
      if ($status !== '' && $row['status'] !== $status) {
        continue;
      }
      $rows[] = [
        Link::fromTextAndUrl($row['name'], Url::fromRoute('xedc_tags.admin_term', ['taxonomy_term' => $row['tid']])),
        $row['label'],
        $row['published'],
        $row['alias'],
        $row['indexed'] ? $this->t('词汇已加入') : $this->t('词汇未加入'),
        implode('；', $row['reasons']),
      ];
    }
    $build['filters'] = ['#markup' => $this->seoLinks()];
    $build['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('标签'), $this->t('建议'), $this->t('已发布内容'), $this->t('别名'), $this->t('Sitemap'), $this->t('原因')],
      '#rows' => $rows,
      '#empty' => $this->t('没有符合条件的标签。'),
    ];
    $build['#cache']['tags'] = $this->catalog->cacheTags();
    return $build;
  }

  public function history(): array {
    $tid = (int) $this->getRequest()->query->get('tid', 0);
    $build = $this->page();
    $build['rollback'] = $this->formBuilder()->getForm('Drupal\xedc_tags\Form\RollbackForm');
    $rows = [];
    foreach ($this->audit->history(80, $tid) as $row) {
      $rows[] = [
        $this->dates->format((int) $row->created, 'custom', 'Y-m-d H:i'),
        $row->uid,
        $row->op,
        $row->tid ?: '',
        $row->message,
      ];
    }
    $build['log'] = [
      '#type' => 'table',
      '#caption' => $this->t('操作审计'),
      '#header' => [$this->t('时间'), $this->t('用户'), $this->t('操作'), $this->t('标签'), $this->t('说明')],
      '#rows' => $rows,
      '#empty' => $this->t('还没有操作记录。'),
    ];
    return $build;
  }

  public function termTitle(TermInterface $taxonomy_term): string {
    return (string) $taxonomy_term->label();
  }

  public function term(TermInterface $taxonomy_term): array {
    $tid = (int) $taxonomy_term->id();
    $terms = $this->catalog->terms();
    $term = $terms[$tid] ?? NULL;
    $build = $this->page();
    if (!$term) {
      $build['missing'] = ['#markup' => '<p>' . $this->t('找不到这个标签的统计。') . '</p>'];
      return $build;
    }
    $build['title'] = ['#markup' => '<h2>' . $this->t('@name', ['@name' => $term['name']]) . '</h2>'];
    $rows = [
      [$this->t('ID'), $tid],
      [$this->t('语言'), implode(', ', $term['langcodes'])],
      [$this->t('别名'), $term['alias']],
      [$this->t('使用内容'), $term['all']],
      [$this->t('已发布'), $term['published_any']],
      [$this->t('图片 / 视频 / 文章'), $term['photo'] . ' / ' . $term['video'] . ' / ' . $term['article']],
      [$this->t('人工描述'), $term['description'] ? $this->t('有') : $this->t('无')],
      [$this->t('创建'), $this->dates->format((int) $term['created'], 'custom', 'Y-m-d H:i')],
      [$this->t('更新'), $this->dates->format((int) $term['changed'], 'custom', 'Y-m-d H:i')],
    ];
    $build['facts'] = [
      '#type' => 'table',
      '#rows' => $rows,
    ];
    $seo = $this->seo->all()[$tid] ?? NULL;
    if ($seo) {
      $build['seo'] = [
        '#markup' => '<p>' . $this->t('SEO：@status。@reason', [
          '@status' => $seo['label'],
          '@reason' => implode('；', $seo['reasons']),
        ]) . '</p>',
      ];
    }
    $related = [];
    foreach ($this->relations->forTerm($tid, 10, FALSE) as $item) {
      $other = $terms[$item['tid']]['name'] ?? $item['tid'];
      $related[] = $other . '（' . $item['shared'] . '，Jaccard ' . number_format($item['jaccard'], 2) . '）';
    }
    $build['related'] = [
      '#markup' => '<p>' . $this->t('关联标签：@list', ['@list' => $related ? implode('、', $related) : $this->t('无')]) . '</p>',
    ];
    $content = [];
    $query = \Drupal::database()->select('node__field_tags', 't');
    $query->join('node_field_data', 'n', 'n.nid = t.entity_id');
    $query->condition('t.field_tags_target_id', $tid);
    $query->condition('t.deleted', 0);
    $query->fields('n', ['nid', 'title', 'type', 'status']);
    $query->orderBy('n.created', 'DESC');
    $query->range(0, 30);
    $query->distinct();
    foreach ($query->execute() as $row) {
      $content[] = [
        $row->nid,
        $row->title,
        $row->type,
        (int) $row->status === 1 ? $this->t('已发布') : $this->t('未发布'),
      ];
    }
    $build['content'] = [
      '#type' => 'table',
      '#caption' => $this->t('关联内容'),
      '#header' => ['ID', $this->t('标题'), $this->t('类型'), $this->t('状态')],
      '#rows' => $content,
      '#empty' => $this->t('没有关联内容。'),
    ];
    $build['refs'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('删除保护会检查的引用'),
      '#items' => array_map(static fn(array $item): string => $item['label'], $this->guard->blockers($tid)) ?: [$this->t('没有菜单或跳转引用。内容引用见上表。')],
    ];
    $build['#cache']['tags'] = array_merge($this->catalog->cacheTags(), ['taxonomy_term:' . $tid]);
    return $build;
  }

  private function page(): array {
    return [
      '#attached' => ['library' => ['xedc_tags/admin']],
      '#prefix' => '<div class="xt-admin">',
      '#suffix' => '</div>',
      'kicker' => [
        '#markup' => '<p class="xt-admin-kicker">' . $this->t('智能标签管理中心') . '</p>',
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args', 'languages:language_interface', 'languages:language_content'],
        'tags' => $this->catalog->cacheTags(),
      ],
    ];
  }

  /**
   * @param array<int, array<string, mixed>> $terms
   */
  private function termTable(string $caption, array $terms, string $field): array {
    $rows = [];
    foreach ($terms as $term) {
      $value = $field === 'created'
        ? $this->dates->format((int) $term['created'], 'custom', 'Y-m-d')
        : (string) $term[$field];
      $rows[] = [
        Link::fromTextAndUrl($term['name'], Url::fromRoute('xedc_tags.admin_term', ['taxonomy_term' => $term['tid']])),
        $value,
      ];
    }
    return [
      '#type' => 'table',
      '#caption' => $caption,
      '#header' => [$this->t('标签'), $field === 'created' ? $this->t('创建时间') : $this->t('数量')],
      '#rows' => $rows,
      '#empty' => $this->t('没有数据。'),
    ];
  }

  /**
   * @return string[]
   */
  private function sharedTitles(int $left, int $right): array {
    $query = \Drupal::database()->select('node__field_tags', 'a');
    $query->join('node__field_tags', 'b', 'b.entity_id = a.entity_id AND b.field_tags_target_id = :right AND b.deleted = 0', [':right' => $right]);
    $query->join('node_field_data', 'n', 'n.nid = a.entity_id');
    $query->condition('a.field_tags_target_id', $left);
    $query->condition('a.deleted', 0);
    $query->fields('n', ['title', 'status']);
    $query->range(0, 5);
    $query->distinct();
    $titles = [];
    foreach ($query->execute() as $row) {
      $titles[] = $row->title . ((int) $row->status === 1 ? '' : '（未发布）');
    }
    return $titles;
  }

  private function seoLinks(): string {
    $links = ['<a href="' . Url::fromRoute('xedc_tags.admin_seo')->toString() . '">' . $this->t('全部') . '</a>'];
    foreach (['ok' => '正常', 'improve' => '建议完善', 'noindex' => '建议 noindex', 'merge' => '建议合并', 'manual' => '需要人工检查'] as $status => $label) {
      $links[] = '<a href="' . Url::fromRoute('xedc_tags.admin_seo', [], ['query' => ['status' => $status]])->toString() . '">' . $this->t($label) . '</a>';
    }
    return '<p class="xt-admin-links">' . implode(' · ', $links) . '</p>';
  }

  private function getRequest(): \Symfony\Component\HttpFoundation\Request {
    return \Drupal::request();
  }

}
