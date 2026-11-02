<?php

/**
 * @file
 * Real R2 checks under _xedc_integration_test/. Does not enable takeover.
 *
 * Run with: drush php:script web/modules/custom/xedc_core/tests/manual/r2-integration-self-test.php
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\user\Entity\User;
use Drupal\xedc_core\Ops\Storage\BootGuard;

$failed = 0;
$check = static function (string $label, bool $condition, string $detail = '') use (&$failed): void {
  echo ($condition ? 'OK  ' : 'FAIL ') . $label . ($detail !== '' ? ' :: ' . $detail : '') . PHP_EOL;
  if (!$condition) {
    $failed++;
  }
};

$check('mime token keeps video/mp4', BootGuard::contentType('video/mp4') === 'video/mp4');
$check('mime token rejects junk', BootGuard::contentType('not a mime') === 'application/octet-stream');
$check('runtime stays off before the run', Settings::get('xedc_storage.runtime_applied') !== TRUE && Settings::get('s3fs.use_s3_for_public') !== TRUE && Settings::get('s3fs.use_s3_for_private') !== TRUE);
$wrapper = \Drupal::service('stream_wrapper_manager')->getViaUri('public://');
$check('public stream stays local', $wrapper instanceof PublicStream);

$readiness = \Drupal::service('xedc_core.storage_readiness')->report();
$census = is_array($readiness['census'] ?? NULL) ? $readiness['census'] : [];
echo 'CENSUS files=' . (int) ($census['files'] ?? 0)
  . ' public=' . (int) ($census['public_files'] ?? 0)
  . ' private=' . (int) ($census['private_files'] ?? 0)
  . ' s3=' . (int) ($census['s3_files'] ?? 0)
  . ' images=' . (int) ($census['image_files'] ?? 0)
  . ' videos=' . (int) ($census['video_files'] ?? 0)
  . PHP_EOL;
$check('active profile is silly', ($readiness['active_profile'] ?? '') === 'silly');
$check('takeover is not marked ready', ($readiness['public_ready'] ?? TRUE) === FALSE && ($readiness['private_ready'] ?? TRUE) === FALSE);

$local = \Drupal::service('xedc_core.storage_delivery')->forUri('public://2026-11/a.jpg');
$check('local public url is not rewritten to cdn', ($local['cdn'] ?? TRUE) === FALSE && ($local['kind'] ?? '') === 'local');
$private = \Drupal::service('xedc_core.storage_delivery')->forUri('private://secret/a.jpg', 1);
$check('private uri is not a permanent cdn url', ($private['cdn'] ?? TRUE) === FALSE && ($private['permanent'] ?? TRUE) === FALSE);

$before = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {file_managed}')->fetchField();
$report = \Drupal::service('xedc_core.storage_media_pipeline')->run();
$after = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {file_managed}')->fetchField();
$check('pipeline did not create file entities', $before === $after, $before . ' -> ' . $after);

$steps = is_array($report['steps'] ?? NULL) ? $report['steps'] : [];
foreach (['jpeg', 'png', 'webp', 'derived_webp', 'style_thumbnail', 'style_photo_gallery_650', 'video', 'zip'] as $name) {
  $step = is_array($steps[$name] ?? NULL) ? $steps[$name] : [];
  $check('r2 ' . $name, !empty($step['ok']), (string) ($step['detail'] ?? $report['error'] ?? ''));
}
if (isset($steps['avif_encoder']) && empty($steps['avif']['ok'])) {
  echo 'INFO avif encoder unavailable: ' . (string) ($steps['avif_encoder']['detail'] ?? '') . PHP_EOL;
}
elseif (isset($steps['avif'])) {
  $check('r2 avif', !empty($steps['avif']['ok']), (string) ($steps['avif']['detail'] ?? ''));
}
$cdn = is_array($steps['cdn'] ?? NULL) ? $steps['cdn'] : [];
echo 'CDN host=' . (string) ($cdn['host'] ?? '') . ' code=' . (int) ($cdn['code'] ?? 0) . ' type=' . (string) ($cdn['type'] ?? '') . ' ' . (string) ($cdn['detail'] ?? '') . PHP_EOL;
$video = is_array($steps['video'] ?? NULL) ? $steps['video'] : [];
echo 'VIDEO http=' . (int) ($video['http_code'] ?? 0) . ' type=' . (string) ($video['http_type'] ?? '') . ' stored=' . (string) ($video['mime'] ?? '') . ' ranges=' . (string) ($video['http_ranges'] ?? '') . PHP_EOL;

$cleanup = is_array($report['cleanup'] ?? NULL) ? $report['cleanup'] : [];
$leftover = 0;
foreach ($cleanup as $key => $gone) {
  if ($gone !== TRUE) {
    $leftover++;
    echo 'FAIL cleanup ' . $key . PHP_EOL;
  }
  $check('cleanup key stays in integration prefix', str_starts_with((string) $key, '_xedc_integration_test/'));
}
$check('integration objects deleted', $leftover === 0 && $cleanup !== []);
$prefixes = is_array($report['prefix_counts'] ?? NULL) ? $report['prefix_counts'] : [];
$check('integration prefix is empty after cleanup', ($prefixes['integration'] ?? -1) === 0, (string) ($prefixes['integration'] ?? 'missing'));
echo 'BUCKET objects seen after cleanup=' . (is_scalar($report['bucket_objects_seen'] ?? NULL) ? (string) $report['bucket_objects_seen'] : 'unknown')
  . ' storage_test_prefix=' . (is_scalar($prefixes['storage_test'] ?? NULL) ? (string) $prefixes['storage_test'] : 'unknown')
  . PHP_EOL;

$check('runtime stays off after the run', Settings::get('xedc_storage.runtime_applied') !== TRUE && Settings::get('s3fs.use_s3_for_public') !== TRUE);

$fid = (int) \Drupal::database()->query("SELECT fid FROM {file_managed} WHERE uri LIKE 'private://%' ORDER BY fid DESC LIMIT 1")->fetchField();
if ($fid > 0) {
  $account = User::load(1);
  if ($account) {
    $decision = \Drupal::service('xedc_core.storage_download')->authorize($fid, $account);
    $check('local private file is not signed', ($decision['status'] ?? 0) !== 302 && ($decision['url'] ?? 'x') === '');
  }
}

if (($report['error'] ?? '') !== '') {
  echo 'ERROR ' . $report['error'] . PHP_EOL;
}

if ($failed > 0) {
  echo $failed . " failed\n";
  exit(1);
}
echo "all passed\n";
