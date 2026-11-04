<?php

/**
 * @file
 * Session identity and SHA-256 probe checks. Deletes only the rows it creates.
 *
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/upload-dedup-self-test.php
 */

use Drupal\Core\Session\UserSession;
use Drupal\node\Entity\Node;
use Drupal\xedc_core\ImportStatus;
use Drupal\xedc_core\Ops\Storage\StorageSizeFormatter;

$results = [];
$check = static function (string $name, bool $ok, string $detail) use (&$results): void {
  $results[] = [$name, $ok, $detail];
  echo ($ok ? 'PASS ' : 'FAIL ') . $name . ': ' . $detail . "\n";
};

$prefix = 'XEDC去重验收 ' . date('YmdHis');
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new UserSession(['uid' => 1]));
$uploads = \Drupal::service('xedc_core.upload_session');
$database = \Drupal::database();
$node = NULL;
$sessions = [];

try {
  $check('512 字节显示为 512 B', StorageSizeFormatter::formatBytes(512) === '512 B', StorageSizeFormatter::formatBytes(512));
  $check('1 KiB', StorageSizeFormatter::formatBytes(1024) === '1.00 KiB', StorageSizeFormatter::formatBytes(1024));
  $check('1 MiB', StorageSizeFormatter::formatBytes(1048576) === '1.00 MiB', StorageSizeFormatter::formatBytes(1048576));
  $check('26880000 显示为 25.63 MiB', StorageSizeFormatter::formatBytes(26880000) === '25.63 MiB', StorageSizeFormatter::formatBytes(26880000));
  $check('1 GiB', StorageSizeFormatter::formatBytes(1073741824) === '1.00 GiB', StorageSizeFormatter::formatBytes(1073741824));
  $check('未知状态', ImportStatus::label('not-a-real-status') === '未知状态', ImportStatus::label('not-a-real-status'));
  $check('等待上传', ImportStatus::label('pending') === '等待上传', ImportStatus::label('pending'));

  $node = Node::create([
    'type' => 'photo',
    'title' => $prefix,
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $node->xedc_keep_unpublished = TRUE;
  $node->save();
  $head = hash('sha256', 'xedc-dedup-head');
  $clientA = bin2hex(random_bytes(16));
  $payload = [
    'filename' => 'dedup.jpg',
    'size' => 1200,
    'kind' => 'image',
    'mime' => 'image/jpeg',
    'head_sha256' => $head,
    'file_sha256' => hash('sha256', 'xedc-dedup-file'),
    'client_upload_id' => $clientA,
    'nid' => (int) $node->id(),
    'title' => $prefix,
  ];
  $first = $uploads->open($payload);
  $second = $uploads->open($payload);
  $sessions[] = (string) ($first['data']['session_id'] ?? '');
  $check('同一次选择只创建一条会话', $first['http'] === 201 && $second['http'] === 200 && ($second['data']['session_id'] ?? '') === ($first['data']['session_id'] ?? 'x') && !empty($second['data']['resumed']), 'http ' . $first['http'] . '/' . $second['http'] . ' id ' . ($first['data']['session_id'] ?? ''));

  $payload['client_upload_id'] = bin2hex(random_bytes(16));
  $other = $uploads->open($payload);
  $sessions[] = (string) ($other['data']['session_id'] ?? '');
  $check('两次选择保持两条会话', $other['http'] === 201 && ($other['data']['session_id'] ?? '') !== ($first['data']['session_id'] ?? ''), (string) ($other['data']['session_id'] ?? $other['data']['error'] ?? $other['http']));

  $payload['filename'] = 'other.jpg';
  $conflict = $uploads->open($payload);
  $check('同一标识不能换成另一个文件', $conflict['http'] === 409, (string) ($conflict['data']['error'] ?? $conflict['http']));

  $unknown = $uploads->probe([
    'kind' => 'image',
    'sha256' => hash('sha256', 'missing-' . $prefix),
    'size' => 1200,
    'filename' => 'missing.jpg',
    'fid' => 1,
    'apply' => FALSE,
  ]);
  $check('未知哈希不会被当成已存在', ($unknown['data']['reusable'] ?? TRUE) === FALSE && !empty($unknown['data']['upload']) && (int) ($unknown['data']['fid'] ?? 0) === 0, json_encode($unknown['data'], JSON_UNESCAPED_UNICODE));

  $count = (int) $database->select('xedc_upload_session', 's')
    ->condition('client_upload_id', $clientA)
    ->countQuery()
    ->execute()
    ->fetchField();
  $check('数据库里同一个标识只有一行', $count === 1, 'count=' . $count);
}
catch (Throwable $e) {
  $check('去重验收没有中断', FALSE, $e->getMessage());
}
finally {
  foreach (array_unique(array_filter($sessions)) as $sessionId) {
    try {
      $uploads->cancel($sessionId);
      $database->delete('xedc_upload_chunk')->condition('session_id', $sessionId)->execute();
      $database->delete('xedc_upload_session')->condition('session_id', $sessionId)->execute();
    }
    catch (Throwable $e) {
      echo 'CLEAN ' . $sessionId . ' ' . $e->getMessage() . "\n";
    }
  }
  if ($node && $node->id()) {
    $nid = (int) $node->id();
    $loaded = Node::load($nid);
    if ($loaded) {
      $loaded->delete();
    }
  }
  $switcher->switchBack();
}

$failed = array_filter($results, static fn(array $row): bool => $row[1] === FALSE);
echo $failed ? 'FAILED ' . count($failed) . '/' . count($results) . "\n" : 'ALL PASS ' . count($results) . "\n";
if ($failed) {
  throw new RuntimeException('upload dedup self-test failed');
}
