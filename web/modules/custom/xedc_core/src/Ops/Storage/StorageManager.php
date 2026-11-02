<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Core\Site\Settings;

/**
 * Active storage connection and the services that use it.
 *
 * Business code uses this instead of creating AWS clients directly.
 */
final class StorageManager {

  public function __construct(
    protected StorageConfig $config,
    protected CredentialVault $credentials,
    protected RemoteFileReader $reader,
    protected RemoteFileWriter $writer,
    protected StorageUriResolver $uris,
    protected StorageOperationGuard $guard,
    protected StorageHealthChecker $health,
    protected LocalTempFileManager $temp,
    protected LocalFileBridge $files,
  ) {}

  public function config(): StorageConfig {
    return $this->config;
  }

  public function reader(): RemoteFileReader {
    return $this->reader;
  }

  public function writer(): RemoteFileWriter {
    return $this->writer;
  }

  public function uris(): StorageUriResolver {
    return $this->uris;
  }

  public function guard(): StorageOperationGuard {
    return $this->guard;
  }

  public function health(): StorageHealthChecker {
    return $this->health;
  }

  public function temp(): LocalTempFileManager {
    return $this->temp;
  }

  public function files(): LocalFileBridge {
    return $this->files;
  }

  /**
   * @return array<string, mixed>|null
   *   Active connection, or NULL when the profile cannot be used.
   */
  public function activeConnection(): ?array {
    $id = $this->config->activeId();
    if ($id === '') {
      return NULL;
    }
    $profile = $this->config->profile($id);
    if ($profile === NULL) {
      return NULL;
    }
    $resolved = $this->credentials->resolve($profile);
    if (empty($resolved['ok']) || !is_array($resolved['connection'] ?? NULL)) {
      return NULL;
    }
    return $resolved['connection'];
  }

  /**
   * @return array<string, mixed>|null
   *   Runtime connection from settings. NULL while runtime is off.
   */
  public function runtimeConnection(): ?array {
    if (!(bool) Settings::get('xedc_storage.runtime_applied', FALSE)) {
      return NULL;
    }
    $access = (string) Settings::get('s3fs.access_key', '');
    $secret = (string) Settings::get('s3fs.secret_key', '');
    $bucket = (string) Settings::get('xedc_storage.bucket', '');
    $region = (string) Settings::get('xedc_storage.region', '');
    if ($access === '' || $secret === '' || $bucket === '' || $region === '') {
      return NULL;
    }
    return [
      'bucket' => $bucket,
      'region' => $region,
      'endpoint' => (string) Settings::get('xedc_storage.endpoint', ''),
      'path_style' => (bool) Settings::get('xedc_storage.path_style', FALSE),
      'access_key' => $access,
      'secret_key' => $secret,
    ];
  }

}
