<?php

declare(strict_types=1);

namespace Drupal\xedc_member\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;

/**
 * Member submission crumbs: 投稿中心 > current type.
 *
 * Applies only when xedc_member_is_submission_page() is TRUE so staff Gin
 * trails and public content pages stay untouched.
 */
final class MemberSubmissionBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

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
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route', 'url.query_args:upload', 'user.permissions']);

    $breadcrumb->addLink(Link::createFromRoute($this->t('投稿中心'), 'xedc_member.submissions'));

    $label = $this->currentLabel($route_match);
    $breadcrumb->addLink(Link::createFromRoute($label, '<none>'));

    return $breadcrumb;
  }

  /**
   * Human label for the current submission page.
   */
  private function currentLabel(RouteMatchInterface $route_match): \Drupal\Core\StringTranslation\TranslatableMarkup {
    $bundle = $this->bundle($route_match);
    if ($bundle === 'photo') {
      $mode = function_exists('xedc_core_member_photo_upload_mode')
        ? xedc_core_member_photo_upload_mode()
        : 'image';
      if ($mode === 'archive') {
        return $this->t('压缩包投稿');
      }
      return $this->t('图片投稿');
    }
    return match ($bundle) {
      'article' => $this->t('文章投稿'),
      'video' => $this->t('视频投稿'),
      default => $this->t('内容投稿'),
    };
  }

  /**
   * Resolves the node bundle for add/edit submission routes.
   */
  private function bundle(RouteMatchInterface $route_match): string {
    $route = (string) $route_match->getRouteName();
    if (str_starts_with($route, 'node.add')) {
      $node_type = $route_match->getParameter('node_type');
      if ($node_type instanceof NodeTypeInterface) {
        return $node_type->id();
      }
      return is_string($node_type) ? $node_type : '';
    }
    if ($route === 'entity.node.edit_form') {
      $node = $route_match->getParameter('node');
      if ($node instanceof NodeInterface) {
        return $node->bundle();
      }
    }
    return '';
  }

}
