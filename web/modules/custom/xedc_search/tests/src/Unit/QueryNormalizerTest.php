<?php

declare(strict_types=1);

namespace Drupal\Tests\xedc_search\Unit;

use Drupal\xedc_search\Query\QueryNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Drupal\xedc_search\Query\QueryNormalizer
 */
final class QueryNormalizerTest extends TestCase {

  public function testStripsTagsAndControlCharacters(): void {
    $normalizer = new QueryNormalizer();
    $value = $normalizer->clean("  <b>日系</b>写真\x00  ");
    $this->assertSame('日系写真', $value);
    $this->assertSame('日系写真', $normalizer->key('  日系写真  '));
  }

  public function testLimitsLength(): void {
    $normalizer = new QueryNormalizer();
    $this->assertSame(10, mb_strlen($normalizer->clean(str_repeat('测', 30), 10)));
  }

  public function testTypeAndSortWhitelist(): void {
    $normalizer = new QueryNormalizer();
    $this->assertSame('photo', $normalizer->type('PHOTO'));
    $this->assertSame('all', $normalizer->type('page'));
    $this->assertSame('hot', $normalizer->sort('hot'));
    $this->assertSame('relevance', $normalizer->sort('random'));
    $this->assertSame('week', $normalizer->window('nope', 'week'));
  }

}
