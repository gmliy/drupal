<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;
use Drupal\xedc_core\Ops\Storage\StorageOperationGuard;

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
    protected MergeIntegrityChecker $integrity,
    protected ?StorageOperationGuard $operations = NULL,
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
    $fids = array_values(array_unique(array_map('intval', $fids)));
    if (count($fids) > 50) {
      return ['ok' => FALSE, 'task_id' => 0, 'errors' => ['一次最多隔离 50 个文件，请分成多批提交。']];
    }
    $items = [];
    $errors = [];
    foreach ($fids as $fid) {
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
    if ($items === [] || $errors !== []) {
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
      $blocked = is_array($payload['blocked']) ? $payload['blocked'] : [];
      $reason = (string) ($blocked[0]['reason'] ?? '方案含有不能迁移的引用。');
      $this->repository->insertLog($taskId, $uid, 'confirm', FALSE, '方案含有不能迁移的引用，不能确认执行。', [
        'blocked' => count($blocked),
        'reason' => $reason,
      ]);
      return ['ok' => FALSE, 'message' => '方案含有不能迁移的引用，不能确认执行。'];
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
    $migrationCount = is_array($payload['migrations'] ?? NULL) ? count($payload['migrations']) : 0;
    $this->repository->insertLog($taskId, $uid, 'confirm', TRUE, '', [
      'task_type' => $task['task_type'],
      'plan_hash' => $hash,
      'migrations' => $migrationCount,
      'batches' => $migrationCount > 0 ? (int) ceil($migrationCount / $this->migrationBatchSize()) : 0,
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
        $cursor = (int) ($task['result']['cursor'] ?? 0);
        if (!empty($task['result']['batch_open']) && $cursor > 0) {
          $journal = is_array($task['result']['journal'] ?? NULL) ? $task['result']['journal'] : [];
          $extra = array_slice($journal, $cursor);
          $rolled = $extra === [] || $this->merger->rollback($extra);
          if (!$rolled) {
            $this->fail($task, '合并重试中断，未完成的部分没能恢复。已完成的迁移没有回滚。', [
              'rolled_back' => FALSE,
              'journal' => $journal,
            ]);
            return 'failed';
          }
          $this->repository->updateTask($taskId, [
            'status' => 'partial',
            'error' => '合并重试中断。已完成的迁移已保留，可以继续处理剩余引用。',
            'result' => [
              'started' => 1,
              'completed' => 0,
              'batch_open' => 0,
              'cursor' => $cursor,
              'total' => (int) ($task['result']['total'] ?? 0),
              'journal' => array_slice($journal, 0, $cursor),
            ],
          ]);
          $this->repository->insertLog($taskId, (int) ($task['confirmed_uid'] ?? 0), 'merge', FALSE, '合并重试中断。已完成的迁移已保留。', [
            'cursor' => $cursor,
          ]);
          return 'interrupted';
        }
        if (!empty($task['result']['batch_open']) || $cursor === 0) {
          $rolled = $this->merger->rollback($task['result']['journal'] ?? []);
          $this->fail($task, $rolled ? '合并中断，已按记录恢复引用。' : '合并中断，自动恢复没有全部成功。', [
            'rolled_back' => $rolled,
            'journal' => $task['result']['journal'] ?? [],
          ]);
          return 'failed';
        }
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
    $stored = is_array($task['result'] ?? NULL) ? $task['result'] : [];
    $cursor = (int) ($stored['cursor'] ?? 0);
    $journal = is_array($stored['journal'] ?? NULL) ? $stored['journal'] : [];
    $migrations = $task['payload']['migrations'] ?? [];
    if (!is_array($migrations)) {
      $this->fail($task, '合并方案缺少引用清单。', []);
      return 'failed';
    }
    if ($cursor === 0) {
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
      $journal = [];
    }
    $slice = array_slice($migrations, $cursor, $this->migrationBatchSize());
    if ($slice === []) {
      return $this->finishMerge($task, $journal);
    }
    if ($cursor > 0) {
      $drift = $this->migrationDrift($slice, (int) ($task['payload']['keep']['fid'] ?? 0));
      if ($drift !== NULL) {
        $this->repository->updateTask((int) $task['id'], [
          'status' => 'needs_review',
          'error' => $drift,
          'result' => [
            'started' => 1,
            'completed' => 0,
            'batch_open' => 0,
            'cursor' => $cursor,
            'total' => count($migrations),
            'journal' => $journal,
          ],
        ]);
        $this->repository->insertLog((int) $task['id'], (int) $task['confirmed_uid'], 'merge', FALSE, $drift, [
          'cursor' => $cursor,
        ]);
        return 'manual';
      }
    }
    $task['payload']['migrations'] = $slice;
    $task['payload']['final_batch'] = $cursor + count($slice) >= count($migrations);
    $task['result'] = [
      'started' => 1,
      'completed' => 0,
      'batch_open' => 1,
      'cursor' => $cursor,
      'total' => count($migrations),
      'journal' => $journal,
    ];
    $this->repository->updateTask((int) $task['id'], ['result' => $task['result'], 'status' => 'running']);
    if ((int) \Drupal::state()->get('xedc_cleanup_crash_before_execute', 0) > 0) {
      \Drupal::state()->delete('xedc_cleanup_crash_before_execute');
      return 'crashed';
    }
    $result = $this->merger->execute($task);
    $this->repository->insertLog((int) $task['id'], (int) $task['confirmed_uid'], 'merge', !empty($result['ok']), (string) ($result['error'] ?? ''), [
      'cursor' => $cursor,
      'batch' => count($slice),
      'error' => (string) ($result['error'] ?? ''),
    ]);
    if (empty($result['ok'])) {
      $kept = is_array($result['journal'] ?? NULL) ? $result['journal'] : $journal;
      if (!empty($result['recoverable']) || !empty($result['manual'])) {
        $status = !empty($result['manual']) ? 'needs_review' : 'partial';
        $this->repository->updateTask((int) $task['id'], [
          'status' => $status,
          'error' => (string) $result['error'],
          'result' => [
            'started' => 1,
            'completed' => 0,
            'batch_open' => 0,
            'cursor' => count($kept),
            'total' => count($migrations),
            'journal' => $kept,
            'rolled_back' => FALSE,
          ],
        ]);
        return !empty($result['manual']) ? 'manual' : 'retry';
      }
      $this->repository->updateTask((int) $task['id'], [
        'status' => 'failed',
        'error' => (string) $result['error'],
        'result' => [
          'started' => 1,
          'completed' => 0,
          'batch_open' => 0,
          'cursor' => $cursor,
          'journal' => $kept,
          'rolled_back' => $result['rolled_back'] ?? FALSE,
        ],
      ]);
      return 'failed';
    }
    $journal = is_array($result['journal'] ?? NULL) ? $result['journal'] : $journal;
    $cursor += count($slice);
    $this->repository->updateTask((int) $task['id'], [
      'status' => 'running',
      'result' => [
        'started' => 1,
        'completed' => 0,
        'batch_open' => 0,
        'cursor' => $cursor,
        'total' => count($migrations),
        'journal' => $journal,
      ],
    ]);
    if ($cursor < count($migrations)) {
      return 'more';
    }
    $task['result']['journal'] = $journal;
    return $this->finishMerge($task, $journal);
  }

  /**
   * @param array<string, mixed> $task
   *   Merge task.
   * @param list<array<string, mixed>> $journal
   *   Applied field values.
   */
  private function finishMerge(array $task, array $journal): string {
    $report = $this->integrity->inspect($task, $journal);
    $this->repository->insertLog((int) $task['id'], (int) $task['confirmed_uid'], 'integrity', ($report['status'] ?? '') === 'pass', '', $report);
    if (!in_array($report['status'] ?? '', ['pass', 'warning'], TRUE)) {
      $rolled = $this->merger->rollback($journal);
      $this->fail($task, '合并后完整性检测未通过，' . ($rolled ? '已按快照恢复引用。' : '自动恢复没有全部成功。'), [
        'integrity' => $report,
        'journal' => $journal,
        'rolled_back' => $rolled,
      ]);
      return 'failed';
    }
    $this->repository->updateTask((int) $task['id'], [
      'status' => 'success',
      'error' => '',
      'result' => [
        'started' => 1,
        'completed' => 1,
        'batch_open' => 0,
        'cursor' => count($journal),
        'journal' => $journal,
        'integrity' => $report,
      ],
    ]);
    return 'done';
  }

  /**
   * @param list<array<string, mixed>> $slice
   *   Migrations that have not been recorded yet.
   */
  private function migrationDrift(array $slice, int $keepFid): ?string {
    foreach ($slice as $ref) {
      if (!is_array($ref) || ($ref['source'] ?? '') !== 'field') {
        return '快照与当前状态不一致，剩余方案含有不能迁移的项目，已停止并等待人工处理。';
      }
      if ((int) ($ref['entity_id'] ?? 0) <= 0) {
        return '快照与当前状态不一致，原实体已不存在，已停止并等待人工处理。';
      }
      try {
        $type = (string) $ref['entity_type'];
        $entityId = (int) $ref['entity_id'];
        $storage = $this->entityTypeManager->getStorage($type);
        $definition = $this->entityTypeManager->getDefinition($type);
        $storage->resetCache([$entityId]);
        $revisionId = (int) ($ref['revision_id'] ?? 0);
        $entity = $definition->isRevisionable() && $revisionId > 0 ? $storage->loadRevision($revisionId) : $storage->load($entityId);
        if ($entity instanceof TranslatableInterface) {
          $langcode = (string) ($ref['langcode'] ?? '');
          if ($langcode !== '' && $entity->language()->getId() !== $langcode) {
            $entity = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : NULL;
          }
        }
      }
      catch (\Throwable) {
        return '快照与当前状态不一致，已停止并等待人工处理。';
      }
      if (!$entity instanceof ContentEntityInterface || !$entity->hasField((string) $ref['field_name'])) {
        return '快照与当前状态不一致，原实体或字段已变化，已停止并等待人工处理。';
      }
      $targets = [];
      foreach ($entity->get((string) $ref['field_name'])->getValue() as $item) {
        $targets[] = (int) ($item['target_id'] ?? 0);
      }
      $from = (int) ($ref['fid'] ?? 0);
      if (in_array($from, $targets, TRUE)) {
        continue;
      }
      $foreign = array_filter($targets, static fn(int $id): bool => $id !== $keepFid);
      if ($foreign === [] && in_array($keepFid, $targets, TRUE)) {
        continue;
      }
      return '字段已被其他操作修改，需要人工处理，不会覆盖现有文件。';
    }
    return NULL;
  }

  public function migrationBatchSize(): int {
    $configured = (int) $this->configFactory->get('xedc_core.settings')->get('file_merge_batch');
    $state = (int) \Drupal::state()->get('xedc_cleanup_migration_batch', 0);
    $size = $state > 0 ? $state : ($configured > 0 ? $configured : 40);
    return min(40, max(1, $size));
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
    $blocked = $this->operations instanceof StorageOperationGuard
      ? $this->operations->localCleanupBlockReason($file->getFileUri())
      : NULL;
    if ($blocked !== NULL || $this->guard->kindForUri($file->getFileUri()) === 'remote') {
      return ['ok' => FALSE, 'error' => '文件 ' . $fid . ' ' . ($blocked ?? '在对象存储中，不能按本地路径隔离。')];
    }
    $real = $this->guard->realpathIfLocal($file->getFileUri());
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
