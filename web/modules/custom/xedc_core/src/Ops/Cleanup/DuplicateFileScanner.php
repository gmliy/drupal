<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;

/**
 * Groups managed files by size, then by streamed SHA-256.
 */
class DuplicateFileScanner {

  public function __construct(
    protected Connection $database,
    protected FileSystemInterface $fileSystem,
    protected CleanupRepository $repository,
    protected FilePathGuard $guard,
  ) {}

  /**
   * @param array<string, mixed> $batch
   *   Mutable batch.
   */
  public function enumerate(array &$batch, int $budget): bool {
    $batchId = (int) $batch['id'];
    if (empty($batch['state']['counted'])) {
      $batch['total'] = (int) $this->database->select('file_managed', 'f')->countQuery()->execute()->fetchField();
      $batch['state']['counted'] = 1;
    }
    $rows = $this->database->select('file_managed', 'f')
      ->fields('f', ['fid', 'uri', 'filename', 'filemime', 'filesize', 'status', 'created', 'changed'])
      ->condition('fid', (int) $batch['cursor_fid'], '>')
      ->orderBy('fid')
      ->range(0, max(1, $budget))
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    if ($rows === []) {
      return FALSE;
    }
    foreach ($rows as $row) {
      $fid = (int) $row['fid'];
      $uri = (string) $row['uri'];
      $kind = $this->guard->kindForUri($uri);
      $real = $this->fileSystem->realpath($uri);
      $info = is_string($real) ? $this->guard->describe($real) : NULL;
      if ($info !== NULL && $kind === 'content') {
        $kind = $this->guard->containedKind($info['path']);
      }
      $phase = 'pending';
      $message = '';
      if ($info === NULL) {
        $phase = 'skipped';
        $message = is_string($real) ? '文件不是可读取的普通文件，或路径超出允许范围。' : '物理文件不存在。';
        $batch['failures']++;
      }
      elseif ($kind !== 'content') {
        $phase = 'skipped';
        $message = '不参与重复内容比较。';
      }
      $path = $info['path'] ?? (is_string($real) ? $real : '');
      $key = hash('sha256', $fid . ':' . ($path !== '' ? $path : 'missing:' . $uri));
      $this->repository->upsertWork([
        'batch_id' => $batchId,
        'fid' => $fid,
        'path_key' => $key,
        'uri' => substr($uri, 0, 512),
        'path' => substr($path, 0, 512),
        'filename' => substr((string) $row['filename'], 0, 255),
        'bytes' => (int) ($info['bytes'] ?? $row['filesize'] ?? 0),
        'mime' => substr((string) $row['filemime'], 0, 128),
        'mtime' => (int) ($info['mtime'] ?? $row['changed'] ?? 0),
        'created' => (int) $row['created'],
        'inode' => (string) ($info['inode'] ?? ''),
        'sha256' => '',
        'phase' => $phase,
        'kind' => $kind,
        'message' => substr($message, 0, 255),
      ]);
      $batch['cursor_fid'] = $fid;
      $batch['processed']++;
    }
    $batch['message'] = '正在列出文件，已处理 ' . (int) $batch['processed'] . ' 个。';
    return count($rows) >= $budget;
  }

  /**
   * @param array<string, mixed> $batch
   *   Mutable batch.
   */
  public function hash(array &$batch, float $deadline, int $maxBytes): bool {
    if (empty($batch['state']['sizes_marked'])) {
      $this->repository->markUniqueSizes((int) $batch['id']);
      $batch['state']['sizes_marked'] = 1;
    }
    $rows = $this->repository->pendingWork((int) $batch['id'], 4);
    if ($rows === []) {
      return FALSE;
    }
    foreach ($rows as $row) {
      if (microtime(TRUE) >= $deadline) {
        return TRUE;
      }
      $path = (string) $row['path'];
      $bytes = (int) $row['bytes'];
      if ($bytes > $maxBytes) {
        $this->repository->updateWork((int) $row['id'], [
          'phase' => 'skipped_large',
          'message' => '文件超过单次哈希上限，未认定为重复或唯一。',
        ]);
        $batch['failures']++;
        continue;
      }
      $hash = $this->guard->hashFile($path, $maxBytes);
      if ($hash === NULL) {
        $this->repository->updateWork((int) $row['id'], [
          'phase' => 'failed',
          'message' => '哈希时文件不可读或内容发生变化。',
        ]);
        $batch['failures']++;
        continue;
      }
      $this->repository->updateWork((int) $row['id'], [
        'phase' => 'hashed',
        'sha256' => $hash,
        'message' => '',
      ]);
    }
    $batch['message'] = '正在计算 SHA-256。';
    return $this->repository->pendingWork((int) $batch['id'], 1) !== [];
  }

  /**
   * @param array<string, mixed> $batch
   *   Mutable batch.
   */
  public function group(array &$batch): void {
    $batchId = (int) $batch['id'];
    $this->repository->clearGroups($batchId);
    $now = $batch['changed'] ?? 0;
    foreach ($this->repository->duplicateHashes($batchId) as $hash) {
      $rows = $this->repository->workByHash($batchId, (string) $hash);
      if (count($rows) < 2) {
        continue;
      }
      $families = [];
      $members = [];
      $total = 0;
      foreach ($rows as $row) {
        $family = DuplicateMath::family((string) $row['mime']);
        $families[$family] = TRUE;
        $total += (int) $row['bytes'];
        $members[] = [
          'batch_id' => $batchId,
          'fid' => (int) $row['fid'],
          'path_key' => (string) $row['path_key'],
          'uri' => (string) $row['uri'],
          'path' => (string) $row['path'],
          'filename' => (string) $row['filename'],
          'bytes' => (int) $row['bytes'],
          'mime' => (string) $row['mime'],
          'mtime' => (int) $row['mtime'],
          'inode' => (string) $row['inode'],
          'has_entity' => 1,
          'media_count' => 0,
          'ref_count' => 0,
          'ref_status' => 'pending',
          'risk' => 'high',
        ];
      }
      $inodes = [];
      foreach ($members as $member) {
        if ($member['inode'] !== '') {
          $inodes[$member['inode']] = TRUE;
        }
      }
      $this->repository->insertGroup([
        'batch_id' => $batchId,
        'sha256' => (string) $hash,
        'file_count' => count($members),
        'total_bytes' => $total,
        'reclaimable_bytes' => DuplicateMath::reclaimable($members),
        'inode_count' => count($inodes),
        'mime_family' => count($families) === 1 ? (string) array_key_first($families) : 'mixed',
        'risk' => 'high',
        'ref_status' => 'pending',
        'created' => (int) $now,
      ], $members);
    }
    $batch['message'] = '重复文件分组已更新。';
  }

}
