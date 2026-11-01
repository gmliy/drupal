<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\Media;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Validates one image, stores the original, and builds WebP derivatives.
 */
class ImagePipeline {

  /**
   * Existing image styles. Derivatives are WebP; originals stay untouched.
   */
  private const STYLES = [
    'photo_gallery_1300',
    'photo_gallery_650',
    'photo_card_640',
    'meitu_card_cover',
  ];

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected ConfigFactoryInterface $configFactory,
    protected AltManager $alt,
    protected TaskRepository $tasks,
    protected LoggerInterface $logger,
  ) {}

  /**
   * TRUE when a GIF has more than one frame.
   */
  public function isAnimatedGif(string $realpath): bool {
    $handle = fopen($realpath, 'rb');
    if (!$handle) {
      return FALSE;
    }
    $count = 0;
    $tail = '';
    while (!feof($handle)) {
      $chunk = fread($handle, 65536);
      if ($chunk === FALSE || $chunk === '') {
        break;
      }
      $count += substr_count($tail . $chunk, "\x21\xF9\x04");
      if ($count > 1) {
        fclose($handle);
        return TRUE;
      }
      $tail = substr($chunk, -3);
    }
    fclose($handle);
    return FALSE;
  }

  /**
   * Imports one scanned image. A bad image is recorded and does not abort the task.
   */
  public function import(object $task, object $item, NodeInterface $node): void {
    $itemId = (int) $item->id;
    if ((int) $item->mid > 0 && (int) $item->fid > 0) {
      $this->tasks->markItem($itemId, ['status' => 'success']);
      return;
    }
    $real = $this->fileSystem->realpath((string) $item->temp_uri);
    $work = $this->fileSystem->realpath((string) $task->work_uri);
    if (!$real || !$work || !is_file($real) || is_link($real) || !str_starts_with($real, $work . DIRECTORY_SEPARATOR)) {
      throw new ImportException('临时图片不在任务目录中。');
    }
    $checked = $this->validate($real);
    $sequence = (int) $item->weight;
    $total = max($sequence, (int) $task->total);
    $alt = $this->alt->format($node->label(), $sequence, $total);
    $directoryUri = 'public://' . date('Y-m');
    $directory = $directoryUri;
    $ready = $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $directoryReal = $ready ? $this->fileSystem->realpath($directory) : FALSE;
    clearstatcache(TRUE, $directoryReal ?: $directoryUri);
    if (!$ready || !is_string($directoryReal) || $directoryReal === '' || !is_dir($directoryReal) || !is_writable($directoryReal)) {
      throw new ImportException('目标目录 ' . $directoryUri . ' 无法创建或当前用户不可写，已停止复制。');
    }
    $filename = sprintf('n%d-%s.%s', (int) $node->id(), str_pad((string) $sequence, max(3, strlen((string) $total)), '0', STR_PAD_LEFT), $checked['extension']);
    $destination = $directory . '/' . $filename;
    $uri = $this->fileSystem->copy($real, $destination, FileExists::Rename);
    $file = File::create([
      'uri' => $uri,
      'uid' => (int) $task->uid,
      'status' => FileInterface::STATUS_PERMANENT,
      'filename' => basename($uri),
    ]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => mb_substr((string) $item->original_name, 0, 255),
      'uid' => (int) $task->uid,
      'status' => 1,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $alt,
        'width' => $checked['width'],
        'height' => $checked['height'],
      ],
    ]);
    $media->xedc_import_internal = TRUE;
    $media->save();
    $this->alt->remember((int) $media->id(), $alt, 'auto');
    $this->tasks->markItem($itemId, [
      'status' => 'success',
      'fid' => (int) $file->id(),
      'mid' => (int) $media->id(),
      'bytes' => $checked['bytes'],
      'width' => $checked['width'],
      'height' => $checked['height'],
      'mime' => $checked['mime'],
      'extension' => $checked['extension'],
      'animated' => $checked['animated'] ? 1 : 0,
      'alt' => $alt,
      'error' => '',
    ]);
    $webp = $this->derivatives($file, $checked['animated']);
    $this->rememberFile((int) $file->id(), $checked['animated'], $webp, $checked['bytes']);
    $this->tasks->markItem($itemId, [
      'status' => 'success',
      'fid' => (int) $file->id(),
      'mid' => (int) $media->id(),
      'bytes' => $checked['bytes'],
      'width' => $checked['width'],
      'height' => $checked['height'],
      'mime' => $checked['mime'],
      'extension' => $checked['extension'],
      'animated' => $checked['animated'] ? 1 : 0,
      'alt' => $alt,
      'webp' => $webp,
      'error' => '',
    ]);
    if ($webp === 'fallback') {
      $this->tasks->log((int) $task->id, 'warning', (string) $item->original_name . ' 的 WebP 转换失败，前台回退原图。');
    }
  }

  /**
   * Checks MIME, dimensions and that GD can decode the file.
   *
   * @return array{mime: string, extension: string, width: int, height: int, bytes: int, animated: bool}
   *   Validated image.
   */
  public function validate(string $realpath): array {
    $bytes = filesize($realpath);
    if ($bytes === FALSE || $bytes < 1) {
      throw new ImportException('图片是空文件。');
    }
    if ($bytes > $this->limit('max_image_bytes', 52428800)) {
      throw new ImportException('单张图片超过大小限制。');
    }
    $info = @getimagesize($realpath);
    $type = (int) ($info[2] ?? 0);
    $map = [
      IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
      IMAGETYPE_PNG => ['image/png', 'png'],
      IMAGETYPE_GIF => ['image/gif', 'gif'],
      IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];
    if (!$info || !isset($map[$type])) {
      throw new ImportException('不是受支持的图片，或文件已损坏。');
    }
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($realpath);
    if ($mime !== $map[$type][0]) {
      throw new ImportException('文件内容与图片格式不一致。');
    }
    $width = (int) $info[0];
    $height = (int) $info[1];
    if ($width < 1 || $height < 1) {
      throw new ImportException('图片尺寸无效。');
    }
    if (($width * $height) > $this->limit('max_pixels', 40000000)) {
      throw new ImportException('图片像素超过限制。');
    }
    $this->assertDecodable($realpath, $type);
    return [
      'mime' => $mime,
      'extension' => $map[$type][1],
      'width' => $width,
      'height' => $height,
      'bytes' => (int) $bytes,
      'animated' => $type === IMAGETYPE_GIF && $this->isAnimatedGif($realpath),
    ];
  }

  /**
   * Builds the existing image styles. Animated GIF is not converted.
   */
  public function derivatives(FileInterface $file, bool $animated): string {
    if ($animated) {
      return 'animated';
    }
    $ok = TRUE;
    foreach (self::STYLES as $id) {
      $style = ImageStyle::load($id);
      if (!$style) {
        continue;
      }
      $derivative = $style->buildUri($file->getFileUri());
      $directory = $this->fileSystem->dirname($derivative);
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      if (!$style->createDerivative($file->getFileUri(), $derivative)) {
        $ok = FALSE;
        $this->logger->warning('图片样式 @style 未能生成 WebP：@uri', [
          '@style' => $id,
          '@uri' => $file->getFileUri(),
        ]);
      }
    }
    return $ok ? 'ok' : 'fallback';
  }

  /**
   * Confirms GD can decode the image, then frees it.
   */
  private function assertDecodable(string $realpath, int $type): void {
    $image = match ($type) {
      IMAGETYPE_JPEG => @imagecreatefromjpeg($realpath),
      IMAGETYPE_PNG => @imagecreatefrompng($realpath),
      IMAGETYPE_GIF => @imagecreatefromgif($realpath),
      IMAGETYPE_WEBP => @imagecreatefromwebp($realpath),
      default => FALSE,
    };
    if (!$image) {
      throw new ImportException('图片无法解码。');
    }
    imagedestroy($image);
  }

  /**
   * Remembers how the front end should deliver this file.
   */
  private function rememberFile(int $fid, bool $animated, string $webp, int $bytes): void {
    \Drupal::database()->merge('xedc_image_file')
      ->keys(['fid' => $fid])
      ->fields([
        'animated' => $animated ? 1 : 0,
        'webp' => $webp,
        'bytes' => $bytes,
      ])
      ->execute();
  }

  /**
   * Configured limit, or the default when unset.
   */
  private function limit(string $key, int $default): int {
    $value = (int) $this->configFactory->get('xedc_core.settings')->get($key);
    return $value > 0 ? $value : $default;
  }

}
