<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\xedc_core\Form\CleanupTaskForm;
use Drupal\xedc_core\Form\MergePlanForm;
use Drupal\xedc_core\Form\UnusedPlanForm;
use Drupal\xedc_core\Ops\Cleanup\CleanupRepository;
use Drupal\xedc_core\Ops\Cleanup\FileAnalysisRunner;
use Drupal\xedc_core\Ops\Cleanup\FileReferencePresenter;
use Drupal\xedc_core\Ops\OpsFormat;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only lists and task review. Mutations go through forms.
 */
class FileCleanupController extends ControllerBase {

  public function __construct(
    protected CleanupRepository $repository,
    protected FileAnalysisRunner $runner,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected FileReferencePresenter $presenter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.cleanup_repository'),
      $container->get('xedc_core.file_analysis'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('xedc_core.reference_presenter'),
    );
  }

  public function duplicates(Request $request): array {
    $batch = $this->finishedBatch();
    $filters = $this->filters($request, ['q', 'family', 'risk', 'sort', 'dir']);
    $page = $this->page($request);
    $result = $batch ? $this->repository->groups((int) $batch['id'], $filters, 25, $page * 25) : ['rows' => [], 'total' => 0];
    $rows = [];
    $membersByGroup = [];
    $fids = [];
    foreach ($result['rows'] as $row) {
      $members = $this->repository->members((int) $row['id']);
      $membersByGroup[(int) $row['id']] = $members;
      foreach ($members as $member) {
        $fids[] = (int) $member['fid'];
      }
    }
    $refsByFid = [];
    foreach ($batch ? $this->repository->refsForFids((int) $batch['id'], $fids) : [] as $ref) {
      $refsByFid[(int) $ref['fid']][] = $ref;
    }
    foreach ($result['rows'] as $row) {
      $locations = 0;
      $entities = 0;
      $unknown = 0;
      foreach ($membersByGroup[(int) $row['id']] ?? [] as $member) {
        $summary = $this->presenter->summarize($refsByFid[(int) $member['fid']] ?? []);
        $locations += $summary['locations'];
        $entities += $summary['entities'];
        $unknown += $summary['unknown'];
      }
      $rows[] = [
        'id' => (int) $row['id'],
        'url' => Url::fromRoute('xedc_core.files_duplicate', ['group' => $row['id']])->toString(),
        'count' => (int) $row['file_count'],
        'bytes' => OpsFormat::bytes((int) $row['total_bytes']),
        'reclaimable' => OpsFormat::bytes((int) $row['reclaimable_bytes']),
        'hash' => (string) $row['sha256'],
        'family' => $this->familyLabel((string) $row['mime_family']),
        'risk' => $this->riskLabel((string) $row['risk']),
        'status' => $this->refLabel((string) $row['ref_status']),
        'locations' => $locations,
        'entities' => $entities,
        'unknown' => $unknown,
      ];
    }
    return $this->pageBuild('xedc_core_duplicates', [
      'rows' => $rows,
      'filters' => $filters,
      'batch' => $this->batchCard($batch),
      'pager' => $this->pager($page, (int) $result['total'], 25, $filters),
      'empty' => $rows === [],
    ]);
  }

  public function duplicate(int $group): array {
    $row = $this->repository->loadGroup($group);
    $batch = $this->finishedBatch();
    if (!$row || !$batch || (int) $row['batch_id'] !== (int) $batch['id']) {
      throw new NotFoundHttpException();
    }
    $members = [];
    $rawMembers = $this->repository->members($group);
    $summaries = [];
    $refsByFid = [];
    foreach ($rawMembers as $member) {
      $refs = $this->repository->refsForFid((int) $batch['id'], (int) $member['fid']);
      $refsByFid[(int) $member['fid']] = $refs;
      $summary = $this->presenter->summarize($refs);
      $summaries[(int) $member['fid']] = $summary;
      $page = $this->presenter->page($refs, $this->currentUser(), 'all', 0, 25);
      $members[] = [
        'fid' => (int) $member['fid'],
        'filename' => (string) $member['filename'],
        'uri' => (string) $member['uri'],
        'path' => (string) $member['path'],
        'bytes' => OpsFormat::bytes((int) $member['bytes']),
        'mime' => (string) $member['mime'],
        'mtime' => $this->stamp((int) $member['mtime']),
        'hash' => (string) $row['sha256'],
        'entity' => (int) $member['has_entity'] === 1 ? '有' : '无',
        'media' => (int) $member['media_count'],
        'refs' => (int) $member['ref_count'],
        'status' => $this->refLabel((string) $member['ref_status']),
        'risk' => $this->riskLabel((string) $member['risk']),
        'summary' => $summary,
        'references' => $page['rows'],
        'more' => $page['total'] > 25 ? Url::fromRoute('xedc_core.files_reference', ['fid' => $member['fid']])->toString() : '',
      ];
    }
    $recommendation = $this->presenter->recommend($rawMembers, $summaries);
    $form = NULL;
    if ($this->currentUser()->hasPermission('create xedc cleanup plans')) {
      $form = $this->formBuilder()->getForm(MergePlanForm::class, $group);
    }
    return $this->pageBuild('xedc_core_duplicate', [
      'group' => [
        'id' => $group,
        'hash' => (string) $row['sha256'],
        'count' => (int) $row['file_count'],
        'bytes' => OpsFormat::bytes((int) $row['total_bytes']),
        'reclaimable' => OpsFormat::bytes((int) $row['reclaimable_bytes']),
        'risk' => $this->riskLabel((string) $row['risk']),
        'status' => $this->refLabel((string) $row['ref_status']),
      ],
      'members' => $members,
      'shared' => $this->presenter->shared($refsByFid),
      'recommendation' => $recommendation,
      'form' => $form,
    ]);
  }

  public function unused(Request $request): array {
    $batch = $this->finishedBatch();
    $filters = $this->filters($request, ['q', 'category', 'family', 'risk', 'sort', 'dir']);
    if ($filters['category'] === '') {
      $filters['category'] = 'confirmed_unused';
    }
    if ($filters['category'] === 'all') {
      $filters['category'] = '';
    }
    $page = $this->page($request);
    $result = $batch ? $this->repository->findings((int) $batch['id'], $filters, 25, $page * 25) : ['rows' => [], 'total' => 0];
    $rows = [];
    $options = [];
    foreach ($result['rows'] as $row) {
      $rows[] = [
        'fid' => (int) $row['fid'],
        'filename' => (string) $row['filename'],
        'uri' => (string) $row['uri'],
        'path' => (string) $row['path'],
        'bytes' => OpsFormat::bytes((int) $row['bytes']),
        'mime' => (string) $row['mime'],
        'changed' => $this->stamp((int) $row['changed']),
        'created' => $this->stamp((int) $row['created']),
        'refs' => (int) $row['ref_count'],
        'status' => $this->refLabel((string) $row['ref_status']),
        'risk' => $this->riskLabel((string) $row['risk']),
        'category' => $this->categoryLabel((string) $row['category']),
        'reason' => (string) $row['reason'],
        'url' => (int) $row['fid'] > 0 ? Url::fromRoute('xedc_core.files_unused_item', ['fid' => $row['fid']])->toString() : '',
      ];
      if ((string) $row['category'] === 'confirmed_unused' && (int) $row['fid'] > 0) {
        $options[(int) $row['fid']] = $row['fid'] . ' · ' . $row['filename'];
      }
    }
    $form = NULL;
    if ($options !== [] && $this->currentUser()->hasPermission('create xedc cleanup plans')) {
      $form = $this->formBuilder()->getForm(UnusedPlanForm::class, $options);
    }
    return $this->pageBuild('xedc_core_unused', [
      'rows' => $rows,
      'filters' => $filters,
      'batch' => $this->batchCard($batch),
      'pager' => $this->pager($page, (int) $result['total'], 25, $filters),
      'form' => $form,
      'empty' => $rows === [],
    ]);
  }

  public function unusedItem(int $fid): array {
    $batch = $this->finishedBatch();
    $row = $batch ? $this->repository->findingByFid((int) $batch['id'], $fid) : NULL;
    if (!$row) {
      throw new NotFoundHttpException();
    }
    $refs = $this->repository->refsForFid((int) $batch['id'], $fid);
    $page = $this->presenter->page($refs, $this->currentUser(), 'all', 0, 25);
    return $this->pageBuild('xedc_core_unused_item', [
      'file' => [
        'fid' => $fid,
        'filename' => (string) $row['filename'],
        'uri' => (string) $row['uri'],
        'path' => (string) $row['path'],
        'bytes' => OpsFormat::bytes((int) $row['bytes']),
        'mime' => (string) $row['mime'],
        'changed' => $this->stamp((int) $row['changed']),
        'created' => $this->stamp((int) $row['created']),
        'category' => $this->categoryLabel((string) $row['category']),
        'status' => $this->refLabel((string) $row['ref_status']),
        'risk' => $this->riskLabel((string) $row['risk']),
        'reason' => (string) $row['reason'],
        'hash' => (string) $row['sha256'],
        'summary' => $this->presenter->summarize($refs),
        'reference_url' => Url::fromRoute('xedc_core.files_reference', ['fid' => $fid])->toString(),
      ],
      'references' => $page['rows'],
    ]);
  }

  public function reference(int $fid, Request $request): array {
    $batch = $this->finishedBatch();
    $row = $batch ? $this->repository->findingByFid((int) $batch['id'], $fid) : NULL;
    if (!$row && $batch) {
      $work = $this->repository->workByFid((int) $batch['id']);
      $row = $work[$fid] ?? NULL;
    }
    if (!$row) {
      throw new NotFoundHttpException();
    }
    $refs = $this->repository->refsForFid((int) $batch['id'], $fid);
    $kind = substr(trim((string) $request->query->get('kind', 'all')), 0, 32);
    $pageNumber = $this->page($request);
    $page = $this->presenter->page($refs, $this->currentUser(), $kind, $pageNumber * 25, 25);
    return $this->pageBuild('xedc_core_reference', [
      'file' => [
        'fid' => $fid,
        'filename' => (string) ($row['filename'] ?? ''),
        'uri' => (string) ($row['uri'] ?? ''),
        'hash' => (string) ($row['sha256'] ?? ''),
      ],
      'summary' => $this->presenter->summarize($refs),
      'kind' => $kind,
      'rows' => $page['rows'],
      'pager' => $this->pager($pageNumber, $page['total'], 25, ['kind' => $kind === 'all' ? '' : $kind]),
      'empty' => $page['rows'] === [],
    ]);
  }

  public function tasks(Request $request): array {
    $filters = $this->filters($request, ['status', 'task_type']);
    $page = $this->page($request);
    $result = $this->repository->ready() ? $this->repository->tasks($filters, 25, $page * 25) : ['rows' => [], 'total' => 0];
    $rows = [];
    foreach ($result['rows'] as $row) {
      $rows[] = [
        'id' => (int) $row['id'],
        'url' => Url::fromRoute('xedc_core.files_task', ['task' => $row['id']])->toString(),
        'type' => $this->typeLabel((string) $row['task_type']),
        'status' => $this->taskLabel((string) $row['status']),
        'created' => $this->stamp((int) $row['created']),
        'changed' => $this->stamp((int) $row['changed']),
      ];
    }
    return $this->pageBuild('xedc_core_cleanup_tasks', [
      'rows' => $rows,
      'filters' => $filters,
      'pager' => $this->pager($page, (int) $result['total'], 25, $filters),
      'empty' => $rows === [],
    ]);
  }

  public function task(int $task): array {
    $row = $this->repository->loadTask($task);
    if (!$row) {
      throw new NotFoundHttpException();
    }
    $items = [];
    foreach ($this->repository->items($task) as $item) {
      $items[] = [
        'fid' => (int) $item['fid'],
        'uri' => (string) $item['uri'],
        'path' => (string) $item['path'],
        'bytes' => OpsFormat::bytes((int) $item['bytes']),
        'hash' => (string) $item['sha256'],
        'role' => (string) $item['role'],
        'status' => (string) $item['status'],
        'error' => (string) $item['error'],
      ];
    }
    $payload = $row['payload'];
    $account = $this->currentUser();
    $forms = [];
    if (in_array($row['status'], ['pending_review', 'pending_confirm'], TRUE) && $account->hasPermission($this->confirmPermission((string) $row['task_type']))) {
      $forms['confirm'] = $this->formBuilder()->getForm(CleanupTaskForm::class);
    }
    if (in_array($row['status'], ['pending_review', 'pending_confirm', 'queued'], TRUE) && $account->hasPermission('review xedc cleanup plans')) {
      $forms['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('取消'),
        '#url' => Url::fromRoute('xedc_core.files_task_cancel', ['task' => $task]),
        '#attributes' => ['class' => ['xedc-ops-button']],
      ];
    }
    return $this->pageBuild('xedc_core_cleanup_task', [
      'task' => [
        'id' => $task,
        'type' => $this->typeLabel((string) $row['task_type']),
        'type_id' => (string) $row['task_type'],
        'status' => $this->taskLabel((string) $row['status']),
        'status_id' => (string) $row['status'],
        'created' => $this->stamp((int) $row['created']),
        'confirmed' => $this->stamp((int) $row['confirmed']),
        'confirmed_uid' => (int) $row['confirmed_uid'],
        'error' => (string) $row['error'],
        'batch_id' => (int) $row['batch_id'],
        'keep' => $payload['keep'] ?? NULL,
        'drops' => $payload['drops'] ?? [],
        'migrations' => $payload['migrations'] ?? [],
        'blocked' => $payload['blocked'] ?? [],
        'rejected' => $payload['rejected'] ?? [],
        'reclaimable' => isset($payload['reclaimable_bytes']) ? OpsFormat::bytes((int) $payload['reclaimable_bytes']) : '',
        'integrity' => is_array($row['result']['integrity'] ?? NULL) ? $row['result']['integrity'] : [],
      ],
      'items' => $items,
      'forms' => $forms,
      'links' => [
        'purge' => $row['task_type'] === 'quarantine' && $row['status'] === 'success' && $account->hasPermission('confirm xedc file purge')
          ? Url::fromRoute('xedc_core.files_task_purge', ['task' => $task])->toString() : '',
        'restore' => $row['task_type'] === 'quarantine' && $row['status'] === 'success' && $account->hasPermission('restore xedc quarantined files')
          ? Url::fromRoute('xedc_core.files_task_restore', ['task' => $task])->toString() : '',
        'continue' => $row['status'] === 'partial' ? Url::fromRoute('xedc_core.files_task_continue', ['task' => $task])->toString() : '',
      ],
    ]);
  }

  public function logs(Request $request): array {
    $filters = [
      'op' => substr((string) $request->query->get('op', ''), 0, 64),
      'task_id' => (int) $request->query->get('task_id', 0),
      'success' => (string) $request->query->get('success', ''),
    ];
    $page = $this->page($request);
    $result = $this->repository->ready() ? $this->repository->logs($filters, 25, $page * 25) : ['rows' => [], 'total' => 0];
    $rows = [];
    foreach ($result['rows'] as $row) {
      $rows[] = [
        'id' => (int) $row['id'],
        'url' => Url::fromRoute('xedc_core.files_log_item', ['log' => $row['id']])->toString(),
        'task_id' => (int) $row['task_id'],
        'uid' => (int) $row['uid'],
        'created' => $this->stamp((int) $row['created']),
        'op' => (string) $row['op'],
        'success' => (int) $row['success'] === 1 ? '成功' : '失败',
        'error' => (string) $row['error'],
      ];
    }
    return $this->pageBuild('xedc_core_cleanup_log', [
      'rows' => $rows,
      'filters' => $filters,
      'pager' => $this->pager($page, (int) $result['total'], 25, array_filter($filters, static fn($value): bool => $value !== '' && $value !== 0)),
      'detail' => NULL,
      'empty' => $rows === [],
    ]);
  }

  public function logItem(int $log): array {
    $row = $this->repository->loadLog($log);
    if (!$row) {
      throw new NotFoundHttpException();
    }
    $build = $this->logs(new Request());
    $build['#detail'] = [
      'id' => (int) $row['id'],
      'task_id' => (int) $row['task_id'],
      'uid' => (int) $row['uid'],
      'created' => $this->stamp((int) $row['created']),
      'op' => (string) $row['op'],
      'success' => (int) $row['success'] === 1 ? '成功' : '失败',
      'error' => (string) $row['error'],
      'detail' => json_encode($row['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
    ];
    return $build;
  }

  public function status(): JsonResponse {
    $summary = $this->runner->summary();
    unset($summary['summary']);
    $response = new JsonResponse($summary);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * @param array<string, mixed> $variables
   *   Theme variables.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function pageBuild(string $theme, array $variables): array {
    return [
      '#theme' => $theme,
    ] + $this->prefix($variables) + [
      '#attached' => ['library' => ['xedc_core/ops']],
      '#cache' => ['max-age' => 0, 'contexts' => ['user.permissions']],
    ];
  }

  /**
   * @param array<string, mixed> $variables
   *   Theme variables.
   *
   * @return array<string, mixed>
   *   Prefixed variables.
   */
  private function prefix(array $variables): array {
    $prefixed = [];
    foreach ($variables as $key => $value) {
      $prefixed['#' . $key] = $value;
    }
    return $prefixed;
  }

  /**
   * @return array<string, mixed>|null
   *   Latest finished batch.
   */
  private function finishedBatch(): ?array {
    if (!$this->repository->ready()) {
      return NULL;
    }
    return $this->repository->latestBatch('done');
  }

  /**
   * @param array<string, mixed>|null $batch
   *   Batch.
   *
   * @return array<string, mixed>
   *   Display card.
   */
  private function batchCard(?array $batch): array {
    $running = $this->runner->summary();
    if (!$batch) {
      return [
        'id' => 0,
        'finished' => '',
        'empty' => TRUE,
        'running' => (string) ($running['status'] ?? ''),
        'message' => (string) ($running['message'] ?? ''),
      ];
    }
    return [
      'id' => (int) $batch['id'],
      'finished' => $this->stamp((int) $batch['finished']),
      'empty' => FALSE,
      'running' => (string) ($running['status'] ?? ''),
      'message' => (string) ($running['message'] ?? ''),
    ];
  }

  /**
   * @param list<string> $keys
   *   Allowed query keys.
   *
   * @return array<string, string>
   *   Filter values.
   */
  private function filters(Request $request, array $keys): array {
    $values = [];
    foreach ($keys as $key) {
      $values[$key] = substr(trim((string) $request->query->get($key, '')), 0, 128);
    }
    return $values;
  }

  private function page(Request $request): int {
    return max(0, (int) $request->query->get('page', 0));
  }

  /**
   * @param array<string, mixed> $filters
   *   Query filters.
   *
   * @return array<string, mixed>
   *   Pager links.
   */
  private function pager(int $page, int $total, int $perPage, array $filters): array {
    $pages = max(1, (int) ceil($total / $perPage));
    $query = array_filter($filters, static fn($value): bool => $value !== '');
    return [
      'page' => $page + 1,
      'pages' => $pages,
      'total' => $total,
      'prev' => $page > 0 ? '?' . http_build_query(['page' => $page - 1] + $query) : '',
      'next' => $page + 1 < $pages ? '?' . http_build_query(['page' => $page + 1] + $query) : '',
    ];
  }

  /**
   * @param array<string, mixed> $ref
   *   Reference row.
   *
   * @return array<string, string>
   *   Display row.
   */
  private function presentRef(array $ref): array {
    return [
      'source' => (string) $ref['source'],
      'entity' => (string) $ref['entity_type'] . ':' . (string) $ref['entity_id'],
      'revision' => (string) $ref['revision_id'],
      'langcode' => (string) $ref['langcode'],
      'field' => (string) $ref['field_name'],
      'confidence' => (string) $ref['confidence'] === 'unknown' ? '引用关系未知' : '已识别',
      'migratable' => (int) $ref['migratable'] === 1 ? '可迁移' : '不能自动迁移',
      'reason' => (string) $ref['reason'],
    ];
  }

  private function stamp(int $timestamp): string {
    if ($timestamp <= 0) {
      return '暂无数据';
    }
    return $this->dateFormatter->format($timestamp, 'custom', 'Y-m-d H:i');
  }

  private function familyLabel(string $family): string {
    return match ($family) {
      'image' => '图片',
      'video' => '视频',
      'mixed' => '混合',
      default => '其他',
    };
  }

  private function riskLabel(string $risk): string {
    return match ($risk) {
      'low' => '低',
      'medium' => '中',
      default => '高',
    };
  }

  private function refLabel(string $status): string {
    return match ($status) {
      'confirmed_unused' => '确认未引用',
      'referenced' => '有引用',
      'complete' => '已分析',
      'pending' => '待分析',
      'protected', 'temporary', 'missing_physical' => '不适用',
      default => '引用关系未知',
    };
  }

  private function categoryLabel(string $category): string {
    return match ($category) {
      'confirmed_unused' => '确认未引用',
      'suspected_unused' => '疑似未引用',
      'unknown' => '引用关系未知',
      'missing_physical' => '物理文件缺失',
      'missing_entity' => '数据库记录缺失',
      'cache' => '系统缓存或可重建文件',
      'protected' => '受保护文件',
      'referenced' => '有引用',
      'temporary' => '临时文件',
      default => $category,
    };
  }

  private function typeLabel(string $type): string {
    return match ($type) {
      'merge' => '引用迁移',
      'quarantine' => '隔离',
      'purge' => '永久清理',
      'restore' => '恢复',
      default => $type,
    };
  }

  private function taskLabel(string $status): string {
    return match ($status) {
      'pending_review' => '待审核',
      'pending_confirm' => '待确认',
      'confirmed' => '已确认',
      'queued' => '排队中',
      'running' => '执行中',
      'success' => '执行成功',
      'partial' => '部分成功',
      'needs_review' => '需要人工处理',
      'failed' => '执行失败',
      'cancelled' => '已取消',
      'pending_restore' => '待恢复',
      'restored' => '已恢复',
      'pending_purge' => '待永久清理',
      'purged' => '已永久清理',
      default => $status,
    };
  }

  private function confirmPermission(string $type): string {
    return match ($type) {
      'merge' => 'confirm xedc reference merge',
      'quarantine' => 'confirm xedc file quarantine',
      'purge' => 'confirm xedc file purge',
      'restore' => 'restore xedc quarantined files',
      default => '',
    };
  }

}
