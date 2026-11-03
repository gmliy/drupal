<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;

/**
 * Issues a short-lived presigned URL after a Drupal permission check.
 */
final class ProtectedDownloader {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StorageConfig $config,
    protected StorageJournal $journal,
    protected LoggerInterface $logger,
    protected StorageUriResolver $uris,
    protected DownloadGate $gate,
  ) {}

  /**
   * @return array{status:int,message:string,url:string}
   *   Result for the controller. The URL is empty unless status is 302.
   */
  public function authorize(int $fid, AccountInterface $account): array {
    $decision = $this->gate->decide($fid, $account);
    if (empty($decision['allow'])) {
      return $this->deny((int) $decision['status'], (string) $decision['message']);
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      return $this->deny(404, '文件不存在。');
    }
    $mapped = $this->objectKey($file->getFileUri());
    if ($mapped === NULL) {
      return $this->deny(403, '此入口只接受对象存储中的受保护文件，不会读取本地私有文件。');
    }
    if (!(bool) Settings::get('xedc_storage.settings_accepted', FALSE)) {
      return $this->deny(503, '对象存储运行时未启用，不能生成下载地址。');
    }
    $bucket = (string) Settings::get('xedc_storage.bucket', '');
    $region = (string) Settings::get('xedc_storage.region', '');
    $endpoint = (string) Settings::get('xedc_storage.endpoint', '');
    $access = (string) Settings::get('s3fs.access_key', '');
    $secret = (string) Settings::get('s3fs.secret_key', '');
    if ($bucket === '' || $region === '' || $access === '' || $secret === '') {
      return $this->deny(503, '运行时连接不完整，不能生成下载地址。');
    }
    $ttl = (int) ($this->config->data()['presign_ttl'] ?? 120);
    if ($ttl < 30 || $ttl > 300) {
      $ttl = 120;
    }
    try {
      $client = S3ClientFactory::create([
        'bucket' => $bucket,
        'region' => $region,
        'endpoint' => $endpoint,
        'path_style' => (bool) Settings::get('xedc_storage.path_style', FALSE),
        'access_key' => $access,
        'secret_key' => $secret,
      ]);
      $filename = str_replace(['"', '\\', "\r", "\n"], '', $file->getFilename());
      $command = $client->getCommand('GetObject', [
        'Bucket' => $bucket,
        'Key' => $mapped,
        'ResponseContentDisposition' => 'attachment; filename="' . $filename . '"',
        'ResponseContentType' => $this->mime($file->getMimeType()),
      ]);
      $request = $client->createPresignedRequest($command, '+' . $ttl . ' seconds');
      $url = (string) $request->getUri();
      if (!$this->allowedUrl($url, $endpoint)) {
        return $this->deny(502, '预签名地址没有通过主机检查。');
      }
      $this->journal->add('info', '已为文件 ' . $fid . ' 生成 ' . $ttl . ' 秒预签名地址。');
      $this->journal->increment(['read_ok' => 1]);
      return ['status' => 302, 'message' => '', 'url' => $url];
    }
    catch (\Throwable $error) {
      $message = BootGuard::safeError($error, [$access, $secret]);
      $this->logger->error('受保护下载失败：@message', ['@message' => $message]);
      $this->journal->increment(['read_failed' => 1]);
      return $this->deny(502, '不能生成受保护下载地址。');
    }
  }

  /**
   * @return array{status:int,message:string,url:string}
   *   Denial result.
   */
  private function deny(int $status, string $message): array {
    return ['status' => $status, 'message' => $message, 'url' => ''];
  }

  private function objectKey(string $uri): ?string {
    if (!$this->uris->isPrivate($uri)) {
      return NULL;
    }
    $scheme = $this->uris->scheme($uri);
    if ($scheme === 'private' && !(bool) Settings::get('xedc_storage.private_takeover', FALSE)) {
      return NULL;
    }
    if ($scheme !== 'private' && $scheme !== 's3') {
      return NULL;
    }
    return $this->uris->objectKey($uri);
  }

  private function allowedUrl(string $url, string $endpoint): bool {
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || !str_contains($url, 'X-Amz-')) {
      return FALSE;
    }
    $endpointHost = $endpoint === '' ? '' : parse_url($endpoint, PHP_URL_HOST);
    if ($endpointHost !== '' && $endpointHost !== FALSE) {
      return $host === $endpointHost || str_ends_with($host, '.' . $endpointHost);
    }
    return $host === 's3.amazonaws.com' || str_ends_with($host, '.amazonaws.com');
  }

  private function mime(string $mime): string {
    return BootGuard::contentType($mime);
  }

}
