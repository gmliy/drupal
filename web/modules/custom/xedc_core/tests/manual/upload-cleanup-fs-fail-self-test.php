<?php

/**
 * @file
 * Real filesystem delete-failure for cancelled upload session cleanup.
 *
 * Creates an isolated session under private://xedc-upload, makes the parent
 * directory non-writable so deleteRecursive fails, then restores permissions
 * and retries cleanup. Does not touch production Worker queues or user files
 * outside the test session directory.
 *
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/upload-cleanup-fs-fail-self-test.php
 */

declare(strict_types=1);

use Drupal\Core\Session\UserSession;
use Drupal\node\Entity\Node;

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
$switcher->switchTo(new UserSession(['uid' => 1]));

$jpeg = tempnam(sys_get_temp_dir(), 'xedc_fsfail_');
$img = imagecreatetruecolor(48, 48);
imagejpeg($img, $jpeg, 85);
imagedestroy($img);
$bytes = filesize($jpeg);
$sha = hash_file('sha256', $jpeg);
$stream = static function (string $path) {
  $h = fopen($path, 'rb');
  if ($h === FALSE) {
    throw new \RuntimeException('open failed');
  }
  return $h;
};

$node = Node::create([
  'type' => 'photo',
  'title' => 'fs-fail-cleanup-' . getmypid(),
  'uid' => 1,
  'status' => 0,
]);
$node->save();
$sid = '';
$parent = '';
$sessionDir = '';
$immutableTarget = '';
$chattr = trim((string) shell_exec('command -v chattr'));

try {
  $open = $uploads->open([
    'filename' => 'fsfail.jpg',
    'size' => $bytes,
    'kind' => 'image',
    'head_sha256' => $sha,
    'file_sha256' => $sha,
    'nid' => (int) $node->id(),
    'client_upload_id' => bin2hex(random_bytes(16)),
  ]);
  $check('创建隔离 Session', ($open['http'] ?? 0) === 201, (string) ($open['http'] ?? ''));
  $sid = (string) ($open['data']['session_id'] ?? '');
  $recv = $uploads->receive($sid, 0, $stream($jpeg), $sha);
  $check('写入测试分片', ($recv['http'] ?? 0) === 200, (string) ($recv['http'] ?? ''));

  $sessionDir = $filesystem->realpath('private://xedc-upload/1/' . $sid);
  $parent = is_string($sessionDir) ? dirname($sessionDir) : '';
  $check('测试目录存在', is_string($sessionDir) && is_dir($sessionDir), (string) $sessionDir);
  $check('父目录是用户上传隔离路径', is_string($parent) && str_ends_with($parent, '/xedc-upload/1'), (string) $parent);

  if (!is_string($parent) || !is_dir($parent)) {
    echo "SUMMARY pass=$pass fail=$fail\n";
    exit(1);
  }

  // Root bypasses chmod; use immutable attribute when available so even root
  // cannot unlink the chunk until chattr -i is restored.
  $chunkFiles = glob($sessionDir . '/c-*') ?: [];
  $immutableTarget = $chunkFiles[0] ?? ($sessionDir . '/hold.bin');
  if (!is_file($immutableTarget)) {
    file_put_contents($immutableTarget, 'hold');
  }
  $chattr = trim((string) shell_exec('command -v chattr'));
  $immutable = FALSE;
  if ($chattr !== '') {
    exec(escapeshellcmd($chattr) . ' +i ' . escapeshellarg($immutableTarget) . ' 2>&1', $out, $code);
    $immutable = ($code === 0);
  }
  $check('制造真实不可删除文件(chattr +i)', $immutable, $immutable ? $immutableTarget : 'chattr unavailable');

  if (!$immutable) {
    echo "NOTE  无法 chattr +i，本项标记后按 NOT TESTED 处理并跳过强制失败路径。\n";
  }
  else {
    $cancel = $uploads->cancel($sid);
    $row = $database->select('xedc_upload_session', 's')->fields('s')->condition('session_id', $sid)->execute()->fetchObject();
    $check('取消 HTTP 成功（清理可失败）', ($cancel['http'] ?? 0) === 200, (string) ($cancel['http'] ?? ''));
    $check('Session 保持 cancelled', $row && (string) $row->status === 'cancelled', (string) ($row->status ?? ''));
    $check('不会创建 Worker 任务', (int) ($row->task_id ?? 0) === 0 && !\Drupal::service('xedc_core.tasks')->findLinkedToUploadSession($sid));
    $cleanup = (string) ($cancel['data']['cleanup'] ?? '');
    $dirStillThere = is_dir($sessionDir) || is_file($immutableTarget);
    $check('cleanup_status=failed', ($cleanup === 'failed' || (string) ($row->cleanup_status ?? '') === 'failed'), 'cleanup=' . $cleanup . ' db=' . (string) ($row->cleanup_status ?? ''));
    $check('不可删除文件仍然存在', $dirStillThere, $immutableTarget);

    // Restore mutability and retry cleanup.
    exec(escapeshellcmd($chattr) . ' -i ' . escapeshellarg($immutableTarget) . ' 2>&1', $out2, $code2);
    $check('解除 chattr +i', $code2 === 0);
    if ($database->schema()->fieldExists('xedc_upload_session', 'cleanup_status')) {
      $database->update('xedc_upload_session')->fields([
        'status' => 'cancelled',
        'cleanup_status' => 'failed',
        'cleanup_error' => 'awaiting retry after chattr',
        'task_id' => 0,
      ])->condition('session_id', $sid)->execute();
    }
    $uploads->purgeExpired();
    $after = $database->select('xedc_upload_session', 's')->fields('s')->condition('session_id', $sid)->execute()->fetchObject();
    $uri = 'private://xedc-upload/1/' . $sid;
    $gone = !$filesystem->realpath($uri) || !is_dir((string) $filesystem->realpath($uri));
    $check('恢复后 Session 仍为 cancelled', $after && (string) $after->status === 'cancelled', (string) ($after->status ?? ''));
    $check('恢复后 cleanup 完成', $after && (string) ($after->cleanup_status ?? '') === 'completed', (string) ($after->cleanup_status ?? ''));
    $check('测试目录已清理', $gone);
    $check('重试不会恢复上传', $after && (string) $after->status === 'cancelled' && (int) $after->task_id === 0);
  }
}
finally {
  if (!empty($immutableTarget) && is_file($immutableTarget) && !empty($chattr)) {
    @exec(escapeshellcmd($chattr) . ' -i ' . escapeshellarg($immutableTarget) . ' 2>/dev/null');
  }
  if (is_string($parent) && is_dir($parent)) {
    @chmod($parent, 0755);
  }
  @unlink($jpeg);
  if ($sid !== '') {
    $database->delete('xedc_upload_chunk')->condition('session_id', $sid)->execute();
    try {
      $uri = 'private://xedc-upload/1/' . $sid;
      $real = $filesystem->realpath($uri);
      if (is_string($real)) {
        foreach (glob($real . '/*') ?: [] as $f) {
          if (is_file($f)) {
            @exec('chattr -i ' . escapeshellarg($f) . ' 2>/dev/null');
          }
        }
        $filesystem->deleteRecursive($uri);
      }
    }
    catch (\Throwable $e) {
      // ignore
    }
  }
  $switcher->switchBack();
}

echo "SUMMARY pass=$pass fail=$fail\n";
exit($fail > 0 ? 1 : 0);
