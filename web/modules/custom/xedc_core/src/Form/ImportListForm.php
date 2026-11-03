<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\xedc_core\ArchiveManager;
use Drupal\xedc_core\ImportStatus;
use Drupal\xedc_core\Publisher;
use Drupal\xedc_core\TaskRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Task list, filters and bulk publish.
 */
class ImportListForm extends FormBase {

  public function __construct(
    protected TaskRepository $tasks,
    protected Publisher $publisher,
    protected ArchiveManager $archive,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.tasks'),
      $container->get('xedc_core.publisher'),
      $container->get('xedc_core.archive'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_core_import_list';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $filter = (string) $request->query->get('status', 'all');
    if (!isset(ImportStatus::filters()[$filter])) {
      $filter = 'all';
    }
    $keyword = mb_substr(trim((string) $request->query->get('q', '')), 0, 80);
    $limit = (int) $request->query->get('limit', 20);
    if (!in_array($limit, [20, 50, 100], TRUE)) {
      $limit = 20;
    }
    $sort = (string) $request->query->get('sort', 'new') === 'old' ? 'old' : 'new';
    $account = $this->currentUser();
    $uid = $account->hasPermission('administer xedc image imports') ? NULL : (int) $account->id();
    $summary = $this->tasks->summary($uid);
    $total = $this->tasks->countList($filter, $uid, $keyword);
    $pages = max(1, (int) ceil($total / $limit));
    $page = max(1, (int) $request->query->get('page', 1));
    if ($page > $pages) {
      $page = $pages;
    }
    $rows = $this->tasks->list($filter, $uid, $limit, ($page - 1) * $limit, $keyword, $sort);
    $canPublish = $account->hasPermission('publish xedc image imports') || $account->hasPermission('administer xedc image imports');
    $showEmpty = $summary['all'] === 0 && $keyword === '' && $filter === 'all';
    $query = [
      'status' => $filter,
      'q' => $keyword,
      'limit' => $limit,
      'sort' => $sort,
      'page' => $page,
    ];

    $form['#attributes']['class'][] = 'xedc-import-dashboard';
    $form['#attached']['library'][] = 'xedc_core/dashboard';
    $form['#attached']['library'][] = 'xedc_core/asset_upload';
    $settings = $this->config('xedc_core.settings');
    $archiveLimit = (int) $settings->get('max_archive_bytes') ?: 209715200;
    $imageLimit = (int) $settings->get('max_image_bytes') ?: 52428800;
    if (!$account->hasPermission('administer xedc image imports')) {
      $archiveLimit = (int) $settings->get('member_max_archive_bytes') ?: $archiveLimit;
      $imageLimit = (int) $settings->get('member_max_image_bytes') ?: $imageLimit;
    }
    $chunkBytes = (int) $settings->get('upload_chunk_bytes') ?: 5242880;
    $concurrency = (int) $settings->get('upload_chunk_concurrency') ?: 2;
    $form['#attached']['drupalSettings']['xedcAsset'] = [
      'archiveUrl' => Url::fromRoute('xedc_core.asset_archive')->toString(),
      'imageUrl' => Url::fromRoute('xedc_core.asset_image')->toString(),
      'draftUrl' => Url::fromRoute('xedc_core.asset_draft')->toString(),
      'closeUrl' => Url::fromRoute('xedc_core.asset_close')->toString(),
      'sessionsUrl' => Url::fromRoute('xedc_core.upload_sessions')->toString(),
      'sessionUrl' => Url::fromRoute('xedc_core.upload_session_create')->toString(),
      'sessionBase' => Url::fromRoute('xedc_core.import_list')->toString() . '/upload/session/',
      'progressBase' => Url::fromRoute('xedc_core.import_list')->toString() . '/',
      'maxArchive' => $archiveLimit,
      'maxImage' => $imageLimit,
      'chunkBytes' => $chunkBytes,
      'headBytes' => 65536,
      'concurrency' => max(1, min(3, $concurrency)),
      'nid' => 0,
      'titleSelector' => '[data-xedc-title]',
    ];
    $seven = $this->archive->sevenZipBinary() ? $this->t('服务器可以解压 ZIP 和 7z。') : $this->t('服务器目前没有 7z，7z 会被拒绝并说明原因。');
    $form['upload'] = [
      '#type' => 'inline_template',
      '#weight' => -20,
      '#template' => '<div class="xedc-upload" data-xedc-asset-upload data-nid="0"><h2>{{ title }}</h2><p>{{ help }} {{ seven }}</p><div class="xedc-upload__grid"><section><h3>ZIP / 7z</h3><label>{{ title_label }}<input type="text" data-xedc-title maxlength="255"></label><label>{{ archive_label }}<input type="file" data-xedc-archive accept=".zip,.7z,application/zip,application/x-7z-compressed" multiple></label></section><section><h3>{{ image_heading }}</h3><label>{{ image_label }}<input type="file" data-xedc-images accept="image/jpeg,image/png,image/gif,image/webp" multiple></label></section></div><div data-xedc-resume></div><p class="xedc-upload__note" data-xedc-note></p><ul data-xedc-list></ul></div>',
      '#context' => [
        'title' => $this->t('上传图片或压缩包'),
        'help' => $this->t('文件按分块上传到私有目录。网络中断、刷新或重新打开页面后，可以用同一个文件继续，服务器已经确认的分块不会重传。全部到齐并校验通过后才创建导入任务。'),
        'seven' => $seven,
        'title_label' => $this->t('图集标题'),
        'archive_label' => $this->t('选择压缩包'),
        'image_heading' => $this->t('普通图片'),
        'image_label' => $this->t('选择图片，可多选'),
      ],
    ];
    $form['#cache']['max-age'] = 0;

    $form['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-import-dashboard__header']],
      'text' => [
        '#type' => 'container',
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h1',
          '#value' => $this->t('图片批量导入'),
          '#attributes' => ['class' => ['xedc-import-dashboard__title-page']],
        ],
        'subtitle' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('管理图片导入任务、查看处理进度和发布内容。'),
          '#attributes' => ['class' => ['xedc-import-dashboard__subtitle']],
        ],
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-import-dashboard__header-actions']],
        'add' => $this->buttonLink((string) $this->t('新建导入'), Url::fromRoute('node.add', ['node_type' => 'photo']), 'xedc-import-dashboard__primary', 'plus'),
        'refresh' => $this->buttonLink((string) $this->t('刷新'), Url::fromRoute('xedc_core.import_list', [], ['query' => $this->cleanQuery($query)]), 'xedc-import-dashboard__ghost', 'refresh'),
      ],
    ];

    $form['stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-import-dashboard__stats']],
    ];
    $cards = [
      'all' => [$this->t('全部任务'), $summary['all'], $this->t('当前可见的导入任务'), 'layers'],
      'processing' => [$this->t('处理中'), $summary['processing'], $this->t('正在解压、转换或导入'), 'activity'],
      'review' => [$this->t('待审核'), $summary['review'], $this->t('导入完成，可以发布'), 'clock'],
      'failed' => [$this->t('失败任务'), $summary['failed'], $this->t('失败和部分失败'), 'alert'],
    ];
    foreach ($cards as $id => [$label, $count, $hint, $icon]) {
      $form['stats'][$id] = [
        '#type' => 'link',
        '#url' => Url::fromRoute('xedc_core.import_list', [], ['query' => $this->cleanQuery(['status' => $id, 'q' => $keyword, 'limit' => $limit, 'sort' => $sort, 'page' => 1])]),
        '#title' => [
          '#type' => 'inline_template',
          '#template' => '<span class="xedc-import-dashboard__stat-icon">{{ icon }}</span><span class="xedc-import-dashboard__stat-copy"><span>{{ label }}</span><strong>{{ count }}</strong><em>{{ hint }}</em></span>',
          '#context' => [
            'icon' => $this->icon($icon),
            'label' => $label,
            'count' => $count,
            'hint' => $hint,
          ],
        ],
        '#attributes' => [
          'class' => ['xedc-import-dashboard__stat', $filter === $id ? 'is-active' : ''],
        ],
      ];
    }

    if ($showEmpty) {
      $form['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-import-dashboard__empty']],
        'icon' => ['#markup' => $this->icon('box')],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('暂无导入任务'),
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('上传图片压缩包后，可以在这里查看导入进度和管理发布任务。'),
        ],
        'add' => $this->buttonLink((string) $this->t('新建导入'), Url::fromRoute('node.add', ['node_type' => 'photo']), 'xedc-import-dashboard__primary', 'plus'),
      ];
    }

    $form['toolbar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-import-dashboard__toolbar']],
      '#access' => !$showEmpty,
    ];
    $form['toolbar']['keyword'] = [
      '#type' => 'textfield',
      '#title' => $this->t('搜索'),
      '#title_display' => 'invisible',
      '#default_value' => $keyword,
      '#size' => 32,
      '#placeholder' => $this->t('搜索任务 ID、标题或压缩包'),
      '#attributes' => ['class' => ['xedc-import-dashboard__search']],
    ];
    $form['toolbar']['sort'] = [
      '#type' => 'select',
      '#title' => $this->t('排序'),
      '#title_display' => 'invisible',
      '#options' => [
        'new' => $this->t('最新创建'),
        'old' => $this->t('最早创建'),
      ],
      '#default_value' => $sort,
    ];
    $form['toolbar']['limit'] = [
      '#type' => 'select',
      '#title' => $this->t('每页数量'),
      '#title_display' => 'invisible',
      '#options' => [
        20 => $this->t('每页 20 条'),
        50 => $this->t('每页 50 条'),
        100 => $this->t('每页 100 条'),
      ],
      '#default_value' => $limit,
    ];
    $form['toolbar']['search'] = [
      '#type' => 'submit',
      '#value' => $this->t('搜索'),
      '#submit' => ['::applyFilters'],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['xedc-import-dashboard__ghost-button']],
    ];
    $form['toolbar']['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-import-dashboard__filters']],
    ];
    $filterLabels = [
      'all' => $this->t('全部'),
      'queued' => $this->t('等待处理'),
      'processing' => $this->t('处理中'),
      'review' => $this->t('待审核'),
      'failed' => $this->t('失败'),
      'published' => $this->t('已发布'),
      'cancelled' => $this->t('已取消'),
    ];
    foreach ($filterLabels as $id => $label) {
      $form['toolbar']['filters'][$id] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('xedc_core.import_list', [], ['query' => $this->cleanQuery(['status' => $id, 'q' => $keyword, 'limit' => $limit, 'sort' => $sort, 'page' => 1])]),
        '#attributes' => ['class' => [$filter === $id ? 'is-active' : '']],
      ];
    }

    $header = [
      'task' => $this->t('任务'),
      'archive' => $this->t('压缩包'),
      'counts' => $this->t('图片数量'),
      'progress' => $this->t('进度'),
      'status' => $this->t('状态'),
      'bytes' => $this->t('原图大小'),
      'created' => $this->t('创建时间'),
      'ops' => $this->t('操作'),
    ];
    $table = [];
    foreach ($rows as $row) {
      $id = (int) $row->id;
      $title = (string) ($row->node_title ?: $this->t('内容已删除'));
      $status = (string) $row->status;
      $table[$id] = [
        'task' => [
          'data' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['xedc-import-dashboard__task']],
            'id' => [
              '#type' => 'link',
              '#title' => '#' . $id,
              '#url' => Url::fromRoute('xedc_core.import_detail', ['task' => $id]),
              '#attributes' => ['class' => ['xedc-import-dashboard__id']],
            ],
            'title' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $title,
              '#attributes' => [
                'class' => ['xedc-import-dashboard__name'],
                'title' => $title,
              ],
            ],
          ],
          'data-label' => (string) $this->t('任务'),
        ],
        'archive' => [
          'data' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => (string) ($row->archive_name ?: '—'),
            '#attributes' => [
              'class' => ['xedc-import-dashboard__file'],
              'title' => (string) $row->archive_name,
            ],
          ],
          'data-label' => (string) $this->t('压缩包'),
        ],
        'counts' => [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '<span class="xedc-import-dashboard__nums"><strong>{{ total }}</strong><em>{{ detail }}</em></span>',
            '#context' => [
              'total' => (int) $row->total,
              'detail' => $this->t('成功 @ok · 失败 @bad', [
                '@ok' => (int) $row->success_count,
                '@bad' => (int) $row->failed_count,
              ]),
            ],
          ],
          'data-label' => (string) $this->t('图片数量'),
        ],
        'progress' => [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '<span class="xedc-import-dashboard__meter" title="{{ progress }}%"><span style="width:{{ progress }}%"></span></span><em class="xedc-import-dashboard__percent">{{ progress }}%</em>',
            '#context' => ['progress' => max(0, min(100, (int) $row->progress))],
          ],
          'data-label' => (string) $this->t('进度'),
        ],
        'status' => [
          'data' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => ImportStatus::label($status),
            '#attributes' => [
              'class' => ['xedc-import-dashboard__badge', 'is-' . $this->statusClass($status)],
            ],
          ],
          'data-label' => (string) $this->t('状态'),
        ],
        'bytes' => [
          'data' => ['#plain_text' => $this->formatBytes((int) $row->bytes)],
          'data-label' => (string) $this->t('原图大小'),
        ],
        'created' => [
          'data' => ['#plain_text' => \Drupal::service('date.formatter')->format((int) $row->created, 'custom', 'Y-m-d H:i')],
          'data-label' => (string) $this->t('创建时间'),
        ],
        'ops' => [
          'data' => $this->operations($row, $canPublish),
          'data-label' => (string) $this->t('操作'),
        ],
      ];
    }
    $form['tasks'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $table,
      '#empty' => $keyword !== '' || $filter !== 'all' ? $this->t('没有符合条件的任务。') : $this->t('暂无导入任务'),
      '#access' => !$showEmpty,
      '#prefix' => '<div class="xedc-import-dashboard__table-scroll">',
      '#suffix' => '</div>',
    ];

    $form['pager'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-import-dashboard__pager']],
      '#access' => !$showEmpty,
      'summary' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('第 @page / @pages 页，共 @total 条，每页 @limit 条', [
          '@page' => $page,
          '@pages' => $pages,
          '@total' => $total,
          '@limit' => $limit,
        ]),
      ],
      'links' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-import-dashboard__pager-links']],
        'prev' => $this->pageLink((string) $this->t('上一页'), $query, $page - 1, $page > 1),
        'next' => $this->pageLink((string) $this->t('下一页'), $query, $page + 1, $page < $pages),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#access' => $canPublish && !$showEmpty,
      '#attributes' => ['class' => ['xedc-import-dashboard__publish']],
    ];
    $form['actions']['publish'] = [
      '#type' => 'submit',
      '#value' => $this->t('发布所选'),
      '#submit' => ['::submitForm'],
      '#attributes' => ['class' => ['xedc-import-dashboard__primary-button']],
    ];
    $form['actions']['note'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('只有待审核的任务可以发布。'),
      '#attributes' => ['class' => ['xedc-import-dashboard__hint']],
    ];
    return $form;
  }

  /**
   * Applies search, sort and page size without publishing.
   */
  public function applyFilters(array &$form, FormStateInterface $form_state): void {
    $status = (string) $this->getRequest()->query->get('status', 'all');
    if (!isset(ImportStatus::filters()[$status])) {
      $status = 'all';
    }
    $limit = (int) $form_state->getValue('limit');
    if (!in_array($limit, [20, 50, 100], TRUE)) {
      $limit = 20;
    }
    $sort = (string) $form_state->getValue('sort') === 'old' ? 'old' : 'new';
    $form_state->setRedirect('xedc_core.import_list', [], [
      'query' => $this->cleanQuery([
        'status' => $status,
        'q' => mb_substr(trim((string) $form_state->getValue('keyword')), 0, 80),
        'limit' => $limit,
        'sort' => $sort,
        'page' => 1,
      ]),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter(array_map('intval', (array) $form_state->getValue('tasks')));
    if (!$selected) {
      $this->messenger()->addWarning($this->t('请先勾选任务。'));
      return;
    }
    $ok = 0;
    $failed = 0;
    foreach ($selected as $taskId) {
      $result = $this->publisher->publish($taskId, $this->currentUser());
      if ($result['ok']) {
        $ok++;
        $this->messenger()->addStatus($this->t('任务 #@id：@message', [
          '@id' => $taskId,
          '@message' => $result['message'],
        ]));
      }
      else {
        $failed++;
        $this->messenger()->addError($this->t('任务 #@id：@message', [
          '@id' => $taskId,
          '@message' => $result['message'],
        ]));
      }
    }
    $this->messenger()->addStatus($this->t('批量发布结束。成功 @ok 条，失败 @failed 条。', [
      '@ok' => $ok,
      '@failed' => $failed,
    ]));
  }

  /**
   * Detail and the existing task actions. Routes still enforce access.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function operations(object $row, bool $canPublish): array {
    $id = (int) $row->id;
    $status = (string) $row->status;
    $ops = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-import-dashboard__ops']],
      'view' => [
        '#type' => 'link',
        '#title' => $this->t('查看'),
        '#url' => Url::fromRoute('xedc_core.import_detail', ['task' => $id]),
        '#attributes' => ['class' => ['xedc-import-dashboard__op']],
      ],
    ];
    if ($canPublish && $status === ImportStatus::REVIEW) {
      $ops['publish'] = [
        '#type' => 'link',
        '#title' => $this->t('发布'),
        '#url' => Url::fromRoute('xedc_core.import_publish', ['task' => $id]),
        '#attributes' => ['class' => ['xedc-import-dashboard__op']],
      ];
    }
    if (in_array($status, [ImportStatus::FAILED, ImportStatus::PARTIAL, ImportStatus::CANCELLED], TRUE)) {
      $ops['retry'] = [
        '#type' => 'link',
        '#title' => $this->t('重试'),
        '#url' => Url::fromRoute('xedc_core.import_retry', ['task' => $id]),
        '#attributes' => ['class' => ['xedc-import-dashboard__op']],
      ];
    }
    if (!in_array($status, [ImportStatus::PUBLISHED, ImportStatus::CANCELLED], TRUE)) {
      $ops['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('取消'),
        '#url' => Url::fromRoute('xedc_core.import_cancel', ['task' => $id]),
        '#attributes' => ['class' => ['xedc-import-dashboard__op']],
      ];
    }
    return $ops;
  }

  /**
   * Previous or next page, or a disabled label.
   *
   * @param array<string, int|string> $query
   *   Current query.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function pageLink(string $label, array $query, int $target, bool $enabled): array {
    if (!$enabled) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $label,
        '#attributes' => ['class' => ['is-disabled']],
      ];
    }
    $query['page'] = $target;
    return [
      '#type' => 'link',
      '#title' => $label,
      '#url' => Url::fromRoute('xedc_core.import_list', [], ['query' => $this->cleanQuery($query)]),
    ];
  }

  /**
   * @param array<string, int|string> $query
   *   Raw query values.
   *
   * @return array<string, int|string>
   *   Query without empty defaults.
   */
  private function cleanQuery(array $query): array {
    $clean = [];
    $status = (string) ($query['status'] ?? 'all');
    if ($status !== '' && $status !== 'all') {
      $clean['status'] = $status;
    }
    $keyword = trim((string) ($query['q'] ?? ''));
    if ($keyword !== '') {
      $clean['q'] = $keyword;
    }
    $limit = (int) ($query['limit'] ?? 20);
    if ($limit !== 20) {
      $clean['limit'] = $limit;
    }
    if (($query['sort'] ?? 'new') === 'old') {
      $clean['sort'] = 'old';
    }
    $page = (int) ($query['page'] ?? 1);
    if ($page > 1) {
      $clean['page'] = $page;
    }
    return $clean;
  }

  /**
   * Link styled as a dashboard button.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function buttonLink(string $label, Url $url, string $class, string $icon): array {
    return [
      '#type' => 'link',
      '#url' => $url,
      '#title' => [
        '#type' => 'inline_template',
        '#template' => '<span class="xedc-import-dashboard__btn-inner">{{ icon }}<span>{{ label }}</span></span>',
        '#context' => [
          'icon' => $this->icon($icon),
          'label' => $label,
        ],
      ],
      '#attributes' => ['class' => [$class]],
    ];
  }

  /**
   * Known status ids only, so the badge class cannot be injected.
   */
  private function statusClass(string $status): string {
    $known = array_keys(ImportStatus::labels());
    return in_array($status, $known, TRUE) ? $status : 'queued';
  }

  /**
   * Zero stays zero. Larger sizes use Drupal's formatter.
   */
  private function formatBytes(int $bytes): string {
    if ($bytes < 1) {
      return (string) $this->t('0 字节');
    }
    return (string) ByteSizeMarkup::create($bytes);
  }

  /**
   * Small stroke icon. The path set is fixed.
   */
  private function icon(string $id): MarkupInterface {
    $paths = [
      'plus' => '<path d="M12 5v14M5 12h14"/>',
      'refresh' => '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v6h-6"/>',
      'layers' => '<path d="m12 3-9 5 9 5 9-5-9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 16 9 5 9-5"/>',
      'activity' => '<path d="M4 13h4l2-6 4 10 2-4h4"/>',
      'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/>',
      'alert' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v5"/><path d="M12 16h.01"/>',
      'box' => '<path d="M3 8 12 3l9 5v8l-9 5-9-5V8Z"/><path d="M12 12 3 8"/><path d="m12 12 9-4"/><path d="M12 12v9"/>',
    ];
    $body = $paths[$id] ?? $paths['box'];
    return Markup::create('<svg class="xedc-import-dashboard__icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>');
  }

}
