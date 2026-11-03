<?php

declare(strict_types=1);

namespace Drupal\Tests\xedc_core\Unit;

use Drupal\xedc_core\Ops\Storage\StorageSizeFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Binary display labels. The original integer stays available for comparison.
 *
 * @group xedc_core
 */
final class StorageSizeFormatterTest extends TestCase {

  public function testKnownSizes(): void {
    $cases = [
      0 => '0 B',
      1 => '1 B',
      512 => '512 B',
      1023 => '1023 B',
      1024 => '1.00 KiB',
      1024 * 1024 => '1.00 MiB',
      163198 => '159.37 KiB',
      39380312064 => '36.68 GiB',
      1024 ** 4 => '1.00 TiB',
      1024 ** 5 => '1.00 PiB',
      (1024 ** 5) * 3 => '3.00 PiB',
    ];
    foreach ($cases as $bytes => $label) {
      $this->assertSame($label, StorageSizeFormatter::formatBytes($bytes), (string) $bytes);
    }
  }

  public function testOriginalValueStaysUsableForComparison(): void {
    $bytes = 39380312064;
    $other = 163198;
    $formatted = StorageSizeFormatter::formatBytesWithExactValue($bytes);
    $smaller = StorageSizeFormatter::formatBytesWithExactValue($other);
    $this->assertSame(39380312064, $bytes);
    $this->assertSame(39380312064, $formatted['bytes']);
    $this->assertSame('36.68 GiB', $formatted['formatted']);
    $this->assertSame(163198, $smaller['bytes']);
    $this->assertTrue($formatted['bytes'] > $smaller['bytes']);
    $this->assertNotSame($formatted['formatted'], (string) $formatted['bytes']);
    $this->assertSame('39,380,312,064 Bytes', StorageSizeFormatter::exactLabel($bytes));
  }

  public function testNumericStringDoesNotChangeTheInteger(): void {
    $pair = StorageSizeFormatter::formatBytesWithExactValue('163198');
    $this->assertSame(163198, $pair['bytes']);
    $this->assertSame('159.37 KiB', $pair['formatted']);
  }

  public function testInvalidValuesDoNotThrow(): void {
    foreach ([NULL, -1, -0.5, NAN, INF, '', ' ', '12.5', '1e3', 'abc', [], new \stdClass()] as $value) {
      $pair = StorageSizeFormatter::formatBytesWithExactValue($value);
      $this->assertNull($pair['bytes']);
      $this->assertSame('暂无数据', $pair['formatted']);
      $this->assertSame('暂无数据', StorageSizeFormatter::formatBytes($value));
    }
  }

}
