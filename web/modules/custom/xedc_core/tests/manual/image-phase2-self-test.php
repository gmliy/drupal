<?php

/**
 * @file
 * Phase 2 server checks for quick publish, retry, filters and publish guard.
 *
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/image-phase2-self-test.php
 */

use Drupal\Core\Session\UserSession;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\views\Views;
use Drupal\xedc_quick\Controller\QuickController;
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

$prefix = 'XEDC上传验收第二阶段 ' . date('YmdHis');
$work = '/tmp/xedc-phase2-' . getmypid();
@mkdir($work, 0775, TRUE);
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new UserSession(['uid' => 1]));
$state = \Drupal::state();
$quick = QuickController::create(\Drupal::getContainer());

$jpeg = static function (string $path, int $red): void {
  $image = imagecreatetruecolor(40, 30);
  imagefilledrectangle($image, 0, 0, 39, 29, imagecolorallocate($image, $red, 40, 90));
  imagejpeg($image, $path, 90);
  imagedestroy($image);
};
$upload = static function (string $path, string $name) use ($quick): array {
  $file = new UploadedFile($path, $name, NULL, \UPLOAD_ERR_OK, TRUE);
  $request = Request::create('https://gitxx.xyz/admin/quick-gallery/upload', 'POST', [
    'title' => 'phase2',
    'n' => '1',
  ], [], ['file' => $file]);
  $response = $quick->upload($request);
  $json = json_decode((string) $response->getContent(), TRUE);
  return ['status' => $response->getStatusCode(), 'json' => is_array($json) ? $json : []];
};

try {
  $jpeg($work . '/ok.jpg', 30);
  $good = $upload($work . '/ok.jpg', 'ok.jpg');
  $mid = (int) ($good['json']['mid'] ?? 0);
  $media = $mid ? Media::load($mid) : NULL;
  $file = $media ? File::load((int) $media->get('field_media_image')->target_id) : NULL;
  $uri = $file ? (string) $file->getFileUri() : '';
  $check('快速发布优化后写入公开存储', $good['status'] === 200 && str_starts_with($uri, 'public://') && !str_contains($uri, 'public/public/') && $file->getSize() > 0, $uri . ' ' . ($file ? $file->getSize() : 0));
  $check('快速发布返回原图和优化后字节', (int) ($good['json']['original_bytes'] ?? 0) > (int) ($good['json']['optimized_bytes'] ?? 0), json_encode($good['json']));
  $check('快速发布 ALT', $media && str_contains((string) $media->get('field_media_image')->alt, '_第1张图'), (string) ($media ? $media->get('field_media_image')->alt : ''));
  $again = $upload($work . '/ok.jpg', 'ok.jpg');
  $check('重复图片复用媒体', (int) ($again['json']['mid'] ?? 0) === $mid && !empty($again['json']['dup']), (string) ($again['json']['mid'] ?? 0));

  file_put_contents($work . '/bad.jpg', 'not a jpeg');
  $bad = $upload($work . '/bad.jpg', 'bad.jpg');
  $check('损坏图片不公开', $bad['status'] === 400 && empty($bad['json']['mid']), (string) ($bad['json']['error'] ?? ''));
  file_put_contents($work . '/note.txt', 'hello');
  $text = $upload($work . '/note.txt', 'note.txt');
  $check('不支持的格式被拒绝', $text['status'] === 400 && empty($text['json']['mid']), (string) ($text['json']['error'] ?? ''));

  $big = $work . '/big.jpg';
  exec('dd if=/dev/zero of=' . escapeshellarg($big) . ' bs=1M count=26 status=none');
  $over = $upload($big, 'big.jpg');
  $check('超过快速发布大小限制', $over['status'] === 400 && str_contains((string) ($over['json']['error'] ?? ''), '过大'), (string) ($over['json']['error'] ?? ''));
  @unlink($big);

  $state->set('xedc_quick_fail_public_copy', TRUE);
  $jpeg($work . '/r2.jpg', 200);
  $before = (int) \Drupal::database()->query("SELECT COUNT(*) FROM {file_managed} WHERE uri LIKE 'public://gallery/%'")->fetchField();
  $denied = $upload($work . '/r2.jpg', 'r2.jpg');
  $after = (int) \Drupal::database()->query("SELECT COUNT(*) FROM {file_managed} WHERE uri LIKE 'public://gallery/%'")->fetchField();
  $state->delete('xedc_quick_fail_public_copy');
  $check('模拟公开存储失败时不创建文件', $denied['status'] === 500 && $after === $before && str_contains((string) ($denied['json']['error'] ?? ''), '公开存储'), (string) ($denied['json']['error'] ?? ''));

  $node = Node::create([
    'type' => 'photo',
    'title' => $prefix . ' 快速发布',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
    'field_images' => [['target_id' => $mid]],
  ]);
  $node->xedc_keep_unpublished = TRUE;
  $node->save();
  $loaded = Node::load((int) $node->id());
  $check('未勾选发布时保持未发布', $loaded && !$loaded->isPublished(), (string) ($loaded ? $loaded->get('moderation_state')->value : ''));

  $batchNode = Node::create([
    'type' => 'photo',
    'title' => $prefix . ' 重试',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $batchNode->xedc_keep_unpublished = TRUE;
  $batchNode->save();
  $jpeg($work . '/retry.jpg', 80);
  $controller = \Drupal\xedc_core\Controller\AssetUploadController::create(\Drupal::getContainer());
  $token = bin2hex(random_bytes(16));
  $imageFile = new UploadedFile($work . '/retry.jpg', 'retry.jpg', NULL, \UPLOAD_ERR_OK, TRUE);
  $imageRequest = Request::create('https://gitxx.xyz/admin/content/image-imports/image', 'POST', [
    'upload_token' => $token,
    'nid' => (int) $batchNode->id(),
  ], [], ['image' => $imageFile]);
  $stored = json_decode((string) $controller->image($imageRequest)->getContent(), TRUE);
  $taskId = (int) ($stored['task_id'] ?? 0);
  $state->set('xedc_import_fail_next', TRUE);
  $controller->close(Request::create('https://gitxx.xyz/admin/content/image-imports/close', 'POST', [], [], [], [], json_encode(['task_id' => $taskId])));
  $failed = \Drupal::service('xedc_core.processor')->processUntilDone($taskId);
  $check('模拟失败后任务失败且未公开', (string) $failed->status === 'failed' && !Node::load((int) $batchNode->id())->isPublished(), (string) $failed->status . ' ' . $failed->error);
  $plugin = \Drupal::service('plugin.manager.action')->createInstance('xedc_moderation_set_state:published');
  $blocked = $plugin->execute(Node::load((int) $batchNode->id()));
  $still = Node::load((int) $batchNode->id());
  $check('失败任务期间的发布被拒绝', is_array($blocked) && ($blocked['type'] ?? '') === 'warning' && !$still->isPublished(), (string) ($blocked['message'] ?? $still->get('moderation_state')->value));
  $failedNids = \Drupal::service('xedc_core.gallery_metrics')->nidsForState('failed');
  $check('失败筛选包含失败任务', in_array((int) $batchNode->id(), $failedNids, TRUE), 'failed count ' . count($failedNids));
  $attempts = (int) \Drupal::database()->query('SELECT MAX(attempts) FROM {xedc_import_item} WHERE task_id = :id', [':id' => $taskId])->fetchField();
  \Drupal::service('xedc_core.processor')->retry($taskId);
  $restored = \Drupal::service('xedc_core.processor')->processUntilDone($taskId);
  $restoredNode = Node::load((int) $batchNode->id());
  $attemptsAfter = (int) \Drupal::database()->query('SELECT MAX(attempts) FROM {xedc_import_item} WHERE task_id = :id', [':id' => $taskId])->fetchField();
  $check('重试后成功且不重复媒体', (string) $restored->status === 'review' && (int) $restored->success_count === 1 && (int) $restoredNode->get('field_images')->count() === 1 && $attemptsAfter > $attempts, 'status=' . $restored->status . ' attempts ' . $attempts . '->' . $attemptsAfter);

  $manual = $restoredNode->get('field_images')->referencedEntities()[0];
  $manual->get('field_media_image')->alt = '手工说明不要覆盖';
  $manual->save();
  \Drupal::service('xedc_core.alt')->remember((int) $manual->id(), '手工说明不要覆盖', 'manual');
  $restoredNode->setTitle($prefix . ' 改标题');
  $restoredNode->save();
  $manual = Media::load((int) $manual->id());
  $check('重试后的人工 ALT 不被标题覆盖', (string) $manual->get('field_media_image')->alt === '手工说明不要覆盖', (string) $manual->get('field_media_image')->alt);

  $failedAfter = \Drupal::service('xedc_core.gallery_metrics')->nidsForState('failed');
  $check('成功后不再算失败', !in_array((int) $batchNode->id(), $failedAfter, TRUE), 'failed count ' . count($failedAfter));
  $complete = \Drupal::service('xedc_core.gallery_metrics')->nidsForState('complete');
  $check('完成后的图片内容进入完成筛选', in_array((int) $batchNode->id(), $complete, TRUE), 'complete ' . (int) in_array((int) $batchNode->id(), $complete, TRUE));

  $view = Views::getView('moderated_content');
  $view->setDisplay('moderated_content');
  $view->setExposedInput(['gallery_state' => 'complete']);
  $view->setItemsPerPage(0);
  $view->preExecute();
  $view->execute();
  $found = FALSE;
  $article = FALSE;
  foreach ($view->result as $row) {
    $entity = $row->_entity ?? NULL;
    if ($entity && (int) $entity->id() === (int) $batchNode->id()) {
      $found = TRUE;
    }
    if ($entity && $entity->bundle() !== 'photo') {
      $article = TRUE;
    }
  }
  $check('审核视图完成筛选能查到测试图片', $found, 'rows ' . count($view->result));
  $check('完成筛选不混入非图片内容', !$article, $article ? 'has other bundle' : 'photos only');
  $handlers = $view->getHandlers('field');
  $check('审核列表有图片处理字段', isset($handlers['xedc_gallery_status']), implode(',', array_keys($handlers)));
}
finally {
  $state->delete('xedc_quick_fail_public_copy');
  $state->delete('xedc_import_fail_next');
  $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', $prefix, 'STARTS_WITH')->execute();
  $db = \Drupal::database();
  $tasks = \Drupal::service('xedc_core.tasks');
  $filesystem = \Drupal::service('file_system');
  foreach ($ids as $nid) {
    $node = Node::load((int) $nid);
    $mids = [];
    if ($node) {
      $mids = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
      $node->delete();
    }
    foreach ($tasks->forNode((int) $nid) as $row) {
      $dir = $filesystem->realpath('private://xedc-import/tasks/' . (int) $row->id);
      $root = $filesystem->realpath('private://xedc-import/tasks');
      if ($dir && $root && str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
        $filesystem->deleteRecursive($dir);
      }
      foreach ($tasks->items((int) $row->id) as $item) {
        if (!empty($item->mid)) {
          $mids[] = (int) $item->mid;
        }
      }
      $db->delete('xedc_import_item')->condition('task_id', (int) $row->id)->execute();
      $db->delete('xedc_import_log')->condition('task_id', (int) $row->id)->execute();
      $db->delete('file_usage')->condition('type', 'xedc_import_task')->condition('id', (int) $row->id)->execute();
      $db->delete('xedc_import_task')->condition('id', (int) $row->id)->execute();
    }
    foreach (array_unique(array_filter($mids)) as $mid) {
      $used = (int) $db->select('node__field_images', 'f')->condition('field_images_target_id', $mid)->countQuery()->execute()->fetchField();
      if ($used > 0) {
        continue;
      }
      $media = Media::load($mid);
      if (!$media) {
        continue;
      }
      $fid = (int) $media->get('field_media_image')->target_id;
      $media->delete();
      $file = $fid ? File::load($fid) : NULL;
      if ($file) {
        $fileUsed = (int) $db->select('media__field_media_image', 'm')->condition('field_media_image_target_id', $fid)->countQuery()->execute()->fetchField();
        if ($fileUsed < 1) {
          $file->delete();
        }
      }
      $db->delete('xedc_image_alt')->condition('mid', $mid)->execute();
    }
  }
  $switcher->switchBack();
  exec('rm -rf ' . escapeshellarg($work));
}

$failed = array_filter($results, static fn(array $row): bool => $row[1] === FALSE);
echo $failed ? 'FAILED ' . count($failed) . '/' . count($results) . "\n" : 'ALL PASS ' . count($results) . "\n";
if ($failed) {
  throw new RuntimeException('phase 2 self-test failed');
}
