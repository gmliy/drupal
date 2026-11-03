<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;

/**
 * Read-only checklist before anyone enables runtime or scheme takeover.
 *
 * This class never changes settings, file URIs, or objects.
 */
final class TakeoverReadiness {

  public function __construct(
    protected Connection $database,
    protected StorageConfig $config,
    protected StorageUriResolver $uris,
    protected StorageJournal $journal,
  ) {}

  /**
   * @return array<string, mixed>
   *   Public report. It contains no credentials.
   */
  public function report(): array {
    $census = $this->census();
    $formats = $this->formats();
    $styles = $this->styles();
    $active = $this->config->activeId();
    $profile = $active === '' ? NULL : $this->config->profile($active);
    $switches = [
      'runtime_applied' => (bool) Settings::get('xedc_storage.runtime_applied', FALSE),
      'public_takeover' => (bool) Settings::get('s3fs.use_s3_for_public', FALSE),
      'private_takeover' => (bool) Settings::get('s3fs.use_s3_for_private', FALSE),
      'cdn_rewrite' => $this->uris->rewriteEnabled(),
    ];
    $missingStyles = array_values(array_diff(['thumbnail', 'photo_gallery_650'], $styles));
    $blockers = [];
    if ($active === '') {
      $blockers[] = '没有活动供应商。';
    }
    elseif ($active === 'r2') {
      $blockers[] = '活动配置仍是旧 ID r2，应使用 silly。';
    }
    if (!$formats['jpeg'] || !$formats['png'] || !$formats['webp']) {
      $blockers[] = 'GD 缺少 JPEG、PNG 或 WebP。';
    }
    if (!$formats['avif']) {
      $blockers[] = 'GD 不能写 AVIF。转换必须报告失败，不能当成成功。';
    }
    if ($missingStyles !== []) {
      $blockers[] = '缺少图片样式：' . implode('、', $missingStyles) . '。';
    }
    if ($switches['runtime_applied'] || $switches['public_takeover'] || $switches['private_takeover'] || $switches['cdn_rewrite']) {
      $blockers[] = '运行时、接管或 CDN rewrite 已经打开。本检查预期它们是关闭的。';
    }
    $cdn = $this->latestCdn();
    $exposed = is_array($cdn) ? ($cdn['private_exposed'] ?? NULL) : NULL;
    if ($exposed === TRUE || $exposed === 1 || $exposed === '1') {
      $blockers[] = 'CDN 域名可以匿名读取私有前缀。需要在 Cloudflare 边缘拒绝该路径，不能靠 Drupal 开关。';
    }
    elseif ($exposed !== FALSE && $exposed !== 0) {
      $blockers[] = 'CDN 私有前缀还没有被匿名访问测试证明已拒绝。自定义域名目前面向整个 Bucket。';
    }
    $observed = RuntimeStatus::observe();
    $bucket = RuntimeStatus::bucketProbe();
    return [
      'active_profile' => $active,
      'bucket' => is_array($profile) ? (string) ($profile['bucket'] ?? '') : '',
      'region' => is_array($profile) ? (string) ($profile['region'] ?? '') : '',
      'endpoint_host' => $this->host((string) (is_array($profile) ? ($profile['endpoint'] ?? '') : '')),
      'cdn_host' => $this->host($this->uris->configuredPublicBase(is_array($profile) ? $profile : [])),
      'public_folder' => (string) ($this->uris->folders(is_array($profile) ? $profile : NULL)['public'] ?? ''),
      'private_folder' => (string) ($this->uris->folders(is_array($profile) ? $profile : NULL)['private'] ?? ''),
      'switches' => $switches,
      'formats' => $formats,
      'styles' => $styles,
      'census' => $census,
      'blockers' => $blockers,
      'public_ready' => FALSE,
      'private_ready' => FALSE,
      'note' => '隔离集成测试通过之前，公开和私有接管都保持未就绪。本页不打开任何开关。配置被接受不等于 Stream Wrapper 已切换，也不等于上传已经进入 R2。',
      'observed' => $observed,
      'bucket_probe' => $bucket,
    ];
  }

  /**
   * @return array<string, int>
   *   File entity counts. These are not Bucket object counts.
   */
  private function census(): array {
    $counts = [
      'files' => 0,
      'bytes' => 0,
      'public_files' => 0,
      'public_bytes' => 0,
      'private_files' => 0,
      'private_bytes' => 0,
      's3_files' => 0,
      's3_bytes' => 0,
      'temporary_files' => 0,
      'image_files' => 0,
      'video_files' => 0,
      'other_files' => 0,
    ];
    if (!$this->database->schema()->tableExists('file_managed')) {
      return $counts;
    }
    $rows = $this->database->query('SELECT uri, filemime, filesize FROM {file_managed}');
    foreach ($rows as $row) {
      $uri = (string) $row->uri;
      $bytes = max(0, (int) $row->filesize);
      $scheme = $this->uris->scheme($uri);
      $kind = $this->uris->mediaKind((string) $row->filemime, $uri);
      $counts['files']++;
      $counts['bytes'] += $bytes;
      if ($scheme === 'public') {
        $counts['public_files']++;
        $counts['public_bytes'] += $bytes;
      }
      elseif ($scheme === 'private') {
        $counts['private_files']++;
        $counts['private_bytes'] += $bytes;
      }
      elseif ($scheme === 's3') {
        $counts['s3_files']++;
        $counts['s3_bytes'] += $bytes;
      }
      elseif ($scheme === 'temporary') {
        $counts['temporary_files']++;
      }
      if ($kind === 'image') {
        $counts['image_files']++;
      }
      elseif ($kind === 'video') {
        $counts['video_files']++;
      }
      else {
        $counts['other_files']++;
      }
    }
    return $counts;
  }

  /**
   * @return array<string, bool>
   *   Encoder availability. Imagick is reported and is not required.
   */
  private function formats(): array {
    return [
      'jpeg' => function_exists('imagejpeg') && function_exists('imagecreatefromjpeg'),
      'png' => function_exists('imagepng') && function_exists('imagecreatefrompng'),
      'webp' => function_exists('imagewebp') && function_exists('imagecreatefromwebp'),
      'avif' => function_exists('imageavif') && function_exists('imagecreatefromavif'),
      'imagick' => extension_loaded('imagick'),
    ];
  }

  /**
   * @return array<string, mixed>|null
   *   Newest CDN probe that recorded a private-prefix result.
   */
  private function latestCdn(): ?array {
    $stored = $this->journal->all()['cdn'] ?? NULL;
    if (!is_array($stored)) {
      return NULL;
    }
    $latest = NULL;
    $time = -1;
    foreach ($stored as $row) {
      if (!is_array($row) || !array_key_exists('private_exposed', $row)) {
        continue;
      }
      $checked = (int) ($row['checked'] ?? 0);
      if ($latest === NULL || $checked >= $time) {
        $latest = $row;
        $time = $checked;
      }
    }
    return $latest;
  }

  /**
   * @return list<string>
   *   Image style config IDs.
   */
  private function styles(): array {
    if (!$this->database->schema()->tableExists('config')) {
      return [];
    }
    $names = $this->database->select('config', 'c')
      ->fields('c', ['name'])
      ->condition('name', 'image.style.%', 'LIKE')
      ->execute()
      ->fetchCol();
    $ids = [];
    foreach ($names as $name) {
      if (is_string($name) && str_starts_with($name, 'image.style.')) {
        $ids[] = substr($name, strlen('image.style.'));
      }
    }
    sort($ids);
    return $ids;
  }

  private function host(string $url): string {
    if ($url === '') {
      return '';
    }
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? $host : '';
  }

}
