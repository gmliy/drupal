<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\Engagement\GuestIdentity;
use Drupal\xedc_core\Engagement\LikeManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like, share and QR endpoints. The user id always comes from the session.
 */
final class EngagementController extends ControllerBase {

  public function likeToken(Request $request): Response {
    $session = $request->getSession();
    if (!$session->has('xedc_like_csrf')) {
      $session->set('xedc_like_csrf', 1);
    }
    // Authenticated routes also require the core header token. Anonymous
    // requests keep the same seed so the value survives the next POST.
    $token = \Drupal::csrfToken()->get(\Drupal\Core\Access\CsrfRequestHeaderAccessCheck::TOKEN_KEY);
    $response = new Response($token, 200, [
      'Content-Type' => 'text/plain; charset=UTF-8',
      'Cache-Control' => 'no-store, private',
    ]);
    return $response;
  }

  public function toggleLike(NodeInterface $node, Request $request): JsonResponse {
    $token = (string) $request->headers->get('X-CSRF-Token', '');
    $csrf = \Drupal::csrfToken();
    $valid = $csrf->validate($token, 'xedc-like')
      || $csrf->validate($token, \Drupal\Core\Access\CsrfRequestHeaderAccessCheck::TOKEN_KEY)
      || $csrf->validate($token, 'rest');
    if (!$valid) {
      return new JsonResponse(['ok' => FALSE, 'message' => '请求未通过安全验证。'], 403);
    }
    if (!in_array($node->bundle(), LikeManager::ALLOWED_BUNDLES, TRUE) || !$node->isPublished()) {
      return new JsonResponse(['ok' => FALSE, 'message' => '这篇内容不能点赞。'], 404);
    }
    if (!$node->access('view', $this->currentUser())) {
      return new JsonResponse(['ok' => FALSE, 'message' => '不能点赞这篇内容。'], 403);
    }
    try {
      if ($this->currentUser()->isAuthenticated()) {
        $state = $this->likes()->toggle((int) $this->currentUser()->id(), $node);
      }
      else {
        $state = $this->likes()->toggleActor(0, $this->guests()->issueHash(), $node);
      }
    }
    catch (\OverflowException $e) {
      return new JsonResponse(['ok' => FALSE, 'message' => $e->getMessage()], 429);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['ok' => FALSE, 'message' => $e->getMessage()], 403);
    }
    return new JsonResponse([
      'ok' => TRUE,
      'liked' => $state['liked'],
      'count' => $state['count'],
    ]);
  }

  public function recordShare(NodeInterface $node, Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      $payload = $request->request->all();
    }
    $method = (string) ($payload['method'] ?? '');
    $outcome = (string) ($payload['outcome'] ?? '');
    $saved = $this->shares()->record($node, $method, $outcome, $this->currentUser());
    return new JsonResponse(['ok' => TRUE, 'saved' => $saved]);
  }

  public function qr(NodeInterface $node): Response {
    if (!$node->isPublished() || !$node->access('view')) {
      return new Response('内容不存在。', 404);
    }
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    try {
      $svg = \Drupal::service('xedc_core.qr')->svg($url);
    }
    catch (\Throwable) {
      return new Response('二维码生成失败。', 500);
    }
    $response = new Response($svg, 200, ['Content-Type' => 'image/svg+xml; charset=UTF-8']);
    $response->headers->set('Cache-Control', 'public, max-age=300');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

  protected function guests(): GuestIdentity {
    return \Drupal::service('xedc_core.guest_identity');
  }

  protected function likes(): LikeManager {
    return \Drupal::service('xedc_core.like_manager');
  }

  protected function shares(): \Drupal\xedc_core\Engagement\ShareRecorder {
    return \Drupal::service('xedc_core.share_recorder');
  }

}
