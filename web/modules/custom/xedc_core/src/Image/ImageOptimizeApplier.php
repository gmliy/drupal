<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Image;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores one optimized public image through the current stream wrapper.
 *
 * The previous object is removed only after the replacement hash matches and
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

  /**
   * Optimizes a new or newly public image. Already settled files are skipped.
   */
  public function accept(FileInterface $file): void {
    if ($this->inside || !$this->consider($file, FALSE)) {
      return;
    }
    $latest = $this->repository->latestForFid((int) $file->id());
    if (is_array($latest) && in_array((string) $latest['status'], ['queued', 'running', 'cancelled'], TRUE)) {
      return;
    }
    if (is_array($latest) && (string) $latest['status'] === 'failed') {
      return;
    }
    if ($this->alreadyStored($file, $latest)) {
      return;
    }
    $size = (int) $file->getSize();
    if ($size > (int) $this->repository->settings()['sync_bytes']) {
      $this->queueFile($file);
      return;
    }
    $this->process((int) $file->id());
    // hook_entity_insert clears the storage cache before it runs, so the
    // upload caller still holds the pre-optimization URI. Copy the stored
    // file back onto that object before it builds a public URL.
    $this->entities->getStorage('file')->resetCache([(int) $file->id()]);
    $fresh = $this->entities->getStorage('file')->load((int) $file->id());
    if ($fresh instanceof FileInterface) {
      $file->setFileUri($fresh->getFileUri());
      $file->setFilename($fresh->getFilename());
      $file->setMimeType($fresh->getMimeType());
      $file->setSize((int) $fresh->getSize());
    }
  }

  /**
   * Optimizes a temporary image before a move into public://.
   *
   * Large files are left for accept() after the public URI exists. An unsafe
   * SVG is refused so it is not copied into the public bucket.
   */
  public function beforePublicTransfer(FileInterface $file): void {
    if ($this->inside || !$this->consider($file, TRUE)) {
      return;
    }
    $latest = $this->repository->latestForFid((int) $file->id());
    if (is_array($latest) && (string) $latest['status'] === 'success') {
      return;
    }
    if ((int) $file->getSize() > (int) $this->repository->settings()['sync_bytes']) {
      return;
    }
    $status = $this->process((int) $file->id(), 0, TRUE);
    $this->entities->getStorage('file')->resetCache([(int) $file->id()]);
    $fresh = $this->entities->getStorage('file')->load((int) $file->id());
    if ($fresh instanceof FileInterface) {
      $file->setFileUri($fresh->getFileUri());
      $file->setFilename($fresh->getFilename());
      $file->setMimeType($fresh->getMimeType());
      $file->setSize((int) $fresh->getSize());
    }
    if ($status === 'failed') {
      $row = $this->repository->latestForFid((int) $file->id());
      $error = (string) ($row['error'] ?? '');
      if (str_contains($error, 'SVG')) {
        throw new FileException($error !== '' ? $error : 'SVG 未通过安全校验。');
      }
    }
  }

  /**
   * Optimizes one file. Lock contention asks the queue to try again.
   */
  public function process(int $fid, int $rowId = 0, bool $allowTemporary = FALSE): string {
    $file = $this->entities->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface || !$this->consider($file, $allowTemporary)) {
      if ($rowId > 0) {
        $this->repository->update($rowId, [
          'status' => 'failed',
          'r2_status' => 'failed',
          'error' => '文件不存在，或不再是需要优化的公开图片。',
        ]);
      }
      return 'failed';
    }
    $name = 'xedc_image_optimize:' . $fid;
    $timeout = max(30, (int) $this->repository->settings()['timeout'] + 15);
    $slot = 'xedc_image_optimize:slot';
    $holdSlot = (int) ($this->repository->settings()['concurrency'] ?? 1) <= 1;
    if ($holdSlot && !$this->lock->acquire($slot, 5)) {
      if ($rowId > 0) {
        throw new RequeueException('已有图片正在优化。');
      }
      $this->queueFile($file);
      return 'queued';
    }
    if (!$this->lock->acquire($name, $timeout)) {
      if ($holdSlot) {
        $this->lock->release($slot);
      }
      if ($rowId > 0) {
        throw new RequeueException('图片优化正在进行。');
      }
      $this->queueFile($file);
      return 'queued';
    }
    $this->inside = TRUE;
    $copy = NULL;
    $owned = FALSE;
    $produced = NULL;
    try {
      if ($rowId > 0) {
        $existing = $this->repository->load($rowId);
        $attempts = (int) ($existing['attempts'] ?? 0);
        $this->repository->update($rowId, [
          'status' => 'running',
          'attempts' => $attempts,
          'filename' => $file->getFilename(),
        ]);
      }
      $loaded = $this->localCopy($file->getFileUri());
      $copy = $loaded['path'];
      $owned = $loaded['owned'];
      if ($copy === NULL || !$this->isImage($copy)) {
        $reason = $copy === NULL ? '文件是空的或无法读取。' : '文件不是可处理的图片。';
        $fields = ['status' => 'failed', 'r2_status' => 'failed', 'error' => $reason, 'filename' => $file->getFilename()];
        if ($rowId > 0) {
          $this->repository->update($rowId, $fields);
        }
        else {
          $this->repository->record(array_merge($this->blank($fid, $file->getFilename(), 'failed', '', (int) $file->getSize()), $fields));
        }
        return 'failed';
      }
      $current = hash_file('sha256', $copy);
      if (is_string($current) && $this->repository->settled($fid, $current)) {
        $this->confirmPublic($file, $current);
        return 'success';
      }
      $claimed = is_string($current) ? $this->optimizer->claimFor($current) : NULL;
      if ($claimed instanceof ImageOptimizeResult && $claimed->sha256 !== '' && hash_equals($claimed->sha256, (string) $current)) {
        $this->storeResult($fid, $file->getFilename(), $claimed, $this->r2Label($file), $rowId);
        $this->confirmPublic($file, $claimed->sha256);
        return 'success';
      }
      $result = $claimed instanceof ImageOptimizeResult && !$claimed->keptOriginal && is_file($claimed->path)
        ? $claimed
        : $this->optimizer->optimize($copy, $this->repository->settings());
      if ($result->status === 'rejected') {
        $this->storeResult($fid, $file->getFilename(), $result, 'failed', $rowId);
        return 'failed';
      }
      if ($result->keptOriginal) {
        $this->storeResult($fid, $file->getFilename(), $result, $this->r2Label($file), $rowId);
        $this->confirmPublic($file, $result->sha256);
        return 'success';
      }
      $this->replace($file, $result, $copy);
      $produced = $result->path;
      $file = $this->entities->getStorage('file')->load($fid);
      if (!$file instanceof FileInterface) {
        throw new \RuntimeException('优化后的文件记录没有保存下来。');
      }
      $this->storeResult($fid, $file->getFilename(), $result, $this->r2Label($file), $rowId);
      return 'success';
    }
    catch (RequeueException $error) {
      throw $error;
    }
    catch (\Throwable $error) {
      $this->logger->error('图片优化失败 fid @fid：@message', ['@fid' => $fid, '@message' => $error->getMessage()]);
      $attempts = 0;
      if ($rowId > 0) {
        $row = $this->repository->load($rowId);
        $attempts = (int) ($row['attempts'] ?? 0);
        $this->repository->update($rowId, [
          'status' => 'failed',
          'r2_status' => 'failed',
          'error' => substr($error->getMessage(), 0, 500),
          'attempts' => $attempts,
          'filename' => $file->getFilename(),
        ]);
      }
      else {
        $this->repository->record($this->blank($fid, $file->getFilename(), 'failed', '', (int) $file->getSize()) + [
          'error' => substr($error->getMessage(), 0, 500),
          'r2_status' => 'failed',
        ]);
      }
      if ($rowId > 0 && $attempts < (int) $this->repository->settings()['retries']) {
        $this->repository->update($rowId, [
          'status' => 'queued',
          'attempts' => $attempts + 1,
          'error' => substr($error->getMessage(), 0, 500),
        ]);
        $this->queueFactory->get('xedc_image_optimize')->createItem(['id' => $rowId, 'fid' => $fid]);
      }
      return 'failed';
    }
    finally {
      $this->inside = FALSE;
      $this->lock->release($name);
      if ($holdSlot) {
        $this->lock->release($slot);
      }
      if ($owned && is_string($copy) && is_file($copy)) {
        @unlink($copy);
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

  /**
   * Writes optimized width and height onto image fields before entity save.
   */
  public function syncImageDimensions(ContentEntityInterface $entity): void {
    if (!in_array($entity->getEntityTypeId(), ['node', 'media', 'comment'], TRUE)) {
      return;
    }
    $items = [];
    foreach ($entity->getFields() as $field) {
      if ($field->getFieldDefinition()->getType() !== 'image') {
        continue;
      }
      foreach ($field as $item) {
        $fid = (int) ($item->target_id ?? 0);
        if ($fid > 0) {
          $items[$fid][] = $item;
        }
      }
    }
    if ($items === []) {
      return;
    }
    foreach ($this->repository->successDimensions(array_keys($items)) as $fid => $size) {
      foreach ($items[$fid] ?? [] as $item) {
        if ($size['width'] > 0 && $size['height'] > 0) {
          $item->set('width', $size['width']);
          $item->set('height', $size['height']);
        }
      }
    }
  }

  private function queueFile(FileInterface $file): void {
    $sha = '';
    $size = (int) $file->getSize();
    $id = $this->repository->record($this->blank((int) $file->id(), $file->getFilename(), 'queued', $sha, $size));
    $this->queueFactory->get('xedc_image_optimize')->createItem(['id' => $id, 'fid' => (int) $file->id()]);
  }

  /**
   * @param array<string, mixed>|null $latest
   */
  private function alreadyStored(FileInterface $file, ?array $latest): bool {
    if (!is_array($latest) || (string) $latest['status'] !== 'success') {
      return FALSE;
    }
    if ((int) $file->getSize() !== (int) $latest['stored_bytes']) {
      return FALSE;
    }
    if ((string) $file->getMimeType() !== (string) $latest['stored_mime']) {
      return FALSE;
    }
    if (!str_starts_with($file->getFileUri(), 'public://')) {
      return TRUE;
    }
    if ((string) ($latest['r2_status'] ?? '') === 'stored') {
      return TRUE;
    }
    $hash = $this->hashUri($file->getFileUri());
    if (is_string($hash) && hash_equals((string) $latest['stored_sha256'], $hash)) {
      $this->repository->update((int) $latest['id'], ['r2_status' => 'stored']);
      return TRUE;
    }
    return FALSE;
  }

  private function confirmPublic(FileInterface $file, string $sha): void {
    if (!str_starts_with($file->getFileUri(), 'public://') || $sha === '') {
      return;
    }
    $hash = $this->hashUri($file->getFileUri());
    if (!is_string($hash) || !hash_equals($sha, $hash)) {
      throw new \RuntimeException('公开文件与优化结果不一致。');
    }
    $latest = $this->repository->latestForFid((int) $file->id());
    if (is_array($latest) && (string) $latest['status'] === 'success' && (string) $latest['r2_status'] !== 'stored') {
      $this->repository->update((int) $latest['id'], ['r2_status' => 'stored']);
    }
  }

  private function r2Label(FileInterface $file): string {
    return str_starts_with($file->getFileUri(), 'public://') ? 'stored' : 'local';
  }

  private function replace(FileInterface $file, ImageOptimizeResult $result, string $originalCopy): void {
    $old = $file->getFileUri();
    $scheme = StreamWrapperManager::getScheme($old);
    $target = StreamWrapperManager::getTarget($old);
    $target = str_replace('\\', '/', (string) $target);
    $directory = str_replace('\\', '/', dirname($target));
    $directory = $directory === '.' ? '' : trim($directory, '/');
    $base = pathinfo((string) $file->getFilename(), PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $base) ?: 'image';
    $final = $scheme . '://' . ($directory === '' ? '' : $directory . '/') . $base . '.' . $result->extension;
    $parent = $scheme . '://' . $directory;
    $staging = $final . '.xedc-staging';
    $overwroteOld = FALSE;
    $created = '';
    $this->fileSystem->prepareDirectory($parent, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    try {
      $written = $this->fileSystem->copy($result->path, $staging, FileExists::Replace);
      $stored = $this->hashUri($written);
      if ($stored === NULL || !hash_equals($result->sha256, $stored)) {
        $this->deleteUri($written);
        throw new \RuntimeException('优化文件上传后校验不一致。');
      }
      if ($written !== $final) {
        if ($final === $old) {
          $overwroteOld = TRUE;
        }
        $final = $this->fileSystem->move($written, $final, FileExists::Replace);
      }
      else {
        $final = $written;
      }
      if ($final !== $old) {
        $created = $final;
      }
      $again = $this->hashUri($final);
      if ($again === NULL || !hash_equals($result->sha256, $again)) {
        throw new \RuntimeException('优化文件移动后校验不一致。');
      }
      $file->setFileUri($final);
      $file->setFilename($this->fileSystem->basename($final));
      $file->setMimeType($result->mime);
      $file->setSize($result->bytes);
      $file->save();
      if ($old !== $final && $this->uriUnused($old, (int) $file->id())) {
        $this->deleteUri($old);
      }
    }
    catch (\Throwable $error) {
      $this->deleteUri($staging);
      if ($created !== '' && $created !== $old) {
        $this->deleteUri($created);
      }
      if ($overwroteOld && is_file($originalCopy)) {
        $this->fileSystem->copy($originalCopy, $old, FileExists::Replace);
      }
      throw $error;
    }
  }

  private function consider(FileInterface $file, bool $allowTemporary): bool {
    $uri = $file->getFileUri();
    $scheme = StreamWrapperManager::getScheme($uri);
    if ($scheme !== 'public' && !($allowTemporary && $scheme === 'temporary')) {
      return FALSE;
    }
    if ($scheme === 'public') {
      $target = substr($uri, strlen('public://'));
      foreach (['styles/', 'css/', 'js/', 'php/'] as $prefix) {
        if ($target === rtrim($prefix, '/') || str_starts_with($target, $prefix) || str_contains($target, '/' . $prefix)) {
          return FALSE;
        }
      }
      if (str_ends_with($target, '.xedc-staging')) {
        return FALSE;
      }
      foreach (['xedc-playback/', 'xedc-samples/', 'xedc-engage-test/'] as $prefix) {
        if (str_starts_with($target, $prefix)) {
          return FALSE;
        }
      }
    }
    return $this->looksLikeImage($file);
  }

  private function looksLikeImage(FileInterface $file): bool {
    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    $blocked = ['mp4', 'webm', 'mkv', 'mov', 'm4v', 'avi', 'mp3', 'pdf', 'zip', '7z', 'gz', 'rar', 'txt', 'doc', 'docx', 'css', 'js'];
    if (in_array($extension, $blocked, TRUE)) {
      return FALSE;
    }
    $mime = strtolower((string) $file->getMimeType());
    if ($mime !== '' && !str_starts_with($mime, 'image/') && $mime !== 'application/octet-stream') {
      return FALSE;
    }
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', ''];
    return $extension === '' || in_array($extension, $allowed, TRUE);
  }

  /**
   * @return array{path: ?string, owned: bool}
   */
  private function localCopy(string $uri): array {
    $temp = tempnam(sys_get_temp_dir(), 'xedc-src-');
    if ($temp === FALSE) {
      return ['path' => NULL, 'owned' => FALSE];
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
      return ['path' => NULL, 'owned' => FALSE];
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    if (!is_file($temp) || filesize($temp) < 1) {
      @unlink($temp);
      return ['path' => NULL, 'owned' => FALSE];
    }
    return ['path' => $temp, 'owned' => TRUE];
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
    $head = $handle ? fread($handle, 512) : FALSE;
    if (is_resource($handle)) {
      fclose($handle);
    }
    return is_string($head) && (str_contains($head, '<svg') || str_contains(ltrim($head), '<svg'));
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

  private function deleteUri(string $uri): void {
    if ($uri === '') {
      return;
    }
    try {
      if (file_exists($uri)) {
        $this->fileSystem->delete($uri);
      }
    }
    catch (\Throwable $error) {
      $this->logger->error('临时优化文件未能删除 @uri：@message', [
        '@uri' => $uri,
        '@message' => $error->getMessage(),
      ]);
    }
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
    return array_values(array_filter($files, static fn ($file): bool => $file instanceof FileInterface));
  }

  private function storeResult(int $fid, string $filename, ImageOptimizeResult $result, string $r2, int $rowId): void {
    $status = $result->status === 'rejected' ? 'failed' : 'success';
    $fields = [
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
    ];
    if ($rowId > 0) {
      $this->repository->update($rowId, $fields);
      return;
    }
    $fields['created'] = \Drupal::time()->getRequestTime();
    $this->repository->record($fields);
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
