<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\xedc_core\Entity\DownloadResource;

/**
 * Records a download only after the server has authorized the handoff.
 */
final class DownloadRecorder {

  public const PAGER_ELEMENT = 12;

  public const PER_PAGE = 20;

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  public function record(int $uid, DownloadResource $resource, string $extension, int $bytes, string $status = 'authorized', string $delivery = '', string $detail = ''): void {
    try {
      $parent = $resource->get('parent')->entity;
      $tier = 'anonymous';
      if ($uid > 0 && \Drupal::hasService('xedc_core.membership')) {
        $account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
        $tier = $account ? \Drupal::service('xedc_core.membership')->tier($account) : 'anonymous';
      }
      $this->database->insert('xedc_download_log')->fields([
        'uid' => max(0, $uid),
        'resource_id' => (int) $resource->id(),
        'content_id' => (int) $resource->get('parent')->target_id,
        'bundle' => $parent ? $parent->bundle() : '',
        'title' => mb_substr((string) $resource->label(), 0, 255),
        'extension' => mb_substr(strtolower($extension), 0, 16),
        'bytes' => max(0, $bytes),
        'source' => mb_substr((string) $resource->get('source_type')->value, 0, 16),
        'tier' => mb_substr($tier, 0, 16),
        'delivery' => mb_substr($delivery, 0, 16),
        'detail' => mb_substr($detail, 0, 255),
        'status' => mb_substr($status, 0, 16) ?: 'authorized',
        'created' => $this->time->getRequestTime(),
      ])->execute();
      \Drupal\Core\Cache\Cache::invalidateTags(['xedc_engagement']);
    }
    catch (\Throwable) {
      // A log failure must not block the file that was already authorized.
    }
  }

  public function deleteForUser(int $uid): void {
    if ($uid < 1) {
      return;
    }
    $this->database->delete('xedc_download_log')->condition('uid', $uid)->execute();
  }

  public function deleteForContent(int $nid): void {
    $this->database->delete('xedc_download_log')->condition('content_id', $nid)->execute();
  }

  /**
   * @return object[]
   *   History rows for the member center.
   */
  public function history(int $uid): array {
    $query = $this->database->select('xedc_download_log', 'd');
    $query->fields('d');
    $query->condition('uid', $uid);
    $query->orderBy('created', 'DESC');
    $query = $query->extend(PagerSelectExtender::class);
    $query->element(self::PAGER_ELEMENT);
    $query->limit(self::PER_PAGE);
    return $query->execute()->fetchAll();
  }

  public function countForUser(int $uid): int {
    return (int) $this->database->select('xedc_download_log', 'd')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * @return array{total: int, bundles: array<string, int>, days: array<string, int>}
   *   Authorized handoffs only.
   */
  public function statistics(int $days = 14): array {
    $total = (int) $this->database->select('xedc_download_log', 'd')
      ->condition('status', 'authorized')
      ->countQuery()
      ->execute()
      ->fetchField();
    $bundles = [];
    $grouped = $this->database->select('xedc_download_log', 'd');
    $grouped->addField('d', 'bundle');
    $grouped->addExpression('COUNT(*)', 'total');
    $grouped->condition('status', 'authorized');
    $grouped->groupBy('bundle');
    foreach ($grouped->execute() as $row) {
      $bundles[(string) $row->bundle] = (int) $row->total;
    }
    $since = $this->time->getRequestTime() - ($days * 86400);
    $trend = [];
    for ($i = $days - 1; $i >= 0; $i--) {
      $trend[date('Y-m-d', $this->time->getRequestTime() - ($i * 86400))] = 0;
    }
    $rows = $this->database->select('xedc_download_log', 'd')
      ->fields('d', ['created'])
      ->condition('status', 'authorized')
      ->condition('created', $since, '>=')
      ->execute();
    foreach ($rows as $row) {
      $day = date('Y-m-d', (int) $row->created);
      if (isset($trend[$day])) {
        $trend[$day]++;
      }
    }
    return [
      'total' => $total,
      'bundles' => $bundles,
      'days' => $trend,
    ];
  }

}
