<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Site\Settings;

/**
 * Admin view of CDN safety. A configuration switch is not treated as proof.
 */
final class CdnStatus {

  public function __construct(
    protected StorageConfig $config,
    protected StorageUriResolver $uris,
    protected StorageJournal $journal,
  ) {}

  /**
   * @return array<string, mixed>
   *   Public status. Credentials are not included.
   */
  public function report(): array {
    $active = $this->config->activeId();
    $profile = $active === '' ? [] : ($this->config->profile($active) ?? []);
    $folders = $this->uris->folders($profile === [] ? NULL : $profile);
    $cdn = $this->config->data()['cdn'] ?? [];
    $base = $this->uris->configuredPublicBase($profile === [] ? [] : $profile);
    $endpoint = (string) ($profile['endpoint'] ?? '');
    $endpointHost = (string) (parse_url($endpoint, PHP_URL_HOST) ?: '');
    $switches = [
      'runtime' => (bool) Settings::get('xedc_storage.runtime_applied', FALSE),
      'understand' => (bool) Settings::get('xedc_storage.runtime_requested', FALSE),
      'public_takeover' => (bool) Settings::get('s3fs.use_s3_for_public', FALSE),
      'private_takeover' => (bool) Settings::get('s3fs.use_s3_for_private', FALSE),
      'cdn_rewrite' => $this->uris->rewriteEnabled(),
    ];
    $latest = $this->latest('cdn', $active);
    $range = $this->latest('cdn_range', $active);
    $delivery = $this->record('cdn_delivery');
    $migration = $this->record('migration_precheck');
    $privateExposed = is_array($latest) && array_key_exists('private_exposed', $latest) ? $latest['private_exposed'] : NULL;
    $privateBlocked = $privateExposed === FALSE || $privateExposed === 0;
    $publicOk = is_array($latest) && (int) ($latest['http_status'] ?? 0) === 200 && !empty($latest['body_ok']);
    $hostOk = rtrim($base, '/') === CdnUrlPolicy::PRODUCTION_BASE;
    $separated = $base !== '' && $endpointHost !== '' && strtolower((string) parse_url($base, PHP_URL_HOST)) !== strtolower($endpointHost);
    $rangeOk = is_array($range) && !empty($range['bytes_ok']) && (int) ($range['status_code'] ?? 0) === 206 && !empty($range['content_range_ok']);
    $acceptRanges = is_array($range) && !empty($range['accept_ranges_ok']);
    $browserOk = is_array($range) && (($range['browser']['played'] ?? FALSE) === TRUE) && (($range['browser']['seek'] ?? FALSE) === TRUE);
    $textCacheOk = is_array($latest) && array_key_exists('cache_mismatch', $latest) && $latest['cache_mismatch'] === FALSE;
    $videoCacheOk = is_array($range) && array_key_exists('cache_mismatch', $range) && $range['cache_mismatch'] === FALSE;
    $cacheOk = $textCacheOk && $videoCacheOk;
    $deliveryOk = is_array($delivery) && !empty($delivery['passed']);
    $migrationOk = is_array($migration) && !empty($migration['scanned']) && (int) ($migration['checksum_failed'] ?? 1) === 0;
    $switchOff = !in_array(TRUE, $switches, TRUE);
    $productionReady = $switchOff && $privateBlocked && $publicOk && $hostOk && $separated && $rangeOk && $acceptRanges && $browserOk && $cacheOk && $deliveryOk && $migrationOk && (int) ($migration['missing_local'] ?? 1) === 0;

    $rows = [
      $this->row('CDN 连接', $publicOk ? '公开测试对象可通过 CDN 读取' : (is_array($latest) ? '最近一次公开读取未通过' : '还没有本次测试记录'), $publicOk ? '已验证' : (is_array($latest) ? '存在安全风险' : '未验证')),
      $this->row('CDN 域名', $base === '' ? '未配置' : $base, $hostOk ? '已验证' : '需要人工操作'),
      $this->row('R2 API Endpoint', $endpointHost === '' ? '未配置' : $endpointHost, $separated ? '已验证' : '需要人工操作'),
      $this->row('公开前缀', (string) ($folders['public'] ?: '未配置'), $folders['public'] !== '' ? '已验证' : '需要人工操作'),
      $this->row('私有前缀', (string) ($folders['private'] ?: '未配置'), $folders['private'] !== '' ? '已验证' : '需要人工操作'),
      $this->row('CDN rewrite', $switches['cdn_rewrite'] ? '环境变量已启用' : '关闭', $switches['cdn_rewrite'] ? '存在安全风险' : '已验证'),
      $this->row('缓存策略', $this->cacheText($latest, $range, is_array($cdn) ? (string) ($cdn['cache_control'] ?? '') : ''), $cacheOk ? '已验证' : (is_array($latest) || is_array($range) ? '需要人工操作' : '未验证')),
      $this->row('最近一次 CDN 测试', $this->when($latest), is_array($latest) ? ($privateBlocked && $publicOk ? '已验证' : '存在安全风险') : '未验证'),
      $this->row('视频 Range', $this->rangeText($range), $rangeOk && $acceptRanges && $browserOk ? '已验证' : (is_array($range) ? '需要人工操作' : '未验证')),
      $this->row('私有对象匿名访问', $this->privateText($privateExposed, $latest), $privateBlocked ? '已验证' : '存在安全风险'),
      $this->row('URL 重写规则', is_array($delivery) ? (string) ($delivery['message'] ?? '') : '还没有跑过地址规则测试', $deliveryOk ? '已验证' : '未验证'),
      $this->row('生产接入', $productionReady ? '就绪' : '未就绪', $productionReady ? '已验证' : '存在安全风险'),
    ];

    return [
      'production_ready' => $productionReady,
      'rows' => $rows,
      'risks' => $this->risks($privateBlocked, $publicOk, $switches, $latest, $range, $migration),
      'human_steps' => $privateBlocked ? [] : $this->humanSteps(),
      'switches' => $switches,
      'cache' => [
        'draft' => is_array($cdn) ? (string) ($cdn['cache_control'] ?? '') : '',
        'unversioned' => CachePolicy::PUBLIC_REVALIDATE,
        'versioned' => CachePolicy::IMMUTABLE,
        'private' => CachePolicy::PRIVATE,
        'applied_to_production' => FALSE,
      ],
    ];
  }

  /**
   * @param array<string, mixed>|null $latest
   *   Stored CDN probe.
   *
   * @return list<string>
   *   Risk lines.
   */
  private function risks(bool $privateBlocked, bool $publicOk, array $switches, ?array $latest, ?array $range, ?array $migration): array {
    $risks = [];
    if (!$privateBlocked) {
      $risks[] = 'cdn.gitxx.xyz 绑定的是整个 R2 Bucket。私有前缀不能靠 Drupal 的 CDN 开关或隐藏 URL 来隔离。在边缘规则被验证拒绝之前，匿名请求仍可能读到 s3fs-private/。';
    }
    if ((is_array($latest) && !empty($latest['cache_mismatch'])) || (is_array($range) && !empty($range['cache_mismatch']))) {
      $risks[] = '文本测试对象的 Cache-Control 被 CDN 原样返回，视频响应却被改成了另一条 TTL。长期 immutable 还没有作用到现有生产文件。';
    }
    if (is_array($range) && empty($range['accept_ranges_ok'])) {
      $risks[] = 'CDN 的 Range 响应里没有 Accept-Ranges。字节范围即使返回 206，也不能单独当成播放器拖动已验收。';
    }
    if (!$publicOk) {
      $risks[] = '公开前缀还没有一次保留下来的成功读取，不能把 CDN 当成生产图片来源。';
    }
    foreach (['runtime' => 'XEDC_STORAGE_RUNTIME', 'public_takeover' => 'STORAGE_PUBLIC_TAKEOVER', 'private_takeover' => 'STORAGE_PRIVATE_TAKEOVER', 'cdn_rewrite' => 'STORAGE_CDN_ENABLED'] as $key => $name) {
      if (!empty($switches[$key])) {
        $risks[] = $name . ' 当前是开启的。这次验收要求它保持关闭。';
      }
    }
    if (is_array($migration) && (int) ($migration['missing_local'] ?? 0) > 0) {
      $risks[] = '有 ' . (int) $migration['missing_local'] . ' 个文件实体的本地文件不在。预检查没有复制，也没有改 URI。';
    }
    if ($risks === []) {
      $risks[] = '没有新的阻断项。生产接管开关仍应保持关闭，直到人工确认边缘规则和迁移窗口。';
    }
    return $risks;
  }

  /**
   * @return list<string>
   *   Steps that must be done in the Cloudflare dashboard.
   */
  private function humanSteps(): array {
    return [
      '在 Cloudflare 区域 gitxx.xyz 增加一条 WAF 自定义规则，仅拦截主机 cdn.gitxx.xyz 且路径为 /s3fs-private 或 /s3fs-private/ 的请求，动作为 Block。不要改 Bucket 策略，不要删除对象，不要撤掉自定义域名。',
      '确认 Bucket dclili 的 Public Development URL（r2.dev）处于关闭。那条地址不经过 cdn.gitxx.xyz 上的规则。',
      '把 Browser Cache TTL 设为 Respect Existing Headers，并确认没有 Cache Rule 覆盖 R2 的 Cache-Control。现有生产文件不要改成一年 immutable。',
      '规则保存后重新跑 CDN 测试。私有测试对象应返回 403，公开测试对象仍应返回原文。回滚方法是停用或删除这一条规则。',
    ];
  }

  /**
   * @param array<string, mixed>|null $latest
   *   Stored probe.
   */
  private function cacheText(?array $latest, ?array $range, string $draft): string {
    $observed = is_array($latest) ? (string) ($latest['cache_control'] ?? '') : '';
    $origin = is_array($latest) ? (string) ($latest['object_cache_control'] ?? '') : '';
    $text = '未版本化对象使用 ' . CachePolicy::PUBLIC_REVALIDATE . '。版本化对象才使用 ' . CachePolicy::IMMUTABLE . '。配置草稿是 ' . ($draft !== '' ? $draft : '空') . '，尚未写到现有生产文件。';
    if ($origin !== '' || $observed !== '') {
      $text .= ' 文本对象头：' . ($origin !== '' ? $origin : '无') . '。CDN 实际：' . ($observed !== '' ? $observed : '无') . '。';
    }
    if (is_array($range)) {
      $text .= ' 视频对象头：' . (string) ($range['object_cache_control'] ?? '无') . '。CDN 实际：' . (string) ($range['cache_control'] ?? '无') . '。';
    }
    return $text;
  }

  /**
   * @param array<string, mixed>|null $range
   *   Stored range probe.
   */
  private function rangeText(?array $range): string {
    if (!is_array($range)) {
      return '还没有用可播放 MP4 做过 CDN Range 测试。';
    }
    $browser = is_array($range['browser'] ?? NULL) ? $range['browser'] : [];
    return trim((string) ($range['message'] ?? '已记录')) . ' Accept-Ranges ' . (string) ($range['accept_ranges'] ?? '无') . '。浏览器播放 ' . (!empty($browser['played']) ? '通过' : '未通过') . '，拖动 ' . (!empty($browser['seek']) ? '通过' : '未通过') . '。';
  }

  private function privateText(mixed $exposed, ?array $latest): string {
    if ($exposed === TRUE || $exposed === 1 || $exposed === '1') {
      return '匿名请求读到了私有前缀测试对象。隔离未完成。';
    }
    if ($exposed === FALSE || $exposed === 0) {
      return '最近一次测试里，匿名请求没有读到私有前缀测试对象。';
    }
    if (is_array($latest)) {
      return '测试跑过，但没有得到私有前缀的明确结果。';
    }
    return '自定义域名面向整个 Bucket。私有前缀还没有被一次匿名读取测试证明已拒绝。';
  }

  /**
   * @param array<string, mixed>|null $row
   *   Stored row.
   */
  private function when(?array $row): string {
    if (!is_array($row)) {
      return '没有记录';
    }
    $checked = (int) ($row['checked'] ?? 0);
    $stamp = $checked > 0 ? gmdate('Y-m-d H:i', $checked) . ' UTC' : '时间未知';
    return $stamp . '。' . (string) ($row['message'] ?? '');
  }

  /**
   * @return array{label: string, value: string, state: string, tone: string}
   *   One status row.
   */
  private function row(string $label, string $value, string $state): array {
    $tone = match ($state) {
      '已验证' => 'ok',
      '需要人工操作' => 'warn',
      '存在安全风险' => 'bad',
      default => 'muted',
    };
    return [
      'label' => $label,
      'value' => $value,
      'state' => $state,
      'tone' => $tone,
    ];
  }

  /**
   * @return array<string, mixed>|null
   *   Newest stored probe for a profile.
   */
  private function latest(string $bucket, string $profileId): ?array {
    $stored = $this->journal->all()[$bucket] ?? NULL;
    if (!is_array($stored)) {
      return NULL;
    }
    if ($profileId !== '' && is_array($stored[$profileId] ?? NULL)) {
      return $stored[$profileId];
    }
    $latest = NULL;
    $time = -1;
    foreach ($stored as $row) {
      if (!is_array($row)) {
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
   * @return array<string, mixed>|null
   *   A single stored record.
   */
  private function record(string $key): ?array {
    $stored = $this->journal->all()[$key] ?? NULL;
    return is_array($stored) ? $stored : NULL;
  }

}
