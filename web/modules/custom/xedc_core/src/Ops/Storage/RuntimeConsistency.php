<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

/**
 * Compares this process, the compiled wrapper, and readable maintenance config.
 *
 * It never changes environment variables or the container cache.
 */
final class RuntimeConsistency {

  /**
   * @return array<string, mixed>
   *   Facts for the process rendering this page.
   */
  public static function report(): array {
    $observed = RuntimeStatus::observe();
    $flags = [];
    foreach ([
      'XEDC_STORAGE_RUNTIME',
      'STORAGE_I_UNDERSTAND_TAKEOVER',
      'STORAGE_PUBLIC_TAKEOVER',
      'STORAGE_PRIVATE_TAKEOVER',
      'STORAGE_CDN_ENABLED',
    ] as $name) {
      $flags[$name] = BootGuard::env($name) === NULL ? 'absent' : (BootGuard::flag($name) ? '1' : BootGuard::env($name));
    }
    $publicClass = (string) $observed['active_stream_wrapper_class'];
    $privateClass = (string) $observed['private_stream_wrapper_class'];
    $runtime = RuntimeState::read();
    $mode = (string) $runtime['public_mode'];
    $wrapperMatches = str_contains($publicClass, 'XedcPublicS3fsStream')
      && in_array($mode, ['local', 'cloudflare_r2', 'env'], TRUE);
    $privateLocal = str_contains($privateClass, 'PrivateStream') && !str_contains($privateClass, 'S3fs');
    $maintenance = self::maintenanceFlags();
    return [
      'sapi' => PHP_SAPI,
      'flags' => $flags,
      'public_class' => $publicClass,
      'private_class' => $privateClass,
      'cdn_enabled' => !empty($observed['cdn_rewrite_active']),
      'mismatch' => !empty($observed['mismatch']) || !$wrapperMatches || !$privateLocal,
      'wrapper_matches_process' => $wrapperMatches && $privateLocal,
      'maintenance' => $maintenance,
      'runtime' => RuntimeState::read(),
      'switch_allowed' => TRUE,
      'switch_reason' => '切换会先复制目标侧缺失的公开原件，再更新私有目录里的运行状态文件。PHP-FPM、Drush 和 Cron 读同一份文件。包装器类保持不变。源文件和 R2 对象不会删除。',
    ];
  }

  /**
   * @return list<array{file: string, readable: bool, flags_present: bool}>
   *   Whether maintenance commands include the five runtime switches.
   */
  private static function maintenanceFlags(): array {
    $rows = [];
    foreach ([
      '/etc/php/8.5/fpm/pool.d/www.conf',
      '/etc/cron.d/xedc-traffic',
      '/root/backup.sh',
    ] as $path) {
      $readable = is_readable($path);
      $present = FALSE;
      if ($readable) {
        $text = (string) file_get_contents($path);
        $present = str_contains($text, 'XEDC_STORAGE_RUNTIME')
          && str_contains($text, 'STORAGE_PUBLIC_TAKEOVER')
          && str_contains($text, 'STORAGE_PRIVATE_TAKEOVER')
          && str_contains($text, 'STORAGE_CDN_ENABLED')
          && str_contains($text, 'STORAGE_I_UNDERSTAND_TAKEOVER');
      }
      $rows[] = [
        'file' => $path,
        'readable' => $readable,
        'flags_present' => $present,
      ];
    }
    return $rows;
  }

}
