<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;

/**
 * Re-reads entities and files after a merge. It does not fetch anonymous URLs.
 */
class MergeIntegrityChecker {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected FilePathGuard $guard,
    protected CleanupRepository $repository,
  ) {}

  /**
   * @param array<string, mixed> $task
   *   Merge task.
   * @param list<array<string, mixed>> $journal
   *   Saved field values.
   *
   * @return array<string, mixed>
   *   status is pass, warning, fail, or unverifiable.
   */
  public function inspect(array $task, array $journal): array {
    $payload = is_array($task['payload'] ?? NULL) ? $task['payload'] : [];
    $keep = is_array($payload['keep'] ?? NULL) ? $payload['keep'] : [];
    $keepFid = (int) ($keep['fid'] ?? 0);
    $issues = [];
    $file = $this->entityTypeManager->getStorage('file')->load($keepFid);
    if (!$file instanceof FileInterface) {
      $issues[] = ['code' => 'A', 'message' => '保留文件的 File Entity 不存在。'];
      return $this->report('fail', $issues, 0, 0);
    }
    if ($file->getFileUri() !== (string) ($keep['uri'] ?? '')) {
      $issues[] = ['code' => 'A', 'message' => '保留文件的 URI 与快照不一致。'];
    }
    $real = $this->fileSystem->realpath($file->getFileUri());
    $info = is_string($real) ? $this->guard->describe($real) : NULL;
    if ($info === NULL) {
      $issues[] = ['code' => 'B', 'message' => '保留文件的物理路径无法确认。'];
      return $this->report('fail', $issues, 0, count($journal));
    }
    $max = 67108864;
    $hash = $this->guard->hashFile($info['path'], $max);
    $expected = (string) ($keep['sha256'] ?? '');
    if ($hash === NULL) {
      $issues[] = ['code' => 'C', 'message' => '无法计算保留文件的 SHA-256。', 'status' => 'unverifiable'];
    }
    elseif ($expected !== '' && !hash_equals($expected, $hash)) {
      $issues[] = ['code' => 'C', 'message' => '保留文件的 SHA-256 与合并前不一致。'];
    }
    $drops = [];
    foreach ($payload['drops'] ?? [] as $drop) {
      if (is_array($drop) && (int) ($drop['fid'] ?? 0) > 0) {
        $drops[(int) $drop['fid']] = TRUE;
      }
    }
    $notes = [];
    $verified = 0;
    foreach ($journal as $entry) {
      if (!is_array($entry)) {
        $issues[] = ['code' => 'N', 'message' => '日志条目无法读取。', 'status' => 'unverifiable'];
        continue;
      }
      $check = $this->fieldMatches($entry, $keepFid, $drops);
      if ($check === 'ok') {
        $verified++;
        continue;
      }
      $issues[] = $check;
    }
    if (str_starts_with((string) $file->getMimeType(), 'image/') && $info['bytes'] <= 2097152) {
      $image = @getimagesize($info['path']);
      if ($image === FALSE) {
        $issues[] = ['code' => 'M', 'message' => '保留图片无法解码。'];
      }
    }
    elseif (str_starts_with((string) $file->getMimeType(), 'image/') && $info['bytes'] > 2097152) {
      $issues[] = ['code' => 'M', 'message' => '图片超过 2MB，未解码，不能把这一项算作通过。', 'status' => 'warning'];
    }
    if (str_starts_with($file->getFileUri(), 'private://')) {
      $notes[] = '私有文件没有通过匿名 HTTP 判断，只检查了文件实体、物理文件和字段引用。';
    }
    $report = $this->report($this->overall($issues), $issues, $verified, count($journal));
    $report['notes'] = $notes;
    return $report;
  }

  /**
   * A successful merge without a passing report blocks purge of its dropped files.
   */
  public function blocksPurge(int $fid): ?string {
    if (!$this->repository->ready()) {
      return '清理记录不可用，不能永久清理。';
    }
    foreach ($this->repository->latestTasks('merge', 30) as $task) {
      if ((string) ($task['status'] ?? '') !== 'success') {
        continue;
      }
      $drops = $task['payload']['drops'] ?? [];
      $listed = FALSE;
      foreach ($drops as $drop) {
        if (is_array($drop) && (int) ($drop['fid'] ?? 0) === $fid) {
          $listed = TRUE;
        }
      }
      if (!$listed) {
        continue;
      }
      $status = (string) ($task['result']['integrity']['status'] ?? '');
      if ($status !== 'pass') {
        return '文件 ' . $fid . ' 的合并完整性结果不是通过，禁止永久清理。';
      }
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $entry
   *   Journal entry.
   * @param array<int, bool> $drops
   *   Dropped file ids.
   *
   * @return array<string, mixed>|string
   *   ok, or an issue.
   */
  private function fieldMatches(array $entry, int $keepFid, array $drops): array|string {
    try {
      $storage = $this->entityTypeManager->getStorage((string) $entry['entity_type']);
      $definition = $this->entityTypeManager->getDefinition((string) $entry['entity_type']);
      $storage->resetCache([(int) $entry['entity_id']]);
      $revisionId = (int) ($entry['revision_id'] ?? 0);
      $entity = $definition->isRevisionable() && $revisionId > 0 ? $storage->loadRevision($revisionId) : $storage->load((int) $entry['entity_id']);
      if (!$entity instanceof ContentEntityInterface) {
        return ['code' => 'D', 'message' => '迁移后的实体无法读取。', 'status' => 'unverifiable'];
      }
      $langcode = (string) ($entry['langcode'] ?? '');
      if ($entity instanceof TranslatableInterface && $langcode !== '' && $entity->language()->getId() !== $langcode) {
        if (!$entity->hasTranslation($langcode)) {
          return ['code' => 'I', 'message' => '迁移后的翻译不存在。'];
        }
        $entity = $entity->getTranslation($langcode);
      }
      $current = $entity->get((string) $entry['field_name'])->getValue();
    }
    catch (\Throwable $exception) {
      return ['code' => 'N', 'message' => '完整性复查失败：' . $exception->getMessage(), 'status' => 'unverifiable'];
    }
    $before = is_array($entry['before'] ?? NULL) ? $entry['before'] : [];
    if (count($current) !== count($before)) {
      return ['code' => 'D', 'message' => '字段值数量与合并前不一致。'];
    }
    foreach ($before as $delta => $item) {
      $old = (int) ($item['target_id'] ?? 0);
      $new = (int) ($current[$delta]['target_id'] ?? 0);
      if (isset($drops[$old])) {
        if ($new !== $keepFid) {
          return ['code' => 'D', 'message' => '字段仍没有指向保留文件。', 'repairable' => $new === $old];
        }
      }
      elseif ($new !== $old) {
        return ['code' => 'D', 'message' => '字段里其他文件引用发生了变化。'];
      }
    }
    return 'ok';
  }

  /**
   * @param list<array<string, mixed>> $issues
   *   Issues.
   */
  private function overall(array $issues): string {
    $status = 'pass';
    foreach ($issues as $issue) {
      $level = (string) ($issue['status'] ?? 'fail');
      if ($level === 'fail') {
        return 'fail';
      }
      if ($level === 'unverifiable') {
        $status = 'unverifiable';
      }
      elseif ($status === 'pass' && $level === 'warning') {
        $status = 'warning';
      }
    }
    return $status;
  }

  /**
   * @param list<array<string, mixed>> $issues
   *   Issues.
   *
   * @return array<string, mixed>
   *   Report.
   */
  private function report(string $status, array $issues, int $verified, int $total): array {
    return [
      'status' => $status,
      'verified' => $verified,
      'total' => $total,
      'issues' => $issues,
      'failed' => count(array_filter($issues, static fn(array $issue): bool => ($issue['status'] ?? 'fail') === 'fail')),
      'warnings' => count(array_filter($issues, static fn(array $issue): bool => ($issue['status'] ?? '') === 'warning')),
      'unverifiable' => count(array_filter($issues, static fn(array $issue): bool => ($issue['status'] ?? '') === 'unverifiable')),
    ];
  }

}
