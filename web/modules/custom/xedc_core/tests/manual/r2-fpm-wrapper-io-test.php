<?php

/**
 * @file
 * CLI-only container compile and Drupal file API check.
 *
 * The environment variables for this process must be set by the caller.
 * This script never dumps the service container and never reloads PHP-FPM.
 * Objects are limited to public://xedc-r2-isolated-test/ and one local style
 * probe, then removed.
 */

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream;

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
  echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
  if (!$condition) {
    $failures++;
  }
};

$requested = Settings::get('s3fs.use_s3_for_public') === TRUE
  && Settings::get('xedc_storage.settings_accepted') === TRUE
  && Settings::get('xedc_storage.runtime_applied') === FALSE
  && Settings::get('s3fs.use_s3_for_private') !== TRUE
  && Settings::get('xedc_storage.cdn_enabled') !== TRUE;
$check('this cli process accepted settings without applying takeover or cdn', $requested);
if (!$requested) {
  echo "RESULT FAIL the caller did not isolate the settings\n";
  return;
}

$database = \Drupal::database();
$cacheBefore = $database->query('SELECT cid, checksum, created FROM {cache_container} ORDER BY cid')->fetchAll();
$fileBefore = $database->query('SELECT COUNT(*) FROM {file_managed}')->fetchField();
$kernel = \Drupal::service('kernel');
$reflection = new ReflectionObject($kernel);
$allow = $reflection->getProperty('allowDumping');
$needs = $reflection->getProperty('containerNeedsDumping');
$compile = $reflection->getMethod('compileContainer');
$allow->setValue($kernel, FALSE);
$needs->setValue($kernel, FALSE);
$builder = $compile->invoke($kernel);
$needs->setValue($kernel, FALSE);
$compiledClass = (string) $builder->getDefinition('stream_wrapper.public')->getClass();
$check('in-memory compile registers XedcPublicS3fsStream', $compiledClass === XedcPublicS3fsStream::class);
$check('in-memory compile did not request a cache dump', $needs->getValue($kernel) === FALSE);
unset($builder);

$manager = \Drupal::service('stream_wrapper_manager');
$manager->registerWrapper('public', XedcPublicS3fsStream::class, XedcPublicS3fsStream::getType());
$stamp = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
$dir = 'public://xedc-r2-isolated-test/' . $stamp;
$uri = $dir . '/pixel.png';
$renamed = $dir . '/pixel-renamed.png';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', TRUE);
$hash = hash('sha256', (string) $png);
$key = 's3fs-public/xedc-r2-isolated-test/' . $stamp . '/pixel-renamed.png';
$styleUri = 'public://styles/_xedc_r2_style_probe/pixel.png';
$fs = \Drupal::service('file_system');
$created = FALSE;
try {
  $dirReady = $fs->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  $check('drupal prepared the isolated directory', $dirReady === TRUE && is_dir($dir) && is_writable($dir));
  $saved = $dirReady ? $fs->saveData((string) $png, $uri, FileExists::Error) : FALSE;
  $created = $saved === $uri;
  $check('drupal file api created the test file', $created);
  $read = file_get_contents($uri);
  $check('drupal file api read matches sha-256', is_string($read) && hash_equals($hash, hash('sha256', $read)));
  $stat = stat($uri);
  $check('stat size matches', is_array($stat) && (int) $stat['size'] === strlen((string) $png));
  $mime = \Drupal::service('file.mime_type.guesser')->guessMimeType($uri);
  $check('mime is image/png', $mime === 'image/png');
  $listed = scandir($dir);
  $check('directory listing contains the file', is_array($listed) && in_array('pixel.png', $listed, TRUE));
  $moved = $fs->move($uri, $renamed, FileExists::Error);
  $check('drupal file api renamed the file', $moved === $renamed && !file_exists($uri) && file_exists($renamed));
  $styleDir = 'public://styles/_xedc_r2_style_probe';
  $prepared = $fs->prepareDirectory($styleDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  $styleSaved = $prepared ? $fs->saveData((string) $png, $styleUri, FileExists::Replace) : FALSE;
  $styleReal = is_string($styleSaved) ? $fs->realpath($styleSaved) : FALSE;
  $check('style write stayed on local disk', is_string($styleReal) && is_file($styleReal) && str_contains($styleReal, '/styles/_xedc_r2_style_probe/'));
  $wrapper = new XedcPublicS3fsStream();
  $wrapper->setUri($renamed);
  $external = $wrapper->getExternalUrl();
  $check('cdn switch off does not emit cdn or api host', str_contains($external, '/sites/default/files/xedc-r2-isolated-test/') && !str_contains($external, 'cdn.gitxx.xyz') && !str_contains($external, 'r2.cloudflarestorage.com'));
  $wrapper->setUri($styleUri);
  $styleExternal = $wrapper->getExternalUrl();
  $check('style external url stays local', str_contains($styleExternal, '/sites/default/files/styles/') && !str_contains($styleExternal, 's3fs-public'));
}
catch (Throwable $error) {
  echo 'FAIL file api ' . $error->getMessage() . PHP_EOL;
  $failures++;
}

$config = \Drupal::service('xedc_core.storage_config');
$resolved = \Drupal::service('xedc_core.storage_credentials')->resolve($config->profile('silly'));
$connection = $resolved['connection'];
unset($resolved);
$reader = \Drupal::service('xedc_core.storage_reader');
$head = NULL;
try {
  $head = $reader->head($connection, $key);
}
catch (Throwable $error) {
  echo 'FAIL head ' . \Drupal\xedc_core\Ops\Storage\BootGuard::safeError($error, [(string) ($connection['access_key'] ?? ''), (string) ($connection['secret_key'] ?? '')]) . PHP_EOL;
  $failures++;
}
$check('r2 object exists after drupal write', is_array($head) && (int) ($head['bytes'] ?? 0) === strlen((string) $png) && ($head['mime'] ?? '') === 'image/png');
$remote = is_array($head) ? $reader->hash($connection, $key) : NULL;
$check('r2 body sha-256 matches', is_string($remote) && hash_equals($hash, $remote));
$styleKey = 's3fs-public/styles/_xedc_r2_style_probe/pixel.png';
$styleHead = $reader->head($connection, $styleKey);
$check('style probe was not uploaded', $styleHead === NULL);

$deleted = is_string($renamed) && file_exists($renamed) ? $fs->delete($renamed) : FALSE;
$check('drupal file api deleted the test file', $deleted === TRUE && !file_exists($renamed));
if (is_dir($dir) || file_exists($dir)) {
  @$fs->deleteRecursive($dir);
}
$afterDelete = $reader->head($connection, $key);
$check('r2 object is gone after drupal delete', $afterDelete === NULL);
$prefix = 's3fs-public/xedc-r2-isolated-test/';
$client = \Drupal\xedc_core\Ops\Storage\S3ClientFactory::create($connection);
$left = $client->listObjectsV2(['Bucket' => 'dclili', 'Prefix' => $prefix]);
$names = [];
foreach ($left['Contents'] ?? [] as $object) {
  $names[] = (string) $object['Key'];
}
if ($names !== []) {
  foreach ($names as $name) {
    if (str_starts_with($name, $prefix)) {
      $client->deleteObject(['Bucket' => 'dclili', 'Key' => $name]);
    }
  }
  echo 'FAIL leftover keys removed by exact prefix cleanup ' . count($names) . PHP_EOL;
  $failures++;
}
else {
  echo "PASS no leftover objects under the test prefix\n";
}
$database->delete('s3fs_file')->condition('uri', 'public://xedc-r2-isolated-test%', 'LIKE')->execute();
$styleReal = $fs->realpath($styleUri);
if (is_string($styleReal) && is_file($styleReal)) {
  unlink($styleReal);
  $parent = dirname($styleReal);
  if (is_dir($parent) && str_ends_with($parent, '/_xedc_r2_style_probe')) {
    rmdir($parent);
  }
}
$database->delete('s3fs_file')->condition('uri', 'public://styles/_xedc_r2_style_probe%', 'LIKE')->execute();

$manager->registerWrapper('public', PublicStream::class, PublicStream::getType());
$cacheAfter = $database->query('SELECT cid, checksum, created FROM {cache_container} ORDER BY cid')->fetchAll();
$fileAfter = $database->query('SELECT COUNT(*) FROM {file_managed}')->fetchField();
$sameCache = count($cacheBefore) === count($cacheAfter);
if ($sameCache) {
  foreach ($cacheBefore as $index => $row) {
    $other = $cacheAfter[$index];
    if ($row->cid !== $other->cid || $row->checksum !== $other->checksum || (string) $row->created !== (string) $other->created) {
      $sameCache = FALSE;
    }
  }
}
$check('production container cache was not rewritten', $sameCache);
$check('file_managed count unchanged', (string) $fileBefore === (string) $fileAfter);
$check('process wrapper restored to PublicStream', stream_get_wrappers() && in_array('public', stream_get_wrappers(), TRUE));
echo 'COMPILED ' . $compiledClass . PHP_EOL;
echo 'RESULT ' . ($failures === 0 ? 'PASS' : 'FAIL') . ' failures=' . $failures . PHP_EOL;
