<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

require_once __DIR__ . '/RuntimeState.php';

/**
 * Environment and address checks that can run before Drupal is bootstrapped.
 *
 * This class must not depend on Drupal services. settings.php loads it directly.
 */
final class BootGuard {

  public const TEST_PREFIX = '_xedc_storage_test/';

  /**
   * Keeps a single MIME type token. Anything else becomes octet-stream.
   */
  public static function contentType(string $mime): string {
    $mime = strtolower(trim(explode(';', $mime)[0]));
    return preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~', $mime) === 1
      ? $mime
      : 'application/octet-stream';
  }

  /**
   * Reads one environment variable. Empty values are treated as missing.
   */
  public static function env(string $name): ?string {
    if (!preg_match('/^[A-Z][A-Z0-9_]{0,80}$/', $name)) {
      return NULL;
    }
    $value = getenv($name);
    if (!is_string($value) && isset($_ENV[$name]) && is_string($_ENV[$name])) {
      $value = $_ENV[$name];
    }
    if (!is_string($value) && isset($_SERVER[$name]) && is_string($_SERVER[$name])) {
      $value = $_SERVER[$name];
    }
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return $value === '' ? NULL : $value;
  }

  /**
   * Only the exact string "1" enables a flag.
   */
  public static function flag(string $name): bool {
    return self::env($name) === '1';
  }

  /**
   * Applies S3 File System overrides. Invalid input leaves local storage in place.
   *
   * @param array<string, mixed> $settings
   *   Drupal settings array.
   * @param array<string, mixed> $config
   *   Drupal config overrides.
   */
  public static function apply(array &$settings, array &$config): void {
    $settings['xedc_storage.boot'] = 'ok';
    $settings['xedc_storage.runtime_requested'] = self::flag('XEDC_STORAGE_RUNTIME');
    $settings['xedc_storage.runtime_eligible'] = FALSE;
    $settings['xedc_storage.settings_accepted'] = FALSE;
    // runtime_applied used to mean "the settings array was accepted". That
    // value is available before Drupal compiles the container, so it cannot
    // prove which stream wrapper this process will actually use.
    $settings['xedc_storage.runtime_applied'] = FALSE;
    $settings['xedc_storage.wrapper_verified'] = FALSE;
    $settings['xedc_storage.takeover_verified'] = FALSE;
    $settings['xedc_storage.runtime_error'] = '';
    $settings['xedc_storage.public_takeover'] = FALSE;
    $settings['xedc_storage.private_takeover'] = FALSE;
    $settings['s3fs.use_s3_for_public'] = FALSE;
    $settings['s3fs.use_s3_for_private'] = FALSE;
    $privatePath = isset($settings['file_private_path']) && is_string($settings['file_private_path']) ? $settings['file_private_path'] : NULL;
    $runtime = RuntimeState::read($privatePath);
    $settings['xedc_storage.public_mode'] = (string) $runtime['public_mode'];
    $settings['xedc_storage.public_provider'] = (string) $runtime['public_provider'];
    $settings['xedc_storage.runtime_version'] = (int) $runtime['version'];
    if ($runtime['public_mode'] === 'local') {
      $settings['xedc_storage.cdn_enabled'] = FALSE;
      $settings['xedc_storage.public_takeover'] = FALSE;
      $settings['xedc_storage.private_takeover'] = FALSE;
      return;
    }
    $fromRuntime = $runtime['public_mode'] === 'cloudflare_r2';
    if (!$settings['xedc_storage.runtime_requested'] && !$fromRuntime) {
      return;
    }

    $privatePath = isset($settings['file_private_path']) && is_string($settings['file_private_path']) ? $settings['file_private_path'] : NULL;
    $credentials = self::resolveBootCredentials($privatePath);
    $access = $credentials['access'];
    $secret = $credentials['secret'];
    $settings['xedc_storage.credential_source'] = $credentials['source'];
    $settings['xedc_storage.credential_profile'] = $credentials['profile'];
    $fileRuntime = self::readProfileRuntime($privatePath, $credentials['profile']);
    $bucket = self::env('STORAGE_BUCKET') ?? (is_array($fileRuntime) ? ($fileRuntime['bucket'] ?? NULL) : NULL);
    $region = self::env('STORAGE_REGION') ?? (is_array($fileRuntime) ? ($fileRuntime['region'] ?? NULL) : NULL);
    $endpoint = self::env('STORAGE_ENDPOINT') ?? (is_array($fileRuntime) ? self::blankToNull($fileRuntime['endpoint'] ?? NULL) : NULL);
    $problems = [];
    if ($credentials['blocked']) {
      $problems[] = $credentials['problem'];
    }
    if ($bucket === NULL || self::bucketError($bucket) !== NULL) {
      $problems[] = 'STORAGE_BUCKET 无效或缺失';
    }
    if ($region === NULL || self::regionError($region) !== NULL) {
      $problems[] = 'STORAGE_REGION 无效或缺失';
    }
    if (!$credentials['blocked'] && ($access === NULL || $secret === NULL)) {
      $problems[] = $credentials['source'] === 'global_env' || $credentials['source'] === 'none'
        ? 'STORAGE_ACCESS_KEY 或 STORAGE_SECRET_KEY 缺失'
        : '当前供应商配置没有可用凭证，没有改用其他配置';
    }
    elseif (!$credentials['blocked'] && (strlen((string) $access) < 8 || strlen((string) $secret) < 8)) {
      $problems[] = '存储凭证长度无效';
    }
    if ($endpoint !== NULL && self::endpointError($endpoint) !== NULL) {
      $problems[] = 'STORAGE_ENDPOINT 无效';
    }
    $publicFolder = self::env('STORAGE_PUBLIC_FOLDER') ?? (is_array($fileRuntime) ? (string) $fileRuntime['public_folder'] : 's3fs-public');
    $privateFolder = self::env('STORAGE_PRIVATE_FOLDER') ?? (is_array($fileRuntime) ? (string) $fileRuntime['private_folder'] : 's3fs-private');
    $rootFolder = self::env('STORAGE_ROOT_FOLDER') ?? (is_array($fileRuntime) ? (string) $fileRuntime['root_folder'] : '');
    if (self::folderError($publicFolder) !== NULL || self::folderError($privateFolder) !== NULL || $publicFolder === $privateFolder) {
      $problems[] = '公共或私有文件夹无效';
    }
    if ($rootFolder !== '' && self::folderError($rootFolder) !== NULL) {
      $problems[] = 'STORAGE_ROOT_FOLDER 无效';
    }
    $cacheControl = self::env('STORAGE_CACHE_CONTROL') ?? (is_array($fileRuntime) ? self::blankToNull($fileRuntime['cache_control'] ?? NULL) : NULL);
    if ($cacheControl !== NULL && self::cacheControlError($cacheControl) !== NULL) {
      $problems[] = 'STORAGE_CACHE_CONTROL 无效';
    }
    $cdnFlag = self::env('STORAGE_CDN_ENABLED');
    $cdnEnabled = $cdnFlag !== NULL ? $cdnFlag === '1' : (is_array($fileRuntime) && !empty($fileRuntime['cdn_enabled']));
    if ($fromRuntime && is_bool($runtime['cdn_enabled'])) {
      $cdnEnabled = $runtime['cdn_enabled'];
    }
    $cdn = $cdnEnabled ? (self::env('STORAGE_CDN_BASE_URL') ?? (is_array($fileRuntime) ? self::blankToNull($fileRuntime['cdn_base_url'] ?? NULL) : NULL)) : NULL;
    if ($cdn !== NULL && self::publicUrlError($cdn) !== NULL) {
      $problems[] = 'STORAGE_CDN_BASE_URL 无效';
    }
    $publicBase = self::env('STORAGE_PUBLIC_BASE_URL') ?? (is_array($fileRuntime) ? self::blankToNull($fileRuntime['public_base_url'] ?? NULL) : NULL);
    if ($publicBase !== NULL && self::publicUrlError($publicBase) !== NULL) {
      $problems[] = 'STORAGE_PUBLIC_BASE_URL 无效';
    }
    if ($problems !== []) {
      $settings['xedc_storage.runtime_error'] = '对象存储运行时未启用：' . implode('；', $problems) . '。网站继续使用本地文件系统。';
      return;
    }

    $uploadEnv = self::env('STORAGE_UPLOAD_AS_PRIVATE');
    $uploadPrivate = $uploadEnv !== NULL ? $uploadEnv !== '0' : (!is_array($fileRuntime) || !empty($fileRuntime['upload_as_private']));
    $pathStyleEnv = self::env('STORAGE_PATH_STYLE');
    $pathStyle = $pathStyleEnv !== NULL ? $pathStyleEnv === '1' : (is_array($fileRuntime) && !empty($fileRuntime['path_style']));
    $syncEnv = self::env('STORAGE_DISABLE_VERSION_SYNC');
    $disableVersionSync = $syncEnv !== NULL ? $syncEnv !== '0' : (!is_array($fileRuntime) || !empty($fileRuntime['disable_version_sync']));
    $settings['s3fs.access_key'] = $access;
    $settings['s3fs.secret_key'] = $secret;
    $settings['s3fs.upload_as_private'] = $uploadPrivate;
    $settings['xedc_storage.runtime_eligible'] = TRUE;
    $settings['xedc_storage.settings_accepted'] = TRUE;
    $settings['xedc_storage.runtime_applied'] = FALSE;
    $settings['xedc_storage.connection_source'] = self::connectionSource($fileRuntime);
    $settings['xedc_storage.provider'] = self::env('STORAGE_PROVIDER') ?? (is_array($fileRuntime) ? (string) $fileRuntime['provider'] : '');
    $settings['xedc_storage.bucket'] = $bucket;
    $settings['xedc_storage.region'] = strtolower((string) $region);
    $settings['xedc_storage.endpoint'] = $endpoint ?? '';
    $settings['xedc_storage.path_style'] = $pathStyle;
    $settings['xedc_storage.public_folder'] = $publicFolder;
    $settings['xedc_storage.private_folder'] = $privateFolder;
    $settings['xedc_storage.root_folder'] = $rootFolder;
    $settings['xedc_storage.public_enabled'] = self::flag('STORAGE_PUBLIC_ENABLED');
    $settings['xedc_storage.private_enabled'] = self::flag('STORAGE_PRIVATE_ENABLED');
    $settings['xedc_storage.cdn_enabled'] = $cdnEnabled;
    $settings['xedc_storage.public_base_url'] = $publicBase ?? '';
    $settings['xedc_storage.cdn_base_url'] = $cdn ?? '';

    $config['s3fs.settings']['bucket'] = $bucket;
    $config['s3fs.settings']['region'] = strtolower((string) $region);
    $config['s3fs.settings']['use_customhost'] = $endpoint !== NULL;
    if ($endpoint !== NULL) {
      $config['s3fs.settings']['hostname'] = $endpoint;
    }
    $config['s3fs.settings']['use_path_style_endpoint'] = $settings['xedc_storage.path_style'];
    $config['s3fs.settings']['use_https'] = TRUE;
    $config['s3fs.settings']['disable_version_sync'] = $disableVersionSync;
    $config['s3fs.settings']['disable_shared_config_files'] = TRUE;
    $config['s3fs.settings']['disable_cert_verify'] = FALSE;
    $config['s3fs.settings']['public_folder'] = $publicFolder;
    $config['s3fs.settings']['private_folder'] = $privateFolder;
    $config['s3fs.settings']['root_folder'] = $rootFolder;
    if ($cacheControl !== NULL) {
      $config['s3fs.settings']['cache_control_header'] = $cacheControl;
    }
    if ($cdn !== NULL) {
      $domain = self::domainFromUrl($cdn);
      if ($domain !== NULL) {
        $config['s3fs.settings']['use_cname'] = TRUE;
        $config['s3fs.settings']['domain'] = $domain;
      }
    }

    $takeover = self::flag('STORAGE_I_UNDERSTAND_TAKEOVER');
    $publicTakeover = $fromRuntime || ($takeover && self::flag('STORAGE_PUBLIC_TAKEOVER'));
    $privateTakeover = FALSE;
    $settings['s3fs.use_s3_for_public'] = $publicTakeover;
    $settings['s3fs.use_s3_for_private'] = $privateTakeover;
    $settings['xedc_storage.public_takeover'] = $publicTakeover;
    $settings['xedc_storage.private_takeover'] = $privateTakeover;
  }

  public static function bucketError(string $bucket): ?string {
    if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)) {
      return 'Bucket 名称无效。';
    }
    if (str_contains($bucket, '..') || str_contains($bucket, '.-') || str_contains($bucket, '-.')) {
      return 'Bucket 名称无效。';
    }
    if (filter_var($bucket, FILTER_VALIDATE_IP)) {
      return 'Bucket 名称不能是 IP 地址。';
    }
    return NULL;
  }

  public static function regionError(string $region): ?string {
    $region = strtolower($region);
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $region)) {
      return 'Region 无效。';
    }
    return NULL;
  }

  public static function folderError(string $folder): ?string {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/', $folder)) {
      return '文件夹名称无效。';
    }
    if ($folder === '.' || $folder === '..' || str_contains($folder, '..')) {
      return '文件夹名称无效。';
    }
    return NULL;
  }

  public static function cacheControlError(string $value): ?string {
    if (strlen($value) > 200 || preg_match('/[\r\n\x00]/', $value)) {
      return 'Cache-Control 无效。';
    }
    if (!preg_match('/^[A-Za-z0-9,=_ \t:.+*-]+$/', $value)) {
      return 'Cache-Control 无效。';
    }
    return NULL;
  }

  public static function endpointError(string $endpoint): ?string {
    return self::urlError($endpoint, TRUE);
  }

  public static function publicUrlError(string $url): ?string {
    return self::urlError($url, FALSE);
  }

  public static function domainFromUrl(string $url): ?string {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || !is_string($parts['host'])) {
      return NULL;
    }
    $domain = $parts['host'];
    if (isset($parts['port']) && is_int($parts['port']) && $parts['port'] !== 443) {
      $domain .= ':' . $parts['port'];
    }
    return $domain;
  }

  /**
   * Resolves a hostname before an outbound storage call.
   */
  public static function destinationError(string $endpoint): ?string {
    $syntax = self::endpointError($endpoint);
    if ($syntax !== NULL) {
      return $syntax;
    }
    $host = parse_url($endpoint, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
      return 'Endpoint 主机无效。';
    }
    if (self::isIp($host)) {
      return self::ipError($host);
    }
    $ips = @gethostbynamel($host);
    if (!is_array($ips) || $ips === []) {
      return '无法解析 Endpoint，已拒绝连接。';
    }
    foreach ($ips as $ip) {
      if (!is_string($ip)) {
        return '无法确认 Endpoint 地址，已拒绝连接。';
      }
      $error = self::ipError($ip);
      if ($error !== NULL) {
        return $error;
      }
    }
    return NULL;
  }

  public static function profileIdOk(string $id): bool {
    return (bool) preg_match('/^[a-z][a-z0-9_]{0,31}$/', $id);
  }

  /**
   * Selects credentials for one boot. Values stay in memory and are not logged.
   *
   * @return array{access:?string,secret:?string,source:string,profile:string,blocked:bool,problem:string}
   *   Credential decision. Access and secret are null unless usable.
   */
  public static function resolveBootCredentials(?string $privatePath): array {
    $profile = self::bootProfileId($privatePath);
    if ($profile === NULL) {
      $access = self::env('STORAGE_ACCESS_KEY');
      $secret = self::env('STORAGE_SECRET_KEY');
      return [
        'access' => $access,
        'secret' => $secret,
        'source' => ($access !== NULL || $secret !== NULL) ? 'global_env' : 'none',
        'profile' => '',
        'blocked' => FALSE,
        'problem' => '',
      ];
    }
    $stored = self::readProfileSecrets($privatePath, $profile);
    if ($stored['status'] === 'configured') {
      return [
        'access' => $stored['access'],
        'secret' => $stored['secret'],
        'source' => 'admin',
        'profile' => $profile,
        'blocked' => FALSE,
        'problem' => '',
      ];
    }
    if ($stored['status'] === 'incomplete') {
      return [
        'access' => NULL,
        'secret' => NULL,
        'source' => 'admin',
        'profile' => $profile,
        'blocked' => TRUE,
        'problem' => '当前供应商配置的后台凭证不完整，没有改用环境变量',
      ];
    }
    $meta = self::readProfileMeta($privatePath, $profile);
    if ($meta === NULL) {
      return [
        'access' => NULL,
        'secret' => NULL,
        'source' => 'none',
        'profile' => $profile,
        'blocked' => TRUE,
        'problem' => '当前供应商配置没有凭证说明，没有改用全局环境变量',
      ];
    }
    $access = self::envNameOk($meta['access_env']) ? self::env($meta['access_env']) : NULL;
    $secret = self::envNameOk($meta['secret_env']) ? self::env($meta['secret_env']) : NULL;
    $globalNames = $meta['access_env'] === 'STORAGE_ACCESS_KEY' && $meta['secret_env'] === 'STORAGE_SECRET_KEY';
    if (($access === NULL || $secret === NULL) && ($meta['use_global'] || $globalNames)) {
      $access = self::env('STORAGE_ACCESS_KEY');
      $secret = self::env('STORAGE_SECRET_KEY');
      $globalNames = TRUE;
    }
    if ($access !== NULL && $secret !== NULL) {
      return [
        'access' => $access,
        'secret' => $secret,
        'source' => $globalNames ? 'global_env' : 'profile_env',
        'profile' => $profile,
        'blocked' => FALSE,
        'problem' => '',
      ];
    }
    return [
      'access' => NULL,
      'secret' => NULL,
      'source' => $globalNames ? 'global_env' : 'profile_env',
      'profile' => $profile,
      'blocked' => !$globalNames,
      'problem' => $globalNames ? '' : '当前供应商配置没有可用的环境变量凭证，没有改用其他供应商',
    ];
  }

  /**
   * @return array{status:string,access:?string,secret:?string}
   *   File state for one profile. Secrets are returned only to trusted callers.
   */
  public static function readProfileSecrets(?string $privatePath, string $profileId): array {
    $empty = ['status' => 'none', 'access' => NULL, 'secret' => NULL];
    $dir = self::profileDirectory($privatePath, $profileId, FALSE);
    if ($dir === NULL) {
      return $empty;
    }
    $access = self::readSecretFile($dir . '/access_key');
    $secret = self::readSecretFile($dir . '/secret_key');
    if ($access['present'] && $secret['present']) {
      return ['status' => 'configured', 'access' => $access['value'], 'secret' => $secret['value']];
    }
    if ($access['present'] || $secret['present'] || $access['invalid'] || $secret['invalid']) {
      return ['status' => 'incomplete', 'access' => NULL, 'secret' => NULL];
    }
    return $empty;
  }

  /**
   * @return array{use_global:bool,access_env:string,secret_env:string}|null
   *   Non-secret profile metadata.
   */
  public static function readProfileMeta(?string $privatePath, string $profileId): ?array {
    $dir = self::profileDirectory($privatePath, $profileId, FALSE);
    if ($dir === NULL) {
      return NULL;
    }
    $path = $dir . '/meta.json';
    $real = self::containedPath($privatePath, $path);
    if ($real === NULL) {
      return NULL;
    }
    $raw = file_get_contents($real);
    if (!is_string($raw) || strlen($raw) > 2000) {
      return NULL;
    }
    try {
      $data = json_decode($raw, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    if (!is_array($data)) {
      return NULL;
    }
    $accessEnv = (string) ($data['access_env'] ?? '');
    $secretEnv = (string) ($data['secret_env'] ?? '');
    if (!self::envNameOk($accessEnv) || !self::envNameOk($secretEnv)) {
      return NULL;
    }
    return [
      'use_global' => !empty($data['use_global']),
      'access_env' => $accessEnv,
      'secret_env' => $secretEnv,
    ];
  }

  public static function writeProfileMeta(string $privatePath, string $profileId, bool $useGlobal, string $accessEnv, string $secretEnv): bool {
    if (!self::envNameOk($accessEnv) || !self::envNameOk($secretEnv)) {
      return FALSE;
    }
    $dir = self::profileDirectory($privatePath, $profileId, TRUE);
    if ($dir === NULL) {
      return FALSE;
    }
    $json = json_encode([
      'use_global' => $useGlobal,
      'access_env' => $accessEnv,
      'secret_env' => $secretEnv,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
      return FALSE;
    }
    return self::writeContainedFile($privatePath, $dir . '/meta.json', $json);
  }

  /**
   * Writes non-secret connection values for one profile. Secrets are rejected.
   *
   * @param array<string, mixed> $values
   *   Bucket, region, endpoint and related public settings.
   */
  public static function writeProfileRuntime(string $privatePath, string $profileId, array $values): bool {
    $runtime = self::normalizeRuntime($values);
    if ($runtime === NULL) {
      return FALSE;
    }
    $dir = self::profileDirectory($privatePath, $profileId, TRUE);
    if ($dir === NULL) {
      return FALSE;
    }
    $json = json_encode($runtime, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || self::containsSecretMaterial($json)) {
      return FALSE;
    }
    return self::writeContainedFile($privatePath, $dir . '/runtime.json', $json);
  }

  /**
   * @return array<string, mixed>|null
   *   Validated connection values, without credentials.
   */
  public static function readProfileRuntime(?string $privatePath, string $profileId): ?array {
    if ($profileId === '' || !self::profileIdOk($profileId)) {
      return NULL;
    }
    $dir = self::profileDirectory($privatePath, $profileId, FALSE);
    if ($dir === NULL) {
      return NULL;
    }
    $real = self::containedPath($privatePath, $dir . '/runtime.json');
    if ($real === NULL) {
      return NULL;
    }
    $size = filesize($real);
    if ($size === FALSE || $size < 2 || $size > 4000) {
      return NULL;
    }
    $raw = file_get_contents($real);
    if (!is_string($raw) || self::containsSecretMaterial($raw)) {
      return NULL;
    }
    try {
      $data = json_decode($raw, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($data) ? self::normalizeRuntime($data) : NULL;
  }

  public static function writeActiveProfile(string $privatePath, string $profileId): bool {
    if (!self::profileIdOk($profileId) || self::credentialRoot($privatePath, TRUE) === NULL) {
      return FALSE;
    }
    $root = self::credentialRoot($privatePath, FALSE);
    return $root !== NULL && self::writeContainedFile($privatePath, $root . '/active', $profileId);
  }

  public static function clearActiveProfile(string $privatePath, string $profileId): void {
    $root = self::credentialRoot($privatePath, FALSE);
    if ($root === NULL) {
      return;
    }
    $path = self::containedPath($privatePath, $root . '/active');
    if ($path === NULL) {
      return;
    }
    $current = trim((string) file_get_contents($path));
    if ($current === $profileId) {
      unlink($path);
    }
  }

  public static function deleteProfileSecrets(string $privatePath, string $profileId): void {
    $dir = self::profileDirectory($privatePath, $profileId, FALSE);
    if ($dir === NULL) {
      return;
    }
    foreach (['access_key', 'secret_key', 'access_key.bak', 'secret_key.bak'] as $name) {
      $path = self::containedPath($privatePath, $dir . '/' . $name);
      if ($path !== NULL) {
        unlink($path);
      }
    }
  }

  public static function deleteProfileFiles(string $privatePath, string $profileId): void {
    $dir = self::profileDirectory($privatePath, $profileId, FALSE);
    if ($dir === NULL) {
      self::clearActiveProfile($privatePath, $profileId);
      return;
    }
    foreach (['access_key', 'secret_key', 'meta.json', 'runtime.json', 'access_key.bak', 'secret_key.bak'] as $name) {
      $path = self::containedPath($privatePath, $dir . '/' . $name);
      if ($path !== NULL) {
        unlink($path);
      }
    }
    @rmdir($dir);
    self::clearActiveProfile($privatePath, $profileId);
  }

  public static function writeProfileSecret(string $privatePath, string $profileId, string $name, string $value): bool {
    if (!in_array($name, ['access_key', 'secret_key'], TRUE)) {
      return FALSE;
    }
    $dir = self::profileDirectory($privatePath, $profileId, TRUE);
    if ($dir === NULL) {
      return FALSE;
    }
    $path = $dir . '/' . $name;
    $backup = $path . '.bak';
    $hadBackup = FALSE;
    $existing = self::containedPath($privatePath, $path);
    if ($existing !== NULL) {
      $hadBackup = copy($existing, $backup);
      if ($hadBackup) {
        self::tighten($backup, FALSE);
      }
    }
    $written = self::writeContainedFile($privatePath, $path, $value);
    $check = $written ? self::readSecretFile($path) : ['present' => FALSE, 'invalid' => TRUE, 'value' => NULL];
    if ($written && $check['present'] && hash_equals($value, (string) $check['value'])) {
      if ($hadBackup && is_file($backup)) {
        unlink($backup);
      }
      return TRUE;
    }
    if ($hadBackup && is_file($backup)) {
      rename($backup, $path);
      self::tighten($path, FALSE);
    }
    return FALSE;
  }

  public static function testKey(string $key): ?string {
    $key = str_replace('\\', '/', $key);
    $key = ltrim($key, '/');
    if (!str_starts_with($key, self::TEST_PREFIX)) {
      return '只允许操作测试前缀 ' . self::TEST_PREFIX;
    }
    if (str_contains($key, '..') || str_contains($key, '//') || str_contains($key, "\0")) {
      return '对象键无效。';
    }
    if (strlen($key) > 240 || !preg_match('#^[A-Za-z0-9._/-]+$#', $key)) {
      return '对象键无效。';
    }
    return NULL;
  }

  /**
   * @param list<string> $secrets
   *   Values that must not survive in the text.
   */
  public static function redact(string $text, array $secrets = []): string {
    $text = preg_replace('/Authorization:\s*\S+/i', 'Authorization: [redacted]', $text) ?? $text;
    $text = preg_replace('/AWS4-HMAC-SHA256\S*/', 'AWS4-HMAC-SHA256 [redacted]', $text) ?? $text;
    $text = preg_replace('/X-Amz-[A-Za-z0-9-]+=([^&\s]+)/', 'X-Amz-[redacted]', $text) ?? $text;
    $text = preg_replace('/(?i)(aws_secret_access_key|secret_key|secretaccesskey)(["\'\s:=]+)[^"\'\s&]+/', '$1$2[redacted]', $text) ?? $text;
    foreach ($secrets as $secret) {
      if (is_string($secret) && strlen($secret) >= 8) {
        $text = str_replace($secret, '[redacted]', $text);
      }
    }
    if (strlen($text) > 300) {
      $text = substr($text, 0, 300);
    }
    return $text;
  }

  /**
   * @param list<string> $secrets
   *   Credential values to remove.
   */
  public static function safeError(\Throwable $error, array $secrets = []): string {
    if ($error instanceof \Aws\S3\Exception\S3Exception) {
      $text = trim((string) $error->getAwsErrorCode() . ' ' . (string) $error->getAwsErrorMessage());
    }
    else {
      $text = $error->getMessage();
    }
    $text = self::redact($text, $secrets);
    return $text === '' ? $error::class : $text;
  }

  public static function containsSecretMaterial(string $value): bool {
    return (bool) preg_match('/AKIA[0-9A-Z]{16}|ASIA[0-9A-Z]{16}|aws_secret_access_key|BEGIN [A-Z ]*PRIVATE KEY|X-Amz-Signature=/i', $value);
  }

  private static function blankToNull(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return $value === '' ? NULL : $value;
  }

  /**
   * @param array<string, mixed>|null $fileRuntime
   *   Saved profile connection, when present.
   */
  private static function connectionSource(?array $fileRuntime): string {
    if (!is_array($fileRuntime)) {
      return 'env';
    }
    foreach ([
      'STORAGE_BUCKET',
      'STORAGE_REGION',
      'STORAGE_ENDPOINT',
      'STORAGE_PATH_STYLE',
      'STORAGE_PUBLIC_FOLDER',
      'STORAGE_PRIVATE_FOLDER',
      'STORAGE_ROOT_FOLDER',
      'STORAGE_CDN_ENABLED',
      'STORAGE_CDN_BASE_URL',
      'STORAGE_PUBLIC_BASE_URL',
      'STORAGE_CACHE_CONTROL',
    ] as $name) {
      if (self::env($name) !== NULL) {
        return 'mixed';
      }
    }
    return 'profile';
  }

  /**
   * @param array<string, mixed> $values
   *   Untrusted connection values.
   *
   * @return array<string, mixed>|null
   *   Allow-listed values, or NULL when the connection is not usable.
   */
  private static function normalizeRuntime(array $values): ?array {
    $bucket = strtolower(trim((string) ($values['bucket'] ?? '')));
    $region = strtolower(trim((string) ($values['region'] ?? '')));
    $endpoint = trim((string) ($values['endpoint'] ?? ''));
    if ($bucket === '' || self::bucketError($bucket) !== NULL || $region === '' || self::regionError($region) !== NULL) {
      return NULL;
    }
    if ($endpoint !== '' && self::endpointError($endpoint) !== NULL) {
      return NULL;
    }
    $publicFolder = (string) ($values['public_folder'] ?? 's3fs-public');
    $privateFolder = (string) ($values['private_folder'] ?? 's3fs-private');
    $rootFolder = (string) ($values['root_folder'] ?? '');
    if (self::folderError($publicFolder) !== NULL || self::folderError($privateFolder) !== NULL || $publicFolder === $privateFolder) {
      return NULL;
    }
    if ($rootFolder !== '' && self::folderError($rootFolder) !== NULL) {
      return NULL;
    }
    $cache = trim((string) ($values['cache_control'] ?? ''));
    if ($cache !== '' && self::cacheControlError($cache) !== NULL) {
      return NULL;
    }
    $publicBase = trim((string) ($values['public_base_url'] ?? ''));
    $cdnBase = trim((string) ($values['cdn_base_url'] ?? ''));
    if (($publicBase !== '' && self::publicUrlError($publicBase) !== NULL) || ($cdnBase !== '' && self::publicUrlError($cdnBase) !== NULL)) {
      return NULL;
    }
    $provider = (string) ($values['provider'] ?? '');
    if (!preg_match('/^[a-z0-9_]{0,40}$/', $provider)) {
      return NULL;
    }
    foreach ([$bucket, $region, $endpoint, $publicFolder, $privateFolder, $rootFolder, $cache, $publicBase, $cdnBase, $provider] as $item) {
      if (self::containsSecretMaterial($item)) {
        return NULL;
      }
    }
    return [
      'provider' => $provider,
      'bucket' => $bucket,
      'region' => $region,
      'endpoint' => $endpoint,
      'path_style' => !empty($values['path_style']),
      'public_folder' => $publicFolder,
      'private_folder' => $privateFolder,
      'root_folder' => $rootFolder,
      'cache_control' => $cache,
      'public_base_url' => $publicBase,
      'cdn_base_url' => $cdnBase,
      'cdn_enabled' => !empty($values['cdn_enabled']),
      'upload_as_private' => !array_key_exists('upload_as_private', $values) || !empty($values['upload_as_private']),
      'disable_version_sync' => !array_key_exists('disable_version_sync', $values) || !empty($values['disable_version_sync']),
    ];
  }

  private static function urlError(string $url, bool $endpoint): ?string {
    if (strlen($url) > 200 || preg_match('/[\r\n\s]/', $url)) {
      return '地址无效。';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      return '地址无效。';
    }
    if (isset($parts['user']) || isset($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
      return '地址不能包含账号、密码或查询参数。';
    }
    $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
    if ($endpoint && $path !== '' && $path !== '/') {
      return 'API Endpoint 不能包含路径。公开访问域名请单独配置。';
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    if ($host === '' || self::hostSyntaxError($host) !== NULL) {
      return '地址主机无效。';
    }
    if (isset($parts['port']) && (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535)) {
      return '地址端口无效。';
    }
    if ($endpoint) {
      $loopback = self::isLoopbackHost($host);
      if ($scheme === 'http') {
        if (!$loopback || !self::flag('STORAGE_ALLOW_LOOPBACK')) {
          return '非本机 Endpoint 必须使用 HTTPS。本机 HTTP 需要 STORAGE_ALLOW_LOOPBACK=1。';
        }
      }
      elseif ($scheme !== 'https') {
        return 'Endpoint 协议无效。';
      }
      if (self::isIp($host)) {
        $ipError = self::ipError($host);
        if ($ipError !== NULL) {
          return $ipError;
        }
      }
      return NULL;
    }
    if ($scheme !== 'https') {
      return '公开访问地址必须使用 HTTPS。';
    }
    if (self::isIp($host) && self::ipError($host) !== NULL) {
      return '公开访问地址不能指向保留地址。';
    }
    return NULL;
  }

  private static function hostSyntaxError(string $host): ?string {
    $blocked = [
      '169.254.169.254',
      'metadata.google.internal',
      'metadata.internal',
    ];
    if (in_array($host, $blocked, TRUE) || str_contains($host, '169.254.169.254')) {
      return '禁止使用元数据地址。';
    }
    if (preg_match('/^\d+$/', $host)) {
      return '主机无效。';
    }
    if (preg_match('/^[0-9.]+$/', $host) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      return 'IP 地址无效。';
    }
    if (self::isIp($host)) {
      return NULL;
    }
    if (!preg_match('/^[a-z0-9][a-z0-9.-]{0,188}[a-z0-9]$/', $host) && $host !== 'localhost') {
      return '主机名无效。';
    }
    return NULL;
  }

  private static function ipError(string $ip): ?string {
    if (!self::isIp($ip)) {
      return 'IP 地址无效。';
    }
    if (self::isMetadataIp($ip)) {
      return '禁止连接元数据地址。';
    }
    $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($public !== FALSE) {
      return NULL;
    }
    if (self::isLoopback($ip)) {
      return self::flag('STORAGE_ALLOW_LOOPBACK') ? NULL : '本机地址需要 STORAGE_ALLOW_LOOPBACK=1。';
    }
    return self::flag('STORAGE_ALLOW_PRIVATE_ENDPOINT') ? NULL : '内网 Endpoint 需要 STORAGE_ALLOW_PRIVATE_ENDPOINT=1。';
  }

  private static function isIp(string $host): bool {
    return filter_var($host, FILTER_VALIDATE_IP) !== FALSE;
  }

  private static function isLoopbackHost(string $host): bool {
    return $host === 'localhost' || self::isLoopback($host);
  }

  private static function isLoopback(string $ip): bool {
    return $ip === '127.0.0.1' || $ip === '::1';
  }

  private static function isMetadataIp(string $ip): bool {
    return str_starts_with($ip, '169.254.') || $ip === 'fd00:ec2::254';
  }

  private static function envNameOk(string $name): bool {
    return (bool) preg_match('/^[A-Z][A-Z0-9_]{0,80}$/', $name);
  }

  private static function bootProfileId(?string $privatePath): ?string {
    $root = self::credentialRoot($privatePath, FALSE);
    if ($root !== NULL) {
      $active = self::containedPath($privatePath, $root . '/active');
      if ($active !== NULL) {
        $id = trim((string) file_get_contents($active));
        if (self::profileIdOk($id)) {
          return $id;
        }
      }
    }
    $fromEnv = self::env('STORAGE_PROVIDER');
    if ($fromEnv !== NULL && self::profileIdOk($fromEnv) && self::profileDirectory($privatePath, $fromEnv, FALSE) !== NULL) {
      return $fromEnv;
    }
    return NULL;
  }

  private static function credentialRoot(?string $privatePath, bool $create): ?string {
    if (!is_string($privatePath) || $privatePath === '' || str_contains($privatePath, "\0")) {
      return NULL;
    }
    $private = realpath($privatePath);
    if ($private === FALSE || !is_dir($private)) {
      return NULL;
    }
    $root = $private . DIRECTORY_SEPARATOR . 'xedc-storage-keys';
    if (!is_dir($root)) {
      if (!$create || !mkdir($root, 0700, TRUE) && !is_dir($root)) {
        return NULL;
      }
    }
    self::tighten($root, TRUE);
    $real = realpath($root);
    if ($real === FALSE || !str_starts_with($real, $private . DIRECTORY_SEPARATOR)) {
      return NULL;
    }
    return $real;
  }

  private static function profileDirectory(?string $privatePath, string $profileId, bool $create): ?string {
    if (!self::profileIdOk($profileId)) {
      return NULL;
    }
    $root = self::credentialRoot($privatePath, $create);
    if ($root === NULL) {
      return NULL;
    }
    $dir = $root . DIRECTORY_SEPARATOR . $profileId;
    if (!is_dir($dir)) {
      if (!$create || !mkdir($dir, 0700) && !is_dir($dir)) {
        return NULL;
      }
      self::tighten($dir, TRUE);
    }
    $real = realpath($dir);
    if ($real === FALSE || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
      return NULL;
    }
    return $real;
  }

  private static function containedPath(?string $privatePath, string $path): ?string {
    $root = self::credentialRoot($privatePath, FALSE);
    if ($root === NULL || !is_file($path)) {
      return NULL;
    }
    $real = realpath($path);
    if ($real === FALSE || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
      return NULL;
    }
    return $real;
  }

  /**
   * @return array{present:bool,invalid:bool,value:?string}
   *   File contents only when the file is a short credential.
   */
  private static function readSecretFile(string $path): array {
    $private = self::privatePathFromCredentialFile($path);
    $real = $private === NULL ? NULL : self::containedPath($private, $path);
    if ($real === NULL) {
      return ['present' => FALSE, 'invalid' => FALSE, 'value' => NULL];
    }
    $size = filesize($real);
    if ($size === FALSE || $size < 1 || $size > 512) {
      return ['present' => FALSE, 'invalid' => TRUE, 'value' => NULL];
    }
    $value = file_get_contents($real);
    if (!is_string($value)) {
      return ['present' => FALSE, 'invalid' => TRUE, 'value' => NULL];
    }
    $value = rtrim($value, "\r\n");
    if (strlen($value) < 8 || strlen($value) > 256 || preg_match('/[\r\n\x00]/', $value)) {
      return ['present' => FALSE, 'invalid' => TRUE, 'value' => NULL];
    }
    return ['present' => TRUE, 'invalid' => FALSE, 'value' => $value];
  }

  private static function privatePathFromCredentialFile(string $path): ?string {
    $real = realpath($path) ?: $path;
    $marker = DIRECTORY_SEPARATOR . 'xedc-storage-keys' . DIRECTORY_SEPARATOR;
    $position = strpos($real, $marker);
    if ($position === FALSE) {
      return NULL;
    }
    return substr($real, 0, $position);
  }

  private static function writeContainedFile(?string $privatePath, string $path, string $contents): bool {
    $root = self::credentialRoot($privatePath, FALSE);
    if ($root === NULL || str_contains($path, "\0")) {
      return FALSE;
    }
    $parent = realpath(dirname($path));
    if ($parent === FALSE || ($parent !== $root && !str_starts_with($parent, $root . DIRECTORY_SEPARATOR))) {
      return FALSE;
    }
    $target = $parent . DIRECTORY_SEPARATOR . basename($path);
    $temporary = $parent . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(8));
    if (file_put_contents($temporary, $contents, LOCK_EX) === FALSE) {
      @unlink($temporary);
      return FALSE;
    }
    self::tighten($temporary, FALSE);
    if (!rename($temporary, $target)) {
      @unlink($temporary);
      return FALSE;
    }
    self::tighten($target, FALSE);
    return TRUE;
  }

  private static function tighten(string $path, bool $directory): void {
    chmod($path, $directory ? 0700 : 0600);
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !function_exists('posix_getpwnam')) {
      return;
    }
    $user = posix_getpwnam('www-data');
    if (!is_array($user)) {
      return;
    }
    @chown($path, (int) $user['uid']);
    @chgrp($path, (int) $user['gid']);
  }

}
