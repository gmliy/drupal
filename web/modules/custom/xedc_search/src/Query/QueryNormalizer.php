<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Query;

/**
 * Cleans search text before it touches storage or SQL.
 */
final class QueryNormalizer {

  /**
   * Strips tags and control characters, then limits length.
   */
  public function clean(string $value, int $max = 100): string {
    $max = max(1, $max);
    $value = trim(strip_tags($value));
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    $value = trim($value);
    if (mb_strlen($value) > $max) {
      $value = mb_substr($value, 0, $max);
    }
    return $value;
  }

  /**
   * Comparison key. Case folding only; it does not translate or split words.
   */
  public function key(string $value): string {
    return mb_strtolower($this->clean($value));
  }

  /**
   * Allowed public search types.
   */
  public function type(string $value): string {
    $value = strtolower(trim($value));
    $allowed = ['all', 'photo', 'video', 'article', 'model', 'category', 'tag'];
    return in_array($value, $allowed, TRUE) ? $value : 'all';
  }

  /**
   * Allowed sort keys.
   */
  public function sort(string $value): string {
    $value = strtolower(trim($value));
    return in_array($value, ['relevance', 'latest', 'hot'], TRUE) ? $value : 'relevance';
  }

  /**
   * Hot-list window.
   */
  public function window(string $value, string $fallback = 'week'): string {
    $value = strtolower(trim($value));
    if (in_array($value, ['today', 'week', 'month'], TRUE)) {
      return $value;
    }
    return in_array($fallback, ['today', 'week', 'month'], TRUE) ? $fallback : 'week';
  }

}
