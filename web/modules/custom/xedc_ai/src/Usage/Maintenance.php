<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Usage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\xedc_ai\Budget\BudgetService;
use Drupal\xedc_ai\Review\ReviewRepository;

/**
 * Releases stale reservations and applies the retention window.
 */
final class Maintenance {

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly BudgetService $budget,
    private readonly ReviewRepository $reviews,
  ) {}

  public function purge(): void {
    $this->budget->releaseStale();
    $this->reviews->failStuck();
    $days = (int) $this->configFactory->get('xedc_ai.settings')->get('retention_days');
    if ($days < 1) {
      return;
    }
    $before = $this->time->getRequestTime() - ($days * 86400);
    foreach (['xedc_ai_review', 'xedc_ai_usage', 'xedc_ai_audit', 'xedc_ai_hold'] as $table) {
      if ($this->database->schema()->tableExists($table)) {
        $this->database->delete($table)->condition('created', $before, '<')->execute();
      }
    }
  }

}
