<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Provider;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Provider rows. Secrets are not stored in this table.
 */
final class ProviderRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * @return list<array<string, mixed>>
   *   Providers.
   */
  public function all(): array {
    $rows = $this->database->select('xedc_ai_provider', 'p')
      ->fields('p')
      ->orderBy('label')
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    return array_values($rows);
  }

  /**
   * @return array<string, mixed>|null
   *   One provider.
   */
  public function load(string $id): ?array {
    $row = $this->database->select('xedc_ai_provider', 'p')
      ->fields('p')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * @param array<string, mixed> $values
   *   Provider fields without a secret.
   */
  public function save(array $values): void {
    $now = $this->time->getRequestTime();
    $fields = [
      'label' => (string) $values['label'],
      'driver' => (string) $values['driver'],
      'base_url' => (string) $values['base_url'],
      'enabled' => (int) (!empty($values['enabled'])),
      'timeout' => (int) $values['timeout'],
      'max_tokens' => (int) $values['max_tokens'],
      'temperature' => (string) $values['temperature'],
      'key_hint' => (string) ($values['key_hint'] ?? ''),
      'changed' => $now,
    ];
    if ($this->load((string) $values['id']) === NULL) {
      $fields['id'] = (string) $values['id'];
      $fields['created'] = $now;
      $this->database->insert('xedc_ai_provider')->fields($fields)->execute();
      return;
    }
    $this->database->update('xedc_ai_provider')
      ->fields($fields)
      ->condition('id', (string) $values['id'])
      ->execute();
  }

  public function setEnabled(string $id, bool $enabled): void {
    $this->database->update('xedc_ai_provider')
      ->fields([
        'enabled' => $enabled ? 1 : 0,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $id)
      ->execute();
  }

  public function delete(string $id): void {
    $this->database->delete('xedc_ai_provider')->condition('id', $id)->execute();
  }

}
