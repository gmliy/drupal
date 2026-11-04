<?php

namespace Drupal\xedc_tags\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;
use Drupal\xedc_tags\TagListing;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public tag pages. Other vocabularies keep their existing handlers.
 */
class TagsController extends ControllerBase {

  public function __construct(
    protected TagListing $listing,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_tags.listing'));
  }

  protected function termFromRoute(RouteMatchInterface $route_match): ?TermInterface {
    $term = $route_match->getParameter('taxonomy_term');
    if (!$term instanceof TermInterface) {
      $raw = $route_match->getRawParameter('taxonomy_term');
      $term = $raw ? Term::load($raw) : NULL;
    }
    return $term instanceof TermInterface ? $term : NULL;
  }

  public function termTitle(RouteMatchInterface $route_match): string {
    $term = $this->termFromRoute($route_match);
    return $term ? (string) \Drupal::service('entity.repository')->getTranslationFromContext($term)->label() : '';
  }

  /**
   * Tag detail page. Other vocabularies fall back to the original handler.
   */
  public function term(RouteMatchInterface $route_match): array|RedirectResponse {
    $term = $this->termFromRoute($route_match);
    if (!$term) {
      throw new NotFoundHttpException();
    }
    if (!$term->access('view')) {
      throw new AccessDeniedHttpException();
    }
    if ($term->bundle() === 'tags') {
      return $this->listing->term($term);
    }
    if ($term->bundle() === 'models') {
      return new RedirectResponse(Url::fromRoute('xedc_models.detail', ['taxonomy_term' => $term->id()])->toString(), 301);
    }
    if ($term->bundle() === 'video_category') {
      $video_view = \Drupal\views\Views::getView('taxonomy_term');
      if ($video_view) {
        $build = $video_view->buildRenderable('page_1', [(string) $term->id()]);
        if ($build) {
          return $build;
        }
      }
      return $this->entityTypeManager()->getViewBuilder('taxonomy_term')->view($term, 'default');
    }
    $route = $route_match->getRouteObject();
    $vid = $route->getDefault('_xt_view_id');
    $display = $route->getDefault('_xt_display_id') ?: 'default';
    if ($vid === 'taxonomy_term') {
      $vid = 'image_gallery';
      $display = 'page_3';
    }
    if ($vid) {
      $view = \Drupal\views\Views::getView($vid);
      if ($view) {
        $build = $view->buildRenderable($display, [$term->id()]);
        if ($build) {
          return $build;
        }
      }
    }
    return $this->entityTypeManager()->getViewBuilder('taxonomy_term')->view($term, 'full');
  }

  /**
   * All tags page.
   */
  public function index(): array {
    return $this->listing->index();
  }

}
