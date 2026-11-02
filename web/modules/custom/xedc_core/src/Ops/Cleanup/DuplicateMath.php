<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

/**
 * Pure helpers for duplicate space and plan identity.
 */
final class DuplicateMath {

  /**
   * Bytes that could be freed by keeping one physical copy.
   *
   * Hard links and file entities that share an inode are counted once.
   * The retained copy is the largest inode, so the figure is theoretical.
   *
   * @param list<array<string, mixed>> $members
   *   Members with bytes and inode.
   */
  public static function reclaimable(array $members): int {
    $inodes = [];
    foreach ($members as $index => $member) {
      $inode = trim((string) ($member['inode'] ?? ''));
      if ($inode === '') {
        $inode = 'missing:' . $index . ':' . (string) ($member['path'] ?? '');
      }
      $bytes = max(0, (int) ($member['bytes'] ?? 0));
      if (!isset($inodes[$inode])) {
        $inodes[$inode] = $bytes;
      }
    }
    if (count($inodes) < 2) {
      return 0;
    }
    return max(0, array_sum($inodes) - max($inodes));
  }

  public static function family(string $mime): string {
    if (str_starts_with($mime, 'image/')) {
      return 'image';
    }
    if (str_starts_with($mime, 'video/')) {
      return 'video';
    }
    return 'other';
  }

  /**
   * @param list<array<string, mixed>> $refs
   *   Reference rows.
   */
  public static function signature(array $refs): string {
    $rows = [];
    foreach ($refs as $ref) {
      $rows[] = implode('|', [
        (string) ($ref['source'] ?? ''),
        (string) ($ref['entity_type'] ?? ''),
        (string) ($ref['entity_id'] ?? ''),
        (string) ($ref['revision_id'] ?? ''),
        (string) ($ref['langcode'] ?? ''),
        (string) ($ref['field_name'] ?? ''),
        (string) ($ref['delta'] ?? ''),
        (string) ($ref['fid'] ?? ''),
        (string) ($ref['confidence'] ?? ''),
        (string) ($ref['migratable'] ?? ''),
      ]);
    }
    sort($rows);
    return hash('sha256', implode("\n", $rows));
  }

  /**
   * @param array<string, mixed> $payload
   *   Canonical plan.
   */
  public static function planHash(array $payload): string {
    $json = json_encode(self::sortKeys($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return hash('sha256', $json === FALSE ? '' : $json);
  }

  private static function sortKeys(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (array_is_list($value)) {
      return array_map([self::class, 'sortKeys'], $value);
    }
    ksort($value);
    foreach ($value as $key => $item) {
      $value[$key] = self::sortKeys($item);
    }
    return $value;
  }

}
