<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Per-member playback position. Guests stay in browser storage.
 */
final class VideoProgressController extends ControllerBase {

  public function __construct(
    private readonly UserDataInterface $userData,
    AccountInterface $currentUser,
  ) {
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('user.data'),
      $container->get('current_user'),
    );
  }

  public function read(NodeInterface $node): JsonResponse {
    if ($node->bundle() !== 'video') {
      return new JsonResponse(['position' => 0], 404);
    }
    $saved = $this->stored((int) $node->id());
    return new JsonResponse([
      'position' => (int) ($saved['position'] ?? 0),
      'saved' => (int) ($saved['saved'] ?? 0),
    ]);
  }

  public function save(Request $request, NodeInterface $node): JsonResponse {
    if ($node->bundle() !== 'video') {
      return new JsonResponse(['ok' => FALSE], 404);
    }
    $nid = (int) $node->id();
    $position = (int) $request->request->get('position');
    $duration = $node->hasField('field_duration') ? (int) $node->get('field_duration')->value : 0;
    $uid = (int) $this->currentUser->id();
    $existing = $this->stored($nid);
    $now = \Drupal::time()->getRequestTime();
    if ($position === 0 || ($duration > 0 && $position >= max(0, $duration - 3))) {
      $this->userData->delete('xedc_video', $uid, $this->key($nid));
      return new JsonResponse(['ok' => TRUE, 'cleared' => TRUE]);
    }
    if ($position < 5 || $position > 86400 || ($duration > 0 && $position > $duration)) {
      return new JsonResponse(['ok' => TRUE, 'skipped' => TRUE]);
    }
    $previous = (int) ($existing['saved'] ?? 0);
    if ($previous > 0 && ($now - $previous) < 10) {
      return new JsonResponse(['ok' => TRUE, 'skipped' => TRUE]);
    }
    $this->userData->set('xedc_video', $uid, $this->key($nid), [
      'position' => $position,
      'saved' => $now,
    ]);
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * @return array<string, mixed>
   */
  private function stored(int $nid): array {
    $value = $this->userData->get('xedc_video', (int) $this->currentUser->id(), $this->key($nid));
    return is_array($value) ? $value : [];
  }

  private function key(int $nid): string {
    return 'progress:' . $nid;
  }

}
