<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

/**
 * Display-only binary sizes. Callers keep the original byte count for math.
 */
final class StorageSizeFormatter {

  /**
   * @return array{bytes: int|null, formatted: string}
   *   The original whole-byte value when it is usable, plus the label.
   */
  public static function formatBytesWithExactValue(mixed $bytes): array {
    $exact = self::exact($bytes);
    if ($exact === NULL) {
      return ['bytes' => NULL, 'formatted' => '暂无数据'];
    }
    return ['bytes' => $exact, 'formatted' => self::label($exact)];
  }

  public static function formatBytes(mixed $bytes): string {
    return self::formatBytesWithExactValue($bytes)['formatted'];
  }

  /**
   * Grouped decimal digits for a detail tooltip. Not for comparison.
   */
  public static function exactLabel(mixed $bytes): string {
    $exact = self::exact($bytes);
    if ($exact === NULL) {
      return '暂无数据';
    }
    return number_format($exact, 0, '.', ',') . ' Bytes';
  }

  private static function exact(mixed $bytes): ?int {
    if (is_string($bytes)) {
      $bytes = trim($bytes);
      if ($bytes === '' || !preg_match('/^\d+$/', $bytes)) {
        return NULL;
      }
      if (strlen($bytes) > 18) {
        return NULL;
      }
      $bytes = (int) $bytes;
    }
    if (!is_int($bytes) && !is_float($bytes)) {
      return NULL;
    }
    if (is_float($bytes) && (!is_finite($bytes) || floor($bytes) !== $bytes)) {
      return NULL;
    }
    if ($bytes < 0 || $bytes > PHP_INT_MAX) {
      return NULL;
    }
    return (int) $bytes;
  }

  private static function label(int $bytes): string {
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    if ($bytes < 1024) {
      return $bytes . ' B';
    }
    $value = (float) $bytes;
    $index = 0;
    $rounded = $value;
    while ($index < count($units) - 1) {
      $value /= 1024;
      $index++;
      $rounded = round($value, 2);
      if ($rounded < 1024 || $index === count($units) - 1) {
        break;
      }
    }
    return number_format($rounded, 2, '.', '') . ' ' . $units[$index];
  }

}
