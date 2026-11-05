<?php

declare(strict_types=1);

namespace Drupal\xedc_tags\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;
use Drupal\xedc_tags\TagAiAnalyzer;
use Drupal\xedc_tags\TagAudit;
use Drupal\xedc_tags\TagCatalog;
use Drupal\xedc_tags\TagDuplicateDetector;
use Drupal\xedc_tags\TagGuard;
use Drupal\xedc_tags\TagHealthInspector;
use Drupal\xedc_tags\TagLabels;
use Drupal\xedc_tags\TagRelations;
use Drupal\xedc_tags\TagSeoInspector;
use Drupal\xedc_tags\TagTaskManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
    private TagTaskManager $tasks,
    private TagLabels $labels,
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
      $container->get('xedc_tags.tasks'),
      $container->get('xedc_tags.labels'),
      $container->get('date.formatter'),
    );
  }

  public function overview(): array|RedirectResponse {
    if ((string) $this->getRequest()->query->get('refresh') === '1') {
      Cache::invalidateTags($this->catalog->cacheTags());
      $this->messenger()->addStatus($this->t('统计已刷新。'));
      return $this->redirect('xedc_tags.admin_overview');
    }

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
    $anomalies = (int) ($health['totals']['issues'] ?? 0);
    $pendingSuggestions = $anomalies + $pendingMerge + count($open) + $pendingAi;

    $metrics = [
      [$this->t('标签总数'), $summary['total'], 'xedc_tags.admin_list', []],
      [$this->t('图片标签使用'), $summary['photo_tags'], 'xedc_tags.admin_list', ['bundle' => 'photo']],
      [$this->t('视频标签使用'), $summary['video_tags'], 'xedc_tags.admin_list', ['bundle' => 'video']],
      [$this->t('文章标签使用'), $summary['article_tags'], 'xedc_tags.admin_list', ['bundle' => 'article']],
      [$this->t('高频标签'), $summary['high_frequency'], 'xedc_tags.admin_list', ['freq' => 'high']],
      [$this->t('低频标签'), $summary['low_frequency'], 'xedc_tags.admin_list', ['freq' => 'low']],
      [$this->t('孤立标签'), $summary['unused'], 'xedc_tags.admin_unused', []],
      [$this->t('待处理建议'), $pendingSuggestions, 'xedc_tags.admin_health', []],
    ];

    $metricItems = [];
    foreach ($metrics as [$label, $value, $route, $query]) {
      $metricItems[] = [
        'label' => $label,
        'value' => $value,
        'url' => Url::fromRoute($route, [], ['query' => $query])->toString(),
      ];
    }

    $scan = $this->scanMeta();
    $canRun = $this->currentUser()->hasPermission('run xedc tag analysis');

    $build = [
      '#theme' => 'xedc_tags_admin_dashboard',
      '#metrics' => $metricItems,
      '#shortcuts' => $this->shortcuts(),
      '#activity' => $this->recentActivity(),
      '#scan' => $scan,
      '#refresh_url' => Url::fromRoute('xedc_tags.admin_overview', [], ['query' => ['refresh' => 1]])->toString(),
      '#scan_url' => $canRun ? Url::fromRoute('xedc_tags.admin_tasks')->toString() : '',
      '#scan_locked' => !$canRun,
      '#attached' => ['library' => ['xedc_tags/admin']],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args', 'languages:language_interface', 'languages:language_content'],
        'tags' => $this->catalog->cacheTags(),
        'max-age' => 0,
      ],
    ];
    return $build;
  }

  public function relations(): array {
    $tid = (int) $this->getRequest()->query->get('tid', 0);
    $build = $this->page(
      (string) $this->t('标签关联分析'),
      (string) $this->t('基于内容共现计算标签关联强度，用于合并与推荐参考。')
    );
    $terms = $this->catalog->terms();
    $sorted = array_values($terms);
    usort($sorted, static fn(array $a, array $b): int => ((int) $b['published_any']) <=> ((int) $a['published_any']));
    $links = [];
    foreach (array_slice($sorted, 0, 24) as $term) {
      $classes = ['xt-chip'];
      if ((int) $term['tid'] === $tid) {
        $classes[] = 'is-active';
      }
      $links[] = [
        '#type' => 'link',
        '#title' => $term['name'],
        '#url' => Url::fromRoute('xedc_tags.admin_relations', [], ['query' => ['tid' => $term['tid']]]),
        '#attributes' => ['class' => $classes],
      ];
    }
    $build['picker'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xt-chip-row']],
      'links' => $links,
    ];
    $build['note'] = [
      '#markup' => '<div class="xt-callout xt-callout--info"><p>' . $this->t('关联强度 = 共同内容数 ÷ 两边内容并集（Jaccard）。后台统计包含未发布内容。上方展示使用量较高的标签，点击后查看其关联。') . '</p></div>',
    ];
    if ($tid <= 0) {
      $rows = [];
      foreach ($this->relations->topPairs(30, FALSE) as $pair) {
        $rows[] = [
          $terms[$pair['left']]['name'] ?? $pair['left'],
          $terms[$pair['right']]['name'] ?? $pair['right'],
          ['data' => ['#markup' => '<span class="xt-num">' . (int) $pair['shared'] . '</span>']],
          number_format($pair['jaccard'], 2),
        ];
      }
      $build['pairs_wrap'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['xt-card', 'xt-card--flush', 'xt-table-wrap']],
        'pairs' => [
          '#type' => 'table',
          '#attributes' => ['class' => ['xt-table']],
          '#caption' => $this->t('关联较强的标签对'),
          '#header' => [$this->t('标签'), $this->t('关联标签'), $this->t('共现内容'), $this->t('关联强度')],
          '#rows' => $rows,
          '#empty' => $this->t('还没有可计算的共现。'),
        ],
      ];
      return $build;
    }
    $rows = [];
    foreach ($this->relations->forTerm($tid, 20, FALSE) as $item) {
      $other = (int) $item['tid'];
      $rows[] = [
        $terms[$tid]['name'] ?? $tid,
        Link::fromTextAndUrl($terms[$other]['name'] ?? (string) $other, Url::fromRoute('xedc_tags.admin_relations', [], ['query' => ['tid' => $other]])),
        ['data' => ['#markup' => '<span class="xt-num">' . (int) $item['shared'] . '</span>']],
        number_format($item['jaccard'], 2),
        implode('、', $this->sharedTitles($tid, $other)) ?: '—',
      ];
    }
    $build['one_wrap'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xt-card', 'xt-card--flush', 'xt-table-wrap']],
      'one' => [
        '#type' => 'table',
        '#attributes' => ['class' => ['xt-table']],
        '#caption' => $this->t('@name 的关联', ['@name' => $terms[$tid]['name'] ?? $tid]),
        '#header' => [$this->t('当前标签'), $this->t('关联标签'), $this->t('共现内容'), $this->t('关联强度'), $this->t('内容预览')],
        '#rows' => $rows,
        '#empty' => $this->t('没有关联标签。'),
      ],
    ];
    return $build;
  }

  public function seo(): array {
    $only = (int) $this->getRequest()->query->get('tid', 0);
    $status = (string) $this->getRequest()->query->get('status', '');
    $build = $this->page(
      (string) $this->t('标签 SEO 检查'),
      (string) $this->t('只给出建议，不会修改 Metatag、别名、canonical 或 sitemap。')
    );
    $build['note'] = [
      '#markup' => '<div class="xt-callout xt-callout--info"><p>' . $this->t('内容少本身不会被判成没有 SEO 价值；只有没有已发布内容时才建议人工考虑 noindex。本页只给建议，不会改 Metatag 或 sitemap。') . '</p></div>',
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
        ['data' => ['#markup' => $this->seoBadge($row['status'], (string) $row['label'])]],
        ['data' => ['#markup' => '<span class="xt-num">' . (int) $row['published'] . '</span>']],
        $row['alias'] !== '' ? $row['alias'] : '—',
        $row['indexed'] ? $this->t('词汇已加入') : $this->t('词汇未加入'),
        implode('；', $row['reasons']) ?: '—',
      ];
    }
    $build['filters'] = ['#markup' => $this->seoLinks()];
    $build['table_wrap'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xt-card', 'xt-card--flush', 'xt-table-wrap']],
      'table' => [
        '#type' => 'table',
        '#attributes' => ['class' => ['xt-table']],
        '#header' => [$this->t('标签'), $this->t('建议'), $this->t('已发布内容'), $this->t('别名'), $this->t('Sitemap'), $this->t('原因')],
        '#rows' => $rows,
        '#empty' => $this->t('没有符合条件的标签。'),
      ],
    ];
    $build['#cache']['tags'] = $this->catalog->cacheTags();
    return $build;
  }

  public function history(): array {
    $tid = (int) $this->getRequest()->query->get('tid', 0);
    $build = $this->page(
      (string) $this->t('操作历史'),
      (string) $this->t('审计日志与合并回滚入口。不会改写生产标签数据，除非你主动执行回滚。')
    );
    $build['rollback'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xt-panel', 'xt-panel--danger']],
      'heading' => ['#markup' => '<h2 class="xt-panel__title">' . $this->t('合并回滚') . '</h2>'],
      'form' => $this->formBuilder()->getForm('Drupal\xedc_tags\Form\RollbackForm'),
    ];
    $rows = [];
    foreach ($this->audit->history(80, $tid) as $row) {
      $rows[] = [
        $this->dates->format((int) $row->created, 'custom', 'Y-m-d H:i'),
        $this->actorName((int) $row->uid),
        (string) $this->labels->operation((string) $row->op),
        $this->tagLabel((int) $row->tid),
        $row->message !== '' ? $row->message : '—',
      ];
    }
    $build['log_wrap'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xt-card', 'xt-card--flush', 'xt-table-wrap']],
      'log' => [
        '#type' => 'table',
        '#attributes' => ['class' => ['xt-table']],
        '#caption' => $this->t('操作审计'),
        '#header' => [$this->t('时间'), $this->t('操作者'), $this->t('操作'), $this->t('标签'), $this->t('说明')],
        '#rows' => $rows,
        '#empty' => $this->t('还没有操作记录。'),
      ],
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
    $build = $this->page(
      (string) ($term['name'] ?? $taxonomy_term->label()),
      (string) $this->t('标签详情、引用与删除保护检查。')
    );
    if (!$term) {
      $build['missing'] = ['#markup' => '<p class="xt-empty">' . $this->t('找不到这个标签的统计。') . '</p>'];
      return $build;
    }
    $facts = [
      [$this->t('Term ID'), '#' . $tid],
      [$this->t('语言'), implode('、', $term['langcodes']) ?: '—'],
      [$this->t('公开别名'), $term['alias'] !== '' ? $term['alias'] : '—'],
      [$this->t('使用内容'), (string) $term['all']],
      [$this->t('已发布'), (string) $term['published_any']],
      [$this->t('图片 / 视频 / 文章'), $term['photo'] . ' / ' . $term['video'] . ' / ' . $term['article']],
      [$this->t('人工描述'), $term['description'] ? $this->t('有') : $this->t('无')],
      [$this->t('创建时间'), $this->dates->format((int) $term['created'], 'custom', 'Y-m-d H:i')],
      [$this->t('更新时间'), $this->dates->format((int) $term['changed'], 'custom', 'Y-m-d H:i')],
    ];
    $dl = '<dl class="xt-facts">';
    foreach ($facts as [$label, $value]) {
      $dl .= '<dt>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</dt>';
      $dl .= '<dd>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</dd>';
    }
    $dl .= '</dl>';
    $build['facts'] = ['#markup' => $dl];
    $seo = $this->seo->all()[$tid] ?? NULL;
    if ($seo) {
      $build['seo'] = [
        '#markup' => '<div class="xt-callout xt-callout--info"><p>' . $this->t('SEO：@status。@reason', [
          '@status' => $seo['label'],
          '@reason' => implode('；', $seo['reasons']),
        ]) . '</p></div>',
      ];
    }
    $related = [];
    foreach ($this->relations->forTerm($tid, 10, FALSE) as $item) {
      $other = $terms[$item['tid']]['name'] ?? $item['tid'];
      $related[] = $other . '（' . $item['shared'] . '，强度 ' . number_format($item['jaccard'], 2) . '）';
    }
    $build['related'] = [
      '#markup' => '<p class="xt-admin__note">' . $this->t('关联标签：@list', ['@list' => $related ? implode('、', $related) : $this->t('无')]) . '</p>',
    ];
    $bundleLabel = static function (string $type): string {
      return match ($type) {
        'photo' => '图片',
        'video' => '视频',
        'article' => '文章',
        default => $type,
      };
    };
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
        $bundleLabel((string) $row->type),
        (int) $row->status === 1 ? $this->t('已发布') : $this->t('未发布'),
      ];
    }
    $build['content_wrap'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xt-card', 'xt-card--flush', 'xt-table-wrap']],
      'content' => [
        '#type' => 'table',
        '#attributes' => ['class' => ['xt-table']],
        '#caption' => $this->t('关联内容'),
        '#header' => [$this->t('内容 ID'), $this->t('标题'), $this->t('类型'), $this->t('状态')],
        '#rows' => $content,
        '#empty' => $this->t('没有关联内容。'),
      ],
    ];
    $build['refs'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('删除保护会检查的引用'),
      '#items' => array_map(static fn(array $item): string => $item['label'], $this->guard->blockers($tid)) ?: [$this->t('没有菜单或跳转引用。内容引用见上表。')],
      '#prefix' => '<div class="xt-panel">',
      '#suffix' => '</div>',
    ];
    $build['#cache']['tags'] = array_merge($this->catalog->cacheTags(), ['taxonomy_term:' . $tid]);
    return $build;
  }

  /**
   * @return array<string, mixed>
   */
  private function page(string $title = '', string $description = ''): array {
    $header = '<header class="xt-admin__header">';
    $header .= '<div class="xt-admin__header-main">';
    $header .= '<p class="xt-admin__eyebrow">' . $this->t('标签统计') . '</p>';
    if ($title !== '') {
      $header .= '<h1 class="xt-admin__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    }
    if ($description !== '') {
      $header .= '<p class="xt-admin__lede">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $header .= '</div>';
    $header .= '<a class="xt-admin__back" href="' . Url::fromRoute('xedc_tags.admin_overview')->toString() . '">' . $this->t('返回总览') . '</a>';
    $header .= '</header>';

    return [
      '#attached' => ['library' => ['xedc_tags/admin']],
      '#prefix' => '<div class="xt-admin">',
      '#suffix' => '</div>',
      'chrome_header' => [
        '#markup' => $header,
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args', 'languages:language_interface', 'languages:language_content'],
        'tags' => $this->catalog->cacheTags(),
      ],
    ];
  }

  /**
   * @return array<int, array{title: mixed, description: mixed, url: string, icon: string}>
   */
  private function shortcuts(): array {
    $account = $this->currentUser();
    $items = [
      [
        'title' => $this->t('标签列表'),
        'description' => $this->t('搜索、筛选和批量处理标签。'),
        'route' => 'xedc_tags.admin_list',
        'permission' => 'view xedc tag center',
        'icon' => '☰',
      ],
      [
        'title' => $this->t('标签健康检查'),
        'description' => $this->t('查看异常、孤立和风险标签。'),
        'route' => 'xedc_tags.admin_health',
        'permission' => 'view xedc tag analysis',
        'icon' => '◎',
      ],
      [
        'title' => $this->t('重复标签检测'),
        'description' => $this->t('发现近似重复并进入合并预览。'),
        'route' => 'xedc_tags.admin_duplicates',
        'permission' => 'view xedc tag analysis',
        'icon' => '⧉',
      ],
      [
        'title' => $this->t('标签合并'),
        'description' => $this->t('预览影响后迁移引用，可回滚。'),
        'route' => 'xedc_tags.admin_merge',
        'permission' => 'merge xedc tags',
        'icon' => '⇄',
      ],
      [
        'title' => $this->t('标签别名'),
        'description' => $this->t('维护同义词别名，不自动改内容。'),
        'route' => 'xedc_tags.admin_aliases',
        'permission' => 'manage xedc tag aliases',
        'icon' => '✎',
      ],
      [
        'title' => $this->t('智能推荐'),
        'description' => $this->t('查看内容级标签推荐建议。'),
        'route' => 'xedc_tags.admin_recommend',
        'permission' => 'view xedc tag analysis',
        'icon' => '✦',
      ],
      [
        'title' => $this->t('关联分析'),
        'description' => $this->t('共现关系与 Jaccard 强度。'),
        'route' => 'xedc_tags.admin_relations',
        'permission' => 'view xedc tag analysis',
        'icon' => '◈',
      ],
      [
        'title' => $this->t('SEO 检查'),
        'description' => $this->t('别名、索引与 noindex 建议。'),
        'route' => 'xedc_tags.admin_seo',
        'permission' => 'view xedc tag analysis',
        'icon' => '◉',
      ],
      [
        'title' => $this->t('AI 语义分析'),
        'description' => $this->t('仅生成建议，不自动改标签。'),
        'route' => 'xedc_tags.admin_ai',
        'permission' => 'view xedc tag analysis',
        'icon' => '✧',
      ],
      [
        'title' => $this->t('分析任务'),
        'description' => $this->t('排队扫描、健康与 SEO 任务。'),
        'route' => 'xedc_tags.admin_tasks',
        'permission' => 'run xedc tag analysis',
        'icon' => '▹',
      ],
      [
        'title' => $this->t('未使用标签'),
        'description' => $this->t('清理前先确认没有任何引用。'),
        'route' => 'xedc_tags.admin_unused',
        'permission' => 'view xedc tag center',
        'icon' => '○',
      ],
      [
        'title' => $this->t('操作历史'),
        'description' => $this->t('审计记录与合并回滚入口。'),
        'route' => 'xedc_tags.admin_history',
        'permission' => 'view xedc tag center',
        'icon' => '◷',
      ],
    ];

    $out = [];
    foreach ($items as $item) {
      if (!$account->hasPermission($item['permission'])) {
        continue;
      }
      $out[] = [
        'title' => $item['title'],
        'description' => $item['description'],
        'url' => Url::fromRoute($item['route'])->toString(),
        'icon' => $item['icon'],
      ];
    }
    return $out;
  }

  /**
   * @return array<int, array{op: string, tag: string, actor: string, time: string, status: string, status_tone: string}>
   */
  private function recentActivity(): array {
    $rows = [];
    foreach ($this->audit->history(12) as $row) {
      $op = (string) $row->op;
      $rows[] = [
        'op' => (string) $this->labels->operation($op),
        'tag' => $this->tagLabel((int) $row->tid),
        'actor' => $this->actorName((int) $row->uid),
        'time' => $this->dates->format((int) $row->created, 'custom', 'Y-m-d H:i'),
        'status' => (string) $this->t('已记录'),
        'status_tone' => $this->labels->operationTone($op),
      ];
    }
    return $rows;
  }

  /**
   * @return array{last_label: string, status_label: string, status_tone: string}
   */
  private function scanMeta(): array {
    $last = NULL;
    $active = NULL;
    foreach ($this->tasks->list(20) as $row) {
      if ($active === NULL && in_array($row->status, ['pending', 'running', 'paused'], TRUE)) {
        $active = $row;
      }
      if ($last === NULL && in_array($row->status, ['completed', 'partial', 'failed', 'cancelled'], TRUE)) {
        $last = $row;
      }
    }

    if ($active) {
      return [
        'last_label' => $last
          ? $this->dates->format((int) ($last->finished ?: $last->started), 'custom', 'Y-m-d H:i')
          : (string) $this->t('尚无完成记录'),
        'status_label' => (string) $this->t('@type · @status', [
          '@type' => $this->labels->taskType((string) $active->type),
          '@status' => $this->labels->status((string) $active->status),
        ]),
        'status_tone' => $this->labels->statusTone((string) $active->status),
      ];
    }

    if ($last) {
      $stamp = (int) ($last->finished ?: $last->started);
      return [
        'last_label' => $stamp > 0 ? $this->dates->format($stamp, 'custom', 'Y-m-d H:i') : (string) $this->t('未知'),
        'status_label' => (string) $this->labels->status((string) $last->status),
        'status_tone' => $this->labels->statusTone((string) $last->status),
      ];
    }

    return [
      'last_label' => (string) $this->t('尚未扫描'),
      'status_label' => (string) $this->t('空闲'),
      'status_tone' => 'neutral',
    ];
  }

  private function actorName(int $uid): string {
    if ($uid <= 0) {
      return (string) $this->t('系统');
    }
    $storage = $this->entityTypeManager()->getStorage('user');
    $user = $storage->load($uid);
    if ($user instanceof UserInterface) {
      return $user->getDisplayName();
    }
    return (string) $uid;
  }

  private function tagLabel(int $tid): string {
    if ($tid <= 0) {
      return '—';
    }
    $terms = $this->catalog->terms();
    return (string) ($terms[$tid]['name'] ?? $tid);
  }

  private function seoBadge(string $status, string $label): string {
    $tone = match ($status) {
      'ok' => 'success',
      'improve' => 'info',
      'noindex', 'merge' => 'warning',
      'manual' => 'danger',
      default => 'neutral',
    };
    return '<span class="xt-badge xt-badge--' . $tone . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
  }

  private function seoLinks(): string {
    $links = ['<a class="xt-chip" href="' . Url::fromRoute('xedc_tags.admin_seo')->toString() . '">' . $this->t('全部') . '</a>'];
    foreach (['ok' => '正常', 'improve' => '建议完善', 'noindex' => '建议 noindex', 'merge' => '建议合并', 'manual' => '需要人工检查'] as $status => $label) {
      $links[] = '<a class="xt-chip" href="' . Url::fromRoute('xedc_tags.admin_seo', [], ['query' => ['status' => $status]])->toString() . '">' . $this->t($label) . '</a>';
    }
    return '<div class="xt-chip-row">' . implode('', $links) . '</div>';
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

  private function getRequest(): \Symfony\Component\HttpFoundation\Request {
    return \Drupal::request();
  }

}
