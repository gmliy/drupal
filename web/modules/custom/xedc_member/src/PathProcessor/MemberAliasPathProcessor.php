<?php

namespace Drupal\xedc_member\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\PathProcessor\OutboundPathProcessorInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\xedc_member\Service\MemberAliasManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * Maps /{slug} and /{slug}/section onto the member center, and back out.
 *
 * Inbound mapping depends only on the slug, never on the current account.
 * Drupal stores the processed path in cache.data under the request path, with
 * no user cache context. A user-specific inbound result would be reused by
 * every later visitor. Ownership is enforced afterwards by the route access
 * check, which sees the original request path.
 */
class MemberAliasPathProcessor implements InboundPathProcessorInterface, OutboundPathProcessorInterface {

  /**
   * Constructs a MemberAliasPathProcessor.
   */
  public function __construct(
    protected MemberAliasManager $aliasManager,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    return $this->aliasManager->vanityInternalPath($path) ?? $path;
  }

  /**
   * {@inheritdoc}
   */
  public function processOutbound($path, &$options = [], ?Request $request = NULL, ?BubbleableMetadata $bubbleable_metadata = NULL) {
    if ($path !== '/member' && !str_starts_with($path, '/member/')) {
      return $path;
    }
    if ($bubbleable_metadata) {
      $bubbleable_metadata->addCacheContexts(['user']);
      $bubbleable_metadata->addCacheTags(['xedc_member:aliases']);
    }
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return $path;
    }
    if ($bubbleable_metadata) {
      $bubbleable_metadata->addCacheTags(['user:' . $uid]);
    }
    return $this->aliasManager->toPublicPath($uid, $path);
  }

}
