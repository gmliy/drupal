<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Filter;

/**
 * Decides whether an image URL is the public object on this site's CDN.
 *
 * The host must be exactly cdn.gitxx.xyz. Matching is not a substring check.
 */
final class TrustedCdnImageUrl {

  public const HOST = 'cdn.gitxx.xyz';

  private const PUBLIC_PREFIX = '/public/';

  /**
   * TRUE only for HTTPS URLs whose host and public object path both match.
   */
  public static function isTrusted(string $url): bool {
    if ($url === '' || strlen($url) > 2048) {
      return FALSE;
    }
    if (preg_match('/[\x00-\x20\x7f\\\\@]/', $url) === 1) {
      return FALSE;
    }
    if (!preg_match('#\Ahttps://([^/?\#]+)#i', $url, $authorityMatch)) {
      return FALSE;
    }
    $authority = $authorityMatch[1];
    if (str_contains($authority, '%')) {
      return FALSE;
    }
    if (str_ends_with(strtolower($authority), ':443')) {
      $authority = substr($authority, 0, -4);
    }
    if (strtolower($authority) !== self::HOST || str_contains($authority, ':')) {
      return FALSE;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
      return FALSE;
    }
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
      return FALSE;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      return FALSE;
    }
    $host = (string) ($parts['host'] ?? '');
    if ($host === '' || str_contains($host, '%') || strtolower($host) !== self::HOST) {
      return FALSE;
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
      return FALSE;
    }
    return self::publicObjectPath((string) ($parts['path'] ?? ''));
  }

  /**
   * The path must stay inside /public/ after repeated decoding.
   */
  private static function publicObjectPath(string $path): bool {
    if ($path === '' || $path[0] !== '/') {
      return FALSE;
    }
    $current = $path;
    for ($attempt = 0; $attempt < 5; $attempt++) {
      if (!self::safePublicPath($current)) {
        return FALSE;
      }
      $decoded = rawurldecode($current);
      if ($decoded === $current) {
        return TRUE;
      }
      $current = $decoded;
    }
    return FALSE;
  }

  /**
   * One decoding step is safe when it cannot leave the public prefix.
   */
  private static function safePublicPath(string $path): bool {
    if (!str_starts_with($path, self::PUBLIC_PREFIX)) {
      return FALSE;
    }
    if (str_contains($path, '\\') || str_contains($path, "\0")) {
      return FALSE;
    }
    $segments = explode('/', $path);
    $file = '';
    foreach ($segments as $segment) {
      if ($segment === '.' || $segment === '..') {
        return FALSE;
      }
      $file = $segment;
    }
    return $file !== '';
  }

}
