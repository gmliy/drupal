<?php

/**
 * @file
 * Applies vendor-neutral S3 settings from the environment.
 *
 * Included from settings.php. Missing or invalid values keep the local
 * public and private filesystems. This file must not contain secrets.
 */

if (!isset($settings) || !is_array($settings) || !isset($app_root) || !is_string($app_root)) {
  return;
}
if (!isset($config) || !is_array($config)) {
  $config = [];
}

try {
  $guard = $app_root . '/modules/custom/xedc_core/src/Ops/Storage/BootGuard.php';
  if (!is_readable($guard)) {
    $settings['xedc_storage.boot'] = 'missing';
    $settings['xedc_storage.runtime_applied'] = FALSE;
    $settings['xedc_storage.runtime_error'] = '对象存储运行时文件缺失，已保持本地文件系统。';
    return;
  }
  require_once $guard;
  Drupal\xedc_core\Ops\Storage\BootGuard::apply($settings, $config);
}
catch (Throwable $exception) {
  $settings['xedc_storage.boot'] = 'error';
  $settings['xedc_storage.runtime_applied'] = FALSE;
  $settings['xedc_storage.runtime_error'] = '对象存储运行时配置无法读取，已保持本地文件系统。';
  $settings['s3fs.use_s3_for_public'] = FALSE;
  $settings['s3fs.use_s3_for_private'] = FALSE;
}
