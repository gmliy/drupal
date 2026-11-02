<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\File\FileSystemInterface;

/**
 * Gives image, video and archive code one local path.
 *
 * Remote URIs are copied into the temp directory. GD and ZipArchive never
 * receive an HTTP, CDN or object-storage URL. Only the copy created here is
 * deleted by release().
 */
final class LocalFileBridge {

  private ?string $heldUri = NULL;

  private ?string $heldPath = NULL;

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected LocalTempFileManager $temp,
    protected StorageUriResolver $uris,
  ) {}

  public function isRemoteUri(string $uri): bool {
    return $this->uris->isRemote($uri);
  }

  public function schemeIsRemote(string $scheme): bool {
    return $this->uris->isRemote($scheme . '://placeholder');
  }

  /**
   * Local filesystem path for a URI, or NULL when it cannot be read.
   */
  public function localPath(string $uri): ?string {
    if ($uri === '' || str_contains($uri, "\0")) {
      return NULL;
    }
    if (!$this->isRemoteUri($uri)) {
      $real = $this->fileSystem->realpath($uri);
      if (is_string($real) && is_file($real) && !is_link($real)) {
        return $real;
      }
      if (!str_contains($uri, '://') && is_file($uri) && !is_link($uri)) {
        $real = realpath($uri);
        return is_string($real) ? $real : NULL;
      }
      return NULL;
    }
    if ($this->heldUri === $uri && is_string($this->heldPath) && is_file($this->heldPath) && !is_link($this->heldPath)) {
      return $this->heldPath;
    }
    $this->release();
    try {
      $path = $this->temp->materializeUri($uri);
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!is_file($path) || is_link($path) || !$this->temp->contains($path)) {
      $this->temp->delete($path);
      return NULL;
    }
    $this->heldUri = $uri;
    $this->heldPath = $path;
    return $path;
  }

  /**
   * Local path for GD. Remote sources stay outside the web root.
   */
  public function localUri(string $uri): string {
    if (!$this->isRemoteUri($uri)) {
      return $uri;
    }
    return $this->localPath($uri) ?? $uri;
  }

  /**
   * Deletes only the temporary copy created for a remote URI.
   */
  public function release(): void {
    if (is_string($this->heldPath)) {
      $this->temp->delete($this->heldPath);
    }
    $this->heldPath = NULL;
    $this->heldUri = NULL;
  }

}
