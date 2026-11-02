<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\xedc_core\Ops\OpsFormat;

/**
 * Local runtime and file-entity statistics. This does not list a bucket.
 */
final class ObjectMonitor {

  public function __construct(
    protected Connection $database,
    protected CacheBackendInterface $cache,
    protected DateFormatterInterface $dateFormatter,
    protected FileSystemInterface $fileSystem,
    protected ModuleExtensionList $modules,
    protected ConfigFactoryInterface $configFactory,
    protected StorageJournal $journal,
    protected StorageConfig $config,
    protected CredentialVault $credentials,
  ) {}

  /**
   * @return array<string, mixed>
   *   Versions and filesystem facts.
   */
  public function versions(): array {
    $s3fs = '';
    if ($this->modules->exists('s3fs')) {
      $info = $this->modules->getExtensionInfo('s3fs');
      $s3fs = (string) ($info['version'] ?? '');
    }
    $cache = Settings::get('cache', []);
    return [
      'drupal' => \Drupal::VERSION,
      'php' => PHP_VERSION,
      's3fs' => $s3fs !== '' ? $s3fs : '未启用',
      'aws' => class_exists(\Aws\Sdk::class) ? (string) \Aws\Sdk::VERSION : '未安装',
      'redis' => is_array($cache) && (($cache['default'] ?? '') === 'cache.backend.redis') ? '默认缓存使用 Redis' : '默认缓存不是 Redis',
      'public_path' => (string) ($this->fileSystem->realpath('public://') ?: '不可用'),
      'private_path' => (string) (Settings::get('file_private_path') ?: '未配置'),
      'temporary_path' => $this->fileSystem->getTempDirectory(),
    ];
  }

  /**
   * @return array<string, mixed>
   *   Live settings without secrets.
   */
  public function runtime(): array {
    $endpoint = (string) Settings::get('xedc_storage.endpoint', '');
    $host = $endpoint === '' ? '' : (string) (parse_url($endpoint, PHP_URL_HOST) ?: '');
    return [
      'boot' => (string) Settings::get('xedc_storage.boot', 'missing'),
      'requested' => (bool) Settings::get('xedc_storage.runtime_requested', FALSE),
      'applied' => (bool) Settings::get('xedc_storage.runtime_applied', FALSE),
      'error' => (string) Settings::get('xedc_storage.runtime_error', ''),
      'provider' => (string) Settings::get('xedc_storage.provider', ''),
      'bucket' => (string) Settings::get('xedc_storage.bucket', ''),
      'region' => (string) Settings::get('xedc_storage.region', ''),
      'endpoint_host' => $host,
      'path_style' => (bool) Settings::get('xedc_storage.path_style', FALSE),
      'public_takeover' => (bool) Settings::get('s3fs.use_s3_for_public', FALSE),
      'private_takeover' => (bool) Settings::get('s3fs.use_s3_for_private', FALSE),
      'public_enabled' => (bool) Settings::get('xedc_storage.public_enabled', FALSE),
      'private_enabled' => (bool) Settings::get('xedc_storage.private_enabled', FALSE),
      'cdn_enabled' => (bool) Settings::get('xedc_storage.cdn_enabled', FALSE),
      'cdn_base_url' => (string) Settings::get('xedc_storage.cdn_base_url', ''),
      'public_base_url' => (string) Settings::get('xedc_storage.public_base_url', ''),
      'upload_as_private' => (bool) Settings::get('s3fs.upload_as_private', FALSE),
      'access_key' => Settings::get('s3fs.access_key') ? '已设置' : '未设置',
      'secret_key' => Settings::get('s3fs.secret_key') ? '已设置' : '未设置',
      'credential_source' => CredentialVault::sourceLabel((string) Settings::get('xedc_storage.credential_source', '')),
      'credential_profile' => (string) Settings::get('xedc_storage.credential_profile', ''),
    ];
  }

  /**
   * @return array<string, mixed>
   *   Cached Drupal-side census.
   */
  public function census(bool $refresh = FALSE): array {
    $cached = $refresh ? FALSE : $this->cache->get('xedc_storage.census');
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }
    $data = $this->buildCensus();
    $this->cache->set('xedc_storage.census', $data, time() + 900, ['file_list']);
    return $data;
  }

  /**
   * @return list<array<string, mixed>>
   *   Recent redacted events.
   */
  public function errors(): array {
    $rows = [];
    foreach ($this->journal->recent(8) as $event) {
      $rows[] = [
        'time' => $this->format((int) ($event['time'] ?? 0)),
        'level' => (string) ($event['level'] ?? ''),
        'message' => (string) ($event['message'] ?? ''),
      ];
    }
    return $rows;
  }

  /**
   * @return array<string, int>
   *   Counters from this center.
   */
  public function counters(): array {
    $counters = $this->journal->counters() + [
      'upload_ok' => 0,
      'upload_failed' => 0,
      'read_ok' => 0,
      'read_failed' => 0,
      'delete_ok' => 0,
      'delete_failed' => 0,
    ];
    return array_map('intval', $counters);
  }

  public function format(int $timestamp): string {
    if ($timestamp <= 0) {
      return '从未';
    }
    return $this->dateFormatter->format($timestamp, 'short');
  }

  /**
   * @return list<array<string, mixed>>
   *   Profiles safe to render.
   */
  public function profileCards(): array {
    $tests = $this->journal->all()['tests'] ?? [];
    $tests = is_array($tests) ? $tests : [];
    $active = $this->config->activeId();
    $cards = [];
    foreach ($this->config->profiles() as $profile) {
      $resolved = $this->credentials->resolve($profile);
      $described = $this->credentials->describe($profile);
      unset($resolved['connection']);
      $catalog = ProviderCatalog::get((string) $profile['provider']);
      $test = is_array($tests[$profile['id']] ?? NULL) ? $tests[$profile['id']] : [];
      $cards[] = [
        'id' => $profile['id'],
        'label' => $profile['label'],
        'provider' => (string) ($catalog['label'] ?? $profile['provider']),
        'provider_id' => $profile['provider'],
        'region' => $profile['region'],
        'endpoint' => $profile['endpoint'] === '' ? '未填写' : (string) (parse_url((string) $profile['endpoint'], PHP_URL_HOST) ?: '已填写'),
        'bucket' => $resolved['bucket'] !== '' ? $resolved['bucket'] : '未配置',
        'path_style' => $profile['path_style'] ? 'Path-style' : 'Virtual-hosted',
        'active' => $profile['id'] === $active,
        'access_key' => $resolved['access_present'] ? '已设置' : '未设置',
        'secret_key' => $resolved['secret_present'] ? '已设置' : '未设置',
        'access_hint' => (string) ($described['access_hint'] !== '' ? $described['access_hint'] : ($resolved['access_present'] ? '已设置' : '未配置')),
        'credential_label' => (string) $described['label'],
        'credential_source_label' => (string) $described['source_label'],
        'access_key_env' => $profile['access_key_env'],
        'secret_key_env' => $profile['secret_key_env'],
        'bucket_env' => $profile['bucket_env'],
        'public_base_url' => $profile['public_base_url'],
        'cdn_base_url' => $profile['cdn_base_url'],
        'test_status' => (string) ($test['status'] ?? 'unverified'),
        'test_message' => (string) ($test['message'] ?? '尚未测试'),
        'test_time' => $this->format((int) ($test['checked'] ?? 0)),
        'env_template' => $this->config->envTemplate($profile),
      ];
    }
    return $cards;
  }

  /**
   * @return array<string, mixed>
   *   Census payload.
   */
  private function buildCensus(): array {
    $row = $this->database->query("SELECT
      COUNT(*) AS files,
      COALESCE(SUM(filesize), 0) AS bytes,
      COALESCE(SUM(CASE WHEN uri LIKE 'public://%' THEN 1 ELSE 0 END), 0) AS public_files,
      COALESCE(SUM(CASE WHEN uri LIKE 'private://%' THEN 1 ELSE 0 END), 0) AS private_files,
      COALESCE(SUM(CASE WHEN uri LIKE 'temporary://%' THEN 1 ELSE 0 END), 0) AS temporary_files,
      COALESCE(SUM(CASE WHEN uri LIKE 's3://%' THEN 1 ELSE 0 END), 0) AS s3_files,
      COALESCE(SUM(CASE WHEN uri LIKE 'public://%' THEN filesize ELSE 0 END), 0) AS public_bytes,
      COALESCE(SUM(CASE WHEN uri LIKE 'private://%' THEN filesize ELSE 0 END), 0) AS private_bytes,
      COALESCE(SUM(CASE WHEN uri LIKE 's3://%' THEN filesize ELSE 0 END), 0) AS s3_bytes,
      COALESCE(SUM(CASE WHEN filemime LIKE 'image/%' THEN filesize ELSE 0 END), 0) AS image_bytes,
      COALESCE(SUM(CASE WHEN filemime LIKE 'video/%' THEN filesize ELSE 0 END), 0) AS video_bytes,
      COALESCE(SUM(CASE WHEN filemime LIKE '%zip%' OR filemime LIKE '%7z%' THEN filesize ELSE 0 END), 0) AS archive_bytes
      FROM {file_managed}")->fetchAssoc();
    $row = is_array($row) ? $row : [];
    $styles = $this->configFactory->listAll('image.style.');
    $webp = 0;
    foreach ($styles as $name) {
      $encoded = json_encode($this->configFactory->get($name)->getRawData());
      if (is_string($encoded) && str_contains($encoded, 'webp')) {
        $webp++;
      }
    }
    $nodes = [];
    try {
      $nodeRows = $this->database->query('SELECT type, COUNT(*) AS total FROM {node_field_data} GROUP BY type')->fetchAllKeyed();
      foreach ($nodeRows as $type => $total) {
        $nodes[] = ['type' => (string) $type, 'total' => (int) $total];
      }
    }
    catch (\Throwable) {
      $nodes = [];
    }
    return [
      'source' => 'Drupal 文件实体统计，不是对象存储清单，也不是供应商账单。',
      'files' => (int) ($row['files'] ?? 0),
      'bytes' => OpsFormat::bytes((int) ($row['bytes'] ?? 0)),
      'bytes_raw' => (int) ($row['bytes'] ?? 0),
      'public_files' => (int) ($row['public_files'] ?? 0),
      'private_files' => (int) ($row['private_files'] ?? 0),
      'temporary_files' => (int) ($row['temporary_files'] ?? 0),
      's3_files' => (int) ($row['s3_files'] ?? 0),
      'public_bytes' => OpsFormat::bytes((int) ($row['public_bytes'] ?? 0)),
      'private_bytes' => OpsFormat::bytes((int) ($row['private_bytes'] ?? 0)),
      's3_bytes' => OpsFormat::bytes((int) ($row['s3_bytes'] ?? 0)),
      's3_bytes_raw' => (int) ($row['s3_bytes'] ?? 0),
      'image_bytes' => OpsFormat::bytes((int) ($row['image_bytes'] ?? 0)),
      'video_bytes' => OpsFormat::bytes((int) ($row['video_bytes'] ?? 0)),
      'archive_bytes' => OpsFormat::bytes((int) ($row['archive_bytes'] ?? 0)),
      'image_styles' => count($styles),
      'webp_styles' => $webp,
      'nodes' => $nodes,
      'quarantine_days' => (int) ($this->configFactory->get('xedc_core.settings')->get('file_quarantine_days') ?: 14),
    ];
  }

}
