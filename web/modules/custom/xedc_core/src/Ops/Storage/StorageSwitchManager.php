<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Copies missing public originals and then updates the shared runtime file.
 *
 * It does not delete sources, R2 objects, private files, or image styles.
 */
final class StorageSwitchManager {

  public function __construct(
    private StorageConfig $config,
    private CredentialVault $credentials,
    private RemoteFileReader $reader,
    private RemoteFileWriter $writer,
    private StorageJournal $journal,
    private Connection $database,
    private StateInterface $state,
  ) {}

  /**
   * @return array<string, mixed>
   *   Size comparison. This does not copy or switch.
   */
  public function inspect(): array {
    $current = RuntimeState::read($this->privatePath());
    $report = $this->compare();
    $report['current_mode'] = (string) $current['public_mode'];
    $report['current_provider'] = (string) $current['public_provider'];
    $report['version'] = (int) $current['version'];
    $report['writes_paused'] = !empty($current['writes_paused']);
    $report['locked'] = RuntimeState::locked($this->privatePath());
    $token = bin2hex(random_bytes(8));
    $this->state->set('xedc_storage.switch_check', [
      'token' => $token,
      'expires' => time() + 900,
      'report' => $report,
    ]);
    $report['token'] = $token;
    return $report;
  }

  /**
   * @return array<string, mixed>
   *   Switch result. Production mode changes only after the confirmation phrase.
   */
  public function execute(string $target, string $phrase, string $token, int $uid): array {
    if ($phrase !== RuntimeState::CONFIRMATION) {
      return $this->fail('confirmation', '确认文字不正确，没有切换。');
    }
    if (!in_array($target, ['local', 'silly'], TRUE)) {
      return $this->fail('target', '只能在本地文件系统和 Cloudflare R2 之间切换。');
    }
    $saved = $this->state->get('xedc_storage.switch_check', []);
    if (!is_array($saved) || ($saved['token'] ?? '') !== $token || (int) ($saved['expires'] ?? 0) < time()) {
      return $this->fail('check', '检查结果已过期。请重新检查。');
    }
    $private = $this->privatePath();
    if (!RuntimeState::acquire($private)) {
      return $this->fail('lock', '已有切换正在执行。');
    }
    $before = RuntimeState::read($private);
    $task = 'sw_' . gmdate('Ymd\THis\Z') . '_' . bin2hex(random_bytes(3));
    try {
      $targetMode = $target === 'local' ? 'local' : 'cloudflare_r2';
      if ((string) $before['public_mode'] === $targetMode && ($targetMode === 'local' || (string) $before['public_provider'] === 'silly')) {
        return $this->fail('same', '目标就是当前生产供应商，没有重复切换。');
      }
      $fresh = $this->compare();
      if ($fresh['required_missing_both'] > 0) {
        return $this->fail('missing', '有公开文件在本地和 R2 都不存在，已停止。');
      }
      $paused = $before;
      $paused['writes_paused'] = TRUE;
      $paused['switch_task'] = $task;
      $paused['status'] = 'switching';
      $paused['updated'] = time();
      if (!RuntimeState::write($private, $paused)) {
        return $this->fail('state', '没能暂停写入，没有切换。');
      }
      $copied = $this->copyMissing($targetMode, $fresh['missing_on_target']);
      if ($copied['failed'] !== []) {
        $this->restore($private, $before, $task, '复制失败，已恢复原运行状态。');
        return $this->fail('copy', '有文件没有复制成功，已回滚运行状态。失败 ' . count($copied['failed']) . ' 个。', $task);
      }
      $verified = $this->verifyCopied($copied['copied']);
      if ($verified !== []) {
        $this->restore($private, $before, $task, '校验失败，已恢复原运行状态。');
        return $this->fail('hash', 'SHA-256 不一致，已回滚运行状态。', $task);
      }
      $next = $before;
      $next['version'] = (int) $before['version'] + 1;
      $next['public_provider'] = $target === 'local' ? 'local' : 'silly';
      $next['public_mode'] = $targetMode;
      $next['cdn_enabled'] = $targetMode === 'cloudflare_r2';
      $next['writes_paused'] = FALSE;
      $next['switch_task'] = '';
      $next['previous_provider'] = (string) $before['public_provider'];
      $next['previous_mode'] = (string) $before['public_mode'];
      $next['updated'] = time();
      $next['status'] = 'active';
      $next['summary'] = '公开存储已从 ' . (string) $before['public_mode'] . ' 切到 ' . $targetMode . '。源文件未删除。';
      if (!RuntimeState::write($private, $next)) {
        $this->restore($private, $before, $task, '新状态没有写入，已恢复。');
        return $this->fail('state', '新状态没有写入，已回滚。', $task);
      }
      $active = RuntimeState::usesObjectStorage();
      if (($targetMode === 'cloudflare_r2') !== $active) {
        $this->restore($private, $before, $task, '切换后读写目标与状态不一致，已恢复。');
        return $this->fail('runtime', '运行状态和实际读写不一致，已回滚。', $task);
      }
      $this->record($uid, $before, $next, $task, 'switched', count($copied['copied']), '');
      $this->state->delete('xedc_storage.switch_check');
      return [
        'ok' => TRUE,
        'status' => 'switched',
        'task' => $task,
        'copied' => count($copied['copied']),
        'message' => $next['summary'],
      ];
    }
    finally {
      $current = RuntimeState::read($private);
      if (!empty($current['writes_paused'])) {
        $current['writes_paused'] = FALSE;
        $current['updated'] = time();
        RuntimeState::write($private, $current);
      }
      RuntimeState::release();
    }
  }

  /**
   * Copies one test object in either direction and checks SHA-256.
   *
   * @return array<string, mixed>
   *   Result for the dedicated test prefix only.
   */
  public function exerciseTestPrefix(): array {
    $prefix = 's3fs-public/' . BootGuard::TEST_PREFIX . 'switch/';
    $name = 'switch-' . bin2hex(random_bytes(4)) . '.txt';
    $key = $prefix . $name;
    $body = 'xedc-switch-test-' . $name;
    $public = $this->publicPath();
    $relative = BootGuard::TEST_PREFIX . 'switch/' . $name;
    $local = $public . '/' . $relative;
    if (!is_dir(dirname($local)) && !mkdir(dirname($local), 0775, TRUE) && !is_dir(dirname($local))) {
      return $this->fail('local', '测试目录没有创建。');
    }
    file_put_contents($local, $body);
    $connection = $this->connection();
    if ($connection === NULL) {
      @unlink($local);
      return $this->fail('credentials', 'R2 凭证不可用，没有写入正式前缀。');
    }
    $uploaded = $this->writer->putFile($connection, $local, $key, ['purpose' => 'test', 'mime' => 'text/plain']);
    $remoteHash = $this->reader->hash($connection, $key);
    $localHash = hash_file('sha256', $local);
    $temp = $this->reader->copyToTemp($connection, $key);
    $tempHash = is_file($temp) ? hash_file('sha256', $temp) : '';
    @unlink($temp);
    $deleted = $this->writer->delete($connection, $key, 'test');
    @unlink($local);
    $ok = ($uploaded['ok'] ?? FALSE) && is_string($remoteHash) && is_string($localHash) && hash_equals($localHash, $remoteHash) && $tempHash === $localHash && $deleted;
    return [
      'ok' => $ok,
      'status' => $ok ? 'verified' : 'failed',
      'message' => $ok ? '测试前缀完成了本地与 R2 的复制和 SHA-256 校验，并已删除测试对象。' : '测试前缀校验没有通过。正式对象没有删除。',
      'key' => $key,
    ];
  }

  /**
   * @return array<string, mixed>
   *   Required public originals and where they exist.
   */
  public function compare(): array {
    $public = $this->publicPath();
    $folder = 's3fs-public';
    $objects = $this->remoteSizes();
    $rows = $this->database->query("SELECT fid, uri, filesize FROM {file_managed} WHERE uri LIKE :public AND uri NOT LIKE :styles", [
      ':public' => 'public://%',
      ':styles' => 'public://styles/%',
    ])->fetchAll();
    $required = 0;
    $both = 0;
    $localOnly = 0;
    $remoteOnly = 0;
    $missingBoth = 0;
    $sizeMismatch = 0;
    $missingOnLocal = [];
    $missingOnRemote = [];
    $seen = [];
    foreach ($rows as $row) {
      $target = substr((string) $row->uri, strlen('public://'));
      if ($target === '' || str_contains($target, '..') || str_starts_with($target, 'styles/')) {
        continue;
      }
      $required++;
      $key = $folder . '/' . $target;
      $seen[$key] = TRUE;
      $localFile = $public . '/' . $target;
      $localBytes = is_file($localFile) && !is_link($localFile) ? filesize($localFile) : FALSE;
      $remoteBytes = $objects[$key] ?? NULL;
      if ($localBytes === FALSE && $remoteBytes === NULL) {
        $missingBoth++;
        continue;
      }
      if ($localBytes === FALSE) {
        $localOnly++;
        $missingOnLocal[] = ['key' => $key, 'fid' => (int) $row->fid, 'bytes' => (int) $remoteBytes];
        continue;
      }
      if ($remoteBytes === NULL) {
        $remoteOnly++;
        $missingOnRemote[] = ['key' => $key, 'fid' => (int) $row->fid, 'bytes' => (int) $localBytes, 'path' => $localFile];
        continue;
      }
      if ((int) $localBytes !== (int) $remoteBytes) {
        $sizeMismatch++;
        continue;
      }
      $both++;
    }
    $extraRemote = 0;
    foreach ($objects as $key => $bytes) {
      if (!isset($seen[$key]) && !str_contains($key, '/styles/')) {
        $extraRemote++;
      }
    }
    return [
      'ok' => TRUE,
      'required' => $required,
      'both' => $both,
      'local_only' => $remoteOnly,
      'remote_only' => $localOnly,
      'required_missing_both' => $missingBoth,
      'size_mismatch' => $sizeMismatch,
      'extra_remote' => $extraRemote,
      'remote_objects' => count($objects),
      'missing_on_target' => [],
      'missing_on_local' => array_slice($missingOnLocal, 0, 20),
      'missing_on_remote' => array_slice($missingOnRemote, 0, 20),
      'missing_on_local_all' => $missingOnLocal,
      'missing_on_remote_all' => $missingOnRemote,
    ];
  }

  /**
   * @param list<array<string, mixed>> $items
   *   Files copied during this task.
   *
   * @return list<string>
   *   Keys whose SHA-256 did not match.
   */
  private function verifyCopied(array $items): array {
    $failed = [];
    $connection = $this->connection();
    foreach ($items as $item) {
      $path = (string) ($item['path'] ?? '');
      $key = (string) ($item['key'] ?? '');
      if ($path === '' || $key === '' || !is_file($path)) {
        $failed[] = $key;
        continue;
      }
      $local = hash_file('sha256', $path);
      $remote = $connection === NULL ? NULL : $this->reader->hash($connection, $key);
      if (!is_string($local) || !is_string($remote) || !hash_equals($local, $remote)) {
        $failed[] = $key;
      }
    }
    return $failed;
  }

  /**
   * @param list<array<string, mixed>> $localMissing
   *   Unused here. The direction selects the source list.
   * @return array{copied: list<array<string, mixed>>, failed: list<string>}
   *   Copy result.
   */
  private function copyMissing(string $targetMode, array $localMissing): array {
    $report = $this->compare();
    $items = $targetMode === 'local' ? $report['missing_on_local_all'] : $report['missing_on_remote_all'];
    $copied = [];
    $failed = [];
    $connection = $this->connection();
    if ($items !== [] && $connection === NULL) {
      return ['copied' => [], 'failed' => ['credentials']];
    }
    foreach ($items as $item) {
      $key = (string) $item['key'];
      if (!str_starts_with($key, 's3fs-public/') || str_contains($key, '/styles/') || str_contains($key, '..')) {
        $failed[] = $key;
        continue;
      }
      $relative = substr($key, strlen('s3fs-public/'));
      $path = $this->publicPath() . '/' . $relative;
      if ($targetMode === 'local') {
        if (!$this->download($connection, $key, $path)) {
          $failed[] = $key;
          continue;
        }
      }
      else {
        $result = $this->writer->putFile($connection, $path, $key, ['purpose' => 'migration', 'mime' => $this->mime($path)]);
        if (empty($result['ok'])) {
          $failed[] = $key;
          continue;
        }
      }
      $copied[] = ['key' => $key, 'path' => $path];
    }
    return ['copied' => $copied, 'failed' => $failed];
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   */
  private function download(array $connection, string $key, string $path): bool {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      return FALSE;
    }
    if (is_link($path) || (is_file($path) && filesize($path) !== 0)) {
      return is_file($path);
    }
    try {
      $temp = $this->reader->copyToTemp($connection, $key);
    }
    catch (\Throwable) {
      return FALSE;
    }
    $moved = rename($temp, $path);
    if (!$moved) {
      @unlink($temp);
      return FALSE;
    }
    @chmod($path, 0644);
    return TRUE;
  }

  /**
   * @param array<string, mixed> $before
   *   Previous document.
   */
  private function restore(?string $private, array $before, string $task, string $summary): void {
    $before['writes_paused'] = FALSE;
    $before['switch_task'] = '';
    $before['status'] = 'rolled_back';
    $before['summary'] = $summary;
    $before['updated'] = time();
    RuntimeState::write($private, $before);
    $this->record(0, $before, $before, $task, 'rolled_back', 0, $summary);
  }

  /**
   * @param array<string, mixed> $before
   *   Previous document.
   * @param array<string, mixed> $after
   *   New document.
   */
  private function record(int $uid, array $before, array $after, string $task, string $status, int $copied, string $error): void {
    $line = [
      'uid' => $uid,
      'task' => $task,
      'from' => (string) ($before['public_mode'] ?? ''),
      'to' => (string) ($after['public_mode'] ?? ''),
      'copied' => $copied,
      'status' => $status,
      'error' => $error,
    ];
    RuntimeState::audit($this->privatePath(), $line);
    $this->journal->add($status === 'switched' ? 'info' : 'error', '存储切换 ' . $task . ' ' . $status . '，复制 ' . $copied . ' 个。' . $error);
  }

  /**
   * @return array<string, int>
   *   Object key to size. Styles are omitted.
   */
  private function remoteSizes(): array {
    $connection = $this->connection();
    if ($connection === NULL) {
      return [];
    }
    $client = S3ClientFactory::create($connection);
    $bucket = (string) $connection['bucket'];
    unset($connection);
    $token = NULL;
    $sizes = [];
    do {
      $args = ['Bucket' => $bucket, 'Prefix' => 's3fs-public/', 'MaxKeys' => 1000];
      if ($token !== NULL) {
        $args['ContinuationToken'] = $token;
      }
      $page = $client->listObjectsV2($args);
      foreach ($page['Contents'] ?? [] as $object) {
        $key = (string) $object['Key'];
        if (str_contains($key, '/styles/')) {
          continue;
        }
        $sizes[$key] = (int) $object['Size'];
      }
      $token = !empty($page['IsTruncated']) ? (string) $page['NextContinuationToken'] : NULL;
    }
    while ($token !== NULL);
    return $sizes;
  }

  /**
   * @return array<string, mixed>|null
   *   Connection, or NULL when it cannot be resolved.
   */
  private function connection(): ?array {
    $profile = $this->config->profile('silly');
    if ($profile === NULL) {
      return NULL;
    }
    $resolved = $this->credentials->resolve($profile);
    if (empty($resolved['ok']) || !is_array($resolved['connection'] ?? NULL)) {
      return NULL;
    }
    return $resolved['connection'];
  }

  private function privatePath(): ?string {
    $path = Settings::get('file_private_path');
    return is_string($path) && $path !== '' ? $path : NULL;
  }

  private function mime(string $path): string {
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
      'jpg', 'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'gif' => 'image/gif',
      'webp' => 'image/webp',
      'avif' => 'image/avif',
      'mp4' => 'video/mp4',
      'webm' => 'video/webm',
      default => 'application/octet-stream',
    };
  }

  private function publicPath(): string {
    $path = \Drupal\Core\StreamWrapper\PublicStream::basePath();
    if (!str_starts_with($path, '/')) {
      $path = DRUPAL_ROOT . '/' . ltrim($path, '/');
    }
    return rtrim($path, '/');
  }

  /**
   * @return array<string, mixed>
   *   Failed result.
   */
  private function fail(string $status, string $message, string $task = ''): array {
    return ['ok' => FALSE, 'status' => $status, 'task' => $task, 'message' => $message];
  }

}
