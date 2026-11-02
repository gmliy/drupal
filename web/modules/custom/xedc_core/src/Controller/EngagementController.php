<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\Engagement\LikeManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like, share and QR endpoints. The user id always comes from the session.
 */
final class EngagementController extends ControllerBase {

  public function toggleLike(NodeInterface $node): JsonResponse {
    if (!$this->currentUser()->isAuthenticated()) {
      return new JsonResponse([
        'ok' => FALSE,
        'message' => '请先登录后再点赞。',
        'login' => \Drupal\Core\Url::fromRoute('user.login', [], [
          'query' => ['destination' => $node->toUrl()->toString()],
        ])->toString(),
      ], 401);
    }
    if (!in_array($node->bundle(), LikeManager::ALLOWED_BUNDLES, TRUE) || !$node->isPublished()) {
      return new JsonResponse(['ok' => FALSE, 'message' => '这篇内容不能点赞。'], 404);
    }
    try {
      $state = $this->likes()->toggle((int) $this->currentUser()->id(), $node);
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

  protected function likes(): LikeManager {
    return \Drupal::service('xedc_core.like_manager');
  }

  protected function shares(): \Drupal\xedc_core\Engagement\ShareRecorder {
    return \Drupal::service('xedc_core.share_recorder');
  }

}
