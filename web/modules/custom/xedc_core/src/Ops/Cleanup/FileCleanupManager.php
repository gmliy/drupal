<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;

/**
 * Creates cleanup plans and runs only tasks an administrator has confirmed.
 */
class FileCleanupManager {

  public function __construct(
    protected CleanupRepository $repository,
    protected FileReferenceAnalyzer $analyzer,
    protected FileReferenceMerger $merger,
    protected FileQuarantineManager $quarantine,
    protected FilePathGuard $guard,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected ConfigFactoryInterface $configFactory,
    protected LockBackendInterface $lock,
    protected QueueFactory $queueFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * @return array{ok: bool, task_id: int, errors: list<string>}
   *   Created plan, or the reasons it was refused.
   */
  public function createMergePlan(int $groupId, int $keepFid, int $uid): array {
    $group = $this->repository->loadGroup($groupId);
    $batch = $this->repository->latestBatch('done');
    if (!$group || !$batch || (int) $group['batch_id'] !== (int) $batch['id']) {
      return ['ok' => FALSE, 'task_id' => 0, 'errors' => ['只能对最近一批已完成的扫描制定方案。']];
    }
    $members = $this->repository->members($groupId);
    $keep = NULL;
    $drops = [];
    foreach ($members as $member) {
      if ((int) $member['fid'] === $keepFid) {
        $keep = $member;
      }
      else {
        $drops[] = $member;
      }
    }
    if ($keep === NULL || $drops === []) {
      return ['ok' => FALSE, 'task_id' => 0, 'errors' => ['保留文件必须是这一组里的一个文件。']];
    }
    $migrations = [];
    $blocked = [];
    $refs = [];
    $errors = [];
    foreach ($drops as $drop) {
      $live = $this->analyzer->recheck((int) $drop['fid']);
      if (empty($live['complete'])) {
        $errors[] = '文件 ' . $drop['fid'] . ' 的引用分析不完整。';
        continue;
      }
      foreach ($live['refs'] ?? [] as $ref) {
        $ref['fid'] = (int) $drop['fid'];
        $refs[] = $ref;
        if (($ref['source'] ?? '') === 'field' && (int) ($ref['migratable'] ?? 0) === 1 && ($ref['confidence'] ?? '') === 'confirmed') {
          $migrations[] = $ref;
        }
        elseif (($ref['source'] ?? '') !== 'file_usage' || (int) ($ref['migratable'] ?? 0) !== 1) {
          $blocked[] = $ref;
        }
      }
    }
    $migrations = $this->uniqueMigrations($migrations);
    if ($errors !== []) {
      return ['ok' => FALSE, 'task_id' => 0, 'errors' => $errors];
    }
    $payload = [
      'v' => 1,
      'type' => 'merge',
      'batch_id' => (int) $batch['id'],
      'group_id' => $groupId,
      'keep' => $this->fileCard($keep),
      'drops' => array_map($this->fileCard(...), $drops),
      'migrations' => $migrations,
      'blocked' => $blocked,
      'signature' => DuplicateMath::signature($refs),
      'reclaimable_bytes' => (int) $group['reclaimable_bytes'],
    ];
    return ['ok' => TRUE, 'task_id' => $this->storePlan('merge', (int) $batch['id'], 0, $uid, $payload), 'errors' => []];
  }

  /**
   * @param list<int> $fids
   *   Selected files.
   *
   * @return array{ok: bool, task_id: int, errors: list<string>}
   *   Plan result.
   */
  public function createQuarantinePlan(array $fids, int $uid, int $parentId = 0): array {
    $batch = $this->repository->latestBatch('done');
    if (!$batch) {
      return ['ok' => FALSE, 'task_id' => 0, 'errors' => ['还没有完成的分析批次。']];
    }
    $items = [];
    $errors = [];
    foreach (array_slice(array_values(array_unique(array_map('intval', $fids))), 0, 50) as $fid) {
      if ($fid <= 0) {
        continue;
      }
      $reason = $this->quarantineCandidate((int) $batch['id'], $fid);
      if ($reason['ok'] === FALSE) {
        $errors[] = $reason['error'];
        continue;
      }
      $items[] = $reason['item'];
    }
    if ($items === []) {
      return ['ok' => FALSE, 'task_id' => 0, 'errors' => $errors ?: ['没有可以隔离的文件。']];
    }
    $payload = [
      'v' => 1,
      'type' => 'quarantine',
      'batch_id' => (int) $batch['id'],
      'items' => $items,
      'rejected' => $errors,
    ];
    return [
      'ok' => TRUE,
      'task_id' => $this->storePlan('quarantine', (int) $batch['id'], $parentId, $uid, $payload),
      'errors' => $errors,
    ];
  }

  /**
   * @return array{ok: bool, task_id: int, errors: list<string>}
   *   Follow-up plan.
   */
  public function createFollowUp(int $parentId, string $type, int $uid): array {
    if (!in_array($type, ['purge', 'restore'], TRUE)) {
      return ['ok' => FALSE, 'task_id' => 0, 'errors' => ['不支持的后续操作。']];
    }
    $parent = $this->repository->loadTask($parentId);
    if (!$parent || $parent['task_type'] !== 'quarantine' || $parent['status'] !== 'success') {
      return ['ok' => FALSE, 'task_id' => 0, 'errors' => ['只有已完成的隔离任务可以恢复或永久清理。']];
    }
    $items = [];
    foreach ($this->repository->items($parentId) as $item) {
      if ($item['status'] !== 'done') {
        continue;
      }
      $items[] = [
        'item_id' => (int) $item['id'],
        'fid' => (int) $item['fid'],
        'uri' => (string) ($item['meta']['original_uri'] ?? $item['uri']),
        'path' => (string) $item['path'],
        'sha256' => (string) ($item['meta']['sha256'] ?? $item['sha256']),
        'bytes' => (int) $item['bytes'],
        'role' => $type,
        'filename' => (string) ($item['meta']['filename'] ?? ''),
        'quarantine_uri' => (string) $item['quarantine_uri'],
        'quarantined' => (int) ($item['meta']['quarantined'] ?? 0),
        'meta' => $item['meta'],
      ];
    }
    if ($items === []) {
      return ['ok' => FALSE, 'task_id' => 0, 'errors' => ['隔离任务里没有可继续处理的文件。']];
    }
    usort($items, static fn(array $a, array $b): int => $a['fid'] <=> $b['fid']);
    $payload = [
      'v' => 1,
      'type' => $type,
      'parent_id' => $parentId,
      'items' => $items,
    ];
    return [
      'ok' => TRUE,
      'task_id' => $this->storePlan($type, (int) $parent['batch_id'], $parentId, $uid, $payload),
      'errors' => [],
    ];
  }

  /**
   * @return array{ok: bool, message: string}
   *   Whether the task was queued.
   */
  public function confirm(int $taskId, int $uid): array {
    $task = $this->repository->loadTask($taskId);
    if (!$task || !in_array($task['status'], ['pending_review', 'pending_confirm'], TRUE)) {
      return ['ok' => FALSE, 'message' => '当前状态不能确认。'];
    }
    $payload = $task['payload'];
    if (!empty($payload['blocked'])) {
      return ['ok' => FALSE, 'message' => '方案含有不能迁移的引用，不能确认执行。'];
    }
    if (!empty($payload['migrations']) && count($payload['migrations']) > 40) {
      return ['ok' => FALSE, 'message' => '迁移数量超过安全上限。'];
    }
    $hash = DuplicateMath::planHash($payload);
    if ($hash !== (string) $task['plan_hash']) {
      return ['ok' => FALSE, 'message' => '方案内容已变化，请重新制定。'];
    }
    $this->repository->updateTask($taskId, [
      'status' => 'queued',
      'confirmed_hash' => $hash,
      'confirmed_uid' => $uid,
      'confirmed' => $this->time->getRequestTime(),
      'error' => '',
    ]);
    $this->repository->insertLog($taskId, $uid, 'confirm', TRUE, '', [
      'task_type' => $task['task_type'],
      'plan_hash' => $hash,
    ]);
    $this->enqueue($taskId);
    return ['ok' => TRUE, 'message' => '已确认并加入队列。没有确认的任务不会执行。'];
  }

  /**
   * Continues pending items on a partial task. Failed items stay failed.
   *
   * @return array{ok: bool, message: string}
   *   Result.
   */
  public function resume(int $taskId, int $uid): array {
    $task = $this->repository->loadTask($taskId);
    if (!$task || $task['status'] !== 'partial') {
      return ['ok' => FALSE, 'message' => '只有部分完成的任务可以继续剩余文件。'];
    }
    if (!$this->confirmationMatches($task)) {
      return ['ok' => FALSE, 'message' => '确认已失效，不能继续。'];
    }
    $this->repository->updateTask($taskId, ['status' => 'queued', 'error' => '']);
    $this->repository->insertLog($taskId, $uid, 'resume', TRUE, '', []);
    $this->enqueue($taskId);
    return ['ok' => TRUE, 'message' => '已继续剩余文件。失败的文件不会自动重试。'];
  }

  public function cancel(int $taskId, int $uid): string {
    $task = $this->repository->loadTask($taskId);
    if (!$task || !in_array($task['status'], ['pending_review', 'pending_confirm', 'queued'], TRUE)) {
      return '当前状态不能取消。';
    }
    $this->repository->updateTask($taskId, ['status' => 'cancelled']);
    $this->repository->insertLog($taskId, $uid, 'cancel', TRUE, '', []);
    return '任务已取消。';
  }

  /**
   * Processes one confirmed task step. Returns more, done, failed, or idle.
   */
  public function step(int $taskId): string {
    if (!$this->lock->acquire('xedc_cleanup_task_' . $taskId, 60.0)) {
      return 'busy';
    }
    try {
      $task = $this->repository->loadTask($taskId);
      if (!$task) {
        return 'idle';
      }
      if (!empty($task['result']['started']) && empty($task['result']['completed']) && $task['task_type'] === 'merge' && $task['status'] === 'running') {
        $rolled = $this->merger->rollback($task['result']['journal'] ?? []);
        $this->fail($task, $rolled ? '合并中断，已按记录恢复引用。' : '合并中断，自动恢复没有全部成功。', [
          'rolled_back' => $rolled,
          'journal' => $task['result']['journal'] ?? [],
        ]);
        return 'failed';
      }
      if (!in_array($task['status'], ['queued', 'running'], TRUE)) {
        return 'idle';
      }
      if (!$this->confirmationMatches($task)) {
        $this->fail($task, '方案内容与确认不一致，确认已失效。', []);
        return 'failed';
      }
      $this->repository->updateTask($taskId, ['status' => 'running']);
      return match ($task['task_type']) {
        'merge' => $this->stepMerge($task),
        'quarantine' => $this->stepItems($task, 'quarantine'),
        'restore' => $this->stepItems($task, 'restore'),
        'purge' => $this->stepItems($task, 'purge'),
        default => $this->fail($task, '未知任务类型。', []) ? 'failed' : 'failed',
      };
    }
    finally {
      $this->lock->release('xedc_cleanup_task_' . $taskId);
    }
  }

  /**
   * @param array<string, mixed> $task
   *   Task row.
   */
  private function stepMerge(array $task): string {
    $task['result'] = ['started' => 1, 'completed' => 0, 'journal' => []];
    $this->repository->updateTask((int) $task['id'], ['result' => $task['result'], 'status' => 'running']);
    $live = [];
    foreach ($task['payload']['drops'] ?? [] as $drop) {
      $check = $this->analyzer->recheck((int) $drop['fid']);
      if (empty($check['complete'])) {
        $this->fail($task, '执行前的引用复查不完整。', []);
        return 'failed';
      }
      foreach ($check['refs'] ?? [] as $ref) {
        $ref['fid'] = (int) $drop['fid'];
        $live[] = $ref;
      }
    }
    if (DuplicateMath::signature($live) !== (string) ($task['payload']['signature'] ?? '')) {
      $this->fail($task, '引用关系已变化，原来的确认已失效。', ['signature' => DuplicateMath::signature($live)]);
      return 'failed';
    }
    $result = $this->merger->execute($task);
    $this->repository->insertLog((int) $task['id'], (int) $task['confirmed_uid'], 'merge', !empty($result['ok']), (string) ($result['error'] ?? ''), $result);
    if (empty($result['ok'])) {
      $this->repository->updateTask((int) $task['id'], [
        'status' => 'failed',
        'error' => (string) $result['error'],
        'result' => [
          'started' => 1,
          'completed' => 0,
          'journal' => $result['journal'] ?? [],
          'rolled_back' => $result['rolled_back'] ?? FALSE,
        ],
      ]);
      return 'failed';
    }
    $this->repository->updateTask((int) $task['id'], [
      'status' => 'success',
      'error' => '',
      'result' => ['started' => 1, 'completed' => 1, 'journal' => $result['journal'] ?? []],
    ]);
    return 'done';
  }

  /**
   * @param array<string, mixed> $task
   *   Task row.
   */
  private function stepItems(array $task, string $op): string {
    $pending = NULL;
    $done = 0;
    $failed = 0;
    foreach ($this->repository->items((int) $task['id']) as $item) {
      if ($item['status'] === 'done' || $item['status'] === 'restored' || $item['status'] === 'purged') {
        $done++;
        continue;
      }
      if ($item['status'] === 'failed') {
        $failed++;
        continue;
      }
      $pending = $item;
      break;
    }
    if ($pending === NULL) {
      $status = $failed > 0 ? ($done > 0 ? 'partial' : 'failed') : 'success';
      $this->repository->updateTask((int) $task['id'], ['status' => $status]);
      return $status === 'success' ? 'done' : 'failed';
    }
    try {
      if ($op === 'quarantine') {
        $check = $this->analyzer->recheck((int) $pending['fid']);
        if (($check['status'] ?? '') !== 'confirmed_unused') {
          throw new \RuntimeException('执行时引用状态已变化：' . (string) ($check['reason'] ?? ''));
        }
        $update = $this->quarantine->quarantine((int) $task['id'], $pending);
      }
      elseif ($op === 'restore') {
        $update = $this->quarantine->restore($pending);
      }
      else {
        $this->quarantine->purge($pending);
        $update = ['status' => 'purged', 'error' => ''];
      }
      $this->repository->updateItem((int) $pending['id'], $update);
      $this->repository->insertLog((int) $task['id'], (int) $task['confirmed_uid'], $op, TRUE, '', [
        'fid' => (int) $pending['fid'],
        'before' => $pending['uri'],
        'after' => $update['quarantine_uri'] ?? $update['uri'] ?? '',
      ]);
    }
    catch (\Throwable $exception) {
      $this->repository->updateItem((int) $pending['id'], [
        'status' => 'failed',
        'error' => $exception->getMessage(),
      ]);
      $doneNow = $done > 0;
      $this->repository->updateTask((int) $task['id'], [
        'status' => $doneNow ? 'partial' : 'failed',
        'error' => $exception->getMessage(),
      ]);
      $this->repository->insertLog((int) $task['id'], (int) $task['confirmed_uid'], $op, FALSE, $exception->getMessage(), [
        'fid' => (int) $pending['fid'],
        'uri' => $pending['uri'],
      ]);
      return 'failed';
    }
    $left = FALSE;
    foreach ($this->repository->items((int) $task['id']) as $item) {
      if ($item['status'] === 'pending') {
        $left = TRUE;
      }
    }
    if ($left) {
      $this->repository->updateTask((int) $task['id'], ['status' => 'running']);
      $this->enqueue((int) $task['id']);
      return 'more';
    }
    $this->repository->updateTask((int) $task['id'], ['status' => 'success', 'error' => '']);
    return 'done';
  }

  /**
   * @return array{ok: bool, error: string, item?: array<string, mixed>}
   *   Candidate check.
   */
  private function quarantineCandidate(int $batchId, int $fid): array {
    $finding = $this->repository->findingByFid($batchId, $fid);
    if (!$finding || $finding['category'] !== 'confirmed_unused') {
      return ['ok' => FALSE, 'error' => '文件 ' . $fid . ' 不是本批确认未引用的文件。'];
    }
    $live = $this->analyzer->recheck($fid);
    if (($live['status'] ?? '') !== 'confirmed_unused') {
      return ['ok' => FALSE, 'error' => '文件 ' . $fid . ' 复查后不能隔离：' . (string) ($live['reason'] ?? '')];
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      return ['ok' => FALSE, 'error' => '文件 ' . $fid . ' 没有文件实体。'];
    }
    $real = $this->fileSystem->realpath($file->getFileUri());
    $info = is_string($real) ? $this->guard->describe($real) : NULL;
    if ($info === NULL) {
      return ['ok' => FALSE, 'error' => '文件 ' . $fid . ' 的路径不能确认。'];
    }
    $sameUri = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $file->getFileUri()]);
    if (count($sameUri) > 1) {
      return ['ok' => FALSE, 'error' => '文件 ' . $fid . ' 的 URI 还被其他文件实体使用。'];
    }
    $max = (int) ($this->configFactory->get('xedc_core.settings')->get('file_hash_max_bytes') ?: 67108864);
    $hash = $this->guard->hashFile($info['path'], $max);
    if ($hash === NULL || ($finding['sha256'] !== '' && !hash_equals((string) $finding['sha256'], $hash))) {
      return ['ok' => FALSE, 'error' => '文件 ' . $fid . ' 的 SHA-256 与扫描结果不一致。'];
    }
    return ['ok' => TRUE, 'error' => '', 'item' => [
      'fid' => $fid,
      'uri' => $file->getFileUri(),
      'path' => $info['path'],
      'filename' => (string) $file->getFilename(),
      'sha256' => $hash,
      'bytes' => (int) $info['bytes'],
      'role' => 'drop',
    ]];
  }

  /**
   * @param array<string, mixed> $member
   *   Member or work row.
   *
   * @return array<string, mixed>
   *   Stable file card.
   */
  private function fileCard(array $member): array {
    return [
      'fid' => (int) $member['fid'],
      'uri' => (string) $member['uri'],
      'path' => (string) $member['path'],
      'filename' => (string) $member['filename'],
      'bytes' => (int) $member['bytes'],
      'sha256' => (string) ($member['sha256'] ?? ''),
    ];
  }

  /**
   * @param list<array<string, mixed>> $migrations
   *   Field rows.
   *
   * @return list<array<string, mixed>>
   *   One row per entity revision and field.
   */
  private function uniqueMigrations(array $migrations): array {
    $seen = [];
    $out = [];
    foreach ($migrations as $ref) {
      $key = implode('|', [
        $ref['entity_type'],
        $ref['entity_id'],
        $ref['revision_id'],
        $ref['langcode'],
        $ref['field_name'],
        $ref['fid'],
      ]);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $out[] = $ref;
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $payload
   *   Plan body.
   */
  private function storePlan(string $type, int $batchId, int $parentId, int $uid, array $payload): int {
    if (isset($payload['items']) && is_array($payload['items'])) {
      usort($payload['items'], static fn(array $a, array $b): int => ((int) ($a['fid'] ?? 0)) <=> ((int) ($b['fid'] ?? 0)));
    }
    if (isset($payload['drops']) && is_array($payload['drops'])) {
      usort($payload['drops'], static fn(array $a, array $b): int => ((int) $a['fid']) <=> ((int) $b['fid']));
    }
    $hash = DuplicateMath::planHash($payload);
    $taskId = $this->repository->insertTask([
      'task_type' => $type,
      'status' => 'pending_review',
      'batch_id' => $batchId,
      'parent_id' => $parentId,
      'plan_hash' => $hash,
      'uid' => $uid,
      'payload' => $payload,
    ]);
    if (!empty($payload['items']) && $type !== 'merge') {
      foreach ($payload['items'] as $item) {
        $meta = is_array($item['meta'] ?? NULL) && $item['meta'] !== [] ? $item['meta'] : [
          'filename' => (string) ($item['filename'] ?? ''),
          'sha256' => (string) ($item['sha256'] ?? ''),
          'quarantined' => (int) ($item['quarantined'] ?? 0),
          'original_uri' => (string) ($item['uri'] ?? ''),
          'original_path' => (string) ($item['path'] ?? ''),
        ];
        $this->repository->insertItem([
          'task_id' => $taskId,
          'fid' => (int) $item['fid'],
          'uri' => (string) ($meta['original_uri'] ?? $item['uri'] ?? ''),
          'path' => (string) ($item['path'] ?? ''),
          'sha256' => (string) ($item['sha256'] ?? ''),
          'bytes' => (int) ($item['bytes'] ?? 0),
          'role' => (string) ($item['role'] ?? $type),
          'quarantine_uri' => (string) ($item['quarantine_uri'] ?? ''),
          'meta' => $meta,
        ]);
      }
    }
    $this->repository->insertLog($taskId, $uid, 'create', TRUE, '', [
      'task_type' => $type,
      'plan_hash' => $hash,
    ]);
    return $taskId;
  }

  /**
   * @param array<string, mixed> $task
   *   Task row.
   */
  private function confirmationMatches(array $task): bool {
    $hash = DuplicateMath::planHash($task['payload']);
    return $hash !== ''
      && hash_equals($hash, (string) $task['plan_hash'])
      && hash_equals($hash, (string) $task['confirmed_hash'])
      && (int) $task['confirmed_uid'] > 0;
  }

  /**
   * @param array<string, mixed> $task
   *   Task row.
   * @param array<string, mixed> $detail
   *   Log detail.
   */
  private function fail(array $task, string $error, array $detail): bool {
    $this->repository->updateTask((int) $task['id'], [
      'status' => 'failed',
      'error' => $error,
      'confirmed_hash' => '',
    ]);
    $this->repository->insertLog((int) $task['id'], (int) ($task['confirmed_uid'] ?? 0), 'reject', FALSE, $error, $detail);
    return TRUE;
  }

  private function enqueue(int $taskId): void {
    $queue = $this->queueFactory->get('xedc_file_cleanup');
    $queue->createItem(['op' => 'task', 'id' => $taskId]);
  }

}
