<?php

/**
 * @file
 * Isolated archive performance checks. Does not modify task 158.
 *
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/archive-perf-self-test.php
 */

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\xedc_core\Controller\ImportController;
use Drupal\xedc_core\ImportException;
use Drupal\xedc_core\ImportStatus;

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

$archive = \Drupal::service('xedc_core.archive');
$processor = \Drupal::service('xedc_core.processor');
$tasks = \Drupal::service('xedc_core.tasks');
$filesystem = \Drupal::service('file_system');
$switcher = \Drupal::service('account_switcher');
$nids = [];
$dirs = [];

$rmTree = static function (string $dir) use (&$rmTree): void {
  if (!is_dir($dir)) {
    return;
  }
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($iterator as $file) {
    $path = $file->getPathname();
    if ($file->isLink() || $file->isFile()) {
      unlink($path);
    }
    else {
      rmdir($path);
    }
  }
  rmdir($dir);
};

$extract = static function (string $method, string $source, string $dest) use ($archive, &$dirs, $rmTree): array {
  if (is_dir($dest)) {
    $rmTree($dest);
  }
  mkdir($dest, 0775, TRUE);
  $dirs[] = $dest;
  $ref = new ReflectionMethod($archive, $method);
  $ref->setAccessible(TRUE);
  $started = microtime(TRUE);
  $error = '';
  $skipped = -1;
  try {
    $skipped = (int) $ref->invoke($archive, $source, $dest);
  }
  catch (Throwable $e) {
    $error = $e->getMessage();
  }
  $elapsed = (int) round((microtime(TRUE) - $started) * 1000);
  $count = 0;
  $bytes = 0;
  if (is_dir($dest)) {
    $scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS));
    foreach ($scan as $file) {
      if ($file->isFile() && !$file->isLink()) {
        $count++;
        $bytes += (int) $file->getSize();
      }
    }
  }
  return ['ms' => $elapsed, 'error' => $error, 'skipped' => $skipped, 'count' => $count, 'bytes' => $bytes];
};

$jpeg = static function (string $path, int $width, int $height): void {
  $image = imagecreatetruecolor($width, $height);
  imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 30, 90, 160));
  imagejpeg($image, $path, 85);
  imagedestroy($image);
};

$zipTo = static function (string $dest, array $entries): void {
  $zip = new ZipArchive();
  if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    throw new RuntimeException('无法创建测试 ZIP');
  }
  foreach ($entries as $name => $value) {
    if (is_string($value) && is_file($value)) {
      $zip->addFile($value, $name);
    }
    else {
      $zip->addFromString($name, (string) $value);
    }
  }
  $zip->close();
};

$cleanupTask = static function (int $nid) use ($tasks, $filesystem): void {
  $database = \Drupal::database();
  $node = Node::load($nid);
  $mids = [];
  if ($node) {
    $mids = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
    $cover = (int) $node->get('field_cover_image')->target_id;
    if ($cover) {
      $mids[] = $cover;
    }
    $node->delete();
  }
  foreach ($tasks->forNode($nid) as $row) {
    foreach ($tasks->items((int) $row->id) as $item) {
      if (!empty($item->mid)) {
        $mids[] = (int) $item->mid;
      }
    }
    $database->delete('xedc_import_item')->condition('task_id', (int) $row->id)->execute();
    $database->delete('xedc_import_log')->condition('task_id', (int) $row->id)->execute();
    $database->delete('queue')->condition('name', 'xedc_image_import')->condition('data', serialize(['task_id' => (int) $row->id]))->execute();
    $dir = $filesystem->realpath('private://xedc-import/tasks/' . (int) $row->id);
    $root = $filesystem->realpath('private://xedc-import/tasks');
    if ($dir && $root && str_starts_with($dir, $root . DIRECTORY_SEPARATOR) && is_dir($dir)) {
      $filesystem->deleteRecursive($dir);
    }
    $database->delete('xedc_import_task')->condition('id', (int) $row->id)->execute();
  }
  foreach (array_unique($mids) as $mid) {
    $media = Media::load($mid);
    if (!$media) {
      continue;
    }
    $usedElsewhere = (int) $database->select('node__field_images', 'f')
      ->condition('field_images_target_id', $mid)
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($usedElsewhere > 0) {
      continue;
    }
    $fid = $media->hasField('field_media_image') ? (int) $media->get('field_media_image')->target_id : 0;
    $file = $fid ? File::load($fid) : NULL;
    $media->delete();
    if ($file instanceof FileInterface) {
      $still = (int) $database->select('media__field_media_image', 'm')
        ->condition('field_media_image_target_id', $fid)
        ->countQuery()
        ->execute()
        ->fetchField();
      if ($still < 1 && $database->schema()->tableExists('xedc_storage_variant')) {
        $database->delete('xedc_storage_variant')->condition('fid', $fid)->execute();
      }
      if ($still < 1) {
        $file->delete();
      }
    }
  }
};

$wait = static function (int $taskId, int $seconds) use ($tasks): ?object {
  $end = time() + $seconds;
  $last = NULL;
  while (time() < $end) {
    $last = $tasks->load($taskId);
    if ($last && ImportStatus::isTerminal((string) $last->status)) {
      return $last;
    }
    sleep(2);
  }
  return $last;
};

try {
  $switcher->switchTo(User::load(1));
  $root = '/tmp/xedc-archive-perf-' . getmypid();
  mkdir($root, 0775, TRUE);
  $dirs[] = $root;

  $realZip = '/var/www/drupal/private/xedc-import/tasks/158/source/gachig129.zip';
  $before158 = $tasks->load(158);
  $check('任务 158 在测试前已结束', $before158 && ImportStatus::isTerminal((string) $before158->status), (string) ($before158->status ?? 'missing'));
  if (is_file($realZip)) {
    $real = $extract('extractZip', $realZip, $root . '/real');
    $check('真实 28MB ZIP 重新解压', $real['error'] === '' && $real['count'] === 137, $real['ms'] . ' ms, files=' . $real['count'] . ', bytes=' . $real['bytes'] . ($real['error'] ? ' ' . $real['error'] : ''));
    echo "METRIC real_extract_ms={$real['ms']} real_files={$real['count']} real_bytes={$real['bytes']}\n";
  }
  else {
    $fail('真实 28MB ZIP 重新解压', '源压缩包不在私有目录');
  }

  $small = $root . '/one.jpg';
  $jpeg($small, 320, 240);
  $smallZip = $root . '/small.zip';
  $zipTo($smallZip, ['one.jpg' => $small]);
  $smallResult = $extract('extractZip', $smallZip, $root . '/small');
  $check('小型 ZIP 解压', $smallResult['error'] === '' && $smallResult['count'] === 1, $smallResult['ms'] . ' ms');

  for ($i = 1; $i <= 100; $i++) {
    $path = $root . '/s' . $i . '.jpg';
    $jpeg($path, 32, 32);
    $entries[sprintf('batch/img-%03d.jpg', $i)] = $path;
  }
  $manyZip = $root . '/many.zip';
  $zipTo($manyZip, $entries);
  $many = $extract('extractZip', $manyZip, $root . '/many');
  $check('100 张小图 ZIP 解压', $many['error'] === '' && $many['count'] === 100, $many['ms'] . ' ms, bytes=' . $many['bytes']);
  echo "METRIC many_extract_ms={$many['ms']}\n";

  $large = $root . '/large.jpg';
  $pixelStarted = microtime(TRUE);
  $jpeg($large, 4000, 3000);
  $pixelMs = (int) round((microtime(TRUE) - $pixelStarted) * 1000);
  $info = getimagesize($large);
  $webp = $root . '/large.webp';
  $encodeStarted = microtime(TRUE);
  $image = imagecreatefromjpeg($large);
  $encoded = imagewebp($image, $webp, 85);
  imagedestroy($image);
  $encodeMs = (int) round((microtime(TRUE) - $encodeStarted) * 1000);
  $check('大分辨率 JPEG 本地 WebP', $encoded && is_file($webp) && (int) ($info[0] ?? 0) === 4000, 'create=' . $pixelMs . ' ms encode=' . $encodeMs . ' ms webp=' . filesize($webp));
  echo "METRIC large_encode_ms={$encodeMs} large_bytes=" . filesize($large) . " webp_bytes=" . filesize($webp) . "\n";

  $sevenDir = $root . '/seven-src';
  mkdir($sevenDir);
  $jpeg($sevenDir . '/a.jpg', 160, 120);
  file_put_contents($sevenDir . '/note.txt', 'not an image');
  $seven = $root . '/sample.7z';
  $command = ['/usr/lib/7zip/7z', 'a', '-y', '-bso0', '-bsp0', $seven, 'a.jpg', 'note.txt'];
  $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $sevenDir);
  stream_get_contents($pipes[1]);
  stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $sevenCode = proc_close($process);
  $sevenResult = $sevenCode === 0 ? $extract('extractSevenZip', $seven, $root . '/seven') : ['error' => '7z exit ' . $sevenCode, 'count' => 0, 'ms' => 0];
  $check('7z 只解压图片', ($sevenResult['error'] ?? '') === '' && ($sevenResult['count'] ?? 0) === 1 && !is_file($root . '/seven/note.txt'), ($sevenResult['ms'] ?? 0) . ' ms files=' . ($sevenResult['count'] ?? 0) . ' skipped=' . ($sevenResult['skipped'] ?? ''));

  $bomb = $root . '/bomb.zip';
  $zipTo($bomb, ['bomb.jpg' => str_repeat("\0", 40 * 1024 * 1024)]);
  $bombResult = $extract('extractZip', $bomb, $root . '/bomb');
  $check('高压缩比压缩包被拒绝', $bombResult['error'] !== '' && $bombResult['count'] === 0, $bombResult['error'] . ' written=' . $bombResult['bytes']);

  $slip = $root . '/slip.zip';
  $zipTo($slip, ['../xedc-slip-canary.jpg' => 'nope']);
  $slipResult = $extract('extractZip', $slip, $root . '/slip');
  $check('路径穿越被拒绝', str_contains($slipResult['error'], '不安全') && !is_file('/tmp/xedc-slip-canary.jpg'), $slipResult['error']);

  $tooMany = [];
  for ($i = 0; $i < 2001; $i++) {
    $tooMany['n' . $i . '.jpg'] = 'x';
  }
  $countZip = $root . '/count.zip';
  $zipTo($countZip, $tooMany);
  $countResult = $extract('extractZip', $countZip, $root . '/count');
  $check('超过 2000 个文件被拒绝', $countResult['error'] !== '' && $countResult['count'] === 0, $countResult['error'] . ' written=' . $countResult['count']);

  $controller = new ImportController($tasks);
  $progress = json_decode($controller->progress(158)->getContent(), TRUE);
  $check('进度来自任务 158', is_array($progress) && ($progress['id'] ?? 0) === 158 && ($progress['total'] ?? 0) === 137 && ($progress['success'] ?? 0) === 137, (string) ($progress['detail'] ?? ''));
  $check('完成后不显示预计剩余', !array_key_exists('eta_seconds', $progress) || $progress['eta_seconds'] === NULL, 'eta=' . var_export($progress['eta_seconds'] ?? NULL, TRUE));
  echo 'METRIC progress=' . json_encode($progress, JSON_UNESCAPED_UNICODE) . "\n";

  $inboxDir = 'private://xedc-import/inbox';
  $filesystem->prepareDirectory($inboxDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  $inbox = $filesystem->copy($smallZip, 'private://xedc-import/inbox/perf-small-' . getmypid() . '.zip', FileExists::Rename);
  $node = Node::create([
    'type' => 'photo',
    'title' => 'XEDC压缩包性能验收 小图',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  xedc_core_import_payload('set', [
    'is_new' => TRUE,
    'mode' => 'create',
    'fid' => 0,
    'server' => basename($inbox),
  ]);
  $node->save();
  $nids[] = (int) $node->id();
  $taskId = (int) ($tasks->forNode((int) $node->id())[0]->id ?? 0);
  $started = microtime(TRUE);
  $done = $wait($taskId, 180);
  $elapsed = (int) round((microtime(TRUE) - $started) * 1000);
  $check('关闭页面后小 ZIP 由后台完成', $done && (string) $done->status === ImportStatus::REVIEW && (int) $done->success_count === 1, (string) ($done->status ?? 'none') . ' ' . $elapsed . ' ms');
  $item = $tasks->items($taskId)[0] ?? NULL;
  if ($item) {
    echo 'METRIC e2e_ms=' . $elapsed . ' optimize_ms=' . (int) ($item->optimize_ms ?? 0) . ' store_ms=' . (int) ($item->store_ms ?? 0) . ' elapsed_ms=' . (int) ($item->elapsed_ms ?? 0) . "\n";
  }

  $pair = [];
  $jpeg($root . '/p1.jpg', 200, 200);
  $jpeg($root . '/p2.jpg', 220, 180);
  $pairZip = $root . '/pair.zip';
  $zipTo($pairZip, ['p1.jpg' => $root . '/p1.jpg', 'p2.jpg' => $root . '/p2.jpg']);
  $pairInbox = $filesystem->copy($pairZip, 'private://xedc-import/inbox/perf-pair-' . getmypid() . '.zip', FileExists::Rename);
  \Drupal::state()->set('xedc_import_fail_next', TRUE);
  $pairNode = Node::create([
    'type' => 'photo',
    'title' => 'XEDC压缩包性能验收 重试',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  xedc_core_import_payload('set', [
    'is_new' => TRUE,
    'mode' => 'create',
    'fid' => 0,
    'server' => basename($pairInbox),
  ]);
  $pairNode->save();
  $nids[] = (int) $pairNode->id();
  $pairId = (int) ($tasks->forNode((int) $pairNode->id())[0]->id ?? 0);
  $partial = $wait($pairId, 180);
  $check('一张失败后任务为部分失败', $partial && (string) $partial->status === ImportStatus::PARTIAL && (int) $partial->failed_count === 1 && (int) $partial->success_count === 1, (string) ($partial->status ?? 'none') . ' ok=' . (int) ($partial->success_count ?? 0) . ' bad=' . (int) ($partial->failed_count ?? 0));
  $processor->retry($pairId);
  $retried = $wait($pairId, 180);
  $check('失败图片重试后完成', $retried && (string) $retried->status === ImportStatus::REVIEW && (int) $retried->failed_count === 0 && (int) $retried->success_count === 2, (string) ($retried->status ?? 'none') . ' ok=' . (int) ($retried->success_count ?? 0));

  $jpeg($root . '/c1.jpg', 1600, 1200);
  $jpeg($root . '/c2.jpg', 1600, 1400);
  $crashZip = $root . '/crash.zip';
  $zipTo($crashZip, ['c1.jpg' => $root . '/c1.jpg', 'c2.jpg' => $root . '/c2.jpg']);
  $crashInbox = $filesystem->copy($crashZip, 'private://xedc-import/inbox/perf-crash-' . getmypid() . '.zip', FileExists::Rename);
  $crashNode = Node::create([
    'type' => 'photo',
    'title' => 'XEDC压缩包性能验收 恢复',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  xedc_core_import_payload('set', [
    'is_new' => TRUE,
    'mode' => 'create',
    'fid' => 0,
    'server' => basename($crashInbox),
  ]);
  $crashNode->save();
  $nids[] = (int) $crashNode->id();
  $crashId = (int) ($tasks->forNode((int) $crashNode->id())[0]->id ?? 0);
  $killed = FALSE;
  $killDetail = '没有看到处理中的任务';
  $killEnd = time() + 90;
  while (time() < $killEnd) {
    $row = $tasks->load($crashId);
    if ($row && !ImportStatus::isTerminal((string) $row->status) && in_array((string) $row->status, ImportStatus::processing(), TRUE)) {
      $pid = 0;
      foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdfile) {
        $cmd = @file_get_contents($cmdfile);
        if (is_string($cmd) && str_contains($cmd, 'xedc:import-work')) {
          $pid = (int) basename(dirname($cmdfile));
          break;
        }
      }
      if ($pid > 0 && posix_kill($pid, 9)) {
        $killed = TRUE;
        $killDetail = 'killed ' . $pid . ' at ' . $row->status . ' ok=' . (int) $row->success_count;
      }
      else {
        $killDetail = 'pid=' . $pid . ' status=' . $row->status;
      }
      break;
    }
    if ($row && ImportStatus::isTerminal((string) $row->status)) {
      $killDetail = '已结束 ' . $row->status . ' ok=' . (int) $row->success_count;
      break;
    }
    usleep(100000);
  }
  $check('处理中终止工作进程', $killed, $killDetail);
  $recovered = $wait($crashId, 180);
  $check('进程被杀死后任务恢复完成', $recovered && (string) $recovered->status === ImportStatus::REVIEW && (int) $recovered->success_count === 2 && (int) $recovered->failed_count === 0, (string) ($recovered->status ?? 'none') . ' ok=' . (int) ($recovered->success_count ?? 0));
}
catch (Throwable $e) {
  $fail('测试脚本异常', $e->getMessage());
}
finally {
  \Drupal::state()->delete('xedc_import_fail_next');
  foreach ($nids as $nid) {
    try {
      $cleanupTask((int) $nid);
    }
    catch (Throwable $e) {
      echo "CLEANUP {$nid}: {$e->getMessage()}\n";
    }
  }
  foreach (array_reverse($dirs) as $dir) {
    $rmTree($dir);
  }
  $switcher->switchBack();
}

$bad = 0;
foreach ($results as $row) {
  if (!$row[1]) {
    $bad++;
  }
}
echo 'RESULT ' . (count($results) - $bad) . '/' . count($results) . " failed={$bad}\n";
