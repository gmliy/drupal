<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Provider;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\key\Entity\Key;
use Drupal\xedc_ai\Client\Redactor;
use Psr\Log\LoggerInterface;

/**
 * Stores provider keys as Key module file references outside the web root.
 *
 * An environment variable XEDC_AI_KEY_{ID} overrides the stored file.
 * The secret is never written to configuration or to the log.
 */
final class SecretStore {

  /**
   * The latest save error. Empty after a successful write.
   */
  private string $failure = '';

  public function __construct(
    private readonly FileSystemInterface $files,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ModuleHandlerInterface $modules,
    private readonly LoggerInterface $logger,
  ) {}

  public function ready(): bool {
    return $this->modules->moduleExists('key') && $this->entities->hasDefinition('key');
  }

  /**
   * Returns the reason for the latest failed write.
   */
  public function failure(): string {
    return $this->failure;
  }

  public function exists(string $providerId): bool {
    if ($this->environment($providerId) !== '') {
      return TRUE;
    }
    return is_file($this->path($providerId));
  }

  public function source(string $providerId): string {
    if ($this->environment($providerId) !== '') {
      return 'environment';
    }
    return is_file($this->path($providerId)) ? 'file' : 'missing';
  }

  public function read(string $providerId): string {
    $environment = $this->environment($providerId);
    if ($environment !== '') {
      return $environment;
    }
    if (!$this->ready() || !ProviderCatalog::validId($providerId)) {
      return '';
    }
    $key = $this->entities->getStorage('key')->load($this->keyId($providerId));
    if (!$key instanceof Key || $key->getKeyProvider()->getPluginId() !== 'file') {
      return '';
    }
    $value = $key->getKeyValue(TRUE);
    return is_string($value) ? trim($value) : '';
  }

  public function hint(string $providerId): string {
    $path = $this->path($providerId);
    if (!is_file($path)) {
      return '';
    }
    $value = trim((string) file_get_contents($path));
    if (strlen($value) < 4) {
      return '';
    }
    return str_repeat('*', 4) . substr($value, -4);
  }

  public function write(string $providerId, string $secret): bool {
    $this->failure = '';
    if (!$this->ready()) {
      $this->failure = 'Key 模块未启用，密钥没有保存。';
      return FALSE;
    }
    if (!ProviderCatalog::validId($providerId)) {
      $this->failure = '供应商机器名不正确，密钥没有保存。';
      return FALSE;
    }
    $secret = trim($secret);
    if ($secret === '') {
      $this->failure = '密钥为空，没有保存。';
      return FALSE;
    }
    if (strlen($secret) > 512) {
      $this->failure = '密钥超过 512 个字符，没有保存。';
      return FALSE;
    }
    $directory = $this->directory();
    if ($directory === NULL) {
      if ($this->failure === '') {
        $this->failure = '私有密钥目录不可用，密钥没有保存。';
      }
      return FALSE;
    }
    if (!is_writable($directory)) {
      $this->failure = '私有密钥目录不可写：' . $directory . '。目录所有者是 ' . $this->ownerName($directory) . '，当前用户是 ' . $this->account() . '。';
      $this->logger->error('AI key directory is not writable. Owner is @owner, current user is @user.', [
        '@owner' => $this->ownerName($directory),
        '@user' => $this->account(),
      ]);
      return FALSE;
    }
    $path = $directory . '/' . $providerId . '.key';
    if (file_put_contents($path, $secret, LOCK_EX) === FALSE) {
      $this->failure = '密钥文件写入失败。当前用户是 ' . $this->account() . '。';
      $this->logger->error('Could not write the AI key file for provider @id.', ['@id' => $providerId]);
      return FALSE;
    }
    @chmod($path, 0600);
    $this->alignOwner($path);
    $real = realpath($path);
    if ($real === FALSE || !$this->inside($real, $directory)) {
      if (is_file($path)) {
        @unlink($path);
      }
      $this->failure = '密钥文件位置不在私有目录内，已取消保存。';
      return FALSE;
    }
    try {
      if (!$this->saveKey($providerId, $real)) {
        if ($this->failure === '') {
          $this->failure = 'Key 模块没有接受这个密钥文件。';
        }
        return FALSE;
      }
    }
    catch (\Throwable $exception) {
      $this->failure = 'Key 模块保存失败：' . Redactor::text($exception->getMessage());
      $this->logger->error('Could not save the Key entity for provider @id.', ['@id' => $providerId]);
      return FALSE;
    }
    return TRUE;
  }

  public function delete(string $providerId): void {
    if (!ProviderCatalog::validId($providerId)) {
      return;
    }
    if ($this->ready()) {
      $key = $this->entities->getStorage('key')->load($this->keyId($providerId));
      if ($key instanceof Key && $key->getKeyProvider()->getPluginId() === 'file') {
        $location = (string) ($key->get('key_provider_settings')['file_location'] ?? '');
        $real = $location !== '' ? realpath($location) : FALSE;
        $directory = $this->directory();
        if ($directory !== NULL && $real !== FALSE && $this->inside($real, $directory)) {
          $key->delete();
          @unlink($real);
        }
      }
    }
    $path = $this->path($providerId);
    $directory = $this->directory();
    $real = is_file($path) ? realpath($path) : FALSE;
    if ($directory !== NULL && $real !== FALSE && $this->inside($real, $directory)) {
      @unlink($real);
    }
  }

  public function deleteAll(): void {
    $directory = $this->directory();
    if ($directory === NULL || !is_dir($directory)) {
      return;
    }
    $files = glob($directory . '/*.key') ?: [];
    foreach ($files as $file) {
      $id = basename($file, '.key');
      if (ProviderCatalog::validId($id)) {
        $this->delete($id);
      }
    }
  }

  private function environment(string $providerId): string {
    if (!ProviderCatalog::validId($providerId)) {
      return '';
    }
    $value = getenv('XEDC_AI_KEY_' . strtoupper($providerId));
    return is_string($value) ? trim($value) : '';
  }

  private function keyId(string $providerId): string {
    return 'xedc_ai_' . $providerId;
  }

  private function directory(): ?string {
    $private = $this->files->realpath('private://');
    if ($private === FALSE || !is_dir($private)) {
      $this->failure = 'Drupal 私有文件目录不可用。请检查 settings.php 的 file_private_path。';
      $this->logger->error('Private file directory is not available for AI keys.');
      return NULL;
    }
    if (!is_writable($private)) {
      $this->failure = '私有文件目录不可写：' . $private . '。当前用户是 ' . $this->account() . '。';
      $this->logger->error('Private file directory is not writable for user @user.', ['@user' => $this->account()]);
      return NULL;
    }
    $directory = $private . '/xedc-ai-keys';
    if (!is_dir($directory) && !mkdir($directory, 0700, TRUE) && !is_dir($directory)) {
      $this->failure = '无法创建私有密钥目录：' . $directory . '。当前用户是 ' . $this->account() . '。';
      $this->logger->error('Could not create the AI key directory.');
      return NULL;
    }
    $this->alignOwner($directory);
    @chmod($directory, 0700);
    if (!is_readable($directory) || !is_writable($directory)) {
      $this->failure = '私有密钥目录不可写：' . $directory . '。目录所有者是 ' . $this->ownerName($directory) . '，当前用户是 ' . $this->account() . '。';
      $this->logger->error('AI key directory is not writable. Owner is @owner, current user is @user.', [
        '@owner' => $this->ownerName($directory),
        '@user' => $this->account(),
      ]);
      return NULL;
    }
    $real = realpath($directory);
    if ($real === FALSE) {
      $this->failure = '无法解析私有密钥目录。';
      return NULL;
    }
    return $real;
  }

  /**
   * Gives root-created files to the private directory owner.
   */
  private function alignOwner(string $path): void {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
      return;
    }
    $private = $this->files->realpath('private://');
    if ($private === FALSE) {
      return;
    }
    $uid = fileowner($private);
    $gid = filegroup($private);
    if (is_int($uid)) {
      @chown($path, $uid);
    }
    if (is_int($gid)) {
      @chgrp($path, $gid);
    }
  }

  private function account(): string {
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
      $info = posix_getpwuid(posix_geteuid());
      if (is_array($info) && isset($info['name']) && is_string($info['name'])) {
        return $info['name'];
      }
    }
    return (string) getmyuid();
  }

  private function ownerName(string $path): string {
    $uid = fileowner($path);
    if (is_int($uid) && function_exists('posix_getpwuid')) {
      $info = posix_getpwuid($uid);
      if (is_array($info) && isset($info['name']) && is_string($info['name'])) {
        return $info['name'];
      }
      return (string) $uid;
    }
    return 'unknown';
  }

  private function path(string $providerId): string {
    $directory = $this->directory();
    if ($directory === NULL || !ProviderCatalog::validId($providerId)) {
      return '';
    }
    return $directory . '/' . $providerId . '.key';
  }

  private function saveKey(string $providerId, string $path): bool {
    $storage = $this->entities->getStorage('key');
    $existing = $storage->load($this->keyId($providerId));
    if ($existing !== NULL && !$existing instanceof Key) {
      $this->failure = '已有同名密钥无法读取，没有覆盖。';
      return FALSE;
    }
    if ($existing instanceof Key && $existing->getKeyProvider()->getPluginId() !== 'file') {
      $this->failure = '已有同名密钥不是文件类型，没有覆盖。';
      return FALSE;
    }
    $settings = [
      'file_location' => $path,
      'base64_encoded' => FALSE,
      'strip_line_breaks' => TRUE,
    ];
    if ($existing instanceof Key) {
      $existing->set('key_provider_settings', $settings);
      $existing->save();
      return TRUE;
    }
    $key = $storage->create([
      'id' => $this->keyId($providerId),
      'label' => 'XEDC AI ' . $providerId,
      'description' => 'File reference for an AI provider. The key value is not stored in configuration.',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'file',
      'key_provider_settings' => $settings,
      'key_input' => 'none',
      'key_input_settings' => [],
    ]);
    $key->save();
    return TRUE;
  }

  private function inside(string $path, string $directory): bool {
    return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
  }

}
