<?php

/**
 * @file
 * Acceptance: member save stamps import tasks so clients can drop archive jobs.
 *
 * Run: vendor/bin/drush php:script web/modules/custom/xedc_core/tests/manual/archive-saved-cleanup-acceptance.php
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\xedc_core\ImportStatus;

$results = [];
$pass = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
  $results[] = ['name' => $name, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
  echo ($ok ? 'PASS' : 'FAIL') . "\t{$name}" . ($detail !== '' ? "\t{$detail}" : '') . PHP_EOL;
};

$web = \Drupal::root();
$js = file_get_contents($web . '/modules/custom/xedc_core/js/upload-manager.js') ?: '';
$pass('js_has_reconcile', str_contains($js, 'reconcileSavedJobs') && str_contains($js, 'dismissJob'));
$pass('js_poll_uses_status', str_contains($js, 'Prefer machine status') || str_contains($js, 'json.status'));
$pass('js_hides_saved', str_contains($js, "HIDDEN_PHASES") && str_contains($js, "'saved'"));
$pass('js_skips_membersaved_restore', str_contains($js, 'row.memberSaved'));

$repoSrc = file_get_contents($web . '/modules/custom/xedc_core/src/TaskRepository.php') ?: '';
$pass('repo_mark_member_saved', str_contains($repoSrc, 'function markMemberSaved') && str_contains($repoSrc, 'member_saved_at'));

/** @var \Drupal\xedc_core\TaskRepository $tasks */
$tasks = \Drupal::service('xedc_core.tasks');

$member = User::create([
  'name' => 'arc_save_' . substr(uniqid('', TRUE), -8),
  'mail' => 'arc_save_' . time() . '@example.invalid',
  'status' => 1,
]);
$member->save();

$node = Node::create([
  'type' => 'photo',
  'title' => 'Archive saved cleanup ' . date('H:i:s'),
  'uid' => (int) $member->id(),
  'status' => 0,
]);
if ($node->hasField('moderation_state')) {
  $node->set('moderation_state', 'draft');
}
$node->save();

$taskId = $tasks->create([
  'uid' => (int) $member->id(),
  'nid' => (int) $node->id(),
  'status' => ImportStatus::REVIEW,
  'stage' => 'done',
  'progress' => 100,
  'mode' => 'create',
  'archive_name' => 'cleanup-test.zip',
  'archive_uri' => 'temporary://cleanup-test.zip',
  'archive_format' => 'zip',
  'archive_bytes' => 2048,
  'source' => 'upload',
  'total' => 2,
  'processed' => 2,
  'success_count' => 2,
  'snapshot' => json_encode(['images' => [], 'cover' => 0], JSON_UNESCAPED_UNICODE),
]);

$row = $tasks->load($taskId);
$pass('task_created', (bool) $row, 'task=' . $taskId);
$pass('not_saved_initially', $row && !$tasks->isMemberSaved($row));

$first = $tasks->markMemberSaved((int) $node->id(), (int) $member->id());
$row2 = $tasks->load($taskId);
$pass('mark_member_saved_count', $first === 1, 'updated=' . $first);
$pass('is_member_saved', $row2 && $tasks->isMemberSaved($row2));
$snap = json_decode((string) ($row2->snapshot ?? ''), TRUE);
$pass('snapshot_keeps_images_key', is_array($snap) && array_key_exists('images', $snap) && !empty($snap['member_saved_at']));

$second = $tasks->markMemberSaved((int) $node->id(), (int) $member->id());
$pass('mark_idempotent', $second === 0, 'updated=' . $second);

// Progress payload includes member_saved for clients.
$controller = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_core\Controller\ImportController::class);
$response = $controller->progress($taskId);
$data = json_decode((string) $response->getContent(), TRUE);
$pass('progress_member_saved_flag', !empty($data['member_saved']));
$pass('progress_keeps_status', ($data['status'] ?? '') === ImportStatus::REVIEW);

// Simulate member node update path (non-import-internal).
$node->setTitle($node->getTitle() . ' saved');
$node->save();
$row3 = $tasks->load($taskId);
$pass('node_update_keeps_saved', $row3 && $tasks->isMemberSaved($row3));

// Cleanup.
\Drupal::database()->delete('xedc_import_task')->condition('id', $taskId)->execute();
if ($fresh = Node::load($node->id())) {
  $fresh->delete();
}
if ($freshUser = User::load($member->id())) {
  $freshUser->delete();
}

$failed = array_filter($results, static fn(array $r): bool => $r['status'] === 'FAIL');
echo PHP_EOL . 'SUMMARY' . "\t" . (count($failed) ? 'FAIL' : 'PASS') . "\t" . (count($results) - count($failed)) . '/' . count($results) . PHP_EOL;
return count($failed) ? 1 : 0;
