<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Component\Datetime\TimeInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Checks the CDN hostname, a test object and response headers.
 *
 * Test objects stay under the isolated test prefix and are deleted afterward.
 * This class does not change bucket policy or production objects.
 */
final class CdnInspector {

  public function __construct(
    protected StorageConfig $config,
    protected CredentialVault $credentials,
    protected StorageUriResolver $uris,
    protected RemoteFileWriter $writer,
    protected RemoteFileReader $reader,
    protected LocalTempFileManager $temp,
    protected ClientInterface $http,
    protected StorageJournal $journal,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * @return array<string, mixed>
   *   Public CDN result.
   */
  public function inspect(string $profileId): array {
    $profile = $this->config->profile($profileId);
    if ($profile === NULL) {
      return $this->finish('cdn', $profileId, 'failed', '供应商配置不存在。', []);
    }
    $base = $this->uris->configuredPublicBase($profile);
    $endpointHost = (string) (parse_url((string) ($profile['endpoint'] ?? ''), PHP_URL_HOST) ?: '');
    $cdnHost = $base === '' ? '' : (string) (parse_url($base, PHP_URL_HOST) ?: '');
    $rows = [
      'base' => $base === '' ? '未配置' : $base,
      'cdn_host' => $cdnHost,
      'api_host' => $endpointHost,
      'separated' => $base !== '' && $cdnHost !== '' && $cdnHost !== $endpointHost && !CdnUrlPolicy::isApiHost($cdnHost),
      'private_exposed' => NULL,
      'cache_mismatch' => NULL,
    ];
    if ($base === '' || BootGuard::publicUrlError($base) !== NULL) {
      return $this->finish('cdn', $profileId, 'failed', '公开 CDN 地址缺失或不是 HTTPS。', $rows);
    }
    if ($cdnHost === $endpointHost || CdnUrlPolicy::isApiHost($cdnHost)) {
      return $this->finish('cdn', $profileId, 'failed', 'CDN 地址不能使用 API Endpoint。', $rows);
    }
    $resolved = $this->credentials->resolve($profile);
    if (empty($resolved['ok'])) {
      return $this->finish('cdn', $profileId, 'pending_credentials', '没有凭证，只检查了地址，没有上传测试对象。', $rows);
    }
    $folders = $this->uris->folders($profile);
    $stamp = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
    $key = $folders['public'] . '/' . BootGuard::TEST_PREFIX . 'cdn-' . $stamp . '.txt';
    $versionedKey = $folders['public'] . '/' . BootGuard::TEST_PREFIX . 'cdn-' . $stamp . '.0123456789abcdef.txt';
    $privateKey = $folders['private'] . '/' . BootGuard::TEST_PREFIX . 'cdn-private-' . $stamp . '.txt';
    $created = [];
    $path = $this->temp->createFile('cdn', '.txt');
    $body = 'xedc-cdn-' . bin2hex(random_bytes(8));
    $cache = 'public, max-age=60';
    try {
      file_put_contents($path, $body);
      $uploaded = $this->writer->putFile($resolved['connection'], $path, $key, [
        'mime' => 'text/plain',
        'cache_control' => $cache,
        'purpose' => 'test',
        'require_cache' => FALSE,
        'overwrite' => FALSE,
      ]);
      if (empty($uploaded['ok'])) {
        return $this->finish('cdn', $profileId, 'failed', (string) $uploaded['message'], $rows);
      }
      $created[] = $key;
      $url = $this->uris->joinUrl($base, $key);
      $rows['url_path'] = parse_url($url, PHP_URL_PATH);
      $rows['has_public_prefix'] = is_string($rows['url_path']) && str_contains($rows['url_path'], '/' . $folders['public'] . '/');
      $response = $this->request('GET', $url, ['Cache-Control' => 'no-cache']);
      $rows['http_status'] = $response['status'];
      $rows['content_type'] = $response['content_type'];
      $rows['cache_control'] = $response['cache_control'];
      $rows['cf_cache_status'] = $response['cf_cache_status'];
      $rows['body_ok'] = $response['body'] === $body;
      $rows['object_cache_control'] = (string) ($uploaded['cache_control'] ?? '');
      $rows['cache_mismatch'] = !$this->cacheMatches($cache, $response['cache_control']);
      $again = $this->request('GET', $url, []);
      $rows['cache_status_second'] = $again['cf_cache_status'];
      $rows['cache_control_second'] = $again['cache_control'];

      $versionedBody = 'xedc-versioned-' . bin2hex(random_bytes(4));
      file_put_contents($path, $versionedBody);
      $versioned = $this->writer->putFile($resolved['connection'], $path, $versionedKey, [
        'mime' => 'text/plain',
        'cache_control' => CachePolicy::IMMUTABLE,
        'purpose' => 'test',
        'require_cache' => FALSE,
        'overwrite' => FALSE,
      ]);
      if (!empty($versioned['ok'])) {
        $created[] = $versionedKey;
        $versionedResponse = $this->request('GET', $this->uris->joinUrl($base, $versionedKey), ['Cache-Control' => 'no-cache']);
        $rows['versioned_object_cache'] = (string) ($versioned['cache_control'] ?? '');
        $rows['versioned_cdn_cache'] = $versionedResponse['cache_control'];
        $rows['versioned_mismatch'] = !$this->cacheMatches(CachePolicy::IMMUTABLE, $versionedResponse['cache_control']);
        $rows['versioned_body_ok'] = $versionedResponse['body'] === $versionedBody;
      }

      $privateBody = 'xedc-private-' . bin2hex(random_bytes(8));
      file_put_contents($path, $privateBody);
      $privateUpload = $this->writer->putFile($resolved['connection'], $path, $privateKey, [
        'mime' => 'text/plain',
        'cache_control' => CachePolicy::PRIVATE,
        'purpose' => 'test',
        'overwrite' => FALSE,
      ]);
      if (empty($privateUpload['ok'])) {
        $rows['private_upload'] = 'failed';
      }
      else {
        $created[] = $privateKey;
        $privateUrl = $this->uris->joinUrl($base, $privateKey);
        $privateResponse = $this->request('GET', $privateUrl, []);
        $encoded = $this->request('GET', rtrim($base, '/') . '/' . rawurlencode($privateKey), []);
        $rows['private_http_status'] = $privateResponse['status'];
        $rows['private_cache_control'] = $privateResponse['cache_control'];
        $rows['private_encoded_status'] = $encoded['status'];
        $plainHit = $privateBody !== '' && $privateResponse['body'] === $privateBody;
        $encodedHit = $privateBody !== '' && $encoded['body'] === $privateBody;
        $denied = in_array($privateResponse['status'], [401, 403], TRUE);
        $rows['private_encoded_exposed'] = $encodedHit;
        if ($plainHit || $encodedHit) {
          $rows['private_exposed'] = TRUE;
        }
        elseif ($denied) {
          $rows['private_exposed'] = FALSE;
        }
        else {
          $rows['private_exposed'] = NULL;
        }
      }
      $publicOk = $rows['http_status'] === 200 && $rows['body_ok'] && $rows['has_public_prefix'];
      $message = $publicOk
        ? 'CDN 返回了公开前缀测试对象。'
        : 'CDN 没有按公开前缀返回测试对象。HTTP ' . $rows['http_status'] . '。';
      if ($rows['private_exposed'] === TRUE) {
        $message .= ' 匿名请求读到了私有前缀，边缘规则还没有拦住它。';
      }
      elseif ($rows['private_exposed'] === FALSE) {
        $message .= ' 私有前缀的正常路径返回了拒绝状态，响应里没有对象正文。';
      }
      elseif ($rows['private_exposed'] === NULL && empty($rows['private_upload'])) {
        $message .= ' 私有路径没有返回拒绝状态。404 不能当成隔离成功。';
      }
      if (!empty($rows['cache_mismatch'])) {
        $message .= ' CDN 的 Cache-Control 与对象头不一致。';
      }
      $status = !$publicOk || $rows['private_exposed'] === TRUE ? 'failed' : ($rows['private_exposed'] === FALSE && empty($rows['cache_mismatch']) ? 'passed' : 'attention');
      return $this->finish('cdn', $profileId, $status, $message, $rows);
    }
    catch (\Throwable $error) {
      $message = BootGuard::safeError($error, [
        (string) ($resolved['connection']['access_key'] ?? ''),
        (string) ($resolved['connection']['secret_key'] ?? ''),
      ]);
      $this->logger->error('CDN 检查失败：@message', ['@message' => $message]);
      return $this->finish('cdn', $profileId, 'failed', $message, $rows);
    }
    finally {
      $this->deleteCreated($resolved['connection'], $created);
      $this->temp->delete($path);
    }
  }

  /**
   * Uploads one playable MP4 under the test prefix and requests it from the CDN.
   *
   * The optional callback runs before the object is deleted. It may return
   * public browser fields. It must not print credentials.
   *
   * @return array<string, mixed>
   *   Public range result.
   */
  public function inspectVideo(string $profileId, ?callable $beforeDelete = NULL): array {
    $profile = $this->config->profile($profileId);
    if ($profile === NULL) {
      return $this->finish('cdn_range', $profileId, 'failed', '供应商配置不存在。', []);
    }
    $base = $this->uris->configuredPublicBase($profile);
    if ($base === '' || BootGuard::publicUrlError($base) !== NULL || CdnUrlPolicy::isApiHost((string) parse_url($base, PHP_URL_HOST))) {
      return $this->finish('cdn_range', $profileId, 'failed', 'CDN 地址不能用来做 Range 验收。', []);
    }
    $resolved = $this->credentials->resolve($profile);
    if (empty($resolved['ok'])) {
      return $this->finish('cdn_range', $profileId, 'pending_credentials', '没有凭证，没有上传视频。', []);
    }
    $path = $this->temp->createFile('cdn-video', '.mp4');
    $created = [];
    try {
      if (!PlayableMp4::write($path)) {
        return $this->finish('cdn_range', $profileId, 'failed', '服务器不能生成可解码的 H.264 MP4，Range 验收没有进行。', []);
      }
      $bytes = (int) filesize($path);
      $folders = $this->uris->folders($profile);
      $key = $folders['public'] . '/' . BootGuard::TEST_PREFIX . 'cdn-range-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3)) . '.mp4';
      $uploaded = $this->writer->putFile($resolved['connection'], $path, $key, [
        'mime' => 'video/mp4',
        'cache_control' => 'public, max-age=60',
        'purpose' => 'test',
        'require_cache' => FALSE,
        'overwrite' => FALSE,
      ]);
      if (empty($uploaded['ok'])) {
        return $this->finish('cdn_range', $profileId, 'failed', (string) $uploaded['message'], []);
      }
      $created[] = $key;
      $url = $this->uris->joinUrl($base, $key);
      $full = $this->request('GET', $url, ['Cache-Control' => 'no-cache']);
      $end = min(15, $bytes - 1);
      $range = $this->request('GET', $url, ['Range' => 'bytes=0-' . $end]);
      $start = min(40, $bytes - 1);
      $last = min($start + 31, $bytes - 1);
      $middle = $this->request('GET', $url, ['Range' => 'bytes=' . $start . '-' . $last]);
      $cached = $this->request('GET', $url, []);
      $expectedHead = (string) file_get_contents($path, FALSE, NULL, 0, $end + 1);
      $expectedMiddle = (string) file_get_contents($path, FALSE, NULL, $start, $last - $start + 1);
      $rows = [
        'url_host' => (string) parse_url($url, PHP_URL_HOST),
        'bytes' => $bytes,
        'get_status' => $full['status'],
        'content_type' => $full['content_type'],
        'get_body_ok' => hash_equals(hash('sha256', (string) file_get_contents($path)), hash('sha256', $full['body'])),
        'status_code' => $range['status'],
        'content_range' => $range['content_range'],
        'content_range_ok' => $this->contentRangeMatches($range['content_range'], 0, $end, $bytes),
        'bytes_ok' => $range['status'] === 206 && hash_equals($expectedHead, $range['body']) && $middle['status'] === 206 && hash_equals($expectedMiddle, $middle['body']),
        'middle_content_range' => $middle['content_range'],
        'accept_ranges' => $range['accept_ranges'] !== '' ? $range['accept_ranges'] : $full['accept_ranges'],
        'accept_ranges_ok' => str_contains(strtolower($range['accept_ranges'] . ' ' . $full['accept_ranges']), 'bytes'),
        'object_cache_control' => (string) ($uploaded['cache_control'] ?? ''),
        'cache_control' => $full['cache_control'],
        'cache_mismatch' => !$this->cacheMatches('public, max-age=60', $full['cache_control']),
        'cf_cache_status' => $full['cf_cache_status'],
        'cf_cache_status_second' => $cached['cf_cache_status'],
        'cache_control_second' => $cached['cache_control'],
        'browser' => ['played' => FALSE, 'seek' => FALSE, 'detail' => '未做浏览器播放'],
      ];
      if ($beforeDelete !== NULL) {
        $browser = $beforeDelete($url, $path);
        if (is_array($browser)) {
          $rows['browser'] = [
            'played' => !empty($browser['played']),
            'seek' => !empty($browser['seek']),
            'detail' => substr((string) ($browser['detail'] ?? ''), 0, 300),
          ];
        }
      }
      $ok = $rows['get_status'] === 200 && $rows['get_body_ok'] && $rows['bytes_ok'] && $rows['content_range_ok'] && str_starts_with(strtolower($rows['content_type']), 'video/mp4');
      $message = $ok
        ? 'CDN 对可播放 MP4 返回了 200 和 206，字节范围与文件一致。'
        : 'CDN Range 没有同时满足 200、206、Content-Range 和字节一致。';
      if (!$rows['accept_ranges_ok']) {
        $message .= ' 响应没有 Accept-Ranges。';
      }
      if (!empty($rows['cache_mismatch'])) {
        $message .= ' CDN Cache-Control 与上传头不一致。';
      }
      $status = $ok && $rows['accept_ranges_ok'] && !empty($rows['browser']['played']) && !empty($rows['browser']['seek']) && empty($rows['cache_mismatch']) ? 'passed' : ($ok ? 'attention' : 'failed');
      return $this->finish('cdn_range', $profileId, $status, $message, $rows);
    }
    catch (\Throwable $error) {
      $message = BootGuard::safeError($error, [
        (string) ($resolved['connection']['access_key'] ?? ''),
        (string) ($resolved['connection']['secret_key'] ?? ''),
      ]);
      $this->logger->error('CDN 视频检查失败：@message', ['@message' => $message]);
      return $this->finish('cdn_range', $profileId, 'failed', $message, []);
    }
    finally {
      $this->deleteCreated($resolved['connection'] ?? [], $created);
      $this->temp->delete($path);
    }
  }

  /**
   * @return array<string, mixed>
   *   Stored results keyed by profile.
   */
  public function stored(): array {
    $stored = $this->journal->all()['cdn'] ?? [];
    return is_array($stored) ? $stored : [];
  }

  /**
   * @param array<string, string> $headers
   *   Request headers.
   *
   * @return array{status: int, body: string, content_type: string, cache_control: string, cf_cache_status: string, accept_ranges: string, content_range: string}
   *   Selected response fields. The body is kept only for the caller to compare.
   */
  private function request(string $method, string $url, array $headers): array {
    $response = $this->http->request($method, $url, [
      'timeout' => 30,
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
      'headers' => $headers,
    ]);
    return [
      'status' => $response->getStatusCode(),
      'body' => (string) $response->getBody(),
      'content_type' => $response->getHeaderLine('Content-Type'),
      'cache_control' => $response->getHeaderLine('Cache-Control'),
      'cf_cache_status' => $response->getHeaderLine('CF-Cache-Status'),
      'accept_ranges' => $response->getHeaderLine('Accept-Ranges'),
      'content_range' => $response->getHeaderLine('Content-Range'),
    ];
  }

  private function cacheMatches(string $expected, string $actual): bool {
    $normalize = static function (string $value): string {
      $parts = preg_split('/\s*,\s*/', strtolower(trim($value))) ?: [];
      $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
      sort($parts);
      return implode(', ', $parts);
    };
    return $actual !== '' && $normalize($expected) === $normalize($actual);
  }

  private function contentRangeMatches(string $header, int $start, int $end, int $size): bool {
    return (bool) preg_match('/bytes\s+' . $start . '-' . $end . '\/' . $size . '/i', $header);
  }

  /**
   * @param array<string, mixed> $connection
   *   Resolved connection.
   * @param list<string> $keys
   *   Keys created by this call.
   */
  private function deleteCreated(array $connection, array $keys): void {
    if ($connection === []) {
      return;
    }
    foreach ($keys as $key) {
      try {
        $this->writer->delete($connection, $key, 'test');
      }
      catch (\Throwable) {
      }
    }
  }

  /**
   * @param array<string, mixed> $extra
   *   Public fields.
   *
   * @return array<string, mixed>
   *   Stored result.
   */
  private function finish(string $bucket, string $profileId, string $status, string $message, array $extra): array {
    unset($extra['access_key'], $extra['secret_key'], $extra['connection'], $extra['body']);
    $result = $extra + [
      'profile' => $profileId,
      'status' => $status,
      'message' => BootGuard::redact($message),
      'checked' => $this->time->getRequestTime(),
    ];
    $stored = $this->journal->all();
    $cdn = is_array($stored[$bucket] ?? NULL) ? $stored[$bucket] : [];
    $cdn[$profileId] = $result;
    $this->journal->put($bucket, $cdn);
    $this->journal->add($status === 'passed' ? 'info' : 'error', $profileId . ' ' . $bucket . '：' . $result['message']);
    return $result;
  }

}
