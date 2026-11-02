<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Budget;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\xedc_ai\Provider\ModelProfile;

/**
 * Money and unpriced-token limits, including concurrent reservations.
 */
final class BudgetService {

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * @return array{allowed: bool, reason: string, estimate_micro: int, price_known: bool}
   *   Whether a new call may start.
   */
  public function authorize(ModelProfile $model, bool $automatic, bool $override): array {
    $settings = $this->configFactory->get('xedc_ai.settings');
    $prompt = 800;
    $completion = min($model->maxTokens, 800);
    $priceKnown = $model->priceKnown();
    $estimate = $priceKnown ? (Money::costMicro($prompt, $completion, $model->inputPrice, $model->outputPrice) ?? 0) : 0;
    $spent = $this->snapshot();
    if (!$priceKnown) {
      if ($automatic || !(bool) $settings->get('allow_unpriced_manual')) {
        return $this->decision(FALSE, '模型没有可靠单价，自动调用已暂停。Token 仍会记录，费用未核算。', 0, FALSE);
      }
      $cap = max(0, (int) $settings->get('unpriced_daily_tokens'));
      $projected = $spent['unpriced_tokens_today'] + $prompt + $completion;
      if ($projected > $cap && !$override) {
        return $this->decision(FALSE, '未标价模型已达到每日 Token 上限。费用未核算。', 0, FALSE);
      }
      if ($projected > $cap && $override && !(bool) $settings->get('allow_manual_over_budget')) {
        return $this->decision(FALSE, '当前配置不允许手动超过预算。', 0, FALSE);
      }
      return $this->decision(TRUE, '', 0, FALSE);
    }
    $dailyLimit = Money::toMicro((string) $settings->get('daily_budget')) ?? 0;
    $monthlyLimit = Money::toMicro((string) $settings->get('monthly_budget')) ?? 0;
    $dailyOver = ($spent['daily_micro'] + $spent['held_micro'] + $estimate) > $dailyLimit;
    $monthlyOver = ($spent['monthly_micro'] + $spent['held_micro'] + $estimate) > $monthlyLimit;
    if (!$dailyOver && !$monthlyOver) {
      return $this->decision(TRUE, '', $estimate, TRUE);
    }
    if ($automatic || !$override) {
      return $this->decision(FALSE, '已达到预算，新的自动调用已暂停。已完成的记录仍可查看。', $estimate, TRUE);
    }
    if (!(bool) $settings->get('allow_manual_over_budget')) {
      return $this->decision(FALSE, '当前配置不允许手动超过预算。', $estimate, TRUE);
    }
    return $this->decision(TRUE, '', $estimate, TRUE);
  }

  public function hold(int $reviewId, int $estimateMicro): int {
    if ($estimateMicro < 1) {
      return 0;
    }
    return (int) $this->database->insert('xedc_ai_hold')->fields([
      'created' => $this->time->getRequestTime(),
      'cost_micro' => $estimateMicro,
      'status' => 'held',
      'review_id' => $reviewId,
    ])->execute();
  }

  public function settle(int $holdId): void {
    if ($holdId < 1) {
      return;
    }
    $this->database->update('xedc_ai_hold')
      ->fields(['status' => 'settled'])
      ->condition('id', $holdId)
      ->execute();
  }

  public function release(int $holdId): void {
    if ($holdId < 1) {
      return;
    }
    $this->database->update('xedc_ai_hold')
      ->fields(['status' => 'released'])
      ->condition('id', $holdId)
      ->execute();
  }

  public function releaseReview(int $reviewId): void {
    $this->database->update('xedc_ai_hold')
      ->fields(['status' => 'released'])
      ->condition('review_id', $reviewId)
      ->condition('status', 'held')
      ->execute();
  }

  public function releaseStale(int $seconds = 1200): void {
    $this->database->update('xedc_ai_hold')
      ->fields(['status' => 'released'])
      ->condition('status', 'held')
      ->condition('created', $this->time->getRequestTime() - $seconds, '<')
      ->execute();
  }

  /**
   * @return array<string, int|string>
   *   Budget figures for the overview.
   */
  public function overview(): array {
    $settings = $this->configFactory->get('xedc_ai.settings');
    $spent = $this->snapshot();
    $daily = Money::toMicro((string) $settings->get('daily_budget')) ?? 0;
    $monthly = Money::toMicro((string) $settings->get('monthly_budget')) ?? 0;
    $dailyUsed = $spent['daily_micro'] + $spent['held_micro'];
    $monthlyUsed = $spent['monthly_micro'] + $spent['held_micro'];
    return [
      'daily_limit' => Money::yuan($daily),
      'monthly_limit' => Money::yuan($monthly),
      'daily_used' => Money::yuan($spent['daily_micro']),
      'monthly_used' => Money::yuan($spent['monthly_micro']),
      'daily_remaining' => Money::yuan(max(0, $daily - $dailyUsed)),
      'monthly_remaining' => Money::yuan(max(0, $monthly - $monthlyUsed)),
      'held' => Money::yuan($spent['held_micro']),
      'unpriced_tokens_today' => $spent['unpriced_tokens_today'],
      'today_tokens' => $spent['today_tokens'],
      'month_tokens' => $spent['month_tokens'],
      'today_calls' => $spent['today_calls'],
      'month_cost' => Money::yuan($spent['monthly_micro']),
      'priced_only' => TRUE,
    ];
  }

  /**
   * @return array{daily_micro: int, monthly_micro: int, held_micro: int, today_tokens: int, month_tokens: int, today_calls: int, unpriced_tokens_today: int}
   *   Current usage.
   */
  public function snapshot(): array {
    [$day, $month] = $this->bounds();
    return [
      'daily_micro' => $this->costSince($day),
      'monthly_micro' => $this->costSince($month),
      'held_micro' => $this->held(),
      'today_tokens' => $this->tokensSince($day),
      'month_tokens' => $this->tokensSince($month),
      'today_calls' => $this->callsSince($day),
      'unpriced_tokens_today' => $this->unpricedTokensSince($day),
    ];
  }

  /**
   * @return array{0: int, 1: int}
   *   Start of today and start of month as timestamps.
   */
  private function bounds(): array {
    $name = (string) ($this->configFactory->get('system.date')->get('timezone.default') ?: date_default_timezone_get());
    try {
      $zone = new \DateTimeZone($name);
    }
    catch (\Throwable) {
      $zone = new \DateTimeZone('UTC');
    }
    $today = (new \DateTimeImmutable('today', $zone))->getTimestamp();
    $month = (new \DateTimeImmutable('first day of this month midnight', $zone))->getTimestamp();
    return [$today, $month];
  }

  private function costSince(int $from): int {
    $query = $this->database->select('xedc_ai_usage', 'u');
    $query->condition('call_state', 'finished');
    $query->condition('cost_known', 1);
    $query->condition('created', $from, '>=');
    $query->addExpression('COALESCE(SUM(cost_micro), 0)', 'total');
    return (int) $query->execute()->fetchField();
  }

  private function tokensSince(int $from): int {
    $query = $this->database->select('xedc_ai_usage', 'u');
    $query->condition('call_state', 'finished');
    $query->condition('created', $from, '>=');
    $query->addExpression('COALESCE(SUM(total_tokens), 0)', 'total');
    return (int) $query->execute()->fetchField();
  }

  private function unpricedTokensSince(int $from): int {
    $query = $this->database->select('xedc_ai_usage', 'u');
    $query->condition('call_state', 'finished');
    $query->condition('cost_known', 0);
    $query->condition('created', $from, '>=');
    $query->addExpression('COALESCE(SUM(total_tokens), 0)', 'total');
    return (int) $query->execute()->fetchField();
  }

  private function callsSince(int $from): int {
    $query = $this->database->select('xedc_ai_usage', 'u');
    $query->condition('call_state', 'finished');
    $query->condition('created', $from, '>=');
    return (int) $query->countQuery()->execute()->fetchField();
  }

  private function held(): int {
    $query = $this->database->select('xedc_ai_hold', 'h');
    $query->condition('status', 'held');
    $query->addExpression('COALESCE(SUM(cost_micro), 0)', 'total');
    return (int) $query->execute()->fetchField();
  }

  /**
   * @return array{allowed: bool, reason: string, estimate_micro: int, price_known: bool}
   *   Decision payload.
   */
  private function decision(bool $allowed, string $reason, int $estimate, bool $priceKnown): array {
    return [
      'allowed' => $allowed,
      'reason' => $reason,
      'estimate_micro' => $estimate,
      'price_known' => $priceKnown,
    ];
  }

}
