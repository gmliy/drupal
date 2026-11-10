<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Drush\Commands;

use Drupal\xedc_search\Service\Tracker;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Maintenance commands for search logs.
 */
final class SearchCommands extends DrushCommands {

  public function __construct(
    private readonly Tracker $tracker,
  ) {
    parent::__construct();
  }

  /**
   * Deletes search logs older than the configured retention.
   */
  #[CLI\Command(name: 'xedc-search:purge', aliases: ['xedc-search-purge'])]
  #[CLI\Usage(name: 'drush xedc-search:purge', description: '按设置清理过期搜索日志')]
  public function purge(): void {
    $days = $this->tracker->purge();
    $this->logger()->success(dt('已清理超过 @days 天的搜索日志。', ['@days' => $days]));
  }

}
