<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;

/**
 * Detects, checks and extracts ZIP and 7Z archives.
 */
class ArchiveManager {

  /**
   * Image extensions that are candidates. MIME is checked later.
   */
  private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected FileUsageInterface $fileUsage,
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * TRUE when the private stream is configured.
   */
  public function privateReady(): bool {
    return $this->streamWrapperManager->isValidScheme('private');
  }

  /**
   * ELF or wrapper binary for 7z, or NULL.
   */
  public function sevenZipBinary(): ?string {
    foreach (['/usr/lib/7zip/7z', '/usr/bin/7zz', '/usr/bin/7z', '/usr/bin/7za'] as $path) {
      if (is_executable($path)) {
        return $path;
      }
    }
    return NULL;
  }

  /**
   * Files an administrator may import from the inbox.
   *
   * @return array<string, string>
   *   Basename to label.
   */
  public function inboxOptions(): array {
    if (!$this->privateReady()) {
      return [];
    }
    $directory = 'private://xedc-import/inbox';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $real = $this->fileSystem->realpath($directory);
    if (!$real) {
      return [];
    }
    $options = [];
    foreach (scandir($real) ?: [] as $name) {
      if ($name === '.' || $name === '..' || !preg_match('/\.(zip|7z)$/i', $name)) {
        continue;
      }
      $full = $real . DIRECTORY_SEPARATOR . $name;
      if (!is_file($full) || is_link($full)) {
        continue;
      }
      $resolved = realpath($full);
      if (!$resolved || !str_starts_with($resolved, $real . DIRECTORY_SEPARATOR)) {
        continue;
      }
      $options[$name] = $name;
    }
    natcasesort($options);
    return $options;
  }

  /**
   * Moves an uploaded or inbox archive into the task directory.
   *
   * @param array<string, mixed> $payload
   *   Form payload.
   *
   * @return array{name: string, uri: string, fid: int, format: string, source: string}
   *   Stored archive.
   */
  public function intake(int $taskId, array $payload): array {
    if (!$this->privateReady()) {
      throw new ImportException('未配置私有文件系统。请在 settings.php 设置 file_private_path 后执行 drush cr。');
    }
    $fid = (int) ($payload['fid'] ?? 0);
    $server = trim((string) ($payload['server'] ?? ''));
    if ($fid && $server !== '') {
      throw new ImportException('请只选择一种压缩包来源。');
    }
    if (!$fid && $server === '') {
      throw new ImportException('请上传 ZIP/7Z，或选择服务器上的压缩包。');
    }
    $destination = 'private://xedc-import/tasks/' . $taskId . '/source';
    $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    if ($fid) {
      $stored = $this->intakeUpload($fid, $destination);
    }
    else {
      $stored = $this->intakeServer($server, $destination);
    }
    $real = $this->fileSystem->realpath($stored['uri']);
    if (!$real || !is_file($real) || is_link($real)) {
      throw new ImportException('压缩包无法读取。');
    }
    $size = filesize($real);
    if ($size === FALSE || $size < 1) {
      throw new ImportException('压缩包是空的。');
    }
    if ($size > $this->limit('max_archive_bytes', 209715200)) {
      throw new ImportException('压缩包超过大小限制。');
    }
    $stored['format'] = $this->detect($real);
    if ($stored['format'] === '7z' && !$this->sevenZipBinary()) {
      throw new ImportException('服务器未安装 7z，无法处理 7Z。安装：sudo apt-get install 7zip。验证：7z i');
    }
    if ($stored['fid']) {
      $this->addUsage($stored['fid'], $taskId);
    }
    return $stored;
  }

  /**
   * Rejects an unreadable, empty, oversized or unknown archive before it is moved.
   */
  private function assertReadableArchive(string $uri): void {
    $real = $this->fileSystem->realpath($uri);
    if (!$real || !is_file($real) || is_link($real)) {
      throw new ImportException('压缩包无法读取。');
    }
    $size = filesize($real);
    if ($size === FALSE || $size < 1) {
      throw new ImportException('压缩包是空的。');
    }
    if ($size > $this->limit('max_archive_bytes', 209715200)) {
      throw new ImportException('压缩包超过大小限制。');
    }
    $format = $this->detect($real);
    if ($format === '7z' && !$this->sevenZipBinary()) {
      throw new ImportException('服务器未安装 7z，无法处理 7Z。安装：sudo apt-get install 7zip。验证：7z i');
    }
  }

  /**
   * Identifies a real ZIP or 7Z from the file header.
   */
  public function detect(string $realpath): string {
    $handle = fopen($realpath, 'rb');
    if (!$handle) {
      throw new ImportException('压缩包无法读取。');
    }
    $magic = (string) fread($handle, 8);
    fclose($handle);
    if (str_starts_with($magic, "PK\x03\x04") || str_starts_with($magic, "PK\x05\x06") || str_starts_with($magic, "PK\x07\x08")) {
      return 'zip';
    }
    if (str_starts_with($magic, "\x37\x7A\xBC\xAF\x27\x1C")) {
      return '7z';
    }
    throw new ImportException('无法识别压缩包，仅支持 ZIP 和 7Z。');
  }

  /**
   * TRUE when a stored archive is still on disk.
   */
  public function exists(string $uri): bool {
    if ($uri === '') {
      return FALSE;
    }
    $real = $this->fileSystem->realpath($uri);
    return $real && is_file($real);
  }

  /**
   * Extracts an archive and returns image candidates in natural order.
   *
   * @return array{format: string, work_uri: string, files: array<int, array{relative: string, bytes: int, uri: string}>, skipped: int}
   *   Scan result.
   */
  public function openAndExtract(object $task): array {
    $uri = (string) $task->archive_uri;
    $real = $this->fileSystem->realpath($uri);
    if (!$real || !is_file($real)) {
      throw new ImportException('找不到源压缩包，无法继续处理。');
    }
    $format = $this->detect($real);
    $work = 'private://xedc-import/tasks/' . (int) $task->id . '/extract';
    $this->resetDirectory($work);
    $workReal = $this->fileSystem->realpath($work);
    if (!$workReal) {
      throw new ImportException('无法创建解压目录。');
    }
    if ($format === 'zip') {
      $this->extractZip($real, $workReal);
    }
    else {
      $this->extractSevenZip($real, $workReal);
    }
    $collected = $this->collect($work, $workReal);
    $collected['files'] = $this->sortFiles($collected['files']);
    $collected['format'] = $format;
    $collected['work_uri'] = $work;
    return $collected;
  }

  /**
   * Natural sort by relative path.
   *
   * @param array<int, array{relative: string, bytes: int, uri: string}> $files
   *   Unsorted files.
   *
   * @return array<int, array{relative: string, bytes: int, uri: string}>
   *   Sorted files.
   */
  public function sortFiles(array $files): array {
    usort($files, static function (array $a, array $b): int {
      return strnatcasecmp($a['relative'], $b['relative']);
    });
    return $files;
  }

  /**
   * TRUE when a zip entry path could escape the destination.
   */
  public function unsafeRelative(string $name): bool {
    $name = str_replace('\\', '/', $name);
    if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name)) {
      return TRUE;
    }
    foreach (explode('/', $name) as $part) {
      if ($part === '' || $part === '.' || $part === '..') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $payload
   *   Unused. Present so callers can share the intake shape.
   *
   * @return array{name: string, uri: string, fid: int, format: string, source: string}
   *   Stored file.
   */
  private function intakeUpload(int $fid, string $destination): array {
    $file = File::load($fid);
    if (!$file instanceof FileInterface) {
      throw new ImportException('上传的压缩包不存在。');
    }
    $uri = $file->getFileUri();
    if (!str_starts_with($uri, 'private://xedc-import/inbox/')) {
      throw new ImportException('上传文件位置无效。');
    }
    $this->assertReadableArchive($uri);
    $owner = (int) $file->getOwnerId();
    $uid = (int) $this->currentUser->id();
    if ($owner !== $uid && !$this->currentUser->hasPermission('administer xedc image imports')) {
      throw new ImportException('无权使用该上传文件。');
    }
    $name = $this->safeBasename($file->getFilename());
    $target = $destination . '/' . $name;
    $moved = $this->fileSystem->move($uri, $target, FileExists::Rename);
    $file->setFileUri($moved);
    $file->setPermanent();
    $file->save();
    return [
      'name' => $name,
      'uri' => $moved,
      'fid' => (int) $file->id(),
      'format' => '',
      'source' => 'upload',
    ];
  }

  /**
   * @return array{name: string, uri: string, fid: int, format: string, source: string}
   *   Stored file.
   */
  private function intakeServer(string $name, string $destination): array {
    if (!$this->currentUser->hasPermission('administer xedc image imports')) {
      throw new ImportException('只有管理员可以选择服务器上的压缩包。');
    }
    $options = $this->inboxOptions();
    if (!isset($options[$name])) {
      throw new ImportException('不能使用该服务器文件。');
    }
    $source = 'private://xedc-import/inbox/' . $name;
    $this->assertReadableArchive($source);
    $target = $destination . '/' . $this->safeBasename($name);
    $moved = $this->fileSystem->move($source, $target, FileExists::Rename);
    return [
      'name' => $this->safeBasename($name),
      'uri' => $moved,
      'fid' => 0,
      'format' => '',
      'source' => 'server',
    ];
  }

  /**
   * Records usage after the task id is known.
   */
  public function addUsage(int $fid, int $taskId): void {
    $file = $fid ? File::load($fid) : NULL;
    if ($file instanceof FileInterface) {
      $this->fileUsage->add($file, 'xedc_core', 'xedc_import_task', $taskId);
    }
  }

  /**
   * Extracts a ZIP without using archive-supplied absolute paths.
   */
  private function extractZip(string $archive, string $destination): void {
    $zip = new \ZipArchive();
    if ($zip->open($archive) !== TRUE) {
      throw new ImportException('ZIP 已损坏或无法读取。');
    }
    $entries = [];
    $total = 0;
    $maxFiles = $this->limit('max_files', 2000);
    $maxBytes = $this->limit('max_uncompressed_bytes', 1073741824);
    $maxDepth = $this->limit('max_depth', 8);
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $flags = defined('ZipArchive::FL_ENC_UTF_8') ? \ZipArchive::FL_ENC_UTF_8 : 0;
      $name = $zip->getNameIndex($i, $flags);
      if ($name === FALSE) {
        $name = $zip->getNameIndex($i);
      }
      $name = str_replace('\\', '/', (string) $name);
      if ($name === '' || str_ends_with($name, '/')) {
        continue;
      }
      if ($this->unsafeRelative($name) || substr_count($name, '/') > $maxDepth) {
        $zip->close();
        throw new ImportException('压缩包包含不安全路径或目录过深。');
      }
      $stat = $zip->statIndex($i);
      $size = (int) ($stat['size'] ?? 0);
      $total += $size;
      $entries[] = ['index' => $i, 'name' => $name, 'size' => $size];
      if (count($entries) > $maxFiles || $total > $maxBytes) {
        $zip->close();
        throw new ImportException('解压后的文件数量或体积超过限制。');
      }
    }
    $zip->close();
    if (!$entries) {
      return;
    }
    $zip = new \ZipArchive();
    if ($zip->open($archive) !== TRUE) {
      throw new ImportException('ZIP 已损坏或无法读取。');
    }
    $written = 0;
    $used = [];
    try {
      foreach ($entries as $entry) {
        $relative = $this->uniqueRelative($entry['name'], $used);
        $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
          throw new ImportException('无法创建解压目录。');
        }
        $input = $zip->getStreamIndex($entry['index']);
        if (!$input) {
          throw new ImportException('ZIP 已损坏或无法读取。');
        }
        $output = fopen($target, 'wb');
        if (!$output) {
          fclose($input);
          throw new ImportException('无法写入解压文件。');
        }
        $copied = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        $resolved = realpath($target);
        if ($copied === FALSE || !$resolved || !str_starts_with($resolved, $destination . DIRECTORY_SEPARATOR)) {
          if ($resolved && is_file($resolved)) {
            unlink($resolved);
          }
          throw new ImportException('压缩包包含不安全路径。');
        }
        if (is_link($target)) {
          unlink($target);
          throw new ImportException('压缩包包含符号链接。');
        }
        $written += (int) $copied;
        if ($written > $maxBytes) {
          throw new ImportException('解压后的体积超过限制。');
        }
      }
    }
    finally {
      $zip->close();
    }
  }

  /**
   * Lists a 7Z first, then extracts with an argument array.
   */
  private function extractSevenZip(string $archive, string $destination): void {
    $binary = $this->sevenZipBinary();
    if (!$binary) {
      throw new ImportException('服务器未安装 7z，无法处理 7Z。安装：sudo apt-get install 7zip。验证：7z i');
    }
    $listing = $this->runSevenZip([$binary, 'l', '-slt', $archive], 120);
    if ($listing['code'] >= 2) {
      throw new ImportException('7Z 已损坏或无法读取。');
    }
    $entries = $this->parseSevenZipList($listing['output']);
    $total = 0;
    $maxFiles = $this->limit('max_files', 2000);
    $maxBytes = $this->limit('max_uncompressed_bytes', 1073741824);
    $maxDepth = $this->limit('max_depth', 8);
    foreach ($entries as $entry) {
      if ($this->unsafeRelative($entry['path']) || substr_count($entry['path'], '/') > $maxDepth) {
        throw new ImportException('压缩包包含不安全路径或目录过深。');
      }
      $total += $entry['size'];
    }
    if (count($entries) > $maxFiles || $total > $maxBytes) {
      throw new ImportException('解压后的文件数量或体积超过限制。');
    }
    $extracted = $this->runSevenZip([$binary, 'x', '-y', '-o' . $destination, $archive], 180);
    if ($extracted['code'] >= 2) {
      throw new ImportException('7Z 已损坏或无法读取。');
    }
    $this->rejectSpecialFiles($destination);
    $this->assertExtractedSize($destination, $maxBytes);
  }

  /**
   * @param string[] $command
   *   Argument vector. Nothing is passed through a shell.
   *
   * @return array{code: int, output: string}
   *   Exit code and combined output.
   */
  private function runSevenZip(array $command, int $timeout): array {
    $descriptor = [
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes);
    if (!is_resource($process)) {
      throw new ImportException('无法执行 7z。');
    }
    stream_set_blocking($pipes[1], FALSE);
    stream_set_blocking($pipes[2], FALSE);
    $output = '';
    $deadline = time() + $timeout;
    while (TRUE) {
      $status = proc_get_status($process);
      $output .= (string) stream_get_contents($pipes[1]);
      $output .= (string) stream_get_contents($pipes[2]);
      if (!$status['running']) {
        break;
      }
      if (strlen($output) > 8388608) {
        proc_terminate($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        throw new ImportException('压缩包文件列表过大。');
      }
      if (time() > $deadline) {
        proc_terminate($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        throw new ImportException('解压超时。');
      }
      usleep(100000);
    }
    $output .= (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'output' => $output];
  }

  /**
   * @return array<int, array{path: string, size: int}>
   *   File entries.
   */
  private function parseSevenZipList(string $output): array {
    $entries = [];
    $current = [];
    $flush = function () use (&$current, &$entries): void {
      $path = str_replace('\\', '/', (string) ($current['Path'] ?? ''));
      $folder = (string) ($current['Folder'] ?? '');
      $isFile = $folder === '-' || ($folder === '' && isset($current['Size']) && !isset($current['Type']));
      if ($path !== '' && $isFile && !isset($current['Type'])) {
        $entries[] = ['path' => $path, 'size' => (int) ($current['Size'] ?? 0)];
      }
      $current = [];
    };
    foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
      if (trim($line) === '') {
        $flush();
        continue;
      }
      if (!str_contains($line, ' = ')) {
        continue;
      }
      [$key, $value] = explode(' = ', $line, 2);
      $current[trim($key)] = trim($value);
    }
    $flush();
    return $entries;
  }

  /**
   * Deletes an oversized extract tree that is still inside the task directory.
   */
  private function assertExtractedSize(string $destination, int $maxBytes): void {
    $root = realpath($destination);
    if (!$root) {
      throw new ImportException('解压目录不存在。');
    }
    $total = 0;
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if (!$file->isFile() || $file->isLink()) {
        continue;
      }
      $total += (int) $file->getSize();
      if ($total > $maxBytes) {
        $this->deleteTree($root);
        throw new ImportException('解压后的体积超过限制。');
      }
    }
  }

  /**
   * Removes symlinks and refuses anything that escaped the directory.
   */
  private function rejectSpecialFiles(string $destination): void {
    $root = realpath($destination);
    if (!$root) {
      throw new ImportException('解压目录不存在。');
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
      $path = $file->getPathname();
      if (is_link($path)) {
        unlink($path);
        throw new ImportException('压缩包包含符号链接。');
      }
      $resolved = realpath($path);
      if (!$resolved || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        throw new ImportException('压缩包包含不安全路径。');
      }
      if (!$file->isFile() && !$file->isDir()) {
        unlink($path);
        throw new ImportException('压缩包包含特殊文件。');
      }
    }
  }

  /**
   * @return array{files: array<int, array{relative: string, bytes: int, uri: string}>, skipped: int}
   *   Image candidates.
   */
  private function collect(string $workUri, string $workReal): array {
    $root = realpath($workReal);
    if (!$root) {
      throw new ImportException('解压目录不存在。');
    }
    $files = [];
    $skipped = 0;
    $total = 0;
    $maxBytes = $this->limit('max_uncompressed_bytes', 1073741824);
    $maxDepth = $this->limit('max_depth', 8);
    if (!is_dir($root)) {
      return ['files' => [], 'skipped' => 0];
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if (!$file->isFile() || $file->isLink()) {
        continue;
      }
      $path = $file->getPathname();
      $resolved = realpath($path);
      if (!$resolved || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        throw new ImportException('压缩包包含不安全路径。');
      }
      $relative = substr($resolved, strlen($root) + 1);
      $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
      if ($this->ignored($relative) || substr_count($relative, '/') > $maxDepth) {
        if (substr_count($relative, '/') > $maxDepth) {
          throw new ImportException('目录层级超过限制。');
        }
        $skipped++;
        continue;
      }
      $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
      $bytes = (int) filesize($resolved);
      $total += $bytes;
      if ($total > $maxBytes) {
        throw new ImportException('解压后的体积超过限制。');
      }
      if (!in_array($extension, self::IMAGE_EXTENSIONS, TRUE)) {
        $skipped++;
        continue;
      }
      $files[] = [
        'relative' => $relative,
        'bytes' => $bytes,
        'uri' => $workUri . '/' . $relative,
      ];
    }
    return ['files' => $files, 'skipped' => $skipped];
  }

  /**
   * Skips metadata files that are not part of the gallery.
   */
  private function ignored(string $relative): bool {
    $base = basename($relative);
    if ($base === '' || str_starts_with($base, '.')) {
      return TRUE;
    }
    if (str_starts_with($relative, '__MACOSX/')) {
      return TRUE;
    }
    return in_array(strtolower($base), ['thumbs.db', 'desktop.ini'], TRUE);
  }

  /**
   * Deletes and recreates a directory inside the task tree.
   */
  private function resetDirectory(string $uri): void {
    $parent = dirname($uri);
    $this->fileSystem->prepareDirectory($parent, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $real = $this->fileSystem->realpath($uri);
    $parent = $this->fileSystem->realpath(dirname($uri));
    if ($real && $parent && str_starts_with($real, $parent . DIRECTORY_SEPARATOR) && is_dir($real)) {
      $this->deleteTree($real);
    }
    $this->fileSystem->prepareDirectory($uri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  }

  /**
   * Deletes a real directory that has already been checked.
   */
  private function deleteTree(string $real): void {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
      $path = $file->getPathname();
      if ($file->isLink() || $file->isFile()) {
        unlink($path);
      }
      else {
        rmdir($path);
      }
    }
    rmdir($real);
  }

  /**
   * Keeps two archive entries that share a path.
   *
   * @param array<string, bool> $used
   *   Paths already written.
   */
  private function uniqueRelative(string $relative, array &$used): string {
    $relative = str_replace('\\', '/', $relative);
    if (!isset($used[$relative])) {
      $used[$relative] = TRUE;
      return $relative;
    }
    $directory = pathinfo($relative, PATHINFO_DIRNAME);
    $filename = pathinfo($relative, PATHINFO_FILENAME);
    $extension = pathinfo($relative, PATHINFO_EXTENSION);
    $prefix = $directory !== '.' ? $directory . '/' : '';
    $suffix = $extension !== '' ? '.' . $extension : '';
    $n = 2;
    do {
      $candidate = $prefix . $filename . ' (' . $n . ')' . $suffix;
      $n++;
    } while (isset($used[$candidate]));
    $used[$candidate] = TRUE;
    return $candidate;
  }

  /**
   * Strips path pieces from a display name.
   */
  private function safeBasename(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name) ?: 'archive';
    return mb_substr($name, 0, 180);
  }

  /**
   * Configured limit, or the default when unset.
   */
  private function limit(string $key, int $default): int {
    $value = (int) $this->configFactory->get('xedc_core.settings')->get($key);
    return $value > 0 ? $value : $default;
  }

}
