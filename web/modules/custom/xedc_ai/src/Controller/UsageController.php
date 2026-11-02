<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\xedc_ai\Budget\Money;
use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Usage\UsageLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filterable API usage. Prompts and keys are not shown.
 */
final class UsageController extends ControllerBase {

  public function __construct(
    private readonly UsageLogger $usage,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.usage'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Usage page.
   */
  public function page(): array {
    $request = \Drupal::request();
    $filters = [
      'source' => (string) $request->query->get('source', ''),
      'provider_id' => (string) $request->query->get('provider_id', ''),
      'model_name' => (string) $request->query->get('model_name', ''),
      'status' => (string) $request->query->get('status', ''),
      'from' => $this->stamp((string) $request->query->get('from', ''), FALSE),
      'to' => $this->stamp((string) $request->query->get('to', ''), TRUE),
    ];
    $result = $this->usage->search($filters);
    $rows = [];
    foreach ($result['rows'] as $row) {
      $rows[] = [
        'time' => $this->dates->format((int) $row['created'], 'short'),
        'source' => Labels::source((string) $row['source']),
        'provider' => (string) $row['provider_id'],
        'model' => (string) $row['model_name'],
        'status' => Labels::status((string) $row['status']),
        'status_key' => (string) $row['status'],
        'prompt' => (int) $row['prompt_tokens'],
        'completion' => (int) $row['completion_tokens'],
        'total' => (int) $row['total_tokens'],
        'duration' => (int) $row['duration_ms'],
        'cost' => (int) $row['cost_known'] === 1 ? Money::yuan((int) $row['cost_micro']) : '未核算',
        'error' => (string) $row['error_message'],
      ];
    }
    return [
      '#theme' => 'xedc_ai_usage',
      '#rows' => $rows,
      '#filters' => [
        'source' => (string) $request->query->get('source', ''),
        'provider_id' => (string) $request->query->get('provider_id', ''),
        'model_name' => (string) $request->query->get('model_name', ''),
        'status' => (string) $request->query->get('status', ''),
        'from' => (string) $request->query->get('from', ''),
        'to' => (string) $request->query->get('to', ''),
      ],
      '#summary' => [
        'tokens' => $result['total_tokens'],
        'cost' => Money::yuan($result['cost_micro']),
        'unpriced' => $result['unpriced'],
      ],
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

  private function stamp(string $value, bool $end): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return 0;
    }
    try {
      $zone = new \DateTimeZone(date_default_timezone_get());
      return (new \DateTimeImmutable($value . ($end ? ' 23:59:59' : ' 00:00:00'), $zone))->getTimestamp();
    }
    catch (\Throwable) {
      return 0;
    }
  }

}
