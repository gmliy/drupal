<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Provider;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Text models. The call profile is assembled from the provider and settings.
 */
final class ModelRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ProviderRepository $providers,
    private readonly SecretStore $secrets,
  ) {}

  /**
   * @return list<array<string, mixed>>
   *   Models.
   */
  public function all(): array {
    return $this->database->select('xedc_ai_model', 'm')
      ->fields('m')
      ->orderBy('provider_id')
      ->orderBy('label')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * @return array<string, mixed>|null
   *   One model.
   */
  public function load(int $id): ?array {
    $row = $this->database->select('xedc_ai_model', 'm')
      ->fields('m')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * @param array<string, mixed> $values
   *   Model fields.
   */
  public function save(array $values): int {
    $now = $this->time->getRequestTime();
    $fields = [
      'provider_id' => (string) $values['provider_id'],
      'model_name' => (string) $values['model_name'],
      'label' => (string) $values['label'],
      'enabled' => (int) (!empty($values['enabled'])),
      'input_price' => trim((string) ($values['input_price'] ?? '')),
      'output_price' => trim((string) ($values['output_price'] ?? '')),
      'currency' => 'CNY',
      'changed' => $now,
    ];
    if (array_key_exists('capabilities', $values)) {
      $capabilities = $values['capabilities'];
      if (is_array($capabilities)) {
        $capabilities = implode(',', array_keys(array_filter($capabilities)));
      }
      $fields['capabilities'] = substr((string) $capabilities, 0, 255);
    }
    $id = (int) ($values['id'] ?? 0);
    if ($id > 0 && $this->load($id) !== NULL) {
      $this->database->update('xedc_ai_model')->fields($fields)->condition('id', $id)->execute();
      return $id;
    }
    $fields['created'] = $now;
    return (int) $this->database->insert('xedc_ai_model')->fields($fields)->execute();
  }

  public function delete(int $id): void {
    $this->database->delete('xedc_ai_model')->condition('id', $id)->execute();
  }

  public function countForProvider(string $providerId): int {
    return (int) $this->database->select('xedc_ai_model', 'm')
      ->condition('provider_id', $providerId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * @return array<int, string>
   *   Enabled model labels.
   */
  public function options(): array {
    $options = [];
    foreach ($this->all() as $row) {
      if ((int) $row['enabled'] === 1) {
        $options[(int) $row['id']] = $row['label'] . ' (' . $row['model_name'] . ')';
      }
    }
    return $options;
  }

  public function defaultId(): int {
    return (int) $this->configFactory->get('xedc_ai.settings')->get('default_model_id');
  }

  public function fallbackId(): int {
    return (int) $this->configFactory->get('xedc_ai.settings')->get('fallback_model_id');
  }

  public function profile(int $id): ?ModelProfile {
    $row = $this->load($id);
    if ($row === NULL) {
      return NULL;
    }
    $provider = $this->providers->load((string) $row['provider_id']);
    if ($provider === NULL) {
      return NULL;
    }
    $settings = $this->configFactory->get('xedc_ai.settings');
    $timeout = (int) $provider['timeout'] > 0 ? (int) $provider['timeout'] : (int) $settings->get('timeout');
    $maxTokens = (int) $provider['max_tokens'] > 0 ? (int) $provider['max_tokens'] : (int) $settings->get('max_tokens');
    $temperature = (string) $provider['temperature'] !== '' ? (string) $provider['temperature'] : (string) $settings->get('temperature');
    return new ModelProfile(
      (int) $row['id'],
      (string) $provider['id'],
      (string) $provider['driver'],
      (string) $provider['label'],
      (string) $row['model_name'],
      (string) $provider['base_url'],
      $this->secrets->read((string) $provider['id']),
      max(5, min(120, $timeout)),
      max(32, min(8192, $maxTokens)),
      $temperature !== '' ? $temperature : '0.2',
      trim((string) $row['input_price']),
      trim((string) $row['output_price']),
      (int) $provider['enabled'] === 1 && (int) $row['enabled'] === 1,
    );
  }

  public function forTask(string $task): ?ModelProfile {
    $keys = [
      'content_review' => 'model_content_review',
      'quality' => 'model_quality',
      'seo_optimization' => 'model_seo',
      'translation' => 'model_translation',
      'general' => 'model_general',
    ];
    $id = isset($keys[$task]) ? (int) $this->configFactory->get('xedc_ai.settings')->get($keys[$task]) : 0;
    if ($id > 0) {
      $profile = $this->profile($id);
      if ($profile !== NULL && $profile->enabled) {
        return $profile;
      }
    }
    return $this->defaultProfile();
  }

  public function defaultProfile(): ?ModelProfile {
    $profile = $this->profile($this->defaultId());
    return ($profile !== NULL && $profile->enabled) ? $profile : NULL;
  }

  public function fallbackProfile(int $exceptId): ?ModelProfile {
    $id = $this->fallbackId();
    if ($id < 1 || $id === $exceptId) {
      return NULL;
    }
    $profile = $this->profile($id);
    return ($profile !== NULL && $profile->enabled) ? $profile : NULL;
  }

}
