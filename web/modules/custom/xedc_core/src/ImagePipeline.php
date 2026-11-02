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
use Drupal\xedc_core\Ops\Storage\ImageVariantBuilder;
use Drupal\xedc_core\Ops\Storage\LocalFileBridge;
use Psr\Log\LoggerInterface;

/**
 * Validates one image, stores one optimized file, and records both SHA-256 hashes.
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
    protected ?LocalFileBridge $files = NULL,
    protected ?ImageVariantBuilder $variants = NULL,
  ) {}

  /**
   * TRUE when remote originals are copied locally before GD runs.
   */
  public function remoteReady(): bool {
    return $this->files instanceof LocalFileBridge;
  }

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
    try {
      $this->importBody($task, $item, $node);
    }
    finally {
      $this->files?->release();
    }
  }

  /**
   * Imports one image. Remote temporary copies are released by import().
   */
  private function importBody(object $task, object $item, NodeInterface $node): void {
    $itemId = (int) $item->id;
    $taskId = (int) $task->id;
    if ((int) $item->mid > 0 && (int) $item->fid > 0) {
      $this->tasks->markItem($itemId, ['status' => 'success', 'error' => '']);
      return;
    }
    $attempts = (int) ($item->attempts ?? 0) + 1;
    if ($attempts > 3) {
      throw new ImportException('这张图片已达到重试次数上限。');
    }
    $this->tasks->markItem($itemId, ['attempts' => $attempts]);
    $real = $this->fileSystem->realpath((string) $item->temp_uri);
    $work = $this->fileSystem->realpath((string) $task->work_uri);
    if (!$real || !$work || !is_file($real) || is_link($real) || !str_starts_with($real, $work . DIRECTORY_SEPARATOR)) {
      throw new ImportException('临时图片不在任务目录中。');
    }
    $sourceHash = hash_file('sha256', $real);
    if (!is_string($sourceHash) || !preg_match('/^[a-f0-9]{64}$/', $sourceHash)) {
      throw new ImportException('无法计算原始图片 SHA-256。');
    }
    $this->tasks->markItem($itemId, ['source_sha256' => $sourceHash]);
    if ((int) $item->fid > 0) {
      $this->finishExistingFile($task, $item, $node, $sourceHash);
      return;
    }
    $prior = $this->taskMatch($taskId, $itemId, 'source_sha256', $sourceHash);
    if ($prior && $this->reuseItem($task, $item, $prior, $sourceHash)) {
      return;
    }
    $existing = $this->findReusable('source_sha256', $sourceHash);
    if ($existing && $this->reuseFile($task, $item, $node, $existing, $sourceHash, FALSE)) {
      return;
    }
    $checked = $this->validate($real);
    $optimized = $work . DIRECTORY_SEPARATOR . 'optimized';
    if (!is_dir($optimized) && !mkdir($optimized, 0775, TRUE) && !is_dir($optimized)) {
      throw new ImportAbortException('无法创建压缩临时目录，已停止导入。');
    }
    $public = $this->fileSystem->realpath('public://');
    $need = max($checked['bytes'] * 2, 67108864);
    if (is_string($public) && $public !== '') {
      $this->assertDisk($public, $need);
    }
    $this->assertDisk($work, $need);
    if ($checked['animated']) {
      $built = $this->copyOfficial($real, $optimized, $itemId, $checked, 'animated');
    }
    else {
      $built = $this->tryWebp($real, $optimized, $itemId, $checked);
      if (!$built) {
        $this->logger->warning('图片压缩未采用，已保留可解码原图：@name', ['@name' => $item->original_name]);
        $this->tasks->log($taskId, 'warning', (string) $item->original_name . ' 压缩未采用，已保留原始图片作为正式文件。');
        $built = $this->copyOfficial($real, $optimized, $itemId, $checked, 'fallback');
      }
    }
    $storedHash = hash_file('sha256', $built['path']);
    if (!is_string($storedHash) || !preg_match('/^[a-f0-9]{64}$/', $storedHash)) {
      throw new ImportException('无法计算正式图片 SHA-256。');
    }
    $priorStored = $this->taskMatch($taskId, $itemId, 'stored_sha256', $storedHash);
    if ($priorStored && $this->reuseItem($task, $item, $priorStored, $sourceHash)) {
      $this->deleteBuilt($built['path'], $work);
      return;
    }
    $storedMatch = $this->findReusable('stored_sha256', $storedHash);
    if ($storedMatch && $this->reuseFile($task, $item, $node, $storedMatch, $sourceHash, FALSE)) {
      $this->deleteBuilt($built['path'], $work);
      return;
    }
    $this->publishNew($task, $item, $node, $built, $sourceHash, $storedHash, $real, $checked);
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
    $source = $file->getFileUri();
    $remote = $this->files instanceof LocalFileBridge && $this->files->isRemoteUri($source);
    if ($remote) {
      $local = $this->files->localPath($source);
      if ($local === NULL) {
        $this->logger->warning('远程原图无法复制到本地临时文件，图片样式未生成：@uri', [
          '@uri' => $file->getFileUri(),
        ]);
        return 'fallback';
      }
      $source = $local;
    }
    try {
      foreach (self::STYLES as $id) {
        $style = ImageStyle::load($id);
        if (!$style) {
          continue;
        }
        $derivative = $style->buildUri($file->getFileUri());
        $directory = $this->fileSystem->dirname($derivative);
        $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
        if (!$style->createDerivative($source, $derivative)) {
          $ok = FALSE;
          $this->logger->warning('图片样式 @style 未能生成 WebP：@uri', [
            '@style' => $id,
            '@uri' => $file->getFileUri(),
          ]);
        }
      }
      return $ok ? 'ok' : 'fallback';
    }
    finally {
      if ($remote && $this->files instanceof LocalFileBridge) {
        $this->files->release();
      }
    }
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
   * Remembers delivery flags and both SHA-256 fingerprints for a file.
   */
  private function rememberFile(int $fid, bool $animated, string $webp, int $bytes, string $sourceHash, string $storedHash): void {
    \Drupal::database()->merge('xedc_image_file')
      ->keys(['fid' => $fid])
      ->fields([
        'animated' => $animated ? 1 : 0,
        'webp' => $webp,
        'bytes' => $bytes,
        'source_sha256' => $sourceHash,
        'stored_sha256' => $storedHash,
      ])
      ->execute();
  }

  /**
   * Finishes a file that was saved before the worker stopped.
   */
  private function finishExistingFile(object $task, object $item, NodeInterface $node, string $sourceHash): void {
    $file = File::load((int) $item->fid);
    $real = $file instanceof FileInterface ? $this->readablePath($file->getFileUri()) : NULL;
    if (!$file instanceof FileInterface || !is_string($real) || !is_file($real) || str_starts_with($file->getFileUri(), 'private://')) {
      $this->tasks->markItem((int) $item->id, ['fid' => 0]);
      throw new ImportException('上次创建的正式文件已不可用。');
    }
    $storedHash = hash_file('sha256', $real);
    if (!is_string($storedHash) || !preg_match('/^[a-f0-9]{64}$/', $storedHash)) {
      throw new ImportException('无法计算正式图片 SHA-256。');
    }
    $media = $this->mediaForFile((int) $file->id());
    if (!$media) {
      $info = $this->describe($real);
      $media = $this->createMedia($task, $item, $node, $file, $info, $this->altText($task, $item, $node));
    }
    $this->markReady($item, $file, $media, $sourceHash, $storedHash, FALSE, 'success');
  }

  /**
   * Reuses the file and media already produced for an earlier item in this ZIP.
   */
  private function reuseItem(object $task, object $item, object $prior, string $sourceHash): bool {
    $file = File::load((int) $prior->fid);
    $media = Media::load((int) $prior->mid);
    $real = $file instanceof FileInterface ? $this->readablePath($file->getFileUri()) : NULL;
    if (!$file instanceof FileInterface || !$media || !is_string($real) || !is_file($real) || str_starts_with($file->getFileUri(), 'private://')) {
      return FALSE;
    }
    $storedHash = (string) ($prior->stored_sha256 ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $storedHash)) {
      $hashed = hash_file('sha256', $real);
      $storedHash = is_string($hashed) ? $hashed : '';
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $storedHash)) {
      return FALSE;
    }
    $this->markReady($item, $file, $media, $sourceHash, $storedHash, TRUE, 'duplicate');
    $this->tasks->log((int) $task->id, 'info', (string) $item->original_name . ' 与 ' . $prior->original_name . ' 重复，已复用第一次的结果。');
    return TRUE;
  }

  /**
   * Reuses a file that is already on the site. Creates a media only when none exists.
   *
   * @param array{file: \Drupal\file\FileInterface, media: \Drupal\media\MediaInterface|null} $existing
   *   Match that still has a readable file.
   */
  private function reuseFile(object $task, object $item, NodeInterface $node, array $existing, string $sourceHash, bool $zipDuplicate): bool {
    $file = $existing['file'];
    $real = $this->readablePath($file->getFileUri());
    if (!is_string($real) || !is_file($real)) {
      return FALSE;
    }
    $storedHash = hash_file('sha256', $real);
    if (!is_string($storedHash) || !preg_match('/^[a-f0-9]{64}$/', $storedHash)) {
      return FALSE;
    }
    $media = $existing['media'];
    $created = FALSE;
    if (!$media) {
      $this->tasks->markItem((int) $item->id, [
        'fid' => (int) $file->id(),
        'source_sha256' => $sourceHash,
        'stored_sha256' => $storedHash,
      ]);
      $info = $this->describe($real);
      $media = $this->createMedia($task, $item, $node, $file, $info, $this->altText($task, $item, $node));
      $created = TRUE;
    }
    $this->markReady($item, $file, $media, $sourceHash, $storedHash, TRUE, $zipDuplicate ? 'duplicate' : 'success');
    if ($created) {
      $this->tasks->log((int) $task->id, 'warning', (string) $item->original_name . ' 已有文件缺少媒体，已补建媒体 ' . $media->id() . '，没有新建文件。');
    }
    else {
      $this->tasks->log((int) $task->id, 'info', (string) $item->original_name . ' 与网站已有图片相同，已复用媒体 ' . $media->id() . '。');
    }
    return TRUE;
  }

  /**
   * Copies one optimized image into public files and creates its media.
   *
   * @param array{path: string, mime: string, extension: string, width: int, height: int, bytes: int, animated: bool, delivery: string} $built
   *   Validated official image still inside the task directory.
   */
  private function publishNew(object $task, object $item, NodeInterface $node, array $built, string $sourceHash, string $storedHash, ?string $sourcePath = NULL, array $sourceInfo = []): void {
    $directory = 'public://' . date('Y-m');
    $ready = $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $remotePublic = $this->files instanceof LocalFileBridge && $this->files->schemeIsRemote('public');
    $directoryReal = $ready ? $this->fileSystem->realpath($directory) : FALSE;
    clearstatcache(TRUE, is_string($directoryReal) ? $directoryReal : $directory);
    $localDirectory = is_string($directoryReal) && $directoryReal !== '' && is_dir($directoryReal) && is_writable($directoryReal);
    if (!$ready || (!$remotePublic && !$localDirectory)) {
      throw new ImportAbortException('目标目录 ' . $directory . ' 无法创建或当前用户不可写，已停止导入。');
    }
    $total = max((int) $item->weight, (int) $task->total);
    $filename = sprintf('n%d-%s.%s', (int) $node->id(), str_pad((string) $item->weight, max(3, strlen((string) $total)), '0', STR_PAD_LEFT), $built['extension']);
    $uri = $this->fileSystem->copy($built['path'], $directory . '/' . $filename, FileExists::Rename);
    $file = File::create([
      'uri' => $uri,
      'uid' => (int) $task->uid,
      'status' => FileInterface::STATUS_PERMANENT,
      'filename' => basename($uri),
    ]);
    try {
      $file->save();
    }
    catch (\Throwable $e) {
      if ($remotePublic) {
        $this->fileSystem->delete($uri);
      }
      else {
        $copied = $this->fileSystem->realpath($uri);
        if (is_string($copied) && is_file($copied)) {
          unlink($copied);
        }
      }
      throw new ImportException('正式文件记录创建失败。');
    }
    $storedReal = $this->readablePath($file->getFileUri());
    $bytes = is_string($storedReal) ? (int) filesize($storedReal) : 0;
    $this->rememberFile((int) $file->id(), $built['animated'], $built['delivery'], $bytes, $sourceHash, $storedHash);
    $alt = $this->altText($task, $item, $node);
    $this->tasks->markItem((int) $item->id, [
      'fid' => (int) $file->id(),
      'source_sha256' => $sourceHash,
      'stored_sha256' => $storedHash,
      'bytes' => $bytes,
      'width' => $built['width'],
      'height' => $built['height'],
      'mime' => $built['mime'],
      'extension' => $built['extension'],
      'animated' => $built['animated'] ? 1 : 0,
      'webp' => $built['delivery'],
    ]);
    try {
      $media = $this->createMedia($task, $item, $node, $file, $built, $alt);
    }
    catch (\Throwable $e) {
      throw new ImportException('图片媒体创建失败。');
    }
    $this->markReady($item, $file, $media, $sourceHash, $storedHash, FALSE, 'success', $alt, $built);
    if ($this->variants instanceof ImageVariantBuilder) {
      try {
        $this->variants->keep($file, $sourcePath, $sourceInfo, $built);
      }
      catch (\Throwable $error) {
        $this->logger->warning('图片副本没有全部生成，正式文件已保留：@message', ['@message' => $error->getMessage()]);
      }
    }
  }

  /**
   * Writes the final item row for a file and media pair.
   *
   * @param array{mime?: string, extension?: string, width?: int, height?: int, animated?: bool, delivery?: string} $built
   *   Stored image description when this call created it.
   */
  private function markReady(object $item, FileInterface $file, Media $media, string $sourceHash, string $storedHash, bool $reused, string $status, string $alt = '', array $built = []): void {
    $real = $this->readablePath($file->getFileUri());
    $bytes = is_string($real) && is_file($real) ? (int) filesize($real) : (int) $file->getSize();
    if ($alt === '' && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
      $alt = (string) $media->get('field_media_image')->alt;
    }
    $width = (int) ($built['width'] ?? 0);
    $height = (int) ($built['height'] ?? 0);
    if (($width < 1 || $height < 1) && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
      $width = (int) $media->get('field_media_image')->width;
      $height = (int) $media->get('field_media_image')->height;
    }
    $animated = array_key_exists('animated', $built) ? (!empty($built['animated']) ? 1 : 0) : 0;
    if (!$animated && is_string($real) && is_file($real) && $this->isAnimatedGif($real)) {
      $animated = 1;
    }
    $this->tasks->markItem((int) $item->id, [
      'status' => $status,
      'fid' => (int) $file->id(),
      'mid' => (int) $media->id(),
      'source_sha256' => $sourceHash,
      'stored_sha256' => $storedHash,
      'reused' => $reused ? 1 : 0,
      'bytes' => $bytes,
      'width' => $width,
      'height' => $height,
      'mime' => (string) ($built['mime'] ?? $file->getMimeType()),
      'extension' => (string) ($built['extension'] ?? pathinfo($file->getFilename(), PATHINFO_EXTENSION)),
      'animated' => $animated,
      'webp' => (string) ($built['delivery'] ?? ($animated ? 'animated' : '')),
      'alt' => $alt,
      'error' => '',
    ]);
  }

  /**
   * @return array{file: \Drupal\file\FileInterface, media: \Drupal\media\MediaInterface|null}|null
   *   A readable official file, with its media when one exists.
   */
  private function findReusable(string $column, string $hash): ?array {
    if (!in_array($column, ['source_sha256', 'stored_sha256'], TRUE) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
      return NULL;
    }
    $fids = \Drupal::database()->select('xedc_image_file', 'h')
      ->fields('h', ['fid'])
      ->condition($column, $hash)
      ->orderBy('h.fid')
      ->execute()
      ->fetchCol();
    foreach ($fids as $fid) {
      $file = File::load((int) $fid);
      if (!$file instanceof FileInterface || str_starts_with($file->getFileUri(), 'private://')) {
        continue;
      }
      $real = $this->readablePath($file->getFileUri());
      if (!is_string($real) || !is_file($real) || is_link($real)) {
        continue;
      }
      $stored = hash_file('sha256', $real);
      $row = \Drupal::database()->select('xedc_image_file', 'h')
        ->fields('h', ['stored_sha256'])
        ->condition('fid', (int) $fid)
        ->execute()
        ->fetchObject();
      if (!is_string($stored) || !$row || !hash_equals((string) $row->stored_sha256, $stored)) {
        continue;
      }
      $info = @getimagesize($real);
      if (!$info) {
        continue;
      }
      return [
        'file' => $file,
        'media' => $this->mediaForFile((int) $fid),
      ];
    }
    return NULL;
  }

  /**
   * Earlier item in the same task that already owns this hash.
   */
  private function taskMatch(int $taskId, int $itemId, string $column, string $hash): ?object {
    if (!in_array($column, ['source_sha256', 'stored_sha256'], TRUE) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
      return NULL;
    }
    $row = \Drupal::database()->select('xedc_import_item', 'i')
      ->fields('i')
      ->condition('task_id', $taskId)
      ->condition('id', $itemId, '<>')
      ->condition($column, $hash)
      ->condition('mid', 0, '>')
      ->condition('status', ['success', 'duplicate'], 'IN')
      ->orderBy('i.id')
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * First image media that still points at this file.
   */
  private function mediaForFile(int $fid): ?Media {
    if (!$this->databaseTable('media__field_media_image')) {
      return NULL;
    }
    $ids = \Drupal::database()->select('media__field_media_image', 'm')
      ->fields('m', ['entity_id'])
      ->condition('field_media_image_target_id', $fid)
      ->execute()
      ->fetchCol();
    if (!$ids) {
      return NULL;
    }
    foreach (Media::loadMultiple(array_map('intval', $ids)) as $media) {
      if ($media->bundle() === 'image' && $media->hasField('field_media_image') && (int) $media->get('field_media_image')->target_id === $fid) {
        return $media;
      }
    }
    return NULL;
  }

  /**
   * @param array{mime: string, extension: string, width: int, height: int, bytes: int, animated: bool} $info
   *   Image description stored on the media field.
   */
  private function createMedia(object $task, object $item, NodeInterface $node, FileInterface $file, array $info, string $alt): Media {
    $media = Media::create([
      'bundle' => 'image',
      'name' => mb_substr((string) $item->original_name, 0, 255),
      'uid' => (int) $task->uid,
      'status' => 1,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $alt,
        'width' => $info['width'],
        'height' => $info['height'],
      ],
    ]);
    $media->xedc_import_internal = TRUE;
    $media->save();
    $this->alt->remember((int) $media->id(), $alt, 'auto');
    return $media;
  }

  /**
   * ALT text for a newly created media. Shared media keeps the alt it already has.
   */
  private function altText(object $task, object $item, NodeInterface $node): string {
    $total = max((int) $item->weight, (int) $task->total, 1);
    return $this->alt->format($node->label(), (int) $item->weight, $total);
  }

  /**
   * Writes a WebP when it is smaller and still decodes. NULL keeps the caller on the original.
   *
   * @param array{mime: string, extension: string, width: int, height: int, bytes: int, animated: bool} $checked
   *   Validated source.
   *
   * @return array{path: string, mime: string, extension: string, width: int, height: int, bytes: int, animated: bool, delivery: string}|null
   *   Official WebP, or NULL when the original must be kept.
   */
  private function tryWebp(string $source, string $dir, int $itemId, array $checked): ?array {
    if (!function_exists('imagewebp') || !function_exists('imagecreatefromwebp') || $checked['animated']) {
      return NULL;
    }
    $type = match ($checked['extension']) {
      'jpg' => IMAGETYPE_JPEG,
      'png' => IMAGETYPE_PNG,
      'gif' => IMAGETYPE_GIF,
      'webp' => IMAGETYPE_WEBP,
      default => 0,
    };
    if ($type === 0 || !$this->memoryAllows($checked['width'], $checked['height'])) {
      if ($type !== 0) {
        $this->logger->warning('图片解码内存不足，跳过重新压缩。');
      }
      return NULL;
    }
    $image = NULL;
    $dest = $dir . DIRECTORY_SEPARATOR . 'item-' . $itemId . '.webp';
    try {
      $image = $this->openImage($source, $type);
      $points = $this->alphaPoints($image);
      $hasAlpha = $points !== [] || ($checked['extension'] === 'png' && $this->pngHasAlphaChannel($source));
      if (is_file($dest)) {
        unlink($dest);
      }
      $saved = @imagewebp($image, $dest, $this->webpQuality());
      imagedestroy($image);
      $image = NULL;
      if (!$saved || !is_file($dest)) {
        $this->deleteBuilt($dest, dirname($dir));
        return NULL;
      }
      $described = $this->describe($dest);
      if ($described['mime'] !== 'image/webp' || $described['width'] !== $checked['width'] || $described['height'] !== $checked['height']) {
        $this->deleteBuilt($dest, dirname($dir));
        return NULL;
      }
      $proof = @imagecreatefromwebp($dest);
      if (!$proof instanceof \GdImage) {
        $this->deleteBuilt($dest, dirname($dir));
        return NULL;
      }
      $keptAlpha = !$hasAlpha || !$points || $this->alphaSurvived($proof, $points);
      imagedestroy($proof);
      if (!$keptAlpha) {
        $this->deleteBuilt($dest, dirname($dir));
        return NULL;
      }
      if ($checked['extension'] === 'webp' && $described['bytes'] >= (int) floor($checked['bytes'] * 0.9)) {
        $this->deleteBuilt($dest, dirname($dir));
        return NULL;
      }
      if ($checked['extension'] !== 'webp' && $described['bytes'] > ($checked['bytes'] * 2)) {
        $this->deleteBuilt($dest, dirname($dir));
        return NULL;
      }
      $described['path'] = $dest;
      $described['delivery'] = 'ok';
      $described['animated'] = FALSE;
      return $described;
    }
    catch (\Throwable $e) {
      if ($image instanceof \GdImage) {
        imagedestroy($image);
      }
      $this->deleteBuilt($dest, dirname($dir));
      $this->logger->warning('WebP 压缩失败，将保留原图：@message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Copies the source bytes into the task optimized directory without deleting the source.
   *
   * @param array{mime: string, extension: string, width: int, height: int, bytes: int, animated: bool} $checked
   *   Validated source.
   *
   * @return array{path: string, mime: string, extension: string, width: int, height: int, bytes: int, animated: bool, delivery: string}
   *   Copy that can become the official file.
   */
  private function copyOfficial(string $source, string $dir, int $itemId, array $checked, string $delivery): array {
    $dest = $dir . DIRECTORY_SEPARATOR . 'item-' . $itemId . '.' . $checked['extension'];
    if (is_file($dest) && !$this->deleteBuilt($dest, dirname($dir))) {
      throw new ImportException('无法准备正式图片临时文件。');
    }
    if (!copy($source, $dest)) {
      $this->failWrite($dir);
    }
    if (!is_file($source)) {
      throw new ImportException('压缩失败后原始图片丢失。');
    }
    $described = $this->describe($dest);
    if ($checked['animated'] && !$described['animated']) {
      $this->deleteBuilt($dest, dirname($dir));
      throw new ImportException('动画 GIF 不能转成静态图片。');
    }
    if ($described['width'] !== $checked['width'] || $described['height'] !== $checked['height']) {
      $this->deleteBuilt($dest, dirname($dir));
      throw new ImportException('正式图片尺寸和原图不一致。');
    }
    $described['path'] = $dest;
    $described['delivery'] = $delivery;
    $described['animated'] = $checked['animated'];
    return $described;
  }

  /**
   * @return array{mime: string, extension: string, width: int, height: int, bytes: int, animated: bool}
   *   Description of a file that can be decoded by GD.
   */
  private function describe(string $path): array {
    $info = @getimagesize($path);
    $type = (int) ($info[2] ?? 0);
    $map = [
      IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
      IMAGETYPE_PNG => ['image/png', 'png'],
      IMAGETYPE_GIF => ['image/gif', 'gif'],
      IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];
    $bytes = filesize($path);
    if (!$info || !isset($map[$type]) || $bytes === FALSE || $bytes < 1) {
      throw new ImportException('正式图片无法读取。');
    }
    $proof = match ($type) {
      IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
      IMAGETYPE_PNG => @imagecreatefrompng($path),
      IMAGETYPE_GIF => @imagecreatefromgif($path),
      IMAGETYPE_WEBP => @imagecreatefromwebp($path),
      default => FALSE,
    };
    if (!$proof instanceof \GdImage) {
      throw new ImportException('正式图片无法解码。');
    }
    imagedestroy($proof);
    return [
      'mime' => $map[$type][0],
      'extension' => $map[$type][1],
      'width' => (int) $info[0],
      'height' => (int) $info[1],
      'bytes' => (int) $bytes,
      'animated' => $type === IMAGETYPE_GIF && $this->isAnimatedGif($path),
    ];
  }

  /**
   * Decodes an image for recompression and keeps its alpha channel available.
   */
  private function openImage(string $path, int $type): \GdImage {
    $image = match ($type) {
      IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
      IMAGETYPE_PNG => @imagecreatefrompng($path),
      IMAGETYPE_GIF => @imagecreatefromgif($path),
      IMAGETYPE_WEBP => @imagecreatefromwebp($path),
      default => FALSE,
    };
    if (!$image instanceof \GdImage) {
      throw new ImportException('图片无法解码。');
    }
    if (function_exists('imagepalettetotruecolor') && !imageistruecolor($image)) {
      imagepalettetotruecolor($image);
    }
    imagealphablending($image, FALSE);
    imagesavealpha($image, TRUE);
    return $image;
  }

  /**
   * @return array<int, array{0: int, 1: int}>
   *   Up to eight transparent pixels.
   */
  private function alphaPoints(\GdImage $image): array {
    $points = [];
    $width = imagesx($image);
    $height = imagesy($image);
    $step = max(1, (int) floor(max($width, $height) / 48));
    for ($y = 0; $y < $height && count($points) < 8; $y += $step) {
      for ($x = 0; $x < $width && count($points) < 8; $x += $step) {
        $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        if (($color['alpha'] ?? 0) >= 16) {
          $points[] = [$x, $y];
        }
      }
    }
    return $points;
  }

  /**
   * @param array<int, array{0: int, 1: int}> $points
   *   Transparent pixels from the source.
   */
  private function alphaSurvived(\GdImage $image, array $points): bool {
    foreach ($points as $point) {
      if ($point[0] >= imagesx($image) || $point[1] >= imagesy($image)) {
        continue;
      }
      $color = imagecolorsforindex($image, imagecolorat($image, $point[0], $point[1]));
      if (($color['alpha'] ?? 0) >= 16) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * TRUE when a PNG header declares an alpha channel or a tRNS chunk.
   */
  private function pngHasAlphaChannel(string $path): bool {
    $handle = fopen($path, 'rb');
    if (!$handle) {
      return FALSE;
    }
    $signature = fread($handle, 8);
    if ($signature !== "\x89PNG\r\n\x1a\n") {
      fclose($handle);
      return FALSE;
    }
    while (!feof($handle)) {
      $head = fread($handle, 8);
      if (!is_string($head) || strlen($head) < 8) {
        break;
      }
      $length = unpack('N', substr($head, 0, 4));
      $size = (int) ($length[1] ?? 0);
      $type = substr($head, 4, 4);
      if ($type === 'IHDR') {
        $data = fread($handle, $size);
        fread($handle, 4);
        $color = ord(substr((string) $data, 9, 1));
        if ($color === 4 || $color === 6) {
          fclose($handle);
          return TRUE;
        }
        continue;
      }
      if ($type === 'tRNS') {
        fclose($handle);
        return TRUE;
      }
      if ($type === 'IDAT') {
        break;
      }
      fseek($handle, $size + 4, SEEK_CUR);
    }
    fclose($handle);
    return FALSE;
  }

  /**
   * Single place for the import WebP quality.
   */
  private function webpQuality(): int {
    $quality = (int) $this->configFactory->get('xedc_core.settings')->get('webp_quality');
    return ($quality >= 60 && $quality <= 95) ? $quality : 85;
  }

  /**
   * TRUE when another full-size decode is likely to fit.
   */
  private function memoryAllows(int $width, int $height): bool {
    $limit = ini_get('memory_limit');
    if (!is_string($limit) || $limit === '' || $limit === '-1') {
      return TRUE;
    }
    $bytes = $this->parseBytes($limit);
    if ($bytes < 1) {
      return TRUE;
    }
    $need = $width * $height * 5;
    return memory_get_usage(TRUE) + $need < (int) ($bytes * 0.75);
  }

  /**
   * Parses a PHP size string.
   */
  private function parseBytes(string $value): int {
    $value = trim($value);
    $unit = strtolower(substr($value, -1));
    $number = (int) $value;
    return match ($unit) {
      'g' => $number * 1073741824,
      'm' => $number * 1048576,
      'k' => $number * 1024,
      default => (int) $value,
    };
  }

  /**
   * Stops the task when the disk cannot hold another official image.
   */
  private function assertDisk(string $directory, int $need): void {
    $free = @disk_free_space($directory);
    if ($free === FALSE) {
      return;
    }
    if ($free < $need) {
      throw new ImportAbortException('磁盘空间不足，已停止导入。可用空间 ' . (int) $free . ' 字节，至少需要 ' . $need . ' 字节。');
    }
  }

  /**
   * Turns a failed write into a task stop when the disk is full.
   */
  private function failWrite(string $directory): void {
    $free = @disk_free_space($directory);
    if ($free !== FALSE && $free < 8388608) {
      throw new ImportAbortException('磁盘空间不足，已停止导入。');
    }
    throw new ImportException('无法写入正式图片。');
  }

  /**
   * Deletes a file only inside the task's optimized directory.
   */
  private function deleteBuilt(string $path, string $workReal): bool {
    $real = realpath($path);
    $optimized = rtrim($workReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'optimized' . DIRECTORY_SEPARATOR;
    if (!is_string($real) || !is_file($real) || is_link($real) || !str_starts_with($real, $optimized)) {
      return FALSE;
    }
    return unlink($real);
  }

  /**
   * TRUE when a database table is present.
   */
  /**
   * Local path for GD and hashing. Remote URIs are copied to temp first.
   */
  private function readablePath(string $uri): ?string {
    if ($this->files instanceof LocalFileBridge) {
      return $this->files->localPath($uri);
    }
    $real = $this->fileSystem->realpath($uri);
    return is_string($real) && is_file($real) && !is_link($real) ? $real : NULL;
  }

  private function databaseTable(string $table): bool {
    return \Drupal::database()->schema()->tableExists($table);
  }

  /**
   * Configured limit, or the default when unset.
   */
  private function limit(string $key, int $default): int {
    $value = (int) $this->configFactory->get('xedc_core.settings')->get($key);
    return $value > 0 ? $value : $default;
  }

}
