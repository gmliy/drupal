<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\xedc_ai\Form\SeoSuggestForm;
use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Review\ReviewRepository;
use Drupal\xedc_ai\Seo\SeoAdvisor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Saved audit explanation and on-demand SEO suggestions.
 */
final class SeoController extends ControllerBase {

  public function __construct(
    private readonly SeoAdvisor $seo,
    private readonly ReviewRepository $reviews,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.seo'),
      $container->get('xedc_ai.reviews'),
      $container->get('date.formatter'),
    );
  }

  /**
   * SEO page.
   */
  public function page(): array {
    $rows = [];
    foreach ($this->reviews->recent(12, 'seo_optimization') as $row) {
      $rows[] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'status' => Labels::status((string) $row['status']),
        'time' => $this->dates->format((int) $row['created'], 'short'),
        'url' => Url::fromRoute('xedc_ai.review', ['review' => $row['id']])->toString(),
      ];
    }
    $audit = $this->seo->storedAudit();
    $audit['seo_url'] = $this->routeExists('audit.reports') ? Url::fromRoute('audit.reports')->toString() : '';
    $audit['site_url'] = $this->routeExists('site_audit.report') ? Url::fromRoute('site_audit.report')->toString() : '';
    return [
      '#theme' => 'xedc_ai_seo',
      '#audit' => $audit,
      '#rows' => $rows,
      '#suggest' => $this->currentUser()->hasPermission('execute xedc ai reviews') ? $this->formBuilder()->getForm(SeoSuggestForm::class) : NULL,
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

  private function routeExists(string $name): bool {
    return $this->routeProvider()->getRoutesByNames([$name]) !== [];
  }

  private function routeProvider(): \Drupal\Core\Routing\RouteProviderInterface {
    return \Drupal::service('router.route_provider');
  }

}
