<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\key\Entity\Key;
use Psr\Log\LoggerInterface;

/**
 * Stores per-profile credentials as Key module file references.
 *
 * The Key entity records the file path only. The key material stays in a
 * plaintext file outside the web root. This is not encryption.
 */
final class CredentialVault {

  public function __construct(
    protected StorageConfig $config,
    protected ModuleHandlerInterface $modules,
    protected EntityTypeManagerInterface $entities,
    protected LoggerInterface $logger,
  ) {}

  public function providerReady(): bool {
    return $this->modules->moduleExists('key')
      && $this->entities->hasDefinition('key')
      && $this->entities->getStorage('key')->getEntityType()->getProvider() === 'key';
  }

  /**
   * @param array<string, mixed> $profile
   *   Saved profile.
   *
   * @return array<string, mixed>
   *   Public status. It never includes a secret.
   */
  public function describe(array $profile): array {
    $stored = $this->storedState((string) ($profile['id'] ?? ''));
    $resolved = $this->resolve($profile);
    $status = (string) $stored['status'];
    if ($status === 'none') {
      $status = $resolved['source'] === 'none' ? 'missing' : 'env';
    }
    if (!$this->providerReady() && $stored['status'] !== 'none') {
      $status = 'provider_unavailable';
    }
    $sourceLabel = self::sourceLabel((string) $resolved['source']);
    if (empty($resolved['ok']) && ($resolved['source'] ?? '') === 'admin') {
      $sourceLabel = '没有改用环境变量';
    }
    return [
      'status' => $status,
      'label' => self::statusLabel($status),
      'source' => (string) $resolved['source'],
      'source_label' => $sourceLabel,
      'access_hint' => (string) ($stored['access_hint'] ?? ''),
      'secret_configured' => !empty($stored['secret_present']),
      'permissions_ok' => !empty($stored['permissions_ok']),
      'encrypted' => FALSE,
      'provider' => 'file',
      'provider_ready' => $this->providerReady(),
    ];
  }

  /**
   * @param array<string, mixed> $profile
   *   Saved profile.
   *
   * @return array<string, mixed>
   *   Connection values. The key values stay in this array only.
   */
  public function resolve(array $profile): array {
    $id = (string) ($profile['id'] ?? '');
    $stored = BootGuard::readProfileSecrets($this->privatePath(), $id);
    if ($stored['status'] === 'incomplete' || ($stored['status'] === 'none' && $this->hasRefs($profile) && !$this->providerReady())) {
      $resolved = CredentialResolver::resolve($profile, NULL, NULL, 'admin');
      $resolved['ok'] = FALSE;
      $resolved['connection']['access_key'] = '';
      $resolved['connection']['secret_key'] = '';
      if ($stored['status'] === 'incomplete') {
        $resolved['missing'] = ['后台凭证不完整'];
        $resolved['errors'][] = '后台凭证不完整，没有改用环境变量。';
      }
      else {
        $resolved['missing'] = ['Key Provider 不可用'];
        $resolved['errors'][] = 'Key Provider 不可用，没有改用环境变量。';
      }
      return $resolved;
    }
    if ($stored['status'] === 'configured') {
      if (!$this->providerReady() || !$this->refsMatch($profile, $id)) {
        $resolved = CredentialResolver::resolve($profile, NULL, NULL, 'admin');
        $resolved['ok'] = FALSE;
        $resolved['connection']['access_key'] = '';
        $resolved['connection']['secret_key'] = '';
        $resolved['missing'] = ['Key Provider 不可用'];
        $resolved['errors'][] = '后台凭证文件存在，但 Key 引用不可用，没有改用环境变量。';
        return $resolved;
      }
      $verified = $this->readThroughKey($profile);
      if ($verified === NULL) {
        $resolved = CredentialResolver::resolve($profile, NULL, NULL, 'admin');
        $resolved['ok'] = FALSE;
        $resolved['connection']['access_key'] = '';
        $resolved['connection']['secret_key'] = '';
        $resolved['missing'] = ['Key Provider 不可用'];
        $resolved['errors'][] = 'Key 文件 Provider 不能读取凭证，没有改用环境变量。';
        return $resolved;
      }
      return CredentialResolver::resolve($profile, $verified['access'], $verified['secret'], 'admin');
    }
    return CredentialResolver::resolve($profile);
  }

  /**
   * @return list<string>
   *   Errors. An empty list means the stored credentials were left unchanged or updated.
   */
  public function save(string $profileId, string $access, string $secret): array {
    $profile = $this->config->profile($profileId);
    if ($profile === NULL) {
      return ['供应商配置不存在。'];
    }
    if (!$this->providerReady()) {
      return ['Key 模块的 File Provider 不可用。后台没有保存密钥，请继续使用环境变量。'];
    }
    $access = trim($access);
    $secret = trim($secret);
    $accessError = $this->valueError($access, 'Access Key ID');
    $secretError = $this->valueError($secret, 'Secret Access Key');
    if ($accessError !== NULL || $secretError !== NULL) {
      return array_values(array_filter([$accessError, $secretError]));
    }
    $existing = BootGuard::readProfileSecrets($this->privatePath(), $profileId);
    $nextAccess = $access !== '' ? $access : $existing['access'];
    $nextSecret = $secret !== '' ? $secret : $existing['secret'];
    if ($access === '' && $secret === '') {
      return ['没有新的凭证输入，原有凭证保持不变。'];
    }
    if (!is_string($nextAccess) || !is_string($nextSecret)) {
      return ['配置不完整。新增凭证时需要同时填写 Access Key ID 和 Secret Access Key。'];
    }
    $private = $this->privatePath();
    if ($private === NULL) {
      return ['私有文件目录不可用，后台没有保存密钥。'];
    }
    if ($access !== '' && !BootGuard::writeProfileSecret($private, $profileId, 'access_key', $access)) {
      $this->logger->error('对象存储 Access Key 文件写入失败。');
      return ['Access Key ID 没有写入。原有凭证已保留。'];
    }
    if ($secret !== '' && !BootGuard::writeProfileSecret($private, $profileId, 'secret_key', $secret)) {
      $this->logger->error('对象存储 Secret Access Key 文件写入失败。');
      return ['Secret Access Key 没有写入。请重新保存这对凭证。'];
    }
    $accessId = 'xedc_storage_' . $profileId . '_access';
    $secretId = 'xedc_storage_' . $profileId . '_secret';
    if (!$this->ensureKey($accessId, $profileId, 'Access Key ID', $private . '/xedc-storage-keys/' . $profileId . '/access_key')
      || !$this->ensureKey($secretId, $profileId, 'Secret Access Key', $private . '/xedc-storage-keys/' . $profileId . '/secret_key')) {
      $this->logger->error('对象存储凭证的 Key 引用保存失败。');
      return ['密钥文件已处理，但 Key 引用保存失败。配置里没有写入密钥明文。'];
    }
    $error = $this->config->setCredentialRefs($profileId, $accessId, $secretId);
    if ($error !== NULL) {
      return [$error];
    }
    $check = $this->readThroughKey($this->config->profile($profileId) ?? $profile);
    if ($check === NULL || !hash_equals($nextAccess, $check['access']) || !hash_equals($nextSecret, $check['secret'])) {
      $this->logger->error('对象存储凭证保存后不能通过 Key Provider 读回。');
      return ['凭证保存后不能通过 Key Provider 读回。没有把密钥写入配置。'];
    }
    $this->logger->info('已保存对象存储供应商 @profile 的后台凭证。', ['@profile' => $profileId]);
    return [];
  }

  public function clear(string $profileId): ?string {
    $profile = $this->config->profile($profileId);
    if ($profile === NULL) {
      return '供应商配置不存在。';
    }
    $this->deleteKeys($profileId);
    $private = $this->privatePath();
    if ($private !== NULL) {
      BootGuard::deleteProfileSecrets($private, $profileId);
      $this->restoreMeta($profile, $private);
    }
    $error = $this->config->setCredentialRefs($profileId, '', '');
    $this->logger->info('已清除对象存储供应商 @profile 的后台凭证。', ['@profile' => $profileId]);
    return $error;
  }

  public function purge(string $profileId): void {
    if (!BootGuard::profileIdOk($profileId)) {
      return;
    }
    $this->deleteKeys($profileId);
    $private = $this->privatePath();
    if ($private !== NULL) {
      BootGuard::deleteProfileFiles($private, $profileId);
    }
    if ($this->config->profile($profileId) !== NULL) {
      $this->config->setCredentialRefs($profileId, '', '');
    }
  }

  public function writeMeta(string $profileId): void {
    $profile = $this->config->profile($profileId);
    $private = $this->privatePath();
    if ($profile === NULL || $private === NULL) {
      return;
    }
    $this->restoreMeta($profile, $private);
  }

  public function markActive(string $profileId): void {
    $private = $this->privatePath();
    if ($private === NULL || $this->config->profile($profileId) === NULL) {
      return;
    }
    $this->writeMeta($profileId);
    BootGuard::writeActiveProfile($private, $profileId);
  }

  /**
   * @param array<string, mixed> $profile
   *   Profile row.
   */
  private function restoreMeta(array $profile, string $private): void {
    $accessEnv = (string) $profile['access_key_env'];
    $secretEnv = (string) $profile['secret_key_env'];
    $useGlobal = $accessEnv === 'STORAGE_ACCESS_KEY' && $secretEnv === 'STORAGE_SECRET_KEY';
    BootGuard::writeProfileMeta($private, (string) $profile['id'], $useGlobal, $accessEnv, $secretEnv);
    if (!BootGuard::writeProfileRuntime($private, (string) $profile['id'], [
      'provider' => (string) $profile['provider'],
      'bucket' => (string) $profile['bucket'],
      'region' => (string) $profile['region'],
      'endpoint' => (string) $profile['endpoint'],
      'path_style' => !empty($profile['path_style']),
      'public_folder' => (string) $profile['public_folder'],
      'private_folder' => (string) $profile['private_folder'],
      'root_folder' => (string) $profile['root_folder'],
      'cache_control' => (string) $profile['cache_control'],
      'public_base_url' => (string) $profile['public_base_url'],
      'cdn_base_url' => (string) $profile['cdn_base_url'],
      'cdn_enabled' => FALSE,
      'upload_as_private' => !empty($profile['upload_as_private']),
      'disable_version_sync' => !array_key_exists('disable_version_sync', $profile) || !empty($profile['disable_version_sync']),
    ])) {
      $this->logger->error('对象存储供应商 @profile 的运行时参数没有写入。密钥没有写入这个文件。', ['@profile' => $profile['id']]);
    }
  }

  /**
   * @param array<string, mixed> $profile
   *   Profile row.
   *
   * @return array{access:string,secret:string}|null
   *   Values read from the Key module.
   */
  private function readThroughKey(array $profile): ?array {
    $access = $this->keyValue((string) ($profile['access_key_id'] ?? ''));
    $secret = $this->keyValue((string) ($profile['secret_key_id'] ?? ''));
    if ($access === NULL || $secret === NULL) {
      return NULL;
    }
    return ['access' => $access, 'secret' => $secret];
  }

  private function keyValue(string $id): ?string {
    if (!$this->providerReady() || $id === '') {
      return NULL;
    }
    $key = $this->entities->getStorage('key')->load($id);
    if (!$key instanceof Key || $key->getKeyProvider()->getPluginId() !== 'file') {
      return NULL;
    }
    $value = $key->getKeyValue(TRUE);
    if (!is_string($value) || strlen($value) < 8 || strlen($value) > 256) {
      return NULL;
    }
    return $value;
  }

  private function ensureKey(string $id, string $profileId, string $label, string $path): bool {
    $real = realpath($path);
    $root = realpath((string) $this->privatePath() . '/xedc-storage-keys');
    if ($real === FALSE || $root === FALSE || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
      return FALSE;
    }
    $storage = $this->entities->getStorage('key');
    $existing = $storage->load($id);
    if ($existing !== NULL && !$existing instanceof Key) {
      return FALSE;
    }
    if ($existing instanceof Key && $existing->getKeyProvider()->getPluginId() !== 'file') {
      return FALSE;
    }
    if ($existing instanceof Key) {
      $existing->delete();
    }
    $key = $storage->create([
      'id' => $id,
      'label' => 'XEDC ' . $profileId . ' ' . $label,
      'description' => 'XEDC object storage file reference. The key value is not stored in configuration.',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'file',
      'key_provider_settings' => [
        'file_location' => $real,
        'base64_encoded' => FALSE,
        'strip_line_breaks' => TRUE,
      ],
      'key_input' => 'none',
      'key_input_settings' => [],
    ]);
    $key->save();
    return TRUE;
  }

  private function deleteKeys(string $profileId): void {
    if (!$this->providerReady()) {
      return;
    }
    $storage = $this->entities->getStorage('key');
    foreach (['access', 'secret'] as $suffix) {
      $id = 'xedc_storage_' . $profileId . '_' . $suffix;
      $key = $storage->load($id);
      if (!$key instanceof Key || $key->getKeyProvider()->getPluginId() !== 'file') {
        continue;
      }
      $location = (string) ($key->get('key_provider_settings')['file_location'] ?? '');
      $root = realpath((string) $this->privatePath() . '/xedc-storage-keys');
      $real = $location !== '' ? realpath($location) : FALSE;
      if ($root === FALSE || $real === FALSE || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        continue;
      }
      $key->delete();
    }
  }

  /**
   * @return array<string, mixed>
   *   File status without the secret value.
   */
  private function storedState(string $profileId): array {
    $private = $this->privatePath();
    $stored = BootGuard::readProfileSecrets($private, $profileId);
    $hint = '';
    if (is_string($stored['access']) && $stored['access'] !== '') {
      $hint = self::mask($stored['access']);
    }
    return [
      'status' => $stored['status'] === 'configured' ? 'configured' : ($stored['status'] === 'incomplete' ? 'incomplete' : 'none'),
      'access_hint' => $hint,
      'secret_present' => $stored['status'] === 'configured',
      'permissions_ok' => $private === NULL || $this->permissionsOk($private, $profileId),
    ];
  }

  private function permissionsOk(?string $private, string $profileId): bool {
    if ($private === NULL) {
      return FALSE;
    }
    $root = realpath($private . '/xedc-storage-keys/' . $profileId);
    if ($root === FALSE) {
      return TRUE;
    }
    foreach (['access_key', 'secret_key'] as $name) {
      $path = $root . DIRECTORY_SEPARATOR . $name;
      if (!is_file($path)) {
        continue;
      }
      $mode = fileperms($path);
      if ($mode !== FALSE && ($mode & 0077) !== 0) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * @param array<string, mixed> $profile
   *   Profile row.
   */
  private function hasRefs(array $profile): bool {
    return (string) ($profile['access_key_id'] ?? '') !== '' || (string) ($profile['secret_key_id'] ?? '') !== '';
  }

  /**
   * @param array<string, mixed> $profile
   *   Profile row.
   */
  private function refsMatch(array $profile, string $profileId): bool {
    return (string) ($profile['access_key_id'] ?? '') === 'xedc_storage_' . $profileId . '_access'
      && (string) ($profile['secret_key_id'] ?? '') === 'xedc_storage_' . $profileId . '_secret';
  }

  private function valueError(string $value, string $label): ?string {
    if ($value === '') {
      return NULL;
    }
    if (strlen($value) < 8 || strlen($value) > 256 || preg_match('/[\r\n\x00]/', $value)) {
      return $label . '无效。';
    }
    return NULL;
  }

  private function privatePath(): ?string {
    $path = Settings::get('file_private_path');
    if (!is_string($path) || $path === '' || !is_dir($path)) {
      return NULL;
    }
    $real = realpath($path);
    return $real === FALSE ? NULL : $real;
  }

  public static function mask(string $value): string {
    $length = strlen($value);
    if ($length < 8) {
      return '已配置';
    }
    return substr($value, 0, 4) . str_repeat('•', 8) . substr($value, -2);
  }

  public static function statusLabel(string $status): string {
    return match ($status) {
      'configured' => '已配置',
      'incomplete' => '配置不完整',
      'env' => '环境变量提供',
      'provider_unavailable' => 'Key Provider 不可用',
      default => '未配置',
    };
  }

  public static function sourceLabel(string $source): string {
    return match ($source) {
      'admin' => '实际采用后台凭证',
      'profile_env' => '实际采用供应商环境变量',
      'global_env' => '实际采用全局环境变量',
      default => '没有可用凭证',
    };
  }

}
