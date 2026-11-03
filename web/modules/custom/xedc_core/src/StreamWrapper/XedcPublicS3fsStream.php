<?php

declare(strict_types=1);

namespace Drupal\xedc_core\StreamWrapper;

use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\s3fs\StreamWrapper\PublicS3fsStream;
use Drupal\xedc_core\Ops\Storage\PublicUrl;
use Drupal\xedc_core\Ops\Storage\RuntimeState;

/**
 * Public S3 wrapper that keeps image styles on the local public filesystem.
 *
 * Original public files use the CDN only when STORAGE_CDN_ENABLED is on.
 * This class is installed only when the container is compiled with public
 * takeover requested. It never emits the R2 API hostname.
 */
final class XedcPublicS3fsStream extends PublicS3fsStream {

  private ?PublicStream $styleDelegate = NULL;

  public function __construct() {
    if (!RuntimeState::usesObjectStorage()) {
      return;
    }
    parent::__construct();
  }

  /**
   * Local public directory used by Drupal's image style route.
   *
   * S3fsStream reports an empty directory because originals are remote.
   * Image style routing and the inbound path processor both require the
   * on-disk public files directory, otherwise /sites/default/files/styles
   * never reaches the derivative controller.
   */
  public function getDirectoryPath() {
    return PublicStream::basePath();
  }

  /**
   * {@inheritdoc}
   */
  public function realpath() {
    $local = $this->style((string) $this->getUri());
    return $local ? $local->realpath() : parent::realpath();
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    $uri = (string) $this->getUri();
    $local = $this->style($uri);
    if ($local instanceof PublicStream) {
      // A generation marker keeps a previously cached 404 from sticking to the
      // style URL. The image token is appended by ImageStyle after this URL.
      $url = $local->getExternalUrl();
      return $url . (str_contains($url, '?') ? '&' : '?') . 'xedc_style=1';
    }
    $decision = PublicUrl::delivery($uri, TRUE, PublicUrl::enabled(), $this->cdnBase(), $this->publicFolder());
    if (is_string($decision['url']) && $decision['url'] !== '') {
      if (str_contains($decision['url'], 'r2.cloudflarestorage.com')) {
        \Drupal::logger('xedc_core')->error('已拒绝把 R2 API Endpoint 用于公开地址。');
        return PublicStream::baseUrl() . '/' . \Drupal\Component\Utility\UrlHelper::encodePath((string) StreamWrapperManager::getTarget($uri));
      }
      return $decision['url'];
    }
    \Drupal::logger('xedc_core')->error('公开文件 @uri 不能生成安全地址，已拒绝回退到 R2 API Endpoint。', ['@uri' => $uri]);
    return PublicStream::baseUrl() . '/' . \Drupal\Component\Utility\UrlHelper::encodePath((string) StreamWrapperManager::getTarget($uri));
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($uri, $mode, $options, &$opened_path) {
    if ($this->blockedWrite((string) $uri, (string) $mode)) {
      return FALSE;
    }
    $local = $this->style((string) $uri);
    if ($local instanceof PublicStream) {
      $this->styleDelegate = $local;
      return $local->stream_open($uri, $mode, $options, $opened_path);
    }
    $this->styleDelegate = NULL;
    $opened = parent::stream_open($uri, $mode, $options, $opened_path);
    if ($opened || preg_match('/[waxc]/', (string) $mode) === 1) {
      return $opened;
    }
    // A public original that has not been copied to the bucket can still be
    // read from the local file so image styles can be generated. Writes stay
    // on the object store.
    $local = $this->localOriginal((string) $uri);
    if (!$local instanceof PublicStream) {
      return FALSE;
    }
    $this->styleDelegate = $local;
    return $local->stream_open($uri, $mode, $options, $opened_path);
  }

  public function stream_read($count) {
    return $this->styleDelegate ? $this->styleDelegate->stream_read($count) : parent::stream_read($count);
  }

  public function stream_write($data) {
    return $this->styleDelegate ? $this->styleDelegate->stream_write($data) : parent::stream_write($data);
  }

  public function stream_eof() {
    return $this->styleDelegate ? $this->styleDelegate->stream_eof() : parent::stream_eof();
  }

  public function stream_seek($offset, $whence = SEEK_SET) {
    return $this->styleDelegate ? $this->styleDelegate->stream_seek($offset, $whence) : parent::stream_seek($offset, $whence);
  }

  public function stream_flush() {
    return $this->styleDelegate ? $this->styleDelegate->stream_flush() : parent::stream_flush();
  }

  public function stream_tell() {
    return $this->styleDelegate ? $this->styleDelegate->stream_tell() : parent::stream_tell();
  }

  public function stream_stat() {
    return $this->styleDelegate ? $this->styleDelegate->stream_stat() : parent::stream_stat();
  }

  public function stream_close() {
    if ($this->styleDelegate) {
      $result = $this->styleDelegate->stream_close();
      $this->styleDelegate = NULL;
      return $result;
    }
    return parent::stream_close();
  }

  public function unlink($uri) {
    if (RuntimeState::writesPaused() && !$this->isStyleUri((string) $uri)) {
      return FALSE;
    }
    $local = $this->style((string) $uri);
    return $local ? $local->unlink($uri) : parent::unlink($uri);
  }

  public function rename($from_uri, $to_uri) {
    $from = $this->style((string) $from_uri);
    $to = $this->style((string) $to_uri);
    if ($from xor $to) {
      \Drupal::logger('xedc_core')->error('拒绝在本地图片样式和对象存储之间重命名。');
      return FALSE;
    }
    return $from ? $from->rename($from_uri, $to_uri) : parent::rename($from_uri, $to_uri);
  }

  public function mkdir($uri, $mode, $options) {
    $local = $this->style((string) $uri);
    return $local ? $local->mkdir($uri, $mode, $options) : parent::mkdir($uri, $mode, $options);
  }

  public function rmdir($uri, $options) {
    $local = $this->style((string) $uri);
    return $local ? $local->rmdir($uri, $options) : parent::rmdir($uri, $options);
  }

  public function url_stat($uri, $flags) {
    $local = $this->style((string) $uri);
    if ($local) {
      return $local->url_stat($uri, $flags);
    }
    $stat = parent::url_stat($uri, $flags);
    if ($stat || !RuntimeState::usesObjectStorage()) {
      return $stat;
    }
    // s3fs treats a metadata-cache miss as "file does not exist" and then
    // caches that miss. Image styles stop before reading the original.
    // A HEAD confirms the object and replaces the negative cache entry.
    try {
      $metadata = $this->getS3Metadata($uri);
      if (!is_array($metadata)) {
        $present = $this->localOriginal((string) $uri);
        return $present ? ($present->url_stat($uri, $flags) ?: FALSE) : FALSE;
      }
      $this->writeCache($metadata);
    }
    catch (\Throwable $exception) {
      \Drupal::logger('xedc_core')->error('公开文件 @uri 的对象元数据无法确认。', ['@uri' => $uri]);
      return FALSE;
    }
    $stat = parent::url_stat($uri, $flags);
    return $stat ?: FALSE;
  }

  public function dir_opendir($uri, $options = NULL) {
    $local = $this->style((string) $uri);
    if ($local) {
      $this->styleDelegate = $local;
      return $local->dir_opendir($uri, $options);
    }
    $this->styleDelegate = NULL;
    return parent::dir_opendir($uri, $options);
  }

  public function dir_readdir() {
    return $this->styleDelegate ? $this->styleDelegate->dir_readdir() : parent::dir_readdir();
  }

  public function dir_rewinddir(): bool {
    return $this->styleDelegate ? $this->styleDelegate->dir_rewinddir() : parent::dir_rewinddir();
  }

  public function dir_closedir() {
    if ($this->styleDelegate) {
      $result = $this->styleDelegate->dir_closedir();
      $this->styleDelegate = NULL;
      return $result;
    }
    return parent::dir_closedir();
  }

  public function stream_metadata($uri, $option, $value) {
    $local = $this->style((string) $uri);
    return $local ? $local->stream_metadata($uri, $option, $value) : parent::stream_metadata($uri, $option, $value);
  }

  /**
   * Local original used only when the bucket has no object for this URI.
   */
  private function localOriginal(string $uri): ?PublicStream {
    if (!RuntimeState::usesObjectStorage() || $this->isStyleUri($uri)) {
      return NULL;
    }
    $target = StreamWrapperManager::getTarget($uri);
    if (!is_string($target) || $target === '' || str_contains($target, '..') || str_contains($target, "\0")) {
      return NULL;
    }
    $root = PublicStream::basePath();
    if (!str_starts_with($root, '/')) {
      $root = DRUPAL_ROOT . '/' . $root;
    }
    $path = $root . '/' . $target;
    if (!is_file($path) || is_link($path)) {
      return NULL;
    }
    $local = new PublicStream();
    $local->setUri($uri);
    return $local;
  }

  private function style(string $uri): ?PublicStream {
    $target = StreamWrapperManager::getTarget($uri);
    $style = is_string($target) && PublicUrl::isStyle($target);
    if (!$style && RuntimeState::usesObjectStorage()) {
      return NULL;
    }
    $local = new PublicStream();
    $local->setUri($uri);
    return $local;
  }

  private function isStyleUri(string $uri): bool {
    $target = StreamWrapperManager::getTarget($uri);
    return is_string($target) && PublicUrl::isStyle($target);
  }

  private function blockedWrite(string $uri, string $mode): bool {
    if (!RuntimeState::writesPaused() || $this->isStyleUri($uri)) {
      return FALSE;
    }
    return preg_match('/[waxc]/', $mode) === 1;
  }

  private function cdnBase(): string {
    $base = trim((string) ($this->config['domain'] ?? ''));
    if ($base !== '' && !str_contains($base, '://')) {
      $base = 'https://' . $base;
    }
    $configured = trim((string) \Drupal\Core\Site\Settings::get('xedc_storage.public_base_url', ''));
    return $configured !== '' ? $configured : ($base !== '' ? $base : \Drupal\xedc_core\Ops\Storage\CdnUrlPolicy::PRODUCTION_BASE);
  }

  private function publicFolder(): string {
    $folder = trim((string) ($this->config['public_folder'] ?? ''));
    return $folder !== '' ? $folder : 's3fs-public';
  }

}
