<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\xedc_search\Query\QueryNormalizer;
use Drupal\xedc_search\Repository\HistoryRepository;
use Drupal\xedc_search\Repository\StatsRepository;

/**
 * Records a search after flood and duplicate checks.
 */
final class Tracker {

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
    private readonly QueryNormalizer $normalizer,
    private readonly HistoryRepository $history,
    private readonly SearchEngine $engine,
    private readonly StatsRepository $stats,
    private readonly CacheTagsInvalidatorInterface $cacheTags,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * @return array{counted: bool, total: int, history_id: int}
   */
  public function record(string $q, string $type, ?string $visitor = NULL, bool $saveHistory = TRUE): array {
    $config = $this->configFactory->get('xedc_search.settings');
    $result = ['counted' => FALSE, 'total' => 0, 'history_id' => 0];
    if (!(bool) $config->get('enabled')) {
      return $result;
    }
    $q = $this->normalizer->clean($q);
    $type = $this->normalizer->type($type);
    if (mb_strlen($q) < max(1, (int) $config->get('min_length'))) {
      return $result;
    }
    $window = max(1, (int) $config->get('flood_window') ?: 60);
    $trackLimit = max(1, (int) $config->get('track_limit') ?: 30);
    if (!$this->flood->isAllowed('xedc_search.track', $trackLimit, $window)) {
      return $result;
    }
    $this->flood->register('xedc_search.track', $window);
    $key = $this->normalizer->key($q);
    $once = substr(hash('sha256', $key), 0, 32);
    if (!$this->flood->isAllowed('xedc_search.once', 1, 60, $once)) {
      if ($saveHistory) {
        $result['history_id'] = $this->saveHistory($q, $type);
      }
      return $result;
    }
    try {
      $counts = $this->engine->counts($q, []);
      $total = $counts[$type] ?? $counts['all'] ?? 0;
      $hash = $this->visitorHash($visitor);
      $day = (int) date('Ymd', $this->time->getRequestTime());
      $newVisitor = FALSE;
      if ($hash !== '' && $this->flood->isAllowed('xedc_search.visitor', 20, 3600)) {
        $newVisitor = $this->stats->rememberVisitor($day, $key, $hash);
        if ($newVisitor) {
          $this->flood->register('xedc_search.visitor', 3600);
        }
      }
      $this->stats->record($q, $key, $type, $total, $newVisitor);
      $this->flood->register('xedc_search.once', 60, $once);
      $this->cacheTags->invalidateTags(['xedc_search']);
      $result['counted'] = TRUE;
      $result['total'] = $total;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Search track failed: @message', ['@message' => $exception->getMessage()]);
      return $result;
    }
    if ($saveHistory) {
      $result['history_id'] = $this->saveHistory($q, $type);
    }
    return $result;
  }

  public function purge(): int {
    $days = max(1, (int) $this->configFactory->get('xedc_search.settings')->get('log_retention_days') ?: 90);
    $cutoff = $this->time->getRequestTime() - ($days * 86400);
    $this->stats->purge($days);
    $this->history->purgeOlderThan($cutoff);
    return $days;
  }

  private function saveHistory(string $q, string $type): int {
    $config = $this->configFactory->get('xedc_search.settings');
    if (!(bool) $config->get('history_enabled') || !$this->currentUser->isAuthenticated()) {
      return 0;
    }
    return $this->history->add((int) $this->currentUser->id(), $q, $type);
  }

  private function visitorHash(?string $token): string {
    $account = $this->currentUser->getAccount();
    if ($account->isAuthenticated()) {
      $raw = 'u:' . $account->id();
    }
    elseif (is_string($token) && preg_match('/^[A-Za-z0-9_-]{8,80}$/', $token)) {
      $raw = 't:' . $token;
    }
    else {
      return '';
    }
    return hash('sha256', Settings::getHashSalt() . '|xedc-search|' . $raw);
  }

}
