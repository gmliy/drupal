<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Reads runtime, CDN and credential state without printing secrets.
 */
final class StorageHealthChecker {

  public function __construct(
    protected StorageConfig $config,
    protected CredentialVault $credentials,
    protected ConnectionTester $tester,
    protected CdnInspector $cdn,
    protected StorageJournal $journal,
    protected TimeInterface $time,
  ) {}

  /**
   * @return array<string, mixed>
   *   Combined public health result.
   */
  public function inspect(string $profileId): array {
    $connection = $this->tester->test($profileId);
    $cdn = $this->cdn->inspect($profileId);
    $profile = $this->config->profile($profileId);
    $credential = $profile === NULL ? ['status' => 'missing', 'label' => '无配置'] : $this->credentials->describe($profile);
    unset($credential['access_key'], $credential['secret_key']);
    $result = [
      'profile' => $profileId,
      'checked' => $this->time->getRequestTime(),
      'connection' => $connection['status'] ?? 'failed',
      'connection_message' => (string) ($connection['message'] ?? ''),
      'cdn' => $cdn['status'] ?? 'failed',
      'cdn_message' => (string) ($cdn['message'] ?? ''),
      'credential' => (string) ($credential['label'] ?? ''),
      'runtime' => (bool) \Drupal\Core\Site\Settings::get('xedc_storage.runtime_applied', FALSE) ? 'enabled' : 'off',
      'public_takeover' => (bool) \Drupal\Core\Site\Settings::get('s3fs.use_s3_for_public', FALSE) ? 'on' : 'off',
      'private_takeover' => (bool) \Drupal\Core\Site\Settings::get('s3fs.use_s3_for_private', FALSE) ? 'on' : 'off',
    ];
    $this->journal->put('health', $result);
    return $result;
  }

  /**
   * @return array<string, mixed>
   *   Last health result.
   */
  public function latest(): array {
    $stored = $this->journal->all()['health'] ?? [];
    return is_array($stored) ? $stored : [];
  }

}
