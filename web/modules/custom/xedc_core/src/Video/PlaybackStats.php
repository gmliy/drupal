<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Video;

use Drupal\Core\Database\Connection;

/**
 * Reads the existing statistics counters without writing them.
 */
final class PlaybackStats {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * @param int[] $nids
   * @return array<int, int>
   */
  public function counts(array $nids): array {
    $ids = [];
    foreach ($nids as $nid) {
      $nid = (int) $nid;
      if ($nid > 0) {
        $ids[$nid] = $nid;
      }
    }
    $out = [];
    foreach ($ids as $nid) {
      $out[$nid] = 0;
    }
    if (!$ids || !$this->database->schema()->tableExists('node_counter')) {
      return $out;
    }
    $cache = &drupal_static(__METHOD__, []);
    $missing = array_diff($ids, array_keys($cache));
    if ($missing) {
      foreach ($missing as $nid) {
        $cache[$nid] = 0;
      }
      $rows = $this->database->select('node_counter', 'c')
        ->fields('c', ['nid', 'totalcount'])
        ->condition('nid', array_values($missing), 'IN')
        ->execute();
      foreach ($rows as $row) {
        $cache[(int) $row->nid] = (int) $row->totalcount;
      }
    }
    foreach ($ids as $nid) {
      $out[$nid] = (int) ($cache[$nid] ?? 0);
    }
    return $out;
  }

}
