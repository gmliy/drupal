<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;

/**
 * Moves supported file field values through the Entity API, then rolls back
 * from the saved journal if a later save fails.
 */
class FileReferenceMerger {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUsageInterface $fileUsage,
    protected FileReferenceAnalyzer $analyzer,
    protected CleanupRepository $repository,
    protected StateInterface $state,
  ) {}

  /**
   * @param array<string, mixed> $task
   *   Task row.
   *
   * @return array<string, mixed>
   *   Result with ok, error and journal.
   */
  public function execute(array $task): array {
    $payload = $task['payload'];
    $migrations = $payload['migrations'] ?? [];
    if (!is_array($migrations) || count($migrations) > 40) {
      return ['ok' => FALSE, 'error' => '可迁移引用超过 40 条，已阻止这次合并。', 'journal' => []];
    }
    if (($payload['blocked'] ?? []) !== []) {
      return ['ok' => FALSE, 'error' => '方案里有不能迁移的引用，已阻止合并。', 'journal' => []];
    }
    $journal = [];
    $applied = 0;
    try {
      foreach ($migrations as $ref) {
        if (($ref['source'] ?? '') !== 'field' || (int) ($ref['migratable'] ?? 0) !== 1) {
          throw new \RuntimeException('方案含有不是字段迁移的项目。');
        }
        $before = $this->replace(
          (string) $ref['entity_type'],
          (int) $ref['entity_id'],
          (int) $ref['revision_id'],
          (string) $ref['langcode'],
          (string) $ref['field_name'],
          (int) $ref['fid'],
          (int) $payload['keep']['fid'],
        );
        $journal[] = $before;
        $applied++;
        $this->repository->updateTask((int) $task['id'], [
          'result' => [
            'started' => 1,
            'completed' => 0,
            'journal' => $journal,
          ],
        ]);
        $failAfter = (int) $this->state->get('xedc_cleanup_fail_after_saves', 0);
        if ($failAfter > 0 && $applied >= $failAfter) {
          throw new \RuntimeException('测试失败点：在第 ' . $applied . ' 次保存后停止。');
        }
      }
      $this->reconcileUsage($payload, $migrations);
      foreach (array_merge([$payload['keep']['fid'] ?? 0], array_column($payload['drops'] ?? [], 'fid')) as $fid) {
        $this->keepPermanent((int) $fid);
      }
      $this->verify($migrations, (int) $payload['keep']['fid']);
    }
    catch (\Throwable $exception) {
      $rolled = $this->rollback($journal);
      foreach (array_merge([$payload['keep']['fid'] ?? 0], array_column($payload['drops'] ?? [], 'fid')) as $fid) {
        $this->keepPermanent((int) $fid);
      }
      return [
        'ok' => FALSE,
        'error' => $exception->getMessage() . ($rolled ? ' 已恢复已修改的引用。' : ' 自动恢复没有全部成功。'),
        'journal' => $journal,
        'rolled_back' => $rolled,
      ];
    }
    return ['ok' => TRUE, 'error' => '', 'journal' => $journal, 'rolled_back' => FALSE];
  }

  /**
   * @return array<string, mixed>
   *   Journal entry captured before the save.
   */
  private function replace(string $entityType, int $entityId, int $revisionId, string $langcode, string $fieldName, int $from, int $to): array {
    $storage = $this->entityTypeManager->getStorage($entityType);
    $definition = $this->entityTypeManager->getDefinition($entityType);
    $storage->resetCache([$entityId]);
    $entity = $definition->isRevisionable() && $revisionId > 0 ? $storage->loadRevision($revisionId) : $storage->load($entityId);
    if (!$entity instanceof ContentEntityInterface) {
      throw new \RuntimeException('找不到要迁移的实体 ' . $entityType . ':' . $entityId . '。');
    }
    if ($entity instanceof TranslatableInterface && $langcode !== '' && $entity->language()->getId() !== $langcode) {
      if (!$entity->hasTranslation($langcode)) {
        throw new \RuntimeException('找不到翻译 ' . $langcode . '。');
      }
      $entity = $entity->getTranslation($langcode);
    }
    if (!$entity->hasField($fieldName)) {
      throw new \RuntimeException('字段 ' . $fieldName . ' 不存在。');
    }
    $before = $entity->get($fieldName)->getValue();
    $changed = FALSE;
    $values = $before;
    foreach ($values as &$item) {
      if ((int) ($item['target_id'] ?? 0) === $from) {
        $item['target_id'] = $to;
        $changed = TRUE;
      }
    }
    unset($item);
    if (!$changed) {
      throw new \RuntimeException('字段里已经没有待迁移的文件 ' . $from . '。');
    }
    $wasDefault = !$entity instanceof RevisionableInterface || $entity->isDefaultRevision();
    $entity->set($fieldName, $values);
    $entity->setSyncing(TRUE);
    $entity->setNewRevision(FALSE);
    if ($entity instanceof RevisionableInterface && !$wasDefault) {
      $entity->isDefaultRevision(FALSE);
    }
    $storage->save($entity);
    return [
      'entity_type' => $entityType,
      'entity_id' => $entityId,
      'revision_id' => $revisionId,
      'langcode' => $langcode,
      'field_name' => $fieldName,
      'was_default' => $wasDefault,
      'before' => $before,
    ];
  }

  /**
   * @param list<array<string, mixed>> $journal
   *   Saved field values.
   */
  public function rollback(array $journal): bool {
    $ok = TRUE;
    foreach (array_reverse($journal) as $entry) {
      try {
        $storage = $this->entityTypeManager->getStorage((string) $entry['entity_type']);
        $definition = $this->entityTypeManager->getDefinition((string) $entry['entity_type']);
        $storage->resetCache([(int) $entry['entity_id']]);
        $revisionId = (int) $entry['revision_id'];
        $entity = $definition->isRevisionable() && $revisionId > 0 ? $storage->loadRevision($revisionId) : $storage->load((int) $entry['entity_id']);
        if (!$entity instanceof ContentEntityInterface) {
          $ok = FALSE;
          continue;
        }
        $langcode = (string) $entry['langcode'];
        if ($entity instanceof TranslatableInterface && $langcode !== '' && $entity->language()->getId() !== $langcode && $entity->hasTranslation($langcode)) {
          $entity = $entity->getTranslation($langcode);
        }
        $entity->set((string) $entry['field_name'], $entry['before']);
        $entity->setSyncing(TRUE);
        $entity->setNewRevision(FALSE);
        if ($entity instanceof RevisionableInterface && empty($entry['was_default'])) {
          $entity->isDefaultRevision(FALSE);
        }
        $storage->save($entity);
      }
      catch (\Throwable) {
        $ok = FALSE;
      }
    }
    return $ok;
  }

  /**
   * @param array<string, mixed> $payload
   *   Plan.
   * @param list<array<string, mixed>> $migrations
   *   Field migrations.
   */
  private function reconcileUsage(array $payload, array $migrations): void {
    $keep = $this->entityTypeManager->getStorage('file')->load((int) $payload['keep']['fid']);
    if (!$keep instanceof FileInterface) {
      throw new \RuntimeException('保留文件不存在。');
    }
    $migrated = [];
    foreach ($migrations as $ref) {
      $migrated[$ref['entity_type'] . ':' . $ref['entity_id']] = TRUE;
    }
    foreach ($payload['drops'] ?? [] as $drop) {
      $file = $this->entityTypeManager->getStorage('file')->load((int) $drop['fid']);
      if (!$file instanceof FileInterface) {
        throw new \RuntimeException('待合并文件不存在。');
      }
      $usage = $this->fileUsage->listUsage($file);
      foreach ($usage as $module => $types) {
        if ($module !== 'file') {
          throw new \RuntimeException('仍有模块 ' . $module . ' 的使用记录，已停止。');
        }
        foreach ($types as $type => $ids) {
          foreach ($ids as $id => $count) {
            if (empty($migrated[$type . ':' . $id])) {
              throw new \RuntimeException('使用记录没有对应的字段迁移，已停止。');
            }
            $this->fileUsage->delete($file, 'file', (string) $type, (string) $id, 0);
            $this->fileUsage->add($keep, 'file', (string) $type, (string) $id);
          }
        }
      }
      $this->keepPermanent((int) $drop['fid']);
    }
    $this->keepPermanent((int) $keep->id());
  }

  /**
   * @param list<array<string, mixed>> $migrations
   *   Field migrations.
   */
  private function verify(array $migrations, int $keepFid): void {
    foreach ($migrations as $ref) {
      $storage = $this->entityTypeManager->getStorage((string) $ref['entity_type']);
      $definition = $this->entityTypeManager->getDefinition((string) $ref['entity_type']);
      $storage->resetCache([(int) $ref['entity_id']]);
      $revisionId = (int) $ref['revision_id'];
      $entity = $definition->isRevisionable() && $revisionId > 0 ? $storage->loadRevision($revisionId) : $storage->load((int) $ref['entity_id']);
      if (!$entity instanceof ContentEntityInterface) {
        throw new \RuntimeException('迁移后无法重新读取实体。');
      }
      $langcode = (string) $ref['langcode'];
      if ($entity instanceof TranslatableInterface && $langcode !== '' && $entity->hasTranslation($langcode)) {
        $entity = $entity->getTranslation($langcode);
      }
      $found = FALSE;
      foreach ($entity->get((string) $ref['field_name'])->getValue() as $item) {
        if ((int) ($item['target_id'] ?? 0) === (int) $ref['fid']) {
          throw new \RuntimeException('迁移后字段仍指向原文件。');
        }
        if ((int) ($item['target_id'] ?? 0) === $keepFid) {
          $found = TRUE;
        }
      }
      if (!$found) {
        throw new \RuntimeException('迁移后没有找到保留文件。');
      }
    }
  }

  private function keepPermanent(int $fid): void {
    if ($fid <= 0) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('file');
    $storage->resetCache([$fid]);
    $file = $storage->load($fid);
    if ($file instanceof FileInterface && !$file->isPermanent()) {
      $file->setPermanent();
      $file->save();
    }
  }

}
