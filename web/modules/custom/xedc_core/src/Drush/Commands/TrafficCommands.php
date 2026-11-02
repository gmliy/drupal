<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Drush\Commands;

use Drupal\xedc_core\Ops\Traffic\TrafficCollector;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Samples physical NIC counters. This does not run from the monitor page.
 */
final class TrafficCommands extends DrushCommands {

  public function __construct(
    private readonly TrafficCollector $collector,
  ) {
    parent::__construct();
  }

  /**
   * Records one counter sample for each physical NIC.
   */
  #[CLI\Command(name: 'xedc:traffic-sample', aliases: ['xedc-traffic-sample'])]
  #[CLI\Usage(name: 'drush xedc:traffic-sample', description: 'Sample physical NIC counters once')]
  public function sample(): void {
    foreach ($this->collector->samplePhysical() as $row) {
      $status = (string) ($row['status'] ?? '');
      if (!in_array($status, ['ok', 'sparse', 'skipped', 'baseline'], TRUE)) {
        $this->logger()->warning('{iface} {status}', [
          'iface' => (string) ($row['iface'] ?? ''),
          'status' => $status,
        ]);
      }
    }
  }

}
