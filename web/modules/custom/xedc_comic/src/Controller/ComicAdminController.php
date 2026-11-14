<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\xedc_comic\Form\ComicChapterBatchForm;
use Drupal\xedc_comic\Form\ComicChapterImportForm;
use Drupal\xedc_comic\Form\ComicImportCancelForm;
use Drupal\xedc_comic\Form\ComicImportHubForm;
use Drupal\xedc_comic\Form\ComicImportRetryForm;
use Drupal\xedc_comic\Form\ComicPageManageForm;
use Drupal\xedc_comic\Form\ComicSettingsForm;
use Drupal\xedc_comic\Repository\ComicPageRepository;
use Drupal\xedc_comic\Service\ComicAdminMetrics;
use Drupal\xedc_comic\Service\ComicAdminShell;
use Drupal\xedc_comic\Service\ComicImportManager;
use Drupal\xedc_comic\Service\ComicManager;
use Drupal\xedc_comic\Service\ComicStatsManager;
use Drupal\xedc_comic\Service\ComicStorageManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * XEDC admin comic center pages.
 */
final class ComicAdminController extends ControllerBase {

  private const PER_PAGE = 25;

  public function __construct(
    private readonly ComicManager $comics,
    private readonly ComicImportManager $imports,
    private readonly ComicPageRepository $pages,
    private readonly ComicStorageManager $storage,
    private readonly ComicStatsManager $stats,
    private readonly DateFormatterInterface $dates,
    private readonly ComicAdminShell $shell,
    private readonly ComicAdminMetrics $metrics,
    private readonly PagerManagerInterface $pager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_comic.manager'),
      $container->get('xedc_comic.import'),
      $container->get('xedc_comic.page_repository'),
      $container->get('xedc_comic.storage'),
      $container->get('xedc_comic.stats'),
      $container->get('date.formatter'),
      $container->get('xedc_comic.admin_shell'),
      $container->get('xedc_comic.admin_metrics'),
      $container->get('pager.manager'),
    );
  }

  public function overview(): array {
    $stats = $this->metrics->statCards();
    $cards = [
      ['label' => $this->t('漫画总数'), 'value' => $stats['comics'], 'hint' => ''],
      ['label' => $this->t('已发布漫画'), 'value' => $stats['published'], 'hint' => ''],
      ['label' => $this->t('连载中'), 'value' => $stats['ongoing'], 'hint' => ''],
      ['label' => $this->t('章节总数'), 'value' => $stats['chapters'], 'hint' => ''],
      ['label' => $this->t('漫画图片'), 'value' => $stats['pages'], 'hint' => ''],
      ['label' => $this->t('今日新增章节'), 'value' => $stats['chapters_today'], 'hint' => ''],
      ['label' => $this->t('累计阅读量'), 'value' => $stats['reads'], 'hint' => ''],
      ['label' => $this->t('VIP 章节'), 'value' => $stats['vip_chapters'], 'hint' => ''],
    ];
    $quick = array_values(array_filter([
      $this->shell->action((string) $this->t('新建漫画'), 'node.add', ['node_type' => 'comic'], TRUE),
      $this->shell->action((string) $this->t('新建章节'), 'node.add', ['node_type' => 'comic_chapter']),
      $this->shell->action((string) $this->t('导入 ZIP'), 'xedc_comic.import'),
      $this->shell->action((string) $this->t('导入图片'), 'xedc_comic.import'),
      $this->shell->action((string) $this->t('导入 PDF'), 'xedc_comic.import'),
      $this->shell->action((string) $this->t('管理图片'), 'xedc_comic.admin_pages'),
    ]));
    $body = [
      '#theme' => 'xedc_comic_admin_overview',
      '#stat_cards' => $cards,
      '#quick_ops' => $quick,
      '#recent_comics' => $this->metrics->recentComics(),
      '#recent_chapters' => $this->metrics->recentChapters(),
      '#recent_jobs' => $this->metrics->recentImportJobs(),
      '#failed_jobs' => $this->metrics->failedImportJobs(),
      '#popular' => $this->metrics->popularComics(),
      '#import_summary' => $this->metrics->importSummary(),
    ];
    return $this->shell->page(
      $body,
      'xedc_comic.admin',
      (string) $this->t('漫画中心'),
      (string) $this->t('统一管理漫画、章节、图片资源和内容导入。'),
      $quick,
    );
  }

  public function comicList(Request $request): array {
    $filters = [
      'q' => trim((string) $request->query->get('q', '')),
      'status' => (string) $request->query->get('status', ''),
      'publish' => (string) $request->query->get('publish', ''),
      'category' => (int) $request->query->get('category', 0),
      'sort' => (string) $request->query->get('sort', 'changed'),
    ];
    $total = $this->comics->countComicsAdmin($filters);
    $this->pager->createPager($total, self::PER_PAGE);
    $page = $this->pager->findPage();
    $offset = $page * self::PER_PAGE;
    $rows = [];
    foreach ($this->comics->listComicsAdmin($filters, self::PER_PAGE, $offset) as $comic) {
      if (!$comic instanceof NodeInterface) {
        continue;
      }
      $card = $this->comics->comicCard($comic);
      $cover = $card['cover'] !== ''
        ? '<img class="xedc-comic-thumb" src="' . Html::escape($card['cover']) . '" alt="" loading="lazy" width="48" height="64">'
        : '<span class="xedc-comic-thumb xedc-comic-thumb--empty" aria-hidden="true"></span>';
      $cat = '';
      if ($comic->hasField('field_comic_category') && $comic->get('field_comic_category')->entity instanceof TermInterface) {
        $cat = $comic->get('field_comic_category')->entity->label();
      }
      $serial = $comic->hasField('field_comic_status') ? (string) $comic->get('field_comic_status')->value : '';
      $pub = $comic->isPublished()
        ? '<span class="xedc-comic-badge xedc-comic-badge--ok">' . $this->t('已发布') . '</span>'
        : '<span class="xedc-comic-badge">' . $this->t('草稿') . '</span>';
      $changed = $comic->hasField('field_comic_update_time') && !$comic->get('field_comic_update_time')->isEmpty()
        ? $this->dates->format((int) $comic->get('field_comic_update_time')->value, 'short')
        : $this->dates->format((int) $comic->getChangedTime(), 'short');
      $ops = Link::fromTextAndUrl($this->t('编辑'), Url::fromRoute('entity.node.edit_form', ['node' => $comic->id()]))->toString();
      $rows[] = [
        ['#markup' => $cover],
        Link::fromTextAndUrl($comic->label(), $comic->toUrl())->toString(),
        Html::escape($card['author']),
        Html::escape($cat),
        Html::escape($serial),
        (string) $this->comics->chapterCount((int) $comic->id()),
        (string) $card['views'],
        (string) $card['favorites'],
        $changed,
        ['#markup' => $pub],
        ['#markup' => $ops],
      ];
    }
    $actions = array_values(array_filter([
      $this->shell->action((string) $this->t('新建漫画'), 'node.add', ['node_type' => 'comic'], TRUE),
    ]));
    $content = [
      '#theme' => 'xedc_comic_admin_table',
      '#header' => [
        $this->t('封面'), $this->t('标题'), $this->t('作者'), $this->t('分类'),
        $this->t('连载'), $this->t('章节'), $this->t('阅读'), $this->t('收藏'),
        $this->t('更新'), $this->t('状态'), $this->t('操作'),
      ],
      '#rows' => $rows,
      '#empty' => (string) $this->t('暂无漫画。'),
      '#filters_form' => $this->comicFilterForm($filters),
      '#pager' => ['#type' => 'pager'],
      '#table_class' => 'xedc-comic-table--comics',
    ];
    return $this->shell->page(
      $content,
      'xedc_comic.admin_comics',
      (string) $this->t('漫画管理'),
      (string) $this->t('管理漫画资料、连载状态与发布。'),
      $actions,
    );
  }

  public function chapterList(Request $request): array {
    $filters = [
      'comic' => (int) $request->query->get('comic', 0),
      'q' => trim((string) $request->query->get('q', '')),
      'vip' => (string) $request->query->get('vip', ''),
      'publish' => (string) $request->query->get('publish', ''),
      'sort' => (string) $request->query->get('sort', 'number'),
    ];
    $total = $this->comics->countChaptersAdmin($filters);
    $this->pager->createPager($total, self::PER_PAGE);
    $offset = $this->pager->findPage() * self::PER_PAGE;
    $rows = [];
    foreach ($this->comics->listChaptersAdmin($filters, self::PER_PAGE, $offset) as $chapter) {
      if (!$chapter instanceof NodeInterface) {
        continue;
      }
      $parent = $chapter->hasField('field_comic_parent') && $chapter->get('field_comic_parent')->entity instanceof NodeInterface
        ? $chapter->get('field_comic_parent')->entity->label() : '';
      $vip = $chapter->hasField('field_comic_vip') && (bool) $chapter->get('field_comic_vip')->value
        ? '<span class="xedc-comic-badge xedc-comic-badge--vip">VIP</span>'
        : '<span class="xedc-comic-badge">' . $this->t('免费') . '</span>';
      $pub = $chapter->isPublished()
        ? '<span class="xedc-comic-badge xedc-comic-badge--ok">' . $this->t('已发布') . '</span>'
        : '<span class="xedc-comic-badge">' . $this->t('草稿') . '</span>';
      $ops = implode(' · ', [
        Link::fromTextAndUrl($this->t('导入'), Url::fromRoute('xedc_comic.chapter_import', ['chapter' => $chapter->id()]))->toString(),
        Link::fromTextAndUrl($this->t('图片'), Url::fromRoute('xedc_comic.admin_pages', [], ['query' => ['chapter' => $chapter->id()]]))->toString(),
        Link::fromTextAndUrl($this->t('编辑'), Url::fromRoute('entity.node.edit_form', ['node' => $chapter->id()]))->toString(),
      ]);
      $rows[] = [
        Link::fromTextAndUrl($chapter->label(), $chapter->toUrl())->toString(),
        Html::escape($parent),
        $chapter->hasField('field_comic_number') ? (string) $chapter->get('field_comic_number')->value : '',
        $chapter->hasField('field_comic_page_count') ? (string) (int) $chapter->get('field_comic_page_count')->value : '0',
        ['#markup' => $vip],
        $chapter->hasField('field_comic_publish_time') && !$chapter->get('field_comic_publish_time')->isEmpty()
          ? $this->dates->format((int) $chapter->get('field_comic_publish_time')->value, 'short') : '—',
        ['#markup' => $pub],
        $this->dates->format((int) $chapter->getChangedTime(), 'short'),
        ['#markup' => $ops],
      ];
    }
    $actions = array_values(array_filter([
      $this->shell->action((string) $this->t('新建章节'), 'node.add', ['node_type' => 'comic_chapter'], TRUE),
      $this->shell->action((string) $this->t('批量章节'), 'xedc_comic.admin_chapter_batch'),
    ]));
    $content = [
      '#theme' => 'xedc_comic_admin_table',
      '#header' => [
        $this->t('章节'), $this->t('漫画'), $this->t('序号'), $this->t('页数'),
        $this->t('权限'), $this->t('发布'), $this->t('状态'), $this->t('更新'), $this->t('操作'),
      ],
      '#rows' => $rows,
      '#empty' => (string) $this->t('暂无章节。'),
      '#filters_form' => $this->chapterFilterForm($filters),
      '#pager' => ['#type' => 'pager'],
    ];
    return $this->shell->page(
      $content,
      'xedc_comic.admin_chapters',
      (string) $this->t('章节管理'),
      (string) $this->t('按漫画组织章节、VIP 与发布状态。'),
      $actions,
    );
  }

  public function chapterBatch(): array {
    $form = $this->formBuilder()->getForm(ComicChapterBatchForm::class);
    return $this->shell->page(
      $form,
      'xedc_comic.admin_chapter_batch',
      (string) $this->t('批量创建章节'),
      (string) $this->t('按行录入章节标题，自动递增序号。'),
    );
  }

  public function settings(): array {
    $form = $this->formBuilder()->getForm(ComicSettingsForm::class);
    return $this->shell->page(
      $form,
      'xedc_comic.settings',
      (string) $this->t('设置'),
      (string) $this->t('PDF/ZIP 导入限制、存储路径与队列参数。'),
    );
  }

  public function importCancel(int $job): array {
    $form = $this->formBuilder()->getForm(ComicImportCancelForm::class, $job);
    return $this->shell->page(
      $form,
      'xedc_comic.import_status',
      (string) $this->t('取消导入'),
      (string) $this->t('确认是否停止队列中的任务。'),
    );
  }

  public function importRetry(int $job): array {
    $form = $this->formBuilder()->getForm(ComicImportRetryForm::class, $job);
    return $this->shell->page(
      $form,
      'xedc_comic.import_status',
      (string) $this->t('重试失败项'),
      (string) $this->t('仅重新排队失败条目，不改动已成功页面。'),
    );
  }

  /**
   * Chapter page image management (form built before shell wrap).
   */
  public function pageManage(Request $request): array {
    $form = $this->formBuilder()->getForm(ComicPageManageForm::class);
    $chapterId = (int) $request->query->get('chapter', 0);
    $description = (string) $this->t('按章节浏览漫画图片，支持排序与替换。');
    if ($chapterId > 0) {
      $chapter = $this->entityTypeManager()->getStorage('node')->load($chapterId);
      if ($chapter instanceof NodeInterface) {
        $parent = $chapter->hasField('field_comic_parent') && !$chapter->get('field_comic_parent')->isEmpty()
          ? $chapter->get('field_comic_parent')->entity : NULL;
        $comicLabel = $parent instanceof NodeInterface ? $parent->label() : '';
        $description = $comicLabel !== ''
          ? (string) $this->t('@chapter · @comic', ['@chapter' => $chapter->label(), '@comic' => $comicLabel])
          : $chapter->label();
      }
    }
    return $this->shell->page(
      $form,
      'xedc_comic.admin_pages',
      (string) $this->t('图片管理'),
      $description,
    );
  }

  /**
   * Import hub wrapped in comic admin shell (form must build before wrap).
   */
  public function importHub(): array {
    $form = $this->formBuilder()->getForm(ComicImportHubForm::class);
    return $this->shell->page(
      $form,
      'xedc_comic.import',
      (string) $this->t('内容导入'),
      (string) $this->t('ZIP、多图/单图与 PDF 导入到指定章节。'),
      array_values(array_filter([
        $this->shell->action((string) $this->t('导入任务'), 'xedc_comic.import_status'),
      ])),
    );
  }

  /**
   * Chapter-scoped import workbench.
   */
  public function chapterImport(NodeInterface $chapter): array {
    $form = $this->formBuilder()->getForm(ComicChapterImportForm::class, $chapter);
    return $this->shell->page(
      $form,
      'xedc_comic.chapter_import',
      (string) $this->t('章节内容导入'),
      (string) $this->t('为当前章节导入 ZIP、PDF 或图片。'),
      array_values(array_filter([
        $this->shell->action((string) $this->t('导入任务'), 'xedc_comic.import_status'),
      ])),
    );
  }

  public function importStatus(Request $request): array {
    $id = (int) $request->query->get('job', 0);
    $job = $id ? $this->imports->loadJob($id) : NULL;
    $storage = $this->entityTypeManager()->getStorage('node');
    $rows = [];
    foreach ($this->imports->recentJobs(50) as $row) {
      $comic = $storage->load((int) $row['comic_nid']);
      $chapter = $storage->load((int) $row['chapter_nid']);
      $mode = $this->imports->modeLabel((string) ($row['import_mode'] ?? 'zip'));
      $status = $this->imports->statusLabel((string) $row['status']);
      $links = [Link::fromTextAndUrl($this->t('详情'), Url::fromRoute('xedc_comic.import_status', [], ['query' => ['job' => $row['id']]]))->toString()];
      if (in_array($row['status'], ['queued', 'processing', 'pending'], TRUE)) {
        $links[] = Link::fromTextAndUrl($this->t('取消'), Url::fromRoute('xedc_comic.import_cancel', ['job' => $row['id']]))->toString();
      }
      if (in_array($row['status'], ['partial', 'completed', 'failed'], TRUE) && (int) $row['failed_count'] > 0) {
        $links[] = Link::fromTextAndUrl($this->t('重试'), Url::fromRoute('xedc_comic.import_retry', ['job' => $row['id']]))->toString();
      }
      $rows[] = [
        (int) $row['id'],
        $comic ? $comic->label() : (int) $row['comic_nid'],
        $chapter ? $chapter->label() : (int) $row['chapter_nid'],
        $mode,
        (int) $row['total'],
        (int) $row['success_count'],
        (int) $row['failed_count'],
        (int) ($row['skipped_count'] ?? 0),
        $status,
        $this->dates->format((int) $row['created']),
        !empty($row['finished']) ? $this->dates->format((int) $row['finished']) : '—',
        ['html' => implode(' · ', $links)],
      ];
    }
    $detail = NULL;
    $failedRows = [];
    $attach = [];
    if ($job) {
      $detail = [
        'id' => $job['id'],
        'status' => $this->imports->statusLabel((string) $job['status']),
        'status_key' => preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $job['status'])) ?: 'queued',
        'progress' => (int) $job['progress'],
        'summary' => (string) $this->t('成功 @ok，失败 @fail，跳过 @skip', [
          '@ok' => $job['success_count'],
          '@fail' => $job['failed_count'],
          '@skip' => $job['skipped_count'] ?? 0,
        ]),
        'message' => !empty($job['message']) ? (string) $job['message'] : '',
        'error' => !empty($job['error']) ? (string) $job['error'] : '',
        'pdf_note' => '',
        'actions' => [],
      ];
      if (($job['import_mode'] ?? '') === 'pdf') {
        $detail['pdf_note'] = (string) $this->t('PDF @name（@size）共 @pages 页，DPI @dpi', [
          '@name' => $job['archive_name'],
          '@size' => (string) ByteSizeMarkup::create((int) ($job['archive_bytes'] ?? 0)),
          '@pages' => (int) ($job['pdf_page_count'] ?? $job['total']),
          '@dpi' => (int) ($job['pdf_dpi'] ?? 150),
        ]);
      }
      foreach ($this->imports->failedItems($id) as $item) {
        $failedRows[] = [
          'weight' => (int) $item['weight'],
          'file' => (string) $item['relative'],
          'error' => (string) $item['error'],
        ];
      }
      if (in_array($job['status'], ['queued', 'processing', 'pending'], TRUE)) {
        $detail['actions'][] = [
          'label' => (string) $this->t('取消任务'),
          'url' => Url::fromRoute('xedc_comic.import_cancel', ['job' => $job['id']])->toString(),
          'danger' => TRUE,
        ];
        $attach['html_head'][] = [
          ['#tag' => 'meta', '#attributes' => ['http-equiv' => 'refresh', 'content' => '10']],
          'xedc_comic_import_refresh',
        ];
      }
      if (in_array($job['status'], ['partial', 'completed', 'failed'], TRUE) && (int) $job['failed_count'] > 0) {
        $detail['actions'][] = [
          'label' => (string) $this->t('重试失败项'),
          'url' => Url::fromRoute('xedc_comic.import_retry', ['job' => $job['id']])->toString(),
        ];
      }
    }
    $body = [
      '#theme' => 'xedc_comic_admin_import_jobs',
      '#header' => [
        'ID', $this->t('漫画'), $this->t('章节'), $this->t('类型'),
        $this->t('总数'), $this->t('成功'), $this->t('失败'), $this->t('跳过'),
        $this->t('状态'), $this->t('创建'), $this->t('完成'), $this->t('操作'),
      ],
      '#rows' => $rows,
      '#job_detail' => $detail,
      '#failed_rows' => $failedRows,
      '#attached' => $attach,
    ];
    return $this->shell->page(
      $body,
      'xedc_comic.import_status',
      (string) $this->t('导入任务'),
      (string) $this->t('查看队列任务进度、失败明细与重试。'),
      array_values(array_filter([$this->shell->action((string) $this->t('去导入'), 'xedc_comic.import', [], TRUE)])),
    );
  }

  public function stats(): array {
    $site = $this->metrics->statCards();
    $totals = ['today' => 0, 'week' => 0, 'month' => 0];
    $db = \Drupal::database();
    if (!$db->schema()->tableExists('xedc_comic_stats_daily')) {
      $totals = ['today' => 0, 'week' => 0, 'month' => 0];
    }
    else {
      $tz = new \DateTimeZone('Asia/Shanghai');
      $todayKey = (int) (new \DateTimeImmutable('today', $tz))->format('Ymd');
      $weekKey = (int) (new \DateTimeImmutable('-6 days', $tz))->format('Ymd');
      $monthKey = (int) (new \DateTimeImmutable('-29 days', $tz))->format('Ymd');
      $qToday = $db->select('xedc_comic_stats_daily', 's');
      $qToday->condition('chapter_id', 0);
      $qToday->condition('day', $todayKey);
      $qToday->addExpression('SUM(views)', 'v');
      $totals['today'] = (int) $qToday->execute()->fetchField();
      $qWeek = $db->select('xedc_comic_stats_daily', 's');
      $qWeek->condition('chapter_id', 0);
      $qWeek->condition('day', $weekKey, '>=');
      $qWeek->addExpression('SUM(views)', 'v');
      $totals['week'] = (int) $qWeek->execute()->fetchField();
      $qMonth = $db->select('xedc_comic_stats_daily', 's');
      $qMonth->condition('chapter_id', 0);
      $qMonth->condition('day', $monthKey, '>=');
      $qMonth->addExpression('SUM(views)', 'v');
      $totals['month'] = (int) $qMonth->execute()->fetchField();
    }

    $cards = [
      ['label' => $this->t('总阅读量'), 'value' => $site['reads'], 'hint' => $this->t('日汇总表 chapter_id=0')],
      ['label' => $this->t('今日阅读量'), 'value' => $totals['today']],
      ['label' => $this->t('近 7 天'), 'value' => $totals['week']],
      ['label' => $this->t('近 30 天'), 'value' => $totals['month']],
    ];
    $tableRows = [];
    foreach ($this->stats->topComics(30, 20) as $row) {
      $node = $this->entityTypeManager()->getStorage('node')->load((int) $row['comic_id']);
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $s = $this->stats->comicSummary((int) $node->id());
      $tableRows[] = [
        $node->label(),
        $s['total'],
        $s['today'],
        $s['week'],
        $s['month'],
      ];
    }
    $body = [
      '#theme' => 'xedc_comic_admin_stats',
      '#summary_cards' => $cards,
      '#header' => [$this->t('漫画'), $this->t('总阅读'), $this->t('今日'), $this->t('7 天'), $this->t('30 天')],
      '#rows' => $tableRows,
      '#trend_note' => (string) $this->t('趋势图：当前版本以表格展示日聚合数据；章节级热门尚未单独统计。'),
    ];
    return $this->shell->page(
      $body,
      'xedc_comic.admin_stats',
      (string) $this->t('阅读统计'),
      (string) $this->t('基于 xedc_comic_stats_daily 与字段汇总。'),
    );
  }

  /**
   * @param array<string, mixed> $filters
   *
   * @return array<string, mixed>
   */
  private function comicFilterForm(array $filters): array {
    $categories = ['' => (string) $this->t('全部分类')];
    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadTree('comic_category', 0, NULL, TRUE);
    foreach ($terms as $term) {
      if ($term instanceof TermInterface) {
        $categories[(string) $term->id()] = $term->label();
      }
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-comic-filter-form']],
      'inner' => [
        '#type' => 'inline_template',
        '#template' => '
<form class="xedc-comic-filters__form" method="get" action="{{ action }}">
  <label>{{ q_label }}<input type="search" name="q" value="{{ q }}"></label>
  <label>{{ cat_label }}<select name="category">{% for id, label in categories %}<option value="{{ id }}"{% if id == category %} selected{% endif %}>{{ label }}</option>{% endfor %}</select></label>
  <label>{{ serial_label }}<select name="status"><option value="">{{ all }}</option><option value="ongoing"{% if status == "ongoing" %} selected{% endif %}>{{ ongoing }}</option><option value="completed"{% if status == "completed" %} selected{% endif %}>{{ completed }}</option></select></label>
  <label>{{ pub_label }}<select name="publish"><option value="">{{ all }}</option><option value="published"{% if publish == "published" %} selected{% endif %}>{{ published }}</option><option value="draft"{% if publish == "draft" %} selected{% endif %}>{{ draft }}</option></select></label>
  <label>{{ sort_label }}<select name="sort"><option value="changed"{% if sort == "changed" %} selected{% endif %}>{{ sort_changed }}</option><option value="views"{% if sort == "views" %} selected{% endif %}>{{ sort_views }}</option><option value="title"{% if sort == "title" %} selected{% endif %}>{{ sort_title }}</option></select></label>
  <button type="submit" class="xedc-admin-button xedc-admin-button--primary">{{ submit }}</button>
</form>',
        '#context' => [
          'action' => Url::fromRoute('xedc_comic.admin_comics')->toString(),
          'q' => Html::escape($filters['q']),
          'q_label' => (string) $this->t('搜索'),
          'cat_label' => (string) $this->t('分类'),
          'categories' => $categories,
          'category' => (string) $filters['category'],
          'serial_label' => (string) $this->t('连载'),
          'status' => $filters['status'],
          'ongoing' => (string) $this->t('连载中'),
          'completed' => (string) $this->t('已完结'),
          'pub_label' => (string) $this->t('发布'),
          'publish' => $filters['publish'],
          'published' => (string) $this->t('已发布'),
          'draft' => (string) $this->t('草稿'),
          'all' => (string) $this->t('全部'),
          'sort_label' => (string) $this->t('排序'),
          'sort' => $filters['sort'],
          'sort_changed' => (string) $this->t('最近更新'),
          'sort_views' => (string) $this->t('阅读量'),
          'sort_title' => (string) $this->t('标题'),
          'submit' => (string) $this->t('筛选'),
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $filters
   *
   * @return array<string, mixed>
   */
  private function chapterFilterForm(array $filters): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-comic-filter-form']],
      'inner' => [
        '#type' => 'inline_template',
        '#template' => '
<form class="xedc-comic-filters__form" method="get" action="{{ action }}">
  <label>{{ q_label }}<input type="search" name="q" value="{{ q }}"></label>
  <label>{{ comic_label }}<input type="number" name="comic" min="0" value="{{ comic }}" placeholder="NID"></label>
  <label>{{ vip_label }}<select name="vip"><option value="">{{ all }}</option><option value="free"{% if vip == "free" %} selected{% endif %}>{{ free }}</option><option value="vip"{% if vip == "vip" %} selected{% endif %}>VIP</option></select></label>
  <label>{{ pub_label }}<select name="publish"><option value="">{{ all }}</option><option value="published"{% if publish == "published" %} selected{% endif %}>{{ published }}</option><option value="draft"{% if publish == "draft" %} selected{% endif %}>{{ draft }}</option></select></label>
  <label>{{ sort_label }}<select name="sort"><option value="number"{% if sort == "number" %} selected{% endif %}>{{ sort_num }}</option><option value="changed"{% if sort == "changed" %} selected{% endif %}>{{ sort_changed }}</option></select></label>
  <button type="submit" class="xedc-admin-button xedc-admin-button--primary">{{ submit }}</button>
</form>',
        '#context' => [
          'action' => Url::fromRoute('xedc_comic.admin_chapters')->toString(),
          'q' => Html::escape($filters['q']),
          'q_label' => (string) $this->t('章节标题'),
          'comic_label' => (string) $this->t('漫画 NID'),
          'comic' => $filters['comic'] ?: '',
          'vip_label' => (string) $this->t('权限'),
          'vip' => $filters['vip'],
          'free' => (string) $this->t('免费'),
          'all' => (string) $this->t('全部'),
          'pub_label' => (string) $this->t('发布'),
          'publish' => $filters['publish'],
          'published' => (string) $this->t('已发布'),
          'draft' => (string) $this->t('草稿'),
          'sort_label' => (string) $this->t('排序'),
          'sort' => $filters['sort'],
          'sort_num' => (string) $this->t('章节序号'),
          'sort_changed' => (string) $this->t('更新时间'),
          'submit' => (string) $this->t('筛选'),
        ],
      ],
    ];
  }

}