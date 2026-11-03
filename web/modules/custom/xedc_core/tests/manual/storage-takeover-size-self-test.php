<?php

/**
 * @file
 * Isolated takeover judgments and byte labels. Does not change production.
 */

declare(strict_types=1);

use Drupal\xedc_core\Ops\Storage\Provider\StorageProviderRegistry;
use Drupal\xedc_core\Ops\Storage\PublicUrl;
use Drupal\xedc_core\Ops\Storage\RuntimeState;
use Drupal\xedc_core\Ops\Storage\StorageSizeFormatter;
use Drupal\xedc_core\Ops\Storage\TakeoverReadiness;

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
  echo ($condition ? 'OK  ' : 'FAIL ') . $label . PHP_EOL;
  if (!$condition) {
    $failures++;
  }
};

$local = [
  'settings_readable' => TRUE,
  'runtime_file' => 'ok',
  'public_mode' => 'local',
  'public_provider' => 'local',
  'settings_public_mode' => 'local',
  'settings_runtime_applied' => FALSE,
  'public_takeover' => FALSE,
  'xedc_public_takeover' => FALSE,
  'private_takeover' => FALSE,
  'xedc_private_takeover' => FALSE,
  'cdn_rewrite' => FALSE,
  'runtime_cdn_enabled' => FALSE,
  'public_wrapper_remote' => FALSE,
  'private_wrapper_remote' => FALSE,
  'public_wrapper_class' => 'Drupal\\xedc_core\\StreamWrapper\\XedcPublicS3fsStream',
  'private_wrapper_class' => 'Drupal\\Core\\StreamWrapper\\PrivateStream',
];
$production = $local;
$production['public_mode'] = 'cloudflare_r2';
$production['public_provider'] = 'silly';
$production['settings_public_mode'] = 'cloudflare_r2';
$production['public_takeover'] = TRUE;
$production['xedc_public_takeover'] = TRUE;
$production['cdn_rewrite'] = TRUE;
$production['runtime_cdn_enabled'] = TRUE;
$production['public_wrapper_remote'] = TRUE;

$allOff = TakeoverReadiness::assess($local);
$check('all off passes without execution', $allOff['takeover_check'] === '通过' && $allOff['execution_allowed'] === FALSE && $allOff['executed_this_round'] === FALSE);

$runtimeOnly = $production;
$runtimeOnly['public_takeover'] = FALSE;
$runtimeOnly['xedc_public_takeover'] = FALSE;
$runtimeOnly['cdn_rewrite'] = FALSE;
$runtimeOnly['runtime_cdn_enabled'] = FALSE;
$runtimeOnlyResult = TakeoverReadiness::assess($runtimeOnly);
$check('runtime only is not a passed takeover', $runtimeOnlyResult['takeover_check'] === '不通过' && $runtimeOnlyResult['rows'][0]['result'] === '通过' && $runtimeOnlyResult['rows'][1]['result'] === '不通过' && $runtimeOnlyResult['rows'][3]['result'] === '不通过');

$takeoverOnly = $local;
$takeoverOnly['public_takeover'] = TRUE;
$takeoverOnly['xedc_public_takeover'] = TRUE;
$takeoverOnlyResult = TakeoverReadiness::assess($takeoverOnly);
$check('public takeover only fails that row', $takeoverOnlyResult['rows'][1]['result'] === '不通过' && $takeoverOnlyResult['rows'][0]['result'] === '通过' && $takeoverOnlyResult['rows'][3]['result'] === '通过');

$cdnOnly = $local;
$cdnOnly['cdn_rewrite'] = TRUE;
$cdnOnlyResult = TakeoverReadiness::assess($cdnOnly);
$check('cdn only fails that row', $cdnOnlyResult['rows'][3]['result'] === '不通过' && $cdnOnlyResult['rows'][1]['result'] === '通过' && $cdnOnlyResult['execution_allowed'] === FALSE);

$many = $local;
$many['settings_runtime_applied'] = TRUE;
$many['public_takeover'] = TRUE;
$many['xedc_public_takeover'] = TRUE;
$many['cdn_rewrite'] = TRUE;
$manyResult = TakeoverReadiness::assess($many);
$check('several local switches fail individually', $manyResult['rows'][0]['result'] === '不通过' && $manyResult['rows'][1]['result'] === '不通过' && $manyResult['rows'][2]['result'] === '通过' && $manyResult['rows'][3]['result'] === '不通过');

$missing = $local;
$missing['runtime_file'] = 'missing';
$missing['public_mode'] = '';
$missing['settings_public_mode'] = '';
$missingResult = TakeoverReadiness::assess($missing);
$check('missing file is not executable', $missingResult['phase'] === 'uninitialized' && $missingResult['takeover_check'] === '通过' && $missingResult['execution_allowed'] === FALSE);

$unreadable = TakeoverReadiness::assess(['settings_readable' => FALSE, 'public_takeover' => TRUE, 'public_mode' => 'cloudflare_r2']);
$check('unreadable settings are not treated as off', $unreadable['phase'] === 'unreadable' && $unreadable['rows'][0]['state'] === '无法判断' && $unreadable['rows'][1]['state'] === '无法判断' && $unreadable['execution_allowed'] === FALSE);

$disagree = $production;
$disagree['xedc_public_takeover'] = FALSE;
$disagreeResult = TakeoverReadiness::assess($disagree);
$check('split takeover settings fail', $disagreeResult['rows'][1]['result'] === '不通过' && str_contains($disagreeResult['rows'][1]['explanation'], '不一致'));

$liveShape = TakeoverReadiness::assess($production);
$legacy = json_encode($liveShape, JSON_UNESCAPED_UNICODE);
$check('production shape passes without claiming execution', $liveShape['takeover_check'] === '通过' && $liveShape['observed_public_r2'] === TRUE && $liveShape['executed_this_round'] === FALSE && $liveShape['execution_allowed'] === FALSE && is_string($legacy) && !str_contains($legacy, '本检查预期它们是关闭的'));

$invalid = $production;
$invalid['runtime_file'] = 'invalid';
$invalidResult = TakeoverReadiness::assess($invalid);
$check('invalid file blocks judgment', $invalidResult['phase'] === 'invalid' && $invalidResult['rows'][0]['state'] === '无法判断' && $invalidResult['execution_allowed'] === FALSE);

$adapter = $production;
$adapter['private_takeover'] = TRUE;
$adapter['xedc_private_takeover'] = TRUE;
$adapter['adapters_ok'] = TRUE;
$adapterResult = TakeoverReadiness::assess($adapter);
$check('adapter flag cannot authorize takeover', $adapterResult['takeover_check'] === '不通过' && $adapterResult['execution_allowed'] === FALSE && $adapterResult['executed_this_round'] === FALSE);

$sizes = [
  0 => '0 B',
  1 => '1 B',
  512 => '512 B',
  1023 => '1023 B',
  1024 => '1.00 KiB',
  1024 * 1024 => '1.00 MiB',
  163198 => '159.37 KiB',
  39380312064 => '36.68 GiB',
  1024 ** 4 => '1.00 TiB',
  1024 ** 5 => '1.00 PiB',
];
foreach ($sizes as $bytes => $label) {
  $check('size ' . $bytes, StorageSizeFormatter::formatBytes($bytes) === $label);
}
$raw = 39380312064;
$pair = StorageSizeFormatter::formatBytesWithExactValue($raw);
$check('raw bytes unchanged', $raw === 39380312064 && $pair['bytes'] === 39380312064 && $pair['bytes'] > 163198);
foreach ([NULL, -1, NAN, INF, '', 'abc', []] as $bad) {
  $check('invalid size stays safe', StorageSizeFormatter::formatBytes($bad) === '暂无数据');
}

$runtimePath = '/var/www/drupal/private/xedc-storage-runtime/active.json';
$before = is_file($runtimePath) ? hash_file('sha256', $runtimePath) : '';
$modeBefore = RuntimeState::read();
$assessment = \Drupal::service('xedc_core.storage_readiness')->switches();
echo 'LIVE phase=' . $assessment['phase'] . ' check=' . $assessment['takeover_check'] . ' execute=' . ($assessment['execution_allowed'] ? 'yes' : 'no') . ' executed=' . ($assessment['executed_this_round'] ? 'yes' : 'no') . PHP_EOL;
foreach ($assessment['rows'] as $row) {
  echo 'LIVE ' . $row['label'] . ' ' . $row['state'] . ' ' . $row['result'] . PHP_EOL;
  $check('live row has source', $row['source'] !== '');
}
$check('live execution stays closed', $assessment['execution_allowed'] === FALSE && $assessment['executed_this_round'] === FALSE);
$check('live mode still r2', ($modeBefore['public_mode'] ?? '') === 'cloudflare_r2' && ($modeBefore['public_provider'] ?? '') === 'silly');

$registry = new StorageProviderRegistry(
  \Drupal::service('xedc_core.storage_config'),
  \Drupal::service('xedc_core.storage_credentials'),
  \Drupal::service('xedc_core.storage_reader'),
);
foreach ($registry->operational() as $provider) {
  $card = $registry->card($provider, TRUE);
  $detail = (string) ($card['health']['detail'] ?? '');
  echo 'HEALTH ' . $card['label'] . ' ' . $card['health']['status'] . ' ' . $detail . PHP_EOL;
  $check('health detail is not a raw byte dump', !preg_match('/剩余字节 \d{5,}/', $detail) && !preg_match('/抽样对象 \d{3,} 字节/', $detail));
}

$folders = \Drupal::service('xedc_core.storage_uris')->folders(\Drupal::service('xedc_core.storage_config')->profile('silly'));
$check('prefixes stay public and private', ($folders['public'] ?? '') === 'public' && ($folders['private'] ?? '') === 'private');
$privateUrl = PublicUrl::cdnUrl('private://secret.txt', 'https://cdn.gitxx.xyz', 'public');
$styleUrl = PublicUrl::localStyleUrl('public://styles/thumbnail/public/example.jpg');
$publicUrl = PublicUrl::cdnUrl('public://example.jpg', 'https://cdn.gitxx.xyz', 'public');
$check('private has no public cdn url', $privateUrl === NULL);
$check('style stays local and public cdn keeps one prefix', is_string($styleUrl) && !str_contains($styleUrl, 'cdn.gitxx.xyz') && $publicUrl === 'https://cdn.gitxx.xyz/public/example.jpg' && !str_contains((string) $publicUrl, 'public/public/'));
$after = is_file($runtimePath) ? hash_file('sha256', $runtimePath) : '';
$modeAfter = RuntimeState::read();
$check('runtime file unchanged', $before !== '' && $before === $after && ($modeAfter['public_mode'] ?? '') === 'cloudflare_r2');

echo $failures === 0 ? 'PASS 0' . PHP_EOL : 'FAIL ' . $failures . PHP_EOL;
if ($failures !== 0) {
  throw new \RuntimeException('storage takeover size self-test failed: ' . $failures);
}
