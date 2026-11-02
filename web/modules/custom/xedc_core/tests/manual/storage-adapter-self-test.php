<?php

/**
 * @file
 * Checks the storage adapter without enabling runtime or touching production files.
 *
 * Run with: drush php:script web/modules/custom/xedc_core/tests/manual/storage-adapter-self-test.php
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;

$failed = 0;
$check = static function (string $label, bool $condition, string $detail = '') use (&$failed): void {
  echo ($condition ? 'OK  ' : 'FAIL ') . $label . ($detail !== '' ? ' :: ' . $detail : '') . PHP_EOL;
  if (!$condition) {
    $failed++;
  }
};

$temp = \Drupal::service('xedc_core.storage_temp');
$uris = \Drupal::service('xedc_core.storage_uris');
$guard = \Drupal::service('xedc_core.storage_guard');
$files = \Drupal::service('xedc_core.storage_files');
$reader = \Drupal::service('xedc_core.storage_reader');
$writer = \Drupal::service('xedc_core.storage_writer');
$pathGuard = \Drupal::service('xedc_core.file_path_guard');
$images = \Drupal::service('xedc_core.images');
$archive = \Drupal::service('xedc_core.archive');

$web = realpath(DRUPAL_ROOT);
$check('temp root is outside the web root', $temp->isOutsideWebRoot() && is_string($web) && !str_starts_with($temp->root(), $web . DIRECTORY_SEPARATOR), $temp->root());
$check('runtime stays off', Settings::get('xedc_storage.runtime_applied') !== TRUE && Settings::get('s3fs.use_s3_for_public') !== TRUE && Settings::get('s3fs.use_s3_for_private') !== TRUE);
$wrapper = \Drupal::service('stream_wrapper_manager')->getViaUri('public://');
$check('public stream stays local', $wrapper instanceof PublicStream);

$one = $temp->createFile('phase', '.txt');
$two = $temp->createFile('phase', '.txt');
$check('temp names are unpredictable', $one !== $two && preg_match('/phase-[a-f0-9]{32}\.txt$/', basename($one)) === 1);
$check('temp file is mode 0600', (fileperms($one) & 0777) === 0600 && !is_link($one));
file_put_contents($one, 'adapter');
$before = file_get_contents('/etc/passwd');
$check('traversal delete is refused', $temp->delete($temp->root() . '/../../etc/passwd') === FALSE && file_get_contents('/etc/passwd') === $before);
$check('temp root itself is not deleted', $temp->delete($temp->root()) === FALSE && is_dir($temp->root()));
$rejected = FALSE;
try {
  $temp->materializeUri('file:///etc/passwd');
}
catch (\RuntimeException) {
  $rejected = TRUE;
}
$check('file scheme is not materialized', $rejected && file_get_contents('/etc/passwd') === $before);
$secret = 'SUPERSECRETVALUE12';
$diag = $temp->recordDiagnostic('read', 'sig X-Amz-Signature=abcdef1234567890 key ' . $secret . ' https://cdn.example/a', [$secret]);
$diagText = (string) file_get_contents($diag);
$check('diagnostic redacts secrets', !str_contains($diagText, $secret) && !str_contains($diagText, 'abcdef1234567890') && !str_contains($diagText, 'https://'));

$check('s3 uri is remote', $uris->location('s3://private/a.jpg') === 'remote' && $pathGuard->kindForUri('s3://private/a.jpg') === 'remote');
$check('temporary uri is temporary', $uris->location('temporary://a.jpg') === 'temporary');
$check('local public uri is local', $uris->location('public://2026-11/a.jpg') === 'local' && $uris->isRemote('public://2026-11/a.jpg') === FALSE);
$check('remote uri skips realpath', $pathGuard->realpathIfLocal('s3://private/a.jpg') === FALSE);
$check('image style stays a derivative while public is local', $pathGuard->kindForUri('public://styles/thumb/public/a.png') === 'derivative');
$check('temp path is not a content file', $pathGuard->describe($one) === NULL && $pathGuard->containedKind($one) === 'temporary');
$check('remote cleanup is blocked', $guard->localCleanupBlockReason('s3://private/a.jpg') !== NULL);
$check('temporary cleanup is blocked', $guard->localCleanupBlockReason('temporary://a.jpg') !== NULL);
$localReason = $guard->localCleanupBlockReason('public://2026-11/a.jpg');
$check('ordinary local uri has no remote twin block', $localReason === NULL, (string) $localReason);
$check('object delete without a file entity is blocked', $guard->objectDeleteBlockReason('s3fs-public/a.jpg', 0) !== NULL);
$check('writer service is wired', is_object($writer) && is_object($reader));
$stored = $writer->putProcessed([], $one, '../nope', []);
$check('rejected write keeps the local source', ($stored['ok'] ?? TRUE) === FALSE && is_file($one) && file_get_contents($one) === 'adapter');
$outside = $reader->streamTo(['bucket' => 'nope', 'access_key' => '', 'secret_key' => ''], 'a.txt', '/etc/passwd');
$check('reader refuses a destination outside temp', $outside === FALSE && file_get_contents('/etc/passwd') === $before);
$check('image pipeline can materialize remote files', $images->remoteReady() === TRUE);
$check('archive manager can materialize remote archives', $archive->remoteReady() === TRUE);
$check('local bridge does not treat public as remote', $files->isRemoteUri('public://2026-11/a.jpg') === FALSE);

$sample = 'public://2026-11/IMG_2771.png';
$sampleReal = \Drupal::service('file_system')->realpath($sample);
$check('existing public file is still local', is_string($sampleReal) && is_file($sampleReal));

$temp->delete($one);
$temp->delete($two);
$temp->delete($diag);
foreach (scandir($temp->root()) ?: [] as $name) {
  if (is_string($name) && str_starts_with($name, 'diag-')) {
    $temp->delete($temp->root() . DIRECTORY_SEPARATOR . $name);
  }
}
$left = array_diff(scandir($temp->root()) ?: [], ['.', '..']);
$check('adapter temp files were removed', !is_file($one) && !is_file($two) && !is_file($diag) && $left === []);

if ($failed > 0) {
  echo $failed . " failed\n";
  exit(1);
}
echo "all passed\n";
