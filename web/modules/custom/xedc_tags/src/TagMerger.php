<?php

namespace Drupal\xedc_tags;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Moves term references onto one target term and can restore that change.
 */
final class TagMerger {

  private const MAX_SOURCES = 20;
  private const MAX_ENTITIES = 300;
  private const LOCK_TIMEOUT = 120.0;

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private Connection $database,
    private TagCatalog $catalog,
    private TagGuard $guard,
    private TagAudit $audit,
    private CacheTagsInvalidatorInterface $cacheTags,
    private LockBackendInterface $lock,
  ) {}

  /**
   * @param int[] $sources
   * @return array<string, mixed>
   */
  public function preview(array $sources, int $targetId): array {
    $sources = $this->cleanIds($sources, $targetId);
    $result = [
      'ok' => $sources['errors'] === [],
      'errors' => $sources['errors'],
      'warnings' => [],
      'sources' => [],
      'target' => NULL,
      'photo' => 0,
      'video' => 0,
      'article' => 0,
      'photo_unpublished' => 0,
      'video_unpublished' => 0,
      'article_unpublished' => 0,
      'entities' => 0,
      'already' => 0,
      'aliases' => [],
      'blockers' => [],
    ];
    if ($sources['errors']) {
      return $result;
    }
    $terms = $this->catalog->terms();
    $target = $this->loadTerm($targetId);
    $result['target'] = $terms[$targetId] ?? NULL;
    $entityIds = [];
    foreach ($sources['ids'] as $sourceId) {
      $source = $this->loadTerm($sourceId);
      $result['sources'][] = $terms[$sourceId] ?? ['tid' => $sourceId, 'name' => $source->label()];
      if ($source->bundle() !== TagCatalog::VOCAB || $target->bundle() !== TagCatalog::VOCAB) {
        $result['errors'][] = '只能合并标签词汇中的术语。';
      }
      if ($source->language()->getId() !== $target->language()->getId()) {
        $result['warnings'][] = $source->label() . ' 与目标标签的默认语言不同。';
      }
      $sourceLangs = array_keys($source->getTranslationLanguages());
      $targetLangs = array_keys($target->getTranslationLanguages());
      sort($sourceLangs);
      sort($targetLangs);
      if ($sourceLangs !== $targetLangs) {
        $result['warnings'][] = $source->label() . ' 的翻译语言和目标标签不一致。合并不会把它们变成同一个翻译。';
      }
      $row = $terms[$sourceId] ?? [];
      $result['photo'] += (int) ($row['photo_pub'] ?? 0);
      $result['video'] += (int) ($row['video_pub'] ?? 0);
      $result['article'] += (int) ($row['article_pub'] ?? 0);
      $result['photo_unpublished'] += max(0, (int) ($row['photo'] ?? 0) - (int) ($row['photo_pub'] ?? 0));
      $result['video_unpublished'] += max(0, (int) ($row['video'] ?? 0) - (int) ($row['video_pub'] ?? 0));
      $result['article_unpublished'] += max(0, (int) ($row['article'] ?? 0) - (int) ($row['article_pub'] ?? 0));
      if (!empty($row['aliases'])) {
        $result['aliases'][$sourceId] = $row['aliases'];
      }
      elseif (!empty($row['alias'])) {
        $result['aliases'][$sourceId] = [$row['alias']];
      }
      foreach ($this->guard->blockers($sourceId) as $blocker) {
        $result['blockers'][] = $source->label() . '：' . $blocker['label'];
      }
      foreach ($this->guard->contentReferences($sourceId) as $group) {
        foreach ($group['ids'] as $id) {
          $entityIds[$group['entity_type'] . ':' . $id] = [$group['entity_type'], $id, $group['field'], $sourceId];
        }
      }
    }
    $result['entities'] = count($entityIds);
    $result['already'] = $this->alreadyHasTarget($entityIds, $targetId);
    if ($result['blockers']) {
      $result['errors'][] = '存在菜单、跳转或其他不能自动迁移的引用。请先处理这些引用。';
    }
    if ($result['entities'] > self::MAX_ENTITIES) {
      $result['errors'][] = '影响的实体超过 ' . self::MAX_ENTITIES . '，本次没有开始合并。';
    }
    $result['warnings'][] = '目标标签的 URL 保持不变。来源标签不会删除，原 URL 仍打开来源标签页。系统不会批量改别名，也不会自动添加跳转。';
    $result['warnings'][] = '不会修改标签描述，也不会改内容的翻译关系。已经带有目标标签的内容只会去掉重复引用。';
    $result['ok'] = $result['errors'] === [];
    $result['entity_map'] = $entityIds;
    return $result;
  }

  /**
   * @param int[] $sources
   * @return array{ok: bool, message: string, moved: int, skipped: int, id: int}
   */
  public function execute(array $sources, int $targetId, int $uid, bool $addRedirects = FALSE): array {
    $preview = $this->preview($sources, $targetId);
    if (!$preview['ok']) {
      return [
        'ok' => FALSE,
        'message' => implode(' ', $preview['errors']),
        'moved' => 0,
        'skipped' => 0,
        'id' => 0,
      ];
    }
    $sourceIds = array_map('intval', array_column($preview['sources'], 'tid'));
    sort($sourceIds);
    $lockName = 'xedc_tags_merge:' . implode('-', $sourceIds) . ':' . $targetId;
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      return [
        'ok' => FALSE,
        'message' => '同一组合并正在执行，请稍后再试。',
        'moved' => 0,
        'skipped' => 0,
        'id' => 0,
      ];
    }
    $snapshotEntities = [];
    $moved = 0;
    $skipped = 0;
    $redirects = 0;
    $redirectIds = [];
    $id = 0;
    try {
      // Re-check under lock so a concurrent merge cannot leave stale references.
      $preview = $this->preview($sources, $targetId);
      if (!$preview['ok']) {
        return [
          'ok' => FALSE,
          'message' => implode(' ', $preview['errors']),
          'moved' => 0,
          'skipped' => 0,
          'id' => 0,
        ];
      }
      $transaction = $this->database->startTransaction();
      try {
        foreach ($preview['entity_map'] as [$entityType, $entityId, $field]) {
          $entity = $this->entityTypeManager->getStorage($entityType)->load($entityId);
          if (!$entity instanceof ContentEntityInterface || !$entity->hasField($field)) {
            $skipped++;
            continue;
          }
          $before = $this->capture($entity, $field);
          $changed = $this->rewrite($entity, $field, $preview['sources'], $targetId);
          if (!$changed) {
            $skipped++;
            continue;
          }
          if ($entity instanceof RevisionLogInterface) {
            $entity->setRevisionUserId($uid);
            $entity->setRevisionLogMessage('标签合并到术语 ' . $targetId . '。来源标签仍保留。');
          }
          $entity->save();
          $snapshotEntities[] = $before;
          $moved++;
        }
        foreach ($this->cleanIds(array_column($preview['sources'], 'tid'), $targetId)['ids'] as $sourceId) {
          if ($this->guard->contentCount($sourceId) > 0) {
            throw new \RuntimeException('术语 ' . $sourceId . ' 在保存后仍被内容引用，已中止。');
          }
        }
        $payload = [
          'version' => 2,
          'sources' => array_column($preview['sources'], 'tid'),
          'target' => $targetId,
          'entities' => $snapshotEntities,
          'redirect_ids' => [],
        ];
        $id = (int) $this->database->insert('xedc_tag_merge')->fields([
          'sources' => implode(',', array_column($preview['sources'], 'tid')),
          'target_tid' => $targetId,
          'uid' => $uid,
          'status' => 'completed',
          'moved' => $moved,
          'skipped' => $skipped,
          'message' => '已迁移引用，来源标签保留。',
          'snapshot' => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
          'created' => \Drupal::time()->getRequestTime(),
          'changed' => \Drupal::time()->getRequestTime(),
        ])->execute();
        $this->audit->log('merge', '合并到 ' . $targetId, [
          'sources' => array_column($preview['sources'], 'tid'),
          'moved' => $moved,
          'skipped' => $skipped,
        ], $targetId, $uid);
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        unset($transaction);
        $this->recordFailure($preview, $targetId, $uid, $exception->getMessage());
        return [
          'ok' => FALSE,
          'message' => '合并已回滚：' . $exception->getMessage(),
          'moved' => 0,
          'skipped' => 0,
          'id' => 0,
        ];
      }
      unset($transaction);
      foreach ($this->cleanIds(array_column($preview['sources'], 'tid'), $targetId)['ids'] as $sourceId) {
        if ($this->guard->contentCount($sourceId) > 0) {
          $rollback = $this->rollback($id, $uid);
          return [
            'ok' => FALSE,
            'message' => '提交后仍有残留引用，已尝试回滚。' . $rollback['message'],
            'moved' => $moved,
            'skipped' => $skipped,
            'id' => $id,
          ];
        }
      }
      // External side effects only after verified commit.
      if ($addRedirects) {
        [$redirects, $redirectIds] = $this->createRedirects(array_column($preview['sources'], 'tid'), $targetId, $preview['aliases'] ?? []);
        if ($redirectIds) {
          $payload = json_decode((string) $this->audit->merge($id)?->snapshot, TRUE) ?: [];
          if (is_array($payload)) {
            $payload['redirect_ids'] = $redirectIds;
            $this->database->update('xedc_tag_merge')->fields([
              'snapshot' => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
              'message' => '已迁移引用，来源标签保留。已添加 ' . $redirects . ' 条 301。',
              'changed' => \Drupal::time()->getRequestTime(),
            ])->condition('id', $id)->execute();
          }
          $this->audit->log('merge_redirect', '合并后添加 ' . $redirects . ' 条 301', [
            'sources' => array_column($preview['sources'], 'tid'),
            'redirects' => $redirects,
            'redirect_ids' => $redirectIds,
          ], $targetId, $uid);
        }
      }
      $this->cacheTags->invalidateTags($this->catalog->cacheTags());
      return [
        'ok' => TRUE,
        'message' => '已把 ' . $moved . ' 个实体的标签引用迁到目标标签。来源标签仍保留。' . ($redirects ? ' 已添加 ' . $redirects . ' 条 301。' : ''),
        'moved' => $moved,
        'skipped' => $skipped,
        'id' => $id,
      ];
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * @param int[] $sourceIds
   * @param array<int, array<string, string>|string> $aliases
   * @return array{0: int, 1: int[]}
   */
  private function createRedirects(array $sourceIds, int $targetId, array $aliases): array {
    if (!\Drupal::moduleHandler()->moduleExists('redirect') || !class_exists('\Drupal\redirect\Entity\Redirect')) {
      return [0, []];
    }
    $created = 0;
    $ids = [];
    $targetPath = '/taxonomy/term/' . $targetId;
    foreach ($sourceIds as $sourceId) {
      $sourceId = (int) $sourceId;
      $map = $aliases[$sourceId] ?? [];
      if (!is_array($map)) {
        $map = ['und' => (string) $map];
      }
      foreach ($map as $langcode => $alias) {
        $alias = trim((string) $alias);
        if ($alias === '' || str_starts_with($alias, '/taxonomy/term/')) {
          continue;
        }
        $source = ltrim($alias, '/');
        if ($source === '') {
          continue;
        }
        $existing = $this->entityTypeManager->getStorage('redirect')->getQuery()
          ->accessCheck(FALSE)
          ->condition('redirect_source.path', $source)
          ->range(0, 1)
          ->execute();
        if ($existing) {
          continue;
        }
        $redirect = \Drupal\redirect\Entity\Redirect::create();
        $redirect->setSource($source);
        $redirect->setRedirect($targetPath);
        $redirect->setStatusCode(301);
        if ($langcode && $langcode !== 'und') {
          $redirect->set('language', $langcode);
        }
        $redirect->save();
        $ids[] = (int) $redirect->id();
        $created++;
      }
    }
    return [$created, $ids];
  }

  /**
   * @return array{ok: bool, message: string}
   */
  public function rollback(int $mergeId, int $uid): array {
    $row = $this->audit->merge($mergeId);
    if (!$row || $row->status !== 'completed') {
      return ['ok' => FALSE, 'message' => '只能回滚已完成的合并。'];
    }
    $lockName = 'xedc_tags_merge_rollback:' . $mergeId;
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      return ['ok' => FALSE, 'message' => '该合并正在回滚，请稍后再试。'];
    }
    try {
      $decoded = json_decode((string) $row->snapshot, TRUE);
      if (!is_array($decoded)) {
        return ['ok' => FALSE, 'message' => '找不到可恢复的字段快照。'];
      }
      $sourceIds = array_map('intval', array_filter(explode(',', (string) $row->sources)));
      $targetId = (int) $row->target_tid;
      $entities = [];
      $redirectIds = [];
      if (isset($decoded['version']) && (int) $decoded['version'] >= 2 && isset($decoded['entities']) && is_array($decoded['entities'])) {
        $entities = $decoded['entities'];
        $sourceIds = array_map('intval', $decoded['sources'] ?? $sourceIds);
        $targetId = (int) ($decoded['target'] ?? $targetId);
        $redirectIds = array_map('intval', $decoded['redirect_ids'] ?? []);
      }
      else {
        // Legacy flat snapshot: list of entity field captures.
        $entities = $decoded;
      }
      if ($sourceIds === [] || $targetId <= 0) {
        return ['ok' => FALSE, 'message' => '快照缺少来源或目标，已阻止不安全回滚。'];
      }
      $transaction = $this->database->startTransaction();
      try {
        foreach ($entities as $item) {
          if (!is_array($item) || empty($item['entity_type']) || empty($item['id']) || empty($item['field'])) {
            continue;
          }
          $entity = $this->entityTypeManager->getStorage($item['entity_type'])->load((int) $item['id']);
          if (!$entity instanceof ContentEntityInterface) {
            continue;
          }
          $this->restore($entity, $item, $sourceIds, $targetId);
          if ($entity instanceof RevisionLogInterface) {
            $entity->setRevisionUserId($uid);
            $entity->setRevisionLogMessage('回滚标签合并 #' . $mergeId);
          }
          $entity->save();
        }
        $this->database->update('xedc_tag_merge')->fields([
          'status' => 'rolled_back',
          'changed' => \Drupal::time()->getRequestTime(),
          'message' => '已按快照安全恢复标签字段（保留合并后新增关联）。',
        ])->condition('id', $mergeId)->condition('status', 'completed')->execute();
        $this->audit->log('merge_rollback', '回滚合并 #' . $mergeId, [
          'sources' => $sourceIds,
          'target' => $targetId,
          'redirect_ids' => $redirectIds,
        ], $targetId, $uid);
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        return ['ok' => FALSE, 'message' => '回滚失败，已尝试撤销本次回滚：' . $exception->getMessage()];
      }
      unset($transaction);
      // Remove only redirects created by this merge; never touch unrelated ones.
      $removedRedirects = $this->deleteOwnedRedirects($redirectIds);
      if ($removedRedirects > 0) {
        $this->audit->log('merge_redirect_cleanup', '回滚时删除本合并创建的 301 共 ' . $removedRedirects . ' 条', [
          'redirect_ids' => $redirectIds,
        ], $targetId, $uid);
      }
      $this->cacheTags->invalidateTags($this->catalog->cacheTags());
      return ['ok' => TRUE, 'message' => '已恢复合并 #' . $mergeId . ' 的标签字段。'];
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * @param int[] $ids
   */
  private function deleteOwnedRedirects(array $ids): int {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !\Drupal::moduleHandler()->moduleExists('redirect')) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('redirect');
    $entities = $storage->loadMultiple($ids);
    if (!$entities) {
      return 0;
    }
    $storage->delete($entities);
    return count($entities);
  }

  /**
   * @param int[] $sources
   * @return array{ids: int[], errors: string[]}
   */
  private function cleanIds(array $sources, int $targetId): array {
    $errors = [];
    $ids = [];
    foreach ($sources as $source) {
      $source = (int) $source;
      if ($source > 0) {
        $ids[$source] = $source;
      }
    }
    if (!$ids) {
      $errors[] = '请选择来源标签。';
    }
    if ($targetId <= 0) {
      $errors[] = '请选择目标标签。';
    }
    if (isset($ids[$targetId])) {
      $errors[] = '目标标签不能同时作为来源标签。';
      unset($ids[$targetId]);
    }
    if (count($ids) > self::MAX_SOURCES) {
      $errors[] = '一次最多合并 ' . self::MAX_SOURCES . ' 个来源标签。';
    }
    foreach (array_merge($ids, [$targetId]) as $tid) {
      if ($tid <= 0) {
        continue;
      }
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
      if (!$term instanceof TermInterface || $term->bundle() !== TagCatalog::VOCAB) {
        $errors[] = '术语 ' . $tid . ' 不是有效的标签。';
      }
    }
    return ['ids' => array_values($ids), 'errors' => $errors];
  }

  private function loadTerm(int $tid): TermInterface {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    if (!$term instanceof TermInterface) {
      throw new \RuntimeException('术语不存在。');
    }
    return $term;
  }

  /**
   * @param array<string, array{0: string, 1: int, 2: string, 3: int}> $entityIds
   */
  private function alreadyHasTarget(array $entityIds, int $targetId): int {
    $count = 0;
    foreach ($entityIds as [$entityType, $id, $field]) {
      $entity = $this->entityTypeManager->getStorage($entityType)->load($id);
      if (!$entity instanceof ContentEntityInterface || !$entity->hasField($field)) {
        continue;
      }
      $current = array_map('intval', array_column($entity->get($field)->getValue(), 'target_id'));
      if (in_array($targetId, $current, TRUE)) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * @return array{entity_type: string, id: int, field: string, values: array<string, array<int, array<string, int>>>}
   */
  private function capture(ContentEntityInterface $entity, string $field): array {
    $definition = $entity->getFieldDefinition($field);
    $values = [];
    if ($definition && !$definition->isTranslatable()) {
      $values['default'] = $this->values($entity->getUntranslated(), $field);
    }
    else {
      foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
        $values[$langcode] = $this->values($entity->getTranslation($langcode), $field);
      }
    }
    return [
      'entity_type' => $entity->getEntityTypeId(),
      'id' => (int) $entity->id(),
      'field' => $field,
      'values' => $values,
    ];
  }

  /**
   * @return array<int, array{target_id: int}>
   */
  private function values(ContentEntityInterface $entity, string $field): array {
    $values = [];
    foreach ($entity->get($field)->getValue() as $item) {
      if (!empty($item['target_id'])) {
        $values[] = ['target_id' => (int) $item['target_id']];
      }
    }
    return $values;
  }

  /**
   * @param array<int, array<string, mixed>> $sources
   */
  private function rewrite(ContentEntityInterface $entity, string $field, array $sources, int $targetId): bool {
    $sourceIds = array_map('intval', array_column($sources, 'tid'));
    $definition = $entity->getFieldDefinition($field);
    $changed = FALSE;
    $translations = $definition && !$definition->isTranslatable()
      ? [$entity->getUntranslated()]
      : array_map(fn(string $langcode) => $entity->getTranslation($langcode), array_keys($entity->getTranslationLanguages()));
    foreach ($translations as $translation) {
      $current = array_map('intval', array_column($translation->get($field)->getValue(), 'target_id'));
      if (!array_intersect($current, $sourceIds)) {
        continue;
      }
      $next = [];
      $seen = [];
      foreach ($current as $id) {
        if (in_array($id, $sourceIds, TRUE)) {
          $id = $targetId;
        }
        if ($id <= 0 || isset($seen[$id])) {
          continue;
        }
        $seen[$id] = TRUE;
        $next[] = ['target_id' => $id];
      }
      $translation->set($field, $next);
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Surgical restore: put source tags back, keep post-merge additions.
   *
   * @param array{entity_type: string, id: int, field: string, values: array<string, array<int, array<string, int>>>} $item
   * @param int[] $sourceIds
   */
  private function restore(ContentEntityInterface $entity, array $item, array $sourceIds, int $targetId): void {
    $field = $item['field'];
    if (!$entity->hasField($field)) {
      return;
    }
    $definition = $entity->getFieldDefinition($field);
    if ($definition && !$definition->isTranslatable()) {
      $this->restoreTranslation($entity->getUntranslated(), $field, $item['values']['default'] ?? [], $sourceIds, $targetId);
      return;
    }
    foreach ($item['values'] as $langcode => $original) {
      if (!$entity->hasTranslation($langcode)) {
        continue;
      }
      $this->restoreTranslation($entity->getTranslation($langcode), $field, $original, $sourceIds, $targetId);
    }
  }

  /**
   * @param array<int, array{target_id: int}> $original
   * @param int[] $sourceIds
   */
  private function restoreTranslation(ContentEntityInterface $entity, string $field, array $original, array $sourceIds, int $targetId): void {
    $originalIds = array_map('intval', array_column($original, 'target_id'));
    $currentIds = array_map('intval', array_column($entity->get($field)->getValue(), 'target_id'));
    $hadTarget = in_array($targetId, $originalIds, TRUE);
    $sourcesToRestore = array_values(array_intersect($sourceIds, $originalIds));
    $next = [];
    $seen = [];
    foreach ($currentIds as $tid) {
      if ($tid === $targetId) {
        // Keep target only if it existed before the merge. Merge-introduced
        // target is removed so sources can return. Entities not in the
        // snapshot (new B links after merge) are never touched.
        if ($hadTarget && !isset($seen[$tid])) {
          $next[] = ['target_id' => $tid];
          $seen[$tid] = TRUE;
        }
        continue;
      }
      if ($tid > 0 && !isset($seen[$tid])) {
        $next[] = ['target_id' => $tid];
        $seen[$tid] = TRUE;
      }
    }
    foreach ($sourcesToRestore as $tid) {
      if ($tid > 0 && !isset($seen[$tid])) {
        $next[] = ['target_id' => $tid];
        $seen[$tid] = TRUE;
      }
    }
    $entity->set($field, $next);
  }

  /**
   * @param array<string, mixed> $preview
   */
  private function recordFailure(array $preview, int $targetId, int $uid, string $message): void {
    try {
      $this->database->insert('xedc_tag_merge')->fields([
        'sources' => implode(',', array_column($preview['sources'], 'tid')),
        'target_tid' => $targetId,
        'uid' => $uid,
        'status' => 'failed',
        'moved' => 0,
        'skipped' => 0,
        'message' => mb_substr($message, 0, 1000),
        'snapshot' => '[]',
        'created' => \Drupal::time()->getRequestTime(),
        'changed' => \Drupal::time()->getRequestTime(),
      ])->execute();
      $this->audit->log('merge_failed', $message, [], $targetId, $uid);
    }
    catch (\Throwable) {
    }
  }

}
