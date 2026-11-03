<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops;

use Drupal\xedc_core\Ops\Storage\StorageSizeFormatter;

/**
 * Display helpers for operational numbers.
 */
final class OpsFormat {

  public static function bytes(int|float|null $bytes): string {
    if ($bytes === NULL || !is_finite((float) $bytes) || (float) $bytes < 0) {
      return '暂无数据';
    }
    // Netdata averages and df output are fractional. The storage formatter
    // only accepts whole bytes, so the display path rounds first.
    return StorageSizeFormatter::formatBytes((int) round((float) $bytes));
  }

  public static function percent(int|float|null $value): string {
    if ($value === NULL || !is_finite((float) $value)) {
      return '暂无数据';
    }
    return number_format((float) $value, 1, '.', '') . '%';
  }

  public static function number(int|float|null $value, int $decimals = 2): string {
    if ($value === NULL || !is_finite((float) $value)) {
      return '暂无数据';
    }
    return number_format((float) $value, $decimals, '.', '');
  }

  public static function rate(int|float|null $bytesPerSecond): string {
    if ($bytesPerSecond === NULL || !is_finite((float) $bytesPerSecond) || $bytesPerSecond < 0) {
      return '暂无数据';
    }
    return self::bytes($bytesPerSecond) . '/s';
  }

  public static function duration(int $seconds): string {
    if ($seconds < 0) {
      return '暂无数据';
    }
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $parts = [];
    if ($days > 0) {
      $parts[] = $days . ' 天';
    }
    if ($hours > 0 || $days > 0) {
      $parts[] = $hours . ' 小时';
    }
    $parts[] = $minutes . ' 分钟';
    return implode(' ', $parts);
  }

  public static function elapsedMs(int $milliseconds): string {
    if ($milliseconds < 0) {
      return '暂无数据';
    }
    $seconds = $milliseconds / 1000;
    if ($seconds < 60) {
      return number_format($seconds, 1, '.', '') . ' 秒';
    }
    return self::duration((int) round($seconds));
  }

}
