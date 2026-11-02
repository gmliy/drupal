<?php

/**
 * @file
 * Destructive checks use only files created by this script.
 */

declare(strict_types=1);

use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\xedc_core\Ops\Cleanup\DuplicateMath;

$failed = 0;
$passed = 0;
$skipped = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed, &$passed): void {
  if ($ok) {
    $passed++;
    echo "PASS $name\n";
    return;
  }
  $failed++;
  echo "FAIL $name" . ($detail !== '' ? " :: $detail" : '') . "\n";
};

$reclaim = DuplicateMath::reclaimable([
  ['inode' => '1:1', 'bytes' => 10],
  ['inode' => '1:1', 'bytes' => 10],
  ['inode' => '1:2', 'bytes' => 10],
]);
$check('reclaimable counts an inode once', $reclaim === 10, (string) $reclaim);
$check('one inode frees nothing', DuplicateMath::reclaimable([
  ['inode' => '9:9', 'bytes' => 4],
  ['inode' => '9:9', 'bytes' => 4],
]) === 0);
$hashA = DuplicateMath::planHash(['type' => 'quarantine', 'items' => [['fid' => 2], ['fid' => 1]]]);
$hashB = DuplicateMath::planHash(['items' => [['fid' => 1], ['fid' => 2]], 'type' => 'quarantine']);
$check('plan hash changes with item order', $hashA !== $hashB);

$token = 'xedc' . bin2hex(random_bytes(4));
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$different = substr($png, 0, -1) . 'A';
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
$guard = \Drupal::service('xedc_core.file_path_guard');
$check('css aggregate is not content', $guard->kindForUri('public://css/css_test.css') === 'aggregate_css');
$check('js aggregate is not content', $guard->kindForUri('public://js/js_test.js') === 'aggregate_js');
$check('image style is not content', $guard->kindForUri('public://styles/thumb/public/a.png') === 'derivative');
$check('s3 uri is remote storage', $guard->kindForUri('s3://private/a.jpg') === 'remote');
$outside = '/tmp/' . $token;
file_put_contents($outside, $png);
$check('path outside Drupal is rejected', $guard->describe($outside) === NULL);
@unlink($outside);

$fs = \Drupal::service('file_system');
$publicDir = 'public://xedc-cleanup-test/' . $token;
$privateDir = 'private://xedc-cleanup-test/' . $token;
$fs->prepareDirectory($publicDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
$fs->prepareDirectory($privateDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
$publicReal = $fs->realpath($publicDir);
$privateReal = $fs->realpath($privateDir);

$write = static function (string $dir, string $name, string $bytes) use ($fs): string {
  $uri = $dir . '/' . $name;
  file_put_contents($fs->realpath($dir) . '/' . $name, $bytes);
  return $uri;
};
$uriA = $write($publicDir, 'a.png', $png);
$uriB = $write($privateDir, 'b.png', $png);
$uriD = $write($privateDir, 'd.png', $png);
$uriOther = $write($privateDir, 'other.png', $different);
$uriTrDrop = $write($privateDir, 'tr-drop.png', $png . 'tr');
$uriTrKeep = $write($privateDir, 'tr-keep.png', $png . 'tr');
$uriSolo = $write($privateDir, 'solo.png', $png . 'solo');
$uriEmbedded = $write($publicDir, 'embedded.png', $png . 'embed');
$uriSameSize = $write($privateDir, 'same-size.png', str_pad('X', strlen($png), 'Y'));
$uriEmptyA = $write($privateDir, 'empty-a.txt', '');
$uriEmptyB = $write($privateDir, 'empty-b.txt', '');
file_put_contents($privateReal . '/orphan.bin', 'orphan-' . $token);
$uriMissing = $publicDir . '/missing.png';
symlink('/etc/hostname', $privateReal . '/escape.link');
symlink($fs->realpath($uriA), $privateReal . '/inside.link');

$make = static function (string $uri) use ($token): File {
  $file = File::create([
    'uri' => $uri,
    'filename' => basename($uri),
    'filemime' => str_ends_with($uri, '.png') ? 'image/png' : 'text/plain',
    'filesize' => is_file(\Drupal::service('file_system')->realpath($uri) ?: '') ? filesize(\Drupal::service('file_system')->realpath($uri)) : 0,
    'status' => FileInterface::STATUS_PERMANENT,
    'uid' => 1,
  ]);
  $file->save();
  return $file;
};
$ids = ['files' => [], 'media' => [], 'nodes' => [], 'users' => [], 'tasks' => []];
$cleanup = static function () use (&$ids, $token, $switcher, $publicReal, $privateReal, $guard): void {
  static $done = FALSE;
  if ($done) {
    return;
  }
  $done = TRUE;
  \Drupal::state()->delete('xedc_cleanup_fail_after_saves');
  \Drupal::state()->delete('xedc_cleanup_migration_batch');
  \Drupal::state()->delete('xedc_cleanup_pause_after_saves');
  \Drupal::state()->delete('xedc_cleanup_hold_next');
  \Drupal::state()->delete('xedc_cleanup_crash_before_execute');
  $savedSuggest = \Drupal::state()->get('xedc_cleanup_test_suggest');
  if (is_array($savedSuggest) && array_key_exists('value', $savedSuggest)) {
    \Drupal::configFactory()->getEditable('xedc_core.settings')->set('file_analysis_suggest', $savedSuggest['value'])->save();
    \Drupal::state()->delete('xedc_cleanup_test_suggest');
  }
  $config = \Drupal::configFactory()->getEditable('xedc_core.settings');
  if ($config->get('file_quarantine_days') === 0) {
    $config->set('file_quarantine_days', 14)->save();
  }
  $storage = \Drupal::entityTypeManager();
  foreach (['node' => 'nodes', 'media' => 'media', 'user' => 'users', 'file' => 'files'] as $type => $key) {
    $entities = $storage->getStorage($type)->loadMultiple($ids[$key]);
    foreach ($entities as $entity) {
      try {
        $entity->delete();
      }
      catch (\Throwable) {
      }
    }
  }
  $database = \Drupal::database();
  if ($database->schema()->tableExists('xedc_cleanup_probe')) {
    $database->schema()->dropTable('xedc_cleanup_probe');
  }
  if ($ids['files'] !== [] && $database->schema()->tableExists('xedc_file_cleanup_item')) {
    $taskIds = $database->select('xedc_file_cleanup_item', 'i')
      ->fields('i', ['task_id'])
      ->condition('fid', $ids['files'], 'IN')
      ->execute()
      ->fetchCol();
    $taskIds = array_values(array_unique(array_merge($ids['tasks'], array_map('intval', $taskIds))));
    if ($taskIds !== []) {
      $database->delete('xedc_file_cleanup_item')->condition('task_id', $taskIds, 'IN')->execute();
      $database->delete('xedc_file_cleanup_log')->condition('task_id', $taskIds, 'IN')->execute();
      $database->delete('xedc_file_cleanup_task')->condition('id', $taskIds, 'IN')->execute();
    }
    $suggested = $database->select('xedc_file_cleanup_task', 't')
      ->fields('t', ['id'])
      ->condition('uid', 0)
      ->condition('status', ['pending_review', 'pending_confirm'], 'IN')
      ->execute()
      ->fetchCol();
    if ($suggested !== []) {
      $database->delete('xedc_file_cleanup_item')->condition('task_id', $suggested, 'IN')->execute();
      $database->delete('xedc_file_cleanup_log')->condition('task_id', $suggested, 'IN')->execute();
      $database->delete('xedc_file_cleanup_task')->condition('id', $suggested, 'IN')->execute();
    }
  }
  $remove = static function (?string $dir) use ($token): void {
    if (!is_string($dir) || !str_contains($dir, '/xedc-cleanup-test/' . $token)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
      $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
  };
  $remove(is_string($publicReal) ? $publicReal : NULL);
  $remove(is_string($privateReal) ? $privateReal : NULL);
  $root = $guard->quarantineRoot();
  if (is_string($root) && is_dir($root)) {
    foreach ($ids['files'] as $fid) {
      foreach (glob($root . '/*/' . (int) $fid . '-*') ?: [] as $leftover) {
        if (is_file($leftover) && $guard->isInsideQuarantine($leftover)) {
          @unlink($leftover);
        }
      }
    }
  }
  try {
    $switcher->switchBack();
  }
  catch (\Throwable) {
  }
};
register_shutdown_function($cleanup);
set_exception_handler(static function (\Throwable $e) use ($cleanup): void {
  $cleanup();
  fwrite(STDERR, $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n");
  exit(1);
});
$track = static function (File $file) use (&$ids): int {
  $ids['files'][] = (int) $file->id();
  return (int) $file->id();
};
$fidA = $track($make($uriA));
$fidB = $track($make($uriB));
$fidD = $track($make($uriD));
$shared = $make($uriA);
$fidShared = $track($shared);
$fidOther = $track($make($uriOther));
$fidTrDrop = $track($make($uriTrDrop));
$fidTrKeep = $track($make($uriTrKeep));
$fidSolo = $track($make($uriSolo));
$fidEmbedded = $track($make($uriEmbedded));
$fidSameSize = $track($make($uriSameSize));
$fidEmptyA = $track($make($uriEmptyA));
$fidEmptyB = $track($make($uriEmptyB));
$fidMissing = $track($make($uriMissing));

$media = Media::create([
  'bundle' => 'image',
  'name' => 'cleanup ' . $token,
  'status' => 0,
  'uid' => 1,
  'field_media_image' => ['target_id' => $fidA, 'alt' => 'cleanup test'],
]);
$media->save();
$ids['media'][] = (int) $media->id();
$oldVid = (int) $media->getRevisionId();
$media->setNewRevision(TRUE);
$media->set('field_media_image', ['target_id' => $fidOther, 'alt' => 'cleanup test']);
$media->setRevisionLogMessage('cleanup test revision');
$media->save();

$node = Node::create([
  'type' => 'photo',
  'title' => 'XEDC cleanup ' . $token,
  'status' => 0,
  'moderation_state' => 'draft',
  'uid' => 1,
  'field_images' => [['target_id' => $media->id()]],
  'field_summary' => '<img srcset="/sites/default/files/xedc-cleanup-test/' . $token . '/embedded.png 1x">',
]);
$node->save();
$ids['nodes'][] = (int) $node->id();

$user = User::create([
  'name' => $token,
  'mail' => $token . '@example.com',
  'status' => 0,
  'pass' => bin2hex(random_bytes(16)),
]);
$user->set('user_picture', ['target_id' => $fidTrDrop, 'alt' => 'cleanup test']);
$user->save();
$ids['users'][] = (int) $user->id();
$user = User::load((int) $user->id());
$translationReady = FALSE;
if ($user && $user->isTranslatable() && !$user->hasTranslation('en')) {
  $user->addTranslation('en');
  $translation = $user->getTranslation('en');
  $translation->set('user_picture', ['target_id' => $fidTrDrop, 'alt' => 'cleanup en']);
  $user->save();
  $translationReady = TRUE;
}
$check('user translation was created', $translationReady);

$runner = \Drupal::service('xedc_core.file_analysis');
$started = $runner->start();
$check('analysis starts', $started === 'started', $started);
for ($i = 0; $i < 160 && $started === 'started'; $i++) {
  $step = $runner->step();
  if ($step !== 'more' && $step !== 'busy') {
    break;
  }
}
$summary = $runner->summary();
$check('analysis finishes', ($summary['status'] ?? '') === 'done', (string) ($summary['message'] ?? ''));
$batchId = (int) ($summary['finished_batch'] ?? 0);
$repo = \Drupal::service('xedc_core.cleanup_repository');
$batch = $repo->loadBatch($batchId);
$check('reference analysis completed', empty($batch['state']['reference_incomplete']) && empty($batch['state']['text_incomplete']));

$membersOf = static function (int $fid) use ($repo, $batchId): array {
  $groups = $repo->groups($batchId, [], 100, 0)['rows'];
  foreach ($groups as $group) {
    foreach ($repo->members((int) $group['id']) as $member) {
      if ((int) $member['fid'] === $fid) {
        return ['group' => $group, 'members' => $repo->members((int) $group['id'])];
      }
    }
  }
  return [];
};
$groupA = $membersOf($fidA);
$fidsInA = array_map(static fn(array $row): int => (int) $row['fid'], $groupA['members'] ?? []);
sort($fidsInA);
$check('identical files form one group', in_array($fidA, $fidsInA, TRUE) && in_array($fidB, $fidsInA, TRUE) && in_array($fidD, $fidsInA, TRUE) && in_array($fidShared, $fidsInA, TRUE), implode(',', $fidsInA));
$check('same size different content stays out', !in_array($fidSameSize, $fidsInA, TRUE) && !in_array($fidOther, $fidsInA, TRUE));
$expected = 2 * strlen($png);
$check('shared URI is not double counted', (int) ($groupA['group']['reclaimable_bytes'] ?? -1) === $expected, (string) ($groupA['group']['reclaimable_bytes'] ?? ''));
$groupEmpty = $membersOf($fidEmptyA);
$emptyFids = array_map(static fn(array $row): int => (int) $row['fid'], $groupEmpty['members'] ?? []);
$check('empty files group together', in_array($fidEmptyA, $emptyFids, TRUE) && in_array($fidEmptyB, $emptyFids, TRUE));
$check('empty reclaimable is zero', (int) ($groupEmpty['group']['reclaimable_bytes'] ?? -1) === 0);

$finding = static fn(int $fid): ?array => $repo->findingByFid($batchId, $fid);
$check('old revision keeps the file referenced', ($finding($fidA)['category'] ?? '') === 'referenced', (string) ($finding($fidA)['reason'] ?? ''));
$check('media-used file is not unused', ($finding($fidOther)['category'] ?? '') !== 'confirmed_unused');
$check('embedded path stays unknown', ($finding($fidEmbedded)['category'] ?? '') === 'unknown', (string) ($finding($fidEmbedded)['category'] ?? ''));
$embeddedReason = '';
foreach ($repo->refsForFid($batchId, $fidEmbedded) as $ref) {
  if (($ref['source'] ?? '') === 'embedded') {
    $embeddedReason = (string) $ref['reason'];
  }
}
$check('srcset path is identified and not migratable', str_contains($embeddedReason, 'srcset'), $embeddedReason);
$presenter = \Drupal::service('xedc_core.reference_presenter');
$refsForA = $repo->refsForFid($batchId, $fidA);
$summaryA = $presenter->summarize($refsForA);
$check('reference locations and entities are distinct counts', $summaryA['locations'] >= $summaryA['entities'] && $summaryA['entities'] >= 1 && $summaryA['media'] >= 1, json_encode($summaryA));
$anonCards = $presenter->page($refsForA, User::getAnonymousUser(), 'media', 0, 10);
$hidden = $anonCards['rows'] !== [];
foreach ($anonCards['rows'] as $card) {
  if ($card['title'] !== '无权查看' || $card['edit'] !== '' || $card['view'] !== '') {
    $hidden = FALSE;
  }
}
$check('anonymous reference card does not expose the title', $hidden);
if (!\Drupal::moduleHandler()->moduleExists('paragraphs')) {
  $skipped++;
  echo "SKIP paragraph module is not installed\n";
}
$check('solo file is confirmed unused', ($finding($fidSolo)['category'] ?? '') === 'confirmed_unused', (string) ($finding($fidSolo)['reason'] ?? ''));
$check('missing physical file is separate', ($finding($fidMissing)['category'] ?? '') === 'missing_physical', (string) ($finding($fidMissing)['category'] ?? ''));
$orphan = $repo->findings($batchId, ['q' => 'orphan.bin', 'category' => 'missing_entity'], 10, 0);
$check('unmanaged file is not called unused', (int) $orphan['total'] >= 1);
$css = $repo->findings($batchId, ['q' => '/css/', 'category' => 'missing_entity'], 5, 0);
$check('css aggregate is not an unmanaged candidate', (int) $css['total'] === 0);
$config = $repo->findings($batchId, ['q' => '/config_', 'category' => 'missing_entity'], 5, 0);
$check('config directory is not an unmanaged candidate', (int) $config['total'] === 0);

$refsA = $repo->refsForFid($batchId, $fidA);
$sawRevision = FALSE;
foreach ($refsA as $ref) {
  if ($ref['entity_type'] === 'media' && (int) $ref['revision_id'] === $oldVid && $ref['source'] === 'field') {
    $sawRevision = TRUE;
  }
}
$check('historical media revision is recorded', $sawRevision);
$refsUser = $repo->refsForFid($batchId, $fidTrDrop);
$langs = [];
foreach ($refsUser as $ref) {
  if ($ref['entity_type'] === 'user' && $ref['source'] === 'field') {
    $langs[] = (string) $ref['langcode'];
  }
}
$check('user picture translations are recorded', in_array('en', $langs, TRUE) && $langs !== [], implode(',', $langs));

$manager = \Drupal::service('xedc_core.cleanup_manager');
$refused = $manager->createQuarantinePlan([$fidA, $fidEmbedded, $fidMissing, $fidShared], 1);
$check('referenced and unknown files are refused', $refused['ok'] === FALSE);
$plan = $manager->createQuarantinePlan([$fidSolo], 1);
$check('unused file can form a plan', $plan['ok'] === TRUE, implode(' ', $plan['errors']));
$ids['tasks'][] = (int) $plan['task_id'];
$before = $manager->step((int) $plan['task_id']);
$check('unconfirmed task does not run', $before === 'idle', $before);
$still = File::load($fidSolo);
$check('unconfirmed file is still in place', $still && is_file($fs->realpath($still->getFileUri())));

$merge = $manager->createMergePlan((int) $groupA['group']['id'], $fidB, 1);
$check('merge plan is created', $merge['ok'] === TRUE, implode(' ', $merge['errors']));
$ids['tasks'][] = (int) $merge['task_id'];
$mergeTask = $repo->loadTask((int) $merge['task_id']);
$check('merge plan has no blockers', ($mergeTask['payload']['blocked'] ?? []) === []);
$confirmed = $manager->confirm((int) $merge['task_id'], 1);
$check('merge can be confirmed', $confirmed['ok'] === TRUE, $confirmed['message']);
$mergeResult = $manager->step((int) $merge['task_id']);
$check('merge executes', $mergeResult === 'done', $mergeResult . ' ' . (string) ($repo->loadTask((int) $merge['task_id'])['error'] ?? ''));
$mergeReport = $repo->loadTask((int) $merge['task_id'])['result']['integrity']['status'] ?? '';
$check('merge integrity passed', $mergeReport === 'pass', (string) $mergeReport);
$check('merge batch size stays at 40', $manager->migrationBatchSize() === 40);
\Drupal::state()->set('xedc_cleanup_migration_batch', 80);
$check('batch size cannot be raised above 40', $manager->migrationBatchSize() === 40);
\Drupal::state()->set('xedc_cleanup_migration_batch', 1);
$check('batch size can be lowered for a test', $manager->migrationBatchSize() === 1);
\Drupal::state()->delete('xedc_cleanup_migration_batch');
$tooMany = $manager->createQuarantinePlan(range(900000, 900050), 1);
$check('more than 50 files are refused before any quarantine', $tooMany['ok'] === FALSE && str_contains(implode(' ', $tooMany['errors']), '50'), implode(' ', $tooMany['errors']));
$old = \Drupal::entityTypeManager()->getStorage('media')->loadRevision($oldVid);
$new = \Drupal::entityTypeManager()->getStorage('media')->load((int) $media->id());
$oldTarget = (int) $old->get('field_media_image')->target_id;
$newTarget = (int) $new->get('field_media_image')->target_id;
$check('old revision now points at the kept file', $oldTarget === $fidB, (string) $oldTarget);
$check('current revision was not rewritten', $newTarget === $fidOther, (string) $newTarget);

\Drupal::state()->set('xedc_cleanup_fail_after_saves', 1);
$trGroup = $membersOf($fidTrDrop);
$trPlan = $manager->createMergePlan((int) ($trGroup['group']['id'] ?? 0), $fidTrKeep, 1);
$ids['tasks'][] = (int) ($trPlan['task_id'] ?? 0);
$check('translation merge plan exists', $trPlan['ok'] === TRUE, implode(' ', $trPlan['errors']));
if ($trPlan['ok']) {
  $manager->confirm((int) $trPlan['task_id'], 1);
  $failedStep = $manager->step((int) $trPlan['task_id']);
  $check('interrupted merge fails', $failedStep === 'failed', $failedStep . ' ' . (string) ($repo->loadTask((int) $trPlan['task_id'])['error'] ?? ''));
  \Drupal::entityTypeManager()->getStorage('user')->resetCache([(int) $user->id()]);
  $reloaded = User::load((int) $user->id());
  $defaultPicture = (int) $reloaded->get('user_picture')->target_id;
  $englishPicture = (int) $reloaded->getTranslation('en')->get('user_picture')->target_id;
  $check('interrupted merge restores both translations', $defaultPicture === $fidTrDrop && $englishPicture === $fidTrDrop, $defaultPicture . '/' . $englishPicture . ' expected ' . $fidTrDrop);
}
\Drupal::state()->delete('xedc_cleanup_fail_after_saves');

$manager->confirm((int) $plan['task_id'], 1);
$payload = $repo->loadTask((int) $plan['task_id'])['payload'];
$payload['items'][0]['bytes'] = 1;
$repo->updateTask((int) $plan['task_id'], ['payload' => $payload, 'status' => 'queued']);
$tampered = $manager->step((int) $plan['task_id']);
$check('changed plan invalidates confirmation', $tampered === 'failed', $tampered);
$check('tampered plan did not move the file', is_file($fs->realpath($uriSolo)));

$fresh = $manager->createQuarantinePlan([$fidSolo], 1);
$ids['tasks'][] = (int) $fresh['task_id'];
$manager->confirm((int) $fresh['task_id'], 1);
$quarantineResult = $manager->step((int) $fresh['task_id']);
$check('quarantine succeeds', $quarantineResult === 'done', (string) ($repo->loadTask((int) $fresh['task_id'])['error'] ?? ''));
$solo = File::load($fidSolo);
$check('quarantine URI is private', $solo && str_starts_with($solo->getFileUri(), 'private://xedc-quarantine/'));
$check('original solo path is gone', !is_file($fs->realpath($privateDir) . '/solo.png'));
$quarantinePath = (string) ($repo->items((int) $fresh['task_id'])[0]['path'] ?? '');
$check('quarantine file matches the hash', $guard->isInsideQuarantine($quarantinePath) && $guard->hashFile($quarantinePath, 1000000) === hash('sha256', $png . 'solo'));

$restore = $manager->createFollowUp((int) $fresh['task_id'], 'restore', 1);
$ids['tasks'][] = (int) $restore['task_id'];
$blockedRestore = $manager->step((int) $restore['task_id']);
$check('restore without confirmation does nothing', $blockedRestore === 'idle');
$manager->confirm((int) $restore['task_id'], 1);
$restoreResult = $manager->step((int) $restore['task_id']);
$check('restore succeeds', $restoreResult === 'done', (string) ($repo->loadTask((int) $restore['task_id'])['error'] ?? ''));
$solo = File::load($fidSolo);
$check('restored URI is original', $solo && $solo->getFileUri() === $uriSolo);

$again = $manager->createQuarantinePlan([$fidSolo], 1);
$ids['tasks'][] = (int) $again['task_id'];
$manager->confirm((int) $again['task_id'], 1);
$manager->step((int) $again['task_id']);
file_put_contents($privateReal . '/solo.png', 'conflict-' . $token);
$conflict = $manager->createFollowUp((int) $again['task_id'], 'restore', 1);
$ids['tasks'][] = (int) $conflict['task_id'];
$manager->confirm((int) $conflict['task_id'], 1);
$conflictResult = $manager->step((int) $conflict['task_id']);
$check('restore refuses to overwrite', $conflictResult === 'failed', $conflictResult);
$check('conflicting file is unchanged', is_file($privateReal . '/solo.png') && file_get_contents($privateReal . '/solo.png') === 'conflict-' . $token);
@unlink($privateReal . '/solo.png');

$originalDays = \Drupal::configFactory()->getEditable('xedc_core.settings')->get('file_quarantine_days');
\Drupal::configFactory()->getEditable('xedc_core.settings')->set('file_quarantine_days', 0)->save();
$purge = $manager->createFollowUp((int) $again['task_id'], 'purge', 1);
$ids['tasks'][] = (int) $purge['task_id'];
$manager->confirm((int) $purge['task_id'], 1);
$purgeResult = $manager->step((int) $purge['task_id']);
$check('purge after a second confirmation removes the test file', $purgeResult === 'done', (string) ($repo->loadTask((int) $purge['task_id'])['error'] ?? ''));
$check('purged entity is gone', File::load($fidSolo) === NULL);
\Drupal::configFactory()->getEditable('xedc_core.settings')->set('file_quarantine_days', $originalDays ?? 14)->save();

$bytesOf = static function (string $suffix) use ($png): string {
  return $png . $suffix;
};
$uriBatchDrop = $write($privateDir, 'batch-drop.png', $bytesOf('batch41'));
$uriBatchKeep = $write($privateDir, 'batch-keep.png', $bytesOf('batch41'));
$uriProbeDrop = $write($privateDir, 'probe-drop.png', $bytesOf('probe'));
$uriProbeKeep = $write($privateDir, 'probe-keep.png', $bytesOf('probe'));
$uriRetryDrop = $write($privateDir, 'retry-drop.png', $bytesOf('retry-ok'));
$uriRetryKeep = $write($privateDir, 'retry-keep.png', $bytesOf('retry-ok'));
$uriFailDrop = $write($privateDir, 'fail-drop.png', $bytesOf('retry-fail'));
$uriFailKeep = $write($privateDir, 'fail-keep.png', $bytesOf('retry-fail'));
$uriManualDrop = $write($privateDir, 'manual-drop.png', $bytesOf('manual'));
$uriManualKeep = $write($privateDir, 'manual-keep.png', $bytesOf('manual'));
$uriManualOther = $write($privateDir, 'manual-other.png', $bytesOf('manual-other'));
$uriSnapDrop = $write($privateDir, 'snap-drop.png', $bytesOf('snapshot'));
$uriSnapKeep = $write($privateDir, 'snap-keep.png', $bytesOf('snapshot'));
$fidBatchDrop = $track($make($uriBatchDrop));
$fidBatchKeep = $track($make($uriBatchKeep));
$fidProbeDrop = $track($make($uriProbeDrop));
$fidProbeKeep = $track($make($uriProbeKeep));
$fidRetryDrop = $track($make($uriRetryDrop));
$fidRetryKeep = $track($make($uriRetryKeep));
$fidFailDrop = $track($make($uriFailDrop));
$fidFailKeep = $track($make($uriFailKeep));
$fidManualDrop = $track($make($uriManualDrop));
$fidManualKeep = $track($make($uriManualKeep));
$fidManualOther = $track($make($uriManualOther));
$fidSnapDrop = $track($make($uriSnapDrop));
$fidSnapKeep = $track($make($uriSnapKeep));
$pictureUsers = static function (int $fid, int $count, string $prefix) use (&$ids, $token): array {
  $uids = [];
  for ($i = 0; $i < $count; $i++) {
    $account = User::create([
      'name' => $token . $prefix . $i,
      'mail' => $token . $prefix . $i . '@example.com',
      'status' => 0,
      'pass' => bin2hex(random_bytes(8)),
    ]);
    $account->set('user_picture', ['target_id' => $fid, 'alt' => 'cleanup test']);
    $account->save();
    $uids[] = (int) $account->id();
    $ids['users'][] = (int) $account->id();
  }
  return $uids;
};
$batchUsers = $pictureUsers($fidBatchDrop, 41, 'b');
$retryUsers = $pictureUsers($fidRetryDrop, 2, 'r');
$failUsers = $pictureUsers($fidFailDrop, 2, 'f');
$manualUsers = $pictureUsers($fidManualDrop, 2, 'm');
$snapUsers = $pictureUsers($fidSnapDrop, 2, 's');
$probeSchema = \Drupal::database()->schema();
if ($probeSchema->tableExists('xedc_cleanup_probe')) {
  $probeSchema->dropTable('xedc_cleanup_probe');
}
$probeSchema->createTable('xedc_cleanup_probe', [
  'description' => 'Temporary table for the XEDC cleanup self-test.',
  'fields' => [
    'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
    'fid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
    'uri' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
  ],
  'primary key' => ['id'],
]);
$probeRecord = (int) \Drupal::database()->insert('xedc_cleanup_probe')->fields([
  'fid' => $fidProbeDrop,
  'uri' => $uriProbeDrop,
])->execute();
$suggestConfig = \Drupal::configFactory()->getEditable('xedc_core.settings');
\Drupal::state()->set('xedc_cleanup_test_suggest', ['value' => $suggestConfig->get('file_analysis_suggest')]);
$suggestConfig->set('file_analysis_suggest', 0)->save();
$secondStarted = $runner->start();
$check('second analysis starts', $secondStarted === 'started', $secondStarted);
for ($i = 0; $i < 400 && $secondStarted === 'started'; $i++) {
  $step = $runner->step();
  if ($step !== 'more' && $step !== 'busy') {
    break;
  }
}
$secondSummary = $runner->summary();
$check('second analysis finishes', ($secondSummary['status'] ?? '') === 'done', (string) ($secondSummary['message'] ?? ''));
$suggestConfig->set('file_analysis_suggest', \Drupal::state()->get('xedc_cleanup_test_suggest')['value'] ?? 1)->save();
\Drupal::state()->delete('xedc_cleanup_test_suggest');
$batchId2 = (int) ($secondSummary['finished_batch'] ?? 0);
$groupOf = static function (int $fid) use ($repo, $batchId2): array {
  $groups = $repo->groups($batchId2, [], 200, 0)['rows'];
  foreach ($groups as $group) {
    foreach ($repo->members((int) $group['id']) as $member) {
      if ((int) $member['fid'] === $fid) {
        return $group;
      }
    }
  }
  return [];
};
$pictureCount = static function (array $uids, int $fid): int {
  if ($uids === []) {
    return 0;
  }
  return (int) \Drupal::database()->select('user__user_picture', 'p')
    ->condition('entity_id', $uids, 'IN')
    ->condition('user_picture_target_id', $fid)
    ->countQuery()
    ->execute()
    ->fetchField();
};
$usageSum = static function (int $fid): int {
  return (int) \Drupal::database()->query('SELECT COALESCE(SUM(count), 0) FROM {file_usage} WHERE fid = :fid', [':fid' => $fid])->fetchField();
};
$batchGroup = $groupOf($fidBatchDrop);
$batchPlan = $manager->createMergePlan((int) ($batchGroup['id'] ?? 0), $fidBatchKeep, 1);
$ids['tasks'][] = (int) ($batchPlan['task_id'] ?? 0);
$batchTask = $batchPlan['ok'] ? $repo->loadTask((int) $batchPlan['task_id']) : [];
$batchMigrations = is_array($batchTask['payload']['migrations'] ?? NULL) ? $batchTask['payload']['migrations'] : [];
$check('41 field references were created', count($batchMigrations) === 41 && ($batchTask['payload']['blocked'] ?? []) === [], (string) count($batchMigrations));
if (count($batchMigrations) === 41) {
  $manager->confirm((int) $batchPlan['task_id'], 1);
  $firstBatch = $manager->step((int) $batchPlan['task_id']);
  $midTask = $repo->loadTask((int) $batchPlan['task_id']);
  $check('first batch stops after at most 40', $firstBatch === 'more' && (int) ($midTask['result']['cursor'] ?? 0) === 40, $firstBatch . ' cursor ' . (string) ($midTask['result']['cursor'] ?? ''));
  $secondBatch = $manager->step((int) $batchPlan['task_id']);
  $doneTask = $repo->loadTask((int) $batchPlan['task_id']);
  $check('second batch finishes the remaining reference', $secondBatch === 'done' && (int) ($doneTask['result']['cursor'] ?? 0) === 41, $secondBatch . ' ' . (string) ($doneTask['error'] ?? ''));
  $check('41-reference integrity passed', ($doneTask['result']['integrity']['status'] ?? '') === 'pass', (string) ($doneTask['result']['integrity']['status'] ?? ''));
  $mergeLogs = \Drupal::database()->select('xedc_file_cleanup_log', 'l')
    ->fields('l', ['detail'])
    ->condition('task_id', (int) $batchPlan['task_id'])
    ->condition('op', 'merge')
    ->orderBy('id')
    ->execute()
    ->fetchCol();
  $batchSizes = [];
  foreach ($mergeLogs as $detail) {
    $decoded = json_decode((string) $detail, TRUE);
    $batchSizes[] = (int) ($decoded['batch'] ?? 0);
  }
  $check('batches were 40 and then 1', $batchSizes === [40, 1], implode(',', $batchSizes));
  $check('all 41 references point at the kept file', $pictureCount($batchUsers, $fidBatchKeep) === 41 && $pictureCount($batchUsers, $fidBatchDrop) === 0);
  $journalIds = [];
  foreach ($doneTask['result']['journal'] ?? [] as $entry) {
    $journalIds[] = (int) ($entry['entity_id'] ?? 0);
  }
  $check('each reference was migrated once', count($journalIds) === 41 && count(array_unique($journalIds)) === 41, (string) count($journalIds));
  $keepReal = $fs->realpath(File::load($fidBatchKeep)->getFileUri());
  $check('kept file hash is unchanged', is_string($keepReal) && hash_file('sha256', $keepReal) === hash('sha256', $bytesOf('batch41')));
  $idle = $manager->step((int) $batchPlan['task_id']);
  $check('completed 41-reference task does not migrate again', $idle === 'idle' && $pictureCount($batchUsers, $fidBatchKeep) === 41, $idle);
}

$probeRefs = $repo->refsForFid($batchId2, $fidProbeDrop);
$probeCustom = NULL;
foreach ($probeRefs as $ref) {
  if (($ref['source'] ?? '') === 'custom' && ($ref['entity_type'] ?? '') === 'xedc_cleanup_probe') {
    $probeCustom = $ref;
  }
}
$check('custom table reference is recorded', $probeCustom !== NULL && (int) ($probeCustom['entity_id'] ?? 0) === $probeRecord, (string) ($probeCustom['reason'] ?? ''));
$probeCard = $probeCustom ? $presenter->card($probeCustom, User::load(1)) : [];
$check('reference card shows the table and record', str_contains((string) ($probeCard['title'] ?? ''), 'xedc_cleanup_probe') && str_contains((string) ($probeCard['title'] ?? ''), (string) $probeRecord) && str_contains((string) ($probeCard['reason'] ?? ''), 'xedc_cleanup_probe'));
$probeLive = \Drupal::service('xedc_core.reference_analyzer')->recheck($fidProbeDrop);
$check('pre-merge recheck finds the custom table', ($probeLive['status'] ?? '') === 'referenced' && str_contains((string) ($probeLive['reason'] ?? ''), 'xedc_cleanup_probe'));
$probeGroup = $groupOf($fidProbeDrop);
$probePlan = $manager->createMergePlan((int) ($probeGroup['id'] ?? 0), $fidProbeKeep, 1);
$ids['tasks'][] = (int) ($probePlan['task_id'] ?? 0);
$probeStored = $probePlan['ok'] ? $repo->loadTask((int) $probePlan['task_id']) : [];
$check('custom table blocks the merge plan', $probePlan['ok'] === TRUE && ($probeStored['payload']['blocked'] ?? []) !== []);
$probeConfirm = $manager->confirm((int) ($probePlan['task_id'] ?? 0), 1);
$check('blocked merge cannot be confirmed', $probeConfirm['ok'] === FALSE);
$probeLogs = \Drupal::database()->select('xedc_file_cleanup_log', 'l')
  ->fields('l', ['error', 'detail'])
  ->condition('task_id', (int) ($probePlan['task_id'] ?? 0))
  ->condition('op', 'confirm')
  ->execute()
  ->fetchAll();
$probeLogged = FALSE;
foreach ($probeLogs as $log) {
  $text = (string) $log->error . (string) $log->detail;
  if (str_contains($text, 'xedc_cleanup_probe') || str_contains($text, '不能迁移')) {
    $probeLogged = TRUE;
  }
}
$check('audit log records why the merge was blocked', $probeLogged);
$probeQuarantine = $manager->createQuarantinePlan([$fidProbeDrop], 1);
$check('custom table file cannot be quarantined', $probeQuarantine['ok'] === FALSE);
$purgeBlocked = FALSE;
$purgeMessage = '';
try {
  \Drupal::service('xedc_core.quarantine_manager')->quarantine(0, [
    'fid' => $fidProbeDrop,
    'uri' => $uriProbeDrop,
    'sha256' => hash('sha256', $bytesOf('probe')),
    'filename' => 'probe-drop.png',
  ]);
}
catch (\Throwable $exception) {
  $purgeBlocked = TRUE;
  $purgeMessage = $exception->getMessage();
}
$check('moving a custom-table file is blocked', $purgeBlocked && str_contains($purgeMessage, 'xedc_cleanup_probe'), $purgeMessage);
$purgeThrown = FALSE;
try {
  \Drupal::service('xedc_core.quarantine_manager')->purge([
    'fid' => $fidProbeDrop,
    'sha256' => hash('sha256', $bytesOf('probe')),
    'meta' => ['quarantined' => 1, 'sha256' => hash('sha256', $bytesOf('probe'))],
  ]);
}
catch (\Throwable) {
  $purgeThrown = TRUE;
}
$check('permanent purge of the custom-table file is blocked', $purgeThrown && is_file($fs->realpath($uriProbeDrop)));

$runPair = static function (array $group, int $keep) use ($manager, &$ids): int {
  $plan = $manager->createMergePlan((int) ($group['id'] ?? 0), $keep, 1);
  $ids['tasks'][] = (int) ($plan['task_id'] ?? 0);
  if ($plan['ok']) {
    $manager->confirm((int) $plan['task_id'], 1);
  }
  return (int) ($plan['task_id'] ?? 0);
};
\Drupal::state()->set('xedc_cleanup_pause_after_saves', 1);
$retryId = $runPair($groupOf($fidRetryDrop), $fidRetryKeep);
$retryPaused = $manager->step($retryId);
$retryMid = $repo->loadTask($retryId);
$retryJournal = $retryMid['result']['journal'] ?? [];
$retryDoneUid = (int) ($retryJournal[0]['entity_id'] ?? 0);
$retryPendingUid = $retryDoneUid === $retryUsers[0] ? $retryUsers[1] : $retryUsers[0];
$check('recoverable pause keeps the first migration', $retryPaused === 'retry' && ($retryMid['status'] ?? '') === 'partial' && $pictureCount([$retryDoneUid], $fidRetryKeep) === 1 && $pictureCount([$retryPendingUid], $fidRetryDrop) === 1, $retryPaused . ' ' . (string) ($retryMid['error'] ?? ''));
\Drupal::state()->delete('xedc_cleanup_pause_after_saves');
$usageBeforeRetry = $usageSum($fidRetryKeep);
$manager->resume($retryId, 1);
$retryDone = $manager->step($retryId);
$retryFinal = $repo->loadTask($retryId);
$check('retry completes only the remaining migration', $retryDone === 'done' && ($retryFinal['result']['integrity']['status'] ?? '') === 'pass' && $pictureCount($retryUsers, $fidRetryKeep) === 2 && $pictureCount($retryUsers, $fidRetryDrop) === 0, $retryDone . ' ' . (string) ($retryFinal['error'] ?? ''));
$retryEntities = [];
foreach ($retryFinal['result']['journal'] ?? [] as $entry) {
  $retryEntities[] = (int) ($entry['entity_id'] ?? 0);
}
$check('retry did not migrate the finished reference again', count($retryEntities) === 2 && count(array_unique($retryEntities)) === 2, implode(',', $retryEntities));
$retryUsage = $usageSum($fidRetryKeep);
$check('retry did not duplicate file usage', $retryUsage === $usageBeforeRetry + 1 || ($usageBeforeRetry >= 1 && $retryUsage === 2), $usageBeforeRetry . '->' . $retryUsage);
$retryReal = $fs->realpath(File::load($fidRetryKeep)->getFileUri());
$check('retry kept the same SHA-256', is_string($retryReal) && hash_file('sha256', $retryReal) === hash('sha256', $bytesOf('retry-ok')));
$retryIdle = $manager->step($retryId);
$check('repeating a finished retry does nothing', $retryIdle === 'idle' && $pictureCount($retryUsers, $fidRetryKeep) === 2 && $usageSum($fidRetryKeep) === $retryUsage, $retryIdle);
$repo->updateTask($retryId, [
  'status' => 'queued',
  'result' => [
    'started' => 1,
    'completed' => 0,
    'batch_open' => 0,
    'cursor' => 0,
    'journal' => $retryFinal['result']['journal'] ?? [],
  ],
]);
$rewound = $manager->step($retryId);
$rewoundTask = $repo->loadTask($retryId);
$check('rewinding a finished task stops on the snapshot', $rewound === 'failed' && str_contains((string) ($rewoundTask['error'] ?? ''), '引用关系已变化'), (string) ($rewoundTask['error'] ?? ''));
$check('stopped rewind does not overwrite file ids', $pictureCount($retryUsers, $fidRetryKeep) === 2 && $usageSum($fidRetryKeep) === $retryUsage);

\Drupal::state()->set('xedc_cleanup_pause_after_saves', 1);
$failId = $runPair($groupOf($fidFailDrop), $fidFailKeep);
$failPaused = $manager->step($failId);
$failMid = $repo->loadTask($failId);
$failDoneUid = (int) (($failMid['result']['journal'][0]['entity_id'] ?? 0));
$failPendingUid = $failDoneUid === $failUsers[0] ? $failUsers[1] : $failUsers[0];
\Drupal::state()->delete('xedc_cleanup_pause_after_saves');
\Drupal::state()->set('xedc_cleanup_hold_next', 1);
$manager->resume($failId, 1);
$failAgain = $manager->step($failId);
$failHeld = $repo->loadTask($failId);
$check('a second retry can fail without undoing the first save', $failPaused === 'retry' && $failAgain === 'retry' && ($failHeld['status'] ?? '') === 'partial' && $pictureCount([$failDoneUid], $fidFailKeep) === 1 && $pictureCount([$failPendingUid], $fidFailDrop) === 1, $failAgain . ' ' . (string) ($failHeld['error'] ?? ''));
\Drupal::state()->delete('xedc_cleanup_hold_next');
\Drupal::state()->set('xedc_cleanup_crash_before_execute', 1);
$manager->resume($failId, 1);
$crashed = $manager->step($failId);
$interrupted = $manager->step($failId);
$failInterrupted = $repo->loadTask($failId);
$check('an interrupted retry keeps completed migrations', $crashed === 'crashed' && $interrupted === 'interrupted' && ($failInterrupted['status'] ?? '') === 'partial' && $pictureCount([$failDoneUid], $fidFailKeep) === 1 && $pictureCount([$failPendingUid], $fidFailDrop) === 1, $crashed . '/' . $interrupted);
\Drupal::state()->delete('xedc_cleanup_crash_before_execute');
$manager->resume($failId, 1);
$failFinished = $manager->step($failId);
$check('retry succeeds after the interruption', $failFinished === 'done' && $pictureCount($failUsers, $fidFailKeep) === 2 && $pictureCount($failUsers, $fidFailDrop) === 0, $failFinished);

\Drupal::state()->set('xedc_cleanup_pause_after_saves', 1);
$manualId = $runPair($groupOf($fidManualDrop), $fidManualKeep);
$manualPaused = $manager->step($manualId);
$manualMid = $repo->loadTask($manualId);
$manualDoneUid = (int) (($manualMid['result']['journal'][0]['entity_id'] ?? 0));
$manualPendingUid = $manualDoneUid === $manualUsers[0] ? $manualUsers[1] : $manualUsers[0];
\Drupal::state()->delete('xedc_cleanup_pause_after_saves');
$pendingAccount = User::load($manualPendingUid);
$pendingAccount->set('user_picture', ['target_id' => $fidManualOther, 'alt' => 'changed']);
$pendingAccount->save();
$manager->resume($manualId, 1);
$manualStep = $manager->step($manualId);
$manualTask = $repo->loadTask($manualId);
\Drupal::entityTypeManager()->getStorage('user')->resetCache([$manualDoneUid, $manualPendingUid]);
$manualResume = $manager->resume($manualId, 1);
$check('a changed entity stops for a person', $manualPaused === 'retry' && $manualStep === 'manual' && ($manualTask['status'] ?? '') === 'needs_review' && str_contains((string) ($manualTask['error'] ?? ''), '人工'), $manualStep . ' ' . (string) ($manualTask['error'] ?? ''));
$check('the changed file id was not overwritten', (int) User::load($manualPendingUid)->get('user_picture')->target_id === $fidManualOther && (int) User::load($manualDoneUid)->get('user_picture')->target_id === $fidManualKeep && $manualResume['ok'] === FALSE);

\Drupal::state()->set('xedc_cleanup_pause_after_saves', 1);
$snapId = $runPair($groupOf($fidSnapDrop), $fidSnapKeep);
$snapPaused = $manager->step($snapId);
$snapMid = $repo->loadTask($snapId);
$snapDoneUid = (int) (($snapMid['result']['journal'][0]['entity_id'] ?? 0));
$snapPendingUid = $snapDoneUid === $snapUsers[0] ? $snapUsers[1] : $snapUsers[0];
\Drupal::state()->delete('xedc_cleanup_pause_after_saves');
$snapPayload = $snapMid['payload'];
$snapPayload['migrations'][1]['entity_id'] = 0;
$snapHash = DuplicateMath::planHash($snapPayload);
$repo->updateTask($snapId, [
  'payload' => $snapPayload,
  'plan_hash' => $snapHash,
  'confirmed_hash' => $snapHash,
]);
$manager->resume($snapId, 1);
$snapStep = $manager->step($snapId);
$snapTask = $repo->loadTask($snapId);
\Drupal::entityTypeManager()->getStorage('user')->resetCache([$snapDoneUid, $snapPendingUid]);
$check('a snapshot mismatch stops the retry', $snapPaused === 'retry' && $snapStep === 'manual' && str_contains((string) ($snapTask['error'] ?? ''), '快照'), $snapStep . ' ' . (string) ($snapTask['error'] ?? ''));
$check('snapshot mismatch does not change the remaining file', (int) User::load($snapPendingUid)->get('user_picture')->target_id === $fidSnapDrop && (int) User::load($snapDoneUid)->get('user_picture')->target_id === $fidSnapKeep);

$editor = User::create([
  'name' => $token . 'editor',
  'mail' => $token . 'editor@example.com',
  'status' => 0,
  'pass' => bin2hex(random_bytes(16)),
  'roles' => ['content_editor'],
]);
$editor->save();
$ids['users'][] = (int) $editor->id();
$role = \Drupal::entityTypeManager()->getStorage('user_role')->load('content_editor');
$check('editor lacks purge permission', $role && !$role->hasPermission('confirm xedc file purge'));
$check('editor cannot open duplicates', !\Drupal::accessManager()->checkNamedRoute('xedc_core.files_duplicates', [], $editor));
$check('editor cannot open reference details', !\Drupal::accessManager()->checkNamedRoute('xedc_core.files_reference', ['fid' => 1], $editor));
$check('editor cannot open cleanup tasks', !\Drupal::accessManager()->checkNamedRoute('xedc_core.files_tasks', [], $editor));
$check('anonymous cannot open file stats', !\Drupal::accessManager()->checkNamedRoute('xedc_core.files', [], User::getAnonymousUser()));

$logs = $repo->logs(['task_id' => (int) $fresh['task_id']], 20, 0);
$check('quarantine wrote an audit row', (int) $logs['total'] > 0);

echo "RESULT passed=$passed failed=$failed skipped=$skipped\n";
$cleanup();
if ($failed > 0) {
  throw new RuntimeException('File cleanup self-test failed.');
}
