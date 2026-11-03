<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records share intent and confirmed share actions, with a short dedupe window.
 */
final class ShareRecorder {

  public const WINDOW = 3600;

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected RequestStack $requestStack,
  ) {}

  public function record(NodeInterface $node, string $method, string $outcome, AccountInterface $account): bool {
    $method = $method === 'panel' || $method === 'copy' || $method === 'native' ? $method : '';
    $outcome = $outcome === 'intent' || $outcome === 'success' ? $outcome : '';
    $valid = ($method === 'panel' && $outcome === 'intent')
      || (($method === 'copy' || $method === 'native') && $outcome === 'success');
    if (!$valid || !$node->isPublished() || !$node->access('view', $account)) {
      return FALSE;
    }
    $uid = (int) $account->id();
    $anon = $uid > 0 ? '' : $this->anonHash();
    $since = $this->time->getRequestTime() - self::WINDOW;
    $query = $this->database->select('xedc_share', 's');
    $query->condition('entity_type', 'node');
    $query->condition('entity_id', (int) $node->id());
    $query->condition('method', $method);
    $query->condition('outcome', $outcome);
    $query->condition('uid', $uid);
    $query->condition('anon_hash', $anon);
    $query->condition('created', $since, '>=');
    if ((int) $query->countQuery()->execute()->fetchField() > 0) {
      return FALSE;
    }
    $this->database->insert('xedc_share')->fields([
      'entity_type' => 'node',
      'entity_id' => (int) $node->id(),
      'bundle' => $node->bundle(),
      'uid' => $uid,
      'anon_hash' => $anon,
      'method' => $method,
      'outcome' => $outcome,
      'created' => $this->time->getRequestTime(),
    ])->execute();
    \Drupal\Core\Cache\Cache::invalidateTags(['xedc_engagement']);
    return TRUE;
  }

  public function anonymizeUser(int $uid): void {
    if ($uid < 1) {
      return;
    }
    $this->database->update('xedc_share')
      ->fields(['uid' => 0])
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * @return array{panel: int, copy: int, native: int, days: array<string, int>}
   *   Success counts, plus panel opens kept separate.
   */
  public function statistics(int $days = 14): array {
    $counts = ['panel' => 0, 'copy' => 0, 'native' => 0];
    $query = $this->database->select('xedc_share', 's');
    $query->addField('s', 'method');
    $query->addField('s', 'outcome');
    $query->addExpression('COUNT(*)', 'total');
    $query->groupBy('method');
    $query->groupBy('outcome');
    foreach ($query->execute() as $row) {
      if ($row->method === 'panel' && $row->outcome === 'intent') {
        $counts['panel'] = (int) $row->total;
      }
      if (($row->method === 'copy' || $row->method === 'native') && $row->outcome === 'success') {
        $counts[(string) $row->method] = (int) $row->total;
      }
    }
    $since = $this->time->getRequestTime() - ($days * 86400);
    $trend = [];
    for ($i = $days - 1; $i >= 0; $i--) {
      $trend[date('Y-m-d', $this->time->getRequestTime() - ($i * 86400))] = 0;
    }
    $rows = $this->database->select('xedc_share', 's')
      ->fields('s', ['created'])
      ->condition('outcome', 'success')
      ->condition('created', $since, '>=')
      ->execute();
    foreach ($rows as $row) {
      $day = date('Y-m-d', (int) $row->created);
      if (isset($trend[$day])) {
        $trend[$day]++;
      }
    }
    return $counts + ['days' => $trend];
  }

  protected function anonHash(): string {
    $request = $this->requestStack->getCurrentRequest();
    $session = $request?->getSession();
    if ($session && !$session->isStarted()) {
      $session->start();
    }
    $id = $session ? $session->getId() : '';
    if ($id === '') {
      return hash('sha256', Settings::getHashSalt() . '|no-session');
    }
    return hash('sha256', Settings::getHashSalt() . '|share|' . $id);
  }

}
