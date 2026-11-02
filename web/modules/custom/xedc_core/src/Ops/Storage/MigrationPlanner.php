<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;

/**
 * Plans a migration without copying files or rewriting URIs.
 */
final class MigrationPlanner {

  public function __construct(
    protected Connection $database,
    protected FileSystemInterface $fileSystem,
    protected StorageConfig $config,
    protected StorageJournal $journal,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    protected CredentialVault $credentials,
  ) {}

  /**
   * @return array<string, mixed>
   *   The stored task.
   */
  public function precheck(string $source, string $targetProfile): array {
    if (!$this->lock->acquire('xedc_storage_precheck', 30.0)) {
      return $this->task('blocked', $source, $targetProfile, '已有预检查在进行。', []);
    }
    try {
      $target = $targetProfile === '' ? NULL : $this->config->profile($targetProfile);
      $warnings = [];
      $blockers = [];
      if ($target === NULL) {
        $blockers[] = '需要选择目标供应商配置。';
      }
      if (!in_array($source, ['public', 'private', 'local', 's3'], TRUE)) {
        $blockers[] = '迁移来源无效。';
      }
      if ((bool) Settings::get('s3fs.use_s3_for_public', FALSE) || (bool) Settings::get('s3fs.use_s3_for_private', FALSE)) {
        $warnings[] = '运行时已经接管 public:// 或 private://。预检查不会回滚这个开关。';
      }
      $counts = $this->counts($source);
      if ($target) {
        $resolved = $this->credentials->resolve($target);
        unset($resolved['connection']);
        if ($resolved['missing'] !== []) {
          $warnings[] = '目标凭证尚未配置，预检查没有连接目标 Bucket。';
        }
        if ($resolved['errors'] !== []) {
          $blockers[] = implode(' ', $resolved['errors']);
        }
        $runtimeBucket = (string) Settings::get('xedc_storage.bucket', '');
        if ($source === 's3' && $runtimeBucket !== '' && $resolved['bucket'] === $runtimeBucket && (string) $target['endpoint'] === (string) Settings::get('xedc_storage.endpoint', '')) {
          $blockers[] = '源和目标看起来是同一个 Bucket，不能把预检查当成迁移完成。';
        }
      }
      if ($source === 's3' && $counts['files'] > 0 && !(bool) Settings::get('xedc_storage.runtime_applied', FALSE)) {
        $warnings[] = '存在 s3:// 文件，但运行时连接没有启用，不能判断它们当前指向哪个 Bucket。';
      }
      if ($source !== 's3') {
        $warnings[] = '临时文件不纳入迁移。本地源文件会保留，这次没有复制。';
      }
      $sample = $this->sample($source);
      $missing = 0;
      foreach ($sample as $row) {
        if ($row['exists'] === '缺失') {
          $missing++;
        }
      }
      if ($missing > 0) {
        $warnings[] = '抽样中有 ' . $missing . ' 个本地文件没有物理路径。这不是完整扫描。';
      }
      $status = $blockers === [] ? 'prechecked' : 'blocked';
      $message = $status === 'prechecked'
        ? '预检查完成。没有复制文件，没有修改 URI，没有删除源文件。'
        : implode(' ', $blockers);
      return $this->task($status, $source, $targetProfile, $message, [
        'counts' => $counts,
        'sample' => $sample,
        'warnings' => $warnings,
        'blockers' => $blockers,
        'target_bucket' => $target['bucket'] ?? '',
        'target_endpoint' => $target ? (string) (parse_url((string) $target['endpoint'], PHP_URL_HOST) ?: '') : '',
      ]);
    }
    finally {
      $this->lock->release('xedc_storage_precheck');
    }
  }

  public function find(string $taskId): ?array {
    foreach ($this->tasks() as $task) {
      if (($task['id'] ?? '') === $taskId) {
        return $task;
      }
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $fields
   *   Public fields to replace.
   *
   * @return array<string, mixed>|null
   *   Updated task.
   */
  public function updateTask(string $taskId, array $fields): ?array {
    $tasks = $this->tasks();
    foreach ($tasks as &$task) {
      if (($task['id'] ?? '') !== $taskId) {
        continue;
      }
      foreach ($fields as $key => $value) {
        if (in_array($key, ['uri_rewritten'], TRUE) && $value === TRUE) {
          continue;
        }
        $task[$key] = $value;
      }
      $task['uri_rewritten'] = FALSE;
      $task['source_retained'] = TRUE;
      $this->save($tasks);
      return $task;
    }
    return NULL;
  }

  public function cancel(string $taskId): ?string {
    $tasks = $this->tasks();
    foreach ($tasks as &$task) {
      if ($task['id'] !== $taskId) {
        continue;
      }
      if (in_array($task['status'], ['cancelled', 'verified'], TRUE)) {
        return '这个任务不能再次取消。';
      }
      $task['status'] = 'cancelled';
      $task['finished'] = $this->time->getRequestTime();
      $task['message'] = '任务已取消。没有复制或删除文件。';
      $this->journal->add('info', '迁移任务 ' . $taskId . ' 已取消。');
      $this->save($tasks);
      return NULL;
    }
    return '迁移任务不存在。';
  }

  /**
   * @return list<array<string, mixed>>
   *   Newest tasks first.
   */
  public function recent(int $limit = 10): array {
    return array_slice($this->tasks(), 0, $limit);
  }

  /**
   * @param array<string, mixed> $extra
   *   Public task fields.
   *
   * @return array<string, mixed>
   *   Stored task.
   */
  private function task(string $status, string $source, string $target, string $message, array $extra): array {
    $task = $extra + [
      'id' => 'mst_' . bin2hex(random_bytes(4)),
      'status' => $status,
      'source' => $source,
      'target' => $target,
      'message' => $message,
      'created' => $this->time->getRequestTime(),
      'finished' => $status === 'prechecked' || $status === 'blocked' ? $this->time->getRequestTime() : 0,
      'copied' => 0,
      'failed' => 0,
      'verified' => FALSE,
      'source_retained' => TRUE,
      'uri_rewritten' => FALSE,
      'copy_enabled' => FALSE,
    ];
    $tasks = $this->tasks();
    array_unshift($tasks, $task);
    $this->save(array_slice($tasks, 0, 20));
    $this->journal->add($status === 'prechecked' ? 'info' : 'error', '迁移预检查 ' . $task['id'] . '：' . $message);
    return $task;
  }

  /**
   * @return list<array<string, mixed>>
   *   Stored tasks.
   */
  private function tasks(): array {
    $tasks = $this->journal->all()['tasks'] ?? [];
    return is_array($tasks) ? array_values($tasks) : [];
  }

  /**
   * @param list<array<string, mixed>> $tasks
   *   Tasks to store.
   */
  private function save(array $tasks): void {
    $this->journal->put('tasks', $tasks);
  }

  /**
   * @return array<string, int|string>
   *   Aggregate counts.
   */
  private function counts(string $source): array {
    $condition = $this->condition($source);
    $sql = "SELECT COUNT(*) AS files, COALESCE(SUM(filesize), 0) AS bytes FROM {file_managed} WHERE $condition";
    $row = $this->database->query($sql)->fetchAssoc();
    $row = is_array($row) ? $row : [];
    return [
      'files' => (int) ($row['files'] ?? 0),
      'bytes' => (int) ($row['bytes'] ?? 0),
    ];
  }

  /**
   * @return list<array<string, string>>
   *   At most 20 sample rows.
   */
  private function sample(string $source): array {
    $condition = $this->condition($source);
    $rows = $this->database->query("SELECT fid, uri, filename, filesize, filemime FROM {file_managed} WHERE $condition ORDER BY fid DESC LIMIT 20")->fetchAllAssoc('fid');
    $sample = [];
    foreach ($rows as $row) {
      $uri = (string) $row->uri;
      $exists = '不适用';
      if (str_starts_with($uri, 'public://') || str_starts_with($uri, 'private://')) {
        $real = $this->fileSystem->realpath($uri);
        $exists = is_string($real) && is_file($real) ? '存在' : '缺失';
      }
      $sample[] = [
        'fid' => (string) $row->fid,
        'uri' => $uri,
        'filename' => (string) $row->filename,
        'bytes' => (string) $row->filesize,
        'mime' => (string) $row->filemime,
        'exists' => $exists,
      ];
    }
    return $sample;
  }

  private function condition(string $source): string {
    return match ($source) {
      'public' => "uri LIKE 'public://%'",
      'private' => "uri LIKE 'private://%'",
      's3' => "uri LIKE 's3://%'",
      default => "(uri LIKE 'public://%' OR uri LIKE 'private://%')",
    };
  }

}
