<?php

/**
 * @file
 * Resumable upload session checks. Creates only prefixed drafts and deletes them.
 *
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/resumable-upload-self-test.php
 */

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\UserSession;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\xedc_core\Upload\UploadSessionManager;

$results = [];
$pass = static function (string $name, string $detail = 'ok') use (&$results): void {
  $results[] = [$name, TRUE, $detail];
  echo "PASS {$name}: {$detail}\n";
};
$fail = static function (string $name, string $detail) use (&$results): void {
  $results[] = [$name, FALSE, $detail];
  echo "FAIL {$name}: {$detail}\n";
};
$check = static function (string $name, bool $ok, string $detail) use ($pass, $fail): void {
  $ok ? $pass($name, $detail) : $fail($name, $detail);
};

$prefix = 'XEDC续传验收 ' . date('YmdHis');
$work = '/tmp/xedc-resume-' . getmypid();
@mkdir($work, 0775, TRUE);
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new UserSession(['uid' => 1]));
/** @var \Drupal\xedc_core\Upload\UploadSessionManager $uploads */
$uploads = \Drupal::service('xedc_core.upload_session');
$config = \Drupal::configFactory()->getEditable('xedc_core.settings');
$saved = [];
foreach (['member_max_image_bytes', 'member_max_archive_bytes', 'upload_max_active_user', 'upload_chunk_bytes'] as $key) {
  $saved[$key] = $config->get($key);
}
$filesystem = \Drupal::service('file_system');
$database = \Drupal::database();
$tasks = \Drupal::service('xedc_core.tasks');
$member = NULL;
$sentinel = 'private://xedc-import/inbox/' . preg_replace('/\s+/', '-', $prefix) . '-sentinel.txt';

$stream = static function (string $path) {
  $handle = fopen($path, 'rb');
  if ($handle === FALSE) {
    throw new RuntimeException('打不开 ' . $path);
  }
  return $handle;
};
$slice = static function (string $path, int $offset, int $length) use ($work): string {
  $target = $work . '/slice-' . $offset . '-' . $length;
  $in = fopen($path, 'rb');
  $out = fopen($target, 'wb');
  fseek($in, $offset);
  $left = $length;
  while ($left > 0 && !feof($in)) {
    $buf = fread($in, min(1048576, $left));
    if ($buf === '') {
      break;
    }
    fwrite($out, $buf);
    $left -= strlen($buf);
  }
  fclose($in);
  fclose($out);
  return $target;
};
$sendAll = static function (string $sessionId, string $path, array $session, array $only = []) use ($uploads, $slice, $stream): array {
  $chunk = (int) $session['chunk_bytes'];
  $indexes = $only ?: range(0, (int) $session['chunk_count'] - 1);
  $sent = 0;
  $last = [];
  foreach ($indexes as $index) {
    $offset = $index * $chunk;
    $length = min($chunk, (int) $session['total_bytes'] - $offset);
    $part = $slice($path, $offset, $length);
    $sha = hash_file('sha256', $part);
    $handle = $stream($part);
    try {
      $last = $uploads->receive($sessionId, $index, $handle, $sha);
    }
    finally {
      fclose($handle);
    }
    if ($last['http'] !== 200) {
      return [$last, $sent];
    }
    $sent += $length;
  }
  return [$last, $sent];
};

try {
  $image = imagecreatetruecolor(40, 30);
  $color = imagecolorallocate($image, 20, 90, 160);
  imagefilledrectangle($image, 0, 0, 39, 29, $color);
  $jpeg = $work . '/风景照片.jpg';
  imagejpeg($image, $jpeg, 85);
  imagedestroy($image);
  $zipPath = $work . '/图集.zip';
  $zip = new ZipArchive();
  $zip->open($zipPath, ZipArchive::CREATE);
  $zip->addFile($jpeg, '风景/one.jpg');
  $zip->close();
  $seven = $work . '/图集.7z';
  $packed = 0;
  exec('7z a -bd -y ' . escapeshellarg($seven) . ' ' . escapeshellarg($jpeg) . ' >/tmp/xedc-7z-pack.log 2>&1', $unused, $packed);

  $node = Node::create([
    'type' => 'photo',
    'title' => $prefix . ' 图片',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $node->xedc_keep_unpublished = TRUE;
  $node->save();
  $nid = (int) $node->id();

  $head = hash_file('sha256', $jpeg);
  $opened = $uploads->open([
    'filename' => '风景照片.jpg',
    'size' => filesize($jpeg),
    'kind' => 'image',
    'mime' => 'image/jpeg',
    'head_sha256' => $head,
    'nid' => $nid,
    'title' => $prefix . ' 图片',
  ]);
  $check('中文 JPEG 创建会话', $opened['http'] === 201 && ($opened['data']['tier'] ?? '') === 'admin', json_encode(['http' => $opened['http'], 'error' => $opened['data']['error'] ?? '', 'chunks' => $opened['data']['chunk_count'] ?? 0], JSON_UNESCAPED_UNICODE));
  if ($opened['http'] === 201) {
    $sessionId = (string) $opened['data']['session_id'];
    [$first, $sent] = $sendAll($sessionId, $jpeg, $opened['data']);
    $check('JPEG 分块写入', $first['http'] === 200 && (int) $first['data']['received_bytes'] === filesize($jpeg), 'http=' . $first['http'] . ' bytes=' . ($first['data']['received_bytes'] ?? 0) . ' sent=' . $sent);
    $part = $slice($jpeg, 0, (int) $opened['data']['chunk_bytes'] > filesize($jpeg) ? filesize($jpeg) : (int) filesize($jpeg));
    $again = $uploads->receive($sessionId, 0, $stream($part), hash_file('sha256', $part));
    $check('重复分块不累计字节', $again['http'] === 200 && !empty($again['data']['duplicate']) && (int) $again['data']['received_bytes'] === filesize($jpeg), 'bytes=' . ($again['data']['received_bytes'] ?? 0));
    $bad = $uploads->receive($sessionId, 0, $stream($part), hash('sha256', 'nope'));
    $check('错误校验值被拒绝', $bad['http'] === 409 || $bad['http'] === 400, 'http=' . $bad['http']);
    $done = $uploads->complete($sessionId, []);
    $taskId = (int) ($done['data']['task_id'] ?? 0);
    $check('JPEG 合并后创建任务', $done['http'] === 200 && $taskId > 0, 'http=' . $done['http'] . ' task=' . $taskId . ' ' . ($done['data']['error'] ?? $done['data']['message'] ?? ''));
    $beforeItems = (int) $database->select('xedc_import_item', 'i')->condition('relative_path', $sessionId)->countQuery()->execute()->fetchField();
    $againDone = $uploads->complete($sessionId, []);
    $afterItems = (int) $database->select('xedc_import_item', 'i')->condition('relative_path', $sessionId)->countQuery()->execute()->fetchField();
    $check('重复完成不创建第二个任务', $againDone['http'] === 200 && (int) ($againDone['data']['task_id'] ?? 0) === $taskId && $beforeItems === 1 && $afterItems === 1, 'items=' . $afterItems . ' id=' . ($againDone['data']['task_id'] ?? 0));
    $uploadDir = '/var/www/drupal/private/xedc-upload/1/' . $sessionId;
    clearstatcache(TRUE, $uploadDir);
    $check('完成后删除私有分块目录', !is_dir($uploadDir), is_dir($uploadDir) ? 'still there' : 'gone');
  }

  $empty = $uploads->open(['filename' => '空.jpg', 'size' => 0, 'kind' => 'image', 'head_sha256' => hash('sha256', ''), 'nid' => $nid]);
  $check('空文件被拒绝', $empty['http'] === 400, 'http=' . $empty['http']);
  $wrong = $uploads->open(['filename' => '坏.exe', 'size' => 10, 'kind' => 'image', 'head_sha256' => hash('sha256', 'x'), 'nid' => $nid]);
  $check('错误扩展名被拒绝', $wrong['http'] === 400, (string) ($wrong['data']['error'] ?? ''));
  $huge = $uploads->open(['filename' => '太大.jpg', 'size' => 52428800 + 1, 'kind' => 'image', 'head_sha256' => hash('sha256', 'x'), 'nid' => $nid]);
  $check('超过图片大小限制被拒绝', $huge['http'] === 400, (string) ($huge['data']['error'] ?? ''));

  file_put_contents($work . '/坏.jpg', 'not-an-image');
  $brokenHead = hash('sha256', file_get_contents($work . '/坏.jpg'));
  $brokenOpen = $uploads->open([
    'filename' => '坏.jpg',
    'size' => filesize($work . '/坏.jpg'),
    'kind' => 'image',
    'head_sha256' => $brokenHead,
    'nid' => $nid,
  ]);
  $check('损坏图片可以建立会话', $brokenOpen['http'] === 201, (string) ($brokenOpen['data']['error'] ?? $brokenOpen['http']));
  if ($brokenOpen['http'] === 201) {
    [$brokenSend] = $sendAll((string) $brokenOpen['data']['session_id'], $work . '/坏.jpg', $brokenOpen['data']);
    $brokenDone = $uploads->complete((string) $brokenOpen['data']['session_id'], []);
    $brokenTasks = (int) $database->select('xedc_import_task', 't')->condition('upload_token', $brokenOpen['data']['session_id'])->countQuery()->execute()->fetchField();
    $check('损坏图片不创建导入任务', $brokenDone['http'] === 422 && $brokenTasks === 0, 'http=' . $brokenDone['http'] . ' tasks=' . $brokenTasks . ' ' . ($brokenDone['data']['error'] ?? ''));
  }

  $zipNode = Node::create([
    'type' => 'photo',
    'title' => $prefix . ' ZIP',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $zipNode->xedc_keep_unpublished = TRUE;
  $zipNode->save();
  $config->set('upload_chunk_bytes', 1048576)->save();
  file_put_contents($work . '/pad.bin', random_bytes(1200000));
  $zip = new ZipArchive();
  $zip->open($zipPath, ZipArchive::CREATE);
  $zip->addFile($jpeg, '风景/one.jpg');
  $zip->addFile($work . '/pad.bin', 'pad.bin');
  $zip->close();
  $zipHead = hash('sha256', file_get_contents($zipPath, FALSE, NULL, 0, 65536));
  $zipOpen = $uploads->open([
    'filename' => '图集.zip',
    'size' => filesize($zipPath),
    'kind' => 'archive',
    'head_sha256' => $zipHead,
    'nid' => (int) $zipNode->id(),
    'title' => $prefix . ' ZIP',
  ]);
  $check('ZIP 创建会话', $zipOpen['http'] === 201, (string) ($zipOpen['data']['error'] ?? $zipOpen['http']));
  if ($zipOpen['http'] === 201 && (int) $zipOpen['data']['chunk_count'] >= 2) {
    $zipId = (string) $zipOpen['data']['session_id'];
    [$firstZip] = $sendAll($zipId, $zipPath, $zipOpen['data'], [0]);
    $confirmed = (int) ($firstZip['data']['received_bytes'] ?? 0);
    $mid = $uploads->complete($zipId, []);
    $check('缺分块时不能完成', $mid['http'] === 409 && $confirmed === (int) $zipOpen['data']['chunk_bytes'], 'http=' . $mid['http'] . ' confirmed=' . $confirmed . ' missing=' . implode(',', $mid['data']['missing_chunks'] ?? []));
    [, $zipSent] = $sendAll($zipId, $zipPath, $zipOpen['data'], $mid['data']['missing_chunks'] ?? []);
    $check('恢复时只上传缺失分块', $zipSent === filesize($zipPath) - $confirmed, 'resent=' . $zipSent . ' confirmed=' . $confirmed);
    $zipDone = $uploads->complete($zipId, []);
    $zipTasks = (int) $database->select('xedc_import_task', 't')->condition('upload_token', $zipId)->countQuery()->execute()->fetchField();
    $zipAgain = $uploads->complete($zipId, []);
    $zipTasksAfter = (int) $database->select('xedc_import_task', 't')->condition('upload_token', $zipId)->countQuery()->execute()->fetchField();
    $check('ZIP 补齐缺失分块后创建任务', $zipDone['http'] === 200 && (int) ($zipDone['data']['task_id'] ?? 0) > 0 && $zipTasks === 1 && $zipTasksAfter === 1 && (int) ($zipAgain['data']['task_id'] ?? 0) === (int) ($zipDone['data']['task_id'] ?? 0), 'http=' . $zipDone['http'] . ' tasks=' . $zipTasksAfter . ' ' . ($zipDone['data']['message'] ?? $zipDone['data']['error'] ?? ''));
  }
  else {
    $fail('ZIP 分块', 'http=' . $zipOpen['http'] . ' chunks=' . ($zipOpen['data']['chunk_count'] ?? 0) . ' ' . ($zipOpen['data']['error'] ?? ''));
  }

  if ($packed === 0 && is_file($seven)) {
    $sevenNode = Node::create([
      'type' => 'photo',
      'title' => $prefix . ' 7z',
      'uid' => 1,
      'status' => 0,
      'moderation_state' => 'draft',
    ]);
    $sevenNode->xedc_keep_unpublished = TRUE;
    $sevenNode->save();
    $sevenHead = hash('sha256', file_get_contents($seven, FALSE, NULL, 0, 65536));
    $sevenOpen = $uploads->open([
      'filename' => '图集.7z',
      'size' => filesize($seven),
      'kind' => 'archive',
      'head_sha256' => $sevenHead,
      'nid' => (int) $sevenNode->id(),
      'title' => $prefix . ' 7z',
    ]);
    if ($sevenOpen['http'] === 201) {
      [$sevenSent] = $sendAll((string) $sevenOpen['data']['session_id'], $seven, $sevenOpen['data']);
      $sevenDone = $uploads->complete((string) $sevenOpen['data']['session_id'], []);
      $check('7z 上传并创建任务', $sevenDone['http'] === 200 && (int) ($sevenDone['data']['task_id'] ?? 0) > 0, 'http=' . $sevenDone['http'] . ' ' . ($sevenDone['data']['message'] ?? $sevenDone['data']['error'] ?? ''));
    }
    else {
      $fail('7z 上传并创建任务', (string) ($sevenOpen['data']['error'] ?? $sevenOpen['http']));
    }
  }
  else {
    $fail('7z 上传并创建任务', '7z 打包失败，退出码 ' . $packed);
  }

  $foreign = $uploads->open([
    'filename' => '隔离.jpg',
    'size' => filesize($jpeg),
    'kind' => 'image',
    'head_sha256' => hash_file('sha256', $jpeg),
    'nid' => $nid,
  ]);
  $member = User::create([
    'name' => 'xedc_resume_' . getmypid(),
    'mail' => 'xedc_resume_' . getmypid() . '@example.com',
    'status' => 1,
  ]);
  $member->save();
  $switcher->switchTo(new UserSession(['uid' => (int) $member->id()]));
  $stolen = $foreign['http'] === 201 ? $uploads->status((string) $foreign['data']['session_id']) : ['http' => 0, 'data' => []];
  $denied = $uploads->open([
    'filename' => '会员.jpg',
    'size' => 100,
    'kind' => 'image',
    'head_sha256' => hash('sha256', 'x'),
    'nid' => $nid,
  ]);
  $check('其他用户不能读取会话', $stolen['http'] === 404, 'http=' . $stolen['http']);
  $check('普通会员不能使用上传接口', $denied['http'] === 403, 'http=' . $denied['http'] . ' ' . ($denied['data']['error'] ?? ''));
  $switcher->switchTo(new UserSession(['uid' => 1]));

  $config->set('member_max_image_bytes', 1000)->save();
  $limits = $uploads->limits($member);
  $adminLimits = $uploads->limits(\Drupal::currentUser());
  $check('会员和管理员大小限制分开读取', $limits['tier'] === 'member' && $limits['image'] === 1000 && $adminLimits['tier'] === 'admin' && $adminLimits['image'] > 1000, json_encode(['member' => $limits, 'admin' => $adminLimits]));

  if ($foreign['http'] === 201) {
    $cancelled = $uploads->cancel((string) $foreign['data']['session_id']);
    $check('取消上传', $cancelled['http'] === 200 && ($cancelled['data']['status'] ?? '') === 'cancelled', (string) ($cancelled['data']['status'] ?? $cancelled['http']));
  }

  $old = $uploads->open([
    'filename' => '过期.jpg',
    'size' => filesize($jpeg),
    'kind' => 'image',
    'head_sha256' => hash_file('sha256', $jpeg),
    'nid' => $nid,
  ]);
  if ($old['http'] === 201) {
    $oldId = (string) $old['data']['session_id'];
    $uploads->receive($oldId, 0, $stream($jpeg), hash_file('sha256', $jpeg));
    $database->update('xedc_upload_session')->fields(['changed' => time() - 90000, 'status' => 'uploading'])->condition('session_id', $oldId)->execute();
    $inbox = 'private://xedc-import/inbox';
    $filesystem->prepareDirectory($inbox, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $filesystem->saveData('keep', $sentinel, \Drupal\Core\File\FileExists::Replace);
    $purged = $uploads->purgeExpired();
    $left = $database->select('xedc_upload_session', 's')->fields('s', ['status'])->condition('session_id', $oldId)->execute()->fetchField();
    $check('过期会话被清理且不删除收件箱', $purged >= 1 && $left === 'expired' && $filesystem->realpath($sentinel) && is_file($filesystem->realpath($sentinel)), 'purged=' . $purged . ' status=' . $left);
  }

  $public = 'https://gitxx.xyz/xedc-upload/1/secret';
  $root = $filesystem->realpath('private://xedc-upload');
  $web = DRUPAL_ROOT;
  $check('分块目录不在公开 web 根下', is_string($root) && !str_starts_with($root, $web . DIRECTORY_SEPARATOR), $root . ' web=' . $web);
}
finally {
  foreach ($saved as $key => $value) {
    if ($value === NULL) {
      $config->clear($key);
    }
    else {
      $config->set($key, $value);
    }
  }
  $config->save();
  if ($sentinel) {
    $real = $filesystem->realpath($sentinel);
    if ($real && is_file($real)) {
      $filesystem->delete($sentinel);
    }
  }
  $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', $prefix, 'STARTS_WITH')->execute();
  foreach ($ids as $nid) {
    $loaded = Node::load((int) $nid);
    $mids = [];
    if ($loaded) {
      $mids = array_map('intval', array_column($loaded->get('field_images')->getValue(), 'target_id'));
      if ($loaded->hasField('field_cover_image')) {
        $cover = (int) $loaded->get('field_cover_image')->target_id;
        if ($cover) {
          $mids[] = $cover;
        }
      }
      $loaded->delete();
    }
    foreach ($tasks->forNode((int) $nid) as $row) {
      foreach ($tasks->items((int) $row->id) as $item) {
        if (!empty($item->mid)) {
          $mids[] = (int) $item->mid;
        }
      }
      $dir = $filesystem->realpath('private://xedc-import/tasks/' . (int) $row->id);
      $root = $filesystem->realpath('private://xedc-import/tasks');
      if ($dir && $root && str_starts_with($dir, $root . DIRECTORY_SEPARATOR) && is_dir($dir)) {
        $filesystem->deleteRecursive($dir);
      }
      $database->delete('xedc_import_item')->condition('task_id', (int) $row->id)->execute();
      $database->delete('xedc_import_log')->condition('task_id', (int) $row->id)->execute();
      $database->delete('xedc_import_task')->condition('id', (int) $row->id)->execute();
    }
    foreach (array_unique($mids) as $mid) {
      $media = Media::load($mid);
      if (!$media) {
        continue;
      }
      $fid = (int) $media->get('field_media_image')->target_id;
      $media->delete();
      $file = $fid ? File::load($fid) : NULL;
      if ($file instanceof FileInterface) {
        $file->delete();
      }
    }
  }
  if ($database->schema()->tableExists('xedc_upload_session')) {
    $sessionRows = $database->select('xedc_upload_session', 's')->fields('s', ['session_id', 'uid'])->condition('filename', ['风景照片.jpg', '坏.jpg', '图集.zip', '图集.7z', '隔离.jpg', '过期.jpg'], 'IN')->execute()->fetchAll();
    $uploadRoot = $filesystem->realpath('private://xedc-upload');
    foreach ($sessionRows as $sessionRow) {
      $dir = $filesystem->realpath('private://xedc-upload/' . (int) $sessionRow->uid . '/' . $sessionRow->session_id);
      if ($dir && $uploadRoot && str_starts_with($dir, $uploadRoot . DIRECTORY_SEPARATOR) && is_dir($dir)) {
        $filesystem->deleteRecursive($dir);
      }
    }
    $sessionIds = array_map(static fn($sessionRow) => $sessionRow->session_id, $sessionRows);
    if ($sessionIds) {
      $database->delete('xedc_upload_chunk')->condition('session_id', $sessionIds, 'IN')->execute();
      $database->delete('xedc_upload_session')->condition('session_id', $sessionIds, 'IN')->execute();
    }
  }
  if ($member) {
    $member->delete();
  }
  $switcher->switchBack();
  exec('rm -rf ' . escapeshellarg($work));
}

$failed = array_filter($results, static fn(array $row): bool => $row[1] === FALSE);
echo $failed ? 'FAILED ' . count($failed) . '/' . count($results) . "\n" : 'ALL PASS ' . count($results) . "\n";
if ($failed) {
  throw new RuntimeException('resumable upload self-test failed');
}
