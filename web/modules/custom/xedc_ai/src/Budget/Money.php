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
    $input = self::toMicro($inputPerMillion);
    $output = self::toMicro($outputPerMillion);
    if ($input === NULL || $output === NULL) {
      return NULL;
    }
    return intdiv($promptTokens * $input, 1000000) + intdiv($completionTokens * $output, 1000000);
  }

}
