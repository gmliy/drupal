<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Utils;

/**
 * Reads the local Netdata agent. The host is fixed to loopback.
 */
class NetdataClient {

  public function __construct(
    protected ClientInterface $httpClient,
  ) {}

  /**
   * @return array{ok: bool, error: string, data: array<string, mixed>, status: int, elapsed_ms: int}
   *   Decoded JSON, or an error the page can show.
   */
  public function get(string $baseUrl, string $path, array $query = [], float $timeout = 4.0): array {
    $started = microtime(TRUE);
    $base = $this->validateBase($baseUrl);
    if ($base === NULL) {
      return $this->failure('Netdata 地址无效。只允许访问本机回环地址。', $started);
    }
    if (!$this->validatePath($path)) {
      return $this->failure('只允许读取 Netdata API。', $started);
    }
    try {
      $response = $this->httpClient->request('GET', $base . $path, $this->options($query, $timeout));
    }
    catch (GuzzleException $exception) {
      return $this->failure('无法连接本机 Netdata：' . $this->safeError($exception->getMessage()), $started);
    }
    return $this->decode($response->getStatusCode(), (string) $response->getBody(), $started);
  }

  /**
   * Parallel reads. Keys come from the caller; paths stay on the API allowlist.
   *
   * @param list<array{key: string, path: string, query?: array<string, mixed>}> $jobs
   *   At most 24 jobs.
   *
   * @return array<string, array{ok: bool, error: string, data: array<string, mixed>, status: int, elapsed_ms: int}>
   *   Results keyed by job key.
   */
  public function getMany(string $baseUrl, array $jobs, float $timeout = 3.5): array {
    $jobs = array_slice($jobs, 0, 24);
    $started = [];
    $results = [];
    $base = $this->validateBase($baseUrl);
    if ($base === NULL || !method_exists($this->httpClient, 'requestAsync')) {
      foreach ($jobs as $job) {
        $key = (string) ($job['key'] ?? '');
        if ($key === '') {
          continue;
        }
        $results[$key] = $base === NULL
          ? $this->failure('Netdata 地址无效。只允许访问本机回环地址。', microtime(TRUE))
          : $this->get($baseUrl, (string) ($job['path'] ?? ''), $job['query'] ?? [], $timeout);
      }
      return $results;
    }
    $promises = [];
    foreach ($jobs as $job) {
      $key = (string) ($job['key'] ?? '');
      $path = (string) ($job['path'] ?? '');
      if ($key === '') {
        continue;
      }
      $started[$key] = microtime(TRUE);
      if (!$this->validatePath($path)) {
        $results[$key] = $this->failure('只允许读取 Netdata API。', $started[$key]);
        continue;
      }
      $promises[$key] = $this->httpClient->requestAsync('GET', $base . $path, $this->options($job['query'] ?? [], $timeout));
    }
    if ($promises === []) {
      return $results;
    }
    $settled = Utils::settle($promises)->wait();
    foreach ($settled as $key => $item) {
      $origin = $started[$key] ?? microtime(TRUE);
      if (($item['state'] ?? '') !== 'fulfilled') {
        $reason = $item['reason'] ?? NULL;
        $message = $reason instanceof \Throwable ? $reason->getMessage() : '请求失败';
        $results[$key] = $this->failure('无法连接本机 Netdata：' . $this->safeError($message), $origin);
        continue;
      }
      $response = $item['value'];
      $results[$key] = $this->decode($response->getStatusCode(), (string) $response->getBody(), $origin);
    }
    return $results;
  }

  /**
   * @return array{ok: bool, error: string, data: array<string, mixed>, status: int, elapsed_ms: int}
   *   Normalized API result.
   */
  private function decode(int $status, string $body, float $started): array {
    $decoded = json_decode($body, TRUE);
    if ($status !== 200 || !is_array($decoded)) {
      return $this->failure('Netdata 返回了无法使用的响应（HTTP ' . $status . '）。', $started, $status);
    }
    return [
      'ok' => TRUE,
      'error' => '',
      'data' => $decoded,
      'status' => $status,
      'elapsed_ms' => $this->elapsed($started),
    ];
  }

  /**
   * @return array{ok: bool, error: string, data: array<string, mixed>, status: int, elapsed_ms: int}
   *   Failed result.
   */
  private function failure(string $error, float $started, int $status = 0): array {
    return [
      'ok' => FALSE,
      'error' => $error,
      'data' => [],
      'status' => $status,
      'elapsed_ms' => $this->elapsed($started),
    ];
  }

  /**
   * @param array<string, mixed> $query
   *   Query string.
   *
   * @return array<string, mixed>
   *   Guzzle options.
   */
  private function options(array $query, float $timeout): array {
    $timeout = max(0.3, min(8.0, $timeout));
    return [
      'query' => $query,
      'timeout' => $timeout,
      'connect_timeout' => min(1.5, $timeout),
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
      'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => 'XEDC-Monitor',
      ],
    ];
  }

  private function elapsed(float $started): int {
    return (int) round((microtime(TRUE) - $started) * 1000);
  }

  private function safeError(string $message): string {
    $message = preg_replace('#\s*\(see https?://[^)]+\)#', '', $message) ?? $message;
    $message = preg_replace('#https?://\S+#', '本机', $message) ?? $message;
    if (strlen($message) > 180) {
      $message = substr($message, 0, 180);
    }
    return $message;
  }

  private function validatePath(string $path): bool {
    return (bool) preg_match('#^/api/v[0-9]+/[A-Za-z0-9_./-]+$#', $path);
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
