<?php

/**
 * @file
 * Isolated checks for the shared image optimizer. Creates files only under
 * public://xedc-opt-test-{token}/ and deletes those objects before exiting.
 */

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\xedc_core\Image\ImageOptimizer;

$token = 't' . bin2hex(random_bytes(6));
$prefix = 'xedc-opt-test-' . $token;
$results = [];
$fids = [];
$mediaIds = [];
$stylePaths = [];
$settingsBackup = NULL;

$check = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
  $results[] = [$ok ? 'PASS' : 'FAIL', $name, $detail];
  echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . ($detail !== '' ? ' ' . $detail : '') . PHP_EOL;
};

$jpeg = static function (int $w, int $h, int $quality = 95): string {
  $image = imagecreatetruecolor($w, $h);
  $color = imagecolorallocate($image, 180, 40, 40);
  imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, $color);
  $path = tempnam(sys_get_temp_dir(), 'xedc-jpg-');
  imagejpeg($image, $path, $quality);
  imagedestroy($image);
  return (string) $path;
};

$optimizer = \Drupal::service('xedc_core.image_optimizer');
$repository = \Drupal::service('xedc_core.image_optimize_repository');
$applier = \Drupal::service('xedc_core.image_optimize');
$filesystem = \Drupal::service('file_system');
$settings = $repository->settings();
assert($optimizer instanceof ImageOptimizer);

try {
  $resolver = \Drupal::service('xedc_core.storage_uris');
  $key = (string) $resolver->objectKey('public://' . $prefix . '/probe.webp');
  $check('public prefix', $key === 'public/' . $prefix . '/probe.webp', $key);
  $wrapper = \Drupal::service('stream_wrapper_manager')->getViaUri('public://');
  $check('wrapper', $wrapper instanceof \Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream, $wrapper ? get_class($wrapper) : 'missing');

  $source = $jpeg(120, 80);
  $started = hrtime(TRUE);
  $memory = memory_get_usage(TRUE);
  $jpegResult = $optimizer->optimize($source, $settings);
  $jpegMs = (int) ((hrtime(TRUE) - $started) / 1000000);
  $jpegPeak = max(0, memory_get_peak_usage(TRUE) - $memory);
  $out = $jpegResult->keptOriginal ? $source : $jpegResult->path;
  $info = @getimagesize($out);
  $check('jpeg optimized', $jpegResult->status !== 'rejected' && $info && (int) $info[0] === 120 && (int) $info[1] === 80 && $jpegResult->bytes <= $jpegResult->sourceBytes, $jpegResult->sourceBytes . '->' . $jpegResult->bytes . ' ' . $jpegResult->mime . ' ' . $jpegMs . 'ms');
  echo 'PERF jpeg_ms=' . $jpegMs . ' jpeg_memory=' . $jpegPeak . ' jpeg_saved=' . $jpegResult->savedBytes() . PHP_EOL;

  $again = $optimizer->optimize($out, $settings);
  $check('idempotent', $again->status !== 'rejected' && $again->bytes <= $jpegResult->bytes, $again->status . ' ' . $again->bytes);

  $png = imagecreatetruecolor(40, 30);
  imagefilledrectangle($png, 0, 0, 39, 29, imagecolorallocate($png, 0, 120, 40));
  $pngPath = tempnam(sys_get_temp_dir(), 'xedc-png-');
  imagepng($png, $pngPath);
  imagedestroy($png);
  $pngResult = $optimizer->optimize($pngPath, $settings);
  $pngOut = $pngResult->keptOriginal ? $pngPath : $pngResult->path;
  $pngInfo = @getimagesize($pngOut);
  $check('png', $pngResult->status !== 'rejected' && $pngInfo && (int) $pngInfo[0] === 40 && $pngResult->bytes <= $pngResult->sourceBytes, $pngResult->sourceBytes . '->' . $pngResult->bytes . ' ' . $pngResult->mime);

  $alpha = imagecreatetruecolor(32, 32);
  imagealphablending($alpha, FALSE);
  imagesavealpha($alpha, TRUE);
  $clear = imagecolorallocatealpha($alpha, 0, 0, 0, 127);
  imagefilledrectangle($alpha, 0, 0, 31, 31, $clear);
  imagefilledrectangle($alpha, 4, 4, 20, 20, imagecolorallocatealpha($alpha, 255, 0, 0, 0));
  $alphaPath = tempnam(sys_get_temp_dir(), 'xedc-a-');
  imagepng($alpha, $alphaPath);
  imagedestroy($alpha);
  $alphaResult = $optimizer->optimize($alphaPath, $settings);
  $alphaOut = $alphaResult->keptOriginal ? $alphaPath : $alphaResult->path;
  $alphaInfo = @getimagesize($alphaOut);
  $alphaKept = $alphaResult->mime !== 'image/jpeg';
  $check('transparent png', $alphaResult->status !== 'rejected' && $alphaKept && $alphaInfo && (int) $alphaInfo[0] === 32, $alphaResult->mime . ' ' . $alphaResult->sourceBytes . '->' . $alphaResult->bytes);

  $webp = imagecreatetruecolor(24, 24);
  imagefilledrectangle($webp, 0, 0, 23, 23, imagecolorallocate($webp, 10, 10, 200));
  $webpPath = tempnam(sys_get_temp_dir(), 'xedc-w-');
  imagewebp($webp, $webpPath, 80);
  imagedestroy($webp);
  $webpResult = $optimizer->optimize($webpPath, $settings);
  $check('webp', $webpResult->status !== 'rejected' && $webpResult->bytes <= $webpResult->sourceBytes, $webpResult->status . ' ' . $webpResult->bytes);

  $gif = hex2bin('47494638396101000100800000000000ffffff21f90400000000002c000000000100010000020244010021f904000a0000002c00000000010001000002024401003b');
  $gifPath = tempnam(sys_get_temp_dir(), 'xedc-g-');
  file_put_contents($gifPath, $gif);
  $gifResult = $optimizer->optimize($gifPath, $settings);
  $gifOut = $gifResult->keptOriginal ? $gifPath : $gifResult->path;
  $controls = substr_count((string) file_get_contents($gifOut), "\x21\xF9\x04");
  $check('animated gif', $gifResult->keptOriginal && $gifResult->mime === 'image/gif' && $controls > 1, 'controls=' . $controls . ' ' . $gifResult->error);

  $svg = tempnam(sys_get_temp_dir(), 'xedc-s-');
  file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"></svg>');
  $svgResult = $optimizer->optimize($svg, $settings);
  $check('svg kept', $svgResult->status === 'kept' && $svgResult->mime === 'image/svg+xml', $svgResult->status);
  file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
  $badSvg = $optimizer->optimize($svg, $settings);
  $check('svg script rejected', $badSvg->status === 'rejected' && is_file($svg), $badSvg->error);

  $empty = tempnam(sys_get_temp_dir(), 'xedc-e-');
  file_put_contents($empty, '');
  $emptyResult = $optimizer->optimize($empty, $settings);
  $check('empty rejected', $emptyResult->status === 'rejected', $emptyResult->error);
  $broken = tempnam(sys_get_temp_dir(), 'xedc-b-');
  file_put_contents($broken, 'not-an-image');
  $brokenResult = $optimizer->optimize($broken, $settings);
  $check('broken rejected', $brokenResult->status === 'rejected' && is_file($broken), $brokenResult->error);
  $huge = $optimizer->optimize($source, ['max_bytes' => 10] + $settings);
  $check('oversize rejected', $huge->status === 'rejected' && is_file($source), $huge->error);

  $same = $optimizer->optimize($source, ['auto_format' => FALSE] + $settings);
  $check('jpeg stays jpeg when auto format is off', $same->mime === 'image/jpeg' || $same->keptOriginal, $same->mime . ' ' . $same->status);

  $orientedPath = $jpeg(30, 60, 90);
  $raw = (string) file_get_contents($orientedPath);
  $exif = "Exif\x00\x00MM\x00*\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', 6) . "\x00\x00\x00\x00\x00\x00";
  $segment = pack('n', strlen($exif) + 2) . $exif;
  if (str_starts_with($raw, "\xFF\xD8")) {
    file_put_contents($orientedPath, "\xFF\xD8\xFF\xE1" . $segment . substr($raw, 2));
  }
  $exifData = @exif_read_data($orientedPath);
  $oriented = $optimizer->optimize($orientedPath, $settings);
  $orientedOut = $oriented->keptOriginal ? $orientedPath : $oriented->path;
  $orientedInfo = @getimagesize($orientedOut);
  $check('exif orientation', (int) ($exifData['Orientation'] ?? 0) === 6 && $orientedInfo && (int) $orientedInfo[0] === 60 && (int) $orientedInfo[1] === 30, 'orientation=' . (int) ($exifData['Orientation'] ?? 0) . ' out=' . ($orientedInfo[0] ?? 0) . 'x' . ($orientedInfo[1] ?? 0));

  if (function_exists('imageavif')) {
    $avifImage = imagecreatetruecolor(16, 16);
    imagefilledrectangle($avifImage, 0, 0, 15, 15, imagecolorallocate($avifImage, 0, 0, 0));
    $avifPath = tempnam(sys_get_temp_dir(), 'xedc-av-');
    $wrote = @imageavif($avifImage, $avifPath, 70);
    imagedestroy($avifImage);
    $avifResult = $wrote ? $optimizer->optimize($avifPath, ['allow_avif' => TRUE] + $settings) : NULL;
    $check('avif encoder', $wrote && $avifResult && $avifResult->status !== 'rejected', $avifResult ? $avifResult->mime . ' ' . $avifResult->bytes : 'write failed');
  }
  else {
    echo 'NOT_TESTED avif encoder missing' . PHP_EOL;
  }

  $archive = \Drupal::service('xedc_core.archive');
  $check('zip slip helper', $archive->unsafeRelative('../evil.jpg') === TRUE && $archive->unsafeRelative('照片/1.jpg') === FALSE, '');
  $zipPath = sys_get_temp_dir() . '/xedc-' . $token . '.zip';
  $zip = new ZipArchive();
  $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  $one = $jpeg(20, 20, 90);
  $two = $jpeg(22, 18, 90);
  $zip->addFile($one, 'a.jpg');
  $zip->addFile($two, 'nested/b.jpg');
  $zip->addFromString('../evil.jpg', 'nope');
  $zip->close();
  $dest = sys_get_temp_dir() . '/xedc-unzip-' . $token;
  mkdir($dest);
  $extract = new ReflectionMethod($archive, 'extractZip');
  $extract->setAccessible(TRUE);
  $slipped = FALSE;
  try {
    $extract->invoke($archive, $zipPath, $dest);
  }
  catch (Throwable $error) {
    $slipped = str_contains($error->getMessage(), '不安全');
  }
  $check('zip slip extract refused', $slipped && !is_file(dirname($dest) . '/evil.jpg') && !is_file($dest . '/../evil.jpg'), '');

  $zipOk = sys_get_temp_dir() . '/xedc-ok-' . $token . '.zip';
  $zip = new ZipArchive();
  $zip->open($zipOk, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  $zip->addFile($one, 'a.jpg');
  $zip->addFile($two, 'b.jpg');
  $zip->close();
  $okDir = sys_get_temp_dir() . '/xedc-okdir-' . $token;
  mkdir($okDir);
  $extract->invoke($archive, $zipOk, $okDir);
  $pipeline = \Drupal::service('xedc_core.images');
  $describe = new ReflectionMethod($pipeline, 'describe');
  $describe->setAccessible(TRUE);
  $official = new ReflectionMethod($pipeline, 'officialImage');
  $official->setAccessible(TRUE);
  $optimizedZip = 0;
  foreach (['a.jpg', 'b.jpg'] as $index => $name) {
    $path = $okDir . '/' . $name;
    $checked = $describe->invoke($pipeline, $path);
    $built = $official->invoke($pipeline, $path, $okDir, $index + 1, $checked, 0, $name);
    if (is_file($built['path']) && $built['width'] === $checked['width'] && $built['height'] === $checked['height']) {
      $optimizedZip++;
    }
  }
  $check('zip images optimized', $optimizedZip === 2, (string) $optimizedZip);

  $seven = $archive->sevenZipBinary();
  if (is_string($seven)) {
    $sevenDir = sys_get_temp_dir() . '/xedc-7zsrc-' . $token;
    mkdir($sevenDir);
    copy($one, $sevenDir . '/c.jpg');
    copy($two, $sevenDir . '/d.jpg');
    $sevenPath = sys_get_temp_dir() . '/xedc-' . $token . '.7z';
    $proc = proc_open([$seven, 'a', '-y', '-bso0', '-bsp0', $sevenPath, 'c.jpg', 'd.jpg'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $sevenDir);
    if (is_resource($proc)) {
      fclose($pipes[1]);
      fclose($pipes[2]);
      proc_close($proc);
    }
    $sevenOut = sys_get_temp_dir() . '/xedc-7zout-' . $token;
    mkdir($sevenOut);
    $extract7 = new ReflectionMethod($archive, 'extractSevenZip');
    $extract7->setAccessible(TRUE);
    $extract7->invoke($archive, $sevenPath, $sevenOut);
    $count7 = 0;
    foreach (['c.jpg', 'd.jpg'] as $index => $name) {
      $path = $sevenOut . '/' . $name;
      if (!is_file($path)) {
        continue;
      }
      $checked = $describe->invoke($pipeline, $path);
      $built = $official->invoke($pipeline, $path, $sevenOut, $index + 1, $checked, 0, $name);
      if (is_file($built['path'])) {
        $count7++;
      }
    }
    $check('7z images optimized', $count7 === 2, (string) $count7);
  }
  else {
    echo 'NOT_TESTED 7z binary missing' . PHP_EOL;
  }

  $directory = 'public://' . $prefix;
  $filesystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  $publicSource = $jpeg(64, 48, 90);
  $publicUri = $directory . '/direct-' . $token . '.jpg';
  $filesystem->copy($publicSource, $publicUri, FileExists::Replace);
  $direct = File::create([
    'uri' => $publicUri,
    'filename' => 'direct-' . $token . '.jpg',
    'status' => FileInterface::STATUS_PERMANENT,
    'uid' => 1,
  ]);
  $direct->save();
  $fids[] = (int) $direct->id();
  $direct = File::load((int) $direct->id());
  $row = $repository->latestForFid((int) $direct->id());
  $storedKey = (string) $resolver->objectKey($direct->getFileUri());
  $read = $filesystem->realpath($direct->getFileUri());
  $localHash = is_string($read) && is_file($read) ? hash_file('sha256', $read) : '';
  if ($localHash === '') {
    $handle = fopen($direct->getFileUri(), 'rb');
    $context = hash_init('sha256');
    if ($handle) {
      while (!feof($handle)) {
        $chunk = fread($handle, 1048576);
        if (!is_string($chunk) || $chunk === '') {
          break;
        }
        hash_update($context, $chunk);
      }
      fclose($handle);
      $localHash = hash_final($context);
    }
  }
  $check('direct public upload', is_array($row) && $row['status'] === 'success' && $row['r2_status'] === 'stored' && str_starts_with($storedKey, 'public/' . $prefix . '/') && !str_contains($storedKey, 'public/public/') && hash_equals((string) $row['stored_sha256'], $localHash), $direct->getFileUri() . ' key=' . $storedKey . ' mime=' . $direct->getMimeType() . ' bytes=' . $direct->getSize());
  $url = \Drupal::service('file_url_generator')->generateAbsoluteString($direct->getFileUri());
  $headers = @get_headers($url);
  $statusLine = is_array($headers) ? (string) $headers[0] : '';
  $check('cdn or public url', str_contains($statusLine, '200'), $url . ' ' . $statusLine);
  echo 'R2 uri=' . $direct->getFileUri() . ' key=' . $storedKey . ' sha=' . $localHash . ' bytes=' . $direct->getSize() . ' mime=' . $direct->getMimeType() . PHP_EOL;

  $style = \Drupal::entityTypeManager()->getStorage('image_style')->load('meitu_card_cover') ?: \Drupal::entityTypeManager()->getStorage('image_style')->load('thumbnail');
  if ($style) {
    $derivative = 'public://styles/' . $style->id() . '/public/' . $prefix . '/' . $filesystem->basename($direct->getFileUri());
    $made = $style->createDerivative($direct->getFileUri(), $derivative);
    $derivativeReal = $filesystem->realpath($derivative);
    $stylePaths[] = is_string($derivativeReal) ? $derivativeReal : '';
    $check('local derivative', $made && is_string($derivativeReal) && is_file($derivativeReal) && str_contains($derivativeReal, '/styles/'), (string) $derivativeReal);
    $styleKey = (string) $resolver->objectKey($derivative);
    $check('derivative is not an r2 original key', !str_starts_with($styleKey, 'public/' . $prefix . '/'), $styleKey);
  }
  else {
    echo 'NOT_TESTED no image style' . PHP_EOL;
  }

  $tempUri = $filesystem->saveData((string) file_get_contents($publicSource), 'temporary://move-' . $token . '.jpg', FileExists::Replace);
  $tempFile = File::create([
    'uri' => $tempUri,
    'filename' => 'move-' . $token . '.jpg',
    'status' => 0,
    'uid' => 1,
  ]);
  $tempFile->save();
  $fids[] = (int) $tempFile->id();
  $moved = \Drupal::service('file.repository')->move($tempFile, $directory);
  $fids[] = (int) $moved->id();
  $movedRow = $repository->latestForFid((int) $moved->id());
  $movedKey = (string) $resolver->objectKey($moved->getFileUri());
  $check('media library move', is_array($movedRow) && $movedRow['status'] === 'success' && str_starts_with($moved->getFileUri(), 'public://' . $prefix . '/') && str_starts_with($movedKey, 'public/' . $prefix . '/'), $moved->getFileUri() . ' ' . $moved->getMimeType() . ' ' . ($movedRow['status'] ?? ''));

  $privateUri = $filesystem->saveData('private-text', 'private://' . $prefix . '.txt', FileExists::Replace);
  $private = File::create([
    'uri' => $privateUri,
    'filename' => $prefix . '.txt',
    'status' => FileInterface::STATUS_PERMANENT,
    'uid' => 1,
  ]);
  $private->save();
  $fids[] = (int) $private->id();
  $check('private skipped', $repository->latestForFid((int) $private->id()) === NULL, $private->getFileUri());

  $videoUri = $filesystem->saveData('not-video', $directory . '/clip-' . $token . '.mp4', FileExists::Replace);
  $video = File::create([
    'uri' => $videoUri,
    'filename' => 'clip-' . $token . '.mp4',
    'filemime' => 'video/mp4',
    'status' => FileInterface::STATUS_PERMANENT,
    'uid' => 1,
  ]);
  $video->save();
  $fids[] = (int) $video->id();
  $check('video skipped', $repository->latestForFid((int) $video->id()) === NULL, '');

  $originalSync = (int) $settings['sync_bytes'];
  $settingsBackup = $settings;
  $repository->saveSettings(['sync_bytes' => 200]);
  $queuedSource = $jpeg(90, 70, 95);
  $queuedUri = $filesystem->saveData((string) file_get_contents($queuedSource), $directory . '/queue-' . $token . '.jpg', FileExists::Replace);
  $queued = File::create([
    'uri' => $queuedUri,
    'filename' => 'queue-' . $token . '.jpg',
    'status' => FileInterface::STATUS_PERMANENT,
    'uid' => 1,
  ]);
  $queued->save();
  $fids[] = (int) $queued->id();
  $queuedRow = $repository->latestForFid((int) $queued->id());
  $check('large file queued', is_array($queuedRow) && $queuedRow['status'] === 'queued', (string) ($queuedRow['status'] ?? ''));
  $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('xedc_image_optimize');
  $handled = FALSE;
  if (is_array($queuedRow)) {
    $worker->processItem(['id' => (int) $queuedRow['id'], 'fid' => (int) $queued->id()]);
    $handled = TRUE;
  }
  $after = $repository->latestForFid((int) $queued->id());
  $check('queue worker', $handled && is_array($after) && $after['status'] === 'success', (string) ($after['status'] ?? 'missing'));
  $repository->saveSettings(['sync_bytes' => $originalSync]);
  $settingsBackup = NULL;

  $second = File::load((int) $direct->id());
  if ($second instanceof FileInterface) {
    $before = (string) $second->getFileUri();
    $second->setPermanent();
    $second->save();
    $third = File::load((int) $direct->id());
    $check('second save does not change uri', $third instanceof FileInterface && $third->getFileUri() === $before, $before);
  }

  $media = Media::create([
    'bundle' => 'image',
    'name' => 'opt ' . $token,
    'status' => 0,
    'uid' => 1,
    'field_media_image' => [
      'target_id' => (int) $direct->id(),
      'alt' => 'opt ' . $token,
    ],
  ]);
  $media->save();
  $mediaIds[] = (int) $media->id();
  $node = Node::create([
    'type' => 'photo',
    'title' => 'opt ' . $token,
    'uid' => 1,
    'status' => 0,
  ]);
  $node->set('field_images', ['target_id' => (int) $media->id()]);
  $repository->update((int) $row['id'], ['status' => 'failed', 'error' => '测试失败状态']);
  $node->setPublished();
  xedc_core_node_presave($node);
  $check('failed image blocks publish', !$node->isPublished(), $node->isPublished() ? 'published' : 'draft');
  $repository->update((int) $row['id'], ['status' => 'success', 'error' => '']);

  $form = \Drupal::formBuilder()->getForm(\Drupal\xedc_core\Form\ImageOptimizeForm::class);
  $check('admin form builds', ($form['#form_id'] ?? '') === 'xedc_image_optimize' && isset($form['summary'], $form['tasks']), (string) ($form['#form_id'] ?? ''));
}
catch (Throwable $error) {
  $check('unexpected exception', FALSE, $error->getMessage());
}
finally {
  if (is_array($settingsBackup)) {
    $repository->saveSettings(['sync_bytes' => (int) $settingsBackup['sync_bytes']]);
  }
  foreach ($mediaIds as $mid) {
    $media = Media::load($mid);
    if ($media) {
      $media->delete();
    }
  }
  $storage = \Drupal::entityTypeManager()->getStorage('file');
  foreach (array_unique($fids) as $fid) {
    $file = $storage->load($fid);
    if ($file instanceof FileInterface) {
      $uri = $file->getFileUri();
      if (str_contains($uri, $prefix)) {
        $file->delete();
      }
    }
  }
  foreach ($stylePaths as $path) {
    if ($path !== '' && is_file($path) && str_contains($path, $prefix)) {
      @unlink($path);
    }
  }
  $database = \Drupal::database();
  $database->delete('xedc_image_optimize')->condition('filename', '%' . $database->escapeLike($token) . '%', 'LIKE')->execute();
  $queue = \Drupal::queue('xedc_image_optimize');
  $release = [];
  for ($n = 0; $n < 30; $n++) {
    $item = $queue->claimItem(1);
    if (!is_object($item)) {
      break;
    }
    $fid = (int) ($item->data['fid'] ?? 0);
    if (in_array($fid, $fids, TRUE)) {
      $queue->deleteItem($item);
    }
    else {
      $release[] = $item;
    }
  }
  foreach ($release as $item) {
    $queue->releaseItem($item);
  }
  echo 'CLEANED token=' . $token . PHP_EOL;
}

$failed = 0;
foreach ($results as $result) {
  if ($result[0] === 'FAIL') {
    $failed++;
  }
}
echo 'SUMMARY pass=' . (count($results) - $failed) . ' fail=' . $failed . PHP_EOL;
if ($failed > 0) {
  throw new RuntimeException('Image optimization self-test failed.');
}
