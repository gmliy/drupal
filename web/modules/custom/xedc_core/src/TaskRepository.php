<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * Persistence for import tasks, items and logs.
 */
class TaskRepository {

  public function __construct(
    protected Connection $database,
    protected StateInterface $state,
    protected TimeInterface $time,
  ) {}

  /**
   * Inserts a task and returns its id.
   *
   * @param array<string, mixed> $fields
   *   Column values.
   */
  public function create(array $fields): int {
    $now = $this->time->getRequestTime() ?: time();
    $fields += [
      'created' => $now,
      'changed' => $now,
      'message' => '',
      'error' => '',
      'snapshot' => '',
    ];
    return (int) $this->database->insert('xedc_import_task')->fields($fields)->execute();
  }

  /**
   * Loads one task.
   */
  public function load(int $id): ?object {
    $row = $this->database->select('xedc_import_task', 't')
      ->fields('t')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Updates columns and the changed timestamp.
   *
   * @param array<string, mixed> $fields
   *   Columns to write.
   */
  public function update(int $id, array $fields): void {
    $fields['changed'] = time();
    $this->database->update('xedc_import_task')->fields($fields)->condition('id', $id)->execute();
  }

  /**
   * Appends a log line.
   */
  public function log(int $taskId, string $level, string $message): void {
    $this->database->insert('xedc_import_log')->fields([
      'task_id' => $taskId,
      'level' => substr($level, 0, 16),
      'message' => mb_substr($message, 0, 4000),
      'created' => time(),
    ])->execute();
  }

  /**
   * Newest log lines.
   *
   * @return object[]
   *   Log rows.
   */
  public function logs(int $taskId, int $limit = 200): array {
    return $this->database->select('xedc_import_log', 'l')
      ->fields('l')
      ->condition('task_id', $taskId)
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Inserts scanned images.
   *
   * @param array<int, array{relative: string, bytes: int, uri: string}> $files
   *   Files in display order.
   */
  public function insertItems(int $taskId, array $files, int $start): void {
    if (!$files) {
      return;
    }
    $query = $this->database->insert('xedc_import_item')->fields([
      'task_id',
      'weight',
      'original_name',
      'relative_path',
      'temp_uri',
      'bytes',
      'status',
    ]);
    foreach (array_values($files) as $index => $file) {
      $relative = (string) $file['relative'];
      $query->values([
        'task_id' => $taskId,
        'weight' => $start + $index,
        'original_name' => mb_substr(basename($relative), 0, 512),
        'relative_path' => mb_substr($relative, 0, 512),
        'temp_uri' => mb_substr((string) $file['uri'], 0, 2048),
        'bytes' => max(0, (int) $file['bytes']),
        'status' => 'pending',
      ]);
    }
    $query->execute();
  }

  /**
   * Number of item rows.
   */
  public function itemCount(int $taskId): int {
    return (int) $this->database->select('xedc_import_item', 'i')
      ->condition('task_id', $taskId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Pending items in natural-sort order.
   *
   * @return object[]
   *   Item rows.
   */
  public function pending(int $taskId, int $limit): array {
    return $this->database->select('xedc_import_item', 'i')
      ->fields('i')
      ->condition('task_id', $taskId)
      ->condition('status', 'pending')
      ->orderBy('weight')
      ->orderBy('id')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Successful items in gallery order.
   *
   * @return object[]
   *   Item rows.
   */
  public function successes(int $taskId): array {
    return $this->database->select('xedc_import_item', 'i')
      ->fields('i')
      ->condition('task_id', $taskId)
      ->condition('status', 'success')
      ->orderBy('weight')
      ->orderBy('id')
      ->execute()
      ->fetchAll();
  }

  /**
   * All items in gallery order.
   *
   * @return object[]
   *   Item rows.
   */
  public function items(int $taskId): array {
    return $this->database->select('xedc_import_item', 'i')
      ->fields('i')
      ->condition('task_id', $taskId)
      ->orderBy('weight')
      ->orderBy('id')
      ->execute()
      ->fetchAll();
  }

  /**
   * Updates one image row.
   *
   * @param array<string, mixed> $fields
   *   Columns.
   */
  public function markItem(int $itemId, array $fields): void {
    $this->database->update('xedc_import_item')->fields($fields)->condition('id', $itemId)->execute();
  }

  /**
   * Puts failed images back in the queue.
   */
  public function resetFailed(int $taskId): void {
    $this->database->update('xedc_import_item')
      ->fields(['status' => 'pending', 'error' => ''])
      ->condition('task_id', $taskId)
      ->condition('status', 'failed')
      ->execute();
  }

  /**
   * Refreshes counters from item rows.
   *
   * @return array<string, int>
   *   success, failed, pending and bytes.
   */
  public function syncCounts(int $taskId): array {
    $counts = ['success' => 0, 'failed' => 0, 'pending' => 0, 'bytes' => 0];
    $rows = $this->database->query('SELECT status, COUNT(*) AS c, COALESCE(SUM(bytes), 0) AS b FROM {xedc_import_item} WHERE task_id = :id GROUP BY status', [
      ':id' => $taskId,
    ])->fetchAll();
    foreach ($rows as $row) {
      $status = (string) $row->status;
      if (isset($counts[$status])) {
        $counts[$status] = (int) $row->c;
      }
      if ($status === 'success') {
        $counts['bytes'] = (int) $row->b;
      }
    }
    $task = $this->load($taskId);
    $total = $task ? (int) $task->total : 0;
    $processed = $counts['success'] + $counts['failed'];
    $progress = $total > 0 ? min(99, 30 + (int) floor($processed / $total * 65)) : (int) ($task->progress ?? 0);
    $this->update($taskId, [
      'processed' => $processed,
      'success_count' => $counts['success'],
      'failed_count' => $counts['failed'],
      'bytes' => $counts['bytes'],
      'progress' => $progress,
    ]);
    return $counts;
  }

  /**
   * TRUE when a worker should not start another archive for this node.
   */
  public function hasActive(int $nid): bool {
    if ($nid < 1) {
      return FALSE;
    }
    $count = $this->database->select('xedc_import_task', 't')
      ->condition('nid', $nid)
      ->condition('status', array_merge([ImportStatus::QUEUED], ImportStatus::processing()), 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
    return (int) $count > 0;
  }

  /**
   * TRUE when the node must stay unpublished.
   */
  public function blocksPublish(int $nid): bool {
    $blocked = array_merge(ImportStatus::processing(), [
      ImportStatus::QUEUED,
      ImportStatus::FAILED,
      ImportStatus::PARTIAL,
    ]);
    $count = $this->database->select('xedc_import_task', 't')
      ->condition('nid', $nid)
      ->condition('status', $blocked, 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
    return (int) $count > 0;
  }

  /**
   * Tasks for one node, newest first.
   *
   * @return object[]
   *   Task rows.
   */
  public function forNode(int $nid): array {
    return $this->database->select('xedc_import_task', 't')
      ->fields('t')
      ->condition('nid', $nid)
      ->orderBy('id', 'DESC')
      ->range(0, 20)
      ->execute()
      ->fetchAll();
  }

  /**
   * Filtered task list.
   *
   * @return object[]
   *   Rows including node_title and user_name.
   */
  public function list(string $filter, ?int $uid, int $limit = 50): array {
    $query = $this->database->select('xedc_import_task', 't');
    $query->fields('t');
    $query->leftJoin('node_field_data', 'n', 'n.nid = t.nid AND n.default_langcode = 1');
    $query->addField('n', 'title', 'node_title');
    $query->leftJoin('users_field_data', 'u', 'u.uid = t.uid');
    $query->addField('u', 'name', 'user_name');
    if ($uid) {
      $query->condition('t.uid', $uid);
    }
    $statuses = $this->statusesForFilter($filter);
    if ($statuses) {
      $query->condition('t.status', $statuses, 'IN');
    }
    $query->orderBy('t.id', 'DESC');
    $query->range(0, $limit);
    return $query->execute()->fetchAll();
  }

  /**
   * @return string[]
   *   Status ids, or empty for every status.
   */
  public function statusesForFilter(string $filter): array {
    return match ($filter) {
      'queued' => [ImportStatus::QUEUED],
      'processing' => ImportStatus::processing(),
      'review' => [ImportStatus::REVIEW, ImportStatus::COMPLETED],
      'failed' => [ImportStatus::FAILED, ImportStatus::PARTIAL],
      'cancelled' => [ImportStatus::CANCELLED],
      'published' => [ImportStatus::PUBLISHED],
      default => [],
    };
  }

  /**
   * Marks review tasks published when the node is published elsewhere.
   */
  public function markNodePublished(int $nid, int $uid): void {
    $this->database->update('xedc_import_task')
      ->fields([
        'status' => ImportStatus::PUBLISHED,
        'stage' => ImportStatus::PUBLISHED,
        'progress' => 100,
        'published_uid' => $uid,
        'published_at' => time(),
        'changed' => time(),
        'message' => '已发布',
      ])
      ->condition('nid', $nid)
      ->condition('status', ImportStatus::REVIEW)
      ->execute();
  }

  /**
   * Tasks whose archives can be removed.
   *
   * @return object[]
   *   Task rows.
   */
  public function expired(int $now): array {
    return $this->database->select('xedc_import_task', 't')
      ->fields('t')
      ->condition('cleanup_after', 0, '>')
      ->condition('cleanup_after', $now, '<')
      ->condition('status', [ImportStatus::FAILED, ImportStatus::PARTIAL, ImportStatus::CANCELLED], 'IN')
      ->range(0, 20)
      ->execute()
      ->fetchAll();
  }

  /**
   * Active tasks that have not been touched recently.
   *
   * @return int[]
   *   Task ids.
   */
  public function stale(int $before): array {
    return array_map('intval', $this->database->select('xedc_import_task', 't')
      ->fields('t', ['id'])
      ->condition('status', array_merge([ImportStatus::QUEUED], ImportStatus::processing()), 'IN')
      ->condition('changed', $before, '<')
      ->range(0, 20)
      ->execute()
      ->fetchCol());
  }

  /**
   * Records that a person chose the cover.
   */
  public function lockCover(int $nid): void {
    $this->state->set('xedc_core.cover.' . $nid, 1);
  }

  /**
   * TRUE when a later import must not replace the cover.
   */
  public function coverLocked(int $nid): bool {
    return (bool) $this->state->get('xedc_core.cover.' . $nid);
  }

  /**
   * Clears the manual-cover flag after an explicit reroll.
   */
  public function unlockCover(int $nid): void {
    $this->state->delete('xedc_core.cover.' . $nid);
  }

}
