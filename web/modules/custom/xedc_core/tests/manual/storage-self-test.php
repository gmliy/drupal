<?php

/**
 * @file
 * Checks object-storage guards without production files or real credentials.
 */

declare(strict_types=1);

use Drupal\xedc_core\Ops\Storage\BootGuard;
use Drupal\xedc_core\Ops\Storage\IntegrityChecker;
use Drupal\xedc_core\Ops\Storage\ProviderCatalog;
use Drupal\xedc_core\Ops\Storage\StorageConfig;

$root = dirname(__DIR__, 2);
require_once $root . '/src/Ops/Storage/BootGuard.php';
require_once $root . '/src/Ops/Storage/IntegrityChecker.php';
require_once $root . '/src/Ops/Storage/ProviderCatalog.php';
require_once $root . '/src/Ops/Storage/CredentialResolver.php';
require_once $root . '/src/Ops/Storage/StorageConfig.php';

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
  echo ($condition ? 'OK  ' : 'FAIL ') . $label . PHP_EOL;
  if (!$condition) {
    $failures++;
  }
};

$check('eight provider presets', count(ProviderCatalog::all()) === 8);
$check('r2 hint is not a saved endpoint', ProviderCatalog::get('cloudflare_r2')['endpoint_hint'] !== '' && ProviderCatalog::get('aws_s3')['endpoint_hint'] === '');
$defaults = StorageConfig::defaults();
$check('runtime profile is empty by default', $defaults['active_profile'] === '' && $defaults['multipart_cleanup'] === 0);
$check('temporary files stay local', $defaults['policy']['temporary'] === 'local');
$check('vip policy is private intent', $defaults['policy']['vip_download'] === 'private');

$check('metadata address rejected', BootGuard::endpointError('http://169.254.169.254') !== NULL);
$check('file scheme rejected', BootGuard::endpointError('file:///tmp/secret') !== NULL);
$check('credentials in endpoint rejected', BootGuard::endpointError('https://key:secret@example.com') !== NULL);
$check('public http rejected', BootGuard::publicUrlError('http://img.example.com') !== NULL);
$check('public https accepted', BootGuard::publicUrlError('https://img.example.com') === NULL);
$check('api path rejected', BootGuard::endpointError('https://s3.example.com/bucket') !== NULL);
$check('production key rejected', BootGuard::testKey('photos/a.jpg') !== NULL);
$check('traversal rejected', BootGuard::testKey('_xedc_storage_test/../a.jpg') !== NULL);
$check('test key allowed', BootGuard::testKey('_xedc_storage_test/a.txt') === NULL);
$check('secret pattern detected', BootGuard::containsSecretMaterial('AKIAIOSFODNN7EXAMPLE'));
$redacted = BootGuard::redact('sig X-Amz-Signature=abcdef1234567890 secret SUPERSECRETVALUE', ['SUPERSECRETVALUE']);
$check('signature redacted', !str_contains($redacted, 'abcdef1234567890') && !str_contains($redacted, 'SUPERSECRETVALUE'));

$body = 'xedc-storage-test';
$source = IntegrityChecker::fingerprint($body, 'text/plain', md5($body));
$target = IntegrityChecker::fingerprint($body, 'text/plain; charset=utf-8', '"' . md5($body) . '"');
$same = IntegrityChecker::compare($source, $target);
$check('sha and size match', $same['ok'] === TRUE && $same['mime'] === TRUE);
$broken = IntegrityChecker::compare($source, IntegrityChecker::fingerprint('other', 'text/plain'));
$check('mismatch is not success', $broken['ok'] === FALSE);
$multipart = IntegrityChecker::fingerprint($body, 'text/plain', 'abc-1');
$check('multipart etag is not md5', $multipart['etag_is_md5'] === FALSE);
$check('plain etag may match md5', $source['etag_is_md5'] === TRUE);

$env = [
  'XEDC_STORAGE_RUNTIME',
  'STORAGE_BUCKET',
  'STORAGE_REGION',
  'STORAGE_ENDPOINT',
  'STORAGE_ACCESS_KEY',
  'STORAGE_SECRET_KEY',
  'STORAGE_PUBLIC_TAKEOVER',
  'STORAGE_PRIVATE_TAKEOVER',
  'STORAGE_I_UNDERSTAND_TAKEOVER',
  'STORAGE_CDN_ENABLED',
  'STORAGE_ALLOW_LOOPBACK',
];
$previous = [];
foreach ($env as $name) {
  $previous[$name] = getenv($name);
  putenv($name);
  unset($_ENV[$name], $_SERVER[$name]);
}
try {
  $settings = [];
  $config = [];
  BootGuard::apply($settings, $config);
  $check('missing runtime stays local', $settings['xedc_storage.runtime_applied'] === FALSE);
  $check('public takeover forced off', $settings['s3fs.use_s3_for_public'] === FALSE && $settings['s3fs.use_s3_for_private'] === FALSE);
  $check('no key without runtime', !isset($settings['s3fs.access_key']));

  putenv('XEDC_STORAGE_RUNTIME=1');
  $_ENV['XEDC_STORAGE_RUNTIME'] = '1';
  $settings = [];
  $config = [];
  BootGuard::apply($settings, $config);
  $check('incomplete runtime is not applied', $settings['xedc_storage.runtime_applied'] === FALSE && $settings['xedc_storage.runtime_error'] !== '');
  $check('incomplete runtime has no secret', !isset($settings['s3fs.secret_key']));

  putenv('STORAGE_BUCKET=example-bucket');
  putenv('STORAGE_REGION=auto');
  putenv('STORAGE_ENDPOINT=https://example.r2.cloudflarestorage.com');
  putenv('STORAGE_ACCESS_KEY=EXAMPLEACCESSKEY');
  putenv('STORAGE_SECRET_KEY=EXAMPLESECRETKEY');
  putenv('STORAGE_PUBLIC_TAKEOVER=1');
  foreach (['STORAGE_BUCKET', 'STORAGE_REGION', 'STORAGE_ENDPOINT', 'STORAGE_ACCESS_KEY', 'STORAGE_SECRET_KEY', 'STORAGE_PUBLIC_TAKEOVER'] as $name) {
    $_ENV[$name] = getenv($name);
  }
  $settings = [];
  $config = [];
  BootGuard::apply($settings, $config);
  $check('complete runtime applies', $settings['xedc_storage.runtime_applied'] === TRUE);
  $check('takeover needs the second flag', $settings['s3fs.use_s3_for_public'] === FALSE);
  $check('custom host is separate from cdn', ($config['s3fs.settings']['hostname'] ?? '') === 'https://example.r2.cloudflarestorage.com' && empty($config['s3fs.settings']['use_cname']));
  $check('upload stays private by default', $settings['s3fs.upload_as_private'] === TRUE);

  $private = sys_get_temp_dir() . '/xedc-cred-' . bin2hex(random_bytes(3));
  mkdir($private, 0700);
  $adminAccess = 'ADMINACCESSKEY1';
  $adminSecret = 'ADMINSECRETKEY1';
  $written = BootGuard::writeProfileSecret($private, 'credtest', 'access_key', $adminAccess)
    && BootGuard::writeProfileSecret($private, 'credtest', 'secret_key', $adminSecret)
    && BootGuard::writeActiveProfile($private, 'credtest');
  $check('admin credential files write', $written);
  $settings = ['file_private_path' => $private];
  $config = [];
  BootGuard::apply($settings, $config);
  $check('admin credential overrides env', ($settings['s3fs.access_key'] ?? '') === $adminAccess && ($settings['xedc_storage.credential_source'] ?? '') === 'admin');
  $check('admin secret stays out of runtime error', !str_contains((string) ($settings['xedc_storage.runtime_error'] ?? ''), $adminSecret));
  $runtimeWritten = BootGuard::writeProfileRuntime($private, 'credtest', [
    'provider' => 'cloudflare_r2',
    'bucket' => 'profile-bucket',
    'region' => 'auto',
    'endpoint' => 'https://profile.example.r2.cloudflarestorage.com',
    'path_style' => TRUE,
    'public_base_url' => 'https://cdn.example.com',
    'cdn_base_url' => 'https://cdn.example.com',
    'cdn_enabled' => FALSE,
    'public_folder' => 's3fs-public',
    'private_folder' => 's3fs-private',
    'upload_as_private' => TRUE,
  ]);
  $check('profile runtime file writes', $runtimeWritten);
  $check('runtime file rejects secret material', BootGuard::writeProfileRuntime($private, 'credtest', [
    'bucket' => 'profile-bucket',
    'region' => 'auto',
    'endpoint' => 'https://profile.example.r2.cloudflarestorage.com',
    'public_base_url' => 'https://cdn.example.com/AKIAIOSFODNN7EXAMPLE',
  ]) === FALSE);
  foreach (['STORAGE_BUCKET', 'STORAGE_REGION', 'STORAGE_ENDPOINT', 'STORAGE_PATH_STYLE'] as $name) {
    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
  }
  $settings = ['file_private_path' => $private];
  $config = [];
  BootGuard::apply($settings, $config);
  $check('profile runtime supplies bucket and endpoint', $settings['xedc_storage.runtime_applied'] === TRUE && ($settings['xedc_storage.bucket'] ?? '') === 'profile-bucket' && ($config['s3fs.settings']['hostname'] ?? '') === 'https://profile.example.r2.cloudflarestorage.com');
  $check('profile runtime keeps takeover off', $settings['s3fs.use_s3_for_public'] === FALSE && $settings['s3fs.use_s3_for_private'] === FALSE);
  $check('profile runtime enables path style', ($config['s3fs.settings']['use_path_style_endpoint'] ?? FALSE) === TRUE);
  $check('profile runtime does not enable cdn rewrite', empty($config['s3fs.settings']['use_cname']) && ($settings['xedc_storage.connection_source'] ?? '') === 'profile');
  $check('profile runtime keeps admin credentials', ($settings['xedc_storage.credential_source'] ?? '') === 'admin' && ($settings['s3fs.access_key'] ?? '') === $adminAccess);
  $rawRuntime = is_file($private . '/xedc-storage-keys/credtest/runtime.json') ? file_get_contents($private . '/xedc-storage-keys/credtest/runtime.json') : '';
  $check('runtime file has no secret', is_string($rawRuntime) && $rawRuntime !== '' && !str_contains($rawRuntime, $adminSecret) && !str_contains($rawRuntime, $adminAccess));
  putenv('STORAGE_BUCKET=example-bucket');
  putenv('STORAGE_REGION=auto');
  putenv('STORAGE_ENDPOINT=https://example.r2.cloudflarestorage.com');
  $_ENV['STORAGE_BUCKET'] = 'example-bucket';
  $_ENV['STORAGE_REGION'] = 'auto';
  $_ENV['STORAGE_ENDPOINT'] = 'https://example.r2.cloudflarestorage.com';
  $settings = ['file_private_path' => $private];
  $config = [];
  BootGuard::apply($settings, $config);
  $check('environment bucket overrides profile file', ($settings['xedc_storage.bucket'] ?? '') === 'example-bucket' && ($settings['xedc_storage.connection_source'] ?? '') === 'mixed');
  $secretFile = $private . '/xedc-storage-keys/credtest/secret_key';
  if (is_file($secretFile)) {
    unlink($secretFile);
  }
  $settings = ['file_private_path' => $private];
  $config = [];
  BootGuard::apply($settings, $config);
  $check('incomplete admin credential blocks env', $settings['xedc_storage.runtime_applied'] === FALSE && !isset($settings['s3fs.access_key']) && !str_contains((string) $settings['xedc_storage.runtime_error'], $adminAccess));
  putenv('XEDC_STORAGE_RUNTIME=0');
  $_ENV['XEDC_STORAGE_RUNTIME'] = '0';
  BootGuard::writeProfileSecret($private, 'credtest', 'secret_key', $adminSecret);
  $settings = ['file_private_path' => $private];
  $config = [];
  BootGuard::apply($settings, $config);
  $check('runtime off ignores credential files', $settings['xedc_storage.runtime_applied'] === FALSE && !isset($settings['s3fs.access_key']));
  BootGuard::deleteProfileFiles($private, 'credtest');
  $check('profile runtime file removed', !is_file($private . '/xedc-storage-keys/credtest/runtime.json'));
  @rmdir($private . '/xedc-storage-keys');
  @rmdir($private);
}
finally {
  foreach ($previous as $name => $value) {
    if ($value === FALSE || $value === NULL || $value === '') {
      putenv($name);
      unset($_ENV[$name], $_SERVER[$name]);
    }
    else {
      putenv($name . '=' . $value);
      $_ENV[$name] = $value;
    }
  }
}

$drupal = class_exists(\Drupal::class) && \Drupal::hasContainer();
echo $drupal ? "Drupal integration running\n" : "Drupal integration skipped\n";
if ($drupal) {
  \Drupal::service('xedc_core.storage_config')->ensure();
  $runtime = \Drupal::service('xedc_core.storage_monitor')->runtime();
  $check('site runtime is not applied', $runtime['applied'] === FALSE);
  $check('site takeover is off', $runtime['public_takeover'] === FALSE && $runtime['private_takeover'] === FALSE);
  $census = \Drupal::service('xedc_core.storage_monitor')->census(TRUE);
  echo 'CENSUS files=' . $census['files'] . ' public=' . $census['public_files'] . ' private=' . $census['private_files'] . ' s3=' . $census['s3_files'] . ' temporary=' . $census['temporary_files'] . PHP_EOL;
  $bucket = (string) \Drupal::config('s3fs.settings')->get('bucket');
  $check('s3fs bucket override is empty', $bucket === '');
  $guard = \Drupal::service('xedc_core.file_path_guard');
  $check('remote uri is protected kind', $guard->kindForUri('s3://private/a.jpg') === 'remote');

  $config = \Drupal::service('xedc_core.storage_config');
  $config->deleteProfile('selftest_tmp');
  $errors = $config->saveProfile([
    'id' => 'selftest_tmp',
    'label' => 'Self test',
    'provider' => 'custom',
    'region' => 'us-east-1',
    'endpoint' => 'https://s3.example.com',
    'bucket' => 'example-bucket',
    'path_style' => TRUE,
    'public_folder' => 's3fs-public',
    'private_folder' => 's3fs-private',
    'disable_version_sync' => TRUE,
    'upload_as_private' => TRUE,
    'access_key_env' => 'STORAGE_PROFILE_SELFTEST_TMP_ACCESS_KEY',
    'secret_key_env' => 'STORAGE_PROFILE_SELFTEST_TMP_SECRET_KEY',
    'bucket_env' => 'STORAGE_PROFILE_SELFTEST_TMP_BUCKET',
  ], TRUE);
  $check('test profile saved', $errors === []);
  $raw = json_encode(\Drupal::config('xedc_core.storage')->getRawData());
  $check('saved config has no access key material', is_string($raw) && !str_contains($raw, 'AKIA') && !str_contains($raw, 'EXAMPLESECRET'));

  $vault = \Drupal::service('xedc_core.storage_credentials');
  $accessA = 'XEDCSELFTESTACCESS01';
  $secretA = 'xedc-selftest-secret-a1';
  $secretA2 = 'xedc-selftest-secret-a2';
  $accessB = 'XEDCSELFTESTACCESS02';
  $secretB = 'xedc-selftest-secret-b2';
  $config->deleteProfile('selftest_b');
  $config->saveProfile([
    'id' => 'selftest_b',
    'label' => 'Self test B',
    'provider' => 'custom',
    'region' => 'us-east-1',
    'endpoint' => 'https://s3.example.com',
    'bucket' => 'example-bucket-b',
    'path_style' => TRUE,
    'public_folder' => 's3fs-public',
    'private_folder' => 's3fs-private',
    'disable_version_sync' => TRUE,
    'upload_as_private' => TRUE,
    'access_key_env' => 'STORAGE_PROFILE_SELFTEST_B_ACCESS_KEY',
    'secret_key_env' => 'STORAGE_PROFILE_SELFTEST_B_SECRET_KEY',
    'bucket_env' => 'STORAGE_PROFILE_SELFTEST_B_BUCKET',
  ], TRUE);
  $check('credential provider ready', $vault->providerReady());
  $saved = $vault->save('selftest_tmp', $accessA, $secretA);
  $savedB = $vault->save('selftest_b', $accessB, $secretB);
  $check('credentials saved', $saved === [] && $savedB === []);
  $resolvedA = $vault->resolve($config->profile('selftest_tmp'));
  $resolvedB = $vault->resolve($config->profile('selftest_b'));
  $check('profiles do not share credentials', ($resolvedA['connection']['secret_key'] ?? '') === $secretA && ($resolvedB['connection']['secret_key'] ?? '') === $secretB && $resolvedA['connection']['secret_key'] !== $resolvedB['connection']['secret_key']);
  $check('admin credential source', ($resolvedA['source'] ?? '') === 'admin' && ($resolvedB['source'] ?? '') === 'admin');
  $updated = $vault->save('selftest_tmp', '', $secretA2);
  $resolvedUpdated = $vault->resolve($config->profile('selftest_tmp'));
  $check('secret can be replaced', $updated === [] && ($resolvedUpdated['connection']['secret_key'] ?? '') === $secretA2 && ($resolvedUpdated['connection']['access_key'] ?? '') === $accessA);
  $kept = $vault->save('selftest_tmp', 'short', '');
  $resolvedKept = $vault->resolve($config->profile('selftest_tmp'));
  $check('invalid input does not replace credentials', $kept !== [] && ($resolvedKept['connection']['access_key'] ?? '') === $accessA && ($resolvedKept['connection']['secret_key'] ?? '') === $secretA2);
  $raw = json_encode(\Drupal::config('xedc_core.storage')->getRawData());
  $keyRaw = '';
  foreach (\Drupal::configFactory()->listAll('key.key.') as $name) {
    $keyRaw .= json_encode(\Drupal::config($name)->getRawData());
  }
  $check('config export has no test secrets', is_string($raw) && !str_contains($raw, $secretA) && !str_contains($raw, $secretA2) && !str_contains($raw, $secretB) && !str_contains($raw, $accessA) && !str_contains($keyRaw, $secretA2) && !str_contains($keyRaw, $accessA));
  $database = \Drupal::database();
  $storedRows = 0;
  if ($database->schema()->tableExists('config')) {
    $storedRows += (int) $database->query('SELECT COUNT(*) FROM {config} WHERE data LIKE :secret', [':secret' => '%' . $database->escapeLike($secretA2) . '%'])->fetchField();
  }
  if ($database->schema()->tableExists('key_value')) {
    $storedRows += (int) $database->query('SELECT COUNT(*) FROM {key_value} WHERE value LIKE :secret', [':secret' => '%' . $database->escapeLike($secretA2) . '%'])->fetchField();
  }
  if ($database->schema()->tableExists('watchdog')) {
    $storedRows += (int) $database->query('SELECT COUNT(*) FROM {watchdog} WHERE message LIKE :secret OR variables LIKE :secret', [':secret' => '%' . $database->escapeLike($secretA2) . '%'])->fetchField();
  }
  $secretPath = \Drupal\Core\Site\Settings::get('file_private_path') . '/xedc-storage-keys/selftest_tmp/secret_key';
  $mode = is_file($secretPath) ? (fileperms($secretPath) & 0777) : 0;
  $check('database has no test secret', $storedRows === 0);
  $check('secret file is private and outside the web root', $mode === 0600 && is_file($secretPath) && !str_contains((string) $secretPath, '/web/'));
  $database = \Drupal::database();
  $storedRows = 0;
  if ($database->schema()->tableExists('config')) {
    $storedRows += (int) $database->query('SELECT COUNT(*) FROM {config} WHERE data LIKE :secret', [':secret' => '%' . $database->escapeLike($secretA2) . '%'])->fetchField();
  }
  if ($database->schema()->tableExists('key_value')) {
    $storedRows += (int) $database->query('SELECT COUNT(*) FROM {key_value} WHERE value LIKE :secret', [':secret' => '%' . $database->escapeLike($secretA2) . '%'])->fetchField();
  }
  if ($database->schema()->tableExists('watchdog')) {
    $storedRows += (int) $database->query('SELECT COUNT(*) FROM {watchdog} WHERE message LIKE :secret OR variables LIKE :secret', [':secret' => '%' . $database->escapeLike($secretA2) . '%'])->fetchField();
  }
  $secretPath = \Drupal\Core\Site\Settings::get('file_private_path') . '/xedc-storage-keys/selftest_tmp/secret_key';
  $mode = is_file($secretPath) ? (fileperms($secretPath) & 0777) : 0;
  $check('database has no test secret', $storedRows === 0);
  $check('secret file is private and outside the web root', $mode === 0600 && is_file($secretPath) && !str_contains((string) $secretPath, '/web/'));
  $described = $vault->describe($config->profile('selftest_tmp'));
  $check('secret is not in the status payload', !str_contains(json_encode($described), $secretA2) && ($described['status'] ?? '') === 'configured');
  $formState = new \Drupal\Core\Form\FormState();
  $formState->set('profile_id', 'selftest_tmp');
  $built = \Drupal::formBuilder()->buildForm(\Drupal\xedc_core\Form\StorageCredentialForm::class, $formState);
  $html = (string) \Drupal::service('renderer')->renderInIsolation($built);
  $check('form does not refill secrets', !str_contains($html, $secretA2) && !str_contains($html, $accessA) && str_contains($html, '已配置'));
  $global = 'GLOBALSECRETKEY99';
  putenv('STORAGE_ACCESS_KEY=GLOBALACCESSKEY99');
  putenv('STORAGE_SECRET_KEY=' . $global);
  $_ENV['STORAGE_ACCESS_KEY'] = 'GLOBALACCESSKEY99';
  $_ENV['STORAGE_SECRET_KEY'] = $global;
  $cleared = $vault->clear('selftest_tmp');
  $afterClear = $vault->resolve($config->profile('selftest_tmp'));
  $check('cleared credential does not use another profile or global key', $cleared === NULL && ($afterClear['connection']['secret_key'] ?? '') !== $secretA2 && ($afterClear['connection']['secret_key'] ?? '') !== $secretB && ($afterClear['connection']['secret_key'] ?? '') !== $global);
  putenv('STORAGE_ACCESS_KEY');
  putenv('STORAGE_SECRET_KEY');
  unset($_ENV['STORAGE_ACCESS_KEY'], $_ENV['STORAGE_SECRET_KEY']);
  $incomplete = $vault->save('selftest_b', '', '');
  $check('empty submit keeps the other profile', $incomplete !== [] && ($vault->resolve($config->profile('selftest_b'))['connection']['secret_key'] ?? '') === $secretB);
  $vault->clear('selftest_b');
  $config->deleteProfile('selftest_b');

  $result = \Drupal::service('xedc_core.storage_tester')->test('selftest_tmp');
  $check('connection waits for credentials', ($result['status'] ?? '') === 'pending_credentials');
  $task = \Drupal::service('xedc_core.storage_migration')->precheck('local', 'selftest_tmp');
  $check('precheck does not copy', ($task['copied'] ?? 1) === 0 && ($task['uri_rewritten'] ?? TRUE) === FALSE && ($task['source_retained'] ?? FALSE) === TRUE);
  $check('precheck is not marked migrated', !in_array(($task['status'] ?? ''), ['completed', 'verified', 'migrated'], TRUE));
  $config->deleteProfile('selftest_tmp');
  $check('test profile removed', $config->profile('selftest_tmp') === NULL);
  $planner = \Drupal::service('xedc_core.storage_migration');
  $cancelled = $planner->cancel((string) ($task['id'] ?? ''));
  foreach ($planner->recent(20) as $row) {
    if (($row['target'] ?? '') === 'selftest_tmp' && ($row['status'] ?? '') !== 'cancelled') {
      $planner->cancel((string) ($row['id'] ?? ''));
    }
  }
  $check('precheck cancelled without copying', $cancelled === NULL);

  $anonymous = new \Drupal\Core\Session\AnonymousUserSession();
  $access = \Drupal::classResolver(\Drupal\xedc_core\Access\StorageAccess::class)->download($anonymous);
  $check('anonymous protected route is forbidden', $access->isForbidden());
  $fid = \Drupal::database()->query("SELECT fid FROM {file_managed} WHERE uri LIKE 'public://%' ORDER BY fid DESC LIMIT 1")->fetchField();
  if ($fid) {
    $download = \Drupal::service('xedc_core.storage_download')->authorize((int) $fid, $anonymous);
    $check('anonymous public file is not presigned', ($download['status'] ?? 0) === 403 && ($download['url'] ?? 'x') === '');
    $admin = \Drupal\user\Entity\User::load(1);
    if ($admin) {
      $allowed = \Drupal::service('xedc_core.storage_download')->authorize((int) $fid, $admin);
      $check('admin public file is not presigned', ($allowed['status'] ?? 0) === 403 && ($allowed['url'] ?? 'x') === '');
    }
  }
  else {
    echo "SKIP no public file to check\n";
  }
}

echo $failures === 0 ? "RESULT passed\n" : "RESULT failed {$failures}\n";
$entry = $_SERVER['argv'][0] ?? '';
if (!str_contains($entry, 'drush')) {
  exit($failures === 0 ? 0 : 1);
}
return $failures === 0 ? 0 : 1;
