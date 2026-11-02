<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Traffic;

/**
 * Turns two counter snapshots into an increment. Does not read the system.
 */
final class TrafficDelta {

  public const OK_SECONDS = 180;

  public const SPARSE_SECONDS = 21600;

  /**
   * @return array{status: string, counted: bool, rx_delta: ?string, tx_delta: ?string, gap: int}
   *   counted means the increment belongs to that calendar day.
   */
  public static function between(string $previousRx, string $previousTx, int $previousTime, string $previousDay, string $rx, string $tx, int $time, string $day): array {
    $gap = max(0, $time - $previousTime);
    if (!self::counter($previousRx) || !self::counter($previousTx) || !self::counter($rx) || !self::counter($tx)) {
      return self::row('failed', FALSE, NULL, NULL, $gap);
    }
    if (bccomp($rx, $previousRx, 0) < 0 || bccomp($tx, $previousTx, 0) < 0) {
      return self::row('reset', FALSE, NULL, NULL, $gap);
    }
    $rxDelta = bcsub($rx, $previousRx, 0);
    $txDelta = bcsub($tx, $previousTx, 0);
    if ($previousDay !== $day) {
      return self::row('cross_day', FALSE, $rxDelta, $txDelta, $gap);
    }
    $status = 'ok';
    if ($gap > self::SPARSE_SECONDS) {
      $status = 'gap';
    }
    elseif ($gap > self::OK_SECONDS) {
      $status = 'sparse';
    }
    return self::row($status, TRUE, $rxDelta, $txDelta, $gap);
  }

  public static function counter(string $value): bool {
    return (bool) preg_match('/^\d{1,20}$/', $value);
  }

  /**
   * @return array{status: string, counted: bool, rx_delta: ?string, tx_delta: ?string, gap: int}
   *   Result row.
   */
  private static function row(string $status, bool $counted, ?string $rx, ?string $tx, int $gap): array {
    return [
      'status' => $status,
      'counted' => $counted,
      'rx_delta' => $rx,
      'tx_delta' => $tx,
      'gap' => $gap,
    ];
  }

}
