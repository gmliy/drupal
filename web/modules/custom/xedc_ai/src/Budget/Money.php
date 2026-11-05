<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Budget;

/**
 * Yuan amounts stored as micro-yuan integers. One yuan is 1000000.
 */
final class Money {

  public static function toMicro(string $yuan): ?int {
    $yuan = trim($yuan);
    if ($yuan === '' || !preg_match('/^\d+(\.\d{1,6})?$/', $yuan)) {
      return NULL;
    }
    [$whole, $fraction] = array_pad(explode('.', $yuan, 2), 2, '');
    $fraction = str_pad(substr($fraction, 0, 6), 6, '0');
    return ((int) $whole) * 1000000 + (int) $fraction;
  }

  public static function yuan(int $micro): string {
    $negative = $micro < 0;
    $micro = abs($micro);
    $whole = intdiv($micro, 1000000);
    $fraction = str_pad((string) ($micro % 1000000), 6, '0', STR_PAD_LEFT);
    $fraction = rtrim($fraction, '0');
    if (strlen($fraction) < 2) {
      $fraction = str_pad($fraction, 2, '0');
    }
    return ($negative ? '-' : '') . $whole . '.' . $fraction;
  }

  /**
   * Cost in micro-yuan. Prices are CNY per one million tokens.
   */
  public static function costMicro(int $promptTokens, int $completionTokens, string $inputPerMillion, string $outputPerMillion): ?int {
    $quote = self::quote($promptTokens, $completionTokens, 0, $inputPerMillion, $outputPerMillion, '');
    if (!in_array($quote['status'], ['known', 'zero'], TRUE)) {
      return NULL;
    }
    return $quote['total'];
  }

  /**
   * Prices are CNY per one million tokens. Cache hits are billed separately.
   *
   * @return array{status: string, input: int, output: int, cache: int, total: int}
   *   status is known, zero, unpriced, cache_unpriced, or failed.
   */
  public static function quote(int $promptTokens, int $completionTokens, int $cacheTokens, string $inputPerMillion, string $outputPerMillion, string $cachePerMillion): array {
    $empty = ['status' => 'unpriced', 'input' => 0, 'output' => 0, 'cache' => 0, 'total' => 0];
    $inputPerMillion = trim($inputPerMillion);
    $outputPerMillion = trim($outputPerMillion);
    $cachePerMillion = trim($cachePerMillion);
    if ($inputPerMillion === '' || $outputPerMillion === '') {
      return $empty;
    }
    $input = self::toMicro($inputPerMillion);
    $output = self::toMicro($outputPerMillion);
    if ($input === NULL || $output === NULL) {
      $empty['status'] = 'failed';
      return $empty;
    }
    $promptTokens = max(0, $promptTokens);
    $completionTokens = max(0, $completionTokens);
    $cacheTokens = min($promptTokens, max(0, $cacheTokens));
    $miss = $promptTokens - $cacheTokens;
    $cacheCost = 0;
    $status = 'known';
    if ($cacheTokens > 0) {
      if ($cachePerMillion === '') {
        $status = 'cache_unpriced';
      }
      else {
        $cache = self::toMicro($cachePerMillion);
        if ($cache === NULL) {
          $empty['status'] = 'failed';
          return $empty;
        }
        $cacheCost = intdiv($cacheTokens * $cache, 1000000);
      }
    }
    $inputCost = intdiv($miss * $input, 1000000);
    $outputCost = intdiv($completionTokens * $output, 1000000);
    $total = $inputCost + $outputCost + $cacheCost;
    if ($status === 'known' && $total === 0) {
      $status = 'zero';
    }
    return [
      'status' => $status,
      'input' => $inputCost,
      'output' => $outputCost,
      'cache' => $cacheCost,
      'total' => $total,
    ];
  }

}
