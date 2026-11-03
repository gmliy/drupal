<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Site\Settings;

/**
 * Shared public-storage mode. PHP-FPM, Drush and cron read this file.
 *
 * The file is the source of truth because settings.php can read it before the
 * container exists. A process must not cache the provider for its lifetime.
 */
final class RuntimeState {

  public const CONFIRMATION = '切换公开存储';

  private const DIRECTORY = 'xedc-storage-runtime';

  private static ?string $overrideDirectory = NULL;

  /**
   * @var array{mtime: int, request: float, data: array<string, mixed>}|null
   */
  private static ?array $cache = NULL;

  /** @var resource|null */
  private static $lockHandle = NULL;

  /**
   * Process-local test directory. Production requests must not call this.
   */
  public static function useDirectory(?string $directory): void {
    self::$overrideDirectory = $directory;
    self::$cache = NULL;
  }

  /**
   * @return array<string, mixed>
   *   Public runtime document. It contains no credentials.
   */
  public static function read(?string $privatePath = NULL): array {
    $path = self::activePath($privatePath);
    if ($path === NULL || !is_file($path)) {
      return self::missing();
    }
    clearstatcache(FALSE, $path);
    $mtime = filemtime($path);
    $mtime = $mtime === FALSE ? 0 : (int) $mtime;
    $request = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? 0);
    if (self::$cache !== NULL && self::$cache['mtime'] === $mtime && self::$cache['request'] === $request) {
      return self::$cache['data'];
    }
    $raw = file_get_contents($path);
    $data = self::decode(is_string($raw) ? $raw : '');
    self::$cache = ['mtime' => $mtime, 'request' => $request, 'data' => $data];
    return $data;
  }

  public static function usesObjectStorage(): bool {
    $state = self::read(self::configuredPrivatePath());
    if ($state['public_mode'] === 'local') {
      return FALSE;
    }
    if ($state['public_mode'] === 'cloudflare_r2') {
      return TRUE;
    }
    return class_exists(Settings::class) && (bool) Settings::get('s3fs.use_s3_for_public', FALSE);
  }

  public static function writesPaused(): bool {
    return !empty(self::read(self::configuredPrivatePath())['writes_paused']);
  }

  /**
   * @param array<string, mixed> $state
   *   Document without a checksum.
   */
  public static function write(?string $privatePath, array $state): bool {
    $directory = self::directory($privatePath, TRUE);
    if ($directory === NULL) {
      return FALSE;
    }
    $state['checksum'] = self::checksum($state);
    $json = json_encode($state, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || strlen($json) > 8000) {
      return FALSE;
    }
    $temporary = $directory . '/active.json.' . getmypid() . '.tmp';
    $written = file_put_contents($temporary, $json, LOCK_EX);
    if ($written === FALSE) {
      return FALSE;
    }
    @chmod($temporary, 0640);
    $moved = rename($temporary, $directory . '/active.json');
    if (!$moved) {
      @unlink($temporary);
      return FALSE;
    }
    self::$cache = NULL;
    return TRUE;
  }

  public static function acquire(?string $privatePath): bool {
    $directory = self::directory($privatePath, TRUE);
    if ($directory === NULL) {
      return FALSE;
    }
    $handle = fopen($directory . '/switch.lock', 'c+');
    if ($handle === FALSE) {
      return FALSE;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
      fclose($handle);
      return FALSE;
    }
    ftruncate($handle, 0);
    fwrite($handle, (string) getmypid());
    fflush($handle);
    self::$lockHandle = $handle;
    return TRUE;
  }

  public static function release(): void {
    if (!is_resource(self::$lockHandle)) {
      self::$lockHandle = NULL;
      return;
    }
    flock(self::$lockHandle, LOCK_UN);
    fclose(self::$lockHandle);
    self::$lockHandle = NULL;
  }

  public static function locked(?string $privatePath): bool {
    $directory = self::directory($privatePath, FALSE);
    if ($directory === NULL || !is_file($directory . '/switch.lock')) {
      return FALSE;
    }
    $handle = fopen($directory . '/switch.lock', 'c');
    if ($handle === FALSE) {
      return TRUE;
    }
    $free = flock($handle, LOCK_EX | LOCK_NB);
    if ($free) {
      flock($handle, LOCK_UN);
    }
    fclose($handle);
    return !$free;
  }

  public static function resumeWrites(?string $privatePath): bool {
    if (self::locked($privatePath)) {
      return FALSE;
    }
    $state = self::read($privatePath);
    $state['writes_paused'] = FALSE;
    $state['updated'] = time();
    return self::write($privatePath, $state);
  }

  /**
   * @param array<string, scalar|null> $fields
   *   Public audit fields. Callers must not pass credentials.
   */
  public static function audit(?string $privatePath, array $fields): void {
    $directory = self::directory($privatePath, TRUE);
    if ($directory === NULL) {
      return;
    }
    $fields['time'] = gmdate('c');
    $line = json_encode($fields, JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
      return;
    }
    file_put_contents($directory . '/audit.log', $line . "\n", FILE_APPEND | LOCK_EX);
  }

  /**
   * @param array<string, mixed> $state
   *   Document. The checksum field is ignored.
   */
  public static function checksum(array $state): string {
    unset($state['checksum']);
    ksort($state);
    $json = json_encode($state, JSON_UNESCAPED_SLASHES);
    return is_string($json) ? hash('sha256', $json) : '';
  }

  /**
   * @return array<string, mixed>
   *   Document used when the shared file does not exist yet.
   */
  public static function missing(): array {
    return [
      'version' => 0,
      'public_provider' => '',
      'public_mode' => 'env',
      'read_policy' => 'active_provider',
      'cdn_enabled' => NULL,
      'cdn_base' => 'https://cdn.gitxx.xyz',
      'writes_paused' => FALSE,
      'switch_task' => '',
      'previous_provider' => '',
      'previous_mode' => '',
      'updated' => 0,
      'status' => 'uninitialized',
      'summary' => '',
      'checksum' => '',
    ];
  }

  private static function configuredPrivatePath(): ?string {
    if (!class_exists(Settings::class)) {
      return NULL;
    }
    $path = Settings::get('file_private_path');
    return is_string($path) && $path !== '' ? $path : NULL;
  }

  private static function activePath(?string $privatePath): ?string {
    $directory = self::directory($privatePath, FALSE);
    return $directory === NULL ? NULL : $directory . '/active.json';
  }

  private static function directory(?string $privatePath, bool $create): ?string {
    if (self::$overrideDirectory !== NULL) {
      if ($create && !is_dir(self::$overrideDirectory) && !mkdir(self::$overrideDirectory, 0700, TRUE) && !is_dir(self::$overrideDirectory)) {
        return NULL;
      }
      return is_dir(self::$overrideDirectory) ? self::$overrideDirectory : NULL;
    }
    if (!is_string($privatePath) || $privatePath === '' || str_contains($privatePath, "\0")) {
      $privatePath = self::configuredPrivatePath();
    }
    if (!is_string($privatePath) || $privatePath === '') {
      return NULL;
    }
    $root = rtrim($privatePath, '/');
    $directory = $root . '/' . self::DIRECTORY;
    if (!is_dir($directory) && $create && !mkdir($directory, 0750, TRUE) && !is_dir($directory)) {
      return NULL;
    }
    return is_dir($directory) ? $directory : NULL;
  }

  /**
   * @return array<string, mixed>
   *   Valid document, or the missing document when the file is unusable.
   */
  private static function decode(string $raw): array {
    if ($raw === '' || strlen($raw) > 8000) {
      return self::missing();
    }
    try {
      $data = json_decode($raw, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return self::missing();
    }
    if (!is_array($data)) {
      return self::missing();
    }
    $checksum = (string) ($data['checksum'] ?? '');
    if ($checksum === '' || !hash_equals($checksum, self::checksum($data))) {
      return self::missing();
    }
    $mode = (string) ($data['public_mode'] ?? '');
    if (!in_array($mode, ['env', 'local', 'cloudflare_r2'], TRUE)) {
      return self::missing();
    }
    $merged = self::missing();
    foreach ($merged as $key => $value) {
      if (array_key_exists($key, $data)) {
        $merged[$key] = $data[$key];
      }
    }
    $merged['checksum'] = $checksum;
    return $merged;
  }

}
