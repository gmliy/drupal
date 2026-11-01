<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\UserSession;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs an import task in small batches that can resume after a crash.
 */
class ImportProcessor {

  public function __construct(
    protected TaskRepository $tasks,
    protected ArchiveManager $archive,
    protected ImagePipeline $images,
    protected AltManager $alt,
    protected TempCleaner $cleaner,
    protected LockBackendInterface $lock,
    protected QueueFactory $queueFactory,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountSwitcherInterface $accountSwitcher,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Creates a task from the photo form and queues it.
   *
   * @param array<string, mixed> $payload
   *   Archive selection captured during form validation.
   */
  public function start(NodeInterface $node, array $payload): int {
    $nid = (int) $node->id();
    if ($this->tasks->hasActive($nid)) {
      throw new ImportException('该内容已有正在处理的导入任务。请等待完成或先取消。');
    }
    $mode = !empty($payload['is_new']) ? 'create' : (string) ($payload['mode'] ?? 'append');
    if (!in_array($mode, ['create', 'append', 'replace'], TRUE)) {
      $mode = 'append';
    }
    $images = array_values(array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id')));
    $id = $this->tasks->create([
      'nid' => $nid,
      'uid' => (int) \Drupal::currentUser()->id(),
      'mode' => $mode,
      'status' => ImportStatus::QUEUED,
      'stage' => ImportStatus::QUEUED,
      'source' => !empty($payload['server']) ? 'server' : 'upload',
      'snapshot' => json_encode([
        'images' => $images,
        'cover' => (int) $node->get('field_cover_image')->target_id,
      ], JSON_UNESCAPED_UNICODE),
      'message' => '等待处理',
    ]);
    try {
      $stored = $this->archive->intake($id, $payload);
      $this->tasks->update($id, [
        'archive_name' => $stored['name'],
        'archive_uri' => $stored['uri'],
        'archive_fid' => $stored['fid'],
        'archive_format' => $stored['format'],
        'source' => $stored['source'],
        'uid' => (int) \Drupal::currentUser()->id(),
      ]);
      $this->queueFactory->get('xedc_image_import')->createItem(['task_id' => $id]);
      $this->tasks->log($id, 'info', '已创建导入任务。');
    }
    catch (\Throwable $e) {
      $this->fail($id, $e->getMessage());
      throw $e;
    }
    return $id;
  }

  /**
   * Processes one slice of a task.
   *
   * @return string
   *   done, more, or busy.
   */
  public function processBatch(int $taskId, ?int $budget = NULL): string {
    if ($taskId < 1) {
      return 'done';
    }
    $lockName = 'xedc_import:' . $taskId;
    if (!$this->lock->acquire($lockName, 300)) {
      return 'busy';
    }
    $deadline = time() + ($budget ?: $this->budget());
    $this->accountSwitcher->switchTo(new UserSession(['uid' => 1]));
    try {
      $task = $this->tasks->load($taskId);
      if (!$task || ImportStatus::isTerminal((string) $task->status)) {
        return 'done';
      }
      if ($this->tasks->itemCount($taskId) === 0) {
        $this->prepare($task);
        $task = $this->tasks->load($taskId);
      }
      elseif ((string) $task->status === ImportStatus::QUEUED) {
        if ($this->halted($taskId)) {
          return 'done';
        }
        $this->tasks->update($taskId, [
          'status' => ImportStatus::IMPORTING,
          'stage' => ImportStatus::IMPORTING,
        ]);
        $task = $this->tasks->load($taskId);
      }
      if (!$task || ImportStatus::isTerminal((string) $task->status)) {
        return 'done';
      }
      $this->importSome($task, $deadline);
      if ($this->halted($taskId)) {
        return 'done';
      }
      $counts = $this->tasks->syncCounts($taskId);
      if ($counts['pending'] > 0) {
        return 'more';
      }
      if ($this->halted($taskId)) {
        return 'done';
      }
      $this->finalize($taskId);
      return 'done';
    }
    catch (ImportException $e) {
      $this->fail($taskId, $e->getMessage());
      return 'done';
    }
    catch (\Throwable $e) {
      $this->logger->error('导入任务 @id 异常：@message', [
        '@id' => $taskId,
        '@message' => $e->getMessage(),
      ]);
      $this->fail($taskId, $e->getMessage());
      return 'done';
    }
    finally {
      $this->accountSwitcher->switchBack();
      $this->lock->release($lockName);
    }
  }

  /**
   * Runs until the task reaches a terminal status.
   */
  public function processUntilDone(int $taskId, int $max = 100): object {
    for ($i = 0; $i < $max; $i++) {
      $result = $this->processBatch($taskId, 60);
      $task = $this->tasks->load($taskId);
      if (!$task) {
        throw new ImportException('任务不存在。');
      }
      if ($result === 'done' || ImportStatus::isTerminal((string) $task->status)) {
        return $task;
      }
    }
    throw new ImportException('任务处理超过批次上限。');
  }

  /**
   * Puts a failed or partial task back on the queue.
   */
  public function retry(int $taskId): void {
    $task = $this->tasks->load($taskId);
    if (!$task) {
      throw new ImportException('任务不存在。');
    }
    if (in_array((string) $task->status, [ImportStatus::PUBLISHED, ImportStatus::CANCELLED], TRUE)) {
      throw new ImportException('已发布或已取消的任务不能重试。');
    }
    if (!in_array((string) $task->status, [ImportStatus::FAILED, ImportStatus::PARTIAL], TRUE)) {
      throw new ImportException('当前状态不能重试。');
    }
    if (!$this->archive->exists((string) $task->archive_uri)) {
      throw new ImportException('源压缩包已清理，无法重试。');
    }
    $reset = \Drupal::database()->update('xedc_import_item')
      ->fields(['status' => 'pending', 'error' => ''])
      ->condition('task_id', $taskId)
      ->condition('status', 'failed')
      ->condition('attempts', 3, '<')
      ->execute();
    $exhausted = (int) \Drupal::database()->select('xedc_import_item', 'i')
      ->condition('task_id', $taskId)
      ->condition('status', 'failed')
      ->condition('attempts', 3, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
    $pending = (int) \Drupal::database()->select('xedc_import_item', 'i')
      ->condition('task_id', $taskId)
      ->condition('status', 'pending')
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($this->tasks->itemCount($taskId) > 0 && (int) $reset < 1 && $pending < 1) {
      throw new ImportException($exhausted > 0 ? '失败图片已达到重试次数上限。' : '没有可以重试的图片。');
    }
    if ($exhausted > 0) {
      $this->tasks->log($taskId, 'warning', '有 ' . $exhausted . ' 张图片已达到重试上限，不再处理。');
    }
    $this->tasks->update($taskId, [
      'status' => ImportStatus::QUEUED,
      'stage' => ImportStatus::QUEUED,
      'error' => '',
      'message' => '已重新排队',
      'cleanup_after' => 0,
    ]);
    $this->tasks->syncCounts($taskId);
    $queue = $this->queueFactory->get('xedc_image_import');
    $queued = (int) \Drupal::database()->select('queue', 'q')
      ->condition('name', 'xedc_image_import')
      ->condition('data', serialize(['task_id' => $taskId]))
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($queued < 1) {
      $queue->createItem(['task_id' => $taskId]);
    }
    $this->tasks->log($taskId, 'info', '任务已重新排队。');
  }

  /**
   * Stops a task. Images already saved on the node stay in place.
   */
  public function cancel(int $taskId): void {
    $task = $this->tasks->load($taskId);
    if (!$task) {
      throw new ImportException('任务不存在。');
    }
    if ((string) $task->status === ImportStatus::PUBLISHED) {
      throw new ImportException('已发布的任务不能取消。');
    }
    $this->tasks->update($taskId, [
      'status' => ImportStatus::CANCELLED,
      'stage' => ImportStatus::CANCELLED,
      'message' => '已取消',
    ]);
    $this->tasks->log($taskId, 'info', '任务已取消。');
    $fresh = $this->tasks->load($taskId);
    if ($fresh) {
      $this->cleaner->schedule($fresh);
    }
  }

  /**
   * Queues tasks whose worker disappeared.
   */
  public function recover(): void {
    foreach ($this->tasks->stale(time() - 180) as $taskId) {
      $this->queueFactory->get('xedc_image_import')->createItem(['task_id' => $taskId]);
      $this->tasks->update($taskId, ['message' => '已恢复中断的任务']);
      $this->tasks->log($taskId, 'warning', '任务长时间没有更新，已重新加入队列。');
    }
  }

  /**
   * Extracts and scans the archive once.
   */
  private function prepare(object $task): void {
    $id = (int) $task->id;
    if ($this->halted($id)) {
      return;
    }
    $this->tasks->update($id, [
      'status' => ImportStatus::VALIDATING,
      'stage' => ImportStatus::VALIDATING,
      'progress' => 5,
      'message' => '正在验证压缩包',
    ]);
    if ($this->halted($id)) {
      return;
    }
    $this->tasks->update($id, [
      'status' => ImportStatus::EXTRACTING,
      'stage' => ImportStatus::EXTRACTING,
      'progress' => 12,
      'message' => '正在解压',
    ]);
    $result = $this->archive->openAndExtract($task);
    if ($this->halted($id)) {
      return;
    }
    $this->tasks->update($id, [
      'status' => ImportStatus::SCANNING,
      'stage' => ImportStatus::SCANNING,
      'progress' => 22,
      'archive_format' => $result['format'],
      'work_uri' => $result['work_uri'],
      'message' => '正在扫描图片',
    ]);
    if (!$result['files']) {
      $message = $result['skipped'] > 0 ? '压缩包里没有可导入的图片。' : '压缩包是空的。';
      throw new ImportException($message);
    }
    if ($this->halted($id)) {
      return;
    }
    $node = $this->node((int) $task->nid);
    $start = 1;
    if ((string) $task->mode === 'append') {
      $start = $node->get('field_images')->count() + 1;
    }
    $this->tasks->insertItems($id, $result['files'], $start);
    $this->tasks->update($id, [
      'status' => ImportStatus::IMPORTING,
      'stage' => ImportStatus::IMPORTING,
      'total' => count($result['files']),
      'skipped_count' => (int) $result['skipped'],
      'progress' => 30,
      'message' => '正在导入图片',
    ]);
    $this->tasks->log($id, 'info', '扫描到 ' . count($result['files']) . ' 张图片，跳过 ' . (int) $result['skipped'] . ' 个非图片文件。');
  }

  /**
   * Imports a small batch without loading every image at once.
   */
  private function importSome(object $task, int $deadline): void {
    $id = (int) $task->id;
    if ($this->halted($id)) {
      return;
    }
    $node = $this->node((int) $task->nid);
    $batch = max(1, (int) $this->configFactory->get('xedc_core.settings')->get('batch_size') ?: 5);
    $this->tasks->update($id, [
      'status' => ImportStatus::CONVERTING,
      'stage' => ImportStatus::CONVERTING,
      'message' => '正在转换并导入图片',
    ]);
    foreach ($this->tasks->pending($id, $batch) as $item) {
      if (time() >= $deadline || $this->halted($id)) {
        break;
      }
      try {
        $this->images->import($task, $item, $node);
      }
      catch (ImportAbortException $e) {
        throw $e;
      }
      catch (ImportException $e) {
        $this->tasks->markItem((int) $item->id, [
          'status' => 'failed',
          'error' => $e->getMessage(),
        ]);
        $this->tasks->log($id, 'error', (string) $item->original_name . '：' . $e->getMessage());
      }
      catch (\Throwable $e) {
        $safe = $this->memberError($e);
        $this->tasks->markItem((int) $item->id, [
          'status' => 'failed',
          'error' => $safe,
        ]);
        $this->logger->error('图片 @name 处理失败：@message', [
          '@name' => $item->original_name,
          '@message' => $e->getMessage(),
          'exception' => $e,
        ]);
        $this->tasks->log($id, 'error', (string) $item->original_name . '：' . $safe);
      }
    }
  }

  /**
   * Attaches successful images and moves the node to review or failure.
   */
  private function finalize(int $taskId): void {
    if ($this->halted($taskId)) {
      return;
    }
    $task = $this->tasks->load($taskId);
    if (!$task) {
      return;
    }
    $this->tasks->update($taskId, [
      'status' => ImportStatus::VERIFYING,
      'stage' => ImportStatus::VERIFYING,
      'message' => '正在校验数据',
    ]);
    $counts = $this->tasks->syncCounts($taskId);
    $task = $this->tasks->load($taskId);
    $node = $this->node((int) $task->nid);
    $live = $node->isPublished();
    if ($counts['success'] < 1) {
      throw new ImportException('没有成功导入的图片。');
    }
    if ($this->halted($taskId)) {
      return;
    }
    $replaceBlocked = (string) $task->mode === 'replace' && $counts['failed'] > 0;
    if ($replaceBlocked) {
      $this->tasks->update($taskId, [
        'status' => ImportStatus::FAILED,
        'stage' => ImportStatus::FAILED,
        'progress' => 100,
        'message' => '替换未执行，原有图片已保留。',
        'error' => '有图片处理失败，已取消替换。',
      ]);
      $this->tasks->log($taskId, 'error', '替换未执行，原有图片已保留。');
      $this->cleaner->schedule($this->tasks->load($taskId));
      return;
    }
    $this->assertSuccessItems($taskId, (int) $counts['bytes']);
    if ($this->halted($taskId)) {
      return;
    }
    $this->attach($node, $task, $live);
    try {
      $this->verify($this->node((int) $node->id()), $taskId);
    }
    catch (ImportException $e) {
      if ((string) $task->mode === 'replace') {
        $this->restoreSnapshot($task);
      }
      elseif (!$live) {
        $this->forceDraft($this->node((int) $node->id()));
      }
      $this->tasks->update($taskId, [
        'status' => ImportStatus::FAILED,
        'stage' => ImportStatus::FAILED,
        'error' => $e->getMessage(),
        'message' => $e->getMessage(),
      ]);
      $this->tasks->log($taskId, 'error', $e->getMessage());
      $this->cleaner->schedule($this->tasks->load($taskId));
      return;
    }
    if ($counts['failed'] > 0) {
      if (!$live) {
        $this->forceDraft($this->node((int) $node->id()));
      }
      $this->tasks->update($taskId, [
        'status' => ImportStatus::PARTIAL,
        'stage' => ImportStatus::PARTIAL,
        'progress' => 100,
        'message' => '部分图片失败，不能发布。',
      ]);
      $this->tasks->log($taskId, 'warning', '部分图片失败。成功 ' . $counts['success'] . '，失败 ' . $counts['failed'] . '。');
      $this->cleaner->schedule($this->tasks->load($taskId));
      return;
    }
    $fresh = $this->node((int) $node->id());
    if ($live) {
      $this->tasks->update($taskId, [
        'status' => ImportStatus::PUBLISHED,
        'stage' => ImportStatus::PUBLISHED,
        'progress' => 100,
        'message' => '已更新已发布内容',
        'error' => '',
        'published_at' => time(),
        'published_uid' => (int) $task->uid,
      ]);
      $this->tasks->log($taskId, 'info', '已更新已发布内容。图片 ' . $counts['success'] . ' 张，正式图片 ' . $counts['bytes'] . ' 字节。' . $this->reuseSummary($taskId));
      $this->cleaner->success($this->tasks->load($taskId));
      return;
    }
    $fresh->xedc_import_internal = TRUE;
    if ($fresh->hasField('moderation_state')) {
      $fresh->set('moderation_state', 'needs_review');
    }
    $fresh->setUnpublished();
    $fresh->setNewRevision(TRUE);
    $fresh->setRevisionUserId((int) $task->uid);
    $fresh->setRevisionLogMessage('图片导入完成，等待审核');
    $fresh->save();
    $this->tasks->update($taskId, [
      'status' => ImportStatus::REVIEW,
      'stage' => ImportStatus::COMPLETED,
      'progress' => 100,
      'message' => '导入完成，等待审核',
      'error' => '',
    ]);
    $this->tasks->log($taskId, 'info', '导入完成，进入待审核。图片 ' . $counts['success'] . ' 张，正式图片 ' . $counts['bytes'] . ' 字节。' . $this->reuseSummary($taskId));
    $this->cleaner->success($this->tasks->load($taskId));
  }

  /**
   * Writes successful media onto the existing photo fields.
   */
  private function attach(NodeInterface $node, object $task, bool $live): void {
    $success = $this->tasks->successes((int) $task->id);
    $new = [];
    foreach ($success as $item) {
      $mid = (int) $item->mid;
      if ($mid && !in_array($mid, $new, TRUE)) {
        $new[] = $mid;
      }
    }
    $existing = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
    if ((string) $task->mode === 'replace') {
      $mids = $new;
    }
    else {
      $mids = $existing;
      foreach ($new as $mid) {
        if (!in_array($mid, $mids, TRUE)) {
          $mids[] = $mid;
        }
      }
    }
    $cover = (int) $node->get('field_cover_image')->target_id;
    $coverEntity = $cover ? $node->get('field_cover_image')->entity : NULL;
    $locked = $this->tasks->coverLocked((int) $node->id());
    if ($locked && $coverEntity && !in_array($cover, $mids, TRUE)) {
      $mids[] = $cover;
    }
    $node->set('field_images', array_map(static fn(int $mid): array => ['target_id' => $mid], $mids));
    if ($coverEntity && in_array($cover, $mids, TRUE)) {
      $node->set('field_cover_image', ['target_id' => $cover]);
    }
    elseif ($mids) {
      $pick = (!$cover && !$locked) ? $mids[random_int(0, count($mids) - 1)] : $mids[0];
      $node->set('field_cover_image', ['target_id' => $pick]);
      if ($cover) {
        $this->tasks->log((int) $task->id, 'warning', '封面不在图片列表中，已改用第一张成功图片。');
      }
    }
    $node->xedc_import_internal = TRUE;
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) $task->uid);
    $node->setRevisionLogMessage('关联批量导入的图片');
    if (!$live) {
      if ($node->hasField('moderation_state')) {
        $node->set('moderation_state', 'draft');
      }
      $node->setUnpublished();
    }
    $node->save();
    $this->alt->refresh($node);
    if ((string) $task->mode === 'replace') {
      $this->tasks->update((int) $task->id, ['replaced' => 1]);
    }
  }

  /**
   * Confirms files, ALT text, cover and original byte totals.
   */
  private function verify(NodeInterface $node, int $taskId): void {
    $task = $this->tasks->load($taskId);
    $attached = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
    if (count($attached) !== count(array_unique($attached))) {
      throw new ImportException('图片发生重复关联。');
    }
    $bytes = 0;
    foreach ($this->tasks->successes($taskId) as $item) {
      $mid = (int) $item->mid;
      if (!in_array($mid, $attached, TRUE)) {
        throw new ImportException('成功导入的图片没有关联到内容。');
      }
      $bytes += $this->assertStored($item);
    }
    if ($bytes !== (int) $task->bytes) {
      throw new ImportException('正式图片总大小和文件不一致。');
    }
    $cover = (int) $node->get('field_cover_image')->target_id;
    if (!$cover || !$node->get('field_cover_image')->entity || !in_array($cover, $attached, TRUE)) {
      throw new ImportException('封面无效或不在图片列表中。');
    }
  }

  /**
   * Local path for a file URI.
   */
  private function fileSystemReal(string $uri): string {
    $real = \Drupal::service('file_system')->realpath($uri);
    return $real ?: '';
  }

  /**
   * Keeps a node unpublished after a failed check.
   */
  private function forceDraft(NodeInterface $node): void {
    $node->xedc_import_internal = TRUE;
    if ($node->hasField('moderation_state')) {
      $node->set('moderation_state', 'draft');
    }
    $node->setUnpublished();
    $node->save();
  }

  /**
   * Puts the gallery back if a replacement was saved and then failed checks.
   */
  private function restoreSnapshot(object $task): void {
    $snapshot = json_decode((string) $task->snapshot, TRUE);
    if (!is_array($snapshot)) {
      return;
    }
    $node = $this->node((int) $task->nid);
    $images = array_values(array_filter(array_map('intval', $snapshot['images'] ?? [])));
    $node->xedc_import_internal = TRUE;
    $node->set('field_images', array_map(static fn(int $mid): array => ['target_id' => $mid], $images));
    $cover = (int) ($snapshot['cover'] ?? 0);
    if ($cover) {
      $node->set('field_cover_image', ['target_id' => $cover]);
    }
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('替换校验失败，已恢复原图片');
    $node->save();
    $this->tasks->update((int) $task->id, ['replaced' => 0]);
    $this->tasks->log((int) $task->id, 'error', '替换校验失败，原有图片已恢复。新文件未删除。');
  }

  /**
   * TRUE when the task was cancelled or already finished.
   */
  private function halted(int $taskId): bool {
    $task = $this->tasks->load($taskId);
    return !$task || ImportStatus::isTerminal((string) $task->status);
  }

  /**
   * Checks imported files before they replace or extend the gallery.
   */
  private function assertSuccessItems(int $taskId, int $expectedBytes): void {
    $seen = [];
    $bytes = 0;
    foreach ($this->tasks->successes($taskId) as $item) {
      $mid = (int) $item->mid;
      if ($mid < 1 || isset($seen[$mid])) {
        throw new ImportException('图片发生重复关联。');
      }
      $seen[$mid] = TRUE;
      $bytes += $this->assertStored($item);
    }
    if ($bytes !== $expectedBytes) {
      throw new ImportException('正式图片总大小和文件不一致。');
    }
  }

  /**
   * Confirms one successful item points at a readable official file.
   */
  private function assertStored(object $item): int {
    $media = $this->entityTypeManager->getStorage('media')->load((int) $item->mid);
    if (!$media || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      throw new ImportException('图片媒体缺失。');
    }
    if (trim((string) $media->get('field_media_image')->alt) === '') {
      throw new ImportException('图片 ALT 为空。');
    }
    $file = $media->get('field_media_image')->entity;
    $real = ($file && method_exists($file, 'getFileUri')) ? $this->fileSystemReal($file->getFileUri()) : '';
    if (!$file || $real === '' || !is_file($real)) {
      throw new ImportException('正式图片文件缺失。');
    }
    if (str_starts_with($file->getFileUri(), 'private://xedc-import/')) {
      throw new ImportException('正式图片不应留在导入临时目录。');
    }
    $stored = hash_file('sha256', $real);
    $row = \Drupal::database()->select('xedc_image_file', 'h')
      ->fields('h', ['source_sha256', 'stored_sha256'])
      ->condition('fid', (int) $file->id())
      ->execute()
      ->fetchObject();
    if (!is_string($stored) || !$row || !preg_match('/^[a-f0-9]{64}$/', (string) $row->stored_sha256) || !hash_equals((string) $row->stored_sha256, $stored)) {
      throw new ImportException('正式图片 SHA-256 和文件不一致。');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', (string) $row->source_sha256)) {
      throw new ImportException('原始图片 SHA-256 缺失。');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', (string) ($item->source_sha256 ?? '')) || !preg_match('/^[a-f0-9]{64}$/', (string) ($item->stored_sha256 ?? ''))) {
      throw new ImportException('导入记录缺少 SHA-256。');
    }
    if (!hash_equals((string) $item->stored_sha256, $stored)) {
      throw new ImportException('导入记录的正式 SHA-256 和文件不一致。');
    }
    $temp = $this->fileSystemReal((string) ($item->temp_uri ?? ''));
    if ($temp !== '' && is_file($temp)) {
      $source = hash_file('sha256', $temp);
      if (!is_string($source) || !hash_equals((string) $item->source_sha256, $source)) {
        throw new ImportException('原始图片 SHA-256 和临时文件不一致。');
      }
    }
    $size = (int) filesize($real);
    if ($size < 1) {
      throw new ImportException('正式图片大小异常。');
    }
    return $size;
  }

  /**
   * One line describing reused and in-archive duplicate images.
   */
  private function reuseSummary(int $taskId): string {
    $database = \Drupal::database();
    $reused = (int) $database->select('xedc_import_item', 'i')
      ->condition('task_id', $taskId)
      ->condition('status', 'success')
      ->condition('reused', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
    $duplicates = (int) $database->select('xedc_import_item', 'i')
      ->condition('task_id', $taskId)
      ->condition('status', 'duplicate')
      ->countQuery()
      ->execute()
      ->fetchField();
    return '复用已有图片 ' . $reused . ' 张，压缩包内重复 ' . $duplicates . ' 张。';
  }

  private function fail(int $taskId, string $message): void {
    if ($this->halted($taskId)) {
      return;
    }
    $this->tasks->update($taskId, [
      'status' => ImportStatus::FAILED,
      'stage' => ImportStatus::FAILED,
      'error' => mb_substr($message, 0, 2000),
      'message' => mb_substr($message, 0, 2000),
    ]);
    $this->tasks->log($taskId, 'error', $message);
    $task = $this->tasks->load($taskId);
    if ($task && $task->archive_uri) {
      $this->cleaner->schedule($task);
    }
  }

  /**
   * Loads the photo node.
   */
  private function node(int $nid): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'photo') {
      throw new ImportException('关联的图片内容不存在。');
    }
    return $node;
  }

  /**
   * Member-visible failure text. Watchdog keeps the original exception.
   */
  private function memberError(\Throwable $e): string {
    $message = trim(preg_replace('/\s+/u', ' ', $e->getMessage()) ?? '');
    $message = preg_replace('/\s+Stack trace:.*/i', '', $message) ?? $message;
    $message = preg_replace('#[A-Za-z]:\\\\[^\s\'"]+#u', '[path]', $message) ?? $message;
    $message = preg_replace("#(?:(?<=^)|(?<=[\\s'\"]))(?:/[\\w./~-]+)+#u", '[path]', $message) ?? $message;
    if ($message === '') {
      $message = '处理失败';
    }
    return mb_substr($message, 0, 500);
  }

  /**
   * Seconds for one worker slice.
   */
  private function budget(): int {
    $budget = (int) $this->configFactory->get('xedc_core.settings')->get('time_budget');
    return $budget > 0 ? $budget : 25;
  }

}
