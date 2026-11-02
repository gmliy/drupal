<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Storage for analysis batches, findings, tasks and the append-only log.
 */
class CleanupRepository {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  public function ready(): bool {
    return $this->database->schema()->tableExists('xedc_file_batch')
      && $this->database->schema()->tableExists('xedc_file_cleanup_task')
      && $this->database->schema()->tableExists('xedc_file_cleanup_log');
  }

  public function createBatch(): int {
    $now = $this->time->getRequestTime();
    return (int) $this->database->insert('xedc_file_batch')
      ->fields([
        'status' => 'running',
        'phase' => 'enumerate',
        'cursor_fid' => 0,
        'processed' => 0,
        'total' => 0,
        'failures' => 0,
        'message' => '正在列出文件。',
        'state' => '{}',
        'summary' => '{}',
        'created' => $now,
        'changed' => $now,
        'finished' => 0,
      ])
      ->execute();
  }

  /**
   * @return array<string, mixed>|null
   *   Batch row with decoded JSON.
   */
  public function loadBatch(int $id): ?array {
    $row = $this->database->select('xedc_file_batch', 'b')
      ->fields('b')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $row ? $this->decodeBatch($row) : NULL;
  }

  /**
   * @return array<string, mixed>|null
   *   Latest batch, optionally restricted to one status.
   */
  public function latestBatch(?string $status = NULL): ?array {
    $query = $this->database->select('xedc_file_batch', 'b')
      ->fields('b')
      ->orderBy('id', 'DESC')
      ->range(0, 1);
    if ($status !== NULL) {
      $query->condition('status', $status);
    }
    $row = $query->execute()->fetchAssoc();
    return $row ? $this->decodeBatch($row) : NULL;
  }

  /**
   * @param array<string, mixed> $fields
   *   Columns to update. state and summary may be arrays.
   */
  public function saveBatch(int $id, array $fields): void {
    if (isset($fields['state']) && is_array($fields['state'])) {
      $fields['state'] = json_encode($fields['state'], JSON_UNESCAPED_UNICODE) ?: '{}';
    }
    if (isset($fields['summary']) && is_array($fields['summary'])) {
      $fields['summary'] = json_encode($fields['summary'], JSON_UNESCAPED_UNICODE) ?: '{}';
    }
    $fields['changed'] = $this->time->getRequestTime();
    $this->database->update('xedc_file_batch')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * @param array<string, mixed> $row
   *   Work row including batch_id and path_key.
   */
  public function upsertWork(array $row): void {
    $keys = [
      'batch_id' => (int) $row['batch_id'],
      'path_key' => (string) $row['path_key'],
    ];
    unset($row['batch_id'], $row['path_key']);
    $this->database->merge('xedc_file_hash_work')
      ->keys($keys)
      ->fields($row)
      ->execute();
  }

  /**
   * @return list<array<string, mixed>>
   *   Pending hash rows.
   */
  public function pendingWork(int $batchId, int $limit): array {
    return $this->database->select('xedc_file_hash_work', 'w')
      ->fields('w')
      ->condition('batch_id', $batchId)
      ->condition('phase', 'pending')
      ->orderBy('id')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * @return list<int>
   *   Sizes that occur more than once.
   */
  public function duplicateSizes(int $batchId): array {
    $sizes = $this->database->query(
      'SELECT bytes FROM {xedc_file_hash_work} WHERE batch_id = :batch AND phase = :phase GROUP BY bytes HAVING COUNT(*) > 1',
      [':batch' => $batchId, ':phase' => 'pending']
    )->fetchCol();
    return array_map('intval', $sizes);
  }

  public function markUniqueSizes(int $batchId): void {
    $sizes = $this->duplicateSizes($batchId);
    $query = $this->database->update('xedc_file_hash_work')
      ->fields(['phase' => 'unique'])
      ->condition('batch_id', $batchId)
      ->condition('phase', 'pending');
    if ($sizes !== []) {
      $query->condition('bytes', $sizes, 'NOT IN');
    }
    $query->execute();
  }

  /**
   * @param array<string, mixed> $fields
   *   Columns.
   */
  public function updateWork(int $id, array $fields): void {
    $this->database->update('xedc_file_hash_work')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * @return list<array<string, mixed>>
   *   Managed work rows after the cursor.
   */
  public function workAfter(int $batchId, int $fid, int $limit): array {
    return $this->database->select('xedc_file_hash_work', 'w')
      ->fields('w')
      ->condition('batch_id', $batchId)
      ->condition('fid', $fid, '>')
      ->orderBy('fid')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * @return array<int, array<string, mixed>>
   *   Work keyed by fid.
   */
  public function workByFid(int $batchId): array {
    $rows = $this->database->select('xedc_file_hash_work', 'w')
      ->fields('w')
      ->condition('batch_id', $batchId)
      ->condition('fid', 0, '>')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
      $map[(int) $row['fid']] = $row;
    }
    return $map;
  }

  /**
   * @return list<string>
   *   Hashes with at least two files.
   */
  public function duplicateHashes(int $batchId): array {
    return $this->database->query(
      "SELECT sha256 FROM {xedc_file_hash_work} WHERE batch_id = :batch AND phase = :phase AND sha256 <> '' GROUP BY sha256 HAVING COUNT(*) > 1",
      [':batch' => $batchId, ':phase' => 'hashed']
    )->fetchCol();
  }

  /**
   * @return list<array<string, mixed>>
   *   Hashed rows for one digest.
   */
  public function workByHash(int $batchId, string $hash): array {
    return $this->database->select('xedc_file_hash_work', 'w')
      ->fields('w')
      ->condition('batch_id', $batchId)
      ->condition('sha256', $hash)
      ->orderBy('fid')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function clearGroups(int $batchId): void {
    $ids = $this->database->select('xedc_file_dup_group', 'g')
      ->fields('g', ['id'])
      ->condition('batch_id', $batchId)
      ->execute()
      ->fetchCol();
    if ($ids) {
      $this->database->delete('xedc_file_dup_member')
        ->condition('group_id', $ids, 'IN')
        ->execute();
    }
    $this->database->delete('xedc_file_dup_group')
      ->condition('batch_id', $batchId)
      ->execute();
  }

  /**
   * @param array<string, mixed> $group
   *   Group columns.
   * @param list<array<string, mixed>> $members
   *   Member columns.
   */
  public function insertGroup(array $group, array $members): int {
    $groupId = (int) $this->database->insert('xedc_file_dup_group')->fields($group)->execute();
    foreach ($members as $member) {
      $member['group_id'] = $groupId;
      $this->database->insert('xedc_file_dup_member')->fields($member)->execute();
    }
    return $groupId;
  }

  /**
   * @return array{rows: list<array<string, mixed>>, total: int}
   *   One page of groups.
   */
  public function groups(int $batchId, array $filters, int $limit, int $offset): array {
    $query = $this->database->select('xedc_file_dup_group', 'g');
    $query->fields('g');
    $query->condition('g.batch_id', $batchId);
    $this->applyGroupFilters($query, $filters);
    $count = clone $query;
    $total = (int) $count->countQuery()->execute()->fetchField();
    $sort = $filters['sort'] ?? 'reclaimable_bytes';
    $allowed = ['id', 'file_count', 'total_bytes', 'reclaimable_bytes', 'risk'];
    if (!in_array($sort, $allowed, TRUE)) {
      $sort = 'reclaimable_bytes';
    }
    $direction = ($filters['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    $rows = $query->orderBy('g.' . $sort, $direction)->orderBy('g.id', 'DESC')->range($offset, $limit)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return ['rows' => $rows, 'total' => $total];
  }

  /**
   * @return array<string, mixed>|null
   *   Group row.
   */
  public function loadGroup(int $id): ?array {
    $row = $this->database->select('xedc_file_dup_group', 'g')
      ->fields('g')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * @return list<array<string, mixed>>
   *   Members.
   */
  public function members(int $groupId): array {
    return $this->database->select('xedc_file_dup_member', 'm')
      ->fields('m')
      ->condition('group_id', $groupId)
      ->orderBy('fid')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function clearRefs(int $batchId): void {
    $this->database->delete('xedc_file_ref')->condition('batch_id', $batchId)->execute();
  }

  /**
   * @param array<string, mixed> $row
   *   Reference row.
   */
  public function insertRef(array $row): void {
    $this->database->insert('xedc_file_ref')->fields($row)->execute();
  }

  /**
   * @return list<array<string, mixed>>
   *   References for one file.
   */
  public function refsForFid(int $batchId, int $fid): array {
    return $this->database->select('xedc_file_ref', 'r')
      ->fields('r')
      ->condition('batch_id', $batchId)
      ->condition('fid', $fid)
      ->orderBy('id')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * @param list<int> $fids
   *   File ids.
   *
   * @return list<array<string, mixed>>
   *   References.
   */
  public function refsForFids(int $batchId, array $fids): array {
    if ($fids === []) {
      return [];
    }
    return $this->database->select('xedc_file_ref', 'r')
      ->fields('r')
      ->condition('batch_id', $batchId)
      ->condition('fid', $fids, 'IN')
      ->orderBy('fid')
      ->orderBy('id')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * @param array<string, mixed> $row
   *   Finding row.
   */
  public function upsertFinding(array $row): void {
    $keys = [
      'batch_id' => (int) $row['batch_id'],
      'path_key' => (string) $row['path_key'],
    ];
    unset($row['batch_id'], $row['path_key']);
    $this->database->merge('xedc_file_finding')
      ->keys($keys)
      ->fields($row)
      ->execute();
  }

  /**
   * @return array{rows: list<array<string, mixed>>, total: int}
   *   One page of findings.
   */
  public function findings(int $batchId, array $filters, int $limit, int $offset): array {
    $query = $this->database->select('xedc_file_finding', 'f')->fields('f');
    $query->condition('batch_id', $batchId);
    if (!empty($filters['category'])) {
      $query->condition('category', $filters['category']);
    }
    if (!empty($filters['risk'])) {
      $query->condition('risk', $filters['risk']);
    }
    if (!empty($filters['family'])) {
      if ($filters['family'] === 'image') {
        $query->condition('mime', 'image/%', 'LIKE');
      }
      elseif ($filters['family'] === 'video') {
        $query->condition('mime', 'video/%', 'LIKE');
      }
      elseif ($filters['family'] === 'other') {
        $query->condition('mime', 'image/%', 'NOT LIKE');
        $query->condition('mime', 'video/%', 'NOT LIKE');
      }
    }
    if (!empty($filters['q'])) {
      $like = '%' . $this->database->escapeLike((string) $filters['q']) . '%';
      $group = $query->orConditionGroup()
        ->condition('filename', $like, 'LIKE')
        ->condition('uri', $like, 'LIKE');
      $query->condition($group);
    }
    $total = (int) (clone $query)->countQuery()->execute()->fetchField();
    $sort = $filters['sort'] ?? 'bytes';
    $allowed = ['fid', 'filename', 'bytes', 'changed', 'risk', 'category'];
    if (!in_array($sort, $allowed, TRUE)) {
      $sort = 'bytes';
    }
    $direction = ($filters['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    $rows = $query->orderBy($sort, $direction)->orderBy('id', 'DESC')->range($offset, $limit)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return ['rows' => $rows, 'total' => $total];
  }

  /**
   * @return array<string, mixed>|null
   *   Finding.
   */
  public function findingByFid(int $batchId, int $fid): ?array {
    $row = $this->database->select('xedc_file_finding', 'f')
      ->fields('f')
      ->condition('batch_id', $batchId)
      ->condition('fid', $fid)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * @return list<array<string, mixed>>
   *   Confirmed unused rows that already have a hash.
   */
  public function confirmedUnused(int $batchId, int $limit): array {
    return $this->database->select('xedc_file_finding', 'f')
      ->fields('f')
      ->condition('batch_id', $batchId)
      ->condition('category', 'confirmed_unused')
      ->condition('sha256', '', '<>')
      ->orderBy('bytes', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * @return array<string, int>
   *   Category counts and byte sums for confirmed unused.
   */
  public function findingSummary(int $batchId): array {
    $rows = $this->database->query(
      'SELECT category, COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS bytes FROM {xedc_file_finding} WHERE batch_id = :batch GROUP BY category',
      [':batch' => $batchId]
    )->fetchAll(\PDO::FETCH_ASSOC);
    $summary = [];
    foreach ($rows as $row) {
      $summary[(string) $row['category']] = [
        'count' => (int) $row['n'],
        'bytes' => (int) $row['bytes'],
      ];
    }
    return $summary;
  }

  /**
   * @param array<string, mixed> $fields
   *   Task columns. payload may be an array.
   */
  public function insertTask(array $fields): int {
    if (isset($fields['payload']) && is_array($fields['payload'])) {
      $fields['payload'] = json_encode($fields['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
    $now = $this->time->getRequestTime();
    $fields += [
      'created' => $now,
      'changed' => $now,
      'parent_id' => 0,
      'confirmed_hash' => '',
      'confirmed_uid' => 0,
      'confirmed' => 0,
      'result' => '',
      'error' => '',
    ];
    return (int) $this->database->insert('xedc_file_cleanup_task')->fields($fields)->execute();
  }

  /**
   * @param array<string, mixed> $fields
   *   Columns.
   */
  public function updateTask(int $id, array $fields): void {
    if (isset($fields['payload']) && is_array($fields['payload'])) {
      $fields['payload'] = json_encode($fields['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
    if (isset($fields['result']) && is_array($fields['result'])) {
      $fields['result'] = json_encode($fields['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
    $fields['changed'] = $this->time->getRequestTime();
    $this->database->update('xedc_file_cleanup_task')->fields($fields)->condition('id', $id)->execute();
  }

  /**
   * @return array<string, mixed>|null
   *   Task with decoded payload and result.
   */
  public function loadTask(int $id): ?array {
    $row = $this->database->select('xedc_file_cleanup_task', 't')
      ->fields('t')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $row['payload'] = json_decode((string) ($row['payload'] ?? ''), TRUE);
    if (!is_array($row['payload'])) {
      $row['payload'] = [];
    }
    $row['result'] = json_decode((string) ($row['result'] ?? ''), TRUE);
    if (!is_array($row['result'])) {
      $row['result'] = [];
    }
    return $row;
  }

  /**
   * @return list<array<string, mixed>>
   *   Newest tasks of one type, with decoded payload and result.
   */
  public function latestTasks(string $type, int $limit): array {
    if (!$this->ready()) {
      return [];
    }
    $rows = $this->database->select('xedc_file_cleanup_task', 't')
      ->fields('t')
      ->condition('task_type', $type)
      ->orderBy('id', 'DESC')
      ->range(0, max(1, $limit))
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
      $row['payload'] = json_decode((string) ($row['payload'] ?? ''), TRUE);
      if (!is_array($row['payload'])) {
        $row['payload'] = [];
      }
      $row['result'] = json_decode((string) ($row['result'] ?? ''), TRUE);
      if (!is_array($row['result'])) {
        $row['result'] = [];
      }
    }
    return $rows;
  }

  /**
   * @return array{rows: list<array<string, mixed>>, total: int}
   *   Task page.
   */
  public function tasks(array $filters, int $limit, int $offset): array {
    $query = $this->database->select('xedc_file_cleanup_task', 't')->fields('t');
    if (!empty($filters['status'])) {
      $query->condition('status', $filters['status']);
    }
    if (!empty($filters['task_type'])) {
      $query->condition('task_type', $filters['task_type']);
    }
    $total = (int) (clone $query)->countQuery()->execute()->fetchField();
    $rows = $query->orderBy('id', 'DESC')->range($offset, $limit)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
      unset($row['payload'], $row['result']);
    }
    return ['rows' => $rows, 'total' => $total];
  }

  /**
   * @param array<string, mixed> $fields
   *   Item columns.
   */
  public function insertItem(array $fields): int {
    if (isset($fields['meta']) && is_array($fields['meta'])) {
      $fields['meta'] = json_encode($fields['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
    $fields += ['status' => 'pending', 'quarantine_uri' => '', 'error' => '', 'meta' => ''];
    return (int) $this->database->insert('xedc_file_cleanup_item')->fields($fields)->execute();
  }

  /**
   * @return list<array<string, mixed>>
   *   Items.
   */
  public function items(int $taskId): array {
    $rows = $this->database->select('xedc_file_cleanup_item', 'i')
      ->fields('i')
      ->condition('task_id', $taskId)
      ->orderBy('id')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
      $meta = json_decode((string) ($row['meta'] ?? ''), TRUE);
      $row['meta'] = is_array($meta) ? $meta : [];
    }
    return $rows;
  }

  /**
   * @param array<string, mixed> $fields
   *   Columns.
   */
  public function updateItem(int $id, array $fields): void {
    if (isset($fields['meta']) && is_array($fields['meta'])) {
      $fields['meta'] = json_encode($fields['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
    $this->database->update('xedc_file_cleanup_item')->fields($fields)->condition('id', $id)->execute();
  }

  /**
   * @param array<string, mixed> $detail
   *   Structured detail. Stored as JSON and never updated.
   */
  public function insertLog(int $taskId, int $uid, string $op, bool $success, string $error, array $detail): void {
    $this->database->insert('xedc_file_cleanup_log')->fields([
      'task_id' => $taskId,
      'uid' => $uid,
      'created' => $this->time->getRequestTime(),
      'op' => substr($op, 0, 64),
      'success' => $success ? 1 : 0,
      'error' => $error,
      'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    ])->execute();
  }

  /**
   * @return array{rows: list<array<string, mixed>>, total: int}
   *   Log page. Detail is omitted from the list.
   */
  public function logs(array $filters, int $limit, int $offset): array {
    $query = $this->database->select('xedc_file_cleanup_log', 'l')
      ->fields('l', ['id', 'task_id', 'uid', 'created', 'op', 'success', 'error']);
    if (!empty($filters['op'])) {
      $query->condition('op', $filters['op']);
    }
    if (isset($filters['task_id']) && (int) $filters['task_id'] > 0) {
      $query->condition('task_id', (int) $filters['task_id']);
    }
    if (isset($filters['success']) && $filters['success'] !== '') {
      $query->condition('success', (int) $filters['success']);
    }
    $total = (int) (clone $query)->countQuery()->execute()->fetchField();
    $rows = $query->orderBy('id', 'DESC')->range($offset, $limit)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return ['rows' => $rows, 'total' => $total];
  }

  /**
   * @return array<string, mixed>|null
   *   One log row with decoded detail.
   */
  public function loadLog(int $id): ?array {
    $row = $this->database->select('xedc_file_cleanup_log', 'l')
      ->fields('l')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $detail = json_decode((string) ($row['detail'] ?? ''), TRUE);
    $row['detail'] = is_array($detail) ? $detail : [];
    return $row;
  }

  /**
   * @return list<int>
   *   Group ids in one batch.
   */
  public function groupIds(int $batchId): array {
    return array_map('intval', $this->database->select('xedc_file_dup_group', 'g')
      ->fields('g', ['id'])
      ->condition('batch_id', $batchId)
      ->execute()
      ->fetchCol());
  }

  public function hasTask(int $batchId, string $type, string $status): bool {
    $count = $this->database->select('xedc_file_cleanup_task', 't')
      ->condition('batch_id', $batchId)
      ->condition('task_type', $type)
      ->condition('status', $status)
      ->countQuery()
      ->execute()
      ->fetchField();
    return (int) $count > 0;
  }

  /**
   * @param array<string, mixed> $fields
   *   Member columns.
   */
  public function updateMember(int $id, array $fields): void {
    $this->database->update('xedc_file_dup_member')->fields($fields)->condition('id', $id)->execute();
  }

  /**
   * @param array<string, mixed> $fields
   *   Group columns.
   */
  public function updateGroup(int $id, array $fields): void {
    $this->database->update('xedc_file_dup_group')->fields($fields)->condition('id', $id)->execute();
  }

  public function countWork(int $batchId, ?string $phase = NULL): int {
    $query = $this->database->select('xedc_file_hash_work', 'w');
    $query->condition('batch_id', $batchId);
    if ($phase !== NULL) {
      $query->condition('phase', $phase);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * @param array<string, mixed> $filters
   *   Search and type filters.
   */
  private function applyGroupFilters($query, array $filters): void {
    if (!empty($filters['family'])) {
      $query->condition('g.mime_family', $filters['family']);
    }
    if (!empty($filters['risk'])) {
      $query->condition('g.risk', $filters['risk']);
    }
    if (!empty($filters['q'])) {
      $like = '%' . $this->database->escapeLike((string) $filters['q']) . '%';
      $query->join('xedc_file_dup_member', 'm', 'm.group_id = g.id');
      $group = $query->orConditionGroup()
        ->condition('m.filename', $like, 'LIKE')
        ->condition('m.uri', $like, 'LIKE')
        ->condition('g.sha256', $this->database->escapeLike((string) $filters['q']) . '%', 'LIKE');
      $query->condition($group);
      $query->distinct();
    }
  }

  /**
   * @param array<string, mixed> $row
   *   Database row.
   *
   * @return array<string, mixed>
   *   Row with JSON decoded.
   */
  private function decodeBatch(array $row): array {
    $state = json_decode((string) ($row['state'] ?? ''), TRUE);
    $summary = json_decode((string) ($row['summary'] ?? ''), TRUE);
    $row['state'] = is_array($state) ? $state : [];
    $row['summary'] = is_array($summary) ? $summary : [];
    return $row;
  }

}
