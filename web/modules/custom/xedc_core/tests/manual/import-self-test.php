<?php

/**
 * @file
 * Real import checks. Run with: drush php:script web/modules/custom/xedc_core/tests/manual/import-self-test.php
 */

use Drupal\Core\File\FileExists;
use Drupal\Core\Form\FormState;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Session\UserSession;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
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
$pipeline = \Drupal::service('xedc_core.images');
$publisher = \Drupal::service('xedc_core.publisher');
$tasks = \Drupal::service('xedc_core.tasks');
$filesystem = \Drupal::service('file_system');
$switcher = \Drupal::service('account_switcher');
$nids = [];
$uids = [];
$canaries = [];

$cleanup = static function () use (&$nids, &$uids, &$canaries, $filesystem, $tasks): void {
  $database = \Drupal::database();
  foreach ($nids as $nid) {
    $rows = $tasks->forNode((int) $nid);
    $mids = [];
    $node = Node::load($nid);
    if ($node) {
      $mids = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
      $cover = (int) $node->get('field_cover_image')->target_id;
      if ($cover) {
        $mids[] = $cover;
      }
      $node->delete();
    }
    foreach ($rows as $row) {
      $items = $tasks->items((int) $row->id);
      foreach ($items as $item) {
        if (!empty($item->mid)) {
          $mids[] = (int) $item->mid;
        }
      }
      $database->delete('xedc_import_item')->condition('task_id', (int) $row->id)->execute();
      $database->delete('xedc_import_log')->condition('task_id', (int) $row->id)->execute();
      $database->delete('xedc_import_task')->condition('id', (int) $row->id)->execute();
      $database->delete('queue')->condition('name', 'xedc_image_import')->condition('data', '%"task_id";i:' . (int) $row->id . ';%', 'LIKE')->execute();
      $dir = $filesystem->realpath('private://xedc-import/tasks/' . (int) $row->id);
      $root = $filesystem->realpath('private://xedc-import/tasks');
      if ($dir && $root && str_starts_with($dir, $root . DIRECTORY_SEPARATOR) && is_dir($dir)) {
        $filesystem->deleteRecursive($dir);
      }
    }
    $medias = $mids ? Media::loadMultiple(array_unique($mids)) : [];
    foreach ($medias as $media) {
      $usedElsewhere = (int) $database->select('node__field_images', 'f')
        ->condition('field_images_target_id', (int) $media->id())
        ->condition('entity_id', $nids, 'NOT IN')
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
        $usedFile = (int) $database->select('media__field_media_image', 'm')
          ->condition('field_media_image_target_id', $fid)
          ->countQuery()
          ->execute()
          ->fetchField();
        if ($usedFile < 1) {
          $file->delete();
        }
      }
    }
  }
  foreach ($uids as $uid) {
    $user = User::load($uid);
    if ($user) {
      $user->delete();
    }
  }
  foreach ($canaries as $uri) {
    $real = $filesystem->realpath($uri);
    if ($real && is_file($real)) {
      unlink($real);
    }
  }
  @unlink('/tmp/xedc-slip-canary.txt');
};

$image = static function (string $path, string $kind): void {
  $im = imagecreatetruecolor(24, 16);
  if ($kind === 'png') {
    imagesavealpha($im, TRUE);
    imagealphablending($im, FALSE);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, 6, 4, 18, 12, imagecolorallocatealpha($im, 255, 0, 0, 0));
    imagepng($im, $path);
  }
  elseif ($kind === 'webp') {
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 80, 200));
    imagewebp($im, $path, 85);
  }
  elseif ($kind === 'gif') {
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 180, 0));
    imagegif($im, $path);
  }
  else {
    $seed = crc32($path);
    imagefill($im, 0, 0, imagecolorallocate($im, ($seed >> 16) & 255, ($seed >> 8) & 255, $seed & 255));
    imagejpeg($im, $path, 90);
  }
  imagedestroy($im);
};

$zipTo = static function (string $dest, array $entries): void {
  $zip = new ZipArchive();
  if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    throw new RuntimeException('无法创建测试 ZIP');
  }
  foreach ($entries as $name => $value) {
    if (is_file($value)) {
      $zip->addFile($value, $name);
    }
    else {
      $zip->addFromString($name, (string) $value);
    }
  }
  $zip->close();
  if (!is_file($dest)) {
    file_put_contents($dest, "PK\x05\x06" . str_repeat("\0", 18));
  }
};

$sevenTo = static function (string $dest, string $cwd, array $names): void {
  $command = array_merge(['/usr/lib/7zip/7z', 'a', '-y', '-bso0', '-bsp0', $dest], $names);
  $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
  if (!is_resource($process)) {
    throw new RuntimeException('无法创建测试 7Z');
  }
  stream_get_contents($pipes[1]);
  stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $code = proc_close($process);
  if ($code !== 0) {
    throw new RuntimeException('7z 退出码 ' . $code);
  }
};

$stage = static function (string $filename, string $source) use ($filesystem): string {
  $directory = 'private://xedc-import/inbox';
  $filesystem->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
  return $filesystem->copy($source, 'private://xedc-import/inbox/' . $filename, FileExists::Rename);
};

$run = static function (string $title, string $inboxName, array $payloadExtra = []) use ($processor, &$nids): array {
  $node = Node::create([
    'type' => 'photo',
    'title' => $title,
    'uid' => (int) \Drupal::currentUser()->id() ?: 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  xedc_core_import_payload('set', [
    'is_new' => TRUE,
    'mode' => 'create',
    'fid' => 0,
    'server' => $inboxName,
  ] + $payloadExtra);
  $node->save();
  $nids[] = (int) $node->id();
  $existing = \Drupal::service('xedc_core.tasks')->forNode((int) $node->id());
  $taskId = $existing ? (int) $existing[0]->id : 0;
  if ($taskId < 1) {
    return ['node' => $node, 'task' => NULL, 'error' => '未创建导入任务'];
  }
  try {
    $task = $processor->processUntilDone($taskId);
  }
  catch (Throwable $e) {
    $task = \Drupal::service('xedc_core.tasks')->load($taskId);
    return ['node' => Node::load($node->id()), 'task' => $task, 'error' => $e->getMessage()];
  }
  return ['node' => Node::load($node->id()), 'task' => $task, 'error' => ''];
};

try {
  $switcher->switchTo(User::load(1));
  $sorted = $archive->sortFiles([
    ['relative' => 'b/1.jpg', 'bytes' => 1, 'uri' => 'x'],
    ['relative' => 'a/10.jpg', 'bytes' => 1, 'uri' => 'x'],
    ['relative' => 'a/2.jpg', 'bytes' => 1, 'uri' => 'x'],
    ['relative' => 'a/1.jpg', 'bytes' => 1, 'uri' => 'x'],
  ]);
  $order = implode(',', array_column($sorted, 'relative'));
  $check('自然排序', $order === 'a/1.jpg,a/2.jpg,a/10.jpg,b/1.jpg', $order);
  $check('拒绝路径穿越', $archive->unsafeRelative('../etc/passwd') && $archive->unsafeRelative('/etc/passwd'), 'detected');
  $check('允许中文相对路径', !$archive->unsafeRelative('照片/第 1 张.jpg'), 'ok');

  $work = sys_get_temp_dir() . '/xedc-import-src-' . getmypid();
  mkdir($work . '/nested', 0775, TRUE);
  $image($work . '/1.jpg', 'jpg');
  $image($work . '/2.jpg', 'jpg');
  $image($work . '/10.jpg', 'jpg');
  $image($work . '/my photo.jpg', 'jpg');
  $image($work . '/nested/照片.jpg', 'jpg');
  $image($work . '/transparent.png', 'png');
  $image($work . '/still.webp', 'webp');
  $image($work . '/still.gif', 'gif');
  file_put_contents($work . '/animated.gif', hex2bin('47494638396101000100800000FF00000000FF21FF0B4E45545343415045322E30030100000021F904041400FF002C000000000100010000020244010021F904041400FF002C00000000010001000002024401003B'));
  file_put_contents($work . '/notes.txt', 'ignore me');
  file_put_contents($work . '/bad.jpg', 'this is not a jpeg');

  $happy = sys_get_temp_dir() . '/xedc-happy-' . getmypid() . '.zip';
  $zipTo($happy, [
    '10.jpg' => $work . '/10.jpg',
    '2.jpg' => $work . '/2.jpg',
    '1.jpg' => $work . '/1.jpg',
    'my photo.jpg' => $work . '/my photo.jpg',
    '照片/第 1 张.jpg' => $work . '/nested/照片.jpg',
    'dup/a/1.jpg' => $work . '/1.jpg',
    'dup/b/1.jpg' => $work . '/2.jpg',
    'transparent.png' => $work . '/transparent.png',
    'still.webp' => $work . '/still.webp',
    'still.gif' => $work . '/still.gif',
    'animated.gif' => $work . '/animated.gif',
    'notes.txt' => $work . '/notes.txt',
  ]);
  $placed = $stage('happy-' . getmypid() . '.zip', $happy);
  $inboxName = basename($placed);
  $title = 'XEDC导入测试 秀人网美女写真 VOL.123';
  $done = $run($title, $inboxName);
  $task = $done['task'];
  $node = $done['node'];
  $check('正常 ZIP 进入待审核', $task && (string) $task->status === ImportStatus::REVIEW, (string) ($task->status ?? $done['error']));
  $check('跳过非图片', $task && (int) $task->skipped_count >= 1, 'skipped=' . ($task->skipped_count ?? 0));
  $check('新建内容未公开', $node && !$node->isPublished() && $node->get('moderation_state')->value === 'needs_review', (string) ($node ? $node->get('moderation_state')->value : ''));
  $names = [];
  if ($task) {
    foreach ($tasks->successes((int) $task->id) as $item) {
      $names[] = (string) $item->original_name;
    }
  }
  $check('压缩包内自然顺序', isset($names[0], $names[1], $names[2]) && $names[0] === '1.jpg' && $names[1] === '2.jpg' && $names[2] === '10.jpg', implode(',', array_slice($names, 0, 3)));
  $alts = [];
  $bytes = 0;
  $animatedOk = FALSE;
  $webpOk = FALSE;
  $alphaOk = FALSE;
  $renamed = TRUE;
  if ($node && $node->hasField('field_images')) {
    $total = $node->get('field_images')->count();
    $check('图片数量', $total === (int) $task->success_count && $total > 0, (string) $total);
    $seq = 1;
    foreach ($node->get('field_images')->referencedEntities() as $media) {
      $alts[] = (string) $media->get('field_media_image')->alt;
      $file = $media->get('field_media_image')->entity;
      if ($file instanceof FileInterface) {
        $bytes += (int) $file->getSize();
        $filename = $file->getFilename();
        if (!preg_match('/^n' . $node->id() . '-\d+\.(jpg|png|gif|webp)$/', $filename)) {
          $renamed = FALSE;
        }
        $flag = \Drupal::database()->select('xedc_image_file', 'f')->fields('f')->condition('fid', $file->id())->execute()->fetchObject();
        $storedReal = $filesystem->realpath($file->getFileUri());
        if ($flag && $flag->webp === 'animated') {
          $animatedOk = str_ends_with($file->getFilename(), '.gif');
          $raw = $storedReal ? file_get_contents($storedReal) : '';
          $animatedOk = $animatedOk && substr_count((string) $raw, "\x21\xF9\x04") > 1;
        }
        if ((string) $media->label() === 'transparent.png' && $storedReal) {
          $alphaImage = str_ends_with(strtolower($file->getFilename()), '.webp') ? @imagecreatefromwebp($storedReal) : @imagecreatefrompng($storedReal);
          if ($alphaImage) {
            $color = imagecolorsforindex($alphaImage, imagecolorat($alphaImage, 0, 0));
            $alphaOk = ($color['alpha'] ?? 0) >= 100;
            imagedestroy($alphaImage);
          }
        }
        if ($flag && in_array((string) $flag->webp, ['ok', 'fallback'], TRUE) && $storedReal && !$webpOk) {
          $info = @getimagesize($storedReal);
          $webpOk = $info && (($info['mime'] ?? '') === 'image/webp');
        }
      }
      $seq++;
    }
    $cover = (int) $node->get('field_cover_image')->target_id;
    $mids = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
    $check('封面来自有效图片', $cover && in_array($cover, $mids, TRUE), (string) $cover);
    $expected = \Drupal::service('xedc_core.alt')->format($title, 1, $total);
    $check('ALT 第001张', ($alts[0] ?? '') === $expected, ($alts[0] ?? '') . ' != ' . $expected);
    $check('每张 ALT 不同', count($alts) === count(array_unique($alts)), implode(' | ', $alts));
    $check('原图总大小', (int) $task->bytes === $bytes && $bytes > 0, $task->bytes . ' vs ' . $bytes);
    $check('重命名保留原格式', $renamed, 'checked');
    $check('正式图片为 WebP', $webpOk, $webpOk ? 'webp' : 'missing');
    $check('透明 PNG 保留', $alphaOk, $alphaOk ? 'alpha' : 'lost');
    $check('动画 GIF 保留', $animatedOk, $animatedOk ? 'gif' : 'lost');
    $stylesKept = ImageStyle::load('photo_gallery_1300') && ImageStyle::load('photo_gallery_650') && ImageStyle::load('meitu_card_cover') && ImageStyle::load('images_450x800');
    $check('原有图片样式仍在', (bool) $stylesKept, $stylesKept ? 'kept' : 'missing');
    $archiveReal = $task->archive_uri ? $filesystem->realpath((string) $task->archive_uri) : FALSE;
    $check('成功后进入保留期', (int) $task->cleanup_after > time() && $archiveReal && is_file($archiveReal), 'after=' . (int) $task->cleanup_after);
  }

  $partialZip = sys_get_temp_dir() . '/xedc-partial-' . getmypid() . '.zip';
  $image($work . '/partial-ok.jpg', 'jpg');
  $zipTo($partialZip, [
    'ok.jpg' => $work . '/partial-ok.jpg',
    'bad.jpg' => $work . '/bad.jpg',
  ]);
  $placed = $stage('partial-' . getmypid() . '.zip', $partialZip);
  $partial = $run('XEDC导入测试 部分失败', basename($placed));
  $check('损坏图片不中断任务', $partial['task'] && (string) $partial['task']->status === ImportStatus::PARTIAL && (int) $partial['task']->success_count === 1 && (int) $partial['task']->failed_count === 1, (string) ($partial['task']->status ?? $partial['error']));
  $refused = $publisher->publish((int) $partial['task']->id, \Drupal::currentUser());
  $check('失败任务不能发布', !$refused['ok'], $refused['message']);

  $empty = sys_get_temp_dir() . '/xedc-empty-' . getmypid() . '.zip';
  $zipTo($empty, []);
  $placed = $stage('empty-' . getmypid() . '.zip', $empty);
  $emptyResult = $run('XEDC导入测试 空包', basename($placed));
  $check('空压缩包', $emptyResult['task'] && (string) $emptyResult['task']->status === ImportStatus::FAILED, (string) ($emptyResult['task']->message ?? $emptyResult['error']));

  $text = sys_get_temp_dir() . '/xedc-text-' . getmypid() . '.zip';
  $zipTo($text, ['readme.txt' => 'hello']);
  $placed = $stage('text-' . getmypid() . '.zip', $text);
  $textResult = $run('XEDC导入测试 无图片', basename($placed));
  $check('只有非图片', $textResult['task'] && (string) $textResult['task']->status === ImportStatus::FAILED && str_contains((string) $textResult['task']->message, '没有可导入的图片'), (string) ($textResult['task']->message ?? ''));

  $slip = sys_get_temp_dir() . '/xedc-slip-' . getmypid() . '.zip';
  $zipTo($slip, [
    '../xedc-slip-canary.txt' => 'nope',
    'ok.jpg' => $work . '/1.jpg',
  ]);
  @unlink('/tmp/xedc-slip-canary.txt');
  $placed = $stage('slip-' . getmypid() . '.zip', $slip);
  $slipResult = $run('XEDC导入测试 路径穿越', basename($placed));
  $check('路径穿越被拒绝', $slipResult['task'] && (string) $slipResult['task']->status === ImportStatus::FAILED && !is_file('/tmp/xedc-slip-canary.txt'), (string) ($slipResult['task']->message ?? ''));

  $broken = sys_get_temp_dir() . '/xedc-broken-' . getmypid() . '.zip';
  file_put_contents($broken, "PK\x03\x04 this is not a zip");
  $placed = $stage('broken-' . getmypid() . '.zip', $broken);
  $brokenResult = $run('XEDC导入测试 损坏ZIP', basename($placed));
  $check('损坏 ZIP', $brokenResult['task'] && (string) $brokenResult['task']->status === ImportStatus::FAILED, (string) ($brokenResult['task']->message ?? $brokenResult['error']));

  $sevenDir = sys_get_temp_dir() . '/xedc-7z-' . getmypid();
  mkdir($sevenDir, 0775, TRUE);
  $image($sevenDir . '/1.jpg', 'jpg');
  $image($sevenDir . '/10.jpg', 'jpg');
  $image($sevenDir . '/2.jpg', 'jpg');
  $seven = sys_get_temp_dir() . '/xedc-ok-' . getmypid() . '.7z';
  $sevenTo($seven, $sevenDir, ['1.jpg', '10.jpg', '2.jpg']);
  $placed = $stage('ok-' . getmypid() . '.7z', $seven);
  $sevenResult = $run('XEDC导入测试 7Z', basename($placed));
  $sevenNames = [];
  if ($sevenResult['task']) {
    foreach ($tasks->successes((int) $sevenResult['task']->id) as $item) {
      $sevenNames[] = (string) $item->original_name;
    }
  }
  $check('正常 7Z', $sevenResult['task'] && (string) $sevenResult['task']->status === ImportStatus::REVIEW && (string) $sevenResult['task']->archive_format === '7z', (string) ($sevenResult['task']->status ?? $sevenResult['error']));
  $check('7Z 自然排序', implode(',', $sevenNames) === '1.jpg,2.jpg,10.jpg', implode(',', $sevenNames));

  $bad7 = sys_get_temp_dir() . '/xedc-bad-' . getmypid() . '.7z';
  file_put_contents($bad7, "\x37\x7A\xBC\xAF\x27\x1C broken");
  $placed = $stage('bad-' . getmypid() . '.7z', $bad7);
  $bad7Result = $run('XEDC导入测试 损坏7Z', basename($placed));
  $check('损坏 7Z', $bad7Result['task'] && (string) $bad7Result['task']->status === ImportStatus::FAILED, (string) ($bad7Result['task']->message ?? ''));

  if ($node && $task && (string) $task->status === ImportStatus::REVIEW) {
    $manualMedia = $node->get('field_images')->referencedEntities()[1] ?? NULL;
    if ($manualMedia) {
      $manualMedia->get('field_media_image')->first()->set('alt', '手工说明');
      $manualMedia->save();
    }
    $node = Node::load($node->id());
    $node->setTitle($title . ' 改名');
    $node->save();
    $node = Node::load($node->id());
    $after = [];
    foreach ($node->get('field_images')->referencedEntities() as $media) {
      $after[] = (string) $media->get('field_media_image')->alt;
    }
    $check('手动 ALT 保留', in_array('手工说明', $after, TRUE), implode(' | ', $after));
    $renamedAlt = \Drupal::service('xedc_core.alt')->format($node->label(), 1, count($after));
    $check('标题变化更新自动 ALT', ($after[0] ?? '') === $renamedAlt, ($after[0] ?? '') . ' != ' . $renamedAlt);
    $coverBefore = (int) $node->get('field_cover_image')->target_id;
    $check('改标题不改封面', $coverBefore === (int) Node::load($node->id())->get('field_cover_image')->target_id, (string) $coverBefore);

    $appendZip = sys_get_temp_dir() . '/xedc-append-' . getmypid() . '.zip';
    $image($work . '/extra.jpg', 'jpg');
    $zipTo($appendZip, ['extra.jpg' => $work . '/extra.jpg']);
    $placed = $stage('append-' . getmypid() . '.zip', $appendZip);
    $beforeCount = $node->get('field_images')->count();
    $appendId = $processor->start($node, [
      'is_new' => FALSE,
      'mode' => 'append',
      'fid' => 0,
      'server' => basename($placed),
    ]);
    $appendTask = $processor->processUntilDone($appendId);
    $node = Node::load($node->id());
    $check('追加图片', (string) $appendTask->status === ImportStatus::REVIEW && $node->get('field_images')->count() === $beforeCount + 1, (string) $appendTask->status . ' count ' . $node->get('field_images')->count());
    $stillManual = FALSE;
    foreach ($node->get('field_images')->referencedEntities() as $media) {
      if ((string) $media->get('field_media_image')->alt === '手工说明') {
        $stillManual = TRUE;
      }
    }
    $check('追加不覆盖手动 ALT', $stillManual, $stillManual ? 'kept' : 'lost');

    $oldMids = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
    $replaceBad = sys_get_temp_dir() . '/xedc-replace-bad-' . getmypid() . '.zip';
    $zipTo($replaceBad, ['ok.jpg' => $work . '/1.jpg', 'bad.jpg' => $work . '/bad.jpg']);
    $placed = $stage('replace-bad-' . getmypid() . '.zip', $replaceBad);
    $replaceBadId = $processor->start($node, [
      'is_new' => FALSE,
      'mode' => 'replace',
      'fid' => 0,
      'server' => basename($placed),
    ]);
    $replaceBadTask = $processor->processUntilDone($replaceBadId);
    $node = Node::load($node->id());
    $nowMids = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
    $check('替换失败保留原图', (string) $replaceBadTask->status === ImportStatus::FAILED && $nowMids === $oldMids, (string) $replaceBadTask->message);

    $replaceOk = sys_get_temp_dir() . '/xedc-replace-ok-' . getmypid() . '.zip';
    $zipTo($replaceOk, ['n1.jpg' => $work . '/1.jpg', 'n2.jpg' => $work . '/2.jpg']);
    $placed = $stage('replace-ok-' . getmypid() . '.zip', $replaceOk);
    $replaceId = $processor->start($node, [
      'is_new' => FALSE,
      'mode' => 'replace',
      'fid' => 0,
      'server' => basename($placed),
    ]);
    $replaceTask = $processor->processUntilDone($replaceId);
    $node = Node::load($node->id());
    $replacedMids = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
    $check('替换成功', (int) $replaceTask->replaced === 1 && $replacedMids !== $oldMids && count($replacedMids) === 2, 'count ' . count($replacedMids));
    $publisher->rollback((int) $replaceTask->id, \Drupal::currentUser());
    $node = Node::load($node->id());
    $rolled = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
    $check('替换回滚', $rolled === $oldMids, implode(',', $rolled));

    $publish = $publisher->publish((int) $task->id, \Drupal::currentUser());
    $node = Node::load($node->id());
    $check('单条发布', $publish['ok'] && $node->isPublished(), $publish['message'] . ' status=' . (int) $node->isPublished());
    $again = $publisher->publish((int) $task->id, \Drupal::currentUser());
    $check('防止重复发布', !$again['ok'], $again['message']);
  }

  $bulkA = $run('XEDC导入测试 批量A', basename($stage('bulk-a-' . getmypid() . '.zip', $happy)));
  $bulkB = $run('XEDC导入测试 批量B', basename($stage('bulk-b-' . getmypid() . '.zip', $happy)));
  $okA = $publisher->publish((int) $bulkA['task']->id, \Drupal::currentUser());
  $okB = $publisher->publish((int) $partial['task']->id, \Drupal::currentUser());
  $check('批量发布互不影响', $okA['ok'] && !$okB['ok'] && Node::load($bulkA['node']->id())->isPublished(), $okA['message'] . ' / ' . $okB['message']);

  $cancelZip = $stage('cancel-' . getmypid() . '.zip', $happy);
  $cancelNode = Node::create([
    'type' => 'photo',
    'title' => 'XEDC导入测试 取消',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $cancelNode->save();
  $nids[] = (int) $cancelNode->id();
  $cancelId = $processor->start($cancelNode, [
    'is_new' => TRUE,
    'mode' => 'create',
    'fid' => 0,
    'server' => basename($cancelZip),
  ]);
  $processor->cancel($cancelId);
  $cancelled = $processor->processUntilDone($cancelId);
  $check('取消任务', (string) $cancelled->status === ImportStatus::CANCELLED, (string) $cancelled->status);

  $userA = User::create(['name' => 'xedc_imp_a_' . getmypid(), 'status' => 1]);
  $userA->save();
  $uids[] = (int) $userA->id();
  $userB = User::create(['name' => 'xedc_imp_b_' . getmypid(), 'status' => 1]);
  $userB->save();
  $uids[] = (int) $userB->id();
  $memberZip = $stage('member-' . getmypid() . '.zip', $happy);
  $file = File::create([
    'uri' => $memberZip,
    'uid' => $userA->id(),
    'status' => 0,
    'filename' => basename($memberZip),
  ]);
  $file->save();
  $switcher->switchTo($userA);
  try {
    $own = Node::create([
      'type' => 'photo',
      'title' => 'XEDC导入测试 会员',
      'uid' => $userA->id(),
      'status' => 0,
      'moderation_state' => 'draft',
    ]);
    xedc_core_import_payload('set', [
      'is_new' => TRUE,
      'mode' => 'create',
      'fid' => (int) $file->id(),
      'server' => '',
    ]);
    $own->save();
    $nids[] = (int) $own->id();
    $memberRows = $tasks->forNode((int) $own->id());
    $memberTask = $memberRows ? (int) $memberRows[0]->id : 0;
  }
  finally {
    $switcher->switchBack();
  }
  $access = \Drupal::service('xedc_core.task_access');
  $check('会员可访问自己的任务', $access->view($userA, $memberTask)->isAllowed(), 'allowed');
  $check('会员不能访问他人任务', $access->view($userB, $memberTask)->isForbidden(), 'forbidden');
  $check('会员不能发布', $access->publish($userA, $memberTask)->isForbidden(), 'forbidden');

  $plain = Node::create([
    'type' => 'photo',
    'title' => 'XEDC导入测试 普通保存',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $formObject = \Drupal::entityTypeManager()->getFormObject('node', 'default');
  $formObject->setEntity($plain);
  $formState = new FormState();
  $built = \Drupal::formBuilder()->buildForm($formObject, $formState);
  $check('新增表单包含 ZIP 区域且保留图片集', isset($built['xedc_import'], $built['field_images'], $built['title']), isset($built['xedc_import']) ? 'present' : 'missing');
  $plain->save();
  $nids[] = (int) $plain->id();
  $check('无压缩包不创建任务', !$tasks->forNode((int) $plain->id()), 'none');

  $queueNode = Node::create([
    'type' => 'photo',
    'title' => 'XEDC导入测试 队列',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $queueNode->save();
  $nids[] = (int) $queueNode->id();
  $queueZip = $stage('queue-' . getmypid() . '.zip', $happy);
  $queueTask = $processor->start($queueNode, [
    'is_new' => TRUE,
    'mode' => 'create',
    'fid' => 0,
    'server' => basename($queueZip),
  ]);
  $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('xedc_image_import');
  $queue = \Drupal::queue('xedc_image_import');
  $guard = 0;
  while ($guard < 30 && ($item = $queue->claimItem(5))) {
    $guard++;
    $itemTask = (int) ($item->data['task_id'] ?? 0);
    if ($itemTask !== $queueTask) {
      $other = $tasks->load($itemTask);
      if (!$other || ImportStatus::isTerminal((string) $other->status)) {
        $queue->deleteItem($item);
      }
      elseif ($queue instanceof \Drupal\Core\Queue\DelayableQueueInterface) {
        $queue->delayItem($item, 120);
      }
      else {
        $queue->releaseItem($item);
        break;
      }
      continue;
    }
    try {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
    catch (RequeueException) {
      $queue->releaseItem($item);
    }
    $current = $tasks->load($queueTask);
    if ($current && ImportStatus::isTerminal((string) $current->status)) {
      break;
    }
  }
  $queued = $tasks->load($queueTask);
  $check('Queue Worker', $queued && ImportStatus::isTerminal((string) $queued->status), (string) ($queued->status ?? 'missing'));
  $check('路由存在', \Drupal::service('router.route_provider')->getRouteByName('xedc_core.import_list')->getPath() === '/admin/content/image-imports', 'ok');

  $config = \Drupal::configFactory()->getEditable('xedc_core.settings');
  $oldPixels = $config->get('max_pixels');
  $config->set('max_pixels', 10)->save();
  $pixelThrew = FALSE;
  try {
    $pipeline->validate($work . '/1.jpg');
  }
  catch (ImportException $e) {
    $pixelThrew = str_contains($e->getMessage(), '像素');
  }
  $config->set('max_pixels', $oldPixels)->save();
  $check('超大像素被拒绝', $pixelThrew, 'pixels');

  $list = \Drupal::formBuilder()->getForm(\Drupal\xedc_core\Form\ImportListForm::class);
  $check('批量发布中心表单', isset($list['tasks']), 'form');
}
catch (Throwable $e) {
  $fail('测试中断', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
finally {
  if (\Drupal::currentUser()->id() != 1) {
    // The member test switches accounts. Return before deleting users.
  }
  $switcher->switchTo(User::load(1));
  $cleanup();
  $switcher->switchBack();
  $switcher->switchBack();
}

$failed = array_values(array_filter($results, static fn(array $row): bool => !$row[1]));
echo 'TOTAL ' . count($results) . ' FAIL ' . count($failed) . "\n";
if ($failed) {
  throw new RuntimeException('导入自测未通过');
}
