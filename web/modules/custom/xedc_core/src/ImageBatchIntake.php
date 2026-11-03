<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\node\NodeInterface;

/**
 * Stores one original image privately and queues the shared import pipeline.
 */
final class ImageBatchIntake {

  public function __construct(
    protected TaskRepository $tasks,
    protected FileSystemInterface $fileSystem,
    protected QueueFactory $queueFactory,
    protected ImportKick $kick,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string, mixed>
   *   Honest upload phase. Optimized and linked stay false until the worker finishes.
   */
  public function addFile(NodeInterface $node, string $path, string $originalName, string $token): array {
    $token = $this->token($token);
    if ($token === '') {
      return $this->fail('缺少上传标识，已拒绝，避免重复创建。', TRUE, FALSE);
    }
    if (!is_file($path) || is_link($path)) {
      return $this->fail('服务器没有读到图片临时文件。', TRUE, FALSE);
    }
    $bytes = filesize($path);
    if ($bytes === FALSE || $bytes < 1) {
      return $this->fail('图片是空文件。', TRUE, FALSE);
    }
    $limit = (int) $this->configFactory->get('xedc_core.settings')->get('max_image_bytes') ?: 52428800;
    if ($bytes > $limit) {
      return $this->fail('单张图片超过大小限制。', TRUE, FALSE);
    }
    $nid = (int) $node->id();
    $task = $this->openTask($node);
    $existing = $this->tasks->itemByRelativePath((int) $task->id, $token);
    if ($existing) {
      return $this->describe($task, $existing, '同一张图片已在任务中，没有重复导入。');
    }
    $checked = $this->inspect($path);
    $work = (string) $task->work_uri;
    if (!$this->workReady($work)) {
      return $this->fail('图片工作目录没有准备好，已拒绝写入。', TRUE, FALSE);
    }
    if ($checked['error'] !== '') {
      $this->tasks->insertItems((int) $task->id, [[
        'relative' => $token,
        'name' => $originalName,
        'uri' => '',
        'bytes' => $bytes,
        'original_bytes' => $bytes,
        'status' => 'failed',
      ]], $this->tasks->itemCount((int) $task->id) + 1);
      $item = $this->tasks->itemByRelativePath((int) $task->id, $token);
      if ($item) {
        $this->tasks->markItem((int) $item->id, ['error' => $checked['error'], 'mime' => '', 'extension' => '']);
      }
      $this->tasks->update((int) $task->id, [
        'total' => $this->tasks->itemCount((int) $task->id),
        'message' => '有图片未通过检查',
      ]);
      $this->tasks->syncCounts((int) $task->id);
      $failed = $item ?: $this->tasks->itemByRelativePath((int) $task->id, $token);
      return $failed ? $this->describe($this->tasks->load((int) $task->id), $failed, $checked['error']) : $this->fail($checked['error'], TRUE, FALSE);
    }
    $target = $work . '/' . $token . '-' . $this->safe($originalName, (string) $checked['extension']);
    try {
      $uri = $this->fileSystem->copy($path, $target, FileExists::Rename);
    }
    catch (\Throwable) {
      return $this->fail('图片无法写入工作目录。', TRUE, FALSE);
    }
    $stored = $this->fileSystem->realpath($uri);
    if (!$stored || !is_file($stored) || is_link($stored) || (int) filesize($stored) !== $bytes) {
      if (is_string($uri) && is_file($uri)) {
        $this->fileSystem->delete($uri);
      }
      return $this->fail('图片已接收，但保存到私有目录后大小不一致。', TRUE, FALSE);
    }
    $this->tasks->insertItems((int) $task->id, [[
      'relative' => $token,
      'name' => $originalName,
      'uri' => $uri,
      'bytes' => $bytes,
      'original_bytes' => $bytes,
      'status' => 'pending',
    ]], $this->tasks->itemCount((int) $task->id) + 1);
    $item = $this->tasks->itemByRelativePath((int) $task->id, $token);
    if ($item && $checked['mime'] !== '') {
      $this->tasks->markItem((int) $item->id, [
        'mime' => $checked['mime'],
        'extension' => $checked['extension'],
        'width' => $checked['width'],
        'height' => $checked['height'],
      ]);
    }
    $this->tasks->update((int) $task->id, [
      'total' => $this->tasks->itemCount((int) $task->id),
      'message' => '图片已保存，等待优化',
    ]);
    $this->queueFactory->get('xedc_image_import')->createItem(['task_id' => (int) $task->id]);
    $this->kick->schedule((int) $task->id);
    $item = $item ?: $this->tasks->itemByRelativePath((int) $task->id, $token);
    return $item ? $this->describe($this->tasks->load((int) $task->id), $item, '图片已保存到私有目录，优化在后台继续。') : $this->fail('图片已保存，但任务记录没有写入。', TRUE, TRUE);
  }

  /**
   * Stops accepting files and lets the worker attach finished images.
   */
  public function close(int $taskId): object {
    $task = $this->tasks->load($taskId);
    if (!$task) {
      throw new ImportException('任务不存在。');
    }
    if ((string) $task->stage !== 'open') {
      return $task;
    }
    $this->tasks->update($taskId, [
      'status' => ImportStatus::QUEUED,
      'stage' => ImportStatus::QUEUED,
      'message' => '图片已收齐，等待处理完成',
    ]);
    $this->queueFactory->get('xedc_image_import')->createItem(['task_id' => $taskId]);
    $this->kick->schedule($taskId);
    return $this->tasks->load($taskId) ?: $task;
  }

  private function openTask(NodeInterface $node): object {
    $existing = $this->tasks->openImageTask((int) $node->id());
    if ($existing) {
      return $existing;
    }
    if ($this->tasks->hasActive((int) $node->id())) {
      throw new ImportException('该内容已有正在处理的导入任务。');
    }
    $id = $this->tasks->create([
      'nid' => (int) $node->id(),
      'uid' => (int) $node->getOwnerId() ?: (int) \Drupal::currentUser()->id(),
      'mode' => 'append',
      'status' => ImportStatus::UPLOADING,
      'stage' => 'open',
      'source' => 'images',
      'message' => '正在接收图片',
      'snapshot' => json_encode([
        'images' => array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id')),
        'cover' => (int) $node->get('field_cover_image')->target_id,
      ], JSON_UNESCAPED_UNICODE),
    ]);
    $work = 'private://xedc-import/tasks/' . $id . '/incoming';
    $this->fileSystem->prepareDirectory($work, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $this->tasks->update($id, ['work_uri' => $work]);
    $task = $this->tasks->load($id);
    if (!$task) {
      throw new ImportException('图片任务没有创建成功。');
    }
    return $task;
  }

  /**
   * @return array{error: string, mime: string, extension: string, width: int, height: int}
   */
  private function inspect(string $path): array {
    $empty = ['error' => '', 'mime' => '', 'extension' => '', 'width' => 0, 'height' => 0];
    $info = @getimagesize($path);
    $type = (int) ($info[2] ?? 0);
    $map = [
      IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
      IMAGETYPE_PNG => ['image/png', 'png'],
      IMAGETYPE_GIF => ['image/gif', 'gif'],
      IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];
    if (!$info || !isset($map[$type])) {
      $empty['error'] = '不是受支持的图片，或文件已损坏。';
      return $empty;
    }
    $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($mime !== $map[$type][0]) {
      $empty['error'] = '文件内容与图片格式不一致。';
      return $empty;
    }
    $empty['mime'] = $mime;
    $empty['extension'] = $map[$type][1];
    $empty['width'] = (int) $info[0];
    $empty['height'] = (int) $info[1];
    return $empty;
  }

  /**
   * @return array<string, mixed>
   */
  private function describe(object $task, object $item, string $message): array {
    $status = (string) $item->status;
    $stored = $status !== 'failed' && (string) $item->temp_uri !== '';
    return [
      'ok' => $status !== 'failed',
      'received' => TRUE,
      'stored' => $stored,
      'queued' => $status === 'pending',
      'optimized' => $status === 'success',
      'linked' => (int) $item->mid > 0,
      'completed' => $status === 'success',
      'phase' => $status === 'success' ? 'completed' : ($status === 'failed' ? 'failed' : 'queued'),
      'task_id' => (int) $task->id,
      'item_id' => (int) $item->id,
      'nid' => (int) $task->nid,
      'filename' => (string) $item->original_name,
      'bytes' => (int) ($item->original_bytes ?? $item->bytes),
      'error' => $status === 'failed' ? ((string) $item->error ?: $message) : '',
      'message' => $message,
    ];
  }

  /**
   * Refuses to copy when the task has no private working directory.
   *
   * An empty destination becomes an uncaught DirectoryNotReadyException.
   */
  private function workReady(string $work): bool {
    if ($work === '' || !str_contains($work, '://')) {
      return FALSE;
    }
    try {
      return $this->fileSystem->prepareDirectory($work, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function fail(string $message, bool $received, bool $stored): array {
    return [
      'ok' => FALSE,
      'received' => $received,
      'stored' => $stored,
      'queued' => FALSE,
      'optimized' => FALSE,
      'linked' => FALSE,
      'completed' => FALSE,
      'phase' => 'failed',
      'task_id' => 0,
      'item_id' => 0,
      'filename' => '',
      'bytes' => 0,
      'error' => $message,
      'message' => $message,
    ];
  }

  private function token(string $token): string {
    $token = strtolower(trim($token));
    return preg_match('/^[a-f0-9-]{16,64}$/', $token) ? $token : '';
  }

  private function safe(string $name, string $extension): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name) ?: 'image';
    $name = mb_substr($name, 0, 80);
    if (!str_ends_with(strtolower($name), '.' . $extension)) {
      $name .= '.' . $extension;
    }
    return $name;
  }

}
