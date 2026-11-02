<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

/**
 * Local temporary files for image, video and archive processing.
 *
 * Files stay outside the web root, use unpredictable names, and are mode 0600.
 * A failure can leave a diagnostic file. Diagnostics never include credentials.
 */
final class LocalTempFileManager {

  private string $root;

  private static bool $purged = FALSE;

  public function __construct() {
    $real = $this->createRoot();
    if (!$this->outsideWeb($real)) {
      throw new \RuntimeException('本地临时目录必须在 Web 根目录之外。');
    }
    $this->root = $real;
    if (!self::$purged) {
      self::$purged = TRUE;
      try {
        $this->purgeOlderThan(21600);
      }
      catch (\Throwable) {
      }
    }
  }

  public function root(): string {
    return $this->root;
  }

  public function isOutsideWebRoot(): bool {
    return $this->outsideWeb($this->root);
  }

  public function contains(string $path): bool {
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
      return FALSE;
    }
    $real = realpath($path);
    if (!is_string($real)) {
      $parent = realpath(dirname($path));
      $name = basename($path);
      if (!is_string($parent) || $name === '' || $name === '.' || $name === '..') {
        return FALSE;
      }
      $real = $parent . DIRECTORY_SEPARATOR . $name;
    }
    return $real === $this->root || str_starts_with($real, $this->root . DIRECTORY_SEPARATOR);
  }

  public function createFile(string $prefix, string $suffix = ''): string {
    $name = $this->safeName($prefix) . '-' . bin2hex(random_bytes(16)) . $this->safeSuffix($suffix);
    $path = $this->root . DIRECTORY_SEPARATOR . $name;
    if (!$this->contains($path)) {
      throw new \RuntimeException('临时文件路径无效。');
    }
    $handle = fopen($path, 'xb');
    if ($handle === FALSE) {
      throw new \RuntimeException('无法创建本地临时文件。');
    }
    fclose($handle);
    chmod($path, 0600);
    if (is_link($path) || !is_file($path) || !$this->contains($path)) {
      @unlink($path);
      throw new \RuntimeException('临时文件没有留在安全目录内。');
    }
    return $path;
  }

  public function directory(string $name): string {
    $path = $this->root . DIRECTORY_SEPARATOR . $this->safeName($name) . '-' . bin2hex(random_bytes(8));
    if (!$this->contains($path) || is_link($path)) {
      throw new \RuntimeException('临时目录路径无效。');
    }
    if (!@mkdir($path, 0700, TRUE) && !is_dir($path)) {
      throw new \RuntimeException('无法创建本地临时目录。');
    }
    chmod($path, 0700);
    $real = realpath($path);
    if (!is_string($real) || !$this->contains($real) || is_link($path)) {
      $this->delete($path);
      throw new \RuntimeException('临时目录没有留在安全目录内。');
    }
    return $real;
  }

  /**
   * Copies a Drupal stream to a local file without loading it all at once.
   */
  public function materializeUri(string $uri): string {
    $scheme = strstr(str_replace('\\', '/', $uri), '://', TRUE);
    $scheme = is_string($scheme) ? strtolower($scheme) : '';
    if (!in_array($scheme, ['public', 'private', 's3', 'temporary'], TRUE) || str_contains($uri, "\0") || str_contains($uri, '?') || str_contains($uri, '#')) {
      $this->recordDiagnostic('materialize', '拒绝了不受支持的流地址。');
      throw new \RuntimeException('只能物化受支持的文件流。');
    }
    $suffix = pathinfo(basename(str_replace('\\', '/', $uri)), PATHINFO_EXTENSION);
    $path = $this->createFile('materialized', $suffix === '' ? '' : '.' . $suffix);
    $input = @fopen($uri, 'rb');
    $output = @fopen($path, 'wb');
    if ($input === FALSE || $output === FALSE) {
      if (is_resource($input)) {
        fclose($input);
      }
      if (is_resource($output)) {
        fclose($output);
      }
      $this->delete($path);
      $this->recordDiagnostic('materialize', $scheme . ' 流无法打开。');
      throw new \RuntimeException('无法读取远程或流文件。');
    }
    $copied = stream_copy_to_stream($input, $output);
    fclose($input);
    fclose($output);
    if ($copied === FALSE || is_link($path) || !is_file($path) || !$this->contains($path)) {
      $this->delete($path);
      $this->recordDiagnostic('materialize', $scheme . ' 流复制失败。');
      throw new \RuntimeException('复制到本地临时文件失败。');
    }
    chmod($path, 0600);
    return $path;
  }

  /**
   * Writes a redacted diagnostic and returns its path. The path is inside the temp root.
   *
   * @param list<string> $secrets
   *   Values that must not remain in the file.
   */
  public function recordDiagnostic(string $operation, string $detail, array $secrets = []): string {
    $detail = BootGuard::redact($detail, $secrets);
    $detail = preg_replace('/https?:\/\/\S+/', '[url]', $detail) ?? $detail;
    $detail = str_replace(["\r", "\n"], ' ', $detail);
    $path = $this->createFile('diag', '.txt');
    $line = gmdate('c') . ' ' . $this->safeName($operation) . ' ' . $detail . "\n";
    if (file_put_contents($path, $line, LOCK_EX) === FALSE) {
      $this->delete($path);
      throw new \RuntimeException('无法写入临时诊断文件。');
    }
    chmod($path, 0600);
    return $path;
  }

  public function delete(string $path): bool {
    if (!$this->contains($path) || is_link($path)) {
      return FALSE;
    }
    $real = realpath($path);
    if (!is_string($real) || $real === $this->root) {
      return FALSE;
    }
    if (is_file($path)) {
      return unlink($path);
    }
    if (!is_dir($path)) {
      return FALSE;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
      $real = $item->getPathname();
      if (!$this->contains($real)) {
        return FALSE;
      }
      if ($item->isLink()) {
        if (!unlink($real)) {
          return FALSE;
        }
      }
      elseif ($item->isDir()) {
        if (!rmdir($real)) {
          return FALSE;
        }
      }
      elseif (!unlink($real)) {
        return FALSE;
      }
    }
    return rmdir($path);
  }

  /**
   * Removes temp entries older than the given age. Returns how many were removed.
   */
  public function purgeOlderThan(int $seconds): int {
    if ($seconds < 60) {
      $seconds = 60;
    }
    $removed = 0;
    $cutoff = time() - $seconds;
    $items = scandir($this->root) ?: [];
    foreach ($items as $name) {
      if ($name === '.' || $name === '..') {
        continue;
      }
      $path = $this->root . DIRECTORY_SEPARATOR . $name;
      if (is_link($path) || !$this->contains($path)) {
        continue;
      }
      $mtime = @filemtime($path);
      if ($mtime === FALSE || $mtime > $cutoff) {
        continue;
      }
      if ($this->delete($path)) {
        $removed++;
      }
    }
    return $removed;
  }

  private function createRoot(): string {
    $bases = [];
    $system = sys_get_temp_dir();
    if ($system !== '') {
      $bases[] = $system;
    }
    if (!in_array('/tmp', $bases, TRUE)) {
      $bases[] = '/tmp';
    }
    foreach ($bases as $base) {
      $baseReal = realpath($base);
      if (!is_string($baseReal) || !is_dir($baseReal) || is_link($base) || !$this->outsideWeb($baseReal)) {
        continue;
      }
      $root = $baseReal . DIRECTORY_SEPARATOR . 'xedc-storage';
      if (!is_dir($root) && !@mkdir($root, 0700, TRUE) && !is_dir($root)) {
        continue;
      }
      if (is_link($root)) {
        continue;
      }
      chmod($root, 0700);
      $real = realpath($root);
      if (is_string($real) && is_dir($real) && !is_link($root) && $this->outsideWeb($real)) {
        return $real;
      }
    }
    throw new \RuntimeException('无法创建 Web 根目录之外的本地临时目录。');
  }

  private function webRoot(): string {
    if (defined('DRUPAL_ROOT') && is_string(DRUPAL_ROOT) && DRUPAL_ROOT !== '') {
      $real = realpath(DRUPAL_ROOT);
      if (is_string($real)) {
        return $real;
      }
    }
    $candidate = dirname(__DIR__, 6);
    $real = realpath($candidate);
    return is_string($real) ? $real : $candidate;
  }

  private function outsideWeb(string $path): bool {
    $web = rtrim($this->webRoot(), DIRECTORY_SEPARATOR);
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    if ($web === '' || $path === '') {
      return FALSE;
    }
    return $path !== $web && !str_starts_with($path, $web . DIRECTORY_SEPARATOR);
  }

  private function safeName(string $name): string {
    $name = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', $name) ?? '');
    $name = trim($name, '-');
    if ($name === '' || $name === '.' || $name === '..') {
      return 'tmp';
    }
    return substr($name, 0, 40);
  }

  private function safeSuffix(string $suffix): string {
    if ($suffix === '' || !preg_match('/^\.[a-z0-9]{1,8}$/i', $suffix)) {
      return '';
    }
    return strtolower($suffix);
  }

}
