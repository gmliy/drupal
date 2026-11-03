<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Image;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Replaces a new public image with its optimized bytes on the current wrapper.
 *
 * The original object is removed only after the replacement hash matches and
 * no other file entity still uses the old URI.
 */
final class ImageOptimizeApplier {

  private bool $inside = FALSE;

  public function __construct(
    protected ImageOptimizer $optimizer,
    protected ImageOptimizeRepository $repository,
    protected FileSystemInterface $fileSystem,
    protected EntityTypeManagerInterface $entities,
    protected QueueFactory $queueFactory,
    protected LockBackendInterface $lock,
    protected LoggerInterface $logger,
  ) {}

  public function accept(FileInterface $file): void {
    if ($this->inside || !$this->eligible($file)) {
      return;
    }
    $real = $this->localCopy($file->getFileUri());
    if ($real === NULL || !$this->isImage($real)) {
      if (is_string($real)) {
        $this->discardCopy($file->getFileUri(), $real);
      }
      return;
    }
    $sha = hash_file('sha256', $real);
    if (is_string($sha) && $this->repository->settled((int) $file->id(), $sha)) {
      $this->discardCopy($file->getFileUri(), $real);
      return;
    }
    $claimed = is_string($sha) ? $this->optimizer->claimFor($sha) : NULL;
    if ($claimed instanceof ImageOptimizeResult) {
      $this->storeResult((int) $file->id(), $file->getFilename(), $claimed, 'stored');
      $this->discardCopy($file->getFileUri(), $real);
      return;
    }
    $settings = $this->repository->settings();
    $size = filesize($real);
    if (is_int($size) && $size > (int) $settings['sync_bytes']) {
      $id = $this->repository->record($this->blank((int) $file->id(), $file->getFilename(), 'queued', is_string($sha) ? $sha : '', (int) $size));
      $this->queueFactory->get('xedc_image_optimize')->createItem(['id' => $id, 'fid' => (int) $file->id()]);
      $this->discardCopy($file->getFileUri(), $real);
      return;
    }
    $this->discardCopy($file->getFileUri(), $real);
    $this->process((int) $file->id());
  }

  public function process(int $fid, int $rowId = 0): void {
    $file = $this->entities->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface || !$this->eligible($file)) {
      return;
    }
    $name = 'xedc_image_optimize:' . $fid;
    if (!$this->lock->acquire($name, 30)) {
      return;
    }
    $this->inside = TRUE;
    $copy = NULL;
    $produced = NULL;
    try {
      $copy = $this->localCopy($file->getFileUri());
      if ($copy === NULL || !$this->isImage($copy)) {
        return;
      }
      $current = hash_file('sha256', $copy);
      if (is_string($current) && $this->repository->settled($fid, $current)) {
        return;
      }
      $result = $this->optimizer->optimize($copy, $this->repository->settings());
      if ($result->status === 'rejected') {
        $this->storeResult($fid, $file->getFilename(), $result, 'failed');
        return;
      }
      if ($result->keptOriginal) {
        $this->storeResult($fid, $file->getFilename(), $result, 'stored');
        return;
      }
      $this->replace($file, $result, $copy);
      $produced = $result->path;
      $this->storeResult($fid, $file->getFilename(), $result, 'stored');
    }
    catch (\Throwable $error) {
      $this->logger->error('图片优化失败 fid @fid：@message', ['@fid' => $fid, '@message' => $error->getMessage()]);
      $this->repository->record($this->blank($fid, $file->getFilename(), 'failed', '', (int) $file->getSize()) + [
        'error' => substr($error->getMessage(), 0, 500),
        'r2_status' => 'failed',
      ]);
      if ($rowId > 0) {
        $row = $this->repository->load($rowId);
        $attempts = (int) ($row['attempts'] ?? 0);
        if ($attempts < (int) $this->repository->settings()['retries']) {
          $this->repository->record(['fid' => $fid, 'filename' => $file->getFilename(), 'status' => 'queued', 'attempts' => $attempts + 1, 'error' => substr($error->getMessage(), 0, 500)]);
          $this->queueFactory->get('xedc_image_optimize')->createItem(['id' => $rowId, 'fid' => $fid]);
        }
      }
    }
    finally {
      $this->inside = FALSE;
      $this->lock->release($name);
      if (is_string($copy)) {
        $this->discardCopy($file->getFileUri(), $copy);
      }
      if (is_string($produced) && is_file($produced)) {
        $dir = dirname($produced);
        @unlink($produced);
        @rmdir($dir);
      }
    }
  }

  public function blocksPublish(NodeInterface $node): bool {
    foreach ($this->referencedFiles($node) as $file) {
      $row = $this->repository->latestForFid((int) $file->id());
      if (is_array($row) && in_array($row['status'], ['queued', 'running', 'failed'], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function replace(FileInterface $file, ImageOptimizeResult $result, string $originalCopy): void {
    $old = $file->getFileUri();
    $directory = $this->fileSystem->dirname($old);
    $base = pathinfo($file->getFilename(), PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $base) ?: 'image';
    $final = $directory . '/' . $base . '.' . $result->extension;
    $staging = $final . '.xedc-staging';
    $written = $this->fileSystem->copy($result->path, $staging, FileExists::Replace);
    $stored = $this->hashUri($written);
    if ($stored === NULL || !hash_equals($result->sha256, $stored)) {
      $this->fileSystem->delete($written);
      throw new \RuntimeException('优化文件上传后校验不一致。');
    }
    if ($written !== $final) {
      $finalWritten = $this->fileSystem->move($written, $final, FileExists::Replace);
      $again = $this->hashUri($finalWritten);
      if ($again === NULL || !hash_equals($result->sha256, $again)) {
        if (is_file($originalCopy)) {
          $this->fileSystem->copy($originalCopy, $old, FileExists::Replace);
        }
        throw new \RuntimeException('优化文件移动后校验不一致。');
      }
      $final = $finalWritten;
    }
    else {
      $final = $written;
    }
    $file->setFileUri($final);
    $file->setFilename(basename($final));
    $file->setMimeType($result->mime);
    $file->setSize($result->bytes);
    $file->save();
    if ($old !== $final && $this->uriUnused($old, (int) $file->id())) {
      $this->fileSystem->delete($old);
    }
  }

  private function eligible(FileInterface $file): bool {
    $uri = $file->getFileUri();
    if (!str_starts_with($uri, 'public://')) {
      return FALSE;
    }
    $target = substr($uri, strlen('public://'));
    if (str_starts_with($target, 'styles/') || str_contains($target, '/styles/') || str_ends_with($target, '.xedc-staging')) {
      return FALSE;
    }
    foreach (['xedc-playback/', 'xedc-samples/', 'xedc-engage-test/'] as $prefix) {
      if (str_starts_with($target, $prefix)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function localCopy(string $uri): ?string {
    $temp = tempnam(sys_get_temp_dir(), 'xedc-src-');
    if ($temp === FALSE) {
      return NULL;
    }
    $in = @fopen($uri, 'rb');
    $out = fopen($temp, 'wb');
    if (!$in || !$out) {
      if (is_resource($in)) {
        fclose($in);
      }
      if (is_resource($out)) {
        fclose($out);
      }
      @unlink($temp);
      return NULL;
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    return is_file($temp) && filesize($temp) > 0 ? $temp : NULL;
  }

  private function discardCopy(string $uri, string $copy): void {
    if ($copy !== $uri && is_file($copy) && !str_starts_with($uri, $copy)) {
      @unlink($copy);
    }
  }

  private function hashUri(string $uri): ?string {
    $handle = @fopen($uri, 'rb');
    if (!$handle) {
      return NULL;
    }
    $context = hash_init('sha256');
    while (!feof($handle)) {
      $chunk = fread($handle, 1048576);
      if (!is_string($chunk) || $chunk === '') {
        break;
      }
      hash_update($context, $chunk);
    }
    fclose($handle);
    $hash = hash_final($context);
    return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : NULL;
  }

  private function isImage(string $path): bool {
    if (@getimagesize($path)) {
      return TRUE;
    }
    $handle = fopen($path, 'rb');
    $head = $handle ? fread($handle, 256) : FALSE;
    if (is_resource($handle)) {
      fclose($handle);
    }
    return is_string($head) && str_contains($head, '<svg');
  }

  private function uriUnused(string $uri, int $fid): bool {
    $ids = $this->entities->getStorage('file')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uri', $uri)
      ->condition('fid', $fid, '<>')
      ->range(0, 1)
      ->execute();
    return $ids === [];
  }

  /**
   * @return list<FileInterface>
   */
  private function referencedFiles(NodeInterface $node): array {
    $ids = [];
    foreach ($node->getFields() as $field) {
      $type = $field->getFieldDefinition()->getType();
      if (!in_array($type, ['image', 'file', 'entity_reference'], TRUE)) {
        continue;
      }
      $target = (string) $field->getFieldDefinition()->getSetting('target_type');
      foreach ($field as $item) {
        $id = (int) ($item->target_id ?? 0);
        if ($id < 1) {
          continue;
        }
        if ($type === 'entity_reference' && $target === 'media') {
          $media = $item->entity;
          if ($media instanceof MediaInterface) {
            $source = $media->getSource()->getConfiguration()['source_field'] ?? '';
            if (is_string($source) && $source !== '' && $media->hasField($source)) {
              $ids[] = (int) $media->get($source)->target_id;
            }
          }
        }
        elseif ($type === 'image' || $type === 'file' || $target === 'file') {
          $ids[] = $id;
        }
      }
    }
    $files = $this->entities->getStorage('file')->loadMultiple(array_values(array_unique($ids)));
    return array_values(array_filter($files, static fn($file): bool => $file instanceof FileInterface));
  }

  private function storeResult(int $fid, string $filename, ImageOptimizeResult $result, string $r2): void {
    $status = $result->status === 'rejected' ? 'failed' : 'success';
    $this->repository->record([
      'fid' => $fid,
      'filename' => substr($filename, 0, 255),
      'source_mime' => $result->sourceMime,
      'stored_mime' => $result->mime,
      'source_bytes' => $result->sourceBytes,
      'stored_bytes' => $result->bytes,
      'source_sha256' => $result->sourceSha256,
      'stored_sha256' => $result->sha256,
      'width' => $result->width,
      'height' => $result->height,
      'elapsed_ms' => $result->elapsedMs,
      'memory_bytes' => $result->memoryBytes,
      'status' => $status,
      'r2_status' => $status === 'success' ? $r2 : 'failed',
      'error' => substr($result->error, 0, 500),
      'created' => \Drupal::time()->getRequestTime(),
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  private function blank(int $fid, string $filename, string $status, string $sha, int $bytes): array {
    return [
      'fid' => $fid,
      'filename' => substr($filename, 0, 255),
      'source_mime' => '',
      'stored_mime' => '',
      'source_bytes' => $bytes,
      'stored_bytes' => $bytes,
      'source_sha256' => $sha,
      'stored_sha256' => $sha,
      'width' => 0,
      'height' => 0,
      'elapsed_ms' => 0,
      'memory_bytes' => 0,
      'status' => $status,
      'r2_status' => $status === 'queued' ? 'pending' : '',
      'error' => '',
      'attempts' => 0,
      'created' => \Drupal::time()->getRequestTime(),
    ];
  }

}
