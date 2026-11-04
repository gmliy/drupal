<?php

/**
 * @file
 * ZIP, 7z and image-batch upload checks.
 *
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/asset-upload-self-test.php
 */

use Drupal\Core\File\FileExists;
use Drupal\Core\Session\UserSession;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\xedc_core\Controller\AssetUploadController;
use Drupal\xedc_core\ImportStatus;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

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

$prefix = 'XEDC上传验收 ' . date('YmdHis');
$work = '/tmp/xedc-asset-' . getmypid();
@mkdir($work . '/风景', 0775, TRUE);
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new UserSession(['uid' => 1]));
$config = \Drupal::configFactory()->getEditable('xedc_core.settings');
$oldLimit = $config->get('max_archive_bytes');
$controller = AssetUploadController::create(\Drupal::getContainer());
$processor = \Drupal::service('xedc_core.processor');
$tasks = \Drupal::service('xedc_core.tasks');
$filesystem = \Drupal::service('file_system');

$jpeg = static function (string $path, int $red, int $green, int $blue): void {
  $image = imagecreatetruecolor(32, 24);
  imagefilledrectangle($image, 0, 0, 31, 23, imagecolorallocate($image, $red, $green, $blue));
  imagejpeg($image, $path, 90);
  imagedestroy($image);
};

$upload = static function (string $path, string $name, array $fields, string $key = 'archive') use ($controller): array {
  $file = new UploadedFile($path, $name, NULL, \UPLOAD_ERR_OK, TRUE);
  $request = Request::create('https://gitxx.xyz/admin/content/image-imports/' . $key, 'POST', $fields, [], [$key => $file]);
  $response = $key === 'image' ? $controller->image($request) : $controller->archive($request);
  $json = json_decode((string) $response->getContent(), TRUE);
  return ['status' => $response->getStatusCode(), 'json' => is_array($json) ? $json : []];
};

$finish = static function (int $taskId) use ($processor, $tasks): ?object {
  try {
    return $processor->processUntilDone($taskId);
  }
  catch (Throwable $e) {
    $row = $tasks->load($taskId);
    if ($row) {
      $row->error = $e->getMessage();
    }
    return $row;
  }
};

try {
  $jpeg($work . '/one.jpg', 20, 80, 160);
  $jpeg($work . '/风景/日落.jpg', 220, 90, 30);
  file_put_contents($work . '/说明.txt', 'not an image');
  $zipPath = $work . '/cn.zip';
  $zip = new ZipArchive();
  $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  $zip->addFile($work . '/风景/日落.jpg', '风景/日落.jpg');
  $zip->addFile($work . '/one.jpg', 'one.jpg');
  $zip->addFile($work . '/说明.txt', '说明.txt');
  $zip->close();
  $zipBytes = filesize($zipPath);

  $sevenDir = $work . '/seven';
  @mkdir($sevenDir . '/相册', 0775, TRUE);
  $jpeg($sevenDir . '/相册/海边.jpg', 20, 160, 70);
  copy($work . '/说明.txt', $sevenDir . '/readme.txt');
  $sevenPath = $work . '/cn.7z';
  exec('7z a -t7z ' . escapeshellarg($sevenPath) . ' ' . escapeshellarg($sevenDir . '/相册/海边.jpg') . ' ' . escapeshellarg($sevenDir . '/readme.txt') . ' 2>&1', $sevenOut, $sevenCode);
  $check('7z 命令可创建压缩包', $sevenCode === 0 && is_file($sevenPath) && filesize($sevenPath) > 0, implode(' ', $sevenOut));

  $zipTitle = $prefix . ' ZIP';
  $zipToken = bin2hex(random_bytes(16));
  $zipResult = $upload($zipPath, '中文图集.zip', [
    'upload_token' => $zipToken,
    'title' => $zipTitle,
  ]);
  $zipJson = $zipResult['json'];
  $check('ZIP 请求被接收并保存', ($zipJson['received'] ?? FALSE) === TRUE && ($zipJson['stored'] ?? FALSE) === TRUE, json_encode($zipJson, JSON_UNESCAPED_UNICODE));
  $check('ZIP 创建了任务', ($zipJson['task_created'] ?? FALSE) === TRUE && (int) ($zipJson['task_id'] ?? 0) > 0, 'task ' . ($zipJson['task_id'] ?? 0));
  $check('ZIP 保存时尚未冒充完成', empty($zipJson['completed']), (string) ($zipJson['phase'] ?? ''));
  $zipTaskId = (int) ($zipJson['task_id'] ?? 0);
  $zipNid = (int) ($zipJson['nid'] ?? 0);
  if ($zipTaskId) {
    $stored = $tasks->load($zipTaskId);
    $real = $stored ? $filesystem->realpath((string) $stored->archive_uri) : FALSE;
    $check('ZIP 文件实体存在且大小正确', $stored && $real && is_file($real) && (int) filesize($real) === (int) $zipBytes, $real ? (string) filesize($real) : 'missing');
    $done = $finish($zipTaskId);
    $node = $zipNid ? Node::load($zipNid) : NULL;
    $check('ZIP 解压后进入审核而不是公开', $done && (string) $done->status === ImportStatus::REVIEW && $node && !$node->isPublished(), $done ? (string) $done->status . ' pub=' . (int) ($node ? $node->isPublished() : -1) : 'no task');
    $check('ZIP 导入 2 张图片并跳过文本', $done && (int) $done->success_count === 2 && (int) $done->failed_count === 0, $done ? 'ok=' . $done->success_count . ' skip=' . $done->skipped_count . ' bad=' . $done->failed_count : '');
    $original = 0;
    foreach ($tasks->items($zipTaskId) as $item) {
      if ((string) $item->status === 'success') {
        $original += (int) ($item->original_bytes ?? 0);
      }
    }
    $check('ZIP 记录了优化前字节', $original > 0, (string) $original);
    $alts = [];
    if ($node) {
      foreach ($node->get('field_images')->referencedEntities() as $media) {
        $alts[] = (string) $media->get('field_media_image')->alt;
        $file = $media->get('field_media_image')->entity;
        $uri = $file instanceof FileInterface ? (string) $file->getFileUri() : '';
        $check('ZIP 图片写入公开存储且大于 0', str_starts_with($uri, 'public://') && $file->getSize() > 0, $uri . ' ' . ($file ? $file->getSize() : 0));
      }
    }
    $expectedAlt = \Drupal::service('xedc_core.alt')->compose('', $zipTitle);
    $check('ZIP ALT 使用模特名+作品标题', $alts && count(array_unique($alts)) === 1 && $alts[0] === $expectedAlt, implode(' | ', $alts));
    $again = $upload($zipPath, '中文图集.zip', [
      'upload_token' => $zipToken,
      'title' => $zipTitle,
    ]);
    $check('相同上传标识不重复创建任务', (int) ($again['json']['task_id'] ?? 0) === $zipTaskId, (string) ($again['json']['task_id'] ?? 0));
    $other = $upload($zipPath, '中文图集.zip', [
      'upload_token' => bin2hex(random_bytes(16)),
      'title' => $prefix . ' 重复包',
    ]);
    $check('相同压缩包哈希不重复创建任务', (int) ($other['json']['task_id'] ?? 0) === $zipTaskId, (string) ($other['json']['message'] ?? ''));
  }

  $sevenToken = bin2hex(random_bytes(16));
  $sevenTitle = $prefix . ' 7z';
  $sevenResult = $upload($sevenPath, '中文相册.7z', [
    'upload_token' => $sevenToken,
    'title' => $sevenTitle,
  ]);
  $sevenJson = $sevenResult['json'];
  $check('7z 请求被接收并保存', ($sevenJson['received'] ?? FALSE) === TRUE && ($sevenJson['stored'] ?? FALSE) === TRUE && ($sevenJson['format'] ?? '') === '7z', json_encode($sevenJson, JSON_UNESCAPED_UNICODE));
  $sevenTaskId = (int) ($sevenJson['task_id'] ?? 0);
  if ($sevenTaskId) {
    $done = $finish($sevenTaskId);
    $node = Node::load((int) ($sevenJson['nid'] ?? 0));
    $check('7z 解压并导入 1 张图片', $done && (int) $done->success_count === 1 && $node && !$node->isPublished(), $done ? (string) $done->status . ' ok=' . $done->success_count . ' err=' . $done->error : 'no');
    $alt = $node ? (string) $node->get('field_images')->referencedEntities()[0]->get('field_media_image')->alt : '';
    $check('7z ALT 使用模特名+作品标题', $alt === \Drupal::service('xedc_core.alt')->compose('', $sevenTitle), $alt);
  }

  $empty = $work . '/empty.zip';
  $emptyZip = new ZipArchive();
  $emptyZip->open($empty, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  $emptyZip->addFromString('空目录/', '');
  $emptyZip->close();
  $emptyResult = $upload($empty, 'empty.zip', [
    'upload_token' => bin2hex(random_bytes(16)),
    'title' => $prefix . ' 空包',
  ]);
  $emptyTask = (int) ($emptyResult['json']['task_id'] ?? 0);
  if ($emptyTask) {
    $done = $finish($emptyTask);
    $check('空压缩包不会标记成功', $done && (string) $done->status === ImportStatus::FAILED && (int) $done->success_count === 0, $done ? (string) $done->status . ' ' . $done->error : '');
  }
  else {
    $check('空压缩包在保存前被拒绝', ($emptyResult['json']['stored'] ?? TRUE) === FALSE && ($emptyResult['json']['completed'] ?? TRUE) === FALSE, json_encode($emptyResult['json'], JSON_UNESCAPED_UNICODE));
  }

  file_put_contents($work . '/bad.zip', 'this is not a zip');
  $bad = $upload($work . '/bad.zip', 'bad.zip', [
    'upload_token' => bin2hex(random_bytes(16)),
    'title' => $prefix . ' 损坏',
  ]);
  $check('损坏压缩包没有创建任务', empty($bad['json']['task_created']) && ($bad['json']['stored'] ?? TRUE) === FALSE, (string) ($bad['json']['error'] ?? ''));

  $config->set('max_archive_bytes', 80)->save();
  $big = $upload($zipPath, '中文图集.zip', [
    'upload_token' => bin2hex(random_bytes(16)),
    'title' => $prefix . ' 超限',
  ]);
  $config->set('max_archive_bytes', $oldLimit)->save();
  $check('超限压缩包没有保存', ($big['json']['received'] ?? FALSE) === TRUE && ($big['json']['stored'] ?? TRUE) === FALSE && empty($big['json']['task_id']), (string) ($big['json']['error'] ?? ''));

  $partial = new UploadedFile($zipPath, 'partial.zip', NULL, \UPLOAD_ERR_PARTIAL, TRUE);
  $partialRequest = Request::create('https://gitxx.xyz/admin/content/image-imports/archive', 'POST', [
    'upload_token' => bin2hex(random_bytes(16)),
    'title' => $prefix . ' 中断',
  ], [], ['archive' => $partial]);
  $partialJson = json_decode((string) $controller->archive($partialRequest)->getContent(), TRUE);
  $check('上传中断不会显示成功', ($partialJson['received'] ?? TRUE) === FALSE && ($partialJson['task_created'] ?? TRUE) === FALSE, (string) ($partialJson['error'] ?? ''));

  $textZip = $work . '/text.zip';
  $text = new ZipArchive();
  $text->open($textZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  $text->addFromString('note.txt', 'hello');
  $text->close();
  $textResult = $upload($textZip, 'text.zip', [
    'upload_token' => bin2hex(random_bytes(16)),
    'title' => $prefix . ' 无图片',
  ]);
  $textTask = (int) ($textResult['json']['task_id'] ?? 0);
  if ($textTask) {
    $done = $finish($textTask);
    $check('没有图片的压缩包失败', $done && (string) $done->status === ImportStatus::FAILED, $done ? (string) $done->status . ' ' . $done->error : '');
  }
  else {
    $fail('没有图片的压缩包失败', '任务没有创建：' . json_encode($textResult['json'], JSON_UNESCAPED_UNICODE));
  }

  $imageNode = Node::create([
    'type' => 'photo',
    'title' => $prefix . ' 图片批次',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $imageNode->xedc_keep_unpublished = TRUE;
  $imageNode->save();
  $imageToken = bin2hex(random_bytes(16));
  $one = $upload($work . '/one.jpg', 'one.jpg', [
    'upload_token' => $imageToken,
    'nid' => (int) $imageNode->id(),
  ], 'image');
  $two = $upload($work . '/风景/日落.jpg', '日落.jpg', [
    'upload_token' => bin2hex(random_bytes(16)),
    'nid' => (int) $imageNode->id(),
  ], 'image');
  $imageTask = (int) ($one['json']['task_id'] ?? 0);
  $check('普通图片已保存但尚未完成', ($one['json']['stored'] ?? FALSE) === TRUE && empty($one['json']['completed']) && $imageTask > 0 && (int) ($two['json']['task_id'] ?? 0) === $imageTask, json_encode([$one['json'], $two['json']], JSON_UNESCAPED_UNICODE));
  $dupImage = $upload($work . '/one.jpg', 'one.jpg', [
    'upload_token' => $imageToken,
    'nid' => (int) $imageNode->id(),
  ], 'image');
  $check('同一图片标识不重复导入', (int) ($dupImage['json']['item_id'] ?? 0) === (int) ($one['json']['item_id'] ?? -1) && (int) ($dupImage['json']['item_id'] ?? 0) > 0, (string) ($dupImage['json']['message'] ?? ''));
  $controller->close(Request::create('https://gitxx.xyz/admin/content/image-imports/close', 'POST', [], [], [], [], json_encode(['task_id' => $imageTask])));
  if ($imageTask) {
    $done = $finish($imageTask);
    $imageNode = Node::load((int) $imageNode->id());
    $check('图片批次后台处理完成且未公开', $done && in_array((string) $done->status, [ImportStatus::REVIEW, ImportStatus::PARTIAL], TRUE) && $imageNode && !$imageNode->isPublished() && (int) $imageNode->get('field_images')->count() === 2, $done ? (string) $done->status . ' images=' . $imageNode->get('field_images')->count() . ' err=' . $done->error : '');
    $metrics = \Drupal::service('xedc_core.gallery_metrics')->forNode($imageNode);
    $check('批次原图大小来自优化前字节', (int) $metrics['original_bytes'] > 0 && (int) $metrics['optimized_bytes'] > 0, json_encode([
      'original' => $metrics['original_bytes'],
      'optimized' => $metrics['optimized_bytes'],
      'label' => $metrics['original_label'],
    ], JSON_UNESCAPED_UNICODE));
    $media = $imageNode->get('field_images')->referencedEntities()[0] ?? NULL;
    if ($media) {
      $manual = '手工说明不要覆盖';
      $media->get('field_media_image')->alt = $manual;
      $media->save();
      \Drupal::service('xedc_core.alt')->remember((int) $media->id(), $manual, 'manual');
      $imageNode->setTitle($prefix . ' 改标题');
      $imageNode->save();
      $media = Media::load((int) $media->id());
      $check('人工 ALT 不被标题修改覆盖', $media && (string) $media->get('field_media_image')->alt === $manual, (string) $media->get('field_media_image')->alt);
    }
  }

  $unknownNode = Node::create([
    'type' => 'photo',
    'title' => $prefix . ' 未知原图',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $unknownNode->xedc_keep_unpublished = TRUE;
  $privateUri = $filesystem->saveData(file_get_contents($work . '/one.jpg'), 'private://xedc-import/inbox/' . $prefix . '-unknown.jpg', FileExists::Rename);
  $privateFile = File::create([
    'uri' => $privateUri,
    'uid' => 1,
    'status' => FileInterface::STATUS_PERMANENT,
    'filename' => 'unknown.jpg',
  ]);
  $privateFile->save();
  $unknownMedia = Media::create([
    'bundle' => 'image',
    'name' => $prefix . ' unknown',
    'uid' => 1,
    'status' => 1,
    'field_media_image' => [
      'target_id' => $privateFile->id(),
      'alt' => $prefix . ' 未知原图_第1张图',
    ],
  ]);
  $unknownMedia->save();
  $unknownNode->set('field_images', [['target_id' => $unknownMedia->id()]]);
  $unknownNode->save();
  $unknown = \Drupal::service('xedc_core.gallery_metrics')->forNode($unknownNode);
  $check('没有原始字节时显示原图大小未知', $unknown['original_label'] === '原图大小未知' && $unknown['original_bytes'] === NULL, $unknown['original_label'] . ' bytes=' . var_export($unknown['original_bytes'], TRUE));
}
finally {
  $config->set('max_archive_bytes', $oldLimit)->save();
  $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', $prefix, 'STARTS_WITH')->execute();
  $database = \Drupal::database();
  foreach ($ids as $nid) {
    $node = Node::load((int) $nid);
    $mids = [];
    if ($node) {
      $mids = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
      if ($node->hasField('field_cover_image')) {
        $cover = (int) $node->get('field_cover_image')->target_id;
        if ($cover) {
          $mids[] = $cover;
        }
      }
      $node->delete();
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
        $used = (int) $database->select('media__field_media_image', 'm')->condition('field_media_image_target_id', $fid)->countQuery()->execute()->fetchField();
        if ($used < 1) {
          $file->delete();
        }
      }
      $database->delete('xedc_image_alt')->condition('mid', $mid)->execute();
      $database->delete('xedc_quick_orig')->condition('mid', $mid)->execute();
    }
  }
  $switcher->switchBack();
  if (is_dir($work)) {
    exec('rm -rf ' . escapeshellarg($work));
  }
}

$failed = array_filter($results, static fn(array $row): bool => $row[1] === FALSE);
echo $failed ? 'FAILED ' . count($failed) . '/' . count($results) . "\n" : 'ALL PASS ' . count($results) . "\n";
if ($failed) {
  throw new RuntimeException('asset upload self-test failed');
}
