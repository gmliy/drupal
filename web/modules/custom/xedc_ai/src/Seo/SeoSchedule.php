<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Seo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Review\ReviewEnqueuer;
use Drupal\xedc_ai\Review\ReviewRepository;

/**
 * Optional SEO queue. It stays off until an administrator enables a scope.
 */
final class SeoSchedule {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ReviewEnqueuer $enqueuer,
    private readonly ReviewRepository $reviews,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Queues a small batch. Returns how many tasks were created.
   */
  public function run(): int {
    $config = $this->configFactory->get('xedc_ai.settings');
    if (!(bool) $config->get('seo_schedule_enabled')) {
      return 0;
    }
    $bundles = [];
    foreach (['photo' => 'seo_schedule_photo', 'video' => 'seo_schedule_video', 'article' => 'seo_schedule_article', 'page' => 'seo_schedule_page'] as $bundle => $key) {
      if ((bool) $config->get($key)) {
        $bundles[] = $bundle;
      }
    }
    if ($bundles === []) {
      return 0;
    }
    $limit = max(1, min(10, (int) ($config->get('seo_schedule_limit') ?: 3)));
    $now = $this->time->getRequestTime();
    $ids = [];
    if ((bool) $config->get('seo_schedule_daily_new')) {
      $ids = array_merge($ids, $this->publishedSince($bundles, $now - 86400, 'created', $limit));
    }
    if ((bool) $config->get('seo_schedule_changed')) {
      $ids = array_merge($ids, $this->changedSinceLastReview($bundles, $limit));
    }
    if ((bool) $config->get('seo_schedule_weekly') && (int) date('N', $now) === 1) {
      $ids = array_merge($ids, $this->changedSinceLastReview($bundles, $limit, $now - 7 * 86400));
    }
    if ((bool) $config->get('seo_schedule_stale')) {
      $days = max(7, (int) ($config->get('seo_schedule_stale_days') ?: 30));
      $ids = array_merge($ids, $this->reviews->staleSeoEntities($bundles, $now - $days * 86400, $limit));
    }
    $queued = 0;
    foreach (array_unique(array_map('intval', $ids)) as $nid) {
      if ($queued >= $limit || $this->reviews->hasOpenForEntity($nid, 'seo_optimization')) {
        continue;
      }
      $node = $this->entities->getStorage('node')->load($nid);
      if (!$node instanceof NodeInterface || !$node->isPublished()) {
        continue;
      }
      $this->enqueuer->request($node, 0, 'seo_optimization', 'seo', FALSE, FALSE);
      $queued++;
    }
    return $queued;
  }

  /**
   * @param list<string> $bundles
   *   Content types in scope.
   *
   * @return list<int>
   *   Node ids.
   */
  private function publishedSince(array $bundles, int $since, string $field, int $limit): array {
    $ids = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundles, 'IN')
      ->condition('status', 1)
      ->condition($field, $since, '>=')
      ->sort($field, 'DESC')
      ->range(0, $limit)
      ->execute();
    $fresh = [];
    foreach ($ids as $id) {
      if ($this->reviews->latestForEntity((int) $id, 'seo_optimization') === NULL) {
        $fresh[] = (int) $id;
      }
    }
    return $fresh;
  }

  /**
   * Nodes that already have an SEO review and have a newer revision.
   *
   * @param list<string> $bundles
   *   Content types.
   *
   * @return list<int>
   *   Node ids.
   */
  private function changedSinceLastReview(array $bundles, int $limit, int $changedAfter = 0): array {
    $query = $this->entities->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundles, 'IN')
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, 40);
    if ($changedAfter > 0) {
      $query->condition('changed', $changedAfter, '>=');
    }
    $ids = [];
    foreach ($query->execute() as $id) {
      $latest = $this->reviews->latestForEntity((int) $id, 'seo_optimization');
      if ($latest === NULL) {
        continue;
      }
      $node = $this->entities->getStorage('node')->load((int) $id);
      if ($node instanceof NodeInterface && (int) $node->getRevisionId() !== (int) $latest['revision_id']) {
        $ids[] = (int) $id;
      }
      if (count($ids) >= $limit) {
        break;
      }
    }
    return $ids;
  }

}
