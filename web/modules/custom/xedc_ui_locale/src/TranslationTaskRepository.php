<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Stores interface-translation jobs, string status and logs.
 */
final class TranslationTaskRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * @return array<string, mixed>
   */
  /**
   * @return list<string>
   */
  public function jobModules(): array {
    if (!$this->database->schema()->tableExists('xedc_ui_locale_job')) {
      return [];
    }
    return $this->database->select('xedc_ui_locale_job', 'j')
      ->fields('j', ['module'])
      ->orderBy('module')
      ->execute()
      ->fetchCol();
  }

  public function getJob(string $module): array {
    $job = $this->database->select('xedc_ui_locale_job', 'j')
      ->fields('j')
      ->condition('module', $module)
      ->execute()
      ->fetchAssoc();
    return is_array($job) ? $job : [];
  }

  /**
   * @param array<string, mixed> $fields
   */
  public function ensureJob(string $module, string $operation, bool $reset_official): void {
    $now = $this->time->getRequestTime();
    $existing = $this->getJob($module);
    if ($existing === []) {
      $this->database->insert('xedc_ui_locale_job')
        ->fields([
          'module' => $module,
          'status' => 'pending',
          'operation' => $operation,
          'message' => '',
          'official_file' => '',
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
      return;
    }
    $fields = [
      'operation' => $operation,
      'status' => 'pending',
      'changed' => $now,
    ];
    if ($reset_official) {
      $fields['official_done'] = 0;
      $fields['official_seek'] = 0;
      $fields['official_file'] = '';
      $fields['message'] = '';
    }
    $this->updateJob($module, $fields);
  }

  /**
   * @param array<string, mixed> $fields
   */
  public function updateJob(string $module, array $fields): void {
    $fields['changed'] = $this->time->getRequestTime();
    $this->database->update('xedc_ui_locale_job')
      ->fields($fields)
      ->condition('module', $module)
      ->execute();
  }

  /**
   * @param array<string, mixed> $fields
   */
  public function upsertItem(string $module, string $source, string $context, array $fields): void {
    $keys = [
      'module' => $module,
      'source_hash' => hash('sha256', $source),
      'context_hash' => hash('sha256', $context),
    ];
    $fields += [
      'source' => $source,
      'context' => mb_substr($context, 0, 255),
      'translation' => '',
      'origin' => '',
      'status' => 'pending',
      'reason' => '',
      'lid' => 0,
      'changed' => $this->time->getRequestTime(),
    ];
    $this->database->merge('xedc_ui_locale_item')
      ->keys($keys)
      ->fields($fields)
      ->execute();
  }

  /**
   * @return array<string, mixed>
   */
  public function findItem(string $module, string $source, string $context): array {
    $row = $this->database->select('xedc_ui_locale_item', 'i')
      ->fields('i')
      ->condition('module', $module)
      ->condition('source_hash', hash('sha256', $source))
      ->condition('context_hash', hash('sha256', $context))
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : [];
  }

  public function isAiOrigin(string $source, string $context): bool {
    $query = $this->database->select('xedc_ui_locale_item', 'i')
      ->condition('source_hash', hash('sha256', $source))
      ->condition('context_hash', hash('sha256', $context))
      ->condition('origin', 'ai');
    $query->addExpression('COUNT(*)');
    return (int) $query->execute()->fetchField() > 0;
  }

  public function markBySource(string $source, string $context, array $fields): void {
    $fields['changed'] = $this->time->getRequestTime();
    $this->database->update('xedc_ui_locale_item')
      ->fields($fields)
      ->condition('source_hash', hash('sha256', $source))
      ->condition('context_hash', hash('sha256', $context))
      ->execute();
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function itemsByStatus(string $module, array $statuses, int $limit): array {
    if ($statuses === []) {
      return [];
    }
    $rows = $this->database->select('xedc_ui_locale_item', 'i')
      ->fields('i')
      ->condition('module', $module)
      ->condition('status', $statuses, 'IN')
      ->orderBy('id')
      ->range(0, $limit)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    return array_values($rows);
  }

  public function resetFailed(string $module): int {
    return (int) $this->database->update('xedc_ui_locale_item')
      ->fields([
        'status' => 'pending',
        'reason' => '',
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('module', $module)
      ->condition('status', ['failed', 'retry'], 'IN')
      ->execute();
  }

  public function recount(string $module): void {
    $counts = [
      'official_count' => 0,
      'ai_count' => 0,
      'existing_count' => 0,
      'protected_count' => 0,
      'failed_count' => 0,
      'remaining_count' => 0,
    ];
    $query = $this->database->select('xedc_ui_locale_item', 'i');
    $query->addField('i', 'origin');
    $query->addField('i', 'status');
    $query->addExpression('COUNT(*)', 'total');
    $query->condition('module', $module);
    $query->groupBy('origin');
    $query->groupBy('status');
    foreach ($query->execute() as $row) {
      $total = (int) $row->total;
      if ($row->origin === 'official') {
        $counts['official_count'] += $total;
      }
      elseif ($row->origin === 'ai') {
        $counts['ai_count'] += $total;
      }
      elseif ($row->origin === 'existing') {
        $counts['existing_count'] += $total;
      }
      elseif ($row->origin === 'protected') {
        $counts['protected_count'] += $total;
      }
      if (in_array($row->status, ['failed', 'retry'], TRUE)) {
        $counts['failed_count'] += $total;
      }
      if (in_array($row->status, ['pending', 'retry'], TRUE)) {
        $counts['remaining_count'] += $total;
      }
    }
    $this->updateJob($module, $counts);
  }

  /**
   * @return array<string, int>
   */
  public function statistics(int $enabled_modules): array {
    $jobs = (int) $this->database->select('xedc_ui_locale_job', 'j')->countQuery()->execute()->fetchField();
    $sums = $this->database->select('xedc_ui_locale_job', 'j');
    $sums->addExpression('COALESCE(SUM(official_count), 0)', 'official');
    $sums->addExpression('COALESCE(SUM(ai_count), 0)', 'ai');
    $sums->addExpression('COALESCE(SUM(existing_count), 0)', 'existing');
    $sums->addExpression('COALESCE(SUM(failed_count), 0)', 'failed');
    $sums->addExpression('COALESCE(SUM(remaining_count), 0)', 'remaining');
    $row = $sums->execute()->fetchAssoc() ?: [];
    return [
      'enabled' => $enabled_modules,
      'scanned' => $jobs,
      'official' => (int) ($row['official'] ?? 0),
      'ai' => (int) ($row['ai'] ?? 0),
      'existing' => (int) ($row['existing'] ?? 0),
      'remaining' => (int) ($row['remaining'] ?? 0),
      'failed' => (int) ($row['failed'] ?? 0),
    ];
  }

  /**
   * @return array{rows: list<array<string, mixed>>, total: int}
   */
  public function browse(array $filters, int $limit, int $offset): array {
    $query = $this->database->select('xedc_ui_locale_item', 'i')->fields('i');
    $this->applyFilters($query, $filters);
    $count = (int) (clone $query)->countQuery()->execute()->fetchField();
    $query->orderBy('changed', 'DESC');
    $query->range($offset, $limit);
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return [
      'rows' => $rows,
      'total' => $count,
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function untranslated(int $limit, string $module = ''): array {
    $filters = ['untranslated' => 1];
    if ($module !== '') {
      $filters['module'] = $module;
    }
    return $this->browse($filters, $limit, 0)['rows'];
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function recentLogs(int $limit): array {
    return $this->database->select('xedc_ui_locale_log', 'l')
      ->fields('l')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function log(string $module, string $level, string $message): void {
    $this->database->insert('xedc_ui_locale_log')
      ->fields([
        'module' => mb_substr($module, 0, 128),
        'level' => mb_substr($level, 0, 16),
        'message' => mb_substr($message, 0, 2000),
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * @param array<string, mixed> $filters
   */
  private function applyFilters($query, array $filters): void {
    if (!empty($filters['module'])) {
      $query->condition('module', (string) $filters['module']);
    }
    if (!empty($filters['status'])) {
      $query->condition('status', (string) $filters['status']);
    }
    if (!empty($filters['untranslated'])) {
      $untranslated = $query->orConditionGroup()
        ->condition('status', ['pending', 'failed', 'retry'], 'IN');
      $skipped = $query->andConditionGroup()
        ->condition('status', 'skipped')
        ->condition('origin', 'protected', '<>');
      $untranslated->condition($skipped);
      $query->condition($untranslated);
    }
  }

}
