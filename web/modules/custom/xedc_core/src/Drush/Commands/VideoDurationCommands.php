<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Drush\Commands;

use Drupal\xedc_core\Video\DurationManager;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Reports and backfills field_duration. It never rewrites other fields.
 */
final class VideoDurationCommands extends DrushCommands {

  public function __construct(
    private readonly DurationManager $duration,
  ) {
    parent::__construct();
  }

  /**
   * Prints video duration coverage.
   */
  #[CLI\Command(name: 'xedc:video-duration-stats', aliases: ['xedc-video-duration-stats'])]
  public function stats(): void {
    $stats = $this->duration->statistics();
    foreach ($stats as $key => $value) {
      $this->output()->writeln($key . '=' . $value);
    }
    $failures = $this->duration->failures();
    $this->output()->writeln('recorded_failures=' . count($failures));
  }

  /**
   * Fills missing durations in small batches.
   */
  #[CLI\Command(name: 'xedc:video-duration-backfill', aliases: ['xedc-video-duration-backfill'])]
  #[CLI\Option(name: 'limit', description: 'Maximum videos to probe in this run')]
  #[CLI\Option(name: 'retry', description: 'Start again from the first missing video')]
  #[CLI\Option(name: 'nid', description: 'Probe one video even when it already has a duration')]
  public function backfill(array $options = ['limit' => 2, 'retry' => FALSE, 'nid' => 0]): void {
    $nid = (int) $options['nid'];
    if ($nid > 0) {
      $result = $this->duration->refresh($nid);
      $this->output()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE));
      return;
    }
    $report = $this->duration->backfill((int) $options['limit'], (bool) $options['retry']);
    $this->output()->writeln(json_encode($report, JSON_UNESCAPED_UNICODE));
  }

}
