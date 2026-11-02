<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\xedc_ai\Budget\BudgetService;
use Drupal\xedc_ai\Budget\Money;
use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Provider\ModelRepository;
use Drupal\xedc_ai\Review\ReviewRepository;
use Drupal\xedc_ai\Usage\OperationsStats;
use Drupal\xedc_ai\Usage\UsageLogger;
use Drupal\xedc_auto_translate\Translator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Operations overview. Every number comes from stored rows.
 */
final class OverviewController extends ControllerBase {

  public function __construct(
    private readonly ModelRepository $models,
    private readonly ReviewRepository $reviews,
    private readonly UsageLogger $usage,
    private readonly BudgetService $budget,
    private readonly DateFormatterInterface $dates,
    private readonly Translator $translator,
    private readonly OperationsStats $stats,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.models'),
      $container->get('xedc_ai.reviews'),
      $container->get('xedc_ai.usage'),
      $container->get('xedc_ai.budget'),
      $container->get('date.formatter'),
      $container->get('xedc_auto_translate.translator'),
      $container->get('xedc_ai.stats'),
    );
  }

  /**
   * Renders the overview.
   */
  public function page(): array {
    $counts = $this->reviews->counts();
    $budget = $this->budget->overview();
    $cards = $this->stats->cards($budget);
    $default = $this->models->defaultProfile();
    $enabled = 0;
    foreach ($this->models->all() as $model) {
      if ((int) $model['enabled'] === 1) {
        $enabled++;
      }
    }
    $provider = (string) $this->config('xedc_auto_translate.settings')->get('provider');
    $calls = [];
    foreach ($this->usage->recent(8) as $row) {
      $calls[] = [
        'time' => $this->dates->format((int) $row['created'], 'short'),
        'source' => Labels::source((string) $row['source']),
        'provider' => (string) $row['provider_id'],
        'model' => (string) $row['model_name'],
        'status' => Labels::status((string) $row['status']),
        'tokens' => (int) $row['total_tokens'],
        'cost' => (int) $row['cost_known'] === 1 ? Money::yuan((int) $row['cost_micro']) : '未核算',
      ];
    }
    $reviews = [];
    foreach ($this->reviews->recent(8) as $row) {
      $reviews[] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'bundle' => Labels::bundle((string) $row['bundle']),
        'status' => Labels::status((string) $row['status']),
        'risk' => Labels::risk((string) $row['risk_level']),
        'url' => Url::fromRoute('xedc_ai.review', ['review' => $row['id']])->toString(),
        'time' => $this->dates->format((int) $row['created'], 'short'),
      ];
    }
    return [
      '#theme' => 'xedc_ai_overview',
      '#stats' => [
        'models' => $enabled,
        'default_model' => $default ? $default->providerLabel . ' / ' . $default->modelName : '未设置',
        'today_calls' => $cards['today_calls'],
        'today_tokens' => $cards['today_tokens'],
        'month_tokens' => $budget['month_tokens'],
        'month_cost' => $cards['month_cost'],
        'pending' => $cards['pending'],
        'completed' => $counts['completed'],
        'passed' => $cards['passed'],
        'changes' => $cards['changes'],
        'high' => $cards['high'],
        'failed' => $cards['failed'],
        'today_reviews' => $cards['today_reviews'],
        'total_reviews' => $cards['total_reviews'],
        'duplicates' => $cards['duplicates'],
      ],
      '#charts' => [
        'daily' => $this->stats->dailyReviews(),
        'bundles' => $this->stats->bundles(),
        'risks' => $this->stats->risks(),
        'models' => $this->stats->models(),
        'cost' => $this->stats->dailyCost(),
      ],
      '#budget' => $budget,
      '#calls' => $calls,
      '#reviews' => $reviews,
      '#translation' => [
        'provider' => $provider === 'deepl' ? 'DeepL' : 'Microsoft Translator',
        'configured' => $this->translator->hasStoredApiKey($provider),
        'url' => Url::fromRoute('xedc_auto_translate.settings')->toString(),
        'locale_url' => Url::fromRoute('xedc_ui_locale.manage')->access($this->currentUser()) ? Url::fromRoute('xedc_ui_locale.manage')->toString() : '',
      ],
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
