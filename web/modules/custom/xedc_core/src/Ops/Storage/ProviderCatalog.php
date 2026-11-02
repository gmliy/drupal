<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

/**
 * Vendor hints. These values are not written into the live connection.
 */
final class ProviderCatalog {

  /**
   * @return array<string, array<string, mixed>>
   *   Provider id to display hints.
   */
  public static function all(): array {
    return [
      'cloudflare_r2' => self::item('Cloudflare R2', 'auto', 'https://<ACCOUNT_ID>.r2.cloudflarestorage.com', TRUE, TRUE, '使用 R2 的 S3 API 凭证。Region 常用 auto。公开文件使用自定义域名，不使用 API Endpoint。'),
      'aws_s3' => self::item('Amazon S3', 'us-east-1', '', FALSE, FALSE, '留空 Endpoint 时使用 AWS SDK 的区域默认地址。凭证使用 IAM 访问密钥，并限制到目标 Bucket。'),
      'wasabi' => self::item('Wasabi', 'us-east-1', 'https://s3.<region>.wasabisys.com', FALSE, TRUE, 'Endpoint 和 Region 以 Wasabi 控制台为准，例如 us-east-1 对应 https://s3.wasabisys.com。'),
      'backblaze_b2' => self::item('Backblaze B2', 'us-west-004', 'https://s3.<region>.backblazeb2.com', FALSE, TRUE, '使用 B2 S3 Compatible 应用密钥。Region 和 Endpoint 来自该 Bucket 的 S3 Endpoint。'),
      'digitalocean_spaces' => self::item('DigitalOcean Spaces', 'nyc3', 'https://<region>.digitaloceanspaces.com', FALSE, TRUE, 'Region 示例为 nyc3、sgp1、fra1。公开域名和 API Endpoint 不是同一个地址。'),
      'vultr' => self::item('Vultr Object Storage', 'ewr', 'https://<region>.vultrobjects.com', FALSE, TRUE, '使用 Vultr 对象存储的 S3 凭证和该区域的 Endpoint。'),
      'minio' => self::item('MinIO', 'us-east-1', 'https://minio.example.com', TRUE, TRUE, '通常需要 Path-style。本机 HTTP 只在明确允许回环地址后才能测试。'),
      'custom' => self::item('自定义 S3 Endpoint', '', 'https://s3.example.com', TRUE, TRUE, '填写服务商文档给出的 Endpoint、Region 和路径风格。不要套用其他服务商的地址。'),
    ];
  }

  /**
   * @return array<string, mixed>|null
   *   One provider, or NULL when the id is unknown.
   */
  public static function get(string $id): ?array {
    return self::all()[$id] ?? NULL;
  }

  /**
   * @return array<string, mixed>
   *   Catalog row.
   */
  private static function item(string $label, string $region, string $endpoint, bool $pathStyle, bool $disableVersionSync, string $note): array {
    return [
      'label' => $label,
      'region_hint' => $region,
      'endpoint_hint' => $endpoint,
      'path_style' => $pathStyle,
      'disable_version_sync' => $disableVersionSync,
      'note' => $note,
      'status' => 'theoretical',
    ];
  }

}
