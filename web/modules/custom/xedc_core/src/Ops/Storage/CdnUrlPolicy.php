<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

/**
 * Decides whether a URI may be published on the public CDN.
 *
 * Local files and private objects never receive a CDN address. The S3 API
 * endpoint is never treated as a CDN hostname.
 */
final class CdnUrlPolicy {

  public const PRODUCTION_BASE = 'https://cdn.gitxx.xyz';

  /**
   * Public CDN URL, or NULL when the URI must keep another delivery path.
   */
  public static function decide(
    bool $rewrite,
    bool $remote,
    bool $private,
    ?string $key,
    string $publicFolder,
    string $privateFolder,
    string $base,
    string $endpoint,
  ): ?string {
    if (!$rewrite || !$remote || $private) {
      return NULL;
    }
    $publicFolder = trim(str_replace('\\', '/', $publicFolder), '/');
    $privateFolder = trim(str_replace('\\', '/', $privateFolder), '/');
    if ($key === NULL || $publicFolder === '' || $publicFolder === $privateFolder) {
      return NULL;
    }
    $key = str_replace('\\', '/', $key);
    if (!str_starts_with($key, $publicFolder . '/')) {
      return NULL;
    }
    if ($privateFolder !== '' && (str_starts_with($key, $privateFolder . '/') || $key === $privateFolder)) {
      return NULL;
    }
    $base = rtrim(trim($base), '/');
    if ($base === '' || BootGuard::publicUrlError($base) !== NULL) {
      return NULL;
    }
    $baseHost = strtolower((string) (parse_url($base, PHP_URL_HOST) ?: ''));
    $endpointHost = strtolower((string) (parse_url(trim($endpoint), PHP_URL_HOST) ?: ''));
    if ($baseHost === '' || self::isApiHost($baseHost) || ($endpointHost !== '' && $baseHost === $endpointHost)) {
      return NULL;
    }
    $parts = explode('/', $key);
    return $base . '/' . implode('/', array_map('rawurlencode', $parts));
  }

  /**
   * TRUE when an already built URL points at the private prefix or the API.
   */
  public static function rejectedUrl(string $url, string $privateFolder, string $endpoint): bool {
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    $endpointHost = strtolower((string) (parse_url(trim($endpoint), PHP_URL_HOST) ?: ''));
    if ($host === '' || self::isApiHost($host) || ($endpointHost !== '' && $host === $endpointHost)) {
      return TRUE;
    }
    $privateFolder = trim(str_replace('\\', '/', $privateFolder), '/');
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
    if ($privateFolder === '') {
      return FALSE;
    }
    $needle = '/' . $privateFolder;
    return $path === $needle || str_starts_with($path, $needle . '/');
  }

  public static function isApiHost(string $host): bool {
    $host = strtolower($host);
    return $host === 'r2.cloudflarestorage.com' || str_ends_with($host, '.r2.cloudflarestorage.com');
  }

}
