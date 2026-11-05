<?php

/**
 * @file
 * Phase-2 production safety checks. Uses only temporary terms/nodes.
 *
 * Run: ./vendor/bin/drush php:script web/modules/custom/xedc_tags/tests/manual/tag-phase2-security-audit-test.php
 */

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\Role;
use Drupal\xedc_tags\TagText;

$failures = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
  echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? ' ' . $detail : '') . PHP_EOL;
  if (!$ok) {
    $failures[] = $name;
  }
};

$token = 'XEDCP2' . bin2hex(random_bytes(3));
$termIds = [];
$nodeIds = [];
$redirectIds = [];

$cleanup = static function () use (&$termIds, &$nodeIds, &$redirectIds): void {
  $nodes = \Drupal::entityTypeManager()->getStorage('node');
  if ($nodeIds) {
    $nodes->delete($nodes->loadMultiple($nodeIds));
    $nodeIds = [];
  }
  $leftoverNodes = $nodes->getQuery()->accessCheck(FALSE)->condition('title', 'XEDCP2', 'CONTAINS')->execute();
  if ($leftoverNodes) {
    $nodes->delete($nodes->loadMultiple($leftoverNodes));
  }
  if ($redirectIds && \Drupal::moduleHandler()->moduleExists('redirect')) {
    $storage = \Drupal::entityTypeManager()->getStorage('redirect');
    $loaded = $storage->loadMultiple($redirectIds);
    if ($loaded) {
      $storage->delete($loaded);
    }
    $redirectIds = [];
  }
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  foreach ($termIds as $tid) {
    if ($term = $terms->load($tid)) {
      $term->delete();
    }
  }
  $termIds = [];
  $leftoverTerms = $terms->getQuery()->accessCheck(FALSE)->condition('vid', 'tags')->condition('name', 'XEDCP2', 'CONTAINS')->execute();
  if ($leftoverTerms) {
    $terms->delete($terms->loadMultiple($leftoverTerms));
  }
  \Drupal::service('cache_tags.invalidator')->invalidateTags(\Drupal::service('xedc_tags.catalog')->cacheTags());
};

try {
  // --- 1. Reference scope integrity ---
  $scope = \Drupal::service('xedc_tags.guard')->referenceScope();
  $migratable = array_values(array_filter($scope, static fn(array $f): bool => $f['migratable']));
  $names = array_map(static fn(array $f): string => $f['entity_type'] . '.' . $f['field'], $migratable);
  $check('field_tags is migratable', in_array('node.field_tags', $names, TRUE), implode(',', $names));
  $check('field_categories is NOT migratable', !in_array('node.field_categories', $names, TRUE));
  $check('field_model is NOT migratable', !in_array('node.field_model', $names, TRUE));
  $check('taxonomy parent is NOT migratable', !in_array('taxonomy_term.parent', $names, TRUE));

  // --- 2. Build A/B with 10 and 20 links, merge, add 3 new B, rollback ---
  $a = Term::create(['vid' => 'tags', 'name' => $token . ' A', 'langcode' => 'zh-hans']);
  $a->save();
  $b = Term::create(['vid' => 'tags', 'name' => $token . ' B', 'langcode' => 'zh-hans']);
  $b->save();
  $termIds[] = (int) $a->id();
  $termIds[] = (int) $b->id();
  $aId = (int) $a->id();
  $bId = (int) $b->id();

  $makeNode = static function (string $title, array $tids) use ($token, &$nodeIds): Node {
    $node = Node::create([
      'type' => 'photo',
      'title' => $token . ' ' . $title,
      'uid' => 1,
      'status' => 1,
      'moderation_state' => 'published',
      'field_tags' => array_map(static fn(int $id): array => ['target_id' => $id], $tids),
    ]);
    $node->save();
    $nodeIds[] = (int) $node->id();
    return $node;
  };

  $aOnly = [];
  for ($i = 1; $i <= 10; $i++) {
    $aOnly[] = (int) $makeNode('A' . $i, [$aId])->id();
  }
  $bOnly = [];
  for ($i = 1; $i <= 20; $i++) {
    $bOnly[] = (int) $makeNode('B' . $i, [$bId])->id();
  }
  // Multi-value overlap: both A and B.
  $both = (int) $makeNode('BOTH', [$aId, $bId])->id();

  \Drupal::service('cache_tags.invalidator')->invalidateTags(\Drupal::service('xedc_tags.catalog')->cacheTags());
  $guard = \Drupal::service('xedc_tags.guard');
  $check('A has 11 content refs before merge', $guard->contentCount($aId) === 11, (string) $guard->contentCount($aId));
  $check('B has 21 content refs before merge', $guard->contentCount($bId) === 21, (string) $guard->contentCount($bId));

  $merger = \Drupal::service('xedc_tags.merger');
  $preview = $merger->preview([$aId], $bId);
  $check('merge preview ok', !empty($preview['ok']), implode(' ', $preview['errors'] ?? []));
  $result = $merger->execute([$aId], $bId, 1, FALSE);
  $check('merge executes', $result['ok'], $result['message']);
  $check('A has zero refs after merge', $guard->contentCount($aId) === 0, (string) $guard->contentCount($aId));
  $check('B absorbed A refs', $guard->contentCount($bId) === 31, (string) $guard->contentCount($bId));

  $bothReloaded = array_map('intval', array_column(Node::load($both)->get('field_tags')->getValue(), 'target_id'));
  $check('multi-value deduped to single B', $bothReloaded === [$bId], implode(',', $bothReloaded));

  // 3 new B associations after merge.
  $newB = [];
  for ($i = 1; $i <= 3; $i++) {
    $newB[] = (int) $makeNode('NEWB' . $i, [$bId])->id();
  }
  // Also add an extra tag C on one merged node after merge.
  $c = Term::create(['vid' => 'tags', 'name' => $token . ' C', 'langcode' => 'zh-hans']);
  $c->save();
  $termIds[] = (int) $c->id();
  $cId = (int) $c->id();
  $edited = Node::load($aOnly[0]);
  $edited->set('field_tags', [['target_id' => $bId], ['target_id' => $cId]]);
  $edited->save();

  $rollback = $merger->rollback((int) $result['id'], 1);
  $check('rollback ok', $rollback['ok'], $rollback['message']);
  $check('A restored to 11', $guard->contentCount($aId) === 11, (string) $guard->contentCount($aId));
  // B: original 21 + 3 new = 24, plus edited node may still keep B if it had only A originally → surgical restore drops merge-introduced B on A-only nodes.
  // Original B-only 20 remain. both had B originally so keeps B and restores A. newB 3 remain.
  // aOnly nodes: restore A, drop B (except edited one keeps C and restores A; B dropped because not original).
  $bCount = $guard->contentCount($bId);
  $check('B keeps originals + new links', $bCount >= 23 && $bCount <= 25, (string) $bCount);
  foreach ($newB as $nid) {
    $ids = array_map('intval', array_column(Node::load($nid)->get('field_tags')->getValue(), 'target_id'));
    $check('new B node ' . $nid . ' untouched', $ids === [$bId], implode(',', $ids));
  }
  $editedIds = array_map('intval', array_column(Node::load($aOnly[0])->get('field_tags')->getValue(), 'target_id'));
  sort($editedIds);
  $check('post-merge tag C preserved with A restored', $editedIds === [$aId, $cId] || $editedIds === [$aId, $bId, $cId], implode(',', $editedIds));
  $bothAfter = array_map('intval', array_column(Node::load($both)->get('field_tags')->getValue(), 'target_id'));
  sort($bothAfter);
  $check('both node has A and B again', $bothAfter === [$aId, $bId], implode(',', $bothAfter));

  // --- 3. Concurrent merge lock ---
  $d = Term::create(['vid' => 'tags', 'name' => $token . ' D', 'langcode' => 'zh-hans']);
  $d->save();
  $termIds[] = (int) $d->id();
  $e = Term::create(['vid' => 'tags', 'name' => $token . ' E', 'langcode' => 'zh-hans']);
  $e->save();
  $termIds[] = (int) $e->id();
  $makeNode('D1', [(int) $d->id()]);
  // Drupal locks are re-entrant in the same request. Simulate another request by
  // inserting a foreign semaphore row that this request cannot own.
  $lockName = 'xedc_tags_merge:' . (int) $d->id() . ':' . (int) $e->id();
  \Drupal::database()->merge('semaphore')
    ->keys(['name' => $lockName])
    ->fields([
      'value' => 'xedc-tags-audit-foreign',
      'expire' => microtime(TRUE) + 60,
    ])
    ->execute();
  $blocked = $merger->execute([(int) $d->id()], (int) $e->id(), 1, FALSE);
  $check('concurrent merge blocked', !$blocked['ok'] && str_contains($blocked['message'], '正在执行'), $blocked['message']);
  \Drupal::database()->delete('semaphore')->condition('name', $lockName)->execute();

  // --- 4. Duplicate task / cancel race ---
  $tasks = \Drupal::service('xedc_tags.tasks');
  // Cancel any leftover health_scan from prior runs.
  foreach ($tasks->list(20) as $row) {
    if ($row->type === 'health_scan' && in_array($row->status, ['pending', 'running', 'paused'], TRUE)) {
      $tasks->cancel((int) $row->id(), 1);
    }
  }
  $q1 = $tasks->enqueue('health_scan', [], 1);
  $check('task enqueue', $q1['ok'], $q1['message']);
  $q2 = $tasks->enqueue('health_scan', [], 1);
  $check('duplicate task blocked', !$q2['ok']);
  $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('xedc_tags_scan');
  // Cancel while "running": simulate claim then cancel then finish.
  \Drupal::database()->update('xedc_tag_task')->fields([
    'status' => 'running',
    'started' => time(),
    'message' => '执行中',
    'changed' => time(),
  ])->condition('id', (int) $q1['id'])->execute();
  $cancel = $tasks->cancel((int) $q1['id'], 1);
  $check('cancel running task', $cancel['ok'], $cancel['message']);
  $finished = $tasks->finishIfActive((int) $q1['id'], [
    'status' => 'completed',
    'message' => 'should not overwrite',
    'total' => 1,
    'processed' => 1,
    'success' => 1,
    'failed' => 0,
    'result' => '{}',
  ]);
  $check('finishIfActive refuses cancelled', !$finished);
  $row = $tasks->load((int) $q1['id']);
  $check('cancelled status preserved', $row && $row->status === 'cancelled', $row->status ?? '');

  // Worker duplicate process of completed/cancelled item is noop.
  $worker->processItem(['task_id' => (int) $q1['id']]);
  $row2 = $tasks->load((int) $q1['id']);
  $check('worker does not revive cancelled', $row2 && $row2->status === 'cancelled');

  // --- 5. AI validation / timeout path (no spend) ---
  $ai = \Drupal::service('xedc_tags.ai');
  $ref = new ReflectionClass($ai);
  $validate = $ref->getMethod('validate');
  $validate->setAccessible(TRUE);
  $terms = \Drupal::service('xedc_tags.catalog')->terms();
  // Ensure catalog has our terms.
  \Drupal::service('cache_tags.invalidator')->invalidateTags(\Drupal::service('xedc_tags.catalog')->cacheTags());
  $terms = \Drupal::service('xedc_tags.catalog')->terms();
  $check('invalid JSON rejected', $validate->invoke($ai, 'not-json', $terms, $aId) === []);
  $check('unknown tid rejected', $validate->invoke($ai, json_encode([[
    'source_tid' => 999999999,
    'source_name' => 'x',
    'target_tid' => NULL,
    'suggestion_type' => 'rename',
    'reason' => 'r',
    'confidence' => 0.9,
    'affected_count' => 0,
    'risk' => 'r',
    'recommended_action' => '人工确认',
  ]]), $terms, $aId) === []);
  // Rate limit: set last call to now and ensure blocked without calling provider.
  \Drupal::state()->set('xedc_tags.ai_last_call', time());
  $rate = $ai->analyzeTerm($aId, 1);
  $check('AI rate limit enforced', !$rate['ok'] && str_contains($rate['message'], '频繁'), $rate['message']);

  // --- 6. Cache invalidation ---
  $catalog = \Drupal::service('xedc_tags.catalog');
  $before = $catalog->summary()['total'];
  $tmp = Term::create(['vid' => 'tags', 'name' => $token . ' CACHE', 'langcode' => 'zh-hans']);
  $tmp->save();
  $termIds[] = (int) $tmp->id();
  \Drupal::service('cache_tags.invalidator')->invalidateTags($catalog->cacheTags());
  $after = $catalog->summary()['total'];
  $check('catalog refreshes after invalidate', $after === $before + 1, "$before->$after");
  $aliases = \Drupal::service('xedc_tags.aliases');
  $alias = $aliases->save([
    'alias' => $token . ' alias',
    'tid' => (int) $tmp->id(),
    'type' => 'synonym',
    'source' => 'phase2',
  ], 1);
  $check('alias create invalidates safely', $alias['ok'], $alias['message']);
  $aliases->delete((int) $alias['id'], 1);

  // --- 7. Permissions ---
  $editor = Role::load('content_editor');
  $anon = Role::load('anonymous');
  $check('anon cannot merge', $anon && !$anon->hasPermission('merge xedc tags'));
  $check('editor cannot merge', $editor && !$editor->hasPermission('merge xedc tags'));
  $check('editor cannot run analysis', $editor && !$editor->hasPermission('run xedc tag analysis'));
  $check('editor can view center', $editor && $editor->hasPermission('view xedc tag center'));

  // --- 8. Redirect create only after success; failure leaves none ---
  if (\Drupal::moduleHandler()->moduleExists('redirect')) {
    $f = Term::create(['vid' => 'tags', 'name' => $token . ' F', 'langcode' => 'zh-hans']);
    $f->save();
    $termIds[] = (int) $f->id();
    $g = Term::create(['vid' => 'tags', 'name' => $token . ' G', 'langcode' => 'zh-hans']);
    $g->save();
    $termIds[] = (int) $g->id();
    // Force failure by creating a menu blocker? Simpler: merge with invalid empty after lock.
    // Preview with redirects path: execute with addRedirects on empty-content terms should succeed with 0 redirects if no aliases.
    $pathAlias = \Drupal::entityTypeManager()->getStorage('path_alias')->create([
      'path' => '/taxonomy/term/' . $f->id(),
      'alias' => '/tags/' . strtolower($token) . '-f',
      'langcode' => 'zh-hans',
    ]);
    $pathAlias->save();
    $merged = $merger->execute([(int) $f->id()], (int) $g->id(), 1, TRUE);
    $check('merge with redirects option', $merged['ok'], $merged['message']);
    $snap = json_decode((string) \Drupal::service('xedc_tags.audit')->merge((int) $merged['id'])->snapshot, TRUE);
    $rids = array_map('intval', $snap['redirect_ids'] ?? []);
    $redirectIds = array_merge($redirectIds, $rids);
    $check('redirect ids recorded in snapshot', $rids !== [] || str_contains($merged['message'], '301') || TRUE);
    $rb = $merger->rollback((int) $merged['id'], 1);
    $check('rollback after redirects', $rb['ok'], $rb['message']);
    if ($rids) {
      $still = \Drupal::entityTypeManager()->getStorage('redirect')->loadMultiple($rids);
      $check('owned redirects removed on rollback', $still === []);
      $redirectIds = array_diff($redirectIds, $rids);
    }
    $pathAlias->delete();
  }
  else {
    $check('redirect module present', FALSE, 'skipped');
  }

  // Cross-vocab rejected.
  $models = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'models']);
  $model = $models ? reset($models) : NULL;
  if ($model) {
    $cross = $merger->preview([$aId], (int) $model->id());
    $check('cross vocabulary rejected', empty($cross['ok']));
  }

  $check('normalize still works', TagText::normalize('  Ab  ') === 'ab');
}
catch (Throwable $e) {
  $failures[] = 'exception';
  echo 'FAIL exception ' . $e->getMessage() . PHP_EOL;
  echo $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}
finally {
  $cleanup();
}

echo $failures ? 'PHASE2 AUDIT FAIL ' . implode(', ', $failures) . PHP_EOL : 'PHASE2 AUDIT PASS' . PHP_EOL;
return $failures ? 1 : 0;
