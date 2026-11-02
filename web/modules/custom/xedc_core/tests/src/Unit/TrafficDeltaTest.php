<?php

declare(strict_types=1);

namespace Drupal\Tests\xedc_core\Unit;

use Drupal\xedc_core\Ops\Traffic\NicGuard;
use Drupal\xedc_core\Ops\Traffic\TrafficDelta;
use Drupal\xedc_core\Ops\Traffic\TrafficReport;
use PHPUnit\Framework\TestCase;

/**
 * Counter deltas and interface-name rules that do not boot Drupal.
 *
 * @group xedc_core
 */
final class TrafficDeltaTest extends TestCase {

  public function testIntervalOtherThanSixtySeconds(): void {
    $delta = TrafficDelta::between('1000', '2000', 1000, '2026-11-02', '2500', '2600', 1137, '2026-11-02');
    $this->assertSame('ok', $delta['status']);
    $this->assertTrue($delta['counted']);
    $this->assertSame('1500', $delta['rx_delta']);
    $this->assertSame('600', $delta['tx_delta']);
    $this->assertSame(137, $delta['gap']);
  }

  public function testResetDoesNotGoNegative(): void {
    $delta = TrafficDelta::between('9000', '8000', 1000, '2026-11-02', '10', '8000', 1200, '2026-11-02');
    $this->assertSame('reset', $delta['status']);
    $this->assertFalse($delta['counted']);
    $this->assertNull($delta['rx_delta']);
  }

  public function testCrossDayIsNotAssigned(): void {
    $delta = TrafficDelta::between('100', '100', 1000, '2026-01-31', '800', '400', 1300, '2026-02-01');
    $this->assertSame('cross_day', $delta['status']);
    $this->assertFalse($delta['counted']);
    $this->assertSame('700', $delta['rx_delta']);
    $this->assertSame('300', $delta['tx_delta']);
  }

  public function testLongGapStaysOnTheSameDay(): void {
    $delta = TrafficDelta::between('10', '10', 1000, '2026-11-02', '40', '15', 1000 + 21601, '2026-11-02');
    $this->assertSame('gap', $delta['status']);
    $this->assertTrue($delta['counted']);
    $this->assertSame('30', $delta['rx_delta']);
  }

  public function testNamesRejectTraversal(): void {
    $this->assertFalse(NicGuard::name('../etc/passwd'));
    $this->assertFalse(NicGuard::name('ens5/../../etc/passwd'));
    $this->assertFalse(NicGuard::name(''));
    $this->assertFalse(NicGuard::name('ens5 extra'));
    $this->assertTrue(NicGuard::name('ens5'));
  }

  public function testChooseDoesNotSumNics(): void {
    $this->assertSame('ens6', NicGuard::choose(['ens5', 'ens6'], 'ens6', 'ens5'));
    $this->assertSame('ens5', NicGuard::choose(['ens5', 'ens6'], '../etc/passwd', 'ens5'));
    $this->assertSame('ens5', NicGuard::choose(['ens5', 'ens6'], '', ''));
    $this->assertSame('', NicGuard::choose([], 'ens5', 'ens5'));
  }

  public function testBinaryUnits(): void {
    $report = (new \ReflectionClass(TrafficReport::class))->newInstanceWithoutConstructor();
    $this->assertSame('0 B', $report->bytes('0'));
    $this->assertSame('1.5 KB', $report->bytes('1536'));
    $this->assertSame('1.0 MB', $report->bytes('1048576'));
    $this->assertSame('1.0 GB', $report->bytes('1073741824'));
    $this->assertSame('1.0 TB', $report->bytes('1099511627776'));
  }

}
