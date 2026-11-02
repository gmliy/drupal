<?php

/**
 * @file
 * Read-only migration precheck. It does not copy, delete, or rewrite URIs.
 *
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/cdn-migration-precheck.php
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\xedc_core\Ops\Storage\S3ClientFactory;

$line = static function (string $label, string $value = ''): void {
  echo $label . ($value !== '' ? ' :: ' . $value : '') . PHP_EOL;
};

if (Settings::get('xedc_storage.runtime_applied') === TRUE || Settings::get('s3fs.use_s3_for_public') === TRUE || Settings::get('s3fs.use_s3_for_private') === TRUE || Settings::get('xedc_storage.cdn_enabled') === TRUE) {
  $line('FAIL production switch is on; precheck was not started');
  return;
}

$database = \Drupal::database();
$fingerprint = static function () use ($database): array {
  $row = $database->query('SELECT COUNT(*) AS c, COALESCE(SUM(fid), 0) AS f, COALESCE(SUM(CRC32(uri)), 0) AS u FROM {file_managed}')->fetchAssoc();
  return [
    'count' => (int) ($row['c'] ?? 0),
    'fids' => (string) ($row['f'] ?? '0'),
    'uris' => (string) ($row['u'] ?? '0'),
  ];
};
$before = $fingerprint();
$active = (string) \Drupal::service('xedc_core.storage_config')->activeId();
$task = \Drupal::service('xedc_core.storage_migration')->precheck('local', $active);
$taskId = (string) ($task['id'] ?? '');
if ($taskId === '' || ($task['status'] ?? '') === 'blocked') {
  $line('FAIL precheck blocked', (string) ($task['message'] ?? ''));
  return;
}
$inventory = \Drupal::service('xedc_core.storage_migration_executor')->inventory($taskId);
if (($inventory['status'] ?? '') !== 'inventoried') {
  $line('FAIL inventory', (string) ($inventory['message'] ?? ''));
  return;
}
$probed = \Drupal::service('xedc_core.storage_migration_executor')->probeTargets($taskId);
$after = $fingerprint();
if ($before !== $after) {
  $line('FAIL file entities changed during the scan');
  return;
}

$rows = $database->query('SELECT scheme, status, COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS bytes, SUM(CASE WHEN sha256 = :empty AND bytes > 33554432 THEN 1 ELSE 0 END) AS deferred FROM {xedc_storage_migration_item} WHERE task_id = :task GROUP BY scheme, status', [
  ':task' => $taskId,
  ':empty' => '',
])->fetchAll();
$summary = [
  'public' => 0,
  'private' => 0,
  'public_bytes' => 0,
  'private_bytes' => 0,
  'ready_to_copy' => 0,
  'ready_bytes' => 0,
  'missing_local' => 0,
  'remote_match' => 0,
  'checksum_failed' => 0,
  'hash_deferred' => 0,
  'other' => 0,
];
foreach ($rows as $row) {
  $count = (int) $row->n;
  $bytes = (int) $row->bytes;
  $scheme = (string) $row->scheme;
  $status = (string) $row->status;
  if ($scheme === 'public') {
    $summary['public'] += $count;
    $summary['public_bytes'] += $bytes;
  }
  elseif ($scheme === 'private') {
    $summary['private'] += $count;
    $summary['private_bytes'] += $bytes;
  }
  else {
    $summary['other'] += $count;
  }
  if ($status === 'pending') {
    $summary['ready_to_copy'] += $count;
    $summary['ready_bytes'] += $bytes;
  }
  elseif ($status === 'missing') {
    $summary['missing_local'] += $count;
  }
  elseif ($status === 'remote_match') {
    $summary['remote_match'] += $count;
  }
  elseif ($status === 'remote_differ') {
    $summary['checksum_failed'] += $count;
  }
  $summary['hash_deferred'] += (int) $row->deferred;
}

$styles = 0;
$styleDirs = 0;
$root = \Drupal::root() . '/sites/default/files/styles';
if (is_dir($root)) {
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
  foreach ($iterator as $item) {
    if ($item->isFile()) {
      $styles++;
    }
    if ($styles > 20000) {
      break;
    }
  }
  $styleDirs = count(glob($root . '/*', GLOB_ONLYDIR) ?: []);
}

$unmapped = ['public' => 0, 'private' => 0, 'capped' => FALSE];
$profile = \Drupal::service('xedc_core.storage_config')->profile($active);
$resolved = is_array($profile) ? \Drupal::service('xedc_core.storage_credentials')->resolve($profile) : ['ok' => FALSE];
if (!empty($resolved['ok']) && is_array($resolved['connection'] ?? NULL)) {
  $client = S3ClientFactory::create($resolved['connection']);
  $bucket = (string) $resolved['connection']['bucket'];
  unset($resolved);
  foreach (['public' => 's3fs-public/', 'private' => 's3fs-private/'] as $name => $prefix) {
    $token = NULL;
    $pages = 0;
    do {
      $args = ['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 200];
      if (is_string($token) && $token !== '') {
        $args['ContinuationToken'] = $token;
      }
      $response = $client->listObjectsV2($args);
      $contents = $response['Contents'] ?? [];
      $unmapped[$name] += is_array($contents) ? count($contents) : 0;
      $token = (string) ($response['NextContinuationToken'] ?? '');
      $pages++;
    } while ($token !== '' && $pages < 5);
    if ($token !== '') {
      $unmapped['capped'] = TRUE;
    }
  }
}

$report = $summary + [
  'scanned' => TRUE,
  'task' => $taskId,
  'files' => $before['count'],
  'profile' => $active,
  'probe_status' => (string) ($probed['status'] ?? ''),
  'style_directories' => $styleDirs,
  'style_files' => $styles,
  'remote_public_objects' => $unmapped['public'],
  'remote_private_objects' => $unmapped['private'],
  'remote_scan_capped' => $unmapped['capped'],
  'uri_unchanged' => TRUE,
  'copied' => 0,
  'checked' => \Drupal::time()->getRequestTime(),
  'risks' => [
    '预检查没有复制文件，没有修改 URI，没有删除本地文件，也没有打开接管。',
    '本地文件缺失的记录不能迁移。私有文件在 CDN 私有前缀被拒绝之前也不能复制。',
    '现有文件名不是内容哈希。复制时不会写一年 immutable。',
  ],
  'rollback' => [
    '本次没有写入生产对象，不需要数据回滚。',
    '若以后复制已开始，先停止复制，保留本地文件，只按任务里校验通过的键回滚。',
    '接管若被打开，先清掉运行时变量并重载 PHP-FPM，再处理远程对象。',
  ],
];
\Drupal::service('xedc_core.storage_journal')->put('migration_precheck', $report);
foreach (['files', 'public', 'private', 'ready_to_copy', 'missing_local', 'remote_match', 'checksum_failed', 'hash_deferred', 'ready_bytes', 'public_bytes', 'private_bytes', 'style_directories', 'style_files', 'remote_public_objects', 'remote_private_objects', 'probe_status'] as $key) {
  $line($key, (string) ($report[$key] ?? ''));
}
$line('uri_unchanged', 'yes');
$line('copied', '0');
return;
