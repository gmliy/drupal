<?php

declare(strict_types=1);

namespace Drupal\xedc_quick;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\xedc_core\Upload\FileFingerprint;

/**
 * Stores one quick-publish image and reuses a healthy existing file.
 */
final class QuickIntake {

  public function __construct(
    protected QuickStore $store,
  ) {}

  /**
   * @return array<string, mixed>
   *   JSON body plus http.
   */
  public function storePath(string $path, string $filename, string $title, int $index, int $originalBytes): array {
    if (!is_file($path) || is_link($path)) {
      return $this->fail('没有收到有效图片。', 400);
    }
    $originalBytes = $originalBytes > 0 ? $originalBytes : (int) filesize($path);
    if ($originalBytes > 25 * 1024 * 1024) {
      return $this->fail('文件过大', 400);
    }
    $info = @getimagesize($path);
    $type = (int) ($info[2] ?? 0);
    $extension = match ($type) {
      IMAGETYPE_JPEG => 'jpg',
      IMAGETYPE_PNG => 'png',
      IMAGETYPE_GIF => 'gif',
      IMAGETYPE_WEBP => 'webp',
      default => (defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF) ? 'avif' : '',
    };
    if ($extension === '') {
      return $this->fail('不是受支持的图片，文件没有公开。', 400);
    }
    $this->store->ensureTables();
    $hash = hash_file('sha256', $path);
    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
      return $this->fail('无法计算图片 SHA-256。', 422);
    }
    $title = trim($title) !== '' ? mb_substr(trim($title), 0, 200) : 'gallery';
    $index = max(1, $index);
    $known = $this->reuse($hash, $title, $index, $originalBytes);
    if ($known) {
      return $known;
    }
    $lockName = 'xedc_quick:' . $hash;
    $lock = \Drupal::lock();
    if (!$lock->acquire($lockName, 30)) {
      $lock->wait($lockName, 5);
      $known = $this->reuse($hash, $title, $index, $originalBytes);
      if ($known) {
        return $known;
      }
      if (!$lock->acquire($lockName, 30)) {
        return $this->fail('请稍后重试', 429);
      }
    }
    $optimizedPath = NULL;
    $ownedClaim = FALSE;
    $storedHash = '';
    try {
      $known = $this->reuse($hash, $title, $index, $originalBytes);
      if ($known) {
        return $known;
      }
      if (!\Drupal::hasService('xedc_core.image_optimizer') || !\Drupal::hasService('xedc_core.image_optimize_repository')) {
        return $this->fail('图片优化服务不可用，文件没有公开', 500);
      }
      $settings = \Drupal::service('xedc_core.image_optimize_repository')->settings();
      $decision = \Drupal::service('xedc_core.image_optimizer')->optimize($path, $settings);
      if ($decision->status === 'rejected') {
        return $this->fail($decision->error !== '' ? $decision->error : '图片未通过优化', 400);
      }
      $sourcePath = $path;
      $optimizedMime = '';
      if (!$decision->keptOriginal && is_file($decision->path)) {
        $sourcePath = $decision->path;
        $optimizedPath = $decision->path;
        $extension = $decision->extension !== '' ? $decision->extension : $extension;
        $optimizedMime = $decision->mime;
      }
      $storedHash = hash_file('sha256', $sourcePath);
      if (!is_string($storedHash)) {
        return $this->fail('无法计算正式图片 SHA-256。', 422);
      }
      $fingerprint = $this->fingerprint();
      if ($fingerprint) {
        $claim = $fingerprint->claimStored($storedHash, (int) filesize($sourcePath));
        if (!empty($claim['busy'])) {
          return $this->fail('请稍后重试', 429);
        }
        if (!empty($claim['reuse']['file']) && $claim['reuse']['file'] instanceof FileInterface) {
          return $this->mediaForFile($claim['reuse']['file'], $title, $index, $originalBytes, $hash, TRUE);
        }
        $ownedClaim = !empty($claim['claimed']);
      }
      if (\Drupal::state()->get('xedc_quick_fail_public_copy') === TRUE) {
        throw new \RuntimeException('公开存储写入失败（测试模拟），文件没有公开。');
      }
      $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', \Drupal::service('transliteration')->transliterate($title, 'en')), '-'));
      $slug = substr($slug !== '' ? $slug : 'gallery', 0, 60);
      $directory = 'public://gallery/' . date('Y-m');
      $filesystem = \Drupal::service('file_system');
      $filesystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $destination = sprintf('%s/%s-%03d.%s', $directory, $slug, $index, $extension);
      $uri = $filesystem->copy($sourcePath, $destination, FileExists::Rename);
      $file = File::create([
        'uri' => $uri,
        'uid' => \Drupal::currentUser()->id(),
        'status' => FileInterface::STATUS_PERMANENT,
        'filename' => basename((string) $uri),
      ]);
      if ($optimizedMime !== '') {
        $file->setMimeType($optimizedMime);
      }
      $file->save();
      if ($ownedClaim && $fingerprint) {
        $fingerprint->commitStored($storedHash, (int) $file->id(), (string) $file->getFileUri(), (int) $file->getSize());
        $ownedClaim = FALSE;
      }
      $this->rememberImage($file, $hash, $storedHash, $originalBytes);
      $saved = $this->mediaForFile($file, $title, $index, $originalBytes, $hash, FALSE);
      return $saved;
    }
    catch (\Throwable $e) {
      if ($ownedClaim && $storedHash !== '' && ($fingerprint = $this->fingerprint())) {
        $fingerprint->abandonStored($storedHash);
      }
      \Drupal::logger('xedc_quick')->error('Gallery upload failed: @message', ['@message' => $e->getMessage()]);
      $safe = str_contains($e->getMessage(), '公开存储') ? $e->getMessage() : '上传失败';
      return $this->fail($safe, 500);
    }
    finally {
      if (is_string($optimizedPath) && is_file($optimizedPath)) {
        $optimizedDir = dirname($optimizedPath);
        @unlink($optimizedPath);
        @rmdir($optimizedDir);
      }
      $lock->release($lockName);
    }
  }

  /**
   * @return array<string, mixed>
   *   Reuse result, or a miss that tells the browser to upload.
   */
  public function match(string $hash, int $bytes, string $title, int $index): array {
    $hash = strtolower(trim($hash));
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
      return ['ok' => TRUE, 'reusable' => FALSE, 'upload' => TRUE, 'http' => 200];
    }
    $known = $this->reuse($hash, $title !== '' ? $title : 'gallery', max(1, $index), $bytes);
    if (!$known) {
      return ['ok' => TRUE, 'reusable' => FALSE, 'upload' => TRUE, 'http' => 200];
    }
    $known['reusable'] = TRUE;
    $known['upload'] = FALSE;
    $known['message'] = '文件已存在，已复用。';
    return $known;
  }

  /**
   * @return array<string, mixed>|null
   *   A new media pointing at the existing file.
   */
  private function reuse(string $hash, string $title, int $index, int $originalBytes): ?array {
    $fingerprint = $this->fingerprint();
    if (!$fingerprint) {
      return NULL;
    }
    $found = $fingerprint->findImage($hash, $originalBytes);
    if (empty($found['reusable']) || !$found['file'] instanceof FileInterface) {
      return NULL;
    }
    return $this->mediaForFile($found['file'], $title, $index, $originalBytes, $hash, TRUE);
  }

  /**
   * @return array<string, mixed>
   *   Upload JSON.
   */
  private function mediaForFile(FileInterface $file, string $title, int $index, int $originalBytes, string $hash, bool $duplicate): array {
    [, $mediaType, $source] = $this->info();
    // Model terms are attached when the gallery node is created; refresh() then
    // rewrites automatic ALT to model + title. At upload time only the title is known.
    $alt = \Drupal::hasService('xedc_core.alt')
      ? \Drupal::service('xedc_core.alt')->compose('', $title)
      : trim($title);
    $media = Media::create([
      'bundle' => $mediaType,
      'name' => $title . ' ' . $index,
      'uid' => \Drupal::currentUser()->id(),
      'status' => 1,
      $source => [
        'target_id' => $file->id(),
        'alt' => $alt,
        'width' => 0,
        'height' => 0,
      ],
    ]);
    $media->save();
    $this->store->rememberUpload($hash, (int) $media->id(), $originalBytes);
    if (\Drupal::hasService('xedc_core.alt')) {
      \Drupal::service('xedc_core.alt')->remember((int) $media->id(), $alt, 'auto');
    }
    return [
      'ok' => TRUE,
      'http' => 200,
      'mid' => (int) $media->id(),
      'fid' => (int) $file->id(),
      'dup' => $duplicate,
      'original_bytes' => $originalBytes,
      'optimized_bytes' => (int) $file->getSize(),
      'mime' => (string) $file->getMimeType(),
      'public' => str_starts_with((string) $file->getFileUri(), 'public://') && !str_contains((string) $file->getFileUri(), 'public/public/'),
      'message' => $duplicate ? '文件已存在，已复用。' : '图片已保存。',
    ];
  }

  private function rememberImage(FileInterface $file, string $sourceHash, string $storedHash, int $originalBytes): void {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('xedc_image_file')) {
      return;
    }
    $database->merge('xedc_image_file')->keys(['fid' => (int) $file->id()])->fields([
      'bytes' => (int) $file->getSize(),
      'source_sha256' => $sourceHash,
      'stored_sha256' => $storedHash,
      'original_bytes' => max(0, $originalBytes),
      'optimized_bytes' => (int) $file->getSize(),
      'optimized_mime' => mb_substr((string) $file->getMimeType(), 0, 64),
      'optimization_status' => 'success',
      'processed' => \Drupal::time()->getRequestTime() ?: time(),
    ])->execute();
  }

  private function fingerprint(): ?FileFingerprint {
    if (!\Drupal::hasService('xedc_core.file_fingerprint')) {
      return NULL;
    }
    $service = \Drupal::service('xedc_core.file_fingerprint');
    return $service instanceof FileFingerprint ? $service : NULL;
  }

  /**
   * @return array{0: string, 1: string, 2: string}
   *   Node bundle, media bundle and source field.
   */
  private function info(): array {
    $manager = \Drupal::service('entity_field.manager');
    $bundle = array_key_first($manager->getFieldMap()['node']['field_images']['bundles'] ?? []) ?: 'photo';
    $definitions = $manager->getFieldDefinitions('node', $bundle);
    $definition = $definitions['field_images'] ?? NULL;
    $mediaType = $definition ? array_key_first($definition->getSetting('handler_settings')['target_bundles'] ?? []) : 'image';
    $mediaType = $mediaType ?: 'image';
    $type = \Drupal::entityTypeManager()->getStorage('media_type')->load($mediaType);
    $source = $type ? ($type->getSource()->getConfiguration()['source_field'] ?? 'field_media_image') : 'field_media_image';
    return [$bundle, $mediaType, $source];
  }

  /**
   * @return array<string, mixed>
   *   Error body.
   */
  private function fail(string $message, int $http): array {
    return [
      'ok' => FALSE,
      'http' => $http,
      'error' => $message,
      'message' => $message,
    ];
  }

}
