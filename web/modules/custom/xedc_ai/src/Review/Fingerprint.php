<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

/**
 * Cache identity for one content revision, model and rule set.
 */
final class Fingerprint {

  public static function fieldHash(array $fields): string {
    $encoded = json_encode(self::normalize($fields), JSON_UNESCAPED_UNICODE);
    return hash('sha256', $encoded === FALSE ? '' : $encoded);
  }

  public static function make(string $entityType, int $entityId, int $revisionId, string $langcode, string $fieldHash, string $modelKey, int $ruleVersion, string $source, string $depth): string {
    return hash('sha256', implode('|', [
      $entityType,
      $entityId,
      $revisionId,
      $langcode,
      $fieldHash,
      $modelKey,
      $ruleVersion,
      $source,
      $depth,
    ]));
  }

  /**
   * @param array<string, mixed> $fields
   *   Text payload.
   *
   * @return array<string, mixed>
   *   Stable payload.
   */
  private static function normalize(array $fields): array {
    $lists = [];
    foreach (['tags', 'categories', 'people'] as $key) {
      $values = [];
      foreach ((array) ($fields[$key] ?? []) as $value) {
        $values[] = trim((string) $value);
      }
      sort($values);
      $lists[$key] = $values;
    }
    return [
      'title' => trim((string) ($fields['title'] ?? '')),
      'summary' => trim((string) ($fields['summary'] ?? '')),
      'body' => trim((string) ($fields['body'] ?? '')),
      'tags' => $lists['tags'],
      'categories' => $lists['categories'],
      'people' => $lists['people'],
      'extra' => trim((string) ($fields['extra'] ?? '')),
    ];
  }

}
