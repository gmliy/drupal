<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

/**
 * Compares bytes with SHA-256. ETag is recorded and is not treated as MD5.
 */
final class IntegrityChecker {

  /**
   * @return array<string, mixed>
   *   Fingerprint of one body.
   */
  public static function fingerprint(string $body, string $mime = '', ?string $etag = NULL): array {
    $etag = $etag === NULL ? '' : trim($etag, " \t\n\r\0\x0B\"");
    return [
      'sha256' => hash('sha256', $body),
      'bytes' => strlen($body),
      'mime' => strtolower(trim(explode(';', $mime)[0])),
      'etag' => $etag,
      'etag_is_md5' => self::etagIsMd5($etag, $body),
    ];
  }

  /**
   * @param array<string, mixed> $source
   *   Source fingerprint.
   * @param array<string, mixed> $target
   *   Target fingerprint.
   *
   * @return array<string, mixed>
   *   Comparison. Success requires SHA-256 and size.
   */
  public static function compare(array $source, array $target): array {
    $sha = is_string($source['sha256'] ?? NULL)
      && is_string($target['sha256'] ?? NULL)
      && hash_equals($source['sha256'], $target['sha256']);
    $bytes = (int) ($source['bytes'] ?? -1) === (int) ($target['bytes'] ?? -2);
    $mime = ($source['mime'] ?? '') !== '' && ($source['mime'] ?? NULL) === ($target['mime'] ?? NULL);
    return [
      'ok' => $sha && $bytes,
      'sha256' => $sha,
      'bytes' => $bytes,
      'mime' => $mime,
      'etag_is_md5' => !empty($source['etag_is_md5']) && !empty($target['etag_is_md5']),
      'note' => 'ETag 不能跨供应商当作 MD5。迁移成功以 SHA-256 和文件大小同时一致为准。',
    ];
  }

  public static function etagIsMd5(string $etag, string $body): bool {
    if ($etag === '' || str_contains($etag, '-')) {
      return FALSE;
    }
    return hash_equals(md5($body), strtolower($etag));
  }

}
