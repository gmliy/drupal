<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;

/**
 * Classifies managed files and records unmanaged files on disk.
 */
class UnusedFileScanner {

  /**
   * @var array<string, int>|null
   */
  private ?array $managedPaths = NULL;

  public function __construct(
    protected Connection $database,
    protected FileSystemInterface $fileSystem,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CleanupRepository $repository,
    protected FilePathGuard $guard,
  ) {}

  /**
   * @param array<string, mixed> $batch
   *   Mutable batch.
   */
  public function classify(array &$batch, float $deadline, int $maxBytes): bool {
    $batchId = (int) $batch['id'];
    $cursor = (int) ($batch['state']['classify_fid'] ?? 0);
    $incomplete = !empty($batch['state']['text_incomplete']) || !empty($batch['state']['reference_incomplete']);
    $rows = $this->repository->workAfter($batchId, $cursor, 30);
    if ($rows === []) {
      $this->refreshMembers($batchId);
      return FALSE;
    }
    foreach ($rows as $row) {
      if (microtime(TRUE) >= $deadline) {
        $batch['state']['classify_fid'] = $cursor;
        return TRUE;
      }
      $cursor = (int) $row['fid'];
      $this->repository->upsertFinding($this->classifyRow($batchId, $row, $incomplete, $maxBytes));
    }
    $batch['state']['classify_fid'] = $cursor;
    $batch['message'] = '正在分类文件引用。';
    return TRUE;
  }

  /**
   * @param array<string, mixed> $batch
   *   Mutable batch.
   */
  public function walk(array &$batch, float $deadline): bool {
    $roots = $this->guard->roots();
    $state = $batch['state'];
    if (!isset($state['walk_stack']) || !is_array($state['walk_stack'])) {
      $state['walk_stack'] = array_values($roots);
      $state['walk_dir'] = '';
      $state['walk_names'] = [];
    }
    $budget = 80;
    while ($budget > 0 && microtime(TRUE) < $deadline) {
      if ($state['walk_dir'] === '') {
        $next = array_pop($state['walk_stack']);
        if (!is_string($next)) {
          $batch['state'] = $state;
          return FALSE;
        }
        $kind = $this->guard->directoryKind($next);
        if (in_array($kind, ['aggregate_css', 'aggregate_js', 'derivative', 'protected', 'import', 'quarantine', 'outside'], TRUE)) {
          continue;
        }
        if (@lstat($next) === FALSE) {
          $batch['failures']++;
          continue;
        }
        $names = @scandir($next);
        if ($names === FALSE) {
          $batch['failures']++;
          continue;
        }
        $state['walk_dir'] = $next;
        $state['walk_names'] = array_values(array_diff($names, ['.', '..']));
      }
      $dir = (string) $state['walk_dir'];
      while ($budget > 0 && microtime(TRUE) < $deadline && $state['walk_names'] !== []) {
        $name = array_pop($state['walk_names']);
        $budget--;
        if (!is_string($name) || $name === '' || str_contains($name, '/') || str_contains($name, "\0")) {
          continue;
        }
        $full = $dir . '/' . $name;
        $stat = @lstat($full);
        if (!is_array($stat)) {
          $batch['failures']++;
          continue;
        }
        $type = $stat['mode'] & 0170000;
        if ($type === 0120000) {
          continue;
        }
        if ($type === 0040000) {
          $state['walk_stack'][] = $full;
          continue;
        }
        if ($type !== 0100000) {
          continue;
        }
        $info = $this->guard->describe($full);
        if ($info === NULL) {
          continue;
        }
        $kind = $this->guard->containedKind($info['path']);
        if ($kind !== 'content') {
          continue;
        }
        if (isset($this->managed()[$info['path']])) {
          continue;
        }
        $this->repository->upsertFinding([
          'batch_id' => (int) $batch['id'],
          'fid' => 0,
          'path_key' => hash('sha256', $info['path']),
          'uri' => '',
          'path' => substr($info['path'], 0, 512),
          'filename' => substr($name, 0, 255),
          'bytes' => (int) $info['bytes'],
          'mime' => '',
          'created' => 0,
          'changed' => (int) $info['mtime'],
          'sha256' => '',
          'category' => 'missing_entity',
          'reason' => '磁盘上有文件，但没有文件实体。只作报告，不能清理。',
          'ref_count' => 0,
          'ref_status' => 'unknown',
          'risk' => 'high',
          'origin' => 'disk',
        ]);
      }
      if ($state['walk_names'] === []) {
        $state['walk_dir'] = '';
      }
    }
    $batch['state'] = $state;
    $batch['message'] = '正在查找没有文件实体的物理文件。';
    return TRUE;
  }

  public function refreshMembers(int $batchId): void {
    $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
    foreach ($this->repository->groupIds($batchId) as $groupId) {
      $members = $this->repository->members($groupId);
      $worst = 'low';
      $status = 'complete';
      foreach ($members as $member) {
        $finding = $this->repository->findingByFid($batchId, (int) $member['fid']);
        $refs = $this->repository->refsForFid($batchId, (int) $member['fid']);
        $media = 0;
        foreach ($refs as $ref) {
          if ($ref['entity_type'] === 'media' && $ref['source'] === 'field') {
            $media++;
          }
        }
        $refStatus = (string) ($finding['ref_status'] ?? 'unknown');
        $risk = (string) ($finding['risk'] ?? 'high');
        if (($rank[$risk] ?? 3) > ($rank[$worst] ?? 1)) {
          $worst = $risk;
        }
        if ($refStatus !== 'confirmed_unused') {
          $status = $refStatus === 'referenced' && $status === 'complete' ? 'referenced' : ($refStatus === 'referenced' ? $status : 'unknown');
        }
        $this->repository->updateMember((int) $member['id'], [
          'media_count' => $media,
          'ref_count' => count($refs),
          'ref_status' => $refStatus,
          'risk' => $risk,
        ]);
      }
      $this->repository->updateGroup($groupId, [
        'risk' => $worst,
        'ref_status' => $status,
      ]);
    }
  }

  /**
   * @param array<string, mixed> $row
   *   Work row.
   *
   * @return array<string, mixed>
   *   Finding row.
   */
  private function classifyRow(int $batchId, array $row, bool $incomplete, int $maxBytes): array {
    $fid = (int) $row['fid'];
    $kind = (string) $row['kind'];
    $refs = $this->repository->refsForFid($batchId, $fid);
    $category = 'unknown';
    $reason = '引用关系未知。';
    $refStatus = 'unknown';
    $risk = 'high';
    $hash = (string) $row['sha256'];
    if (!$this->isContent($kind)) {
      $category = in_array($kind, ['aggregate_css', 'aggregate_js', 'derivative'], TRUE) ? 'cache' : 'protected';
      $reason = $this->kindReason($kind);
      $refStatus = 'protected';
    }
    elseif ($this->isContent($kind) && in_array((string) $row['phase'], ['skipped', 'failed'], TRUE) && !$this->regularFile((string) $row['path'])) {
      if ((string) $row['path'] !== '' && is_link((string) $row['path'])) {
        $category = 'protected';
        $reason = '符号链接不能当作未引用文件。';
        $refStatus = 'protected';
      }
      else {
        $category = 'missing_physical';
        $reason = '数据库有记录，物理文件不存在。';
        $refStatus = 'missing_physical';
      }
    }
    elseif ($incomplete) {
      $category = 'unknown';
      $reason = '本批引用分析没有完整完成，不能判断为未引用。';
    }
    else {
      $unknown = FALSE;
      $used = FALSE;
      $usage = FALSE;
      $field = FALSE;
      foreach ($refs as $ref) {
        if ((string) $ref['confidence'] === 'unknown' || ((int) $ref['migratable'] === 0 && $ref['source'] !== 'file_usage')) {
          $unknown = TRUE;
        }
        if ($ref['source'] === 'field' || $ref['source'] === 'custom' || ($ref['source'] === 'file_usage' && (int) $ref['migratable'] !== 1)) {
          $used = TRUE;
        }
        if ($ref['source'] === 'file_usage') {
          $usage = TRUE;
        }
        if ($ref['source'] === 'field') {
          $field = TRUE;
        }
      }
      if ($unknown) {
        $category = 'unknown';
        $reason = '存在不能确认或不能迁移的引用。';
      }
      elseif ($used) {
        $category = 'referenced';
        $reason = '文件仍被字段、模块或使用记录引用。';
        $refStatus = 'referenced';
        $risk = 'medium';
      }
      elseif ($usage && !$field) {
        $category = 'unknown';
        $reason = 'file_usage 有记录，但没有找到对应字段。';
      }
      else {
        $file = NULL;
        try {
          $loaded = $this->entityTypeManager->getStorage('file')->load($fid);
          if ($loaded instanceof FileInterface && (int) $loaded->get('status')->value !== FileInterface::STATUS_PERMANENT) {
            $category = 'temporary';
            $reason = '临时文件不纳入未引用清理。';
            $refStatus = 'temporary';
          }
        }
        catch (\Throwable) {
          $category = 'unknown';
          $reason = '读取文件状态失败。';
        }
        if ($category === 'unknown' && $reason === '引用关系未知。') {
          if ($hash === '' && (string) $row['path'] !== '' && (int) $row['bytes'] <= $maxBytes) {
            $hash = (string) ($this->guard->hashFile((string) $row['path'], $maxBytes) ?? '');
          }
          if ($hash === '') {
            $category = 'unknown';
            $reason = (int) $row['bytes'] > $maxBytes
              ? '文件超过哈希上限，不能确认可清理。'
              : '无法计算哈希，不能确认可清理。';
          }
          else {
            $category = 'confirmed_unused';
            $reason = '字段、修订、翻译、使用记录和正文路径中都没有找到引用。';
            $refStatus = 'confirmed_unused';
            $risk = 'medium';
          }
        }
      }
    }
    if ($category === 'unknown') {
      $refStatus = 'unknown';
      $risk = 'high';
    }
    return [
      'batch_id' => $batchId,
      'fid' => $fid,
      'path_key' => (string) $row['path_key'],
      'uri' => (string) $row['uri'],
      'path' => (string) $row['path'],
      'filename' => (string) $row['filename'],
      'bytes' => (int) $row['bytes'],
      'mime' => (string) $row['mime'],
      'created' => (int) $row['created'],
      'changed' => (int) $row['mtime'],
      'sha256' => $hash,
      'category' => $category,
      'reason' => substr($reason, 0, 255),
      'ref_count' => count($refs),
      'ref_status' => $refStatus,
      'risk' => $risk,
      'origin' => 'managed',
    ];
  }

  private function regularFile(string $path): bool {
    if ($path === '' || is_link($path)) {
      return FALSE;
    }
    return is_file($path);
  }

  /**
   * @return array<string, int>
   *   Real paths of managed files.
   */
  private function managed(): array {
    if ($this->managedPaths !== NULL) {
      return $this->managedPaths;
    }
    $paths = [];
    $uris = $this->database->query('SELECT uri FROM {file_managed}')->fetchCol();
    foreach ($uris as $uri) {
      if (!is_string($uri)) {
        continue;
      }
      $real = $this->guard->realpathIfLocal($uri);
      if (is_string($real) && $real !== '') {
        $paths[$real] = 1;
      }
    }
    $this->managedPaths = $paths;
    return $paths;
  }

  private function isContent(string $kind): bool {
    return $kind === 'content';
  }

  private function kindReason(string $kind): string {
    return match ($kind) {
      'aggregate_css' => 'CSS 聚合缓存，不是用户上传文件。',
      'aggregate_js' => 'JS 聚合缓存，不是用户上传文件。',
      'derivative' => '图片样式衍生物，可重建，不在此清理。',
      'import' => '导入目录受保护。',
      'quarantine' => '隔离目录受保护。',
      'protected' => '系统文件受保护。',
      'temporary' => '临时目录受保护。',
      'remote' => '对象存储文件不按本地孤立文件处理。',
      default => '路径不在可清理范围内。',
    };
  }

}
