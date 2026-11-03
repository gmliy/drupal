<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\FileExistsException;
use Drupal\Core\File\Exception\FileWriteException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManager;

/**
 * Keeps image style derivatives on the local public filesystem.
 *
 * The public scheme is the R2 wrapper, so S3fsFileSystem would upload any
 * public:// destination, including styles/. Derivatives must stay on disk
 * and must not create new bucket objects.
 */
final class ImageStyleFileSystem implements FileSystemInterface {

  public function __construct(
    protected FileSystemInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function moveUploadedFile($filename, $uri) {
    $path = $this->stylePath(is_string($uri) ? $uri : '');
    if ($path === NULL) {
      return $this->inner->moveUploadedFile($filename, $uri);
    }
    if (!$this->prepareLocalDirectory(dirname($path))) {
      return FALSE;
    }
    return move_uploaded_file($filename, $path);
  }

  /**
   * {@inheritdoc}
   */
  public function chmod($uri, $mode = NULL) {
    $path = $this->stylePath(is_string($uri) ? $uri : '');
    if ($path !== NULL && file_exists($path)) {
      return chmod($path, $mode ?? (is_dir($path) ? 0775 : 0644));
    }
    return $this->inner->chmod($uri, $mode);
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($uri, $context = NULL) {
    return $this->inner->unlink($uri, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function realpath($uri) {
    $path = $this->stylePath(is_string($uri) ? $uri : '');
    if ($path === NULL) {
      return $this->inner->realpath($uri);
    }
    $real = realpath($path);
    return $real === FALSE ? FALSE : $real;
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri) {
    return $this->inner->dirname($uri);
  }

  /**
   * {@inheritdoc}
   */
  public function basename($uri, $suffix = NULL) {
    return $this->inner->basename($uri, $suffix);
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir($uri, $mode = NULL, $recursive = FALSE, $context = NULL) {
    return $this->inner->mkdir($uri, $mode, $recursive, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($uri, $context = NULL) {
    return $this->inner->rmdir($uri, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function tempnam($directory, $prefix) {
    return $this->inner->tempnam($directory, $prefix);
  }

  /**
   * {@inheritdoc}
   */
  public function copy($source, $destination, /* FileExists */$fileExists = FileExists::Rename) {
    if (!is_string($destination) || $this->stylePath($destination) === NULL) {
      return $this->inner->copy($source, $destination, $fileExists);
    }
    return $this->transfer((string) $source, $destination, $fileExists, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($path) {
    $local = $this->stylePath(is_string($path) ? $path : '');
    if ($local !== NULL && is_file($local)) {
      return unlink($local);
    }
    return $this->inner->delete($path);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteRecursive($path, ?callable $callback = NULL) {
    $local = $this->stylePath(is_string($path) ? $path : '');
    if ($local === NULL) {
      return $this->inner->deleteRecursive($path, $callback);
    }
    if (!file_exists($local)) {
      return TRUE;
    }
    if (is_file($local)) {
      if ($callback) {
        $callback($path);
      }
      return unlink($local);
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($local, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
      $itemPath = $item->getPathname();
      if ($callback) {
        $callback($itemPath);
      }
      if ($item->isDir()) {
        if (!rmdir($itemPath)) {
          return FALSE;
        }
      }
      elseif (!unlink($itemPath)) {
        return FALSE;
      }
    }
    return rmdir($local);
  }

  /**
   * {@inheritdoc}
   */
  public function move($source, $destination, /* FileExists */$fileExists = FileExists::Rename) {
    if (!is_string($destination) || $this->stylePath($destination) === NULL) {
      return $this->inner->move($source, $destination, $fileExists);
    }
    return $this->transfer((string) $source, $destination, $fileExists, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function saveData($data, $destination, /* FileExists */$fileExists = FileExists::Rename) {
    if (!is_string($destination) || $this->stylePath($destination) === NULL) {
      return $this->inner->saveData($data, $destination, $fileExists);
    }
    $temp = $this->inner->tempnam('temporary://', 'xedcstyle');
    if (!is_string($temp) || file_put_contents($temp, $data) === FALSE) {
      throw new FileWriteException('图片样式临时文件无法创建。');
    }
    return $this->move($temp, $destination, $fileExists);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareDirectory(&$directory, $options = self::MODIFY_PERMISSIONS) {
    return $this->inner->prepareDirectory($directory, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function createFilename($basename, $directory) {
    return $this->inner->createFilename($basename, $directory);
  }

  /**
   * {@inheritdoc}
   */
  public function getDestinationFilename($destination, /* FileExists */$fileExists) {
    return $this->inner->getDestinationFilename($destination, $fileExists);
  }

  /**
   * {@inheritdoc}
   */
  public function getTempDirectory() {
    return $this->inner->getTempDirectory();
  }

  /**
   * {@inheritdoc}
   */
  public function scanDirectory($dir, $mask, array $options = []) {
    return $this->inner->scanDirectory($dir, $mask, $options);
  }

  /**
   * Copies or moves a file onto the local image-style path.
   */
  private function transfer(string $source, string $destination, FileExists|int $fileExists, bool $unlinkSource): string {
    $fileExists = $this->existsMode($fileExists);
    $path = $this->stylePath($destination);
    if ($path === NULL) {
      throw new FileException('图片样式路径无效。');
    }
    if (!$this->prepareLocalDirectory(dirname($path))) {
      throw new FileWriteException("图片样式目录无法创建：$destination");
    }
    if (is_file($path)) {
      if ($fileExists === FileExists::Error) {
        throw new FileExistsException("图片样式文件已存在：$destination");
      }
      if ($fileExists === FileExists::Rename) {
        $path = $this->renamed($path);
        $destination = $this->uriForPath($path);
      }
      elseif (!unlink($path)) {
        throw new FileWriteException("无法替换图片样式文件：$destination");
      }
    }
    if (!$this->writeLocal($source, $path)) {
      throw new FileWriteException("无法写入本地图片样式：$destination");
    }
    chmod($path, 0644);
    if ($unlinkSource && $this->canUnlinkSource($source)) {
      $sourcePath = $this->inner->realpath($source);
      if (is_string($sourcePath) && is_file($sourcePath)) {
        unlink($sourcePath);
      }
      else {
        @unlink($source);
      }
    }
    return $destination;
  }

  private function writeLocal(string $source, string $path): bool {
    $sourcePath = $this->inner->realpath($source);
    if (is_string($sourcePath) && is_file($sourcePath)) {
      return copy($sourcePath, $path);
    }
    $input = @fopen($source, 'rb');
    $output = @fopen($path, 'wb');
    if ($input === FALSE || $output === FALSE) {
      if (is_resource($input)) {
        fclose($input);
      }
      if (is_resource($output)) {
        fclose($output);
      }
      return FALSE;
    }
    $copied = stream_copy_to_stream($input, $output);
    fclose($input);
    fclose($output);
    return $copied !== FALSE;
  }

  /**
   * Original public and private objects are never removed by a style write.
   */
  private function canUnlinkSource(string $source): bool {
    $scheme = StreamWrapperManager::getScheme($source) ?: '';
    if ($scheme === 'public' || $scheme === 'private') {
      return $this->stylePath($source) !== NULL;
    }
    return TRUE;
  }

  private function existsMode(FileExists|int $fileExists): FileExists {
    if ($fileExists instanceof FileExists) {
      return $fileExists;
    }
    return FileExists::fromLegacyInt($fileExists, __METHOD__);
  }

  private function renamed(string $path): string {
    $directory = dirname($path);
    $name = pathinfo($path, PATHINFO_FILENAME);
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    $suffix = $extension === '' ? '' : '.' . $extension;
    $i = 0;
    do {
      $candidate = $directory . '/' . $name . '_' . $i . $suffix;
      $i++;
    } while (is_file($candidate));
    return $candidate;
  }

  private function uriForPath(string $path): string {
    $root = $this->filesRoot();
    $relative = ltrim(substr($path, strlen($root)), '/');
    return 'public://' . $relative;
  }

  private function prepareLocalDirectory(string $directory): bool {
    if (is_dir($directory)) {
      return is_writable($directory);
    }
    return mkdir($directory, 0775, TRUE) && is_dir($directory);
  }

  private function stylePath(string $uri): ?string {
    $scheme = StreamWrapperManager::getScheme($uri) ?: '';
    if ($scheme !== 'public') {
      return NULL;
    }
    $target = StreamWrapperManager::getTarget($uri);
    if (!is_string($target) || !PublicUrl::isStyle($target)) {
      return NULL;
    }
    $target = str_replace('\\', '/', $target);
    if ($target === '' || str_contains($target, "\0") || str_contains($target, '..')) {
      return NULL;
    }
    $root = $this->filesRoot();
    if ($root === NULL) {
      return NULL;
    }
    $path = $root . '/' . $target;
    $styles = $root . '/styles/';
    if (!str_starts_with($path, $styles)) {
      return NULL;
    }
    return $path;
  }

  private function filesRoot(): ?string {
    $relative = PublicStream::basePath();
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
      return NULL;
    }
    $root = DRUPAL_ROOT . '/' . $relative;
    $real = realpath($root);
    return $real === FALSE ? NULL : $real;
  }

}
