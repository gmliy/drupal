<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Usage;

use Drupal\Core\Database\Connection;
use Drupal\xedc_ai\Budget\Money;
use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Review\DuplicateFinder;

/**
 * Dashboard numbers from stored review and usage rows.
 */
final class OperationsStats {

  public function __construct(
    private readonly Connection $database,
    private readonly DuplicateFinder $duplicates,
  ) {}

  /**
   * @return array<string, int|string>
   *   Card values.
   */
  public function cards(array $budget): array {
    $today = $this->dayStart();
    $month = $this->monthStart();
    $counts = $this->database->query('SELECT status, COUNT(*) AS total FROM {xedc_ai_review} GROUP BY status')->fetchAllKeyed();
    $passed = $this->countCompletedRisk(['low', 'normal']);
    $changes = $this->countCompletedRisk(['medium']);
    $high = $this->countCompletedRisk(['high']);
    return [
      'today_reviews' => $this->countReviewsSince($today),
      'total_reviews' => array_sum(array_map('intval', $counts)),
      'pending' => (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0),
      'passed' => $passed,
      'changes' => $changes,
      'high' => $high,
      'failed' => (int) ($counts['failed'] ?? 0),
      'duplicates' => $this->duplicates->duplicateFileCount(),
      'today_calls' => (int) $budget['today_calls'],
      'today_tokens' => (int) $budget['today_tokens'],
      'month_cost' => (string) $budget['month_cost'],
      'month_reviews' => $this->countReviewsSince($month),
    ];
  }

  /**
   * @return list<array{label: string, value: int, percent: int}>
   *   Local-day review counts, including real zeros.
   */
  public function dailyReviews(int $days = 14): array {
    $items = [];
    $start = $this->dayStart();
    for ($i = $days - 1; $i >= 0; $i--) {
      $from = $start - ($i * 86400);
      $items[] = [
        'label' => date('m-d', $from),
        'value' => $this->countReviewsBetween($from, $from + 86400),
      ];
    }
    return $this->bars($items);
  }

  /**
   * @return list<array{label: string, value: int, percent: int}>
   *   Reviews by content type.
   */
  public function bundles(): array {
    $query = $this->database->select('xedc_ai_review', 'r');
    $query->addField('r', 'bundle');
    $query->addExpression('COUNT(*)', 'total');
    $query->groupBy('bundle');
    $query->orderBy('total', 'DESC');
    $items = [];
    foreach ($query->execute() as $row) {
      $items[] = [
        'label' => Labels::bundle((string) $row->bundle),
        'value' => (int) $row->total,
      ];
    }
    return $this->bars($items);
  }

  /**
   * @return list<array{label: string, value: int, percent: int}>
   *   Completed reviews by stored risk.
   */
  public function risks(): array {
    $query = $this->database->select('xedc_ai_review', 'r');
    $query->addField('r', 'risk_level');
    $query->addExpression('COUNT(*)', 'total');
    $query->condition('status', 'completed');
    $query->groupBy('risk_level');
    $items = [];
    foreach ($query->execute() as $row) {
      $risk = (string) $row->risk_level;
      $items[] = [
        'label' => Labels::risk($risk),
        'value' => (int) $row->total,
      ];
    }
    return $this->bars($items);
  }

  /**
   * @return list<array{label: string, value: int, percent: int}>
   *   Finished calls by model.
   */
  public function models(): array {
    if (!$this->database->schema()->tableExists('xedc_ai_usage')) {
      return [];
    }
    $query = $this->database->select('xedc_ai_usage', 'u');
    $query->addField('u', 'provider_id');
    $query->addField('u', 'model_name');
    $query->addExpression('SUM(total_tokens)', 'tokens');
    $query->condition('call_state', 'finished');
    $query->groupBy('provider_id');
    $query->groupBy('model_name');
    $query->orderBy('tokens', 'DESC');
    $query->range(0, 8);
    $items = [];
    foreach ($query->execute() as $row) {
      $items[] = [
        'label' => trim((string) $row->provider_id . ' / ' . (string) $row->model_name, ' /'),
        'value' => (int) $row->tokens,
      ];
    }
    return $this->bars($items);
  }

  /**
   * @return list<array{label: string, value: int, percent: int, cost: string}>
   *   Known cost per local day, in micro-yuan for the bar.
   */
  public function dailyCost(int $days = 14): array {
    $items = [];
    $start = $this->dayStart();
    for ($i = $days - 1; $i >= 0; $i--) {
      $from = $start - ($i * 86400);
      $micro = 0;
      if ($this->database->schema()->tableExists('xedc_ai_usage')) {
        $query = $this->database->select('xedc_ai_usage', 'u');
        $query->addExpression('COALESCE(SUM(cost_micro), 0)', 'cost');
        $query->condition('call_state', 'finished');
        $query->condition('cost_known', 1);
        $query->condition('created', $from, '>=');
        $query->condition('created', $from + 86400, '<');
        $micro = (int) $query->execute()->fetchField();
      }
      $items[] = [
        'label' => date('m-d', $from),
        'value' => $micro,
        'cost' => Money::yuan($micro),
      ];
    }
    return $this->bars($items);
  }

  /**
   * @param list<string> $risks
   *   Stored risk values.
   */
  private function countCompletedRisk(array $risks): int {
    return (int) $this->database->select('xedc_ai_review', 'r')
      ->condition('status', 'completed')
      ->condition('risk_level', $risks, 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  private function countReviewsSince(int $from): int {
    return $this->countReviewsBetween($from, PHP_INT_MAX);
  }

  private function countReviewsBetween(int $from, int $to): int {
    $query = $this->database->select('xedc_ai_review', 'r')
      ->condition('created', $from, '>=');
    if ($to < PHP_INT_MAX) {
      $query->condition('created', $to, '<');
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  private function dayStart(): int {
    $zone = new \DateTimeZone(date_default_timezone_get());
    return (new \DateTimeImmutable('today', $zone))->getTimestamp();
  }

  private function monthStart(): int {
    $zone = new \DateTimeZone(date_default_timezone_get());
    return (new \DateTimeImmutable('first day of this month midnight', $zone))->getTimestamp();
  }

  /**
   * @param list<array<string, int|string>> $items
   *   Rows with a numeric value.
   *
   * @return list<array<string, int|string>>
   *   Same rows plus a percent.
   */
  private function bars(array $items): array {
    $max = 0;
    foreach ($items as $item) {
      $max = max($max, (int) $item['value']);
    }
    foreach ($items as &$item) {
      $item['percent'] = $max > 0 ? (int) round(((int) $item['value']) / $max * 100) : 0;
    }
    unset($item);
    return $items;
  }

}
