<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Usage;

use Drupal\xedc_ai\Budget\Money;

/**
 * Turns stored calls into labels that do not collapse every zero into “未核算”.
 */
final class UsageReport {

  /**
   * @param list<array<string, mixed>> $calls
   *   Usage rows for one review, oldest or newest.
   *
   * @return array<string, mixed>
   *   Totals and the sentence shown on the SEO page.
   */
  public static function summarize(array $calls, string $reviewStatus, string $errorCode): array {
    $finished = [];
    foreach ($calls as $call) {
      if ((string) ($call['call_state'] ?? 'finished') === 'finished') {
        $finished[] = $call;
      }
    }
    if ($finished === []) {
      if ($errorCode === 'cache') {
        return self::emptyReport('cache', '缓存命中，未再次调用', '缓存命中');
      }
      if ($reviewStatus !== 'completed') {
        return self::emptyReport('not_called', '尚未调用', '尚未调用');
      }
      return self::emptyReport('no_usage', 'Token 未返回', 'Token 未返回');
    }
    $prompt = 0;
    $completion = 0;
    $cache = 0;
    $reported = 0;
    $missing = 0;
    $cost = 0;
    $statuses = [];
    $rows = [];
    foreach ($finished as $call) {
      $known = self::reported($call);
      $promptTokens = (int) ($call['prompt_tokens'] ?? 0);
      $completionTokens = (int) ($call['completion_tokens'] ?? 0);
      $cacheTokens = (int) ($call['cache_read_tokens'] ?? 0);
      if ($known) {
        $reported++;
        $prompt += $promptTokens;
        $completion += $completionTokens;
        $cache += $cacheTokens;
      }
      else {
        $missing++;
      }
      $status = self::costStatus($call, $known);
      $statuses[] = $status;
      if (in_array($status, ['known', 'zero', 'cache_unpriced'], TRUE)) {
        $cost += (int) ($call['cost_micro'] ?? 0);
      }
      $rows[] = [
        'id' => (int) ($call['id'] ?? 0),
        'model' => trim((string) ($call['provider_id'] ?? '') . ' / ' . (string) ($call['model_name'] ?? ''), ' /'),
        'status' => (string) ($call['status'] ?? ''),
        'http_status' => (int) ($call['http_status'] ?? 0),
        'finish_reason' => (string) ($call['finish_reason'] ?? ''),
        'retry_index' => (int) ($call['retry_index'] ?? 0),
        'prompt' => $known ? $promptTokens : NULL,
        'completion' => $known ? $completionTokens : NULL,
        'cache' => $known ? $cacheTokens : NULL,
        'total' => $known ? $promptTokens + $completionTokens : NULL,
        'token_label' => $known ? (string) ($promptTokens + $completionTokens) : 'Token 未返回',
        'cost_label' => self::costLabel($status, (int) ($call['cost_micro'] ?? 0)),
        'created' => (int) ($call['created'] ?? 0),
      ];
    }
    $tokenLabel = $reported === 0 ? 'Token 未返回' : ($prompt + $completion === 0 ? 'Token 0' : 'Token ' . ($prompt + $completion));
    if ($reported > 0 && $missing > 0) {
      $tokenLabel .= '（另有 ' . $missing . ' 次调用未返回 Token）';
    }
    return [
      'state' => $reported === 0 ? 'no_usage' : 'recorded',
      'token_label' => $tokenLabel,
      'cost_label' => self::aggregateCost($statuses, $cost, $reported),
      'prompt_tokens' => $prompt,
      'completion_tokens' => $completion,
      'cache_read_tokens' => $cache,
      'total_tokens' => $prompt + $completion,
      'cost_micro' => $cost,
      'calls' => $rows,
    ];
  }

  /**
   * @param array<string, mixed> $call
   *   One usage row.
   */
  public static function reported(array $call): bool {
    $tokens = (int) ($call['prompt_tokens'] ?? 0) + (int) ($call['completion_tokens'] ?? 0);
    if (!array_key_exists('usage_reported', $call) || (string) $call['usage_reported'] === '') {
      return $tokens > 0;
    }
    if ((int) $call['usage_reported'] === 1) {
      return TRUE;
    }
    $status = (string) ($call['cost_status'] ?? '');
    return $status === '' && $tokens > 0;
  }

  /**
   * @param array<string, mixed> $call
   *   One usage row.
   */
  private static function costStatus(array $call, bool $reported): string {
    if (!$reported) {
      return 'no_usage';
    }
    $status = (string) ($call['cost_status'] ?? '');
    if (in_array($status, ['known', 'zero', 'unpriced', 'failed', 'cache_unpriced', 'no_usage'], TRUE)) {
      return $status;
    }
    if ((int) ($call['cost_known'] ?? 0) === 1) {
      return (int) ($call['cost_micro'] ?? 0) === 0 ? 'zero' : 'known';
    }
    return 'unpriced';
  }

  /**
   * @param list<string> $statuses
   *   Per-call cost statuses.
   */
  private static function aggregateCost(array $statuses, int $cost, int $reported): string {
    if ($reported === 0) {
      return 'Token 未返回';
    }
    if (in_array('failed', $statuses, TRUE)) {
      return 'Token 已记录 · 费用计算失败';
    }
    if (in_array('cache_unpriced', $statuses, TRUE)) {
      return 'Token 已记录 · ' . Money::yuan($cost) . ' 元 · 缓存单价未配置';
    }
    $priced = array_intersect($statuses, ['known', 'zero']);
    $unpriced = in_array('unpriced', $statuses, TRUE);
    if ($priced === [] && $unpriced) {
      return 'Token 已记录 · 单价未配置';
    }
    if ($unpriced) {
      return 'Token 已记录 · ' . Money::yuan($cost) . ' 元 · 部分调用单价未配置';
    }
    $onlyZero = $priced !== [] && array_diff($priced, ['zero']) === [];
    if ($onlyZero || $cost === 0) {
      return '费用为零';
    }
    return '已核算 ' . Money::yuan($cost) . ' 元';
  }

  private static function costLabel(string $status, int $cost): string {
    return match ($status) {
      'known' => '已核算 ' . Money::yuan($cost) . ' 元',
      'zero' => '费用为零',
      'unpriced' => '单价未配置',
      'failed' => '费用计算失败',
      'cache_unpriced' => Money::yuan($cost) . ' 元 · 缓存单价未配置',
      default => 'Token 未返回',
    };
  }

  /**
   * @return array<string, mixed>
   *   Report with no calls.
   */
  private static function emptyReport(string $state, string $tokens, string $cost): array {
    return [
      'state' => $state,
      'token_label' => $tokens,
      'cost_label' => $cost,
      'prompt_tokens' => 0,
      'completion_tokens' => 0,
      'cache_read_tokens' => 0,
      'total_tokens' => 0,
      'cost_micro' => 0,
      'calls' => [],
    ];
  }

}
