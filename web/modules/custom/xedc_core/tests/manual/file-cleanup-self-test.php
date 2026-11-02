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

$formats = \Drupal::entityQuery('filter_format')->accessCheck(FALSE)->execute();
$format = isset($formats['basic_html']) ? 'basic_html' : (string) array_key_first($formats);
$node = Node::create([
  'type' => 'photo',
  'title' => 'XEDC cleanup ' . $token,
  'status' => 0,
  'moderation_state' => 'draft',
  'uid' => 1,
  'field_images' => [['target_id' => $media->id()]],
  'field_body' => [
    'value' => 'embedded /sites/default/files/xedc-cleanup-test/' . $token . '/embedded.png',
    'format' => $format,
  ],
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
  $check('interrupted merge fails', $failedStep === 'failed', $failedStep);
  $reloaded = User::load((int) $user->id());
  $defaultPicture = (int) $reloaded->get('user_picture')->target_id;
  $englishPicture = (int) $reloaded->getTranslation('en')->get('user_picture')->target_id;
  $check('interrupted merge restores both translations', $defaultPicture === $fidTrDrop && $englishPicture === $fidTrDrop, $defaultPicture . '/' . $englishPicture);
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
$check('editor cannot open cleanup tasks', !\Drupal::accessManager()->checkNamedRoute('xedc_core.files_tasks', [], $editor));
$check('anonymous cannot open file stats', !\Drupal::accessManager()->checkNamedRoute('xedc_core.files', [], User::getAnonymousUser()));

$logs = $repo->logs(['task_id' => (int) $fresh['task_id']], 20, 0);
$check('quarantine wrote an audit row', (int) $logs['total'] > 0);

echo "RESULT passed=$passed failed=$failed\n";
$cleanup();
if ($failed > 0) {
  throw new RuntimeException('File cleanup self-test failed.');
}
