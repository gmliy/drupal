<?php

/**
 * @file
 * Read-only production census, unknown-object inspection, and migration inventory.
 *
 * Does not copy files, change URIs, delete sources, or enable takeover.
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/r2-acceptance-precheck.php
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
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

$check('runtime stays off', Settings::get('xedc_storage.runtime_applied') !== TRUE && Settings::get('s3fs.use_s3_for_public') !== TRUE && Settings::get('s3fs.use_s3_for_private') !== TRUE);
$check('public stream stays local', \Drupal::service('stream_wrapper_manager')->getViaUri('public://') instanceof PublicStream);
$check('cdn rewrite stays off', \Drupal::service('xedc_core.storage_uris')->rewriteEnabled() === FALSE);

$readiness = \Drupal::service('xedc_core.storage_readiness')->report();
$census = is_array($readiness['census'] ?? NULL) ? $readiness['census'] : [];
echo 'CENSUS files=' . (int) ($census['files'] ?? 0)
  . ' public=' . (int) ($census['public_files'] ?? 0)
  . ' private=' . (int) ($census['private_files'] ?? 0)
  . ' s3=' . (int) ($census['s3_files'] ?? 0)
  . ' images=' . (int) ($census['image_files'] ?? 0)
  . ' videos=' . (int) ($census['video_files'] ?? 0)
  . ' other=' . (int) ($census['other_files'] ?? 0)
  . ' bytes=' . (int) ($census['bytes'] ?? 0)
  . PHP_EOL;
$check('active profile is silly', ($readiness['active_profile'] ?? '') === 'silly' && ($readiness['bucket'] ?? '') === 'dclili');

$before = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {file_managed}')->fetchField();
$keys = [];
$rows = \Drupal::database()->query('SELECT fid, uri, filesize FROM {file_managed}');
$disk = ['public_present' => 0, 'public_missing' => 0, 'private_present' => 0, 'private_missing' => 0];
foreach ($rows as $row) {
  $uri = (string) $row->uri;
  $key = \Drupal::service('xedc_core.storage_uris')->objectKey($uri);
  if (is_string($key) && $key !== '') {
    $keys[$key] = (int) $row->fid;
  }
  $real = \Drupal::service('file_system')->realpath($uri);
  $exists = is_string($real) && is_file($real) && !is_link($real);
  if (str_starts_with($uri, 'public://')) {
    $disk[$exists ? 'public_present' : 'public_missing']++;
  }
  elseif (str_starts_with($uri, 'private://')) {
    $disk[$exists ? 'private_present' : 'private_missing']++;
  }
}
echo 'DISK public_present=' . $disk['public_present'] . ' public_missing=' . $disk['public_missing']
  . ' private_present=' . $disk['private_present'] . ' private_missing=' . $disk['private_missing'] . PHP_EOL;

$profile = \Drupal::service('xedc_core.storage_config')->profile('silly');
$resolved = \Drupal::service('xedc_core.storage_credentials')->resolve($profile);
$check('credentials resolve', !empty($resolved['ok']) && is_array($resolved['connection'] ?? NULL));
if (empty($resolved['ok']) || !is_array($resolved['connection'] ?? NULL)) {
  echo $failed . " failed\n";
  exit(1);
}
$connection = $resolved['connection'];
$secrets = [(string) ($connection['access_key'] ?? ''), (string) ($connection['secret_key'] ?? '')];
$client = S3ClientFactory::create($connection);
$objects = [];
$token = NULL;
do {
  $params = ['Bucket' => (string) $connection['bucket'], 'MaxKeys' => 1000];
  if (is_string($token) && $token !== '') {
    $params['ContinuationToken'] = $token;
  }
  $listed = $client->listObjectsV2($params);
  foreach ($listed['Contents'] ?? [] as $object) {
    $objects[] = $object;
  }
  $token = !empty($listed['IsTruncated']) ? ($listed['NextContinuationToken'] ?? NULL) : NULL;
} while (is_string($token) && $token !== '' && count($objects) < 5000);
echo 'BUCKET objects=' . count($objects) . PHP_EOL;

$unknown = 0;
foreach ($objects as $object) {
  $key = (string) ($object['Key'] ?? '');
  $test = str_starts_with($key, StorageUriResolver::INTEGRATION_PREFIX) || str_starts_with($key, BootGuard::TEST_PREFIX);
  if ($test) {
    echo 'TEST_OBJECT key=' . $key . ' bytes=' . (int) ($object['Size'] ?? 0) . PHP_EOL;
    continue;
  }
  $unknown++;
  $head = $client->headObject(['Bucket' => (string) $connection['bucket'], 'Key' => $key]);
  $meta = $head['Metadata'] ?? [];
  $meta = is_array($meta) ? implode(',', array_keys($meta)) : '';
  $fid = $keys[$key] ?? 0;
  $modified = $object['LastModified'] ?? '';
  $modified = is_object($modified) && method_exists($modified, 'format') ? $modified->format('c') : (string) $modified;
  echo 'UNKNOWN key=' . $key
    . ' bytes=' . (int) ($object['Size'] ?? 0)
    . ' type=' . (string) ($head['ContentType'] ?? '')
    . ' modified=' . $modified
    . ' etag=' . trim((string) ($object['ETag'] ?? ''), '"')
    . ' metadata_keys=' . ($meta === '' ? 'none' : $meta)
    . ' file_fid=' . $fid
    . PHP_EOL;
  $local = 'no-local-name-match';
  if ($fid > 0) {
    $uri = (string) \Drupal::database()->query('SELECT uri FROM {file_managed} WHERE fid = :fid', [':fid' => $fid])->fetchField();
    $real = \Drupal::service('file_system')->realpath($uri);
    $local = is_string($real) && is_file($real) ? 'local-file-present' : 'local-file-missing';
  }
  echo 'UNKNOWN_MATCH ' . $local . PHP_EOL;
}
$check('unknown objects were only inspected', $unknown >= 0);

$planner = \Drupal::service('xedc_core.storage_migration');
$executor = \Drupal::service('xedc_core.storage_migration_executor');
$reportDir = '/var/www/drupal/private/xedc-storage-reports';
if (!is_dir($reportDir) && !mkdir($reportDir, 0700, TRUE) && !is_dir($reportDir)) {
  $check('report directory', FALSE);
}
else {
  chmod($reportDir, 0700);
}
foreach (['public', 'private'] as $source) {
  $task = $planner->precheck($source, 'silly');
  $check($source . ' precheck', ($task['status'] ?? '') === 'prechecked', (string) ($task['message'] ?? ''));
  if (($task['status'] ?? '') !== 'prechecked') {
    continue;
  }
  $listed = $executor->inventory((string) $task['id']);
  $check($source . ' inventory', ($listed['status'] ?? '') === 'inventoried', (string) ($listed['message'] ?? ''));
  $probed = $executor->probeTargets((string) $task['id']);
  $remote = is_array($probed['remote'] ?? NULL) ? $probed['remote'] : [];
  echo strtoupper($source) . ' files=' . (int) (($listed['counts']['files'] ?? 0))
    . ' bytes=' . (int) (($listed['counts']['bytes'] ?? 0))
    . ' missing=' . (int) ($listed['missing'] ?? 0)
    . ' duplicate_groups=' . (int) ($listed['duplicate_groups'] ?? 0)
    . ' kinds=' . json_encode($listed['kinds'] ?? [])
    . ' remote_absent=' . (int) ($remote['absent'] ?? 0)
    . ' remote_match=' . (int) ($remote['match'] ?? 0)
    . ' remote_differ=' . (int) ($remote['differ'] ?? 0)
    . PHP_EOL;
  $check($source . ' probe did not copy', (int) ($probed['copied'] ?? 0) === 0 && empty($probed['uri_rewritten']) && !empty($probed['source_retained']));
  $refused = $executor->copyBatch((string) $task['id'], 1, FALSE);
  $check($source . ' copy without confirmation is blocked', ($refused['status'] ?? '') === 'blocked');
  $rollback = $executor->rollback((string) $task['id'], FALSE);
  $check($source . ' rollback without confirmation is blocked', ($rollback['status'] ?? '') === 'blocked');
  $paused = $executor->pause((string) $task['id']);
  $check($source . ' pause', ($paused['status'] ?? '') === 'paused' && empty($paused['uri_rewritten']));
  $planner->updateTask((string) $task['id'], [
    'status' => 'probed',
    'message' => '暂停和继续已验证后恢复为已检查。没有复制，没有修改 URI。',
    'copied' => 0,
    'verified' => FALSE,
    'source_retained' => TRUE,
    'uri_rewritten' => FALSE,
    'copy_enabled' => FALSE,
  ]);
  $path = $reportDir . '/migration-' . $source . '-' . $task['id'] . '.csv';
  $written = file_put_contents($path, $executor->report((string) $task['id']));
  if (is_string($path) && is_file($path)) {
    chmod($path, 0600);
  }
  $check($source . ' manifest written', $written !== FALSE, $path);
}

$after = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {file_managed}')->fetchField();
$check('file entities unchanged', $before === $after, $before . ' -> ' . $after);
$check('runtime still off', Settings::get('xedc_storage.runtime_applied') !== TRUE && Settings::get('s3fs.use_s3_for_public') !== TRUE);

if ($failed > 0) {
  echo $failed . " failed\n";
  exit(1);
}
echo "all passed\n";
