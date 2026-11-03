<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Site\Settings;

/**
 * Classifies stream URIs and builds object keys and CDN URLs.
 *
 * Local public files are never rewritten to a CDN address.
 */
final class StorageUriResolver {

  public const INTEGRATION_PREFIX = '_xedc_integration_test/';

  public function __construct(
    protected StorageConfig $config,
  ) {}

  public function scheme(string $uri): string {
    $scheme = strstr(str_replace('\\', '/', $uri), '://', TRUE);
    return is_string($scheme) ? strtolower($scheme) : '';
  }

  public function target(string $uri): string {
    $scheme = $this->scheme($uri);
    if ($scheme === '') {
      return '';
    }
    $target = substr(str_replace('\\', '/', $uri), strlen($scheme) + 3);
    return trim($target, '/');
  }

  public function isRemote(string $uri): bool {
    $scheme = $this->scheme($uri);
    if ($scheme === 's3') {
      return TRUE;
    }
    if ($scheme === 'public' || $scheme === 'private') {
      return WrapperStatus::schemeUsesObjectStorage($scheme);
    }
    return FALSE;
  }

  /**
   * @return 'remote'|'temporary'|'local'|'path'|'unknown'
   *   Where the URI is stored. Remote URIs must not be passed to realpath().
   */
  public function location(string $uri): string {
    $scheme = $this->scheme($uri);
    if ($scheme === 'temporary') {
      return 'temporary';
    }
    if ($this->isRemote($uri)) {
      return 'remote';
    }
    if ($scheme === 'public' || $scheme === 'private') {
      return 'local';
    }
    if ($scheme === '') {
      return 'path';
    }
    return 'unknown';
  }

  public function isPrivate(string $uri): bool {
    $scheme = $this->scheme($uri);
    if ($scheme === 'private') {
      return TRUE;
    }
    if ($scheme !== 's3') {
      return FALSE;
    }
    $folders = $this->folders();
    $target = $this->target($uri);
    $private = $folders['private'];
    return $private !== '' && (str_starts_with($target, $private . '/') || $target === $private);
  }

  /**
   * @return 'image'|'video'|'archive'|'other'
   *   Coarse file class.
   */
  public function mediaKind(string $mime, string $filename = ''): string {
    $mime = strtolower(trim(explode(';', $mime)[0]));
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (str_starts_with($mime, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], TRUE)) {
      return 'image';
    }
    if (str_starts_with($mime, 'video/') || in_array($extension, ['mp4', 'webm', 'mov', 'm4v'], TRUE)) {
      return 'video';
    }
    if (in_array($mime, ['application/zip', 'application/x-7z-compressed', 'application/x-zip-compressed'], TRUE) || in_array($extension, ['zip', '7z'], TRUE)) {
      return 'archive';
    }
    return 'other';
  }

  /**
   * @param array<string, mixed>|null $profile
   *   Profile used when the runtime is off.
   *
   * @return array{public: string, private: string, root: string}
   *   Folder names.
   */
  public function folders(?array $profile = NULL): array {
    if ((bool) Settings::get('xedc_storage.settings_accepted', FALSE)) {
      return [
        'public' => (string) Settings::get('xedc_storage.public_folder', 's3fs-public'),
        'private' => (string) Settings::get('xedc_storage.private_folder', 's3fs-private'),
        'root' => (string) Settings::get('xedc_storage.root_folder', ''),
      ];
    }
    if ($profile === NULL) {
      $id = $this->config->activeId();
      $profile = $id === '' ? NULL : $this->config->profile($id);
    }
    return [
      'public' => (string) ($profile['public_folder'] ?? 's3fs-public'),
      'private' => (string) ($profile['private_folder'] ?? 's3fs-private'),
      'root' => (string) ($profile['root_folder'] ?? ''),
    ];
  }

  /**
   * Object key S3 File System would use. NULL when the URI is not a file URI.
   *
   * @param array<string, mixed>|null $profile
   *   Optional profile.
   */
  public function objectKey(string $uri, ?array $profile = NULL): ?string {
    $scheme = $this->scheme($uri);
    $target = $this->target($uri);
    if ($target === '' || str_contains($target, '..') || str_contains($target, "\0")) {
      return NULL;
    }
    $folders = $this->folders($profile);
    if ($scheme === 's3') {
      $key = $target;
    }
    elseif ($scheme === 'public') {
      $key = $folders['public'] . '/' . $target;
    }
    elseif ($scheme === 'private') {
      $key = $folders['private'] . '/' . $target;
    }
    else {
      return NULL;
    }
    if ($folders['root'] !== '') {
      $key = $folders['root'] . '/' . $key;
    }
    return $this->keyError($key) === NULL ? $key : NULL;
  }

  public function keyError(string $key): ?string {
    $key = str_replace('\\', '/', $key);
    if ($key === '' || str_starts_with($key, '/') || str_contains($key, "\0") || str_contains($key, '..') || str_contains($key, '//')) {
      return '对象键无效。';
    }
    if (strlen($key) > 900 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
      return '对象键无效。';
    }
    return NULL;
  }

  /**
   * @param array{public: string, private: string, root: string} $folders
   *   Allowed folder names.
   */
  public function allowsKey(string $key, string $purpose, array $folders): ?string {
    $error = $this->keyError($key);
    if ($error !== NULL) {
      return $error;
    }
    $public = trim($folders['public'], '/');
    $private = trim($folders['private'], '/');
    $root = trim($folders['root'], '/');
    $prefixes = [];
    foreach ([$public, $private] as $folder) {
      if ($folder === '') {
        continue;
      }
      $prefixes[] = $folder . '/';
      if ($root !== '') {
        $prefixes[] = $root . '/' . $folder . '/';
      }
    }
    if ($purpose === 'test') {
      if (str_starts_with($key, BootGuard::TEST_PREFIX)) {
        return BootGuard::testKey($key);
      }
      foreach ($prefixes as $prefix) {
        if (str_starts_with($key, $prefix . BootGuard::TEST_PREFIX)) {
          return NULL;
        }
      }
      return '测试写入只能使用测试前缀。';
    }
    if ($purpose === 'integration') {
      $prefix = self::INTEGRATION_PREFIX;
      if (!str_starts_with($key, $prefix) || strlen($key) === strlen($prefix)) {
        return '集成测试只能写入 _xedc_integration_test/。';
      }
      return NULL;
    }
    if ($purpose === 'migration' || $purpose === 'migration-rollback' || $purpose === 'processed') {
      foreach ($prefixes as $prefix) {
        if (str_starts_with($key, $prefix) && strlen($key) > strlen($prefix)) {
          return NULL;
        }
      }
      return $purpose === 'processed'
        ? '处理后的文件只能写回公共或私有文件夹。'
        : '迁移对象必须位于公共或私有文件夹前缀下。';
    }
    return '未知的对象写入用途。';
  }

  public function rewriteEnabled(): bool {
    return (bool) Settings::get('xedc_storage.cdn_enabled', FALSE);
  }

  /**
   * @param array<string, mixed>|null $profile
   *   Profile whose public base URL can be checked.
   */
  public function configuredPublicBase(?array $profile = NULL): string {
    if ($profile === NULL) {
      $id = $this->config->activeId();
      $profile = $id === '' ? [] : ($this->config->profile($id) ?? []);
    }
    $cdn = trim((string) ($profile['cdn_base_url'] ?? ''));
    if ($cdn === '') {
      $cdn = trim((string) ($profile['public_base_url'] ?? ''));
    }
    if ($cdn === '') {
      $saved = $this->config->data()['cdn']['base_url'] ?? '';
      $cdn = is_string($saved) ? trim($saved) : '';
    }
    return $cdn;
  }

  /**
   * CDN URL for a public remote object. NULL for local or private objects.
   *
   * @param array<string, mixed>|null $profile
   *   Profile used for the base URL.
   */
  public function publicCdnUrl(string $uri, ?array $profile = NULL): ?string {
    if ($profile === NULL) {
      $id = $this->config->activeId();
      $profile = $id === '' ? [] : ($this->config->profile($id) ?? []);
    }
    $base = trim((string) Settings::get('xedc_storage.cdn_base_url', ''));
    if ($base === '') {
      $base = $this->configuredPublicBase($profile);
    }
    $endpoint = trim((string) Settings::get('xedc_storage.endpoint', ''));
    if ($endpoint === '') {
      $endpoint = (string) ($profile['endpoint'] ?? '');
    }
    $folders = $this->folders($profile);
    return CdnUrlPolicy::decide(
      $this->rewriteEnabled(),
      $this->location($uri) === 'remote',
      $this->isPrivate($uri),
      $this->objectKey($uri, $profile),
      $folders['public'],
      $folders['private'],
      $base,
      $endpoint,
    );
  }

  public function joinUrl(string $base, string $key): string {
    $parts = explode('/', str_replace('\\', '/', $key));
    $encoded = implode('/', array_map('rawurlencode', $parts));
    return rtrim($base, '/') . '/' . $encoded;
  }

}
