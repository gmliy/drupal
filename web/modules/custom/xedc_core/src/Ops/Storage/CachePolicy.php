<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

/**
 * Cache-Control for object keys.
 *
 * Long-lived immutable caching is limited to keys that already contain a
 * content hash. Existing production names are left on a short public TTL so
 * a later overwrite cannot stay cached for a year.
 */
final class CachePolicy {

  public const IMMUTABLE = 'public, max-age=31536000, immutable';

  public const PUBLIC_REVALIDATE = 'public, max-age=3600';

  public const PRIVATE = 'private, max-age=0, no-store';

  public static function versioned(string $key): bool {
    $key = str_replace('\\', '/', $key);
    if (preg_match('#/(?:v|h)/[a-f0-9]{12,}/#', $key) === 1) {
      return TRUE;
    }
    return preg_match('/\.[a-f0-9]{12,}\.[A-Za-z0-9]+$/', $key) === 1;
  }

  public static function forObject(string $key, bool $private): string {
    if ($private || str_contains($key, "\n") || str_contains($key, "\r")) {
      return self::PRIVATE;
    }
    return self::versioned($key) ? self::IMMUTABLE : self::PUBLIC_REVALIDATE;
  }

  /**
   * Suggested filename for a future upload. It does not rename stored files.
   */
  public static function versionedFilename(string $filename, string $sha256): ?string {
    if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
      return NULL;
    }
    $filename = trim(str_replace('\\', '/', $filename), '/');
    if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '..')) {
      return NULL;
    }
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $stem = pathinfo($filename, PATHINFO_FILENAME);
    if ($stem === '' || $extension === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
      return NULL;
    }
    return $stem . '.' . substr($sha256, 0, 16) . '.' . $extension;
  }

}
