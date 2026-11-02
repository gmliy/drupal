<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps an original beside a WebP delivery file and writes an AVIF sidecar.
 *
 * A failed conversion does not delete the file entity or its source.
 */
final class ImageVariantBuilder {

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected Connection $database,
    protected LocalTempFileManager $temp,
    protected LoggerInterface $logger,
  ) {}

  /**
   * @param array<string, mixed> $sourceInfo
   *   Validated source metadata.
   * @param array<string, mixed> $built
   *   Official image written by the pipeline.
   */
  public function keep(FileInterface $file, ?string $sourcePath, array $sourceInfo, array $built): void {
    if (!$this->database->schema()->tableExists('xedc_storage_variant')) {
      return;
    }
    if ($sourcePath === NULL || !is_file($sourcePath) || is_link($sourcePath)) {
      return;
    }
    $official = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    $sourceExtension = strtolower((string) ($sourceInfo['extension'] ?? pathinfo($sourcePath, PATHINFO_EXTENSION)));
    if ($sourceExtension !== '' && $sourceExtension !== $official) {
      $this->storeCopy($file, $sourcePath, 'original', (string) ($sourceInfo['mime'] ?? 'application/octet-stream'), $sourceExtension);
    }
    if ($official !== 'avif' && function_exists('imageavif') && empty($sourceInfo['animated']) && empty($built['animated'])) {
      $this->storeAvif($file, $sourcePath);
    }
  }

  private function storeCopy(FileInterface $file, string $source, string $variant, string $mime, string $extension): void {
    $directory = $this->directory($file) . '/originals';
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->logger->warning('原图副本目录无法创建，正式文件已保留。');
      return;
    }
    $name = pathinfo($file->getFilename(), PATHINFO_FILENAME) . '.' . $extension;
    try {
      $uri = $this->fileSystem->copy($source, $directory . '/' . $name, FileExists::Replace);
    }
    catch (\Throwable $error) {
      $this->logger->warning('原图副本没有写入，正式文件已保留：@message', ['@message' => $error->getMessage()]);
      return;
    }
    $this->remember((int) $file->id(), $variant, $uri, $source, $mime);
  }

  private function storeAvif(FileInterface $file, string $source): void {
    $info = @getimagesize($source);
    $type = (int) ($info[2] ?? 0);
    $image = match ($type) {
      IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
      IMAGETYPE_PNG => @imagecreatefrompng($source),
      IMAGETYPE_GIF => @imagecreatefromgif($source),
      IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : FALSE,
      default => FALSE,
    };
    if (!$image) {
      $this->logger->warning('AVIF 转换没有读取到原图，正式文件已保留。');
      return;
    }
    $dest = '';
    try {
      $dest = $this->temp->createFile('avif', '.avif');
      imagepalettetotruecolor($image);
      $saved = @imageavif($image, $dest, 60);
      imagedestroy($image);
      if (!$saved || !is_file($dest) || filesize($dest) === 0) {
        $this->logger->warning('AVIF 转换失败，正式文件已保留。');
        return;
      }
      $directory = $this->directory($file);
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        return;
      }
      $name = pathinfo($file->getFilename(), PATHINFO_FILENAME) . '.avif';
      $uri = $this->fileSystem->copy($dest, $directory . '/' . $name, FileExists::Replace);
      $this->remember((int) $file->id(), 'avif', $uri, $dest, 'image/avif');
    }
    catch (\Throwable $error) {
      $this->logger->warning('AVIF 副本没有写入，正式文件已保留：@message', ['@message' => $error->getMessage()]);
    }
    finally {
      if ($dest !== '') {
        $this->temp->delete($dest);
      }
    }
  }

  private function directory(FileInterface $file): string {
    $uri = str_replace('\\', '/', $file->getFileUri());
    $slash = strrpos($uri, '/');
    return $slash === FALSE ? $uri : substr($uri, 0, $slash);
  }

  private function remember(int $fid, string $variant, string $uri, string $path, string $mime): void {
    $hash = hash_file('sha256', $path);
    $bytes = filesize($path);
    $this->database->merge('xedc_storage_variant')
      ->keys(['fid' => $fid, 'variant' => $variant])
      ->fields([
        'uri' => substr($uri, 0, 255),
        'sha256' => is_string($hash) ? $hash : '',
        'bytes' => $bytes === FALSE ? 0 : (int) $bytes,
        'mime' => substr($mime, 0, 128),
        'status' => 'stored',
        'message' => '',
      ])
      ->execute();
  }

}
