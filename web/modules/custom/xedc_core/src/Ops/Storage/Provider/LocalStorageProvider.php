<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage\Provider;

use Drupal\Core\Site\Settings;

/**
 * Local public and private files. This does not delete or move them.
 */
final class LocalStorageProvider implements StorageProviderInterface {

  public function getId(): string {
    return 'local';
  }

  public function getLabel(): string {
    return '本地服务器';
  }

  public function getType(): string {
    return 'local';
  }

  public function isOperational(): bool {
    return TRUE;
  }

  public function capabilities(): array {
    return [
      'read' => TRUE,
      'write' => TRUE,
      'delete' => TRUE,
      'list' => TRUE,
      'public_url' => TRUE,
      'private_url' => TRUE,
      'cdn' => FALSE,
      'drupal_stream' => TRUE,
    ];
  }

  public function summary(): array {
    return [
      'endpoint' => '本机文件系统',
      'bucket' => '无',
      'region' => '无',
      'cdn' => '不使用',
      'public_prefix' => 'sites/default/files',
      'private_prefix' => 'private',
    ];
  }

  public function healthReadOnly(): array {
    $public = Settings::get('file_public_path');
    $publicPath = is_string($public) && $public !== '' ? $public : DRUPAL_ROOT . '/sites/default/files';
    $private = Settings::get('file_private_path');
    $privatePath = is_string($private) ? $private : '';
    $problems = [];
    if (!is_dir($publicPath) || !is_readable($publicPath)) {
      $problems[] = '公开目录不可读';
    }
    elseif (!is_writable($publicPath)) {
      $problems[] = '公开目录不可写';
    }
    if ($privatePath === '' || !is_dir($privatePath) || !is_readable($privatePath)) {
      $problems[] = '私有目录不可读';
    }
    $free = @disk_free_space($publicPath);
    $freeLabel = is_int($free) || is_float($free) ? (string) $free : '未知';
    if ($problems !== []) {
      return ['status' => 'bad', 'detail' => implode('；', $problems) . '。剩余字节 ' . $freeLabel . '。'];
    }
    return ['status' => 'ok', 'detail' => '公开和私有目录可读。剩余字节 ' . $freeLabel . '。没有删除文件。'];
  }

}
