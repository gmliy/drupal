<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Query;

/**
 * Ranks a keyword from real counts. Weight is an admin adjustment.
 */
final class HotRank {

  /**
   * Newer days count more. Weight is applied by the caller after the sum.
   */
  public static function score(int $searches, int $users, int $weight, int $ageDays): float {
    $ageDays = max(0, $ageDays);
    $decay = 1 / (1 + ($ageDays / 7));
    return (($searches + ($users * 2)) * $decay) + $weight;
  }

}
