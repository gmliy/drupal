<?php

/**
 * @file
 * Live CDN isolation and Range checks.
 *
 * Uploads only under the test prefix, then deletes those objects.
 * Does not enable takeover or change production files.
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/cdn-security-self-test.php
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\xedc_core\Ops\Storage\S3ClientFactory;

$failed = 0;
$line = static function (string $label, string $value = '') use (&$failed): void {
  echo $label . ($value !== '' ? ' :: ' . $value : '') . PHP_EOL;
};

$check = static function (string $label, bool $condition, string $detail = '') use (&$failed, $line): void {
  $line(($condition ? 'OK  ' : 'FAIL ') . $label, $detail);
  if (!$condition) {
    $failed++;
  }
};

if (Settings::get('xedc_storage.runtime_applied') === TRUE || Settings::get('s3fs.use_s3_for_public') === TRUE || Settings::get('s3fs.use_s3_for_private') === TRUE || Settings::get('xedc_storage.cdn_enabled') === TRUE) {
  $line('FAIL production switch is on; live probe was not started');
  return;
}

$profileId = (string) \Drupal::service('xedc_core.storage_config')->activeId();
$profile = $profileId === '' ? NULL : \Drupal::service('xedc_core.storage_config')->profile($profileId);
if ($profile === NULL) {
  $line('FAIL no active storage profile');
  return;
}
$resolved = \Drupal::service('xedc_core.storage_credentials')->resolve($profile);
if (empty($resolved['ok']) || !is_array($resolved['connection'] ?? NULL)) {
  $line('FAIL credentials are not usable; no object was uploaded');
  return;
}
$connection = $resolved['connection'];
unset($resolved);
$client = S3ClientFactory::create($connection);
$bucket = (string) $connection['bucket'];

$countPrefix = static function (string $prefix) use ($client, $bucket): int {
  $response = $client->listObjectsV2([
    'Bucket' => $bucket,
    'Prefix' => $prefix,
    'MaxKeys' => 50,
  ]);
  $contents = $response['Contents'] ?? [];
  return is_array($contents) ? count($contents) : 0;
};
$before = [
  'public' => $countPrefix('s3fs-public/_xedc_storage_test/'),
  'private' => $countPrefix('s3fs-private/_xedc_storage_test/'),
];
$line('test prefix before', 'public=' . $before['public'] . ' private=' . $before['private']);

$browser = static function (string $url): array {
  $binary = NULL;
  $found = glob('/snap/chromium/*/usr/lib/chromium-browser/chrome') ?: [];
  rsort($found);
  foreach (array_merge($found, ['/usr/bin/chromium', '/usr/bin/chromium-browser']) as $candidate) {
    if (is_string($candidate) && is_executable($candidate) && !is_link($candidate)) {
      $binary = $candidate;
      break;
    }
  }
  if ($binary === NULL) {
    return ['played' => FALSE, 'seek' => FALSE, 'detail' => '没有可用的浏览器'];
  }
  $page = tempnam(sys_get_temp_dir(), 'xedc-cdn-');
  if (!is_string($page)) {
    return ['played' => FALSE, 'seek' => FALSE, 'detail' => '不能写临时页面'];
  }
  $html = $page . '.html';
  rename($page, $html);
  $source = htmlspecialchars($url, ENT_QUOTES);
  file_put_contents($html, '<!doctype html><video id="v" muted playsinline src="' . $source . '"></video><pre id="result">PLAY_WAIT</pre><script>
const v=document.getElementById("v");
const out=document.getElementById("result");
v.addEventListener("error",()=>out.textContent="PLAY_ERROR "+(v.error?v.error.code:"0"));
v.addEventListener("loadeddata",async()=>{
  let seek=false;
  try{await v.play();}catch(e){}
  if(Number.isFinite(v.duration)&&v.duration>0.3){
    v.currentTime=Math.min(1,v.duration/2);
    await new Promise((resolve)=>{const timer=setTimeout(resolve,1500);v.addEventListener("seeked",()=>{clearTimeout(timer);resolve();},{once:true});});
    seek=v.currentTime>0.2;
  }
  out.textContent="PLAY_OK duration="+v.duration+" time="+v.currentTime+" seek="+(seek?"yes":"no")+" ready="+v.readyState;
});
</script>');
  $profile = tempnam(sys_get_temp_dir(), 'xedc-chrome-');
  if (is_string($profile)) {
    @unlink($profile);
  }
  $profile = is_string($profile) ? $profile : sys_get_temp_dir() . '/xedc-chrome';
  $command = 'HOME=/tmp XDG_RUNTIME_DIR=/tmp ' . $binary
    . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
    . ' --user-data-dir=' . escapeshellarg($profile)
    . ' --autoplay-policy=no-user-gesture-required --virtual-time-budget=12000 --timeout=25000 --dump-dom '
    . escapeshellarg('file://' . $html) . ' 2>/dev/null';
  $dom = shell_exec($command);
  if (is_dir($profile)) {
    $cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($cleanup as $item) {
      $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($profile);
  }
  @unlink($html);
  $text = is_string($dom) ? $dom : '';
  if (preg_match('/PLAY_OK duration=([0-9.]+|NaN|Infinity) time=([0-9.]+) seek=(yes|no)/', $text, $match) === 1) {
    return [
      'played' => $match[1] !== 'NaN' && (float) $match[1] > 0,
      'seek' => $match[3] === 'yes',
      'detail' => 'duration=' . $match[1] . ' time=' . $match[2] . ' seek=' . $match[3],
    ];
  }
  $detail = '浏览器没有返回播放结果';
  if (preg_match('/PLAY_ERROR\s+\d+/', $text, $error) === 1) {
    $detail = $error[0];
  }
  return ['played' => FALSE, 'seek' => FALSE, 'detail' => $detail];
};

$inspector = \Drupal::service('xedc_core.storage_cdn_inspector');
$videoOnly = getenv('XEDC_CDN_VIDEO_ONLY') === '1';
if (!$videoOnly) {
  $cdn = $inspector->inspect($profileId);
  $check('public object readable', (int) ($cdn['http_status'] ?? 0) === 200 && !empty($cdn['body_ok']), 'HTTP ' . (string) ($cdn['http_status'] ?? ''));
  $check('private prefix is not anonymously readable', ($cdn['private_exposed'] ?? TRUE) === FALSE, 'HTTP ' . (string) ($cdn['private_http_status'] ?? '') . ' encoded ' . (string) ($cdn['private_encoded_status'] ?? ''));
  $line('cache object', (string) ($cdn['object_cache_control'] ?? ''));
  $line('cache cdn', (string) ($cdn['cache_control'] ?? ''));
  $line('cache second', (string) ($cdn['cache_status_second'] ?? '') . ' ' . (string) ($cdn['cache_control_second'] ?? ''));
  $line('versioned object', (string) ($cdn['versioned_object_cache'] ?? ''));
  $line('versioned cdn', (string) ($cdn['versioned_cdn_cache'] ?? ''));
  $line('cdn message', (string) ($cdn['message'] ?? ''));
}
else {
  $line('private probe', 'skipped');
}

$range = $inspector->inspectVideo($profileId, $browser);
$check('mp4 get is 200 video/mp4', (int) ($range['get_status'] ?? 0) === 200 && str_starts_with(strtolower((string) ($range['content_type'] ?? '')), 'video/mp4'));
$check('mp4 range is 206 and bytes match', !empty($range['bytes_ok']) && (int) ($range['status_code'] ?? 0) === 206 && !empty($range['content_range_ok']), (string) ($range['content_range'] ?? ''));
$line('accept-ranges', (string) ($range['accept_ranges'] ?? ''));
$line('range cache', (string) ($range['cache_control'] ?? '') . ' / ' . (string) ($range['cf_cache_status'] ?? '') . ' / ' . (string) ($range['cf_cache_status_second'] ?? ''));
$browserResult = is_array($range['browser'] ?? NULL) ? $range['browser'] : [];
$line('browser', (string) ($browserResult['detail'] ?? ''));
$line('range message', (string) ($range['message'] ?? ''));

$after = [
  'public' => $countPrefix('s3fs-public/_xedc_storage_test/'),
  'private' => $countPrefix('s3fs-private/_xedc_storage_test/'),
];
$check('test objects removed', $after['public'] <= $before['public'] && $after['private'] <= $before['private'], 'public=' . $after['public'] . ' private=' . $after['private']);
$line(($failed === 0 ? 'PASS' : 'DONE') . ' security failures ' . $failed);
return;
