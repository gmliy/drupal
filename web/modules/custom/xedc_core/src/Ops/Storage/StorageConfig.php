<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Non-secret object storage configuration.
 */
final class StorageConfig {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string, mixed>
   *   Defaults. No credentials and no live takeover.
   */
  public static function defaults(): array {
    return [
      'active_profile' => '',
      'presign_ttl' => 120,
      'multipart_cleanup' => 0,
      'profiles' => [],
      'policy' => [
        'cover_image' => 'public',
        'thumbnail' => 'public',
        'original_image' => 'inherit',
        'video_poster' => 'public',
        'video_file' => 'private',
        'zip_archive' => 'private',
        'vip_download' => 'private',
        'article_attachment' => 'inherit',
        'temporary' => 'local',
      ],
      'cdn' => [
        'enabled' => FALSE,
        'https_only' => TRUE,
        'image_domain' => '',
        'media_domain' => '',
        'base_url' => '',
        'cache_control' => 'public, max-age=31536000, immutable',
        'browser_cache' => 'public, max-age=31536000, immutable',
        'purge_mode' => 'manual',
      ],
      'pricing' => [
        'currency' => 'USD',
        'storage_price_per_gb_month' => 0,
        'download_price_per_gb' => 0,
        'request_price_per_thousand' => 0,
        'free_storage_gb' => 0,
        'free_download_gb' => 0,
        'free_requests' => 0,
        'other_monthly' => 0,
      ],
    ];
  }

  public function ensure(): void {
    $config = $this->configFactory->getEditable('xedc_core.storage');
    if (!$config->isNew() && is_array($config->get('policy'))) {
      return;
    }
    $config->setData(self::defaults());
    $config->save();
  }

  /**
   * @return array<string, mixed>
   *   Merged configuration.
   */
  public function data(): array {
    $saved = $this->configFactory->get('xedc_core.storage')->getRawData();
    return $this->merge(self::defaults(), is_array($saved) ? $saved : []);
  }

  public function activeId(): string {
    return (string) ($this->data()['active_profile'] ?? '');
  }

  /**
   * @return list<array<string, mixed>>
   *   Normalized profiles.
   */
  public function profiles(): array {
    $profiles = $this->data()['profiles'] ?? [];
    if (!is_array($profiles)) {
      return [];
    }
    $out = [];
    foreach ($profiles as $profile) {
      if (is_array($profile) && isset($profile['id'])) {
        $out[] = $this->normalizeProfile($profile);
      }
    }
    return $out;
  }

  /**
   * @return array<string, mixed>|null
   *   One profile.
   */
  public function profile(string $id): ?array {
    foreach ($this->profiles() as $profile) {
      if ($profile['id'] === $id) {
        return $profile;
      }
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $input
   *   Form values.
   *
   * @return list<string>
   *   Validation errors.
   */
  public function saveProfile(array $input, bool $isNew): array {
    $id = strtolower(trim((string) ($input['id'] ?? '')));
    if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $id)) {
      return ['配置 ID 只能使用小写字母、数字和下划线。'];
    }
    $existing = $this->profile($id);
    if ($isNew && $existing !== NULL) {
      return ['这个配置 ID 已经存在。'];
    }
    if (!$isNew && $existing === NULL) {
      return ['要编辑的供应商配置不存在。'];
    }
    $provider = (string) ($input['provider'] ?? '');
    if (ProviderCatalog::get($provider) === NULL) {
      return ['未知的供应商类型。'];
    }
    $profile = $this->normalizeProfile($input);
    $profile['id'] = $id;
    $profile['provider'] = $provider;
    if ($existing !== NULL) {
      $profile['access_key_id'] = (string) $existing['access_key_id'];
      $profile['secret_key_id'] = (string) $existing['secret_key_id'];
    }
    $errors = $this->profileErrors($profile);
    if ($errors !== []) {
      return $errors;
    }
    $profiles = [];
    $replaced = FALSE;
    foreach ($this->profiles() as $row) {
      if ($row['id'] === $id) {
        $profiles[] = $profile;
        $replaced = TRUE;
      }
      else {
        $profiles[] = $row;
      }
    }
    if (!$replaced) {
      $profiles[] = $profile;
    }
    $this->persist(['profiles' => $profiles]);
    return [];
  }

  public function setCredentialRefs(string $id, string $accessId, string $secretId): ?string {
    $profile = $this->profile($id);
    if ($profile === NULL) {
      return '供应商配置不存在。';
    }
    if (!$this->credentialRefOk($id, $accessId, 'access') || !$this->credentialRefOk($id, $secretId, 'secret')) {
      return '凭证引用无效。';
    }
    $profile['access_key_id'] = $accessId;
    $profile['secret_key_id'] = $secretId;
    $profiles = [];
    foreach ($this->profiles() as $row) {
      $profiles[] = $row['id'] === $id ? $profile : $row;
    }
    $this->persist(['profiles' => $profiles]);
    return NULL;
  }

  public function deleteProfile(string $id): ?string {
    if (\Drupal::hasService('xedc_core.storage_credentials')) {
      \Drupal::service('xedc_core.storage_credentials')->purge($id);
    }
    $found = FALSE;
    $profiles = [];
    foreach ($this->profiles() as $profile) {
      if ($profile['id'] === $id) {
        $found = TRUE;
        continue;
      }
      $profiles[] = $profile;
    }
    if (!$found) {
      return '供应商配置不存在。';
    }
    $values = ['profiles' => $profiles];
    if ($this->activeId() === $id) {
      $values['active_profile'] = '';
    }
    $this->persist($values);
    return NULL;
  }

  public function activate(string $id): ?string {
    if ($id !== '' && $this->profile($id) === NULL) {
      return '供应商配置不存在。';
    }
    $this->persist(['active_profile' => $id]);
    return NULL;
  }

  /**
   * @param array<string, string> $policy
   *   Policy selections.
   *
   * @return list<string>
   *   Errors.
   */
  public function savePolicy(array $policy, int $ttl): array {
    $allowed = ['public', 'private', 'inherit', 'local'];
    $defaults = self::defaults()['policy'];
    $clean = [];
    foreach ($defaults as $key => $value) {
      $choice = $policy[$key] ?? $value;
      if (!in_array($choice, $allowed, TRUE)) {
        return ['存储策略包含无效选项。'];
      }
      if ($key === 'temporary' && $choice !== 'local') {
        return ['临时文件必须保留在本地。'];
      }
      $clean[$key] = $choice;
    }
    if ($ttl < 30 || $ttl > 300) {
      return ['预签名有效期必须在 30 到 300 秒之间。'];
    }
    $this->persist([
      'policy' => $clean,
      'presign_ttl' => $ttl,
    ]);
    return [];
  }

  /**
   * @param array<string, mixed> $cdn
   *   CDN form values.
   *
   * @return list<string>
   *   Errors.
   */
  public function saveCdn(array $cdn): array {
    $clean = self::defaults()['cdn'];
    $clean['enabled'] = !empty($cdn['enabled']);
    $clean['https_only'] = TRUE;
    $clean['purge_mode'] = 'manual';
    foreach (['image_domain', 'media_domain', 'base_url'] as $key) {
      $value = trim((string) ($cdn[$key] ?? ''));
      if ($value !== '' && BootGuard::publicUrlError($value) !== NULL) {
        return [$key . ' 必须是不含账号和查询参数的 HTTPS 地址。'];
      }
      if (BootGuard::containsSecretMaterial($value)) {
        return ['公开域名里不能填写密钥。'];
      }
      $clean[$key] = $value;
    }
    foreach (['cache_control', 'browser_cache'] as $key) {
      $value = trim((string) ($cdn[$key] ?? ''));
      if ($value !== '' && BootGuard::cacheControlError($value) !== NULL) {
        return ['Cache-Control 无效。'];
      }
      $clean[$key] = $value;
    }
    $this->persist(['cdn' => $clean]);
    return [];
  }

  /**
   * @param array<string, mixed> $pricing
   *   Pricing form values.
   *
   * @return list<string>
   *   Errors.
   */
  public function savePricing(array $pricing): array {
    $clean = self::defaults()['pricing'];
    $currency = strtoupper(trim((string) ($pricing['currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
      return ['货币代码需要是 3 个字母。'];
    }
    $clean['currency'] = $currency;
    $floatKeys = [
      'storage_price_per_gb_month',
      'download_price_per_gb',
      'request_price_per_thousand',
      'free_storage_gb',
      'free_download_gb',
      'other_monthly',
    ];
    foreach ($floatKeys as $key) {
      if (!is_numeric($pricing[$key] ?? NULL) || (float) $pricing[$key] < 0) {
        return ['价格和免费额度不能是负数。'];
      }
      $clean[$key] = round((float) $pricing[$key], 6);
    }
    if (!is_numeric($pricing['free_requests'] ?? NULL) || (int) $pricing['free_requests'] < 0) {
      return ['免费请求额度无效。'];
    }
    $clean['free_requests'] = (int) $pricing['free_requests'];
    $this->persist(['pricing' => $clean]);
    return [];
  }

  public function saveMultipartCleanup(bool $enabled): void {
    $this->persist(['multipart_cleanup' => $enabled ? 1 : 0]);
  }

  /**
   * @param array<string, mixed> $profile
   *   Profile row.
   */
  public function envTemplate(array $profile): string {
    $lines = [
      'XEDC_STORAGE_RUNTIME=0',
      'STORAGE_PROVIDER=' . $profile['id'],
      'STORAGE_ACCESS_KEY=',
      'STORAGE_SECRET_KEY=',
      'STORAGE_BUCKET=' . $profile['bucket'],
      'STORAGE_REGION=' . $profile['region'],
      'STORAGE_ENDPOINT=' . $profile['endpoint'],
      'STORAGE_PUBLIC_BASE_URL=' . $profile['public_base_url'],
      'STORAGE_PATH_STYLE=' . ($profile['path_style'] ? '1' : '0'),
      'STORAGE_PUBLIC_ENABLED=0',
      'STORAGE_PRIVATE_ENABLED=0',
      'STORAGE_PUBLIC_TAKEOVER=0',
      'STORAGE_PRIVATE_TAKEOVER=0',
      'STORAGE_I_UNDERSTAND_TAKEOVER=0',
      'STORAGE_CDN_ENABLED=0',
      'STORAGE_CDN_BASE_URL=' . $profile['cdn_base_url'],
      'STORAGE_PUBLIC_FOLDER=' . $profile['public_folder'],
      'STORAGE_PRIVATE_FOLDER=' . $profile['private_folder'],
      'STORAGE_ROOT_FOLDER=' . $profile['root_folder'],
      'STORAGE_CACHE_CONTROL=' . $profile['cache_control'],
      'STORAGE_DISABLE_VERSION_SYNC=' . ($profile['disable_version_sync'] ? '1' : '0'),
      'STORAGE_UPLOAD_AS_PRIVATE=' . ($profile['upload_as_private'] ? '1' : '0'),
      'STORAGE_ALLOW_LOOPBACK=0',
      'STORAGE_ALLOW_PRIVATE_ENDPOINT=0',
    ];
    return implode("\n", $lines);
  }

  /**
   * @param array<string, mixed> $values
   *   Keys to replace.
   */
  private function persist(array $values): void {
    $data = $this->data();
    foreach ($values as $key => $value) {
      $data[$key] = $value;
    }
    unset($data['_core'], $data['langcode']);
    $config = $this->configFactory->getEditable('xedc_core.storage');
    $config->setData($data);
    $config->save();
  }

  /**
   * @param array<string, mixed> $defaults
   *   Default tree.
   * @param array<string, mixed> $saved
   *   Stored tree.
   *
   * @return array<string, mixed>
   *   Merged tree.
   */
  private function merge(array $defaults, array $saved): array {
    $out = $defaults;
    foreach ($saved as $key => $value) {
      if ($key === 'profiles' && is_array($value)) {
        $out['profiles'] = array_values($value);
        continue;
      }
      if (is_array($value) && isset($out[$key]) && is_array($out[$key]) && $this->isAssoc($out[$key])) {
        $out[$key] = $this->merge($out[$key], $value);
      }
      else {
        $out[$key] = $value;
      }
    }
    return $out;
  }

  /**
   * @param array<mixed> $value
   *   Array to inspect.
   */
  private function isAssoc(array $value): bool {
    return array_keys($value) !== range(0, count($value) - 1);
  }

  /**
   * @param array<string, mixed> $input
   *   Raw profile.
   *
   * @return array<string, mixed>
   *   Normalized profile.
   */
  private function normalizeProfile(array $input): array {
    $id = strtolower(trim((string) ($input['id'] ?? '')));
    $provider = (string) ($input['provider'] ?? 'custom');
    $catalog = ProviderCatalog::get($provider) ?? ProviderCatalog::get('custom');
    $upper = strtoupper($id);
    return [
      'id' => $id,
      'label' => $this->text($input['label'] ?? ($catalog['label'] ?? $id), 80),
      'provider' => $provider,
      'region' => strtolower($this->text($input['region'] ?? '', 32)),
      'endpoint' => $this->text($input['endpoint'] ?? '', 200),
      'bucket' => strtolower($this->text($input['bucket'] ?? '', 63)),
      'path_style' => array_key_exists('path_style', $input) ? (bool) $input['path_style'] : FALSE,
      'public_base_url' => $this->text($input['public_base_url'] ?? '', 200),
      'cdn_base_url' => $this->text($input['cdn_base_url'] ?? '', 200),
      'public_folder' => $this->text($input['public_folder'] ?? 's3fs-public', 63),
      'private_folder' => $this->text($input['private_folder'] ?? 's3fs-private', 63),
      'root_folder' => $this->text($input['root_folder'] ?? '', 63),
      'cache_control' => $this->text($input['cache_control'] ?? '', 200),
      'disable_version_sync' => array_key_exists('disable_version_sync', $input) ? (bool) $input['disable_version_sync'] : TRUE,
      'upload_as_private' => array_key_exists('upload_as_private', $input) ? (bool) $input['upload_as_private'] : TRUE,
      'access_key_env' => $this->text($input['access_key_env'] ?? ('STORAGE_PROFILE_' . $upper . '_ACCESS_KEY'), 81),
      'secret_key_env' => $this->text($input['secret_key_env'] ?? ('STORAGE_PROFILE_' . $upper . '_SECRET_KEY'), 81),
      'bucket_env' => $this->text($input['bucket_env'] ?? ('STORAGE_PROFILE_' . $upper . '_BUCKET'), 81),
      'access_key_id' => $this->text($input['access_key_id'] ?? '', 80),
      'secret_key_id' => $this->text($input['secret_key_id'] ?? '', 80),
      'notes' => $this->text($input['notes'] ?? '', 500),
    ];
  }

  /**
   * @param array<string, mixed> $profile
   *   Normalized profile.
   *
   * @return list<string>
   *   Errors.
   */
  private function profileErrors(array $profile): array {
    $errors = [];
    foreach ($profile as $value) {
      if (is_string($value) && BootGuard::containsSecretMaterial($value)) {
        return ['配置里不能保存访问密钥或签名。请只填写环境变量名称。'];
      }
    }
    if ($profile['label'] === '') {
      $errors[] = '请填写显示名称。';
    }
    if (BootGuard::regionError((string) $profile['region']) !== NULL) {
      $errors[] = 'Region 无效。';
    }
    if ($profile['endpoint'] !== '' && BootGuard::endpointError((string) $profile['endpoint']) !== NULL) {
      $errors[] = 'API Endpoint 必须是不含路径、账号和查询参数的地址。';
    }
    if ($profile['bucket'] !== '' && BootGuard::bucketError((string) $profile['bucket']) !== NULL) {
      $errors[] = 'Bucket 名称无效。';
    }
    foreach (['public_base_url', 'cdn_base_url'] as $key) {
      if ($profile[$key] !== '' && BootGuard::publicUrlError((string) $profile[$key]) !== NULL) {
        $errors[] = '公开访问地址必须是 HTTPS，并且不能包含查询参数。';
      }
    }
    if (BootGuard::folderError((string) $profile['public_folder']) !== NULL || BootGuard::folderError((string) $profile['private_folder']) !== NULL) {
      $errors[] = '公共或私有文件夹名称无效。';
    }
    if ($profile['public_folder'] === $profile['private_folder']) {
      $errors[] = '公共文件夹和私有文件夹不能相同。';
    }
    if ($profile['root_folder'] !== '' && BootGuard::folderError((string) $profile['root_folder']) !== NULL) {
      $errors[] = '根文件夹名称无效。';
    }
    if ($profile['cache_control'] !== '' && BootGuard::cacheControlError((string) $profile['cache_control']) !== NULL) {
      $errors[] = 'Cache-Control 无效。';
    }
    foreach (['access_key_env', 'secret_key_env', 'bucket_env'] as $key) {
      if (!CredentialResolver::envName((string) $profile[$key])) {
        $errors[] = '环境变量名称无效。';
      }
    }
    return $errors;
  }

  private function credentialRefOk(string $profileId, string $keyId, string $suffix): bool {
    if ($keyId === '') {
      return TRUE;
    }
    return $keyId === 'xedc_storage_' . $profileId . '_' . $suffix;
  }

  private function text(mixed $value, int $limit): string {
    $text = trim(strip_tags((string) $value));
    if (strlen($text) > $limit) {
      $text = substr($text, 0, $limit);
    }
    return $text;
  }

}
