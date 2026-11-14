<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\xedc_comic\Entity\ComicPage;
use Drupal\xedc_core\Image\ImageOptimizeRepository;
use Drupal\xedc_core\Image\ImageOptimizer;
use Drupal\xedc_core\ImagePipeline;
use Drupal\xedc_core\Ops\Storage\DeliveryUrl;
use Drupal\xedc_core\Upload\FileFingerprint;
use Psr\Log\LoggerInterface;

/**
 * Validates, optimizes and stores comic page images via Storage Hub file flow.
 */
final class ComicStorageManager {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entities,
    private readonly FileUsageInterface $fileUsage,
    private readonly ImagePipeline $images,
    private readonly ImageOptimizer $optimizer,
    private readonly ImageOptimizeRepository $optimizeSettings,
    private readonly FileFingerprint $fingerprint,
    private readonly DeliveryUrl $delivery,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return array{page: \Drupal\xedc_comic\Entity\ComicPage, file: \Drupal\file\FileInterface}
   */
  public function createPageFromPath(
    int $comicId,
    int $chapterId,
    int $pageNumber,
    string $sourcePath,
    string $originalName,
  ): array {
    $checked = $this->images->validate($sourcePath);
    $sourceHash = hash_file('sha256', $sourcePath);
    $settings = $this->optimizeSettings->settings();
    $optimize = $this->optimizer->optimize($sourcePath, $settings);
    if (!in_array($optimize->status, ['ok', 'kept', 'optimized'], TRUE)) {
      throw new \RuntimeException($optimize->error !== '' ? $optimize->error : '图片优化失败。');
    }
    $usePath = $optimize->path;
    $mime = $optimize->mime;
    $extension = $optimize->extension;
    if ($usePath === '' || !is_file($usePath)) {
      throw new \RuntimeException('优化后的图片无法读取。');
    }
    $storedHash = hash_file('sha256', $usePath);
    if (!is_string($storedHash)) {
      throw new \RuntimeException('无法计算图片哈希。');
    }
    $directory = $this->pageDirectory($comicId, $chapterId);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $filename = sprintf('p%04d-%s.%s', $pageNumber, substr($storedHash, 0, 8), $extension);
    $uri = $this->fileSystem->copy($usePath, $directory . '/' . $filename, FileExists::Rename);
    $file = File::create([
      'uri' => $uri,
      'filename' => basename($uri),
      'filemime' => $mime,
      'uid' => (int) $this->currentUser->id(),
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    $file->save();
    $this->fileUsage->add($file, 'xedc_comic', 'comic_page', $chapterId);
    $animated = $checked['animated'] ?? FALSE;
    if (!$animated) {
      $this->images->derivatives($file, FALSE);
    }
    $real = $this->fileSystem->realpath($file->getFileUri());
    $bytes = is_string($real) && is_file($real) ? (int) filesize($real) : (int) $checked['bytes'];
    $page = ComicPage::create([
      'chapter_id' => $chapterId,
      'image_file_id' => (int) $file->id(),
      'page_number' => $pageNumber,
      'original_filename' => $originalName,
      'image_width' => (int) $checked['width'],
      'image_height' => (int) $checked['height'],
      'file_size' => $bytes,
    ]);
    $page->save();
    $this->rememberImageMeta((int) $file->id(), $checked, is_string($sourceHash) ? $sourceHash : $storedHash, $storedHash, $bytes);
    return ['page' => $page, 'file' => $file];
  }

  /**
   * Replace image on existing page entity (preserves page id).
   *
   * @return array{page: ComicPage, file: FileInterface}
   */
  public function replacePageImage(ComicPage $page, int $comicId, string $sourcePath, string $originalName): array {
    $checked = $this->images->validate($sourcePath);
    $sourceHash = hash_file('sha256', $sourcePath);
    $settings = $this->optimizeSettings->settings();
    $optimize = $this->optimizer->optimize($sourcePath, $settings);
    if (!in_array($optimize->status, ['ok', 'kept', 'optimized'], TRUE)) {
      throw new \RuntimeException($optimize->error !== '' ? $optimize->error : '图片优化失败。');
    }
    $usePath = $optimize->path;
    $mime = $optimize->mime;
    $extension = $optimize->extension;
    if ($usePath === '' || !is_file($usePath)) {
      throw new \RuntimeException('优化后的图片无法读取。');
    }
    $storedHash = hash_file('sha256', $usePath);
    if (!is_string($storedHash)) {
      throw new \RuntimeException('无法计算图片哈希。');
    }
    $chapterId = $page->getChapterId();
    $pageNumber = $page->getPageNumber();
    $directory = $this->pageDirectory($comicId, $chapterId);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $filename = sprintf('p%04d-%s.%s', $pageNumber, substr($storedHash, 0, 8), $extension);
    $uri = $this->fileSystem->copy($usePath, $directory . '/' . $filename, FileExists::Rename);
    $file = File::create([
      'uri' => $uri,
      'filename' => basename($uri),
      'filemime' => $mime,
      'uid' => (int) $this->currentUser->id(),
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    $file->save();
    $this->fileUsage->add($file, 'xedc_comic', 'comic_page', $chapterId);
    $animated = $checked['animated'] ?? FALSE;
    if (!$animated) {
      $this->images->derivatives($file, FALSE);
    }
    $real = $this->fileSystem->realpath($file->getFileUri());
    $bytes = is_string($real) && is_file($real) ? (int) filesize($real) : (int) $checked['bytes'];
    $page->set('image_file_id', (int) $file->id());
    $page->set('original_filename', $originalName);
    $page->set('image_width', (int) $checked['width']);
    $page->set('image_height', (int) $checked['height']);
    $page->set('file_size', $bytes);
    $page->save();
    $this->rememberImageMeta((int) $file->id(), $checked, is_string($sourceHash) ? $sourceHash : $storedHash, $storedHash, $bytes);
    return ['page' => $page, 'file' => $file];
  }

  public function pageDirectory(int $comicId, int $chapterId): string {
    $prefix = (string) \Drupal::config('xedc_comic.settings')->get('paths.file_prefix') ?: 'comics';
    return 'public://' . $prefix . '/' . $comicId . '/' . $chapterId;
  }

  /**
   * @return array{url: string, width: int, height: int, page: int, id: int}
   */
  public function pagePayload(ComicPage $page): array {
    $file = $page->getFile();
    $url = '';
    if ($file) {
      $delivery = $this->delivery->forUri($file->getFileUri(), (int) $file->id());
      $url = (string) ($delivery['url'] ?? '');
    }
    return [
      'id' => (int) $page->id(),
      'page' => $page->getPageNumber(),
      'url' => $url,
      'width' => (int) $page->get('image_width')->value,
      'height' => (int) $page->get('image_height')->value,
    ];
  }

  public function deletePage(ComicPage $page): void {
    $fid = $page->getFileId();
    if ($fid > 0) {
      $file = File::load($fid);
      if ($file instanceof FileInterface) {
        $this->fileUsage->delete($file, 'xedc_comic');
        $file->delete();
      }
    }
    $page->delete();
  }

  /**
   * @param array<string, mixed> $checked
   */
  private function rememberImageMeta(int $fid, array $checked, string $sourceHash, string $storedHash, int $bytes): void {
    if (!\Drupal::database()->schema()->tableExists('xedc_image_file')) {
      return;
    }
    \Drupal::database()->merge('xedc_image_file')
      ->keys(['fid' => $fid])
      ->fields([
        'animated' => !empty($checked['animated']) ? 1 : 0,
        'webp' => 'ok',
        'bytes' => $bytes,
        'source_sha256' => $sourceHash,
        'stored_sha256' => $storedHash,
        'original_bytes' => (int) ($checked['bytes'] ?? $bytes),
        'original_mime' => (string) ($checked['mime'] ?? ''),
        'original_extension' => (string) ($checked['extension'] ?? ''),
        'optimized_bytes' => $bytes,
        'optimization_status' => 'success',
        'processed' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();
  }

}