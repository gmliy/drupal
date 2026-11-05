<?php

/**
 * @file
 * Cancel vs complete atomicity, mid-upload cleanup, and handoff protection.
 *
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/upload-cancel-handoff-self-test.php
 */

declare(strict_types=1);

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\UserSession;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
  if ($ok) {
    $pass++;
    echo "PASS  $label\n";
    return;
  }
  $fail++;
  echo "FAIL  $label" . ($detail !== '' ? " :: $detail" : '') . "\n";
};

$database = \Drupal::database();
$filesystem = \Drupal::service('file_system');
$uploads = \Drupal::service('xedc_core.upload_session');
$switcher = \Drupal::service('account_switcher');
$tasks = \Drupal::service('xedc_core.tasks');

$switcher->switchTo(new UserSession(['uid' => 1]));

$jpeg = tempnam(sys_get_temp_dir(), 'xedc_cancel_');
$img = imagecreatetruecolor(64, 64);
imagejpeg($img, $jpeg, 90);
imagedestroy($img);
$bytes = filesize($jpeg);
$head = hash_file('sha256', $jpeg);
$fileSha = $head;
$stream = static function (string $path) {
  $h = fopen($path, 'rb');
  if ($h === FALSE) {
    throw new \RuntimeException('cannot open ' . $path);
  }
  return $h;
};

$node = Node::create([
  'type' => 'photo',
  'title' => 'cancel-handoff-' . getmypid(),
  'uid' => 1,
  'status' => 0,
]);
$node->save();
$nid = (int) $node->id();
$sessionIds = [];

try {
  // A. Local-style cancel after partial chunk upload + cleanup.
  $open = $uploads->open([
    'filename' => 'half.jpg',
    'size' => $bytes,
    'kind' => 'image',
    'head_sha256' => $head,
    'file_sha256' => $fileSha,
    'nid' => $nid,
    'client_upload_id' => bin2hex(random_bytes(16)),
  ]);
  $check('创建上传会话', ($open['http'] ?? 0) === 201, (string) ($open['data']['error'] ?? $open['http']));
  $sid = (string) ($open['data']['session_id'] ?? '');
  $sessionIds[] = $sid;
  $recv = $uploads->receive($sid, 0, $stream($jpeg), $fileSha);
  $check('写入分块', ($recv['http'] ?? 0) === 200, (string) ($recv['data']['error'] ?? $recv['http']));
  $dir = $filesystem->realpath('private://xedc-upload/1/' . $sid);
  $check('分块目录存在', is_string($dir) && is_dir($dir), (string) $dir);
  $cancel = $uploads->cancel($sid);
  $check('取消成功', ($cancel['http'] ?? 0) === 200 && ($cancel['data']['outcome'] ?? '') === 'cancelled', json_encode($cancel['data'] ?? [], JSON_UNESCAPED_UNICODE));
  $check('取消后 cleanup 完成或失败有记录', in_array(($cancel['data']['cleanup'] ?? ''), ['completed', 'failed'], TRUE), (string) ($cancel['data']['cleanup'] ?? ''));
  $row = $database->select('xedc_upload_session', 's')->fields('s')->condition('session_id', $sid)->execute()->fetchObject();
  $check('会话状态 cancelled', $row && (string) $row->status === 'cancelled', (string) ($row->status ?? ''));
  $check('分块表已清空', (int) $database->select('xedc_upload_chunk', 'c')->condition('session_id', $sid)->countQuery()->execute()->fetchField() === 0);
  $check('临时目录已删除', !is_string($dir) || !is_dir($dir), is_string($dir) && is_dir($dir) ? 'dir remains' : 'gone');
  $again = $uploads->cancel($sid);
  $check('重复取消幂等', ($again['http'] ?? 0) === 200 && ($again['data']['outcome'] ?? '') === 'already_cancelled', (string) ($again['data']['outcome'] ?? $again['http']));
  $late = $uploads->receive($sid, 0, $stream($jpeg), $fileSha);
  $check('取消后拒绝新分块', ($late['http'] ?? 0) === 409, (string) ($late['http'] ?? ''));
  $lateComplete = $uploads->complete($sid, ['file_sha256' => $fileSha]);
  $check('取消后拒绝 complete', ($lateComplete['http'] ?? 0) === 409 && ($lateComplete['data']['outcome'] ?? '') === 'cancelled', json_encode($lateComplete['data'] ?? [], JSON_UNESCAPED_UNICODE));
  $check('取消后无导入任务', !$tasks->findByUploadToken($sid));

  // B. complete wins: cancel must return handed_off and keep task.
  $open2 = $uploads->open([
    'filename' => 'full.jpg',
    'size' => $bytes,
    'kind' => 'image',
    'head_sha256' => $head,
    'file_sha256' => $fileSha,
    'nid' => $nid,
    'client_upload_id' => bin2hex(random_bytes(16)),
  ]);
  $sid2 = (string) ($open2['data']['session_id'] ?? '');
  $sessionIds[] = $sid2;
  $uploads->receive($sid2, 0, $stream($jpeg), $fileSha);
  $done = $uploads->complete($sid2, ['file_sha256' => $fileSha]);
  $check('complete 成功', ($done['http'] ?? 0) === 200 && (int) ($done['data']['task_id'] ?? 0) > 0, json_encode($done['data'] ?? [], JSON_UNESCAPED_UNICODE));
  $taskId = (int) ($done['data']['task_id'] ?? 0);
  $cancel2 = $uploads->cancel($sid2);
  $check('交接后取消冲突', ($cancel2['http'] ?? 0) === 409 && ($cancel2['data']['outcome'] ?? '') === 'handed_off', json_encode($cancel2['data'] ?? [], JSON_UNESCAPED_UNICODE));
  $task = $taskId > 0 ? $tasks->load($taskId) : NULL;
  $check('导入任务仍在', $task !== NULL, 'missing task ' . $taskId);
  $dupComplete = $uploads->complete($sid2, ['file_sha256' => $fileSha]);
  $check('重复 complete 幂等', ($dupComplete['http'] ?? 0) === 200 && (int) ($dupComplete['data']['task_id'] ?? 0) === $taskId, (string) ($dupComplete['data']['task_id'] ?? ''));

  // C. cancel wins before complete: claim cancel then complete fails.
  $open3 = $uploads->open([
    'filename' => 'race.jpg',
    'size' => $bytes,
    'kind' => 'image',
    'head_sha256' => $head,
    'file_sha256' => $fileSha,
    'nid' => $nid,
    'client_upload_id' => bin2hex(random_bytes(16)),
  ]);
  $sid3 = (string) ($open3['data']['session_id'] ?? '');
  $sessionIds[] = $sid3;
  $uploads->receive($sid3, 0, $stream($jpeg), $fileSha);
  $c3 = $uploads->cancel($sid3);
  $check('竞态准备：cancel 获胜', ($c3['http'] ?? 0) === 200, (string) ($c3['data']['outcome'] ?? ''));
  $p3 = $uploads->complete($sid3, ['file_sha256' => $fileSha]);
  $check('cancel 后 complete 失败', ($p3['http'] ?? 0) === 409, (string) ($p3['http'] ?? '') . ' ' . ($p3['data']['outcome'] ?? ''));
  $check('cancel 后无 task', !$tasks->findByUploadToken($sid3));

  // D. True concurrent cancel+complete via two separate PHP processes
  // (independent DB connections). Single-threaded sequential calls are not used.
  $open4 = $uploads->open([
    'filename' => 'concurrent.jpg',
    'size' => $bytes,
    'kind' => 'image',
    'head_sha256' => $head,
    'file_sha256' => $fileSha,
    'nid' => $nid,
    'client_upload_id' => bin2hex(random_bytes(16)),
  ]);
  $sid4 = (string) ($open4['data']['session_id'] ?? '');
  $sessionIds[] = $sid4;
  $uploads->receive($sid4, 0, $stream($jpeg), $fileSha);
  $script = DRUPAL_ROOT . '/modules/custom/xedc_core/tests/manual/upload-race-one-shot.php';
  $drush = dirname(DRUPAL_ROOT) . '/vendor/bin/drush';
  if (!is_file($drush)) {
    $drush = 'drush';
  }
  $outCancel = sys_get_temp_dir() . '/xedc_race_c_' . getmypid() . '.json';
  $outComplete = sys_get_temp_dir() . '/xedc_race_p_' . getmypid() . '.json';
  @unlink($outCancel);
  @unlink($outComplete);
  $launch = sprintf(
    'cd %s && XEDC_RACE_OP=cancel XEDC_RACE_SID=%s XEDC_RACE_SHA=%s %s php:script %s >%s 2>/dev/null & p1=$!; XEDC_RACE_OP=complete XEDC_RACE_SID=%s XEDC_RACE_SHA=%s %s php:script %s >%s 2>/dev/null & p2=$!; wait $p1; wait $p2',
    escapeshellarg(dirname(DRUPAL_ROOT)),
    escapeshellarg($sid4),
    escapeshellarg($fileSha),
    escapeshellarg($drush),
    escapeshellarg($script),
    escapeshellarg($outCancel),
    escapeshellarg($sid4),
    escapeshellarg($fileSha),
    escapeshellarg($drush),
    escapeshellarg($script),
    escapeshellarg($outComplete)
  );
  exec($launch);
  $parseOut = static function (string $path): array {
    $raw = is_file($path) ? (string) file_get_contents($path) : '';
    @unlink($path);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));
    for ($i = count($lines) - 1; $i >= 0; $i--) {
      $try = json_decode($lines[$i], TRUE);
      if (is_array($try) && isset($try['http'])) {
        return $try;
      }
    }
    return ['http' => 0, 'data' => ['outcome' => 'unknown', 'error' => 'empty', 'raw' => $raw]];
  };
  $cancelRes = $parseOut($outCancel);
  $completeRes = $parseOut($outComplete);
  $final = $database->select('xedc_upload_session', 's')->fields('s', ['status', 'task_id'])->condition('session_id', $sid4)->execute()->fetchObject();
  $finalStatus = (string) ($final->status ?? '');
  $finalTask = (int) ($final->task_id ?? 0);
  $linked = $tasks->findByUploadToken($sid4);
  $cancelOk = (int) ($cancelRes['http'] ?? 0) === 200 && in_array((string) ($cancelRes['data']['outcome'] ?? ''), ['cancelled', 'already_cancelled'], TRUE);
  $completeOk = (int) ($completeRes['http'] ?? 0) === 200 && (int) ($completeRes['data']['task_id'] ?? 0) > 0;
  // Image handoff may store task_id on the session without upload_token lookup.
  $taskRow = $finalTask > 0 ? $tasks->load($finalTask) : NULL;
  $validCancelWin = $finalStatus === 'cancelled' && $finalTask === 0 && !$taskRow;
  $validCompleteWin = $finalStatus === 'completed' && $finalTask > 0 && $taskRow !== NULL;
  $doubleWin = $cancelOk && $completeOk;
  $check('真实并发进程仅一个权威终态', ($validCancelWin xor $validCompleteWin) && !$doubleWin, json_encode([
    'status' => $finalStatus,
    'task' => $finalTask,
    'cancel' => [$cancelRes['http'] ?? 0, $cancelRes['data']['outcome'] ?? ''],
    'complete' => [$completeRes['http'] ?? 0, $completeRes['data']['outcome'] ?? '', $completeRes['data']['task_id'] ?? 0],
    'task_exists' => $taskRow ? 1 : 0,
    'token_link' => $linked ? (int) $linked->id : 0,
  ], JSON_UNESCAPED_UNICODE));
  if ($validCancelWin) {
    $check('并发 cancel 胜：无 Worker 任务', TRUE);
    $uploads->cancel($sid4);
  }
  if ($validCompleteWin) {
    $blocked = $uploads->cancel($sid4);
    $check('并发 complete 胜：后续 cancel 冲突', ($blocked['http'] ?? 0) === 409 && ($blocked['data']['outcome'] ?? '') === 'handed_off', (string) ($blocked['data']['outcome'] ?? ''));
  }
  $raceNote = 'TESTED (two drush processes)';

  // E. cleanup failure recording: mark cleanup_failed then retry via purge.
  $open5 = $uploads->open([
    'filename' => 'cleanup.jpg',
    'size' => $bytes,
    'kind' => 'image',
    'head_sha256' => $head,
    'file_sha256' => $fileSha,
    'nid' => $nid,
    'client_upload_id' => bin2hex(random_bytes(16)),
  ]);
  $sid5 = (string) ($open5['data']['session_id'] ?? '');
  $sessionIds[] = $sid5;
  $uploads->receive($sid5, 0, $stream($jpeg), $fileSha);
  $uploads->cancel($sid5);
  $database->update('xedc_upload_session')
    ->fields([
      'cleanup_status' => 'failed',
      'cleanup_error' => 'self-test forced failure',
      'status' => 'cancelled',
      'task_id' => 0,
    ])
    ->condition('session_id', $sid5)
    ->execute();
  // Recreate a leftover file under the session path to verify retry cleanup.
  $uri = 'private://xedc-upload/1/' . $sid5;
  $filesystem->prepareDirectory($uri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  $filesystem->saveData('leftover', $uri . '/leftover.bin', \Drupal\Core\File\FileExists::Replace);
  $retried = $uploads->purgeExpired();
  $after = $database->select('xedc_upload_session', 's')->fields('s', ['cleanup_status', 'status'])->condition('session_id', $sid5)->execute()->fetchObject();
  $check('清理失败可重试', $after && (string) $after->status === 'cancelled' && (string) $after->cleanup_status === 'completed', json_encode($after ?: [], JSON_UNESCAPED_UNICODE) . ' purged=' . $retried);
  $leftReal = $filesystem->realpath($uri);
  $check('重试后临时目录删除', !is_string($leftReal) || !is_dir($leftReal));

  // F. Permission: other user cannot cancel.
  $member = User::create([
    'name' => 'xedc_cancel_' . getmypid(),
    'mail' => 'xedc_cancel_' . getmypid() . '@example.com',
    'status' => 1,
  ]);
  $member->save();
  $open6 = $uploads->open([
    'filename' => 'perm.jpg',
    'size' => $bytes,
    'kind' => 'image',
    'head_sha256' => $head,
    'file_sha256' => $fileSha,
    'nid' => $nid,
    'client_upload_id' => bin2hex(random_bytes(16)),
  ]);
  $sid6 = (string) ($open6['data']['session_id'] ?? '');
  $sessionIds[] = $sid6;
  $switcher->switchTo(new UserSession(['uid' => (int) $member->id()]));
  $stolen = $uploads->cancel($sid6);
  $check('其他用户不能取消', ($stolen['http'] ?? 0) === 404, (string) ($stolen['http'] ?? ''));
  $switcher->switchTo(new UserSession(['uid' => 1]));
  $uploads->cancel($sid6);

  echo "RACE_CONCURRENT=$raceNote\n";
}
finally {
  @unlink($jpeg);
  foreach ($sessionIds as $id) {
    if ($id === '') {
      continue;
    }
    $database->delete('xedc_upload_chunk')->condition('session_id', $id)->execute();
    // Keep cancelled/completed rows for audit but remove leftover trees.
    try {
      $uri = 'private://xedc-upload/1/' . $id;
      if ($filesystem->realpath($uri)) {
        $filesystem->deleteRecursive($uri);
      }
    }
    catch (\Throwable $e) {
      // ignore
    }
  }
  if ($node->id()) {
    // Leave draft node; mark for manual cleanup by title prefix if needed.
  }
  $switcher->switchBack();
}

echo "SUMMARY pass=$pass fail=$fail\n";
exit($fail > 0 ? 1 : 0);
