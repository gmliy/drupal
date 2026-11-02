<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Traffic;

/**
 * Reads cumulative receive and transmit byte counters for one interface.
 */
interface CounterSource {

  /**
   * @return array{rx: string, tx: string}|null
   *   Decimal counter strings, or NULL when the read failed.
   */
  public function read(string $iface): ?array;

}
