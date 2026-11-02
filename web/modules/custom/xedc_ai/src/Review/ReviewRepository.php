<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * AI review rows. They are not Drupal moderation states.
 */
final class ReviewRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * @param array<string, mixed> $values
   *   Review fields.
   */
  public function create(array $values): int {
    $now = $this->time->getRequestTime();
    return (int) $this->database->insert('xedc_ai_review')->fields([
      'entity_type' => (string) $values['entity_type'],
      'entity_id' => (int) $values['entity_id'],
      'revision_id' => (int) $values['revision_id'],
      'langcode' => (string) $values['langcode'],
      'bundle' => (string) $values['bundle'],
      'fingerprint' => (string) $values['fingerprint'],
      'field_hash' => (string) $values['field_hash'],
      'model_id' => (int) $values['model_id'],
      'provider_id' => (string) $values['provider_id'],
      'model_name' => (string) $values['model_name'],
      'rule_version' => (int) $values['rule_version'],
      'source' => (string) $values['source'],
      'depth' => (string) $values['depth'],
      'force_call' => (int) (!empty($values['force_call'])),
      'allow_over_budget' => (int) (!empty($values['allow_over_budget'])),
      'status' => 'pending',
      'risk_level' => '',
      'score' => NULL,
      'seo_score' => NULL,
      'issues' => '[]',
      'suggestions' => '[]',
      'summary' => '',
      'result' => '',
      'local_checks' => '',
      'error_code' => '',
      'error_message' => '',
      'attempts' => 0,
      'is_submission' => (int) (!empty($values['is_submission'])),
      'handled_status' => 'open',
      'handled_uid' => 0,
      'handled_at' => 0,
      'moderation_state' => (string) ($values['moderation_state'] ?? ''),
      'moderation_workflow' => (string) ($values['moderation_workflow'] ?? ''),
      'title' => (string) ($values['title'] ?? ''),
      'task_note' => substr((string) ($values['task_note'] ?? ''), 0, 64),
      'uid' => (int) ($values['uid'] ?? 0),
      'created' => $now,
      'changed' => $now,
      'completed' => 0,
    ])->execute();
  }

  /**
   * @return array<string, mixed>|null
   *   Review row.
   */
  public function load(int $id): ?array {
    $row = $this->database->select('xedc_ai_review', 'r')
      ->fields('r')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * @param array<string, mixed> $fields
   *   Changed fields.
   */
  public function update(int $id, array $fields): void {
    $fields['changed'] = $this->time->getRequestTime();
    $this->database->update('xedc_ai_review')->fields($fields)->condition('id', $id)->execute();
  }

  /**
   * @return array<string, mixed>|null
   *   Completed row with the same fingerprint.
   */
  /**
   * @return array<string, mixed>|null
   *   Pending row for the same fingerprint.
   */
  public function findOpen(string $fingerprint): ?array {
    $row = $this->database->select('xedc_ai_review', 'r')
      ->fields('r')
      ->condition('fingerprint', $fingerprint)
      ->condition('status', ['pending', 'processing'], 'IN')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  public function findReusable(string $fingerprint, int $exceptId): ?array {
    $query = $this->database->select('xedc_ai_review', 'r')
      ->fields('r')
      ->condition('fingerprint', $fingerprint)
      ->condition('status', 'completed')
      ->condition('id', $exceptId, '<>')
      ->orderBy('id', 'DESC')
      ->range(0, 1);
    $row = $query->execute()->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * Reuses a completed result when the text, model and rules are unchanged.
   *
   * A new revision with the same field hash must not cause another API call.
   * A different model id never matches.
   *
   * @param array<string, mixed> $review
   *   Review waiting to run.
   */
  public function findReusableText(array $review, int $exceptId): ?array {
    if ((int) $review['model_id'] < 1 || (string) $review['field_hash'] === '') {
      return NULL;
    }
    $row = $this->database->select('xedc_ai_review', 'r')
      ->fields('r')
      ->condition('entity_type', (string) $review['entity_type'])
      ->condition('entity_id', (int) $review['entity_id'])
      ->condition('field_hash', (string) $review['field_hash'])
      ->condition('model_id', (int) $review['model_id'])
      ->condition('rule_version', (int) $review['rule_version'])
      ->condition('source', (string) $review['source'])
      ->condition('depth', (string) $review['depth'])
      ->condition('status', 'completed')
      ->condition('error_code', 'invalid_json', '<>')
      ->condition('id', $exceptId, '<>')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  public function countProcessing(int $exceptId): int {
    $query = $this->database->select('xedc_ai_review', 'r')
      ->condition('status', 'processing')
      ->condition('changed', $this->time->getRequestTime() - 900, '>=');
    if ($exceptId > 0) {
      $query->condition('id', $exceptId, '<>');
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * @return array<string, int>
   *   Dashboard counts.
   */
  public function counts(): array {
    $status = $this->database->query("SELECT status, COUNT(*) FROM {xedc_ai_review} GROUP BY status")->fetchAllKeyed();
    $high = (int) $this->database->query("SELECT COUNT(*) FROM {xedc_ai_review} WHERE status = :status AND risk_level = :risk", [
      ':status' => 'completed',
      ':risk' => 'high',
    ])->fetchField();
    $changes = (int) $this->database->query("SELECT COUNT(*) FROM {xedc_ai_review} WHERE status = :status AND handled_status = :handled AND risk_level IN (:medium, :high)", [
      ':status' => 'completed',
      ':handled' => 'open',
      ':medium' => 'medium',
      ':high' => 'high',
    ])->fetchField();
    return [
      'pending' => (int) ($status['pending'] ?? 0) + (int) ($status['processing'] ?? 0),
      'completed' => (int) ($status['completed'] ?? 0),
      'failed' => (int) ($status['failed'] ?? 0),
      'ignored' => (int) ($status['ignored'] ?? 0),
      'high' => $high,
      'changes' => $changes,
    ];
  }

  /**
   * @return list<array<string, mixed>>
   *   Latest reviews.
   */
  /**
   * @return list<array<string, mixed>>
   *   Older reviews for one entity, newest first.
   */
  public function history(string $entityType, int $entityId, int $limit = 12): array {
    return $this->database->select('xedc_ai_review', 'r')
      ->fields('r')
      ->condition('entity_type', $entityType)
      ->condition('entity_id', $entityId)
      ->orderBy('id', 'DESC')
      ->range(0, max(1, $limit))
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function recent(int $limit, string $source = ''): array {
    $query = $this->database->select('xedc_ai_review', 'r')
      ->fields('r')
      ->orderBy('id', 'DESC')
      ->range(0, $limit);
    if ($source !== '') {
      $query->condition('source', $source);
    }
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * @param array<string, mixed> $filters
   *   List filters.
   *
   * @return list<array<string, mixed>>
   *   Current page.
   */
  public function search(array $filters): array {
    $query = $this->database->select('xedc_ai_review', 'r')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->fields('r')
      ->orderBy('r.id', 'DESC')
      ->limit(20);
    $this->apply($query, $filters);
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function countForProvider(string $providerId): int {
    return (int) $this->database->select('xedc_ai_review', 'r')
      ->condition('provider_id', $providerId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  public function countForModel(int $modelId): int {
    return (int) $this->database->select('xedc_ai_review', 'r')
      ->condition('model_id', $modelId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  public function deleteForEntity(string $entityType, int $entityId): void {
    $this->database->delete('xedc_ai_review')
      ->condition('entity_type', $entityType)
      ->condition('entity_id', $entityId)
      ->execute();
  }

  public function failStuck(int $seconds = 900): void {
    $this->database->update('xedc_ai_review')
      ->fields([
        'status' => 'failed',
        'error_code' => 'stuck',
        'error_message' => '审核任务超时，已停止自动重试。',
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('status', 'processing')
      ->condition('changed', $this->time->getRequestTime() - $seconds, '<')
      ->execute();
  }

  /**
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   Select being filtered.
   * @param array<string, mixed> $filters
   *   Filters.
   */
  private function apply($query, array $filters): void {
    foreach ([
      'bundle' => 'bundle',
      'status' => 'status',
      'risk_level' => 'risk_level',
      'source' => 'source',
    ] as $input => $column) {
      $value = trim((string) ($filters[$input] ?? ''));
      if ($value !== '') {
        $query->condition('r.' . $column, $value);
      }
    }
    if (($filters['submission'] ?? '') === '1') {
      $query->condition('r.is_submission', 1);
    }
    if (!empty($filters['from'])) {
      $query->condition('r.created', (int) $filters['from'], '>=');
    }
    if (!empty($filters['to'])) {
      $query->condition('r.created', (int) $filters['to'], '<=');
    }
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
      $query->condition('r.title', '%' . $this->database->escapeLike($search) . '%', 'LIKE');
    }
  }

}
