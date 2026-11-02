<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

/**
 * Accepts only plain HTTP(S) download URLs.
 *
 * The browser is redirected after a permission check. This class does not
 * fetch the URL, and it rejects credentials and non-http schemes.
 */
final class ExternalUrlGuard {

  public function normalize(string $url): string {
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048) {
      throw new \InvalidArgumentException('下载地址不能为空，且不能超过 2048 个字符。');
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
      throw new \InvalidArgumentException('下载地址包含非法控制字符。');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      throw new \InvalidArgumentException('请填写完整的 HTTP 或 HTTPS 下载地址。');
    }
    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      throw new \InvalidArgumentException('只允许 HTTP 或 HTTPS 下载地址。');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      throw new \InvalidArgumentException('下载地址不能包含账号或密码。');
    }
    $host = strtolower((string) $parts['host']);
    if ($host === '' || str_contains($host, '\\') || str_contains($host, '@')) {
      throw new \InvalidArgumentException('下载地址的主机名无效。');
    }
    $rebuilt = $scheme . '://' . $host;
    if (isset($parts['port'])) {
      $port = (int) $parts['port'];
      if ($port < 1 || $port > 65535) {
        throw new \InvalidArgumentException('下载地址的端口无效。');
      }
      $rebuilt .= ':' . $port;
    }
    $rebuilt .= $parts['path'] ?? '';
    if (isset($parts['query']) && $parts['query'] !== '') {
      $rebuilt .= '?' . $parts['query'];
    }
    return $rebuilt;
  }

}
