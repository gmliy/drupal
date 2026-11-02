<?php

/**
 * @file
 * Isolated business checks. Deletes only records this run creates.
 *
 * Does not enable takeover, copy production files, or change existing URIs.
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/r2-acceptance-business.php
 */

declare(strict_types=1);

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\xedc_core\Entity\DownloadResource;
use Drupal\xedc_core\Ops\Storage\BootGuard;
use Drupal\xedc_core\Ops\Storage\S3ClientFactory;
use Drupal\xedc_core\Ops\Storage\StorageUriResolver;

$failed = 0;
$check = static function (string $label, bool $condition, string $detail = '') use (&$failed): void {
  echo ($condition ? 'OK  ' : 'FAIL ') . $label . ($detail !== '' ? ' :: ' . $detail : '') . PHP_EOL;
  if (!$condition) {
    $failed++;
  }
};

$filesBefore = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {file_managed}')->fetchField();
$nodesBefore = (int) \Drupal::database()->query("SELECT COUNT(*) FROM {node} WHERE type = 'photo'")->fetchField();
$createdFiles = [];
$createdMedia = [];
$createdNodes = [];
$createdResources = [];
$createdUsers = [];
$localPaths = [];
$remoteKeys = [];
$extractDir = '';

$cleanup = static function () use (&$createdResources, &$createdNodes, &$createdMedia, &$createdFiles, &$createdUsers, &$localPaths, &$remoteKeys, &$extractDir): void {
  foreach ($createdResources as $entity) {
    if ($entity && method_exists($entity, 'id') && $entity->id()) {
      $entity->delete();
    }
  }
  foreach ($createdNodes as $entity) {
    if ($entity && $entity->id()) {
      $entity->delete();
    }
  }
  foreach ($createdMedia as $entity) {
    if ($entity && $entity->id()) {
      $entity->delete();
    }
  }
  foreach ($createdFiles as $entity) {
    if (!$entity || !$entity->id()) {
      continue;
    }
    $uri = $entity->getFileUri();
    $entity->delete();
    $real = \Drupal::service('file_system')->realpath($uri);
    if (is_string($real) && is_file($real)) {
      unlink($real);
    }
  }
  foreach ($createdUsers as $uid) {
    \Drupal::database()->delete('xedc_vip')->condition('uid', $uid)->execute();
    \Drupal::database()->delete('xedc_vip_log')->condition('uid', $uid)->execute();
    \Drupal::database()->delete('xedc_storage_download_log')->condition('uid', $uid)->execute();
    $account = User::load($uid);
    if ($account) {
      $account->delete();
    }
  }
  foreach ($localPaths as $path) {
    if (is_string($path) && is_file($path)) {
      unlink($path);
    }
  }
  if ($extractDir !== '' && is_dir($extractDir)) {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
      $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($extractDir);
  }
  if ($remoteKeys !== []) {
    $profile = \Drupal::service('xedc_core.storage_config')->profile('silly');
    $resolved = \Drupal::service('xedc_core.storage_credentials')->resolve($profile);
    if (!empty($resolved['ok']) && is_array($resolved['connection'] ?? NULL)) {
      $client = S3ClientFactory::create($resolved['connection']);
      foreach ($remoteKeys as $key) {
        $client->deleteObject(['Bucket' => (string) $resolved['connection']['bucket'], 'Key' => $key]);
      }
    }
  }
};

try {
  $directory = 'public://xedc-r2-acceptance';
  \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $image = imagecreatetruecolor(64, 40);
  $color = imagecolorallocate($image, 30, 120, 180);
  imagefilledrectangle($image, 0, 0, 63, 39, $color);
  $png = \Drupal::service('file_system')->realpath($directory) . '/sample.png';
  imagepng($image, $png);
  imagedestroy($image);
  $localPaths[] = $png;
  $info = \Drupal::service('xedc_core.images')->validate($png);
  $check('image pipeline accepts generated png', ($info['mime'] ?? '') === 'image/png' && (int) ($info['width'] ?? 0) === 64);

  $file = File::create([
    'uri' => $directory . '/sample.png',
    'filename' => 'xedc-r2-acceptance.png',
    'filemime' => 'image/png',
    'status' => 1,
    'uid' => 1,
  ]);
  $file->save();
  $createdFiles[] = $file;
  $media = Media::create([
    'bundle' => 'image',
    'name' => 'xedc-r2-acceptance',
    'uid' => 1,
    'status' => 0,
    'field_media_image' => [
      'target_id' => $file->id(),
      'alt' => 'xedc r2 acceptance',
      'width' => 64,
      'height' => 40,
    ],
  ]);
  $media->save();
  $createdMedia[] = $media;
  $node = Node::create([
    'type' => 'photo',
    'title' => 'XEDC R2 acceptance ' . bin2hex(random_bytes(3)),
    'uid' => 1,
    'status' => 0,
    'field_images' => [['target_id' => $media->id()]],
  ]);
  $node->save();
  $createdNodes[] = $node;
  $check('unpublished photo node created', (int) $node->id() > 0 && !$node->isPublished());

  $delivery = \Drupal::service('xedc_core.storage_delivery')->forUri($file->getFileUri(), (int) $file->id());
  $check('acceptance image url is not cdn', empty($delivery['cdn']) && !str_contains((string) ($delivery['url'] ?? ''), 'cdn.gitxx.xyz'));
  $stylesOk = TRUE;
  foreach (['thumbnail', 'photo_gallery_650'] as $styleId) {
    $style = ImageStyle::load($styleId);
    $derivative = $style ? $style->buildUri($file->getFileUri()) : '';
    $built = $style && $derivative !== '' && $style->createDerivative($file->getFileUri(), $derivative);
    $url = $style ? $style->buildUrl($file->getFileUri()) : '';
    $real = $derivative !== '' ? \Drupal::service('file_system')->realpath($derivative) : FALSE;
    $localPaths[] = is_string($real) ? $real : '';
    if (!$built || !is_string($real) || !is_file($real) || str_contains($url, 'cdn.gitxx.xyz')) {
      $stylesOk = FALSE;
      echo 'FAIL style ' . $styleId . PHP_EOL;
    }
    else {
      echo 'OK  style ' . $styleId . ' bytes=' . (int) filesize($real) . ' local-url' . PHP_EOL;
    }
  }
  $check('styles generated locally', $stylesOk);

  $webp = \Drupal::service('xedc_core.storage_temp')->createFile('accept', '.webp');
  $localPaths[] = $webp;
  $source = imagecreatefrompng($png);
  $webpOk = $source && function_exists('imagewebp') && imagewebp($source, $webp, 80) && is_file($webp) && filesize($webp) > 0 && is_file($png);
  if ($source) {
    imagedestroy($source);
  }
  $check('webp conversion keeps the png', $webpOk);
  $avifOk = function_exists('imageavif');
  if ($avifOk) {
    $avif = \Drupal::service('xedc_core.storage_temp')->createFile('accept', '.avif');
    $localPaths[] = $avif;
    $source = imagecreatefrompng($png);
    $avifOk = $source && imageavif($source, $avif, 60) && is_file($avif) && filesize($avif) > 0 && is_file($png);
    if ($source) {
      imagedestroy($source);
    }
  }
  $check('avif conversion keeps the png', $avifOk);

  $downloadDir = 'private://xedc-downloads';
  \Drupal::service('file_system')->prepareDirectory($downloadDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $privateUri = $downloadDir . '/xedc-r2-acceptance-' . bin2hex(random_bytes(3)) . '.txt';
  $privateReal = \Drupal::service('file_system')->realpath($downloadDir) . '/' . basename($privateUri);
  file_put_contents($privateReal, 'xedc-r2-acceptance');
  chmod($privateReal, 0600);
  $privateFile = File::create([
    'uri' => $privateUri,
    'filename' => basename($privateUri),
    'filemime' => 'text/plain',
    'status' => 1,
    'uid' => 1,
  ]);
  $privateFile->save();
  $createdFiles[] = $privateFile;

  $member = User::create(['name' => 'xedc_r2_member_' . bin2hex(random_bytes(3)), 'mail' => 'xedc-r2-member-' . bin2hex(random_bytes(3)) . '@example.com', 'status' => 1]);
  $member->save();
  $createdUsers[] = (int) $member->id();
  $vip = User::create(['name' => 'xedc_r2_vip_' . bin2hex(random_bytes(3)), 'mail' => 'xedc-r2-vip-' . bin2hex(random_bytes(3)) . '@example.com', 'status' => 1]);
  $vip->save();
  $createdUsers[] = (int) $vip->id();
  $now = \Drupal::time()->getRequestTime();
  \Drupal::service('xedc_core.membership')->grant((int) $vip->id(), $now - 60, $now + 86400, 1, 'r2 acceptance');
  $vip = User::load((int) $vip->id());
  $check('membership tiers', \Drupal::service('xedc_core.membership')->tier(new AnonymousUserSession()) === 'anonymous' && \Drupal::service('xedc_core.membership')->tier($member) === 'member' && \Drupal::service('xedc_core.membership')->tier($vip) === 'vip');

  if ($node->hasField('moderation_state')) {
    $node->set('moderation_state', 'published');
  }
  $node->setPublished();
  $node->save();
  echo 'NODE published=' . ($node->isPublished() ? 'yes' : 'no') . PHP_EOL;
  $resource = DownloadResource::create([
    'title' => 'XEDC R2 acceptance download',
    'parent' => $node->id(),
    'source_type' => 'upload',
    'access_level' => 'vip',
    'file' => $privateFile->id(),
    'status' => 1,
  ]);
  $resource->save();
  $createdResources[] = $resource;

  $downloader = \Drupal::service('xedc_core.storage_download');
  $anonymous = $downloader->authorize((int) $privateFile->id(), new AnonymousUserSession());
  $memberDecision = $downloader->authorize((int) $privateFile->id(), $member);
  $vipDecision = $downloader->authorize((int) $privateFile->id(), $vip);
  $admin = User::load(1);
  $adminDecision = $downloader->authorize((int) $privateFile->id(), $admin);
  echo 'ANON status=' . (int) ($anonymous['status'] ?? 0) . ' message=' . (string) ($anonymous['message'] ?? '') . PHP_EOL;
  echo 'MEMBER status=' . (int) ($memberDecision['status'] ?? 0) . ' message=' . (string) ($memberDecision['message'] ?? '') . PHP_EOL;
  echo 'VIP status=' . (int) ($vipDecision['status'] ?? 0) . ' message=' . (string) ($vipDecision['message'] ?? '') . PHP_EOL;
  echo 'ADMIN status=' . (int) ($adminDecision['status'] ?? 0) . ' message=' . (string) ($adminDecision['message'] ?? '') . PHP_EOL;
  $check('anonymous gets no url', ($anonymous['status'] ?? 0) === 403 && ($anonymous['url'] ?? 'x') === '');
  $check('member is denied vip resource', ($memberDecision['status'] ?? 0) === 403 && ($memberDecision['url'] ?? 'x') === '' && str_contains((string) ($memberDecision['message'] ?? ''), 'VIP'));
  $check('active vip is not signed while takeover is off', ($vipDecision['status'] ?? 0) === 503 && ($vipDecision['url'] ?? 'x') === '');
  $check('administrator without vip record is denied', ($adminDecision['status'] ?? 0) === 403 && ($adminDecision['url'] ?? 'x') === '');
  $logged = (int) \Drupal::database()->select('xedc_storage_download_log', 'd')->condition('fid', (int) $privateFile->id())->countQuery()->execute()->fetchField();
  $check('download decisions were logged', $logged >= 3);

  $profile = \Drupal::service('xedc_core.storage_config')->profile('silly');
  $resolved = \Drupal::service('xedc_core.storage_credentials')->resolve($profile);
  $connection = $resolved['connection'];
  $client = S3ClientFactory::create($connection);
  $bucket = (string) $connection['bucket'];
  $prefix = StorageUriResolver::INTEGRATION_PREFIX . 'accept-' . bin2hex(random_bytes(3)) . '/';
  $privateKey = 's3fs-private/' . $prefix . 'probe.txt';
  $publicKey = $prefix . 'clip.mp4';
  $remoteKeys[] = $privateKey;
  $remoteKeys[] = $publicKey;
  $client->putObject(['Bucket' => $bucket, 'Key' => $privateKey, 'Body' => 'private-probe', 'ContentType' => 'text/plain']);
  $mp4 = \Drupal::service('xedc_core.storage_temp')->createFile('clip', '.mp4');
  $localPaths[] = $mp4;
  $mp4Bytes = base64_decode('AAAAIGZ0eXBpc29tAAACAGlzb21pc28yYXZjMW1wNDEAAAAIZnJlZQAAAsdtZGF0AAACGHNlYXJjaCBzYW1wbGU=', TRUE);
  file_put_contents($mp4, is_string($mp4Bytes) ? $mp4Bytes : '');
  $described = trim((string) shell_exec('file -b ' . escapeshellarg($mp4)));
  echo 'MP4 file(1)=' . $described . PHP_EOL;
  $client->putObject(['Bucket' => $bucket, 'Key' => $publicKey, 'SourceFile' => $mp4, 'ContentType' => 'video/mp4', 'CacheControl' => 'public, max-age=60']);
  $head = $client->headObject(['Bucket' => $bucket, 'Key' => $publicKey]);
  $check('mp4 object content type', (string) ($head['ContentType'] ?? '') === 'video/mp4' && (int) ($head['ContentLength'] ?? 0) === filesize($mp4));
  $command = $client->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $publicKey]);
  $signed = (string) $client->createPresignedRequest($command, '+1 second')->getUri();
  sleep(2);
  $expired = httpCode($signed, ['Range: bytes=0-3']);
  unset($signed);
  $check('expired signature is rejected', $expired['code'] === 403);
  $fresh = (string) $client->createPresignedRequest($command, '+60 seconds')->getUri();
  $range = httpCode($fresh, ['Range: bytes=0-3']);
  unset($fresh);
  $check('fresh signature range', $range['code'] === 206 && $range['type'] === 'video/mp4' && $range['ranges'] === 'bytes');
  $cdnPublic = httpCode('https://cdn.gitxx.xyz/' . $publicKey, ['Range: bytes=0-3']);
  echo 'CDN_MP4 code=' . $cdnPublic['code'] . ' type=' . $cdnPublic['type'] . ' ranges=' . $cdnPublic['ranges'] . ' cache=' . $cdnPublic['cache'] . PHP_EOL;
  $cdnPrivate = httpCode('https://cdn.gitxx.xyz/' . $privateKey, []);
  echo 'CDN_PRIVATE code=' . $cdnPrivate['code'] . ' type=' . $cdnPrivate['type'] . PHP_EOL;
  $check('private prefix is not anonymously readable', $cdnPrivate['code'] !== 200);
  $client->deleteObject(['Bucket' => $bucket, 'Key' => $privateKey]);
  $client->deleteObject(['Bucket' => $bucket, 'Key' => $publicKey]);
  $remoteKeys = [];
  try {
    $client->headObject(['Bucket' => $bucket, 'Key' => $publicKey]);
    $gone = FALSE;
  }
  catch (\Throwable) {
    $gone = TRUE;
  }
  $check('mp4 probe key is gone', $gone);

  $zipPath = \Drupal::service('xedc_core.storage_temp')->createFile('pack', '.zip');
  $localPaths[] = $zipPath;
  $zip = new \ZipArchive();
  $zip->open($zipPath, \ZipArchive::CREATE);
  $zip->addFile($png, 'album/one.png');
  $zip->close();
  $zipUri = 'private://xedc-import/inbox/xedc-r2-acceptance.zip';
  $inboxDir = 'private://xedc-import/inbox';
  \Drupal::service('file_system')->prepareDirectory($inboxDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $inbox = \Drupal::service('file_system')->realpath('private://xedc-import/inbox') . '/xedc-r2-acceptance.zip';
  copy($zipPath, $inbox);
  $localPaths[] = $inbox;
  $taskId = ((int) \Drupal::database()->query('SELECT COALESCE(MAX(id), 0) FROM {xedc_import_task}')->fetchField()) + 100000;
  $task = (object) ['id' => $taskId, 'archive_uri' => $zipUri];
  $extracted = \Drupal::service('xedc_core.archive')->openAndExtract($task);
  $extractDir = (string) (\Drupal::service('file_system')->realpath('private://xedc-import/tasks/' . $taskId) ?: '');
  $check('zip extractor found the png', ($extracted['format'] ?? '') === 'zip' && count($extracted['files'] ?? []) === 1);
  $check('zip slip helper rejects traversal', \Drupal::service('xedc_core.archive')->unsafeRelative('../escape.png') === TRUE);
}
catch (\Throwable $error) {
  echo 'ERROR ' . BootGuard::safeError($error, []) . PHP_EOL;
  $failed++;
}
finally {
  $cleanup();
}

$filesAfter = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {file_managed}')->fetchField();
$nodesAfter = (int) \Drupal::database()->query("SELECT COUNT(*) FROM {node} WHERE type = 'photo'")->fetchField();
$check('file entities restored', $filesBefore === $filesAfter, $filesBefore . ' -> ' . $filesAfter);
$check('photo nodes restored', $nodesBefore === $nodesAfter, $nodesBefore . ' -> ' . $nodesAfter);
$check('runtime still off', \Drupal\Core\Site\Settings::get('xedc_storage.runtime_applied') !== TRUE);

if ($failed > 0) {
  echo $failed . " failed\n";
  exit(1);
}
echo "all passed\n";

/**
 * @param list<string> $headers
 *   Request headers.
 *
 * @return array{code: int, type: string, ranges: string, cache: string}
 *   Response summary. The URL is not returned.
 */
function httpCode(string $url, array $headers): array {
  $empty = ['code' => 0, 'type' => '', 'ranges' => '', 'cache' => ''];
  if (!function_exists('curl_init') || $url === '') {
    return $empty;
  }
  $handle = curl_init($url);
  if ($handle === FALSE) {
    return $empty;
  }
  curl_setopt_array($handle, [
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_HEADER => TRUE,
    CURLOPT_NOBODY => FALSE,
    CURLOPT_FOLLOWLOCATION => FALSE,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $headers,
  ]);
  $raw = curl_exec($handle);
  $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
  $type = strtolower(trim(explode(';', (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE))[0]));
  $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
  curl_close($handle);
  $header = is_string($raw) ? substr($raw, 0, $headerSize) : '';
  $ranges = '';
  $cache = '';
  if (preg_match('/^accept-ranges:\s*(.+)$/mi', $header, $match) === 1) {
    $ranges = trim($match[1]);
  }
  if (preg_match('/^cache-control:\s*(.+)$/mi', $header, $match) === 1) {
    $cache = trim($match[1]);
  }
  return ['code' => $code, 'type' => $type, 'ranges' => $ranges, 'cache' => $cache];
}
