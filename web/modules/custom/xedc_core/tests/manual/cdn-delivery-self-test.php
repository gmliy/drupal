<?php

/**
 * @file
 * Delivery URL checks against the booted site.
 *
 * Does not enable production switches, upload objects, or change file URIs.
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/cdn-delivery-self-test.php
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\image\Entity\ImageStyle;
use Drupal\xedc_core\Ops\Storage\CdnUrlPolicy;

$failed = 0;
$check = static function (string $label, bool $condition) use (&$failed): void {
  echo ($condition ? 'OK  ' : 'FAIL ') . $label . PHP_EOL;
  if (!$condition) {
    $failed++;
  }
};

$cdnHost = 'cdn.gitxx.xyz';
$apiHost = 'r2.cloudflarestorage.com';
$safe = static function (array $result) use ($cdnHost, $apiHost): bool {
  $url = (string) ($result['url'] ?? '');
  if (!empty($result['cdn'])) {
    return FALSE;
  }
  return !str_contains($url, $cdnHost) && !str_contains($url, $apiHost);
};

$reflection = new ReflectionClass(Settings::class);
$instance = $reflection->getProperty('instance');
$original = $instance->getValue();
$restore = static function () use ($instance, $original): void {
  $instance->setValue(NULL, $original);
};

try {
  $check('runtime takeover is off', Settings::get('xedc_storage.runtime_applied') !== TRUE);
  $check('cdn rewrite is off', Settings::get('xedc_storage.cdn_enabled') !== TRUE);
  $check('public takeover is off', Settings::get('s3fs.use_s3_for_public') !== TRUE);
  $check('private takeover is off', Settings::get('s3fs.use_s3_for_private') !== TRUE);

  $delivery = \Drupal::service('xedc_core.storage_delivery');
  $publicUri = (string) (\Drupal::database()->query("SELECT uri FROM {file_managed} WHERE uri LIKE 'public://%' AND uri NOT LIKE 'public://styles/%' ORDER BY fid LIMIT 1")->fetchField() ?: 'public://photo.jpg');
  $checks = [
    'local public file' => $delivery->forUri($publicUri),
    'plain public uri' => $delivery->forUri('public://photo.jpg'),
    'image style uri' => $delivery->forUri('public://styles/thumbnail/public/photo.jpg'),
    'private uri' => $delivery->forUri('private://member.zip', 4),
    'unknown scheme' => $delivery->forUri('ftp://example.com/a.bin'),
    'empty uri' => $delivery->forUri(''),
    'parent segment' => $delivery->forUri('public://../secret.txt'),
  ];
  foreach ($checks as $label => $result) {
    $check($label . ' stays off the cdn', $safe($result));
  }
  $check('unknown uri has no url', ($checks['unknown scheme']['url'] ?? '') === '' && ($checks['empty uri']['kind'] ?? '') === 'unknown');

  $style = ImageStyle::load('thumbnail');
  if ($style) {
    $styleUrl = $style->buildUrl($publicUri);
    $check('image style builder stays off the cdn', !str_contains($styleUrl, $cdnHost) && !str_contains($styleUrl, $apiHost));
  }
  else {
    $check('thumbnail style exists', FALSE);
  }

  $base = $original->getAll();
  $remote = $base;
  $remote['xedc_storage.cdn_enabled'] = TRUE;
  $remote['xedc_storage.cdn_base_url'] = CdnUrlPolicy::PRODUCTION_BASE;
  $remote['xedc_storage.endpoint'] = 'https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com';
  $remote['s3fs.use_s3_for_public'] = TRUE;
  $remote['s3fs.use_s3_for_private'] = TRUE;
  $instance->setValue(NULL, new Settings($remote));
  $public = $delivery->forUri('public://photo.jpg');
  $styleRemote = $delivery->forUri('public://styles/thumbnail/public/photo.jpg');
  $private = $delivery->forUri('private://member.zip', 4);
  $s3Private = $delivery->forUri('s3://s3fs-private/member.zip', 4);
  $s3Public = $delivery->forUri('s3://s3fs-public/photo.jpg');
  $check('remote public file uses cdn.gitxx.xyz', ($public['cdn'] ?? FALSE) === TRUE && str_starts_with((string) $public['url'], CdnUrlPolicy::PRODUCTION_BASE . '/s3fs-public/'));
  $check('remote image style uses the public prefix', ($styleRemote['cdn'] ?? FALSE) === TRUE && str_contains((string) $styleRemote['url'], '/s3fs-public/styles/'));
  $check('remote private file uses the protected route', ($private['cdn'] ?? TRUE) === FALSE && str_contains((string) $private['url'], '/xedc/storage/protected/4') && !str_contains((string) $private['url'], $cdnHost));
  $check('s3 private key is not a cdn url', ($s3Private['cdn'] ?? TRUE) === FALSE && !str_contains((string) $s3Private['url'], $cdnHost));
  $check('s3 public key uses the cdn', ($s3Public['cdn'] ?? FALSE) === TRUE && str_starts_with((string) $s3Public['url'], CdnUrlPolicy::PRODUCTION_BASE . '/s3fs-public/photo.jpg'));

  $api = $remote;
  $api['xedc_storage.cdn_base_url'] = 'https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com';
  $instance->setValue(NULL, new Settings($api));
  $apiResult = $delivery->forUri('public://photo.jpg');
  $check('api endpoint is not returned as the cdn', empty($apiResult['cdn']) && !str_contains((string) ($apiResult['url'] ?? ''), $apiHost));

  $local = $remote;
  $local['s3fs.use_s3_for_public'] = FALSE;
  $local['s3fs.use_s3_for_private'] = FALSE;
  $local['xedc_storage.cdn_base_url'] = CdnUrlPolicy::PRODUCTION_BASE;
  $instance->setValue(NULL, new Settings($local));
  $stillLocal = $delivery->forUri('public://photo.jpg');
  $check('cdn on does not rewrite a local public file', $safe($stillLocal));
}
finally {
  $restore();
}

$passed = $failed === 0;
\Drupal::service('xedc_core.storage_journal')->put('cdn_delivery', [
  'passed' => $passed,
  'failed' => $failed,
  'message' => $passed ? '本地、私有和未知地址没有改写成 CDN。公开远程对象才会使用 cdn.gitxx.xyz。' : '地址规则测试有失败项。',
  'checked' => \Drupal::time()->getRequestTime(),
]);
echo ($passed ? 'PASS' : 'FAIL') . ' delivery ' . $failed . PHP_EOL;
return;
