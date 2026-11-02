<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\File\FileSystemInterface;
use Drupal\image\Entity\ImageStyle;

/**
 * Exercises image, video and ZIP bytes against the integration prefix only.
 *
 * It does not enable runtime, does not change file URIs, and does not create
 * production content. Created objects are deleted before it returns.
 */
final class MediaObjectPipeline {

  public function __construct(
    protected StorageConfig $config,
    protected CredentialVault $credentials,
    protected StorageUriResolver $uris,
    protected RemoteFileReader $reader,
    protected RemoteFileWriter $writer,
    protected LocalTempFileManager $temp,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * @return array<string, mixed>
   *   Public report. Credentials and presigned URLs are omitted.
   */
  public function run(): array {
    $report = [
      'ok' => FALSE,
      'profile' => $this->config->activeId(),
      'prefix' => StorageUriResolver::INTEGRATION_PREFIX,
      'steps' => [],
      'cleanup' => [],
      'bucket_objects_seen' => NULL,
      'error' => '',
    ];
    $connection = NULL;
    $secrets = [];
    $created = [];
    $local = [];
    try {
      $connection = $this->connection($report);
      if ($connection === NULL) {
        return $report;
      }
      $secrets = [
        (string) ($connection['access_key'] ?? ''),
        (string) ($connection['secret_key'] ?? ''),
      ];
      $run = bin2hex(random_bytes(4));
      $report['run'] = $run;
      $this->images($connection, $run, $report, $created, $local);
      $this->video($connection, $run, $report, $created, $local);
      $this->zip($connection, $run, $report, $created, $local);
      $this->cdn($connection, $report, $created);
      $report['ok'] = $this->requiredOk($report);
    }
    catch (\Throwable $error) {
      $report['error'] = BootGuard::safeError($error, $secrets);
      $report['ok'] = FALSE;
    }
    finally {
      foreach ($local as $path) {
        if (is_string($path) && $path !== '') {
          $this->temp->delete($path);
        }
      }
      if (is_array($connection)) {
        foreach ($created as $key) {
          $gone = $this->writer->delete($connection, $key, 'integration');
          $report['cleanup'][$key] = $gone;
        }
        $report['bucket_objects_seen'] = $this->countBucket($connection, $secrets);
        $report['prefix_counts'] = [
          'integration' => $this->countPrefix($connection, StorageUriResolver::INTEGRATION_PREFIX, $secrets),
          'storage_test' => $this->countPrefix($connection, BootGuard::TEST_PREFIX, $secrets),
        ];
      }
      $connection = NULL;
    }
    return $report;
  }

  /**
   * @param array<string, mixed> $report
   *   Mutable report.
   *
   * @return array<string, mixed>|null
   *   Active connection, without being copied into the report.
   */
  private function connection(array &$report): ?array {
    if ($report['profile'] !== 'silly') {
      $report['error'] = '活动供应商不是 silly，已停止，没有写入对象。';
      return NULL;
    }
    $profile = $this->config->profile('silly');
    if ($profile === NULL) {
      $report['error'] = '找不到活动配置 silly。';
      return NULL;
    }
    $resolved = $this->credentials->resolve($profile);
    if (empty($resolved['ok']) || !is_array($resolved['connection'] ?? NULL)) {
      $report['error'] = '活动配置凭证不可用，没有改用其他配置。';
      return NULL;
    }
    $report['bucket'] = (string) ($resolved['connection']['bucket'] ?? '');
    $report['region'] = (string) ($resolved['connection']['region'] ?? '');
    return $resolved['connection'];
  }

  /**
   * @param array<string, mixed> $connection
   *   Active connection.
   * @param array<string, mixed> $report
   *   Mutable report.
   * @param list<string> $created
   *   Object keys to delete.
   * @param list<string> $local
   *   Temp paths to delete.
   */
  private function images(array $connection, string $run, array &$report, array &$created, array &$local): void {
    $samples = [
      'jpeg' => $this->raster('jpeg'),
      'png' => $this->raster('png'),
      'webp' => $this->raster('webp'),
    ];
    if (function_exists('imageavif') && function_exists('imagecreatefromavif')) {
      $samples['avif'] = $this->raster('avif');
    }
    else {
      $report['steps']['avif_encoder'] = ['ok' => FALSE, 'detail' => 'GD 不能写 AVIF，未伪造成功。'];
    }
    foreach ($samples as $name => $path) {
      if ($path === NULL) {
        $report['steps'][$name] = ['ok' => FALSE, 'detail' => '本地样本没有生成。'];
        continue;
      }
      $local[] = $path;
      $key = StorageUriResolver::INTEGRATION_PREFIX . $run . '/images/sample.' . $name;
      $mime = 'image/' . ($name === 'jpeg' ? 'jpeg' : $name);
      $first = $this->put($connection, $path, $key, $mime);
      $second = $first['ok'] ?? FALSE ? $this->put($connection, $path, $key, $mime) : ['ok' => FALSE, 'status' => 'skipped'];
      if (($first['ok'] ?? FALSE) === TRUE) {
        $created[] = $key;
      }
      $report['steps'][$name] = [
        'ok' => ($first['ok'] ?? FALSE) === TRUE && ($first['sha256'] ?? '') !== '' && ($second['status'] ?? '') === 'exists' && ($first['mime'] ?? '') === $mime,
        'bytes' => (int) ($first['bytes'] ?? 0),
        'mime' => (string) ($first['mime'] ?? ''),
        'repeat' => (string) ($second['status'] ?? ''),
        'detail' => (string) ($first['message'] ?? ''),
      ];
    }
    $jpeg = $samples['jpeg'] ?? NULL;
    if (is_string($jpeg) && function_exists('imagecreatefromjpeg') && function_exists('imagewebp')) {
      $webp = $this->temp->createFile('derived', '.webp');
      $local[] = $webp;
      $image = @imagecreatefromjpeg($jpeg);
      $saved = $image ? @imagewebp($image, $webp, 80) : FALSE;
      if ($image) {
        imagedestroy($image);
      }
      $key = StorageUriResolver::INTEGRATION_PREFIX . $run . '/images/derived.webp';
      $stored = $saved ? $this->put($connection, $webp, $key, 'image/webp') : ['ok' => FALSE, 'message' => 'WebP 转换失败，原图样本已保留。'];
      if (($stored['ok'] ?? FALSE) === TRUE) {
        $created[] = $key;
      }
      $report['steps']['derived_webp'] = [
        'ok' => ($stored['ok'] ?? FALSE) === TRUE && is_file($jpeg) && ($stored['mime'] ?? '') === 'image/webp',
        'mime' => (string) ($stored['mime'] ?? ''),
        'detail' => (string) ($stored['message'] ?? ''),
      ];
    }
    $this->styles($connection, $run, $jpeg, $report, $created, $local);
  }

  /**
   * @param array<string, mixed> $connection
   *   Active connection.
   * @param array<string, mixed> $report
   *   Mutable report.
   * @param list<string> $created
   *   Object keys.
   * @param list<string> $local
   *   Temp paths.
   */
  private function styles(array $connection, string $run, ?string $jpeg, array &$report, array &$created, array &$local): void {
    if (!is_string($jpeg)) {
      return;
    }
    foreach (['thumbnail', 'photo_gallery_650'] as $id) {
      $style = ImageStyle::load($id);
      if (!$style) {
        $report['steps']['style_' . $id] = ['ok' => FALSE, 'detail' => '样式不存在。'];
        continue;
      }
      $extension = pathinfo($style->buildUri('public://probe.jpg'), PATHINFO_EXTENSION);
      $extension = $extension === '' ? 'jpg' : $extension;
      $directory = 'temporary://xedc-r2-style';
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $dest = $directory . '/probe-' . bin2hex(random_bytes(4)) . '.' . $extension;
      $ok = $style->createDerivative($jpeg, $dest);
      $real = $this->fileSystem->realpath($dest);
      $key = StorageUriResolver::INTEGRATION_PREFIX . $run . '/styles/' . $id . '.' . $extension;
      $stored = ['ok' => FALSE, 'message' => '样式没有生成，原图样本已保留。'];
      if ($ok && is_string($real) && is_file($real)) {
        $local[] = $real;
        $stored = $this->put($connection, $real, $key, 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension));
        if (($stored['ok'] ?? FALSE) === TRUE) {
          $created[] = $key;
        }
      }
      if (is_string($real)) {
        $this->fileSystem->delete($dest);
      }
      $styleMime = 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension);
      $report['steps']['style_' . $id] = [
        'ok' => ($stored['ok'] ?? FALSE) === TRUE && is_file($jpeg) && ($stored['mime'] ?? '') === $styleMime,
        'mime' => (string) ($stored['mime'] ?? ''),
        'detail' => (string) ($stored['message'] ?? ''),
      ];
    }
  }

  /**
   * @param array<string, mixed> $connection
   *   Active connection.
   * @param array<string, mixed> $report
   *   Mutable report.
   * @param list<string> $created
   *   Object keys.
   * @param list<string> $local
   *   Temp paths.
   */
  private function video(array $connection, string $run, array &$report, array &$created, array &$local): void {
    $path = $this->temp->createFile('clip', '.mp4');
    $local[] = $path;
    $payload = random_bytes(262144);
    file_put_contents($path, $payload);
    chmod($path, 0600);
    $key = StorageUriResolver::INTEGRATION_PREFIX . $run . '/videos/clip.mp4';
    $stored = $this->put($connection, $path, $key, 'video/mp4');
    if (($stored['ok'] ?? FALSE) === TRUE) {
      $created[] = $key;
    }
    $range = '';
    $middle = '';
    if (($stored['ok'] ?? FALSE) === TRUE) {
      $range = $this->reader->readRange($connection, $key, 0, 15);
      $middle = $this->reader->readRange($connection, $key, 1000, 1015);
    }
    $http = ($stored['ok'] ?? FALSE) === TRUE ? $this->presignedRange($connection, $key, $payload) : ['ok' => FALSE, 'code' => 0, 'type' => '', 'ranges' => ''];
    $report['steps']['video'] = [
      'ok' => ($stored['ok'] ?? FALSE) === TRUE
        && ($stored['mime'] ?? '') === 'video/mp4'
        && $range === substr($payload, 0, 16)
        && $middle === substr($payload, 1000, 16)
        && ($http['ok'] ?? FALSE) === TRUE
        && ($http['type'] ?? '') === 'video/mp4',
      'bytes' => strlen($payload),
      'mime' => (string) ($stored['mime'] ?? ''),
      'sdk_range' => $range === substr($payload, 0, 16),
      'http_code' => (int) ($http['code'] ?? 0),
      'http_type' => (string) ($http['type'] ?? ''),
      'http_ranges' => (string) ($http['ranges'] ?? ''),
      'detail' => 'Range 通过 S3 API 和预签名 HTTP 验证。这不是浏览器播放测试，样本也不是可播放的转码视频。',
    ];
  }

  /**
   * @param array<string, mixed> $connection
   *   Active connection.
   * @param array<string, mixed> $report
   *   Mutable report.
   * @param list<string> $created
   *   Object keys.
   * @param list<string> $local
   *   Temp paths.
   */
  private function zip(array $connection, string $run, array &$report, array &$created, array &$local): void {
    $png = $this->raster('png');
    if ($png === NULL) {
      $report['steps']['zip'] = ['ok' => FALSE, 'detail' => 'ZIP 样本图片没有生成。'];
      return;
    }
    $local[] = $png;
    $pack = $this->temp->createFile('pack', '.zip');
    $local[] = $pack;
    $zip = new \ZipArchive();
    if ($zip->open($pack, \ZipArchive::CREATE) !== TRUE) {
      $report['steps']['zip'] = ['ok' => FALSE, 'detail' => '无法创建 ZIP。'];
      return;
    }
    $zip->addFile($png, 'album/one.png');
    $zip->close();
    $extract = $this->temp->directory('unzip');
    $local[] = $extract;
    $opened = new \ZipArchive();
    $opened->open($pack);
    $refused = FALSE;
    for ($i = 0; $i < $opened->numFiles; $i++) {
      $name = str_replace('\\', '/', (string) $opened->getNameIndex($i));
      if ($name === '' || str_contains($name, '../') || str_starts_with($name, '/')) {
        $refused = TRUE;
        continue;
      }
      $target = $extract . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
      $parent = dirname($target);
      if (!is_dir($parent) && !mkdir($parent, 0700, TRUE) && !is_dir($parent)) {
        $refused = TRUE;
        continue;
      }
      $input = $opened->getStreamIndex($i);
      $output = fopen($target, 'wb');
      if ($input && $output) {
        stream_copy_to_stream($input, $output);
      }
      if (is_resource($input)) {
        fclose($input);
      }
      if (is_resource($output)) {
        fclose($output);
      }
    }
    $opened->close();
    $evil = $this->temp->createFile('evil', '.zip');
    $local[] = $evil;
    $trap = new \ZipArchive();
    $trap->open($evil, \ZipArchive::CREATE);
    $trap->addFromString('../escape.png', 'nope');
    $trap->close();
    $trapOpen = new \ZipArchive();
    $trapOpen->open($evil);
    $slip = str_replace('\\', '/', (string) $trapOpen->getNameIndex(0));
    $trapOpen->close();
    $slipBlocked = str_contains($slip, '../');
    $extracted = $extract . '/album/one.png';
    $key = StorageUriResolver::INTEGRATION_PREFIX . $run . '/zip/one.png';
    $stored = is_file($extracted) ? $this->put($connection, $extracted, $key, 'image/png') : ['ok' => FALSE, 'message' => '解压文件不存在。'];
    if (($stored['ok'] ?? FALSE) === TRUE) {
      $created[] = $key;
    }
    $report['steps']['zip'] = [
      'ok' => ($stored['ok'] ?? FALSE) === TRUE && $slipBlocked && is_file($pack),
      'slip_blocked' => $slipBlocked,
      'unsafe_entry_skipped' => $refused,
      'source_kept' => is_file($pack),
      'detail' => 'ZIP 在临时目录解压后写入集成前缀。原 ZIP 没有被删除。',
    ];
  }

  /**
   * @param array<string, mixed> $connection
   *   Active connection.
   * @param array<string, mixed> $report
   *   Mutable report.
   * @param list<string> $created
   *   Uploaded keys.
   */
  private function cdn(array $connection, array &$report, array $created): void {
    $profile = $this->config->profile('silly') ?? [];
    $base = $this->uris->configuredPublicBase($profile);
    $key = $created[0] ?? '';
    if ($base === '' || $key === '') {
      $report['steps']['cdn'] = ['ok' => FALSE, 'code' => 0, 'detail' => '没有 CDN 域名或没有可检查的测试对象。'];
      return;
    }
    $url = $this->uris->joinUrl($base, $key);
    $response = $this->http($url, []);
    $report['steps']['cdn'] = [
      'ok' => ($response['code'] ?? 0) === 200,
      'host' => (string) (parse_url($base, PHP_URL_HOST) ?: ''),
      'code' => (int) ($response['code'] ?? 0),
      'type' => (string) ($response['type'] ?? ''),
      'detail' => ($response['code'] ?? 0) === 200
        ? '测试对象可以通过公开域名取回。这不表示生产文件已经映射到 CDN。'
        : '公开域名没有返回这个测试对象。CDN 自定义域名映射仍需在 Cloudflare 确认，不能写成已经接通。',
    ];
    unset($url);
  }

  /**
   * @param array<string, mixed> $connection
   *   Active connection.
   *
   * @return array<string, mixed>
   *   Upload result.
   */
  private function put(array $connection, string $path, string $key, string $mime): array {
    return $this->writer->putFile($connection, $path, $key, [
      'purpose' => 'integration',
      'mime' => $mime,
      'cache_control' => 'public, max-age=60',
      'require_cache' => TRUE,
      'overwrite' => FALSE,
    ]);
  }

  private function raster(string $format): ?string {
    if (!function_exists('imagecreatetruecolor')) {
      return NULL;
    }
    $suffix = $format === 'jpeg' ? '.jpg' : '.' . $format;
    $path = $this->temp->createFile($format, $suffix);
    $image = imagecreatetruecolor(48, 32);
    $color = imagecolorallocate($image, 20, 90, 160);
    imagefilledrectangle($image, 0, 0, 47, 31, $color);
    $saved = match ($format) {
      'jpeg' => function_exists('imagejpeg') ? imagejpeg($image, $path, 85) : FALSE,
      'png' => function_exists('imagepng') ? imagepng($image, $path) : FALSE,
      'webp' => function_exists('imagewebp') ? imagewebp($image, $path, 80) : FALSE,
      'avif' => function_exists('imageavif') ? imageavif($image, $path, 60) : FALSE,
      default => FALSE,
    };
    imagedestroy($image);
    if (!$saved || !is_file($path) || filesize($path) === 0) {
      $this->temp->delete($path);
      return NULL;
    }
    chmod($path, 0600);
    return $path;
  }

  /**
   * @param array<string, mixed> $connection
   *   Active connection.
   *
   * @return array{ok: bool, code: int, type: string, ranges: string}
   *   HTTP result without the URL.
   */
  private function presignedRange(array $connection, string $key, string $payload): array {
    $empty = ['ok' => FALSE, 'code' => 0, 'type' => '', 'ranges' => ''];
    try {
      $client = S3ClientFactory::create($connection);
      $command = $client->getCommand('GetObject', [
        'Bucket' => (string) $connection['bucket'],
        'Key' => $key,
      ]);
      $request = $client->createPresignedRequest($command, '+60 seconds');
      $url = (string) $request->getUri();
      $response = $this->http($url, ['Range: bytes=0-15']);
      unset($url, $request);
      $ok = ($response['code'] ?? 0) === 206 && ($response['body'] ?? '') === substr($payload, 0, 16);
      return [
        'ok' => $ok,
        'code' => (int) ($response['code'] ?? 0),
        'type' => (string) ($response['type'] ?? ''),
        'ranges' => (string) ($response['ranges'] ?? ''),
      ];
    }
    catch (\Throwable) {
      return $empty;
    }
  }

  /**
   * @param list<string> $headers
   *   Extra request headers.
   *
   * @return array{code: int, type: string, ranges: string, body: string}
   *   Response fields. The request URL is not returned.
   */
  private function http(string $url, array $headers): array {
    if (!function_exists('curl_init') || $url === '') {
      return ['code' => 0, 'type' => '', 'ranges' => '', 'body' => ''];
    }
    $handle = curl_init($url);
    if ($handle === FALSE) {
      return ['code' => 0, 'type' => '', 'ranges' => '', 'body' => ''];
    }
    curl_setopt_array($handle, [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HEADER => TRUE,
      CURLOPT_FOLLOWLOCATION => FALSE,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = curl_exec($handle);
    $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    $raw = is_string($raw) ? $raw : '';
    $header = substr($raw, 0, $headerSize);
    $ranges = '';
    if (preg_match('/^accept-ranges:\s*(.+)$/mi', $header, $match) === 1) {
      $ranges = trim($match[1]);
    }
    return [
      'code' => $code,
      'type' => strtolower(trim(explode(';', $type)[0])),
      'ranges' => $ranges,
      'body' => substr($raw, $headerSize),
    ];
  }

  /**
   * @param array<string, mixed> $connection
   *   Active connection.
   * @param list<string> $secrets
   *   Values to redact.
   */
  private function countPrefix(array $connection, string $prefix, array $secrets): int|string {
    return $this->countBucket($connection, $secrets, $prefix);
  }

  /**
   * @param array<string, mixed> $connection
   *   Active connection.
   * @param list<string> $secrets
   *   Values to redact.
   */
  private function countBucket(array $connection, array $secrets, string $prefix = ''): int|string {
    try {
      $client = S3ClientFactory::create($connection);
      $count = 0;
      $token = NULL;
      do {
        $params = [
          'Bucket' => (string) $connection['bucket'],
          'MaxKeys' => 1000,
        ];
        if ($prefix !== '') {
          $params['Prefix'] = $prefix;
        }
        if (is_string($token) && $token !== '') {
          $params['ContinuationToken'] = $token;
        }
        $result = $client->listObjectsV2($params);
        $count += count($result['Contents'] ?? []);
        $token = $result['IsTruncated'] ? ($result['NextContinuationToken'] ?? NULL) : NULL;
      } while (is_string($token) && $token !== '' && $count < 5000);
      return $count;
    }
    catch (\Throwable $error) {
      return BootGuard::safeError($error, $secrets);
    }
  }

  /**
   * @param array<string, mixed> $report
   *   Report whose required steps must pass.
   */
  private function requiredOk(array $report): bool {
    $steps = is_array($report['steps'] ?? NULL) ? $report['steps'] : [];
    foreach (['jpeg', 'png', 'webp', 'video', 'zip'] as $name) {
      if (empty($steps[$name]['ok'])) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
