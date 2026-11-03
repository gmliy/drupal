<?php

/**
 * @file
 * Isolated checks for the storage switch. Does not switch production mode.
 */

declare(strict_types=1);

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\xedc_core\Form\StorageSwitchForm;
use Drupal\xedc_core\Ops\Storage\PublicUrl;
use Drupal\xedc_core\Ops\Storage\RuntimeState;
use Drupal\xedc_core\Ops\Storage\StorageSwitchManager;

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
  echo ($condition ? 'OK  ' : 'FAIL ') . $label . PHP_EOL;
  if (!$condition) {
    $failures++;
  }
};

$base = [
  'target' => 'local',
  'current_mode' => 'cloudflare_r2',
  'current_provider' => 'silly',
  'public_folder' => 'public',
  'private_folder' => 'private',
  'legacy_prefix' => 's3fs-public',
  'legacy_objects' => 0,
  'active_objects' => 1,
  'files' => [],
  'remote_known' => TRUE,
  'list_error' => '',
  'directory_exists' => TRUE,
  'directory_readable' => TRUE,
  'directory_writable' => TRUE,
  'directory' => '/tmp/xedc-public',
  'directory_note' => '/tmp/xedc-public',
  'runtime_writable' => TRUE,
  'locked' => FALSE,
  'process_user' => 'www-data',
  'private_wrapper_local' => TRUE,
  'private_cdn_blocked' => TRUE,
  'private_takeover' => FALSE,
  'private_note' => 'private',
  'style_stays_local' => TRUE,
  'architecture_ok' => TRUE,
  'architecture_note' => 'ok',
  'language_note' => '2 种内容语言，0 个文件被多于一种语言引用。',
  'language_error' => '',
];

$file = static function (string $uri, ?int $local, ?int $active, ?int $legacy = NULL, string $error = ''): array {
  $relative = substr($uri, strlen('public://'));
  return [
    'fid' => 1,
    'uri' => $uri,
    'relative' => $relative,
    'disk' => '/tmp/xedc-public/' . $relative,
    'local_bytes' => $local,
    'local_error' => $error,
    'active_bytes' => $active,
    'legacy_bytes' => $legacy,
  ];
};

$present = $base;
$present['files'] = [$file('public://2026-11/a.webp', 10, 10)];
$localCheck = StorageSwitchManager::evaluate($present);
$check('local target check can pass', !empty($localCheck['allowed']) && ($localCheck['rows'][10]['result'] ?? '') === '可以执行');
$check('hash is not pretended', ($localCheck['rows'][6]['result'] ?? '') === '未执行');
$check('local check uses public prefix', ($localCheck['prefix'] ?? '') === 'public' && ($localCheck['copy_plan'][0]['key'] ?? '') !== 's3fs-public/2026-11/a.webp');

$r2 = $present;
$r2['target'] = 'silly';
$r2['current_mode'] = 'local';
$r2['current_provider'] = 'local';
$r2Check = StorageSwitchManager::evaluate($r2);
$check('r2 target check can pass', !empty($r2Check['allowed']) && $r2Check['target_mode'] === 'cloudflare_r2');

$same = $present;
$same['target'] = 'silly';
$sameCheck = StorageSwitchManager::evaluate($same);
$check('same mode blocks switch', empty($sameCheck['allowed']) && str_contains((string) $sameCheck['block_reason'], '当前运行模式'));

$missingDir = $present;
$missingDir['directory_exists'] = FALSE;
$missingDir['directory_readable'] = FALSE;
$missingDir['directory_writable'] = FALSE;
$missingDir['directory_note'] = '目录不存在：/missing';
$dirCheck = StorageSwitchManager::evaluate($missingDir);
$check('missing directory blocks', empty($dirCheck['allowed']) && str_starts_with((string) ($dirCheck['rows'][2]['result'] ?? ''), '失败'));

$gone = $present;
$gone['files'] = [$file('public://gone.jpg', NULL, NULL)];
$goneCheck = StorageSwitchManager::evaluate($gone);
$check('missing everywhere blocks', empty($goneCheck['allowed']) && (int) $goneCheck['missing_both'] === 1);

$mismatch = $present;
$mismatch['files'] = [$file('public://size.jpg', 10, 11)];
$mismatchCheck = StorageSwitchManager::evaluate($mismatch);
$check('size mismatch blocks', empty($mismatchCheck['allowed']) && (int) $mismatchCheck['size_mismatch'] === 1);

$unreadable = $present;
$unreadable['files'] = [$file('public://secret.jpg', NULL, 10, NULL, '文件存在但当前进程无法读取')];
$unreadableCheck = StorageSwitchManager::evaluate($unreadable);
$check('unreadable file blocks', empty($unreadableCheck['allowed']) && str_starts_with((string) ($unreadableCheck['rows'][7]['result'] ?? ''), '失败'));

$double = $present;
$double['files'] = [$file('public://public/nested.jpg', 4, 4)];
$doubleCheck = StorageSwitchManager::evaluate($double);
$check('double prefix blocks', empty($doubleCheck['allowed']) && str_contains((string) ($doubleCheck['problems'][0]['reason'] ?? ''), '双重前缀'));

$legacyOnly = $present;
$legacyOnly['files'] = [$file('public://old.jpg', NULL, NULL, 8)];
$legacyLocal = StorageSwitchManager::evaluate($legacyOnly);
$check('legacy object can be downloaded to local', !empty($legacyLocal['allowed']) && ($legacyLocal['copy_plan'][0]['source_key'] ?? '') === 's3fs-public/old.jpg' && ($legacyLocal['copy_plan'][0]['key'] ?? '') === 'public/old.jpg');
$legacyR2 = $legacyOnly;
$legacyR2['target'] = 'silly';
$legacyR2['current_mode'] = 'local';
$legacyR2['current_provider'] = 'local';
$legacyR2Check = StorageSwitchManager::evaluate($legacyR2);
$check('legacy object is not copied between prefixes', empty($legacyR2Check['allowed']) && str_contains((string) ($legacyR2Check['problems'][0]['reason'] ?? ''), '自动搬迁'));

$upload = $present;
$upload['target'] = 'silly';
$upload['current_mode'] = 'local';
$upload['current_provider'] = 'local';
$upload['files'] = [$file('public://only-local.jpg', 6, NULL)];
$uploadCheck = StorageSwitchManager::evaluate($upload);
$check('local file uploads to public prefix', !empty($uploadCheck['allowed']) && ($uploadCheck['copy_plan'][0]['action'] ?? '') === 'upload' && ($uploadCheck['copy_plan'][0]['key'] ?? '') === 'public/only-local.jpg');

$listFailed = $present;
$listFailed['remote_known'] = FALSE;
$listFailed['list_error'] = '列出 R2 对象失败：AccessDenied';
$listCheck = StorageSwitchManager::evaluate($listFailed);
$check('remote list failure is not success', empty($listCheck['allowed']) && ($listCheck['rows'][5]['result'] ?? '') === '未执行' && $listCheck['copy_plan'] === []);

$locked = $present;
$locked['locked'] = TRUE;
$lockedCheck = StorageSwitchManager::evaluate($locked);
$check('lock blocks switch', empty($lockedCheck['allowed']));

$uriPath = StorageSwitchManager::diskPath('/var/www/drupal/web/sites/default/files', 'public://2026-11/a.webp');
$diskPath = StorageSwitchManager::diskPath('/var/www/drupal/web/sites/default/files', '2026-11/a.webp');
$check('uri is not used as a disk path', $uriPath === NULL && $diskPath === '/var/www/drupal/web/sites/default/files/2026-11/a.webp');

$now = time();
$saved = ['token' => 'abc123', 'target' => 'local', 'digest' => 'digest-a', 'expires' => $now + 100];
$check('token accepts matching target', StorageSwitchManager::tokenAccepts($saved, 'abc123', 'local', 'digest-a', $now, TRUE) === '');
$check('expired token is rejected', str_contains(StorageSwitchManager::tokenAccepts($saved, 'abc123', 'local', 'digest-a', $now + 101, TRUE), '过期'));
$check('other target is rejected', str_contains(StorageSwitchManager::tokenAccepts($saved, 'abc123', 'silly', 'digest-a', $now, TRUE), '另一个目标'));
$check('changed files are rejected', str_contains(StorageSwitchManager::tokenAccepts($saved, 'abc123', 'local', 'digest-b', $now, TRUE), '发生了变化'));
$check('reused empty token is rejected', str_contains(StorageSwitchManager::tokenAccepts([], 'abc123', 'local', 'digest-a', $now, TRUE), '没有可用'));

$left = hash('sha256', 'alpha');
$right = hash('sha256', 'beta');
$check('different hashes fail', StorageSwitchManager::hashesMatch($left, $right) === FALSE && StorageSwitchManager::hashesMatch($left, $left) === TRUE);

$sentinel = tempnam(sys_get_temp_dir(), 'xedc-src-');
file_put_contents((string) $sentinel, 'keep-me');
$prepared = StorageSwitchManager::prepareLocalDestination('/proc/xedc-not-a-directory/file.txt');
$check('failed destination keeps source', $prepared === FALSE && is_file((string) $sentinel) && file_get_contents((string) $sentinel) === 'keep-me');
@unlink((string) $sentinel);

$privateUrl = PublicUrl::cdnUrl('private://secret.txt', 'https://cdn.gitxx.xyz', 'public');
$styleUrl = PublicUrl::localStyleUrl('public://styles/thumbnail/public/example.jpg');
$publicUrl = PublicUrl::cdnUrl('public://example.jpg', 'https://cdn.gitxx.xyz', 'public');
$check('private uri has no cdn url', $privateUrl === NULL);
$check('image style stays off the cdn', is_string($styleUrl) && !str_contains($styleUrl, 'cdn.gitxx.xyz'));
$check('public url has one prefix', $publicUrl === 'https://cdn.gitxx.xyz/public/example.jpg');

$runtimeBefore = is_file('/var/www/drupal/private/xedc-storage-runtime/active.json') ? hash_file('sha256', '/var/www/drupal/private/xedc-storage-runtime/active.json') : '';
$lockDir = sys_get_temp_dir() . '/xedc-switch-lock-' . bin2hex(random_bytes(4));
RuntimeState::useDirectory($lockDir);
try {
  $first = RuntimeState::acquire(NULL);
  $child = 'require ' . var_export('/var/www/drupal/web/modules/custom/xedc_core/src/Ops/Storage/RuntimeState.php', TRUE) . '; '
    . 'Drupal\\xedc_core\\Ops\\Storage\\RuntimeState::useDirectory(' . var_export($lockDir, TRUE) . '); '
    . 'echo Drupal\\xedc_core\\Ops\\Storage\\RuntimeState::acquire(NULL) ? "yes" : "no";';
  $second = shell_exec('php -r ' . escapeshellarg($child) . ' 2>&1');
  $check('first lock acquires and second process conflicts', $first === TRUE && trim((string) $second) === 'no');
}
finally {
  RuntimeState::release();
  RuntimeState::useDirectory(NULL);
}

$state = \Drupal::state();
$previousCheck = $state->get('xedc_storage.switch_check', NULL);
$manager = \Drupal::service('xedc_core.storage_switch');
$check('service is the switch manager', $manager instanceof StorageSwitchManager);
try {
  $local = $manager->inspect('local');
  $remote = $manager->inspect('silly');
  $check('production local inspect returns rows', is_array($local['rows'] ?? NULL) && count($local['rows']) === 11 && ($local['prefix'] ?? '') === 'public');
  $check('production r2 inspect returns rows', is_array($remote['rows'] ?? NULL) && ($remote['current_mode'] ?? '') === 'cloudflare_r2');
  $check('production inspect did not switch', RuntimeState::read()['public_mode'] === 'cloudflare_r2');
  $unreadablePath = sys_get_temp_dir() . '/xedc-unreadable-' . bin2hex(random_bytes(3));
  file_put_contents($unreadablePath, 'hidden');
  chmod($unreadablePath, 0000);
  $unreadableStat = StorageSwitchManager::readLocalFile($unreadablePath);
  chmod($unreadablePath, 0600);
  @unlink($unreadablePath);
  $check('www-data cannot read a mode 000 file', ($unreadableStat['error'] ?? '') === '文件存在但当前进程无法读取');
  foreach ((array) ($local['pending'] ?? []) as $item) {
    if (is_array($item)) {
      echo 'LOCAL copy ' . (string) ($item['action'] ?? '') . ' ' . (string) ($item['key'] ?? '') . ' from ' . (string) ($item['source_key'] ?? '') . ' bytes ' . (int) ($item['bytes'] ?? 0) . PHP_EOL;
    }
  }
  foreach ((array) ($remote['pending'] ?? []) as $item) {
    if (is_array($item)) {
      echo 'R2 copy ' . (string) ($item['action'] ?? '') . ' ' . (string) ($item['key'] ?? '') . ' bytes ' . (int) ($item['bytes'] ?? 0) . PHP_EOL;
    }
  }
  echo 'LOCAL allowed=' . (!empty($local['allowed']) ? 'yes' : 'no')
    . ' required=' . (int) ($local['required'] ?? -1)
    . ' missing=' . (int) ($local['missing_target'] ?? -1)
    . ' both_missing=' . (int) ($local['missing_both'] ?? -1)
    . ' size=' . (int) ($local['size_mismatch'] ?? -1)
    . ' copy=' . (int) ($local['copy_count'] ?? -1)
    . ' reason=' . (string) ($local['block_reason'] ?? '')
    . PHP_EOL;
  echo 'R2 allowed=' . (!empty($remote['allowed']) ? 'yes' : 'no')
    . ' required=' . (int) ($remote['required'] ?? -1)
    . ' missing=' . (int) ($remote['missing_target'] ?? -1)
    . ' both_missing=' . (int) ($remote['missing_both'] ?? -1)
    . ' size=' . (int) ($remote['size_mismatch'] ?? -1)
    . ' copy=' . (int) ($remote['copy_count'] ?? -1)
    . ' active=' . (int) ($remote['active_objects'] ?? -1)
    . ' legacy=' . (int) ($remote['legacy_objects'] ?? -1)
    . ' reason=' . (string) ($remote['block_reason'] ?? '')
    . PHP_EOL;
  $refused = $manager->execute('local', '不是确认文字', 'missing-token', 0);
  $check('wrong confirmation does not switch', empty($refused['ok']) && RuntimeState::read()['public_mode'] === 'cloudflare_r2');
  $expired = $manager->execute('local', RuntimeState::CONFIRMATION, 'missing-token', 0);
  $check('missing token does not switch', empty($expired['ok']) && str_contains((string) $expired['message'], '没有切换'));
}
finally {
  if ($previousCheck === NULL) {
    $state->delete('xedc_storage.switch_check');
  }
  else {
    $state->set('xedc_storage.switch_check', $previousCheck);
  }
  RuntimeState::useDirectory(NULL);
  RuntimeState::release();
}
$runtimeAfter = is_file('/var/www/drupal/private/xedc-storage-runtime/active.json') ? hash_file('sha256', '/var/www/drupal/private/xedc-storage-runtime/active.json') : '';
$check('runtime file unchanged', $runtimeBefore !== '' && $runtimeBefore === $runtimeAfter);

$form = StorageSwitchForm::create(\Drupal::getContainer());
$serialized = unserialize(serialize($form));
$property = new ReflectionProperty(StorageSwitchForm::class, 'switcher');
$restored = $property->getValue($serialized);
$check('form survives cache serialization', $restored instanceof StorageSwitchManager);
$anonymous = $form->access(new AnonymousUserSession(), TRUE);
$check('anonymous cannot switch', $anonymous->isForbidden() || !$anonymous->isAllowed());

echo $failures === 0 ? "PASS {$failures}\n" : "FAIL {$failures}\n";
if ($failures !== 0) {
  throw new RuntimeException('storage switch self-test failed: ' . $failures);
}
