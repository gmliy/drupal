<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Upload;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\ArchiveManager;
use Drupal\xedc_core\ImageBatchIntake;
use Drupal\xedc_core\ImportException;
use Drupal\xedc_core\ImportKick;
use Drupal\xedc_core\ImportProcessor;
use Drupal\xedc_core\ImportStatus;
use Drupal\xedc_core\TaskRepository;
use Psr\Log\LoggerInterface;

/**
 * Chunked, resumable uploads stored under the private filesystem.
 */
final class UploadSessionManager {

  public const HEAD_BYTES = 65536;

  public const DEFAULT_CHUNK_BYTES = 5242880;

  private const MAX_CHUNKS = 512;

  private const INFLIGHT_CAP = 3;

  private const ACTIVE = ['open', 'uploading', 'merging'];

  public function __construct(
    protected Connection $database,
    protected FileSystemInterface $fileSystem,
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected LockBackendInterface $lock,
    protected LoggerInterface $logger,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ArchiveManager $archive,
    protected ImportProcessor $processor,
    protected ImageBatchIntake $images,
    protected TaskRepository $tasks,
    protected ImportKick $kick,
  ) {}

  /**
   * @param array<string, mixed> $payload
   * @return array{http: int, data: array<string, mixed>}
   */
  public function open(array $payload): array {
    $account = $this->currentUser->getAccount();
    if ((int) $account->id() < 1 || !$this->canUpload($account)) {
      return $this->result(403, $this->fail('没有上传权限。'));
    }
    if (!$this->ready()) {
      return $this->result(500, $this->fail('私有文件目录不可用，上传会话没有创建。'));
    }
    $kind = (string) ($payload['kind'] ?? '');
    if (!in_array($kind, ['image', 'archive'], TRUE)) {
      return $this->result(400, $this->fail('上传类型无效。'));
    }
    $filename = $this->safeName((string) ($payload['filename'] ?? ''));
    if ($filename === '') {
      return $this->result(400, $this->fail('文件名无效。'));
    }
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowed = $kind === 'archive' ? ['zip', '7z'] : ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowed, TRUE)) {
      return $this->result(400, $this->fail($kind === 'archive' ? '只接受 ZIP 和 7z。' : '只接受 JPEG、PNG、GIF 和 WebP。'));
    }
    $size = (int) ($payload['size'] ?? 0);
    if ($size < 1) {
      return $this->result(400, $this->fail('空文件不能上传。'));
    }
    $limits = $this->limits($account);
    $max = $kind === 'archive' ? $limits['archive'] : $limits['image'];
    if ($size > $max) {
      return $this->result(400, $this->fail('文件超过当前账号的大小限制。'));
    }
    $head = strtolower(trim((string) ($payload['head_sha256'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $head)) {
      return $this->result(400, $this->fail('缺少文件开头的 SHA256。'));
    }
    $fileSha = strtolower(trim((string) ($payload['file_sha256'] ?? '')));
    if ($fileSha !== '' && !preg_match('/^[a-f0-9]{64}$/', $fileSha)) {
      return $this->result(400, $this->fail('文件 SHA256 格式无效。'));
    }
    $nid = max(0, (int) ($payload['nid'] ?? 0));
    $title = mb_substr(trim((string) ($payload['title'] ?? '')), 0, 255);
    if ($kind === 'image' && $nid < 1) {
      return $this->result(400, $this->fail('请先保存草稿，再上传图片。'));
    }
    if ($kind === 'archive' && $nid < 1 && $title === '') {
      return $this->result(400, $this->fail('请先填写标题。'));
    }
    if ($nid > 0 && !$this->editablePhoto($nid, $account) instanceof NodeInterface) {
      return $this->result(403, $this->fail('找不到可编辑的图片内容。'));
    }
    $chunkBytes = $this->chunkBytes();
    $count = (int) ceil($size / $chunkBytes);
    if ($count < 1 || $count > self::MAX_CHUNKS) {
      return $this->result(400, $this->fail('分块数量超出限制。'));
    }
    $activeUser = $this->countActive((int) $account->id());
    if ($activeUser >= $this->number('upload_max_active_user', 4)) {
      return $this->result(429, $this->fail('当前账号未完成的上传过多，请先完成或取消。'));
    }
    if ($this->countActive(NULL) >= $this->number('upload_max_active_site', 12)) {
      return $this->result(429, $this->fail('站点正在处理的上传过多，请稍后再试。'));
    }
    if ($this->reservedBytes() + $size > $this->diskQuota()) {
      return $this->result(507, $this->fail('上传临时空间不足，会话没有创建。'));
    }
    $sessionId = bin2hex(random_bytes(16));
    $now = $this->now();
    $directory = $this->directoryUri((int) $account->id(), $sessionId);
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return $this->result(500, $this->fail('上传目录没有创建。'));
    }
    if (!$this->pathIsUploadTree($directory)) {
      return $this->result(500, $this->fail('上传目录位置不正确，会话没有创建。'));
    }
    $this->database->insert('xedc_upload_session')->fields([
      'session_id' => $sessionId,
      'uid' => (int) $account->id(),
      'filename' => $filename,
      'mime' => mb_substr(trim((string) ($payload['mime'] ?? '')), 0, 128),
      'kind' => $kind,
      'tier' => $limits['tier'],
      'total_bytes' => $size,
      'chunk_bytes' => $chunkBytes,
      'chunk_count' => $count,
      'received_bytes' => 0,
      'head_sha256' => $head,
      'file_sha256' => $fileSha,
      'sha_status' => 'pending',
      'status' => 'open',
      'nid' => $nid,
      'title' => $title,
      'task_id' => 0,
      'final_uri' => '',
      'error' => '',
      'created' => $now,
      'changed' => $now,
    ])->execute();
    $row = $this->load($sessionId);
    return $this->result(201, $this->present($row));
  }

  /**
   * @return array{http: int, data: array<string, mixed>}
   */
  public function list(): array {
    $account = $this->currentUser->getAccount();
    if ((int) $account->id() < 1 || !$this->canUpload($account)) {
      return $this->result(403, $this->fail('没有上传权限。'));
    }
    if (!$this->database->schema()->tableExists('xedc_upload_session')) {
      return $this->result(200, ['ok' => TRUE, 'sessions' => []]);
    }
    $cutoff = $this->now() - $this->ttl();
    $ids = $this->database->select('xedc_upload_session', 's')
      ->fields('s', ['session_id'])
      ->condition('uid', (int) $account->id())
      ->condition('status', ['open', 'uploading', 'merging', 'failed'], 'IN')
      ->condition('changed', $cutoff, '>=')
      ->orderBy('changed', 'DESC')
      ->range(0, 20)
      ->execute()
      ->fetchCol();
    $sessions = [];
    foreach ($ids as $id) {
      $row = $this->load((string) $id);
      if ($row && (int) $row->uid === (int) $account->id()) {
        $sessions[] = $this->present($row);
      }
    }
    return $this->result(200, ['ok' => TRUE, 'sessions' => $sessions]);
  }

  /**
   * @return array{http: int, data: array<string, mixed>}
   */
  public function status(string $sessionId): array {
    $row = $this->owned($sessionId);
    if (!$row) {
      return $this->result(404, $this->fail('上传会话不存在。'));
    }
    if ($this->markIfExpired($row)) {
      return $this->result(410, $this->fail('上传会话已过期。'));
    }
    return $this->result(200, $this->present($this->load($sessionId) ?: $row));
  }

  /**
   * @param resource $input
   * @return array{http: int, data: array<string, mixed>}
   */
  public function receive(string $sessionId, int $index, $input, string $declaredSha): array {
    $row = $this->owned($sessionId);
    if (!$row) {
      return $this->result(404, $this->fail('上传会话不存在。'));
    }
    if ($this->markIfExpired($row)) {
      return $this->result(410, $this->fail('上传会话已过期。'));
    }
    $sha = strtolower(trim($declaredSha));
    if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
      return $this->result(400, $this->fail('分块 SHA256 无效。'));
    }
    if ($index < 0 || $index >= (int) $row->chunk_count) {
      return $this->result(400, $this->fail('分块编号无效。'));
    }
    $expected = $this->expectedSize($row, $index);
    if ($expected < 1) {
      return $this->result(400, $this->fail('分块大小无效。'));
    }
    if (in_array((string) $row->status, ['completed', 'cancelled', 'expired', 'failed'], TRUE)) {
      $existing = $this->chunkRow($sessionId, $index);
      if ($existing && hash_equals((string) $existing->sha256, $sha)) {
        return $this->result(200, $this->present($row) + ['duplicate' => TRUE]);
      }
      return $this->result(409, $this->fail('这个上传会话已经结束。'));
    }
    $existing = $this->chunkRow($sessionId, $index);
    if ($existing && hash_equals((string) $existing->sha256, $sha) && $this->chunkFileMatches($row, $index, $sha, (int) $existing->byte_size)) {
      return $this->result(200, $this->present($row) + ['duplicate' => TRUE]);
    }
    if ($existing && !hash_equals((string) $existing->sha256, $sha)) {
      return $this->result(409, $this->fail('这个分块已经接收，但内容和本次提交不一致。'));
    }
    if ($this->reservedBytes() > $this->diskQuota()) {
      return $this->result(507, $this->fail('上传临时空间不足。'));
    }
    $dir = $this->uploadDirReal((int) $row->uid, $sessionId);
    if ($dir === NULL) {
      return $this->result(500, $this->fail('上传目录不可用。'));
    }
    $this->sweepPartials($dir);
    if ($this->inflight($dir) >= self::INFLIGHT_CAP) {
      return $this->result(429, $this->fail('这个会话的并发分块过多，请稍后重试。'));
    }
    $partial = $dir . '/p-' . sprintf('%05d', $index) . '-' . bin2hex(random_bytes(4));
    $written = $this->writeChunk($input, $partial, $expected, $index === 0 ? min(self::HEAD_BYTES, (int) $row->total_bytes) : 0);
    if (isset($written['error'])) {
      @unlink($partial);
      return $this->result((int) $written['http'], $this->fail((string) $written['error']));
    }
    if (!hash_equals($sha, (string) $written['sha'])) {
      @unlink($partial);
      return $this->result(400, $this->fail('分块 SHA256 和服务器计算的不一致。'));
    }
    if ($index === 0 && !hash_equals((string) $row->head_sha256, (string) $written['head'])) {
      @unlink($partial);
      return $this->result(400, $this->fail('文件开头和上传会话记录的不是同一个文件。'));
    }
    $lockName = 'xedc_upload_chunk:' . $sessionId . ':' . $index;
    if (!$this->lock->acquire($lockName, 30.0)) {
      @unlink($partial);
      return $this->result(409, $this->fail('这个分块正在写入，请稍后查询。'));
    }
    try {
      $stored = $this->commitChunk($row, $index, $partial, $sha, $expected);
    }
    catch (\Throwable $e) {
      @unlink($partial);
      $this->logger->error('分块写入失败 @id #@index：@message', [
        '@id' => $sessionId,
        '@index' => $index,
        '@message' => $e->getMessage(),
      ]);
      return $this->result(500, $this->fail('分块没有写入。'));
    }
    finally {
      $this->lock->release($lockName);
      if (is_file($partial)) {
        @unlink($partial);
      }
    }
    $fresh = $this->load($sessionId) ?: $row;
    if (!$stored['ok']) {
      return $this->result((int) $stored['http'], array_merge($this->present($fresh), $this->fail((string) $stored['error'])));
    }
    return $this->result(200, $this->present($fresh) + ['duplicate' => !empty($stored['duplicate'])]);
  }

  /**
   * @param array<string, mixed> $payload
   * @return array{http: int, data: array<string, mixed>}
   */
  public function complete(string $sessionId, array $payload): array {
    $row = $this->owned($sessionId);
    if (!$row) {
      return $this->result(404, $this->fail('上传会话不存在。'));
    }
    if ((string) $row->status === 'completed' && (int) $row->task_id > 0) {
      return $this->result(200, $this->taskPayload($row, '上传已经完成，没有重复创建任务。'));
    }
    if ($this->markIfExpired($row)) {
      return $this->result(410, $this->fail('上传会话已过期。'));
    }
    if (in_array((string) $row->status, ['cancelled', 'expired'], TRUE)) {
      return $this->result(409, $this->fail('这个上传会话已经结束。'));
    }
    $lockName = 'xedc_upload_complete:' . $sessionId;
    if (!$this->lock->acquire($lockName, 180.0)) {
      $fresh = $this->load($sessionId) ?: $row;
      if ((string) $fresh->status === 'completed' && (int) $fresh->task_id > 0) {
        return $this->result(200, $this->taskPayload($fresh, '上传已经完成，没有重复创建任务。'));
      }
      return $this->result(409, $this->fail('合并正在进行，请稍后查询。'));
    }
    try {
      $row = $this->load($sessionId) ?: $row;
      if ((string) $row->status === 'completed' && (int) $row->task_id > 0) {
        return $this->result(200, $this->taskPayload($row, '上传已经完成，没有重复创建任务。'));
      }
      $linked = $this->tasks->findByUploadToken($sessionId);
      if ($linked) {
        $this->rememberTask($row, (int) $linked->id, '');
        return $this->result(200, $this->taskPayload($this->load($sessionId) ?: $row, '这次上传已经创建过任务，没有重复创建。'));
      }
      $missing = $this->missing($row);
      if ($missing) {
        return $this->result(409, array_merge($this->present($row), $this->fail('还有分块没有上传完成。')));
      }
      $clientSha = strtolower(trim((string) ($payload['file_sha256'] ?? $row->file_sha256)));
      if ($clientSha !== '' && !preg_match('/^[a-f0-9]{64}$/', $clientSha)) {
        return $this->result(400, $this->fail('文件 SHA256 格式无效。'));
      }
      $this->update($sessionId, ['status' => 'merging', 'error' => '', 'changed' => $this->now()]);
      $merged = $this->merge($row);
      if (isset($merged['error'])) {
        $this->update($sessionId, [
          'status' => 'failed',
          'sha_status' => 'mismatch',
          'error' => (string) $merged['error'],
          'changed' => $this->now(),
        ]);
        return $this->result(422, $this->fail((string) $merged['error']));
      }
      $sha = (string) $merged['sha'];
      if ($clientSha !== '' && !hash_equals($clientSha, $sha)) {
        $this->update($sessionId, [
          'status' => 'failed',
          'file_sha256' => $sha,
          'sha_status' => 'mismatch',
          'error' => '文件 SHA256 和浏览器提交的不一致，没有创建导入任务。',
          'changed' => $this->now(),
        ]);
        return $this->result(422, $this->fail('文件 SHA256 和浏览器提交的不一致，没有创建导入任务。'));
      }
      $this->update($sessionId, [
        'file_sha256' => $sha,
        'sha_status' => 'matched',
        'changed' => $this->now(),
      ]);
      $row = $this->load($sessionId) ?: $row;
      $handed = (string) $row->kind === 'archive'
        ? $this->handoffArchive($row, (string) $merged['path'])
        : $this->handoffImage($row, (string) $merged['path']);
      if (empty($handed['ok'])) {
        $status = !empty($handed['task_id']) ? 'completed' : ((int) ($handed['http'] ?? 422) === 422 ? 'failed' : 'uploading');
        $this->update($sessionId, [
          'status' => $status,
          'task_id' => (int) ($handed['task_id'] ?? 0),
          'error' => (string) ($handed['error'] ?? ''),
          'changed' => $this->now(),
        ]);
        $fresh = $this->load($sessionId) ?: $row;
        return $this->result((int) ($handed['http'] ?? 422), array_merge($this->present($fresh), $this->fail((string) ($handed['error'] ?? '导入任务没有创建。'))));
      }
      $this->rememberTask($row, (int) $handed['task_id'], (string) ($handed['final_uri'] ?? ''));
      $this->deleteUploadTree($row);
      return $this->result(200, $this->taskPayload($this->load($sessionId) ?: $row, (string) ($handed['message'] ?? '上传已完成。')));
    }
    catch (\Throwable $e) {
      $this->logger->error('上传会话合并失败 @id：@message', [
        '@id' => $sessionId,
        '@message' => $e->getMessage(),
      ]);
      $linked = $this->tasks->findByUploadToken($sessionId);
      if ($linked) {
        $this->rememberTask($row, (int) $linked->id, '');
        return $this->result(200, $this->taskPayload($this->load($sessionId) ?: $row, '任务已经创建，没有重复创建。'));
      }
      $this->update($sessionId, [
        'status' => 'uploading',
        'error' => '合并没有完成，已保留分块，可以再次完成。',
        'changed' => $this->now(),
      ]);
      return $this->result(500, $this->fail('合并没有完成，已保留分块，可以再次完成。'));
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * @return array{http: int, data: array<string, mixed>}
   */
  public function cancel(string $sessionId): array {
    $row = $this->owned($sessionId);
    if (!$row) {
      return $this->result(404, $this->fail('上传会话不存在。'));
    }
    if ((string) $row->status === 'completed') {
      return $this->result(409, $this->fail('上传已经完成，不能取消。'));
    }
    $lockName = 'xedc_upload_complete:' . $sessionId;
    if (!$this->lock->acquire($lockName, 30.0)) {
      return $this->result(409, $this->fail('合并正在进行，暂时不能取消。'));
    }
    try {
      $row = $this->load($sessionId) ?: $row;
      if ((string) $row->status === 'completed') {
        return $this->result(409, $this->fail('上传已经完成，不能取消。'));
      }
      if (!$this->deleteUploadTree($row)) {
        return $this->result(500, $this->fail('上传文件路径不安全，没有删除。'));
      }
      $this->database->delete('xedc_upload_chunk')->condition('session_id', $sessionId)->execute();
      $this->update($sessionId, [
        'status' => 'cancelled',
        'received_bytes' => 0,
        'error' => '用户取消了上传。',
        'changed' => $this->now(),
      ]);
      return $this->result(200, $this->present($this->load($sessionId) ?: $row) + ['ok' => TRUE, 'message' => '上传已取消。']);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Deletes expired session files that are still inside the private upload tree.
   */
  public function purgeExpired(): int {
    if (!$this->database->schema()->tableExists('xedc_upload_session')) {
      return 0;
    }
    $cutoff = $this->now() - $this->ttl();
    $rows = $this->database->select('xedc_upload_session', 's')
      ->fields('s')
      ->condition('status', ['open', 'uploading', 'failed', 'cancelled', 'merging'], 'IN')
      ->condition('changed', $cutoff, '<')
      ->range(0, 50)
      ->execute()
      ->fetchAll();
    $count = 0;
    foreach ($rows as $row) {
      $lockName = 'xedc_upload_complete:' . $row->session_id;
      if (!$this->lock->acquire($lockName, 30.0)) {
        $this->logger->notice('跳过清理上传会话 @id，仍有请求占用。', ['@id' => $row->session_id]);
        continue;
      }
      try {
        $fresh = $this->load((string) $row->session_id);
        if (!$fresh || (int) $fresh->changed >= $cutoff || (string) $fresh->status === 'completed') {
          continue;
        }
        if ((int) $fresh->task_id > 0 || $this->tasks->findByUploadToken((string) $fresh->session_id)) {
          $this->logger->notice('跳过清理上传会话 @id，已经关联导入任务。', ['@id' => $fresh->session_id]);
          $this->update((string) $fresh->session_id, ['status' => 'completed', 'changed' => $this->now()]);
          continue;
        }
        if (!$this->deleteUploadTree($fresh)) {
          continue;
        }
        $this->database->delete('xedc_upload_chunk')->condition('session_id', $fresh->session_id)->execute();
        $this->update((string) $fresh->session_id, [
          'status' => 'expired',
          'received_bytes' => 0,
          'final_uri' => '',
          'error' => '会话过期，已清理未完成的分块。',
          'changed' => $this->now(),
        ]);
        $this->logger->notice('已清理过期上传会话 @id，用户 @uid，原状态 @status。', [
          '@id' => $fresh->session_id,
          '@uid' => $fresh->uid,
          '@status' => $row->status,
        ]);
        $count++;
      }
      finally {
        $this->lock->release($lockName);
      }
    }
    $done = $this->database->select('xedc_upload_session', 's')
      ->fields('s')
      ->condition('status', 'completed')
      ->condition('changed', $this->now() - 3600, '<')
      ->range(0, 20)
      ->execute()
      ->fetchAll();
    foreach ($done as $row) {
      $uri = $this->directoryUri((int) $row->uid, (string) $row->session_id);
      $real = $this->fileSystem->realpath($uri);
      if ($real && $this->pathIsUploadTree($uri)) {
        $this->deleteUploadTree($row);
        $this->logger->notice('已清理已完成会话残留分块 @id。', ['@id' => $row->session_id]);
      }
    }
    return $count;
  }

  /**
   * @return array{tier: string, image: int, archive: int}
   */
  public function limits(AccountInterface $account): array {
    $config = $this->configFactory->get('xedc_core.settings');
    $image = (int) $config->get('max_image_bytes') ?: 52428800;
    $archive = (int) $config->get('max_archive_bytes') ?: 209715200;
    if ($account->hasPermission('administer xedc image imports')) {
      return ['tier' => 'admin', 'image' => $image, 'archive' => $archive];
    }
    return [
      'tier' => 'member',
      'image' => (int) $config->get('member_max_image_bytes') ?: $image,
      'archive' => (int) $config->get('member_max_archive_bytes') ?: $archive,
    ];
  }

  private function canUpload(AccountInterface $account): bool {
    return $account->hasPermission('administer xedc image imports') || $account->hasPermission('import photo archives');
  }

  private function ready(): bool {
    return $this->archive->privateReady() && $this->database->schema()->tableExists('xedc_upload_session');
  }

  private function chunkBytes(): int {
    $bytes = $this->number('upload_chunk_bytes', self::DEFAULT_CHUNK_BYTES);
    return min(8388608, max(1048576, $bytes));
  }

  private function ttl(): int {
    return max(3600, $this->number('upload_session_ttl', 86400));
  }

  private function diskQuota(): int {
    return max(104857600, $this->number('upload_max_disk_bytes', 2147483648));
  }

  private function number(string $key, int $default): int {
    $value = $this->configFactory->get('xedc_core.settings')->get($key);
    return is_numeric($value) ? (int) $value : $default;
  }

  private function now(): int {
    return $this->time->getRequestTime() ?: time();
  }

  private function countActive(?int $uid): int {
    $query = $this->database->select('xedc_upload_session', 's')
      ->condition('status', self::ACTIVE, 'IN');
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  private function reservedBytes(): int {
    $activeQuery = $this->database->select('xedc_upload_session', 's')
      ->condition('status', self::ACTIVE, 'IN');
    $activeQuery->addExpression('COALESCE(SUM(total_bytes), 0)', 'bytes');
    $active = (int) $activeQuery->execute()->fetchField();
    $failedQuery = $this->database->select('xedc_upload_session', 's')
      ->condition('status', 'failed');
    $failedQuery->addExpression('COALESCE(SUM(received_bytes), 0)', 'bytes');
    $failed = (int) $failedQuery->execute()->fetchField();
    return $active + $failed;
  }

  private function owned(string $sessionId): ?object {
    if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
      return NULL;
    }
    $account = $this->currentUser->getAccount();
    if ((int) $account->id() < 1 || !$this->canUpload($account)) {
      return NULL;
    }
    $row = $this->load($sessionId);
    if (!$row || (int) $row->uid !== (int) $account->id()) {
      return NULL;
    }
    return $row;
  }

  private function load(string $sessionId): ?object {
    if (!$this->database->schema()->tableExists('xedc_upload_session')) {
      return NULL;
    }
    $row = $this->database->select('xedc_upload_session', 's')
      ->fields('s')
      ->condition('session_id', $sessionId)
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  private function markIfExpired(object $row): bool {
    if (in_array((string) $row->status, ['completed', 'cancelled', 'expired'], TRUE)) {
      return (string) $row->status === 'expired';
    }
    if ((int) $row->changed >= $this->now() - $this->ttl()) {
      return FALSE;
    }
    $this->update((string) $row->session_id, [
      'status' => 'expired',
      'error' => '上传会话已过期。',
      'changed' => $this->now(),
    ]);
    return TRUE;
  }

  /**
   * @param array<string, mixed> $fields
   */
  private function update(string $sessionId, array $fields): void {
    $this->database->update('xedc_upload_session')
      ->fields($fields)
      ->condition('session_id', $sessionId)
      ->execute();
  }

  private function expectedSize(object $row, int $index): int {
    $chunk = (int) $row->chunk_bytes;
    $count = (int) $row->chunk_count;
    $total = (int) $row->total_bytes;
    if ($index === $count - 1) {
      return $total - (($count - 1) * $chunk);
    }
    return $chunk;
  }

  private function chunkRow(string $sessionId, int $index): ?object {
    $row = $this->database->select('xedc_upload_chunk', 'c')
      ->fields('c')
      ->condition('session_id', $sessionId)
      ->condition('chunk_index', $index)
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  private function chunkFileMatches(object $session, int $index, string $sha, int $size): bool {
    $path = $this->chunkPath($session, $index);
    if ($path === NULL || !is_file($path) || is_link($path) || filesize($path) !== $size) {
      return FALSE;
    }
    $hash = hash_file('sha256', $path);
    return is_string($hash) && hash_equals($sha, $hash);
  }

  /**
   * @param resource $input
   * @return array{sha?: string, head?: string, error?: string, http?: int}
   */
  private function writeChunk($input, string $partial, int $expected, int $headBytes): array {
    if (!is_resource($input)) {
      return ['error' => '没有读到分块内容。', 'http' => 400];
    }
    $out = fopen($partial, 'wb');
    if ($out === FALSE) {
      return ['error' => '分块临时文件没有创建。', 'http' => 500];
    }
    $full = hash_init('sha256');
    $head = $headBytes > 0 ? hash_init('sha256') : NULL;
    $written = 0;
    $headWritten = 0;
    while (!feof($input)) {
      $buf = fread($input, 1048576);
      if ($buf === FALSE) {
        fclose($out);
        return ['error' => '读取分块失败。', 'http' => 400];
      }
      if ($buf === '') {
        break;
      }
      $written += strlen($buf);
      if ($written > $expected) {
        fclose($out);
        return ['error' => '分块大于预期大小。', 'http' => 400];
      }
      hash_update($full, $buf);
      if ($head && $headWritten < $headBytes) {
        $take = min(strlen($buf), $headBytes - $headWritten);
        hash_update($head, substr($buf, 0, $take));
        $headWritten += $take;
      }
      if (fwrite($out, $buf) !== strlen($buf)) {
        fclose($out);
        return ['error' => '分块没有完全写入磁盘。', 'http' => 500];
      }
    }
    fflush($out);
    fclose($out);
    if ($written !== $expected) {
      return ['error' => '分块大小和会话记录不一致。', 'http' => 400];
    }
    return [
      'sha' => hash_final($full),
      'head' => $head ? hash_final($head) : '',
    ];
  }

  /**
   * @return array{ok: bool, duplicate?: bool, http?: int, error?: string}
   */
  private function commitChunk(object $session, int $index, string $partial, string $sha, int $expected): array {
    $sessionId = (string) $session->session_id;
    $transaction = $this->database->startTransaction();
    try {
      $locked = $this->database->select('xedc_upload_session', 's')
        ->fields('s')
        ->condition('session_id', $sessionId)
        ->forUpdate()
        ->execute()
        ->fetchObject();
      if (!$locked || (int) $locked->uid !== (int) $session->uid) {
        $transaction->rollBack();
        return ['ok' => FALSE, 'http' => 404, 'error' => '上传会话不存在。'];
      }
      if (!in_array((string) $locked->status, ['open', 'uploading'], TRUE)) {
        $existing = $this->chunkRow($sessionId, $index);
        $transaction->rollBack();
        if ($existing && hash_equals((string) $existing->sha256, $sha)) {
          return ['ok' => TRUE, 'duplicate' => TRUE];
        }
        return ['ok' => FALSE, 'http' => 409, 'error' => '这个上传会话已经结束。'];
      }
      $existing = $this->chunkRow($sessionId, $index);
      if ($existing) {
        $transaction->rollBack();
        if (hash_equals((string) $existing->sha256, $sha)) {
          return ['ok' => TRUE, 'duplicate' => TRUE];
        }
        return ['ok' => FALSE, 'http' => 409, 'error' => '这个分块已经接收，但内容和本次提交不一致。'];
      }
      $final = $this->chunkPath($locked, $index);
      if ($final === NULL) {
        $transaction->rollBack();
        return ['ok' => FALSE, 'http' => 500, 'error' => '分块路径无效。'];
      }
      if (is_file($final)) {
        @unlink($final);
      }
      if (!@rename($partial, $final) || !is_file($final) || is_link($final)) {
        $transaction->rollBack();
        return ['ok' => FALSE, 'http' => 500, 'error' => '分块没有保存。'];
      }
      $hash = hash_file('sha256', $final);
      if (!is_string($hash) || !hash_equals($sha, $hash) || filesize($final) !== $expected) {
        @unlink($final);
        $transaction->rollBack();
        return ['ok' => FALSE, 'http' => 500, 'error' => '分块保存后校验失败。'];
      }
      $this->database->insert('xedc_upload_chunk')->fields([
        'session_id' => $sessionId,
        'chunk_index' => $index,
        'byte_size' => $expected,
        'sha256' => $sha,
        'created' => $this->now(),
      ])->execute();
      $sumQuery = $this->database->select('xedc_upload_chunk', 'c')
        ->condition('session_id', $sessionId);
      $sumQuery->addExpression('COALESCE(SUM(byte_size), 0)', 'bytes');
      $sum = (int) $sumQuery->execute()->fetchField();
      $this->update($sessionId, [
        'received_bytes' => $sum,
        'status' => 'uploading',
        'error' => '',
        'changed' => $this->now(),
      ]);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    return ['ok' => TRUE, 'duplicate' => FALSE];
  }

  /**
   * @return array{path?: string, sha?: string, error?: string}
   */
  private function merge(object $session): array {
    $dir = $this->uploadDirReal((int) $session->uid, (string) $session->session_id);
    if ($dir === NULL) {
      return ['error' => '上传目录不可用，没有合并。'];
    }
    $target = $dir . '/merged.bin';
    $known = (string) $session->file_sha256;
    $reused = is_file($target) && !is_link($target) && filesize($target) === (int) $session->total_bytes
      && (string) $session->sha_status === 'matched' && preg_match('/^[a-f0-9]{64}$/', $known);
    if ($reused) {
      $existing = hash_file('sha256', $target);
      if (is_string($existing) && hash_equals($known, $existing)) {
        return ['path' => $target, 'sha' => $existing];
      }
    }
    if (is_file($target) || is_link($target)) {
      @unlink($target);
    }
    $out = fopen($target, 'wb');
    if ($out === FALSE) {
      return ['error' => '合并文件没有创建。'];
    }
    $hash = hash_init('sha256');
    try {
      for ($index = 0; $index < (int) $session->chunk_count; $index++) {
        $path = $this->chunkPath($session, $index);
        $row = $this->chunkRow((string) $session->session_id, $index);
        if ($path === NULL || !$row || !is_file($path) || is_link($path)) {
          return ['error' => '分块 ' . $index . ' 缺失，没有创建导入任务。'];
        }
        $in = fopen($path, 'rb');
        if ($in === FALSE) {
          return ['error' => '分块 ' . $index . ' 无法读取。'];
        }
        while (!feof($in)) {
          $buf = fread($in, 1048576);
          if ($buf === FALSE || $buf === '') {
            break;
          }
          hash_update($hash, $buf);
          if (fwrite($out, $buf) !== strlen($buf)) {
            fclose($in);
            return ['error' => '合并写入中断。'];
          }
        }
        fclose($in);
      }
    }
    finally {
      fflush($out);
      fclose($out);
    }
    clearstatcache(TRUE, $target);
    if (!is_file($target) || is_link($target) || filesize($target) !== (int) $session->total_bytes) {
      @unlink($target);
      return ['error' => '合并后的文件大小不正确，没有创建导入任务。'];
    }
    $sha = hash_final($hash);
    $check = hash_file('sha256', $target);
    if (!is_string($check) || !hash_equals($sha, $check)) {
      @unlink($target);
      return ['error' => '合并后的 SHA256 校验失败，没有创建导入任务。'];
    }
    return ['path' => $target, 'sha' => $sha];
  }

  /**
   * @return array<string, mixed>
   */
  private function handoffImage(object $session, string $path): array {
    $error = $this->imageError($path);
    if ($error !== '') {
      return ['ok' => FALSE, 'http' => 422, 'error' => $error . ' 没有创建导入任务。'];
    }
    $node = $this->editablePhoto((int) $session->nid, $this->currentUser->getAccount());
    if (!$node instanceof NodeInterface) {
      return ['ok' => FALSE, 'http' => 403, 'error' => '找不到可编辑的图片内容，没有创建导入任务。'];
    }
    try {
      $result = $this->images->addFile($node, $path, (string) $session->filename, (string) $session->session_id);
    }
    catch (ImportException $e) {
      return ['ok' => FALSE, 'http' => 422, 'error' => $e->getMessage()];
    }
    if (empty($result['ok']) || (int) ($result['task_id'] ?? 0) < 1) {
      return [
        'ok' => FALSE,
        'http' => 422,
        'error' => (string) ($result['error'] ?? $result['message'] ?? '图片没有进入导入流程。'),
        'task_id' => (int) ($result['task_id'] ?? 0),
      ];
    }
    return [
      'ok' => TRUE,
      'task_id' => (int) $result['task_id'],
      'final_uri' => '',
      'message' => (string) ($result['message'] ?? '图片已保存，后台继续处理。'),
      'nid' => (int) ($result['nid'] ?? $session->nid),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function handoffArchive(object $session, string $path): array {
    try {
      $format = $this->archive->detect($path);
    }
    catch (ImportException $e) {
      return ['ok' => FALSE, 'http' => 422, 'error' => $e->getMessage() . ' 没有创建导入任务。'];
    }
    if ($format === '7z' && !$this->archive->sevenZipBinary()) {
      return ['ok' => FALSE, 'http' => 422, 'error' => '服务器未安装 7z，文件没有保存，也没有创建任务。'];
    }
    $bytes = filesize($path);
    if ($bytes === FALSE || (int) $bytes !== (int) $session->total_bytes) {
      return ['ok' => FALSE, 'http' => 422, 'error' => '压缩包大小校验失败，没有创建导入任务。'];
    }
    $sha = (string) $session->file_sha256;
    if ($sha === '') {
      $hashed = hash_file('sha256', $path);
      $sha = is_string($hashed) ? $hashed : '';
    }
    $duplicate = $sha !== '' ? $this->tasks->findByArchiveHash($sha) : NULL;
    if ($duplicate) {
      return [
        'ok' => TRUE,
        'task_id' => (int) $duplicate->id,
        'final_uri' => (string) $duplicate->archive_uri,
        'message' => '相同压缩包已有任务，没有重复创建。',
        'nid' => (int) $duplicate->nid,
      ];
    }
    $directory = 'private://xedc-import/inbox';
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return ['ok' => FALSE, 'http' => 500, 'error' => '私有收件箱不可用，没有创建导入任务。'];
    }
    try {
      $uri = $this->fileSystem->copy($path, $directory . '/' . $this->safeName((string) $session->filename), FileExists::Rename);
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'http' => 500, 'error' => '压缩包无法写入私有收件箱。'];
    }
    $real = is_string($uri) ? $this->fileSystem->realpath($uri) : FALSE;
    if (!is_string($uri) || !is_string($real) || !is_file($real) || is_link($real) || filesize($real) !== (int) $bytes) {
      if (is_string($uri)) {
        $this->fileSystem->delete($uri);
      }
      return ['ok' => FALSE, 'http' => 500, 'error' => '压缩包写入后校验失败，已删除这次的临时文件。'];
    }
    $file = File::create([
      'uri' => $uri,
      'uid' => (int) $session->uid,
      'status' => FileInterface::STATUS_PERMANENT,
      'filename' => $this->fileSystem->basename($uri),
    ]);
    $file->setMimeType($format === '7z' ? 'application/x-7z-compressed' : 'application/zip');
    $file->save();
    $node = $this->resolveNode($session);
    if (!$node instanceof NodeInterface) {
      $file->delete();
      return ['ok' => FALSE, 'http' => 403, 'error' => '没有可编辑的图片内容，压缩包没有保留。'];
    }
    try {
      $id = $this->processor->start($node, [
        'is_new' => FALSE,
        'nid' => (int) $node->id(),
        'mode' => 'append',
        'fid' => (int) $file->id(),
        'server' => '',
        'archive_bytes' => (int) $bytes,
        'archive_sha256' => $sha,
        'upload_token' => (string) $session->session_id,
      ]);
    }
    catch (\Throwable $e) {
      $failed = $this->tasks->findByUploadToken((string) $session->session_id);
      if ($failed) {
        return [
          'ok' => TRUE,
          'task_id' => (int) $failed->id,
          'final_uri' => (string) $failed->archive_uri,
          'message' => $e->getMessage(),
          'nid' => (int) $failed->nid,
        ];
      }
      $file->delete();
      return ['ok' => FALSE, 'http' => 422, 'error' => '压缩包没有保留，任务也没有创建：' . $e->getMessage()];
    }
    $this->kick->schedule($id);
    return [
      'ok' => TRUE,
      'task_id' => $id,
      'final_uri' => $uri,
      'message' => '压缩包已保存，导入任务已创建。',
      'nid' => (int) $node->id(),
    ];
  }

  private function resolveNode(object $session): NodeInterface|false {
    $account = $this->currentUser->getAccount();
    if ((int) $session->nid > 0) {
      $node = $this->editablePhoto((int) $session->nid, $account);
      return $node instanceof NodeInterface ? $node : FALSE;
    }
    $title = trim((string) $session->title);
    if ($title === '') {
      return FALSE;
    }
    $node = Node::create([
      'type' => 'photo',
      'title' => mb_substr($title, 0, 255),
      'uid' => (int) $account->id(),
      'status' => 0,
    ]);
    if (!$node->access('create')) {
      return FALSE;
    }
    $node->xedc_keep_unpublished = TRUE;
    if ($node->hasField('moderation_state')) {
      $node->set('moderation_state', 'draft');
    }
    $node->save();
    return $node;
  }

  private function editablePhoto(int $nid, AccountInterface $account): NodeInterface|false {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'photo' || !$node->access('update', $account)) {
      return FALSE;
    }
    return $node;
  }

  private function imageError(string $path): string {
    if (!is_file($path) || is_link($path)) {
      return '图片文件不可读。';
    }
    $info = @getimagesize($path);
    $type = (int) ($info[2] ?? 0);
    $map = [
      IMAGETYPE_JPEG => 'image/jpeg',
      IMAGETYPE_PNG => 'image/png',
      IMAGETYPE_GIF => 'image/gif',
      IMAGETYPE_WEBP => 'image/webp',
    ];
    if (!$info || !isset($map[$type])) {
      return '不是受支持的图片，或文件已损坏。';
    }
    $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($mime !== $map[$type]) {
      return '文件内容与图片格式不一致。';
    }
    $pixels = (int) $info[0] * (int) $info[1];
    $max = (int) $this->configFactory->get('xedc_core.settings')->get('max_pixels') ?: 40000000;
    if ($pixels > $max) {
      return '图片像素超过限制。';
    }
    return '';
  }

  private function rememberTask(object $session, int $taskId, string $finalUri): void {
    $fields = [
      'status' => 'completed',
      'task_id' => $taskId,
      'error' => '',
      'changed' => $this->now(),
    ];
    if ($finalUri !== '' && str_starts_with($finalUri, 'private://xedc-import/')) {
      $fields['final_uri'] = $finalUri;
    }
    $task = $taskId > 0 ? $this->tasks->load($taskId) : NULL;
    if ($task && (int) $session->nid < 1) {
      $fields['nid'] = (int) $task->nid;
    }
    $this->update((string) $session->session_id, $fields);
  }

  /**
   * @return list<int>
   */
  private function receivedIndexes(object $session): array {
    $indexes = $this->database->select('xedc_upload_chunk', 'c')
      ->fields('c', ['chunk_index'])
      ->condition('session_id', $session->session_id)
      ->orderBy('chunk_index')
      ->execute()
      ->fetchCol();
    return array_map('intval', $indexes);
  }

  /**
   * @return list<int>
   */
  private function missing(object $session): array {
    $have = array_fill_keys($this->receivedIndexes($session), TRUE);
    $missing = [];
    for ($i = 0; $i < (int) $session->chunk_count; $i++) {
      if (!isset($have[$i])) {
        $missing[] = $i;
      }
    }
    return $missing;
  }

  /**
   * @return array<string, mixed>
   */
  private function present(object $session): array {
    $received = $this->receivedIndexes($session);
    $missing = $this->missing($session);
    return [
      'ok' => !in_array((string) $session->status, ['failed', 'cancelled', 'expired'], TRUE),
      'session_id' => (string) $session->session_id,
      'filename' => (string) $session->filename,
      'kind' => (string) $session->kind,
      'tier' => (string) $session->tier,
      'total_bytes' => (int) $session->total_bytes,
      'chunk_bytes' => (int) $session->chunk_bytes,
      'chunk_count' => (int) $session->chunk_count,
      'received_bytes' => (int) $session->received_bytes,
      'received_chunks' => $received,
      'missing_chunks' => $missing,
      'status' => (string) $session->status,
      'sha_status' => (string) $session->sha_status,
      'head_sha256' => (string) $session->head_sha256,
      'file_sha256' => (string) $session->file_sha256,
      'task_id' => (int) $session->task_id,
      'nid' => (int) $session->nid,
      'error' => (string) $session->error,
      'created' => (int) $session->created,
      'changed' => (int) $session->changed,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function taskPayload(object $session, string $message): array {
    $task = (int) $session->task_id > 0 ? $this->tasks->load((int) $session->task_id) : NULL;
    $status = $task ? (string) $task->status : (string) $session->status;
    $done = $task && in_array($status, [ImportStatus::REVIEW, ImportStatus::COMPLETED, ImportStatus::PUBLISHED], TRUE);
    return $this->present($session) + [
      'ok' => TRUE,
      'stored' => TRUE,
      'task_created' => $task !== NULL,
      'task_id' => $task ? (int) $task->id : (int) $session->task_id,
      'nid' => $task ? (int) $task->nid : (int) $session->nid,
      'phase' => $done ? 'completed' : 'queued',
      'label' => $task ? ImportStatus::label($status) : '已上传',
      'message' => $message,
      'error' => '',
    ];
  }

  /**
   * @param array<string, mixed> $data
   * @return array{http: int, data: array<string, mixed>}
   */
  private function result(int $http, array $data): array {
    $data += ['ok' => $http >= 200 && $http < 300];
    return ['http' => $http, 'data' => $data];
  }

  /**
   * @return array<string, mixed>
   */
  private function fail(string $message): array {
    return ['ok' => FALSE, 'error' => $message, 'message' => $message];
  }

  private function safeName(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = str_replace("\0", '', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    if ($name === '' || $name === '.' || $name === '..') {
      return '';
    }
    return mb_substr($name, 0, 180);
  }

  private function directoryUri(int $uid, string $sessionId): string {
    if ($uid < 1 || !preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
      throw new \InvalidArgumentException('上传会话路径无效。');
    }
    return 'private://xedc-upload/' . $uid . '/' . $sessionId;
  }

  private function uploadDirReal(int $uid, string $sessionId): ?string {
    $uri = $this->directoryUri($uid, $sessionId);
    if (!$this->fileSystem->prepareDirectory($uri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return NULL;
    }
    if (!$this->pathIsUploadTree($uri)) {
      return NULL;
    }
    $real = $this->fileSystem->realpath($uri);
    return is_string($real) && !is_link($real) ? $real : NULL;
  }

  private function chunkPath(object $session, int $index): ?string {
    if ($index < 0 || $index > 99999) {
      return NULL;
    }
    $dir = $this->uploadDirReal((int) $session->uid, (string) $session->session_id);
    if ($dir === NULL) {
      return NULL;
    }
    $path = $dir . '/c-' . sprintf('%05d', $index);
    if (is_link($path)) {
      return NULL;
    }
    $root = $this->fileSystem->realpath('private://xedc-upload');
    if (!is_string($root) || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
      return NULL;
    }
    return $path;
  }

  private function pathIsUploadTree(string $uri): bool {
    if (!preg_match('#^private://xedc-upload/(\d+)/([a-f0-9]{32})$#', $uri)) {
      return FALSE;
    }
    $real = $this->fileSystem->realpath($uri);
    $root = $this->fileSystem->realpath('private://xedc-upload');
    if (!$real || !$root || is_link($real) || is_link($root)) {
      return FALSE;
    }
    $final = (string) $this->database->select('xedc_upload_session', 's')
      ->fields('s', ['final_uri'])
      ->condition('session_id', substr($uri, -32))
      ->execute()
      ->fetchField();
    if ($final !== '' && !str_starts_with($final, 'private://xedc-upload/')) {
      // Handed-off files live elsewhere and are never part of this tree.
    }
    return str_starts_with($real, $root . DIRECTORY_SEPARATOR);
  }

  private function deleteUploadTree(object $session): bool {
    try {
      $uri = $this->directoryUri((int) $session->uid, (string) $session->session_id);
    }
    catch (\InvalidArgumentException) {
      $this->logger->error('拒绝清理上传会话 @id，会话标识无效。', ['@id' => $session->session_id ?? '']);
      return FALSE;
    }
    $final = (string) ($session->final_uri ?? '');
    if ($final !== '' && !str_starts_with($final, 'private://xedc-upload/')) {
      // Keep the imported inbox or task file.
    }
    elseif ($final !== '') {
      $this->logger->error('拒绝清理上传会话 @id，最终路径仍在上传目录内。', ['@id' => $session->session_id]);
      return FALSE;
    }
    $real = $this->fileSystem->realpath($uri);
    if ($real === FALSE) {
      return TRUE;
    }
    if (!$this->pathIsUploadTree($uri)) {
      $this->logger->error('拒绝清理上传会话 @id，路径不在私有上传目录内：@uri', [
        '@id' => $session->session_id,
        '@uri' => $uri,
      ]);
      return FALSE;
    }
    $this->fileSystem->deleteRecursive($uri);
    clearstatcache(TRUE, $real);
    if (is_dir($real)) {
      $this->logger->error('上传目录没有删除：@uri', ['@uri' => $uri]);
      return FALSE;
    }
    return TRUE;
  }

  private function inflight(string $dir): int {
    $count = 0;
    foreach (scandir($dir) ?: [] as $name) {
      if (str_starts_with($name, 'p-') && is_file($dir . '/' . $name) && !is_link($dir . '/' . $name)) {
        $count++;
      }
    }
    return $count;
  }

  private function sweepPartials(string $dir): void {
    $cutoff = time() - 900;
    foreach (scandir($dir) ?: [] as $name) {
      if (!str_starts_with($name, 'p-')) {
        continue;
      }
      $path = $dir . '/' . $name;
      if (is_file($path) && !is_link($path) && filemtime($path) !== FALSE && filemtime($path) < $cutoff) {
        @unlink($path);
      }
    }
  }

}
