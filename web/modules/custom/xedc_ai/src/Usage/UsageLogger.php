<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Usage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\xedc_ai\Client\ChatResponse;
use Drupal\xedc_ai\Client\Redactor;

/**
 * Call log. Prompts, responses and API keys are not stored.
 */
final class UsageLogger {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  public function start(int $reviewId, string $source, string $providerId, string $modelName, int $uid): int {
    return (int) $this->database->insert('xedc_ai_usage')->fields([
      'created' => $this->time->getRequestTime(),
      'source' => $source,
      'provider_id' => $providerId,
      'model_name' => $modelName,
      'status' => 'started',
      'prompt_tokens' => 0,
      'completion_tokens' => 0,
      'total_tokens' => 0,
      'duration_ms' => 0,
      'cost_micro' => 0,
      'cost_known' => 0,
      'error_code' => '',
      'error_message' => '',
      'review_id' => $reviewId,
      'uid' => $uid,
      'call_state' => 'started',
    ])->execute();
  }

  public function finish(int $usageId, ChatResponse $response, ?int $costMicro, int $uid): void {
    $known = $costMicro !== NULL;
    $this->database->update('xedc_ai_usage')->fields([
      'provider_id' => $response->providerId,
      'model_name' => $response->modelName,
      'status' => $response->ok ? 'success' : 'error',
      'prompt_tokens' => $response->promptTokens,
      'completion_tokens' => $response->completionTokens,
      'total_tokens' => $response->promptTokens + $response->completionTokens,
      'duration_ms' => $response->durationMs,
      'cost_micro' => $known ? $costMicro : 0,
      'cost_known' => $known ? 1 : 0,
      'error_code' => $response->errorCode,
      'error_message' => Redactor::text($response->errorMessage),
      'uid' => $uid,
      'call_state' => 'finished',
    ])->condition('id', $usageId)->execute();
  }

  public function interrupt(int $usageId, string $message): void {
    $this->database->update('xedc_ai_usage')->fields([
      'status' => 'error',
      'error_code' => 'interrupted',
      'error_message' => Redactor::text($message),
      'call_state' => 'finished',
    ])->condition('id', $usageId)->execute();
  }

  public function openCall(int $reviewId): ?array {
    $row = $this->database->select('xedc_ai_usage', 'u')
      ->fields('u')
      ->condition('review_id', $reviewId)
      ->condition('call_state', 'started')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * @param array<string, mixed> $filters
   *   Query filters.
   *
   * @return array{rows: list<array<string, mixed>>, total_tokens: int, cost_micro: int, unpriced: int}
   *   Page and totals for the current filter.
   */
  public function search(array $filters): array {
    $query = $this->database->select('xedc_ai_usage', 'u')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->fields('u')
      ->condition('call_state', 'finished')
      ->orderBy('id', 'DESC')
      ->limit(30);
    $this->apply($query, $filters);
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $totals = $this->database->select('xedc_ai_usage', 'u');
    $totals->addExpression('COALESCE(SUM(total_tokens), 0)', 'tokens');
    $totals->addExpression('COALESCE(SUM(CASE WHEN cost_known = 1 THEN cost_micro ELSE 0 END), 0)', 'cost');
    $totals->addExpression('COALESCE(SUM(CASE WHEN cost_known = 0 THEN 1 ELSE 0 END), 0)', 'unpriced');
    $totals->condition('call_state', 'finished');
    $this->apply($totals, $filters);
    $summary = $totals->execute()->fetchAssoc() ?: [];
    return [
      'rows' => $rows,
      'total_tokens' => (int) ($summary['tokens'] ?? 0),
      'cost_micro' => (int) ($summary['cost'] ?? 0),
      'unpriced' => (int) ($summary['unpriced'] ?? 0),
    ];
  }

  /**
   * @return list<array<string, mixed>>
   *   Latest finished calls.
   */
  /**
   * @return array{tokens: int, cost_micro: int, known: bool}
   *   Stored usage for one review.
   */
  public function totalsForReview(int $reviewId): array {
    $query = $this->database->select('xedc_ai_usage', 'u');
    $query->addExpression('COALESCE(SUM(total_tokens), 0)', 'tokens');
    $query->addExpression('COALESCE(SUM(CASE WHEN cost_known = 1 THEN cost_micro ELSE 0 END), 0)', 'cost');
    $query->addExpression('COALESCE(SUM(cost_known), 0)', 'known');
    $query->condition('review_id', $reviewId);
    $query->condition('call_state', 'finished');
    $row = $query->execute()->fetchAssoc() ?: [];
    return [
      'tokens' => (int) ($row['tokens'] ?? 0),
      'cost_micro' => (int) ($row['cost'] ?? 0),
      'known' => (int) ($row['known'] ?? 0) > 0,
    ];
  }

  public function recent(int $limit): array {
    return $this->database->select('xedc_ai_usage', 'u')
      ->fields('u')
      ->condition('call_state', 'finished')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   Select being filtered.
   * @param array<string, mixed> $filters
   *   Filters.
   */
  private function apply($query, array $filters): void {
    foreach (['source' => 'source', 'provider_id' => 'provider_id', 'model_name' => 'model_name', 'status' => 'status'] as $input => $column) {
      $value = trim((string) ($filters[$input] ?? ''));
      if ($value !== '') {
        $query->condition($column, $value);
      }
    }
    if (!empty($filters['from'])) {
      $query->condition('created', (int) $filters['from'], '>=');
    }
    if (!empty($filters['to'])) {
      $query->condition('created', (int) $filters['to'], '<=');
    }
  }

}
