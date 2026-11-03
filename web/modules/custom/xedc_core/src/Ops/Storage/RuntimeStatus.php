<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\xedc_core\StreamWrapper\XedcPublicS3fsStream;

/**
 * Status that separates saved configuration from the live stream wrapper.
 *
 * None of these checks enable takeover or write to production objects.
 */
final class RuntimeStatus {

  /**
   * @return array<string, mixed>
   *   Facts for this process. Write destination stays unverified.
   */
  public static function observe(): array {
    $publicClass = WrapperStatus::classForScheme('public');
    $privateClass = WrapperStatus::classForScheme('private');
    $wantsRemote = RuntimeState::usesObjectStorage();
    $publicRemote = $wantsRemote && WrapperStatus::isObjectStorageClass($publicClass);
    $privateRemote = WrapperStatus::isObjectStorageClass($privateClass);
    $publicRequested = (bool) Settings::get('s3fs.use_s3_for_public', FALSE);
    $privateRequested = (bool) Settings::get('s3fs.use_s3_for_private', FALSE);
    $settingsAccepted = (bool) Settings::get('xedc_storage.settings_accepted', FALSE);
    $cdnRewrite = (bool) Settings::get('xedc_storage.cdn_enabled', FALSE);
    $sample = self::samplePublicUrl();
    $styleCount = self::localStyleCount();
    $mismatch = ($wantsRemote && !WrapperStatus::isObjectStorageClass($publicClass)) || ($privateRequested && !$privateRemote);
    return [
      'runtime_requested' => (bool) Settings::get('xedc_storage.runtime_requested', FALSE),
      'runtime_eligible' => (bool) Settings::get('xedc_storage.runtime_eligible', FALSE),
      'settings_accepted' => $settingsAccepted,
      'runtime_applied' => $publicRemote || $privateRemote,
      'stream_wrapper_active' => $publicRemote ? 'r2' : ($publicClass === '' ? 'unverified' : 'local'),
      'active_stream_wrapper_class' => $publicClass === '' ? 'unverified' : $publicClass,
      'private_stream_wrapper_class' => $privateClass === '' ? 'unverified' : $privateClass,
      'actual_write_destination' => 'unverified',
      'generated_public_url' => $sample,
      'cdn_rewrite_active' => $cdnRewrite,
      'configuration_valid' => (string) Settings::get('xedc_storage.boot', '') === 'ok' && (string) Settings::get('xedc_storage.runtime_error', '') === '',
      'runtime_error' => (string) Settings::get('xedc_storage.runtime_error', ''),
      'public_takeover_requested' => $publicRequested,
      'private_takeover_requested' => $privateRequested,
      'mismatch' => $mismatch,
      'image_styles' => $styleCount > 0 ? 'local' : 'unverified',
      'image_style_files' => $styleCount,
      'rows' => self::rows($publicClass, $privateClass, $publicRemote, $privateRemote, $sample, $cdnRewrite, $styleCount, $mismatch),
    ];
  }

  /**
   * @return array{status: string, detail: string}
   *   A live HeadBucket result, or unverified when it cannot be attempted.
   */
  public static function bucketProbe(): array {
    if (!\Drupal::hasService('xedc_core.storage_config') || !\Drupal::hasService('xedc_core.storage_credentials')) {
      return ['status' => 'unverified', 'detail' => '存储服务不可用。'];
    }
    $config = \Drupal::service('xedc_core.storage_config');
    $id = $config->activeId();
    $profile = $id === '' ? NULL : $config->profile($id);
    if (!is_array($profile)) {
      return ['status' => 'unverified', 'detail' => '没有活动供应商。'];
    }
    $resolved = \Drupal::service('xedc_core.storage_credentials')->resolve($profile);
    if (empty($resolved['ok']) || !is_array($resolved['connection'] ?? NULL)) {
      return ['status' => 'unverified', 'detail' => '凭证未能解析，未连接 Bucket。'];
    }
    $connection = $resolved['connection'];
    unset($resolved);
    try {
      $client = S3ClientFactory::create($connection);
      $client->headBucket(['Bucket' => (string) $connection['bucket']]);
      return ['status' => 'verified', 'detail' => 'HeadBucket 成功。这只证明 API 凭证和 Bucket，不证明接管。'];
    }
    catch (\Throwable $error) {
      $secrets = [(string) ($connection['access_key'] ?? ''), (string) ($connection['secret_key'] ?? '')];
      return ['status' => 'unverified', 'detail' => BootGuard::safeError($error, $secrets)];
    }
  }

  private static function samplePublicUrl(): string {
    if (!\Drupal::hasService('file_url_generator')) {
      return 'unverified';
    }
    try {
      $url = \Drupal::service('file_url_generator')->generateAbsoluteString('public://2026-10/IMG_3009.jpeg');
    }
    catch (\Throwable) {
      return 'unverified';
    }
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    if (CdnUrlPolicy::isApiHost($host)) {
      return 'api-endpoint';
    }
    if ($host === 'cdn.gitxx.xyz') {
      return 'cdn';
    }
    if ($url === '' || str_contains($url, '/sites/default/files/')) {
      return 'local';
    }
    return 'unverified';
  }

  private static function localStyleCount(): int {
    $path = PublicStream::basePath() . '/styles';
    if (!is_dir($path)) {
      return 0;
    }
    $count = 0;
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * @return list<array{item: string, status: string}>
   *   Display rows. Labels are results, not configuration wishes.
   */
  private static function rows(string $publicClass, string $privateClass, bool $publicRemote, bool $privateRemote, string $sample, bool $cdnRewrite, int $styleCount, bool $mismatch): array {
    $publicLabel = $publicClass === '' ? '未验证' : ($publicRemote ? 'R2' : '本地');
    $privateLabel = $privateClass === '' ? '未验证' : ($privateRemote ? 'R2' : '本地');
    $target = $publicClass === XedcPublicS3fsStream::class;
    if ($cdnRewrite && $sample === 'cdn') {
      $cdnStatus = '已开启';
    }
    elseif ($cdnRewrite) {
      $cdnStatus = '开关已开启，页面仍未改写';
    }
    elseif ($sample === 'cdn') {
      $cdnStatus = '矛盾：开关关闭但页面已是 CDN';
    }
    else {
      $cdnStatus = '已关闭';
    }
    $takeover = static function (bool $requested, bool $remote): string {
      if ($remote) {
        return '已接管';
      }
      if ($requested) {
        return '未验证';
      }
      return '未接管';
    };
    return [
      ['item' => 'Settings 配置已接受', 'status' => (Settings::get('xedc_storage.settings_accepted') ? '已接受' : '未接受')],
      ['item' => '容器中的公开包装器', 'status' => $publicClass === '' ? '未验证' : ($target ? '目标包装器' : '本地包装器')],
      ['item' => '目标 Stream Wrapper 已注册', 'status' => $target ? '是' : '否'],
      ['item' => '当前进程使用目标包装器', 'status' => $target ? '是' : '否'],
      ['item' => 'Private Stream Wrapper', 'status' => $privateLabel],
      ['item' => '公开 URL', 'status' => $sample === 'local' ? '本地' : ($sample === 'cdn' ? 'CDN' : '未验证')],
      ['item' => 'CDN URL 改写', 'status' => $cdnStatus],
      ['item' => '实际 Drupal 写入 R2', 'status' => '未验证'],
      ['item' => '图片样式', 'status' => $styleCount > 0 ? '本地' : '未验证'],
      ['item' => 'Public takeover', 'status' => $takeover((bool) Settings::get('s3fs.use_s3_for_public', FALSE), $publicRemote)],
      ['item' => 'Private takeover', 'status' => $takeover((bool) Settings::get('s3fs.use_s3_for_private', FALSE), $privateRemote)],
      ['item' => '容器与标志是否一致', 'status' => $mismatch ? '不一致' : '一致'],
    ];
  }

}
