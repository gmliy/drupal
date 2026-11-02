<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Traffic;

/**
 * Accepts interface names and tells physical NICs from virtual ones.
 *
 * Names never become a path until they match the interface pattern.
 */
final class NicGuard {

  public static function name(string $name): bool {
    return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,14}$/', $name);
  }

  /**
   * @param list<string> $physical
   *   Physical names already discovered.
   */
  public static function choose(array $physical, string $requested, string $route): string {
    $names = [];
    foreach ($physical as $name) {
      if (self::name($name) && !in_array($name, $names, TRUE)) {
        $names[] = $name;
      }
    }
    if ($names === []) {
      return '';
    }
    if ($requested !== '' && in_array($requested, $names, TRUE)) {
      return $requested;
    }
    if ($route !== '' && in_array($route, $names, TRUE)) {
      return $route;
    }
    return $names[0];
  }

  /**
   * @return list<string>
   *   Physical interface names. Virtual bridges and veth devices are omitted.
   */
  public static function physical(): array {
    $names = [];
    foreach (self::listed() as $name) {
      if (self::isPhysical($name)) {
        $names[] = $name;
      }
    }
    return $names;
  }

  /**
   * @return list<string>
   *   Virtual interface names, excluding the loopback name from the display list.
   */
  public static function virtual(): array {
    $names = [];
    foreach (self::listed() as $name) {
      if ($name !== 'lo' && !self::isPhysical($name)) {
        $names[] = $name;
      }
    }
    return $names;
  }

  public static function isPhysical(string $name): bool {
    if (!self::name($name) || $name === 'lo') {
      return FALSE;
    }
    $iface = self::ifacePath($name);
    $device = self::devicePath($name);
    if ($iface === NULL || $device === NULL) {
      return FALSE;
    }
    if (!str_starts_with($iface, '/sys/devices/') || str_contains($iface, '/virtual/')) {
      return FALSE;
    }
    if (!str_starts_with($device, '/sys/devices/') || str_contains($device, '/virtual/')) {
      return FALSE;
    }
    return is_readable($iface . '/statistics/rx_bytes') && is_readable($iface . '/statistics/tx_bytes');
  }

  public static function operstate(string $name): string {
    $iface = self::ifacePath($name);
    if ($iface === NULL) {
      return 'unknown';
    }
    $path = $iface . '/operstate';
    $real = realpath($path);
    if ($real === FALSE || $real !== $iface . '/operstate') {
      return 'unknown';
    }
    $raw = @file_get_contents($real);
    $state = is_string($raw) ? trim($raw) : '';
    return in_array($state, ['up', 'down', 'unknown', 'dormant', 'lowerlayerdown', 'notpresent', 'testing'], TRUE) ? $state : 'unknown';
  }

  public static function defaultRoute(): string {
    $raw = @file_get_contents('/proc/net/route');
    if (!is_string($raw)) {
      return '';
    }
    $selected = '';
    $metric = PHP_INT_MAX;
    foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
      $parts = preg_split('/\s+/', trim($line)) ?: [];
      if (count($parts) < 7 || $parts[1] !== '00000000') {
        continue;
      }
      $name = $parts[0];
      $candidate = (int) $parts[6];
      if (self::name($name) && $candidate < $metric) {
        $selected = $name;
        $metric = $candidate;
      }
    }
    return $selected;
  }

  /**
   * @return list<string>
   *   Directory names under the interface class.
   */
  private static function listed(): array {
    $root = '/sys/class/net';
    $names = @scandir($root);
    if (!is_array($names)) {
      return [];
    }
    $out = [];
    foreach ($names as $name) {
      if (self::name($name)) {
        $out[] = $name;
      }
    }
    sort($out);
    return $out;
  }

  private static function ifacePath(string $name): ?string {
    if (!self::name($name)) {
      return NULL;
    }
    $real = realpath('/sys/class/net/' . $name);
    if ($real === FALSE || !str_starts_with($real, '/sys/devices/') || !str_ends_with($real, '/' . $name)) {
      return NULL;
    }
    return $real;
  }

  private static function devicePath(string $name): ?string {
    $iface = self::ifacePath($name);
    if ($iface === NULL) {
      return NULL;
    }
    $real = realpath($iface . '/device');
    if ($real === FALSE || !str_starts_with($real, '/sys/devices/')) {
      return NULL;
    }
    return $real;
  }

}
