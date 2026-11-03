<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManager;

/**
 * Public browser URLs for object storage.
 *
 * Original public objects use the CDN host only when STORAGE_CDN_ENABLED is
 * on and the public wrapper is object storage. The object key keeps the
 * s3fs-public prefix once. Image styles stay on the local public path.
 * Private URIs are never rewritten.
 */
final class PublicUrl {

  /**
   * STORAGE_CDN_ENABLED is the public original URL rewrite switch.
   *
   * Off means generated public URLs stay on this site. It does not describe
   * whether objects exist in the bucket. Image styles and private URIs never
   * use the CDN. The R2 API host is never returned.
   */
  public static function enabled(): bool {
    if (!RuntimeState::usesObjectStorage()) {
      return FALSE;
    }
    return (bool) \Drupal\Core\Site\Settings::get('xedc_storage.cdn_enabled', FALSE);
  }

  /**
   * @return array{kind: string, url: ?string}
   *   kind is unchanged, local, or cdn.
   */
  public static function delivery(string $uri, bool $wrapperRemote, bool $cdnEnabled, string $base, string $publicFolder): array {
    $scheme = StreamWrapperManager::getScheme($uri) ?: '';
    if ($scheme === 'private' || $scheme === 'temporary' || $scheme !== 'public' || !$wrapperRemote) {
      return ['kind' => 'unchanged', 'url' => NULL];
    }
    $style = self::localStyleUrl($uri);
    if ($style !== NULL) {
      return ['kind' => 'local', 'url' => $style];
    }
    if (!$cdnEnabled) {
      $target = self::target($uri);
      if ($target === NULL) {
        return ['kind' => 'unchanged', 'url' => NULL];
      }
      return ['kind' => 'local', 'url' => rtrim(PublicStream::baseUrl(), '/') . '/' . UrlHelper::encodePath($target)];
    }
    $cdn = self::cdnUrl($uri, $base, $publicFolder);
    if ($cdn === NULL) {
      return ['kind' => 'unchanged', 'url' => NULL];
    }
    return ['kind' => 'cdn', 'url' => $cdn];
  }

  public static function alter(string $uri): ?string {
    $decision = self::delivery($uri, WrapperStatus::schemeUsesObjectStorage('public'), self::enabled(), self::base(), self::publicFolder());
    return $decision['kind'] === 'unchanged' ? NULL : $decision['url'];
  }

  public static function cdnUrl(string $uri, string $base, string $publicFolder): ?string {
    $scheme = StreamWrapperManager::getScheme($uri) ?: '';
    if ($scheme !== 'public') {
      return NULL;
    }
    $target = self::target($uri);
    if ($target === NULL || self::isStyle($target)) {
      return NULL;
    }
    $publicFolder = trim(str_replace('\\', '/', $publicFolder), '/');
    if ($publicFolder === '' || str_contains($publicFolder, '..')) {
      return NULL;
    }
    $key = str_starts_with($target, $publicFolder . '/') ? $target : $publicFolder . '/' . $target;
    if (str_contains($key, '..') || str_contains($key, '//')) {
      return NULL;
    }
    $base = rtrim(trim($base), '/');
    if ($base === '' || BootGuard::publicUrlError($base) !== NULL) {
      return NULL;
    }
    $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?: ''));
    if (CdnUrlPolicy::isApiHost($host)) {
      return NULL;
    }
    $parts = explode('/', $key);
    return $base . '/' . implode('/', array_map('rawurlencode', $parts));
  }

  public static function localStyleUrl(string $uri): ?string {
    $scheme = StreamWrapperManager::getScheme($uri) ?: '';
    if ($scheme !== 'public') {
      return NULL;
    }
    $target = self::target($uri);
    if ($target === NULL || !self::isStyle($target)) {
      return NULL;
    }
    // xedc_style busts a CDN 404 that was cached before the derivative existed.
    // ImageStyle appends the itok after this URL.
    return rtrim(PublicStream::baseUrl(), '/') . '/' . UrlHelper::encodePath($target) . '?xedc_style=1';
  }

  public static function isStyle(string $target): bool {
    $target = trim(str_replace('\\', '/', $target), '/');
    return $target === 'styles' || str_starts_with($target, 'styles/');
  }

  private static function target(string $uri): ?string {
    $target = StreamWrapperManager::getTarget($uri);
    if (!is_string($target)) {
      return NULL;
    }
    $target = trim(str_replace('\\', '/', $target), '/');
    if ($target === '' || str_contains($target, "\0") || str_contains($target, '..')) {
      return NULL;
    }
    return $target;
  }

  private static function base(): string {
    $base = trim((string) \Drupal\Core\Site\Settings::get('xedc_storage.public_base_url', ''));
    if ($base === '') {
      $base = trim((string) \Drupal\Core\Site\Settings::get('xedc_storage.cdn_base_url', ''));
    }
    if ($base === '' && \Drupal::hasService('xedc_core.storage_uris')) {
      $uris = \Drupal::service('xedc_core.storage_uris');
      if ($uris instanceof StorageUriResolver) {
        $base = $uris->configuredPublicBase();
      }
    }
    return $base !== '' ? $base : CdnUrlPolicy::PRODUCTION_BASE;
  }

  private static function publicFolder(): string {
    $folder = trim((string) \Drupal\Core\Site\Settings::get('xedc_storage.public_folder', ''));
    if ($folder !== '') {
      return $folder;
    }
    if (\Drupal::hasService('xedc_core.storage_uris')) {
      $uris = \Drupal::service('xedc_core.storage_uris');
      if ($uris instanceof StorageUriResolver) {
        return (string) ($uris->folders()['public'] ?? 's3fs-public');
      }
    }
    return 's3fs-public';
  }

}
