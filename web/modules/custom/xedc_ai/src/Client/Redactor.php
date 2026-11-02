<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Client;

/**
 * Removes credentials from text that may be stored or shown.
 */
final class Redactor {

  public static function text(string $value): string {
    $value = preg_replace('/Bearer\s+[A-Za-z0-9._\-]{6,}/i', 'Bearer [redacted]', $value) ?? $value;
    $value = preg_replace('/sk-[A-Za-z0-9_\-]{6,}/', 'sk-[redacted]', $value) ?? $value;
    $value = preg_replace('/(api[_-]?key|secret|token|authorization)(["\'\s:=]+)[^\s"\']{6,}/i', '$1$2[redacted]', $value) ?? $value;
    if (strlen($value) > 400) {
      $value = substr($value, 0, 400);
    }
    return $value;
  }

  /**
   * Redacts secrets and keeps a longer admin-log excerpt.
   */
  public static function secret(string $value, int $limit = 2000): string {
    $value = preg_replace('/Bearer\s+[A-Za-z0-9._\-]{6,}/i', 'Bearer [redacted]', $value) ?? $value;
    $value = preg_replace('/sk-[A-Za-z0-9_\-]{6,}/', 'sk-[redacted]', $value) ?? $value;
    $value = preg_replace('/(api[_-]?key|secret|token|authorization)(["\'\s:=]+)[^\s"\']{6,}/i', '$1$2[redacted]', $value) ?? $value;
    if (mb_strlen($value) > $limit) {
      $value = mb_substr($value, 0, $limit);
    }
    return $value;
  }

}
