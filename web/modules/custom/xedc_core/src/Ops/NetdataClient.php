<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Reads the local Netdata agent. The host is fixed to loopback.
 */
class NetdataClient {

  public function __construct(
    protected ClientInterface $httpClient,
  ) {}

  /**
   * @return array{ok: bool, error: string, data: array<string, mixed>}
   *   Decoded JSON, or an error the page can show.
   */
  public function get(string $baseUrl, string $path, array $query = []): array {
    $base = $this->validateBase($baseUrl);
    if ($base === NULL) {
      return [
        'ok' => FALSE,
        'error' => 'Netdata 地址无效。只允许访问本机回环地址。',
        'data' => [],
      ];
    }
    if (!str_starts_with($path, '/api/')) {
      return [
        'ok' => FALSE,
        'error' => '只允许读取 Netdata API。',
        'data' => [],
      ];
    }
    try {
      $response = $this->httpClient->request('GET', $base . $path, [
        'query' => $query,
        'timeout' => 4,
        'connect_timeout' => 1.5,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'headers' => [
          'Accept' => 'application/json',
          'User-Agent' => 'XEDC-Monitor',
        ],
      ]);
    }
    catch (GuzzleException $exception) {
      return [
        'ok' => FALSE,
        'error' => '无法连接本机 Netdata：' . $exception->getMessage(),
        'data' => [],
      ];
    }
    $status = $response->getStatusCode();
    $decoded = json_decode((string) $response->getBody(), TRUE);
    if ($status !== 200 || !is_array($decoded)) {
      return [
        'ok' => FALSE,
        'error' => 'Netdata 返回了无法使用的响应（HTTP ' . $status . '）。',
        'data' => [],
      ];
    }
    return ['ok' => TRUE, 'error' => '', 'data' => $decoded];
  }

  /**
   * Rejects any URL that is not the local agent.
   */
  private function validateBase(string $baseUrl): ?string {
    $parts = parse_url($baseUrl);
    if (!is_array($parts)) {
      return NULL;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $port = (int) ($parts['port'] ?? 19999);
    if ($scheme !== 'http' || !in_array($host, ['127.0.0.1', 'localhost'], TRUE)) {
      return NULL;
    }
    if ($port < 1 || $port > 65535 || isset($parts['user']) || isset($parts['pass'])) {
      return NULL;
    }
    if (!empty($parts['path']) && $parts['path'] !== '/') {
      return NULL;
    }
    return 'http://' . $host . ':' . $port;
  }

}
