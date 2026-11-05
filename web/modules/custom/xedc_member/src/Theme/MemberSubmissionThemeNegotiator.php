<?php

declare(strict_types=1);

namespace Drupal\xedc_member\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Forces the frontend theme for ordinary members on submission forms.
 *
 * Node add/edit is marked as an admin route when use_admin_theme is on, which
 * would otherwise pull members into the staff chrome and import tooling.
 */
final class MemberSubmissionThemeNegotiator implements ThemeNegotiatorInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route = $route_match->getRouteName();
    return function_exists('xedc_member_is_submission_page')
      && xedc_member_is_submission_page(is_string($route) ? $route : NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    $theme = (string) $this->configFactory->get('system.theme')->get('default');
    return $theme !== '' ? $theme : NULL;
  }

}
