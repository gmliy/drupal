<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Small redacted event log stored in state, not in the watchdog message body.
 */
final class StorageJournal {

  public function __construct(
    protected StateInterface $state,
    protected TimeInterface $time,
  ) {}

  public function add(string $level, string $message): void {
    $events = $this->state->get('xedc_storage.events', []);
    if (!is_array($events)) {
      $events = [];
    }
    $events[] = [
      'time' => $this->time->getRequestTime(),
      'level' => $level,
      'message' => BootGuard::redact($message),
    ];
    if (count($events) > 30) {
      $events = array_slice($events, -30);
    }
    $this->state->set('xedc_storage.events', $events);
  }

  /**
   * @return list<array<string, mixed>>
   *   Newest events first.
   */
  public function recent(int $limit = 8): array {
    $events = $this->state->get('xedc_storage.events', []);
    if (!is_array($events)) {
      return [];
    }
    return array_reverse(array_slice($events, -$limit));
  }

  /**
   * @param array<string, mixed> $value
   *   Public result.
   */
  public function put(string $key, array $value): void {
    $stored = $this->state->get('xedc_storage.results', []);
    if (!is_array($stored)) {
      $stored = [];
    }
    $stored[$key] = $value;
    $this->state->set('xedc_storage.results', $stored);
  }

  /**
   * @return array<string, mixed>
   *   Stored public results.
   */
  public function all(): array {
    $stored = $this->state->get('xedc_storage.results', []);
    return is_array($stored) ? $stored : [];
  }

  /**
   * @param array<string, int> $delta
   *   Counter increments.
   */
  public function increment(array $delta): void {
    $counters = $this->state->get('xedc_storage.counters', []);
    if (!is_array($counters)) {
      $counters = [];
    }
    foreach ($delta as $name => $amount) {
      $counters[$name] = (int) ($counters[$name] ?? 0) + $amount;
    }
    $this->state->set('xedc_storage.counters', $counters);
  }

  /**
   * @return array<string, int>
   *   Counters recorded by this center.
   */
  public function counters(): array {
    $counters = $this->state->get('xedc_storage.counters', []);
    return is_array($counters) ? $counters : [];
  }

}
