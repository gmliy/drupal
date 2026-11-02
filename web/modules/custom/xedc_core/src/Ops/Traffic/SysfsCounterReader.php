<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Traffic;

/**
 * Reads cumulative byte counters from sysfs. Only physical names succeed.
 */
final class SysfsCounterReader implements CounterSource {

  public function read(string $iface): ?array {
    if (!NicGuard::isPhysical($iface)) {
      return NULL;
    }
    $rx = $this->counter($iface, 'rx_bytes');
    $tx = $this->counter($iface, 'tx_bytes');
    if ($rx === NULL || $tx === NULL) {
      return NULL;
    }
    return ['rx' => $rx, 'tx' => $tx];
  }

  private function counter(string $iface, string $file): ?string {
    if (!in_array($file, ['rx_bytes', 'tx_bytes'], TRUE)) {
      return NULL;
    }
    $dir = realpath('/sys/class/net/' . $iface . '/statistics');
    if ($dir === FALSE || !str_ends_with($dir, '/' . $iface . '/statistics')) {
      return NULL;
    }
    $path = $dir . '/' . $file;
    $real = realpath($path);
    if ($real === FALSE || $real !== $path) {
      return NULL;
    }
    $raw = @file_get_contents($real);
    if (!is_string($raw)) {
      return NULL;
    }
    $value = trim($raw);
    return TrafficDelta::counter($value) ? $value : NULL;
  }

}
