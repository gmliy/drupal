<?php

/**
 * @file
 * Final CDN isolation, cache and video retest.
 *
 * Creates objects only under the test prefix and deletes those objects.
 * Does not change Cloudflare, bucket policy, file URIs, or production switches.
 * Run: drush php:script web/modules/custom/xedc_core/tests/manual/cdn-isolation-retest.php
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\xedc_core\Ops\Storage\BootGuard;
use Drupal\xedc_core\Ops\Storage\PlayableMp4;
use GuzzleHttp\ClientInterface;

$line = static function (string $label, string $value = ''): void {
  echo $label . ($value !== '' ? ' :: ' . $value : '') . PHP_EOL;
};

if (Settings::get('xedc_storage.runtime_applied') === TRUE || Settings::get('xedc_storage.runtime_requested') === TRUE || Settings::get('s3fs.use_s3_for_public') === TRUE || Settings::get('s3fs.use_s3_for_private') === TRUE || Settings::get('xedc_storage.cdn_enabled') === TRUE) {
  $line('FAIL production switch is on; retest was not started');
  return;
}
foreach (['XEDC_STORAGE_RUNTIME', 'STORAGE_I_UNDERSTAND_TAKEOVER', 'STORAGE_PUBLIC_TAKEOVER', 'STORAGE_PRIVATE_TAKEOVER', 'STORAGE_CDN_ENABLED'] as $name) {
  $value = getenv($name);
  if ($value === '1') {
    $line('FAIL environment flag is on', $name);
    return;
  }
}

$profileId = (string) \Drupal::service('xedc_core.storage_config')->activeId();
$profile = $profileId === '' ? NULL : \Drupal::service('xedc_core.storage_config')->profile($profileId);
$resolved = is_array($profile) ? \Drupal::service('xedc_core.storage_credentials')->resolve($profile) : ['ok' => FALSE];
if ($profile === NULL || empty($resolved['ok']) || !is_array($resolved['connection'] ?? NULL)) {
  $line('FAIL credentials are not usable; no object was uploaded');
  return;
}
$connection = $resolved['connection'];
unset($resolved);
$base = rtrim((string) \Drupal::service('xedc_core.storage_uris')->configuredPublicBase($profile), '/');
if ($base !== 'https://cdn.gitxx.xyz') {
  $line('FAIL cdn base is not the production hostname');
  return;
}

$filesBefore = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {file_managed}')->fetchField();
$writer = \Drupal::service('xedc_core.storage_writer');
$reader = \Drupal::service('xedc_core.storage_reader');
$temp = \Drupal::service('xedc_core.storage_temp');
/** @var \GuzzleHttp\ClientInterface $http */
$http = \Drupal::service('http_client');
$stamp = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
$privateKey = 's3fs-private/_xedc_storage_test/retest-' . $stamp . '.txt';
$publicKey = 's3fs-public/_xedc_storage_test/retest-' . $stamp . '.txt';
$privateBody = 'xedc-private-' . bin2hex(random_bytes(8));
$publicBody = 'xedc-public-' . bin2hex(random_bytes(8));
$created = [];
$localFiles = [];

$probe = static function (ClientInterface $http, string $url): array {
  $response = $http->request('GET', $url, [
    'timeout' => 25,
    'http_errors' => FALSE,
    'allow_redirects' => FALSE,
    'headers' => ['Cache-Control' => 'no-cache'],
  ]);
  return [
    'status' => $response->getStatusCode(),
    'body' => (string) $response->getBody(),
    'cache_control' => $response->getHeaderLine('Cache-Control'),
    'cf_cache_status' => $response->getHeaderLine('CF-Cache-Status'),
    'age' => $response->getHeaderLine('Age'),
    'content_type' => $response->getHeaderLine('Content-Type'),
    'content_range' => $response->getHeaderLine('Content-Range'),
    'accept_ranges' => $response->getHeaderLine('Accept-Ranges'),
  ];
};

$classify = static function (array $response, string $secret): string {
  if ($secret !== '' && $response['body'] === $secret) {
    return 'exposed';
  }
  if (in_array($response['status'], [401, 403], TRUE)) {
    return 'blocked';
  }
  return 'not_proof';
};

$upload = static function (string $path, string $key, string $mime, string $cache) use ($writer, $connection, &$created): array {
  $result = $writer->putFile($connection, $path, $key, [
    'mime' => $mime,
    'cache_control' => $cache,
    'purpose' => 'test',
    'require_cache' => FALSE,
    'overwrite' => FALSE,
  ]);
  if (!empty($result['ok'])) {
    $created[] = $key;
  }
  return $result;
};

try {
  $text = $temp->createFile('retest', '.txt');
  $localFiles[] = $text;
  file_put_contents($text, $privateBody);
  $privateUpload = $upload($text, $privateKey, 'text/plain', 'private, max-age=0, no-store');
  file_put_contents($text, $publicBody);
  $publicUpload = $upload($text, $publicKey, 'text/plain', 'public, max-age=60');
  if (empty($privateUpload['ok']) || empty($publicUpload['ok'])) {
    $line('FAIL upload', 'private=' . (string) ($privateUpload['message'] ?? '') . ' public=' . (string) ($publicUpload['message'] ?? ''));
    return;
  }
  $privateHead = $reader->head($connection, $privateKey);
  $line('private object exists in bucket', $privateHead === NULL ? 'no' : 'yes');
  if ($privateHead === NULL) {
    $line('FAIL private object missing after upload');
    return;
  }

  $slash = static function (string $key, string $token): string {
    return str_replace('/', $token, $key);
  };
  $encodedPrefix = implode('', array_map(static fn (string $char): string => '%' . strtoupper(bin2hex($char)), str_split('s3fs-private')));
  $tail = substr($privateKey, strlen('s3fs-private/'));
  $cases = [
    'normal' => $base . '/' . $privateKey,
    'encoded_slash_upper' => $base . '/' . $slash($privateKey, '%2F'),
    'encoded_slash_lower' => $base . '/' . $slash($privateKey, '%2f'),
    'double_encoded_slash' => $base . '/' . $slash($privateKey, '%252F'),
    'encoded_prefix' => $base . '/' . $encodedPrefix . '/' . $tail,
    'encoded_dot_segment' => $base . '/s3fs-private/%2e%2e/s3fs-private/' . $tail,
    'case_upper' => $base . '/S3FS-PRIVATE/' . $tail,
    'case_mixed' => $base . '/s3fs-Private/' . $tail,
    'extra_leading_slash' => $base . '//' . $privateKey,
    'extra_inner_slash' => $base . '/s3fs-private//' . $tail,
    'dot_segment' => $base . '/s3fs-private/./' . $tail,
    'query' => $base . '/' . $privateKey . '?retest=1',
    'query_encoded' => $base . '/' . $privateKey . '?path=%2F' . rawurlencode($tail),
  ];

  $exposed = [];
  $blocked = [];
  $unproven = [];
  foreach ($cases as $name => $url) {
    $response = $probe($http, $url);
    $verdict = $classify($response, $privateBody);
    $path = (string) parse_url($url, PHP_URL_PATH);
    $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
    $line($verdict . ' ' . $name, 'HTTP ' . $response['status'] . ' path=' . $path . ($query !== '' ? '?' . $query : '') . ' bytes=' . strlen($response['body']));
    if ($verdict === 'exposed') {
      $exposed[$name] = $response['status'];
    }
    elseif ($verdict === 'blocked') {
      $blocked[$name] = $response['status'];
    }
    else {
      $unproven[$name] = $response['status'];
    }
  }

  $public = $probe($http, $base . '/' . $publicKey);
  $publicOk = $public['status'] === 200 && $public['body'] === $publicBody;
  $line($publicOk ? 'OK public' : 'FAIL public', 'HTTP ' . $public['status'] . ' body_match=' . ($public['body'] === $publicBody ? 'yes' : 'no'));
  $normalBlocked = ($blocked['normal'] ?? 0) === 403 && !isset($exposed['normal']);
  $line($normalBlocked ? 'OK private normal denied' : 'FAIL private normal not denied', 'HTTP ' . (string) ($blocked['normal'] ?? $unproven['normal'] ?? $exposed['normal'] ?? 'none'));
  $line(isset($exposed) && $exposed === [] ? 'OK no private body returned' : 'FAIL private body returned', implode(',', array_keys($exposed)));

  $cacheCases = [
    'max-age-60' => ['public, max-age=60', 's3fs-public/_xedc_storage_test/retest-cache60-' . $stamp . '.txt', 'text/plain', 'xedc-cache60-' . bin2hex(random_bytes(4))],
    'max-age-3600' => ['public, max-age=3600', 's3fs-public/_xedc_storage_test/retest-cache3600-' . $stamp . '.txt', 'text/plain', 'xedc-cache3600-' . bin2hex(random_bytes(4))],
    'immutable' => ['public, max-age=31536000, immutable', 's3fs-public/_xedc_storage_test/retest-cache.' . substr(hash('sha256', $stamp), 0, 16) . '.txt', 'text/plain', 'xedc-immutable-' . bin2hex(random_bytes(4))],
  ];
  $cacheRows = [];
  foreach ($cacheCases as $name => [$cache, $key, $mime, $body]) {
    file_put_contents($text, $body);
    $stored = $upload($text, $key, $mime, $cache);
    if (empty($stored['ok'])) {
      $line('FAIL cache upload ' . $name, (string) ($stored['message'] ?? ''));
      continue;
    }
    $first = $probe($http, $base . '/' . $key);
    $second = $probe($http, $base . '/' . $key);
    $match = str_contains(strtolower($first['cache_control']), 'max-age=' . (preg_match('/max-age=(\d+)/', $cache, $found) === 1 ? $found[1] : ''));
    if (str_contains($cache, 'immutable')) {
      $match = $match && str_contains(strtolower($first['cache_control']), 'immutable');
    }
    $cacheRows[$name] = [
      'upload' => (string) ($stored['cache_control'] ?? ''),
      'first' => $first['cache_control'],
      'second' => $second['cache_control'],
      'cf_first' => $first['cf_cache_status'],
      'cf_second' => $second['cf_cache_status'],
      'age_first' => $first['age'],
      'age_second' => $second['age'],
      'body_ok' => $first['body'] === $body,
      'honored' => $match && $first['body'] === $body,
    ];
    $line(($cacheRows[$name]['honored'] ? 'OK ' : 'DIFF ') . 'cache ' . $name, 'upload=' . $cacheRows[$name]['upload'] . ' first=' . $first['cache_control'] . ' cf=' . $first['cf_cache_status'] . ' age=' . ($first['age'] === '' ? '-' : $first['age']) . ' second=' . $second['cache_control'] . ' cf=' . $second['cf_cache_status'] . ' age=' . ($second['age'] === '' ? '-' : $second['age']));
  }

  $videoPath = $temp->createFile('retest-video', '.mp4');
  $localFiles[] = $videoPath;
  $video = ['ok' => FALSE];
  if (!PlayableMp4::write($videoPath)) {
    $line('FAIL mp4', 'encoder unavailable');
  }
  else {
    $videoKey = 's3fs-public/_xedc_storage_test/retest-range-' . $stamp . '.mp4';
    $videoUpload = $upload($videoPath, $videoKey, 'video/mp4', 'public, max-age=60');
    if (empty($videoUpload['ok'])) {
      $line('FAIL mp4 upload', (string) ($videoUpload['message'] ?? ''));
    }
    else {
      $size = (int) filesize($videoPath);
      $url = $base . '/' . $videoKey;
      $full = $http->request('GET', $url, ['timeout' => 30, 'http_errors' => FALSE, 'allow_redirects' => FALSE]);
      $fullBody = (string) $full->getBody();
      $end = min(15, $size - 1);
      $start = min(40, $size - 1);
      $last = min($start + 31, $size - 1);
      $range = $http->request('GET', $url, ['timeout' => 30, 'http_errors' => FALSE, 'allow_redirects' => FALSE, 'headers' => ['Range' => 'bytes=0-' . $end]]);
      $middle = $http->request('GET', $url, ['timeout' => 30, 'http_errors' => FALSE, 'allow_redirects' => FALSE, 'headers' => ['Range' => 'bytes=' . $start . '-' . $last]]);
      $again = $probe($http, $url);
      $rangeBody = (string) $range->getBody();
      $middleBody = (string) $middle->getBody();
      $expectedHead = (string) file_get_contents($videoPath, FALSE, NULL, 0, $end + 1);
      $expectedMiddle = (string) file_get_contents($videoPath, FALSE, NULL, $start, $last - $start + 1);
      $contentRange = $range->getHeaderLine('Content-Range');
      $host = (string) parse_url($url, PHP_URL_HOST);
      $video = [
        'ok' => TRUE,
        'host' => $host,
        'get_status' => $full->getStatusCode(),
        'content_type' => $full->getHeaderLine('Content-Type'),
        'get_body_ok' => hash_equals(hash('sha256', (string) file_get_contents($videoPath)), hash('sha256', $fullBody)),
        'range_status' => $range->getStatusCode(),
        'content_range' => $contentRange,
        'content_range_ok' => (bool) preg_match('/bytes\s+0-' . $end . '\/' . $size . '/i', $contentRange),
        'bytes_ok' => $range->getStatusCode() === 206 && hash_equals($expectedHead, $rangeBody) && $middle->getStatusCode() === 206 && hash_equals($expectedMiddle, $middleBody),
        'middle_status' => $middle->getStatusCode(),
        'middle_range' => $middle->getHeaderLine('Content-Range'),
        'accept_ranges' => $range->getHeaderLine('Accept-Ranges') !== '' ? $range->getHeaderLine('Accept-Ranges') : $full->getHeaderLine('Accept-Ranges'),
        'upload_cache' => (string) ($videoUpload['cache_control'] ?? ''),
        'cdn_cache' => $full->getHeaderLine('Cache-Control'),
        'cf_first' => $full->getHeaderLine('CF-Cache-Status'),
        'cf_second' => $again['cf_cache_status'],
        'age_second' => $again['age'],
        'cache_second' => $again['cache_control'],
      ];
      $video['browser'] = cdn_retest_browser($url);
      $line('mp4 host', $host);
      $line('mp4 get', 'HTTP ' . $video['get_status'] . ' type=' . $video['content_type'] . ' body_ok=' . ($video['get_body_ok'] ? 'yes' : 'no'));
      $line('mp4 range', 'HTTP ' . $video['range_status'] . ' ' . $video['content_range'] . ' bytes_ok=' . ($video['bytes_ok'] ? 'yes' : 'no') . ' middle=' . $video['middle_status'] . ' ' . $video['middle_range']);
      $line('mp4 accept-ranges', $video['accept_ranges'] === '' ? 'none' : $video['accept_ranges']);
      $line('mp4 cache', 'upload=' . $video['upload_cache'] . ' first=' . $video['cdn_cache'] . ' cf=' . $video['cf_first'] . ' second=' . $video['cache_second'] . ' cf=' . $video['cf_second'] . ' age=' . ($video['age_second'] === '' ? '-' : $video['age_second']));
      $line('mp4 browser', (string) ($video['browser']['detail'] ?? ''));
    }
  }

  $r2dev = 'unverified';
  $saved = json_encode($profile);
  if (is_string($saved) && str_contains(strtolower($saved), 'r2.dev')) {
    $r2dev = 'configured';
  }
  $line('r2.dev', $r2dev === 'configured' ? 'hostname present in profile' : 'no hostname stored; switch was not readable');

  $filesAfter = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {file_managed}')->fetchField();
  $line('file entities', $filesBefore . ' -> ' . $filesAfter);
  $isolationOk = $normalBlocked && $exposed === [] && $publicOk;
  $summary = [
    'profile' => $profileId,
    'status' => $isolationOk ? 'attention' : 'failed',
    'http_status' => $public['status'],
    'body_ok' => $publicOk,
    'private_exposed' => $exposed !== [] ? TRUE : ($normalBlocked ? FALSE : NULL),
    'private_http_status' => $blocked['normal'] ?? $unproven['normal'] ?? $exposed['normal'] ?? 0,
    'private_blocked' => array_keys($blocked),
    'private_unproven' => $unproven,
    'private_bypass' => array_keys($exposed),
    'cache_mismatch' => in_array(FALSE, array_column($cacheRows, 'honored'), TRUE),
    'cache' => $cacheRows,
    'object_cache_control' => 'public, max-age=60',
    'cache_control' => $public['cache_control'],
    'cf_cache_status' => $public['cf_cache_status'],
    'r2dev' => $r2dev,
    'message' => $isolationOk
      ? '私有正常路径返回拒绝，编码变体没有返回对象正文。公开测试对象仍可读取。'
      : '私有隔离复测没有同时满足拒绝状态、无正文和公开对象可读。',
    'checked' => \Drupal::time()->getRequestTime(),
  ];
  $storedCdn = \Drupal::service('xedc_core.storage_journal')->all()['cdn'] ?? [];
  if (!is_array($storedCdn)) {
    $storedCdn = [];
  }
  $storedCdn[$profileId] = $summary;
  \Drupal::service('xedc_core.storage_journal')->put('cdn', $storedCdn);
  if (!empty($video['ok'])) {
    $rangeStored = \Drupal::service('xedc_core.storage_journal')->all()['cdn_range'] ?? [];
    if (!is_array($rangeStored)) {
      $rangeStored = [];
    }
    $rangeStored[$profileId] = [
      'profile' => $profileId,
      'status' => !empty($video['bytes_ok']) && !empty($video['get_body_ok']) ? 'attention' : 'failed',
      'get_status' => $video['get_status'],
      'content_type' => $video['content_type'],
      'get_body_ok' => $video['get_body_ok'],
      'status_code' => $video['range_status'],
      'content_range' => $video['content_range'],
      'content_range_ok' => $video['content_range_ok'],
      'bytes_ok' => $video['bytes_ok'],
      'accept_ranges' => $video['accept_ranges'],
      'accept_ranges_ok' => str_contains(strtolower((string) $video['accept_ranges']), 'bytes'),
      'object_cache_control' => $video['upload_cache'],
      'cache_control' => $video['cdn_cache'],
      'cache_mismatch' => !str_contains(strtolower((string) $video['cdn_cache']), 'max-age=60'),
      'cf_cache_status' => $video['cf_first'],
      'cf_cache_status_second' => $video['cf_second'],
      'browser' => $video['browser'],
      'message' => '复测视频走 cdn.gitxx.xyz。',
      'checked' => \Drupal::time()->getRequestTime(),
    ];
    \Drupal::service('xedc_core.storage_journal')->put('cdn_range', $rangeStored);
  }
  $line($isolationOk ? 'RETEST private isolation passed the deny check' : 'RETEST private isolation did not pass');
}
catch (\Throwable $error) {
  $line('FAIL exception', BootGuard::safeError($error, [
    (string) ($connection['access_key'] ?? ''),
    (string) ($connection['secret_key'] ?? ''),
  ]));
}
finally {
  foreach ($created as $key) {
    try {
      $gone = $writer->delete($connection, $key, 'test') && $reader->head($connection, $key) === NULL;
      $line($gone ? 'deleted' : 'DELETE-FAILED', $key);
    }
    catch (\Throwable $error) {
      $line('DELETE-FAILED', BootGuard::safeError($error, [
        (string) ($connection['access_key'] ?? ''),
        (string) ($connection['secret_key'] ?? ''),
      ]));
    }
  }
  foreach ($localFiles as $path) {
    if (is_string($path) && is_file($path)) {
      @unlink($path);
    }
  }
}

/**
 * Plays one CDN MP4 in headless Chromium.
 *
 * @return array{played: bool, seek: bool, detail: string}
 *   Browser result.
 */
function cdn_retest_browser(string $url): array {
  $binary = NULL;
  $found = glob('/snap/chromium/*/usr/lib/chromium-browser/chrome') ?: [];
  rsort($found);
  foreach ($found as $candidate) {
    if (is_string($candidate) && is_executable($candidate)) {
      $binary = $candidate;
      break;
    }
  }
  if ($binary === NULL) {
    return ['played' => FALSE, 'seek' => FALSE, 'detail' => '没有可用的浏览器'];
  }
  $page = tempnam(sys_get_temp_dir(), 'xedc-retest-');
  if (!is_string($page)) {
    return ['played' => FALSE, 'seek' => FALSE, 'detail' => '不能写临时页面'];
  }
  $html = $page . '.html';
  @unlink($page);
  file_put_contents($html, '<!doctype html><video id="v" muted playsinline src="' . htmlspecialchars($url, ENT_QUOTES) . '"></video><pre id="result">PLAY_WAIT</pre><script>
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
  $profile = sys_get_temp_dir() . '/xedc-chrome-retest-' . bin2hex(random_bytes(3));
  $command = 'HOME=/tmp XDG_RUNTIME_DIR=/tmp ' . $binary
    . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
    . ' --user-data-dir=' . escapeshellarg($profile)
    . ' --autoplay-policy=no-user-gesture-required --virtual-time-budget=12000 --timeout=25000 --dump-dom '
    . escapeshellarg('file://' . $html) . ' 2>/dev/null';
  $dom = shell_exec($command);
  @unlink($html);
  if (is_dir($profile)) {
    $cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($cleanup as $item) {
      $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($profile);
  }
  $text = is_string($dom) ? $dom : '';
  if (preg_match('/PLAY_OK duration=([0-9.]+|NaN|Infinity) time=([0-9.]+) seek=(yes|no)/', $text, $match) === 1) {
    return [
      'played' => $match[1] !== 'NaN' && (float) $match[1] > 0,
      'seek' => $match[3] === 'yes',
      'detail' => 'duration=' . $match[1] . ' time=' . $match[2] . ' seek=' . $match[3],
    ];
  }
  return ['played' => FALSE, 'seek' => FALSE, 'detail' => '浏览器没有返回播放结果'];
}
