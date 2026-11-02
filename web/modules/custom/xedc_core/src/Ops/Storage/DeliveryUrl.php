<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;

/**
 * Builds a delivery URL without turning CDN rewrite on for local files.
 *
 * Private objects never receive a permanent CDN address.
 */
final class DeliveryUrl {

  public function __construct(
    protected StorageUriResolver $uris,
    protected FileUrlGeneratorInterface $fileUrls,
  ) {}

  /**
   * @return array{kind: string, url: string, permanent: bool, cdn: bool}
   *   Delivery decision. The URL is empty when no public address is allowed.
   */
  public function forUri(string $uri, int $fid = 0): array {
    $location = $this->uris->location($uri);
    if ($this->uris->isPrivate($uri) || $location === 'temporary') {
      $url = '';
      if ($fid > 0 && $location === 'remote') {
        $url = Url::fromRoute('xedc_core.storage_protected_download', ['fid' => $fid])->toString();
      }
      return [
        'kind' => $this->uris->isPrivate($uri) ? 'private' : 'temporary',
        'url' => $url,
        'permanent' => FALSE,
        'cdn' => FALSE,
      ];
    }
    $cdn = $this->uris->publicCdnUrl($uri);
    $folders = $this->uris->folders();
    $endpoint = trim((string) Settings::get('xedc_storage.endpoint', ''));
    if (is_string($cdn) && $cdn !== '' && !CdnUrlPolicy::rejectedUrl($cdn, $folders['private'], $endpoint)) {
      return [
        'kind' => 'cdn',
        'url' => $cdn,
        'permanent' => TRUE,
        'cdn' => TRUE,
      ];
    }
    if ($location === 'local' || $location === 'remote') {
      try {
        return [
          'kind' => $location,
          'url' => $this->fileUrls->generateAbsoluteString($uri),
          'permanent' => $location === 'local',
          'cdn' => FALSE,
        ];
      }
      catch (\Throwable) {
        return ['kind' => $location, 'url' => '', 'permanent' => FALSE, 'cdn' => FALSE];
      }
    }
    return ['kind' => 'unknown', 'url' => '', 'permanent' => FALSE, 'cdn' => FALSE];
  }

}
