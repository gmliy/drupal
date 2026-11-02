<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Rows for one migration task. The file entity URI is not stored as a change.
 */
final class MigrationRepository {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  public function ready(): bool {
    return $this->database->schema()->tableExists('xedc_storage_migration_item');
  }

  public function clear(string $taskId): void {
    if (!$this->ready()) {
      return;
    }
    $this->database->delete('xedc_storage_migration_item')
      ->condition('task_id', $taskId)
      ->execute();
  }

  /**
   * @param array<string, mixed> $row
   *   Item fields.
   */
  public function insert(array $row): void {
    $row += [
      'sha256' => '',
      'remote_sha256' => '',
      'object_key' => '',
      'message' => '',
      'created' => $this->time->getRequestTime(),
      'changed' => $this->time->getRequestTime(),
    ];
    $this->database->insert('xedc_storage_migration_item')
      ->fields($row)
      ->execute();
  }

  /**
   * @return list<array<string, mixed>>
   *   Pending or failed items.
   */
  public function claim(string $taskId, int $limit, bool $includeFailed): array {
    $query = $this->database->select('xedc_storage_migration_item', 'm')
      ->fields('m')
      ->condition('task_id', $taskId)
      ->orderBy('id')
      ->range(0, max(1, $limit));
    if ($includeFailed) {
      $query->condition('status', ['pending', 'failed'], 'IN');
    }
    else {
      $query->condition('status', 'pending');
    }
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * @param array<string, mixed> $fields
   *   Columns to update.
   */
  public function update(int $id, array $fields): void {
    $fields['changed'] = $this->time->getRequestTime();
    $this->database->update('xedc_storage_migration_item')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * @return list<array<string, mixed>>
   *   Items in a status.
   */
  public function byStatus(string $taskId, array $statuses): array {
    $rows = $this->database->select('xedc_storage_migration_item', 'm')
      ->fields('m')
      ->condition('task_id', $taskId)
      ->condition('status', $statuses, 'IN')
      ->orderBy('id')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * @return list<array<string, mixed>>
   *   All items for a report.
   */
  public function all(string $taskId): array {
    $rows = $this->database->select('xedc_storage_migration_item', 'm')
      ->fields('m')
      ->condition('task_id', $taskId)
      ->orderBy('id')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * @return array<string, int>
   *   Counts by status and kind.
   */
  public function summary(string $taskId): array {
    $summary = [
      'items' => 0,
      'pending' => 0,
      'verified' => 0,
      'failed' => 0,
      'missing' => 0,
      'rolled_back' => 0,
      'image' => 0,
      'video' => 0,
      'archive' => 0,
      'other' => 0,
      'bytes' => 0,
    ];
    if (!$this->ready()) {
      return $summary;
    }
    $rows = $this->database->query('SELECT status, kind, COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS bytes FROM {xedc_storage_migration_item} WHERE task_id = :task GROUP BY status, kind', [
      ':task' => $taskId,
    ])->fetchAll();
    foreach ($rows as $row) {
      $count = (int) $row->n;
      $summary['items'] += $count;
      $summary['bytes'] += (int) $row->bytes;
      $status = (string) $row->status;
      $kind = (string) $row->kind;
      if (isset($summary[$status])) {
        $summary[$status] += $count;
      }
      if (isset($summary[$kind])) {
        $summary[$kind] += $count;
      }
    }
    return $summary;
  }

}
