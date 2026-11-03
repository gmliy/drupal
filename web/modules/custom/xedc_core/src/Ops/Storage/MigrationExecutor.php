<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Copies local files to R2 without changing file URIs or deleting sources.
 */
final class MigrationExecutor {

  public function __construct(
    protected Connection $database,
    protected FileSystemInterface $fileSystem,
    protected StorageConfig $config,
    protected CredentialVault $credentials,
    protected MigrationPlanner $planner,
    protected MigrationRepository $repository,
    protected RemoteFileReader $reader,
    protected RemoteFileWriter $writer,
    protected StorageUriResolver $uris,
    protected StorageOperationGuard $guard,
    protected StorageJournal $journal,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * @return array<string, mixed>
   *   Updated task.
   */
  public function inventory(string $taskId, string $prefix = ''): array {
    $task = $this->requireTask($taskId);
    if (isset($task['error'])) {
      return $task;
    }
    if (!$this->repository->ready()) {
      return $this->blocked($task, '迁移表还没有安装。请先运行数据库更新。');
    }
    $prefix = trim(str_replace('\\', '/', $prefix), '/');
    if ($prefix !== '' && (str_contains($prefix, '..') || preg_match('/[%_\x00]/', $prefix))) {
      return $this->blocked($task, '路径前缀无效。');
    }
    if (!$this->lock->acquire('xedc_storage_migration_' . $taskId, 120.0)) {
      return $this->blocked($task, '这个迁移任务正在运行。');
    }
    try {
      $this->repository->clear($taskId);
      $source = (string) $task['source'];
      $query = $this->database->select('file_managed', 'f')
        ->fields('f', ['fid', 'uri', 'filename', 'filemime', 'filesize']);
      $this->applySource($query, $source);
      if ($prefix !== '') {
        $scheme = $source === 'private' ? 'private' : 'public';
        if ($source === 'local') {
          $group = $query->orConditionGroup()
            ->condition('uri', 'public://' . $this->database->escapeLike($prefix) . '/%', 'LIKE')
            ->condition('uri', 'private://' . $this->database->escapeLike($prefix) . '/%', 'LIKE')
            ->condition('uri', 'public://' . $this->database->escapeLike($prefix), '=')
            ->condition('uri', 'private://' . $this->database->escapeLike($prefix), '=');
          $query->condition($group);
        }
        else {
          $query->condition('uri', $scheme . '://' . $this->database->escapeLike($prefix) . '/%', 'LIKE');
        }
      }
      $rows = $query->execute();
      $hashes = [];
      $missing = 0;
      foreach ($rows as $row) {
        $uri = (string) $row->uri;
        $kind = $this->uris->mediaKind((string) $row->filemime, (string) $row->filename);
        $real = $this->uris->isRemote($uri) ? FALSE : $this->fileSystem->realpath($uri);
        $exists = is_string($real) && is_file($real) && !is_link($real);
        $bytes = $exists ? (int) filesize($real) : (int) $row->filesize;
        $hash = '';
        $status = 'pending';
        $message = '';
        if (!$exists) {
          $status = 'missing';
          $message = '本地文件不存在，没有复制。';
          $missing++;
        }
        elseif ($bytes <= 33554432) {
          $hashed = hash_file('sha256', $real);
          $hash = is_string($hashed) ? $hashed : '';
          if ($hash !== '') {
            $hashes[$hash] = ($hashes[$hash] ?? 0) + 1;
          }
        }
        else {
          $message = '文件超过 32MB，清单未计算 SHA-256，复制时会重新计算。';
        }
        $this->repository->insert([
          'task_id' => $taskId,
          'fid' => (int) $row->fid,
          'source_uri' => substr($uri, 0, 255),
          'scheme' => $this->uris->scheme($uri),
          'kind' => $kind,
          'bytes' => $bytes,
          'object_key' => (string) ($this->uris->objectKey($uri) ?? ''),
          'sha256' => $hash,
          'status' => $status,
          'message' => $message,
        ]);
      }
      $duplicateGroups = 0;
      foreach ($hashes as $count) {
        if ($count > 1) {
          $duplicateGroups++;
        }
      }
      $summary = $this->repository->summary($taskId);
      $updated = $this->planner->updateTask($taskId, [
        'status' => 'inventoried',
        'message' => '清单已完成。没有复制文件，没有修改 URI，没有删除源文件。',
        'prefix' => $prefix,
        'missing' => $missing,
        'duplicate_groups' => $duplicateGroups,
        'kinds' => [
          'image' => $summary['image'],
          'video' => $summary['video'],
          'archive' => $summary['archive'],
          'other' => $summary['other'],
        ],
        'counts' => [
          'files' => $summary['items'],
          'bytes' => $summary['bytes'],
        ],
        'copied' => 0,
        'failed' => $summary['failed'],
        'verified' => FALSE,
        'source_retained' => TRUE,
        'uri_rewritten' => FALSE,
        'copy_enabled' => FALSE,
        'finished' => 0,
      ]);
      $this->journal->add('info', '迁移清单 ' . $taskId . ' 完成，文件 ' . $summary['items'] . ' 个。');
      return is_array($updated) ? $updated : $task;
    }
    finally {
      $this->lock->release('xedc_storage_migration_' . $taskId);
    }
  }

  /**
   * @return array<string, mixed>
   *   Updated task.
   */
  public function copyBatch(string $taskId, int $limit, bool $confirmed, bool $retryFailed = FALSE): array {
    if (!$confirmed) {
      return ['status' => 'blocked', 'message' => '复制需要本次请求明确确认。确认只复制，不修改 URI，不删除本地文件。'];
    }
    $task = $this->requireTask($taskId);
    if (isset($task['error'])) {
      return $task;
    }
    if (!in_array((string) $task['status'], ['inventoried', 'copying', 'paused', 'verified'], TRUE) && !$retryFailed) {
      return $this->blocked($task, '请先完成清单，再复制。');
    }
    if ((string) ($task['status'] ?? '') === 'paused') {
      return $this->blocked($task, '任务已暂停。继续之后才能复制。');
    }
    $profile = $this->config->profile((string) ($task['target'] ?? ''));
    if ($profile === NULL) {
      return $this->blocked($task, '目标供应商配置不存在。');
    }
    $resolved = $this->credentials->resolve($profile);
    if (empty($resolved['ok'])) {
      return $this->blocked($task, '目标凭证不可用，没有复制。');
    }
    if (!$this->lock->acquire('xedc_storage_migration_' . $taskId, 300.0)) {
      return $this->blocked($task, '这个迁移任务正在运行。');
    }
    $limit = max(1, min(20, $limit));
    try {
      $this->planner->updateTask($taskId, [
        'status' => 'copying',
        'copy_enabled' => TRUE,
        'source_retained' => TRUE,
        'uri_rewritten' => FALSE,
      ]);
      $rows = $this->repository->claim($taskId, $limit, $retryFailed);
      foreach ($rows as $row) {
        $current = $this->planner->find($taskId);
        if (($current['status'] ?? '') === 'paused') {
          break;
        }
        $this->copyOne($resolved['connection'], $row);
      }
      $summary = $this->repository->summary($taskId);
      $done = $summary['pending'] === 0 && $summary['failed'] === 0 && $summary['verified'] > 0;
      $updated = $this->planner->updateTask($taskId, [
        'status' => $done ? 'verified' : 'copying',
        'copied' => $summary['verified'],
        'failed' => $summary['failed'],
        'verified' => $done,
        'source_retained' => TRUE,
        'uri_rewritten' => FALSE,
        'message' => '本批复制结束。源文件仍在本地，文件 URI 没有修改。已校验 ' . $summary['verified'] . ' 个，失败 ' . $summary['failed'] . ' 个。',
        'finished' => $done ? \Drupal::time()->getRequestTime() : 0,
      ]);
      return is_array($updated) ? $updated : $task;
    }
    finally {
      $this->lock->release('xedc_storage_migration_' . $taskId);
    }
  }

  /**
   * @return array<string, mixed>|null
   *   Updated task.
   */
  public function pause(string $taskId): ?array {
    $task = $this->planner->find($taskId);
    if ($task === NULL) {
      return NULL;
    }
    return $this->planner->updateTask($taskId, [
      'status' => 'paused',
      'message' => '已暂停。没有删除源文件，也没有修改 URI。',
    ]);
  }

  /**
   * @return array<string, mixed>|null
   *   Updated task.
   */
  public function resume(string $taskId): ?array {
    $task = $this->planner->find($taskId);
    if ($task === NULL || (string) ($task['status'] ?? '') !== 'paused') {
      return $task;
    }
    return $this->planner->updateTask($taskId, [
      'status' => 'copying',
      'message' => '已继续。下一次复制会从剩余项目开始。',
    ]);
  }

  /**
   * @return array<string, mixed>
   *   Updated task.
   */
  public function rollback(string $taskId, bool $confirmed): array {
    if (!$confirmed) {
      return ['status' => 'blocked', 'message' => '回滚需要确认。回滚只删除本任务写入且校验匹配的远程对象。'];
    }
    $task = $this->requireTask($taskId);
    if (isset($task['error'])) {
      return $task;
    }
    $profile = $this->config->profile((string) ($task['target'] ?? ''));
    if ($profile === NULL) {
      return $this->blocked($task, '目标供应商配置不存在，没有删除任何对象。');
    }
    $resolved = $this->credentials->resolve($profile);
    if (empty($resolved['ok'])) {
      return $this->blocked($task, '没有凭证，没有删除任何对象。');
    }
    $failed = 0;
    foreach ($this->repository->byStatus($taskId, ['verified', 'copied']) as $row) {
      $key = (string) $row['object_key'];
      $hash = (string) $row['sha256'];
      try {
        $remote = $this->reader->hash($resolved['connection'], $key);
      }
      catch (\Throwable) {
        $remote = NULL;
      }
      if (!is_string($remote) || $hash === '' || !hash_equals($hash, $remote)) {
        $this->repository->update((int) $row['id'], [
          'status' => 'failed',
          'message' => '回滚前校验不一致，远程对象已保留。',
        ]);
        $failed++;
        continue;
      }
      $deleted = $this->writer->delete($resolved['connection'], $key, 'migration');
      $this->repository->update((int) $row['id'], [
        'status' => $deleted ? 'rolled_back' : 'failed',
        'message' => $deleted ? '已删除本任务写入的远程对象。本地文件仍在。' : '远程删除失败，对象已保留。',
      ]);
      if (!$deleted) {
        $failed++;
      }
    }
    $summary = $this->repository->summary($taskId);
    $updated = $this->planner->updateTask($taskId, [
      'status' => 'rolled_back',
      'copied' => $summary['verified'],
      'failed' => $failed,
      'verified' => FALSE,
      'source_retained' => TRUE,
      'uri_rewritten' => FALSE,
      'message' => '回滚结束。本地源文件没有删除，文件 URI 没有修改。',
    ]);
    $this->journal->add('info', '迁移回滚 ' . $taskId . ' 结束。');
    return is_array($updated) ? $updated : $task;
  }

  /**
   * @return array<string, mixed>
   *   Refusal. This does not delete files.
   */
  public function deleteSources(string $taskId, string $phrase): array {
    $blocked = $this->guard->sourceDeleteBlocked();
    if ($blocked !== NULL || $phrase !== 'DELETE LOCAL COPIES') {
      return [
        'status' => 'blocked',
        'message' => $blocked ?? '确认短语不正确。本地文件没有删除。',
        'source_retained' => TRUE,
        'uri_rewritten' => FALSE,
      ];
    }
    $task = $this->planner->find($taskId);
    if ($task === NULL || empty($task['verified'])) {
      return [
        'status' => 'blocked',
        'message' => '只有全部校验通过的任务才能删除本地副本。本次没有删除。',
        'source_retained' => TRUE,
        'uri_rewritten' => FALSE,
      ];
    }
    $roots = [];
    foreach (['public://', 'private://'] as $rootUri) {
      $real = $this->fileSystem->realpath($rootUri);
      if (is_string($real) && $real !== '') {
        $roots[] = rtrim($real, DIRECTORY_SEPARATOR);
      }
    }
    $deleted = 0;
    $skipped = 0;
    foreach ($this->repository->byStatus($taskId, ['verified']) as $row) {
      $fid = (int) $row['fid'];
      $uri = (string) $row['source_uri'];
      if ($this->guard->referenced($fid) || $this->uris->isRemote($uri)) {
        $skipped++;
        continue;
      }
      $real = $this->fileSystem->realpath($uri);
      $inside = FALSE;
      if (is_string($real)) {
        foreach ($roots as $root) {
          if ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            $inside = TRUE;
          }
        }
      }
      $shares = (int) $this->database->select('file_managed', 'f')
        ->condition('uri', $uri)
        ->countQuery()
        ->execute()
        ->fetchField();
      if (!$inside || !is_string($real) || !is_file($real) || is_link($real) || $shares !== 1 || !unlink($real)) {
        $skipped++;
        continue;
      }
      $deleted++;
    }
    return [
      'status' => $deleted > 0 ? 'sources_deleted' : 'blocked',
      'message' => '本地副本删除结束：删除 ' . $deleted . ' 个，跳过 ' . $skipped . ' 个。被引用的文件已跳过。',
      'source_retained' => $deleted === 0,
      'uri_rewritten' => FALSE,
    ];
  }

  /**
   * Checks whether each inventoried key already exists. It does not upload or delete.
   *
   * @return array<string, mixed>
   *   Updated task.
   */
  public function probeTargets(string $taskId): array {
    $task = $this->requireTask($taskId);
    if (isset($task['error'])) {
      return $task;
    }
    if (!in_array((string) ($task['status'] ?? ''), ['inventoried', 'probed'], TRUE)) {
      return $this->blocked($task, '请先生成清单，再检查目标对象。');
    }
    $profile = $this->config->profile((string) ($task['target'] ?? ''));
    if ($profile === NULL) {
      return $this->blocked($task, '目标供应商配置不存在，没有连接 Bucket。');
    }
    $resolved = $this->credentials->resolve($profile);
    if (empty($resolved['ok']) || !is_array($resolved['connection'] ?? NULL)) {
      return $this->blocked($task, '目标凭证不可用，没有检查对象，也没有复制。');
    }
    if (!$this->lock->acquire('xedc_storage_migration_' . $taskId, 300.0)) {
      return $this->blocked($task, '这个迁移任务正在运行。');
    }
    $connection = $resolved['connection'];
    $stats = ['absent' => 0, 'match' => 0, 'differ' => 0, 'skipped' => 0];
    try {
      foreach ($this->repository->all($taskId) as $row) {
        $this->probeOne($connection, $row, $stats);
      }
      $updated = $this->planner->updateTask($taskId, [
        'status' => 'probed',
        'message' => '目标对象已逐个检查。没有复制，没有覆盖，没有修改 URI，没有删除源文件。',
        'remote' => $stats,
        'copied' => 0,
        'verified' => FALSE,
        'source_retained' => TRUE,
        'uri_rewritten' => FALSE,
        'copy_enabled' => FALSE,
        'finished' => 0,
      ]);
      $this->journal->add('info', '迁移目标检查 ' . $taskId . '：不存在 ' . $stats['absent'] . '，一致 ' . $stats['match'] . '，不一致 ' . $stats['differ'] . '。');
      return is_array($updated) ? $updated : $task;
    }
    finally {
      $this->lock->release('xedc_storage_migration_' . $taskId);
    }
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   * @param array<string, mixed> $row
   *   Inventory row.
   * @param array<string, int> $stats
   *   Mutable counters.
   */
  private function probeOne(array $connection, array $row, array &$stats): void {
    $id = (int) $row['id'];
    $key = (string) $row['object_key'];
    $scheme = (string) $row['scheme'];
    if ((string) $row['status'] === 'missing' || $key === '') {
      $stats['skipped']++;
      return;
    }
    $folders = $this->uris->folders();
    $folderName = $scheme === 'private' ? (string) $folders['private'] : (string) $folders['public'];
    $folder = ($folders['root'] !== '' ? $folders['root'] . '/' : '') . trim($folderName, '/') . '/';
    if ($scheme !== 'public' && $scheme !== 'private') {
      $this->repository->update($id, ['status' => 'failed', 'message' => '来源不是 public 或 private，没有上传。']);
      $stats['differ']++;
      return;
    }
    if ($folder === '/' || !str_starts_with($key, $folder)) {
      $this->repository->update($id, ['status' => 'failed', 'message' => '对象键和来源协议不一致，没有上传。']);
      $stats['differ']++;
      return;
    }
    try {
      $head = $this->reader->head($connection, $key);
    }
    catch (\Throwable $error) {
      $this->repository->update($id, [
        'status' => 'failed',
        'message' => '检查目标对象失败，没有上传：' . BootGuard::safeError($error, [
          (string) ($connection['access_key'] ?? ''),
          (string) ($connection['secret_key'] ?? ''),
        ]),
      ]);
      $stats['differ']++;
      return;
    }
    if ($head === NULL) {
      $this->repository->update($id, [
        'status' => 'pending',
        'remote_sha256' => '',
        'message' => '目标对象不存在。未复制，源文件仍在本地。',
      ]);
      $stats['absent']++;
      return;
    }
    $localHash = (string) $row['sha256'];
    $bytes = (int) $row['bytes'];
    if ($localHash === '' || (int) $head['bytes'] !== $bytes || $bytes > 33554432) {
      $this->repository->update($id, [
        'status' => 'remote_differ',
        'message' => '目标对象已存在，但大小或本地哈希无法比对，没有覆盖。',
      ]);
      $stats['differ']++;
      return;
    }
    $remote = $this->reader->hash($connection, $key);
    if (is_string($remote) && hash_equals($localHash, $remote)) {
      $this->repository->update($id, [
        'status' => 'remote_match',
        'remote_sha256' => $remote,
        'message' => '目标对象已存在且 SHA-256 一致。本次没有覆盖，也没有改 URI。',
      ]);
      $stats['match']++;
      return;
    }
    $this->repository->update($id, [
      'status' => 'remote_differ',
      'remote_sha256' => is_string($remote) ? $remote : '',
      'message' => '目标对象已存在但内容不同，没有覆盖。',
    ]);
    $stats['differ']++;
  }

  public function report(string $taskId): string {
    $lines = ["fid,scheme,kind,bytes,status,sha256,object_key,source_uri,message"];
    if (!$this->repository->ready()) {
      return implode("\n", $lines) . "\n";
    }
    foreach ($this->repository->all($taskId) as $row) {
      $cells = [
        (string) $row['fid'],
        (string) $row['scheme'],
        (string) $row['kind'],
        (string) $row['bytes'],
        (string) $row['status'],
        (string) $row['sha256'],
        (string) $row['object_key'],
        (string) $row['source_uri'],
        (string) $row['message'],
      ];
      $lines[] = implode(',', array_map([$this, 'csv'], $cells));
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * @param array<string, mixed> $connection
   *   Target connection.
   * @param array<string, mixed> $row
   *   Inventory row.
   */
  private function copyOne(array $connection, array $row): void {
    $id = (int) $row['id'];
    $uri = (string) $row['source_uri'];
    $key = (string) $row['object_key'];
    if ($key === '') {
      $this->repository->update($id, ['status' => 'failed', 'message' => '无法生成对象键，源文件未改动。']);
      return;
    }
    $real = $this->fileSystem->realpath($uri);
    if (!is_string($real) || !is_file($real) || is_link($real)) {
      $this->repository->update($id, ['status' => 'missing', 'message' => '复制前源文件已不在本地，没有上传。']);
      return;
    }
    $before = filesize($real);
    $hash = hash_file('sha256', $real);
    if ($before === FALSE || !is_string($hash)) {
      $this->repository->update($id, ['status' => 'failed', 'message' => '无法计算源文件 SHA-256，没有上传。']);
      return;
    }
    if ($this->uris->scheme($uri) === 'private' && $this->privateExposed() !== FALSE) {
      $this->repository->update($id, [
        'status' => 'failed',
        'message' => 'CDN 检查尚未证明私有前缀不可公开读取，私有文件没有复制。',
      ]);
      return;
    }
    $mime = (string) ($this->database->select('file_managed', 'f')->fields('f', ['filemime'])->condition('fid', (int) $row['fid'])->execute()->fetchField() ?: 'application/octet-stream');
    $result = $this->writer->putFile($connection, $real, $key, [
      'mime' => $mime,
      'purpose' => 'migration',
      'overwrite' => FALSE,
      'timeout' => 120,
      'cache_control' => CachePolicy::forObject($key, $this->uris->isPrivate($uri)),
    ]);
    $after = filesize($real);
    if ($after !== $before) {
      $this->repository->update($id, ['status' => 'failed', 'message' => '源文件在复制期间发生变化，没有修改 URI。']);
      return;
    }
    if (empty($result['ok']) || !hash_equals($hash, (string) $result['sha256'])) {
      $this->repository->update($id, [
        'status' => 'failed',
        'sha256' => $hash,
        'message' => substr((string) ($result['message'] ?? '复制失败，源文件已保留。'), 0, 255),
      ]);
      return;
    }
    $this->repository->update($id, [
      'status' => 'verified',
      'sha256' => $hash,
      'remote_sha256' => (string) $result['sha256'],
      'bytes' => (int) $before,
      'message' => '已复制并校验。本地文件仍在，URI 未修改。',
    ]);
  }

  /**
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   File query.
   */
  private function applySource($query, string $source): void {
    if ($source === 'public') {
      $query->condition('uri', 'public://%', 'LIKE');
    }
    elseif ($source === 'private') {
      $query->condition('uri', 'private://%', 'LIKE');
    }
    elseif ($source === 's3') {
      $query->condition('uri', 's3://%', 'LIKE');
    }
    else {
      $group = $query->orConditionGroup()
        ->condition('uri', 'public://%', 'LIKE')
        ->condition('uri', 'private://%', 'LIKE');
      $query->condition($group);
    }
  }

  /**
   * @param array<string, mixed> $task
   *   Task row.
   *
   * @return array<string, mixed>
   *   Blocked task.
   */
  private function blocked(array $task, string $message): array {
    $task['status'] = 'blocked';
    $task['message'] = $message;
    return $task;
  }

  /**
   * @return array<string, mixed>
   *   Task or an error row.
   */
  private function requireTask(string $taskId): array {
    $task = $this->planner->find($taskId);
    if ($task === NULL) {
      return ['status' => 'blocked', 'message' => '迁移任务不存在。', 'error' => TRUE];
    }
    if ((string) ($task['status'] ?? '') === 'cancelled') {
      return ['status' => 'blocked', 'message' => '任务已取消。', 'error' => TRUE];
    }
    return $task;
  }

  private function privateExposed(): ?bool {
    $stored = $this->journal->all()['cdn'] ?? [];
    if (!is_array($stored)) {
      return NULL;
    }
    $latest = NULL;
    $latestTime = -1;
    foreach ($stored as $row) {
      if (!is_array($row) || !array_key_exists('private_exposed', $row) || $row['private_exposed'] === NULL) {
        continue;
      }
      $checked = (int) ($row['checked'] ?? 0);
      if ($latest === NULL || $checked >= $latestTime) {
        $latest = $row;
        $latestTime = $checked;
      }
    }
    if ($latest === NULL) {
      return NULL;
    }
    return $latest['private_exposed'] === TRUE || $latest['private_exposed'] === 1 || $latest['private_exposed'] === '1';
  }

  private function csv(string $value): string {
    if (preg_match('/^[=+\-@]/', $value)) {
      $value = "'" . $value;
    }
    return '"' . str_replace('"', '""', $value) . '"';
  }

}
