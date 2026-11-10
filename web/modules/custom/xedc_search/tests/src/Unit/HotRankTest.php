<?php

declare(strict_types=1);

namespace Drupal\Tests\xedc_search\Unit;

use Drupal\xedc_search\Query\HotRank;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Drupal\xedc_search\Query\HotRank
 */
final class HotRankTest extends TestCase {

  public function testNewerSearchesRankHigher(): void {
    $fresh = HotRank::score(10, 2, 0, 0);
    $old = HotRank::score(10, 2, 0, 14);
    $this->assertGreaterThan($old, $fresh);
  }

  public function testWeightCanRaiseOrLowerAKeyword(): void {
    $base = HotRank::score(4, 1, 0, 0);
    $this->assertGreaterThan($base, HotRank::score(4, 1, 10, 0));
    $this->assertLessThan($base, HotRank::score(4, 1, -3, 0));
  }

  public function testUniqueUsersCountMoreThanRepeatSearches(): void {
    $this->assertGreaterThan(HotRank::score(1, 0, 0, 0), HotRank::score(0, 1, 0, 0));
  }

}
