<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\s3fs\StreamWrapper\S3fsStream;

/**
 * Reads the stream wrapper class that this process is actually using.
 *
 * The class comes from the compiled service container. An environment variable
 * cannot change it until that container is rebuilt by a request which sees
 * the variable.
 */
final class WrapperStatus {

  public static function classForScheme(string $scheme): string {
    if (!class_exists(\Drupal::class) || !\Drupal::hasContainer() || !\Drupal::hasService('stream_wrapper_manager')) {
      return '';
    }
    $wrapper = \Drupal::service('stream_wrapper_manager')->getViaScheme($scheme);
    return is_object($wrapper) ? $wrapper::class : '';
  }

  public static function isObjectStorageClass(string $class): bool {
    return $class !== '' && is_a($class, S3fsStream::class, TRUE);
  }

  public static function isLocalClass(string $class): bool {
    return $class !== '' && is_a($class, LocalStream::class, TRUE) && !self::isObjectStorageClass($class);
  }

  /**
   * TRUE only when the live wrapper class stores this scheme on object storage.
   */
  public static function schemeUsesObjectStorage(string $scheme): bool {
    if ($scheme === 'private') {
      return FALSE;
    }
    $class = self::classForScheme($scheme);
    $requested = $scheme === 'public' && RuntimeState::usesObjectStorage();
    if ($requested && !self::isObjectStorageClass($class)) {
      self::logMismatch($scheme, $class);
      return FALSE;
    }
    return $requested && self::isObjectStorageClass($class);
  }

  private static function logMismatch(string $scheme, string $class): void {
    static $logged = [];
    if (isset($logged[$scheme]) || !\Drupal::hasService('logger.channel.xedc_core')) {
      $logged[$scheme] = TRUE;
      return;
    }
    $logged[$scheme] = TRUE;
    \Drupal::logger('xedc_core')->warning('接管标志已请求 @scheme，但当前进程的 Stream Wrapper 仍是 @class。本次请求继续使用该 Wrapper，不把接管记为成功。需要由带有相应环境变量的 PHP-FPM 请求重新编译容器。', [
      '@scheme' => $scheme,
      '@class' => $class === '' ? '未知' : $class,
    ]);
  }

}
