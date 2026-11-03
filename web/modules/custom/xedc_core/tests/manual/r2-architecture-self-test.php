<?php

/**
 * @file
 * Isolated checks for stream-wrapper status and CDN URLs.
 *
 * Does not enable takeover, reload PHP-FPM, or touch the 128 migrated objects
 * except with HeadObject and a few ranged reads. New objects stay under the
 * test prefix and are deleted before the script finishes.
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\xedc_core\Ops\Storage\BootGuard;
use Drupal\xedc_core\Ops\Storage\CachePolicy;
use Drupal\xedc_core\Ops\Storage\CdnUrlPolicy;
use Drupal\xedc_core\Ops\Storage\PublicUrl;
use Drupal\xedc_core\Ops\Storage\RuntimeStatus;
use Drupal\xedc_core\Ops\Storage\WrapperStatus;
use Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream;
use Drupal\xedc_core\XedcCoreServiceProvider;
use Drupal\Core\DependencyInjection\ContainerBuilder;

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
  echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
  if (!$condition) {
    $failures++;
  }
};

if (Settings::get('s3fs.use_s3_for_public') === TRUE || Settings::get('s3fs.use_s3_for_private') === TRUE || Settings::get('xedc_storage.cdn_enabled') === TRUE) {
  echo "FAIL production switch is on; isolated test stopped\n";
  return;
}

$observed = RuntimeStatus::observe();
$check('public wrapper is the local class', $observed['active_stream_wrapper_class'] === PublicStream::class);
$check('private wrapper is local', str_ends_with((string) $observed['private_stream_wrapper_class'], 'PrivateStream'));
$check('takeover is not reported from an absent environment', $observed['runtime_applied'] === FALSE && $observed['public_takeover_requested'] === FALSE);
$check('write destination stays unverified', $observed['actual_write_destination'] === 'unverified');
$check('current generated URL is local', $observed['generated_public_url'] === 'local');
$check('cdn rewrite is off', $observed['cdn_rewrite_active'] === FALSE);

$builder = new ContainerBuilder();
$builder->register('stream_wrapper.public', PublicStream::class);
(new XedcCoreServiceProvider())->alter($builder);
$check('compiled container is not swapped while takeover is off', $builder->getDefinition('stream_wrapper.public')->getClass() === PublicStream::class);
$check('takeover compile target is the xedc wrapper', XedcCoreServiceProvider::publicWrapperClass(TRUE) === XedcPublicS3fsStream::class);
$check('compile target stays the stable xedc wrapper', XedcCoreServiceProvider::publicWrapperClass(FALSE) === XedcPublicS3fsStream::class);

$cdn = PublicUrl::cdnUrl('public://test/example.jpg', CdnUrlPolicy::PRODUCTION_BASE, 's3fs-public');
$check('public url uses the cdn host and object prefix', $cdn === 'https://cdn.gitxx.xyz/s3fs-public/test/example.jpg');
$check('public url has no api host', $cdn !== NULL && !str_contains($cdn, 'r2.cloudflarestorage.com') && !str_contains($cdn, '/dclili/'));
$check('prefix is not doubled', PublicUrl::cdnUrl('public://s3fs-public/test/example.jpg', CdnUrlPolicy::PRODUCTION_BASE, 's3fs-public') === 'https://cdn.gitxx.xyz/s3fs-public/test/example.jpg');
$nested = PublicUrl::cdnUrl('public://2026-11/n195-001.jpg', CdnUrlPolicy::PRODUCTION_BASE, 's3fs-public');
$check('subdirectory is kept', $nested === 'https://cdn.gitxx.xyz/s3fs-public/2026-11/n195-001.jpg');
$encoded = PublicUrl::cdnUrl('public://2026-10/a b.jpg', CdnUrlPolicy::PRODUCTION_BASE, 's3fs-public');
$check('space is encoded once', $encoded === 'https://cdn.gitxx.xyz/s3fs-public/2026-10/a%20b.jpg');
$check('api base is rejected', PublicUrl::cdnUrl('public://test/example.jpg', 'https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com', 's3fs-public') === NULL);
$style = PublicUrl::localStyleUrl('public://styles/max_325x325/public/pictures/IMG_2664.jpeg.avif');
$check('style url stays on the site files path', is_string($style) && str_contains($style, '/sites/default/files/styles/') && !str_contains($style, 'cdn.gitxx.xyz') && !str_contains($style, 'r2.cloudflarestorage.com'));
$check('style is not a cdn object url', PublicUrl::cdnUrl('public://styles/max_325x325/public/a.jpg', CdnUrlPolicy::PRODUCTION_BASE, 's3fs-public') === NULL);
$check('private uri is not rewritten', PublicUrl::alter('private://member-2707556.zip') === NULL);
$check('local public uri is not rewritten while wrapper is local', PublicUrl::alter('public://test/example.jpg') === NULL);
$cdnOff = PublicUrl::delivery('public://test/example.jpg', TRUE, FALSE, CdnUrlPolicy::PRODUCTION_BASE, 's3fs-public');
$check('cdn switch off keeps a remote original on the site url', $cdnOff['kind'] === 'local' && is_string($cdnOff['url']) && str_contains($cdnOff['url'], '/sites/default/files/test/example.jpg') && !str_contains($cdnOff['url'], 'cdn.gitxx.xyz'));
$cdnOn = PublicUrl::delivery('public://test/example.jpg', TRUE, TRUE, CdnUrlPolicy::PRODUCTION_BASE, 's3fs-public');
$check('cdn switch on rewrites only when explicitly enabled', $cdnOn['kind'] === 'cdn' && $cdnOn['url'] === 'https://cdn.gitxx.xyz/s3fs-public/test/example.jpg');
$styleOn = PublicUrl::delivery('public://styles/max_325x325/public/a.webp', TRUE, TRUE, CdnUrlPolicy::PRODUCTION_BASE, 's3fs-public');
$check('style stays local even when cdn rewrite is on', $styleOn['kind'] === 'local' && is_string($styleOn['url']) && str_contains($styleOn['url'], '/styles/') && !str_contains($styleOn['url'], 's3fs-public'));
$check('cdn switch is off in this process', PublicUrl::enabled() === FALSE);
$live = \Drupal::service('file_url_generator')->generateAbsoluteString('public://2026-10/IMG_3009.jpeg');
$check('live file url stays local', str_contains($live, '/sites/default/files/2026-10/IMG_3009.jpeg') && !str_contains($live, 'cdn.gitxx.xyz'));

$probeDir = 'public://styles/_xedc_architecture_probe';
$probe = $probeDir . '/pixel.png';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', TRUE);
$fs = \Drupal::service('file_system');
$prepared = $fs->prepareDirectory($probeDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
$written = $prepared ? $fs->saveData($png, $probe, \Drupal\Core\File\FileExists::Replace) : FALSE;
$real = is_string($written) ? \Drupal::service('file_system')->realpath($written) : FALSE;
$check('style probe is a local file', is_string($real) && is_file($real) && str_contains($real, '/styles/_xedc_architecture_probe/'));
if (is_string($real) && is_file($real)) {
  unlink($real);
  $parent = dirname($real);
  if (is_dir($parent) && str_ends_with($parent, '/_xedc_architecture_probe')) {
    rmdir($parent);
  }
}
$check('style probe removed', !is_string($real) || !is_file($real));

$styleEntity = \Drupal::entityTypeManager()->getStorage('image_style')->load('max_325x325');
foreach (['avif', 'webp'] as $extension) {
  $dest = 'temporary://xedc-arch-style-' . bin2hex(random_bytes(3)) . '.' . $extension;
  $made = $styleEntity && $styleEntity->createDerivative('public://pictures/IMG_2664.jpeg', $dest);
  $styleReal = $made ? \Drupal::service('file_system')->realpath($dest) : FALSE;
  $check('temporary ' . $extension . ' derivative from a real source', is_string($styleReal) && is_file($styleReal) && filesize($styleReal) > 0);
  if (is_string($styleReal) && is_file($styleReal)) {
    unlink($styleReal);
  }
}

$bucket = RuntimeStatus::bucketProbe();
$check('head bucket verifies api credentials', ($bucket['status'] ?? '') === 'verified');

$config = \Drupal::service('xedc_core.storage_config');
$profile = $config->profile('silly');
$resolved = \Drupal::service('xedc_core.storage_credentials')->resolve($profile);
$connection = $resolved['connection'];
unset($resolved);
$writer = \Drupal::service('xedc_core.storage_writer');
$reader = \Drupal::service('xedc_core.storage_reader');
$stamp = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
$imageKey = 's3fs-public/_xedc_storage_test/architecture-' . $stamp . '.png';
$videoKey = 's3fs-public/_xedc_storage_test/architecture-' . $stamp . '.mp4';
$privateKey = 's3fs-private/_xedc_storage_test/architecture-' . $stamp . '.txt';
$imagePath = tempnam(sys_get_temp_dir(), 'xedc-png');
file_put_contents($imagePath, $png);
$imageHash = hash('sha256', $png);
$put = $writer->putFile($connection, $imagePath, $imageKey, [
  'mime' => 'image/png',
  'purpose' => 'test',
  'overwrite' => FALSE,
  'cache_control' => CachePolicy::forObject($imageKey, FALSE),
]);
$remoteHash = $reader->hash($connection, $imageKey);
$check('isolated png upload hash matches', !empty($put['ok']) && is_string($remoteHash) && hash_equals($imageHash, $remoteHash));
$head = $reader->head($connection, $imageKey);
$check('isolated png type and cache', is_array($head) && ($head['mime'] ?? '') === 'image/png' && ($head['cache_control'] ?? '') === 'public, max-age=3600' && (int) ($head['bytes'] ?? 0) === strlen($png));

$videoPath = sys_get_temp_dir() . '/xedc-arch-' . bin2hex(random_bytes(3)) . '.mp4';
$ffmpeg = shell_exec('ffmpeg -y -f lavfi -i testsrc=size=160x120:rate=10:duration=1 -pix_fmt yuv420p -an ' . escapeshellarg($videoPath) . ' 2>&1');
$videoOk = is_file($videoPath) && filesize($videoPath) > 100;
$check('generated independent mp4', $videoOk);
if ($videoOk) {
  $videoPut = $writer->putFile($connection, $videoPath, $videoKey, [
    'mime' => 'video/mp4',
    'purpose' => 'test',
    'overwrite' => FALSE,
    'cache_control' => 'public, max-age=60',
  ]);
  $check('isolated mp4 upload', !empty($videoPut['ok']));
  $videoUrl = 'https://cdn.gitxx.xyz/' . implode('/', array_map('rawurlencode', explode('/', $videoKey)));
  $ch = curl_init($videoUrl);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_HEADER => TRUE, CURLOPT_HTTPHEADER => ['Range: bytes=0-15'], CURLOPT_TIMEOUT => 30]);
  $raw = curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);
  $raw = is_string($raw) ? $raw : '';
  $range = '';
  foreach (preg_split("/\r\n|\n|\r/", substr($raw, 0, $headerSize)) ?: [] as $line) {
    if (stripos($line, 'content-range:') === 0) {
      $range = trim(substr($line, 14));
    }
  }
  $localHead = file_get_contents($videoPath, FALSE, NULL, 0, 16);
  $check('isolated mp4 range is 206', $status === 206 && str_starts_with($range, 'bytes 0-15/') && $localHead === substr($raw, $headerSize, 16));
}

$marker = 'ARCH-' . bin2hex(random_bytes(4));
$privatePath = tempnam(sys_get_temp_dir(), 'xedc-private');
file_put_contents($privatePath, $marker);
$privatePut = $writer->putFile($connection, $privatePath, $privateKey, [
  'mime' => 'text/plain',
  'purpose' => 'test',
  'overwrite' => FALSE,
  'cache_control' => CachePolicy::PRIVATE,
]);
$check('isolated private object uploaded', !empty($privatePut['ok']));
$ch = curl_init('https://cdn.gitxx.xyz/' . $privateKey);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_TIMEOUT => 30]);
$privateBody = curl_exec($ch);
$privateStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$check('private test prefix is not anonymously readable', $privateStatus === 403 && is_string($privateBody) && !str_contains($privateBody, $marker));

$created = [$imageKey];
if ($videoOk && !empty($videoPut['ok'])) {
  $created[] = $videoKey;
}
if (!empty($privatePut['ok'])) {
  $created[] = $privateKey;
}
$deleted = TRUE;
foreach ($created as $createdKey) {
  $deleted = $writer->delete($connection, $createdKey, 'test') && $deleted;
}
$check('only this run test objects were deleted', $deleted);
$check('deleted image key is gone', $reader->head($connection, $imageKey) === NULL);
@unlink($imagePath);
@unlink($videoPath);
@unlink($privatePath);

$manifest = dirname(__DIR__, 6) . '/docs/r2-public-migration-manifest.csv';
$handle = fopen($manifest, 'rb');
$keys = [];
if ($handle) {
  fgetcsv($handle);
  while (($row = fgetcsv($handle)) !== FALSE) {
    if (($row[5] ?? '') !== '' && ($row[6] ?? '') !== '') {
      $keys[] = ['sha' => $row[5], 'key' => $row[6], 'bytes' => (int) $row[3]];
    }
  }
  fclose($handle);
}
$check('manifest has 128 keys', count($keys) === 128);
$present = 0;
$metaOk = 0;
foreach ($keys as $row) {
  $meta = $reader->head($connection, $row['key']);
  if (!is_array($meta)) {
    echo 'FAIL missing ' . $row['key'] . PHP_EOL;
    $failures++;
    continue;
  }
  $present++;
  $cache = (string) ($meta['cache_control'] ?? $meta['CacheControl'] ?? '');
  $bytes = (int) ($meta['bytes'] ?? $meta['content_length'] ?? $meta['ContentLength'] ?? -1);
  if ($bytes === $row['bytes'] && $cache === 'public, max-age=3600') {
    $metaOk++;
  }
}
$check('all 128 objects still exist', $present === 128);
$check('all 128 keep size and cache-control', $metaOk === 128);
$sampleOk = 0;
foreach (array_slice($keys, 0, 3) as $row) {
  $hash = $reader->hash($connection, $row['key']);
  if (is_string($hash) && hash_equals($row['sha'], $hash)) {
    $sampleOk++;
  }
}
$check('three manifest samples match sha-256', $sampleOk === 3);

$builder = \Drupal::service('xedc_core.engagement_builder');
$reflection = new ReflectionMethod($builder, '__construct');
$check('engagement builder can be constructed', $builder instanceof \Drupal\xedc_core\Engagement\EngagementBuilder);
$check('guest argument is optional for the stale container', $reflection->getNumberOfRequiredParameters() === 5);

echo 'RESULT ' . ($failures === 0 ? 'PASS' : 'FAIL') . ' failures=' . $failures . PHP_EOL;
return;
