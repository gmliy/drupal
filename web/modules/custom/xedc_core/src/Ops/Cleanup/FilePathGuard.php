<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\xedc_core\Ops\Storage\LocalTempFileManager;
use Drupal\xedc_core\Ops\Storage\StorageUriResolver;

/**
 * Allows file operations only inside the Drupal public and private roots.
 */
final class FilePathGuard {

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected ?StorageUriResolver $uris = NULL,
    protected ?LocalTempFileManager $temp = NULL,
  ) {}

  /**
   * @return array<string, string>
   *   Scheme name to real directory.
   */
  public function roots(): array {
    $roots = [];
    foreach (['public' => 'public://', 'private' => 'private://'] as $name => $uri) {
      $real = $this->fileSystem->realpath($uri);
      if (!is_string($real) || $real === '' || !$this->underDrupal($real)) {
        continue;
      }
      $roots[$name] = rtrim($real, '/');
    }
    return $roots;
  }

  public function quarantineRoot(): ?string {
    $roots = $this->roots();
    if (!isset($roots['private'])) {
      return NULL;
    }
    return $roots['private'] . '/xedc-quarantine';
  }

  /**
   * Classifies a managed URI before trusting its real path.
   */
  public function kindForUri(string $uri): string {
    $path = strtolower(str_replace('\\', '/', $uri));
    if (str_starts_with($path, 'temporary://')) {
      return 'temporary';
    }
    if ($this->uris instanceof StorageUriResolver && $this->uris->isRemote($uri)) {
      return 'remote';
    }
    if (str_starts_with($path, 's3://')) {
      return 'remote';
    }
    if (str_starts_with($path, 'public://') && (bool) Settings::get('s3fs.use_s3_for_public', FALSE)) {
      return 'remote';
    }
    if (str_starts_with($path, 'private://') && (bool) Settings::get('s3fs.use_s3_for_private', FALSE)) {
      return 'remote';
    }
    if (str_starts_with($path, 'public://styles/') || $path === 'public://styles') {
      return 'derivative';
    }
    if (str_starts_with($path, 'public://css/') || $path === 'public://css') {
      return 'aggregate_css';
    }
    if (str_starts_with($path, 'public://js/') || $path === 'public://js') {
      return 'aggregate_js';
    }
    if (str_starts_with($path, 'public://php/') || $path === 'public://php') {
      return 'protected';
    }
    if (str_starts_with($path, 'private://xedc-quarantine/') || $path === 'private://xedc-quarantine') {
      return 'quarantine';
    }
    if (str_starts_with($path, 'private://xedc-import/') || $path === 'private://xedc-import') {
      return 'import';
    }
    if (str_ends_with($path, '/.htaccess') || str_ends_with($path, '/.htpasswd')) {
      return 'protected';
    }
    if (str_starts_with($path, 'public://') || str_starts_with($path, 'private://')) {
      return 'content';
    }
    return 'outside';
  }

  /**
   * @return array<string, mixed>|null
   *   Regular file stat inside an allowed root, without following a symlink.
   */
  /**
   * Real path of a local URI. Remote URIs return FALSE without calling realpath().
   */
  public function realpathIfLocal(string $uri): string|false {
    if ($uri === '' || str_contains($uri, "\0") || $this->kindForUri($uri) === 'remote') {
      return FALSE;
    }
    $real = $this->fileSystem->realpath($uri);
    return is_string($real) && $real !== '' ? $real : FALSE;
  }

  public function describe(string $path): ?array {
    if ($path === '' || str_contains($path, "\0")) {
      return NULL;
    }
    if ($this->temp instanceof LocalTempFileManager && $this->temp->contains($path)) {
      return NULL;
    }
    $stat = @lstat($path);
    if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0100000)) {
      return NULL;
    }
    $real = realpath($path);
    if (!is_string($real) || $this->normalize($path) !== $real) {
      return NULL;
    }
    $root = $this->containingRoot($real);
    if ($root === NULL || $this->symlinkBetween($real, $root)) {
      return NULL;
    }
    return [
      'path' => $real,
      'bytes' => (int) $stat['size'],
      'mtime' => (int) $stat['mtime'],
      'mode' => (int) $stat['mode'] & 0777,
      'inode' => ((int) $stat['dev']) . ':' . ((int) $stat['ino']),
    ];
  }

  public function isInsideQuarantine(string $path): bool {
    $info = $this->describe($path);
    $root = $this->quarantineRoot();
    if ($info === NULL || $root === NULL) {
      return FALSE;
    }
    $realRoot = realpath($root);
    if (!is_string($realRoot)) {
      return FALSE;
    }
    return $info['path'] === $realRoot || str_starts_with($info['path'], $realRoot . '/');
  }

  /**
   * Streams a SHA-256. Returns NULL when the file changes while being read.
   */
  public function hashFile(string $path, int $maxBytes): ?string {
    $info = $this->describe($path);
    if ($info === NULL || $info['bytes'] > $maxBytes) {
      return NULL;
    }
    $hash = @hash_file('sha256', $info['path']);
    clearstatcache(TRUE, $info['path']);
    $after = @filesize($info['path']);
    if (!is_string($hash) || strlen($hash) !== 64 || $after !== $info['bytes']) {
      return NULL;
    }
    return $hash;
  }

  public function directoryKind(string $path): string {
    if ($path === '' || str_contains($path, "\0")) {
      return 'outside';
    }
    if ($this->temp instanceof LocalTempFileManager && $this->temp->contains($path)) {
      return 'temporary';
    }
    $stat = @lstat($path);
    if (!is_array($stat) || (($stat['mode'] & 0170000) === 0120000)) {
      return 'outside';
    }
    $real = realpath($path);
    if (!is_string($real) || $this->normalize($path) !== $real) {
      return 'outside';
    }
    return $this->kindOfReal($real);
  }

  public function containedKind(string $path): string {
    if ($this->temp instanceof LocalTempFileManager && $this->temp->contains($path)) {
      return 'temporary';
    }
    $info = $this->describe($path);
    if ($info === NULL) {
      return 'outside';
    }
    return $this->kindOfReal($info['path']);
  }

  private function kindOfReal(string $real): string {
    if ($this->containingRoot($real) === NULL) {
      return 'outside';
    }
    $roots = $this->roots();
    if (isset($roots['public']) && str_starts_with($real, $roots['public'] . '/')) {
      $first = explode('/', substr($real, strlen($roots['public']) + 1))[0] ?? '';
      if ($first === 'config' || str_starts_with($first, 'config_')) {
        return 'protected';
      }
    }
    if (isset($roots['public'])) {
      foreach ([
        'css' => 'aggregate_css',
        'js' => 'aggregate_js',
        'styles' => 'derivative',
        'php' => 'protected',
        'translations' => 'protected',
        'languages' => 'protected',
        'media-icons' => 'protected',
      ] as $dir => $kind) {
        $prefix = $roots['public'] . '/' . $dir;
        if ($real === $prefix || str_starts_with($real, $prefix . '/')) {
          return $kind;
        }
      }
    }
    if (isset($roots['private'])) {
      foreach ([
        'xedc-quarantine' => 'quarantine',
        'xedc-import' => 'import',
      ] as $dir => $kind) {
        $prefix = $roots['private'] . '/' . $dir;
        if ($real === $prefix || str_starts_with($real, $prefix . '/')) {
          return $kind;
        }
      }
    }
    $base = basename($real);
    if ($base === '.htaccess' || $base === '.htpasswd') {
      return 'protected';
    }
    return 'content';
  }

  private function containingRoot(string $real): ?string {
    foreach ($this->roots() as $root) {
      if ($real === $root || str_starts_with($real, $root . '/')) {
        return $root;
      }
    }
    return NULL;
  }

  private function underDrupal(string $path): bool {
    $base = '/var/www/drupal';
    $path = rtrim($path, '/');
    return $path === $base || str_starts_with($path, $base . '/');
  }

  private function symlinkBetween(string $real, string $root): bool {
    $current = $real;
    while ($current !== $root && str_starts_with($current, $root . '/')) {
      $stat = @lstat($current);
      if (!is_array($stat) || (($stat['mode'] & 0170000) === 0120000)) {
        return TRUE;
      }
      $parent = dirname($current);
      if ($parent === $current) {
        break;
      }
      $current = $parent;
    }
    return FALSE;
  }

  private function normalize(string $path): string {
    $parts = [];
    foreach (explode('/', $path) as $part) {
      if ($part === '' || $part === '.') {
        continue;
      }
      if ($part === '..') {
        array_pop($parts);
        continue;
      }
      $parts[] = $part;
    }
    return '/' . implode('/', $parts);
  }

}
