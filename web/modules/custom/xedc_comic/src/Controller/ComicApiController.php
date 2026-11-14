<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_comic\Service\ComicAccessManager;
use Drupal\xedc_comic\Service\ComicFavoriteStore;
use Drupal\xedc_comic\Service\ComicReaderManager;
use Drupal\xedc_comic\Service\ComicReadingStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reader page API and reading progress.
 */
final class ComicApiController extends ControllerBase {

  public function __construct(
    private readonly ComicReaderManager $reader,
    private readonly ComicAccessManager $access,
    private readonly ComicReadingStore $reading,
    private readonly ComicFavoriteStore $favorites,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_comic.reader'),
      $container->get('xedc_comic.access'),
      $container->get('xedc_comic.reading'),
      $container->get('xedc_comic.favorites'),
    );
  }

  public function pages(NodeInterface $comic, NodeInterface $chapter, Request $request): JsonResponse {
    $this->assertChapter($comic, $chapter);
    if (!$this->access->canViewChapter($chapter)) {
      throw new AccessDeniedHttpException();
    }
    $offset = max(0, (int) $request->query->get('offset', 0));
    $limit = min(40, max(1, (int) $request->query->get('limit', 10)));
    $data = $this->reader->pageBatch($chapter, $offset, $limit);
    return new JsonResponse($data);
  }

  public function saveProgress(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token') ?: $request->headers->get('x-csrf-token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'xedc_comic_progress')) {
      return new JsonResponse(['error' => 'csrf'], 403);
    }
    $payload = json_decode($request->getContent() ?: '{}', TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['error' => 'invalid'], 400);
    }
    $comicId = (int) ($payload['comic_id'] ?? 0);
    $chapterId = (int) ($payload['chapter_id'] ?? 0);
    $page = (int) ($payload['page'] ?? 1);
    $progress = (float) ($payload['progress'] ?? 0);
    $mode = (string) ($payload['mode'] ?? 'scroll');
    if ($comicId < 1 || $chapterId < 1) {
      return new JsonResponse(['error' => 'missing'], 400);
    }
    $chapter = $this->entityTypeManager()->getStorage('node')->load($chapterId);
    if (!$chapter instanceof NodeInterface || !$this->access->canViewChapter($chapter)) {
      throw new AccessDeniedHttpException();
    }
    $this->reading->saveProgress($comicId, $chapterId, $page, $progress, $mode);
    return new JsonResponse(['ok' => TRUE]);
  }

  public function toggleFavorite(NodeInterface $comic, Request $request): JsonResponse {
    if ($comic->bundle() !== 'comic') {
      throw new NotFoundHttpException();
    }
    if (!$this->currentUser()->isAuthenticated()) {
      throw new AccessDeniedHttpException();
    }
    $token = $request->headers->get('X-CSRF-Token') ?: $request->headers->get('x-csrf-token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'xedc_comic_favorite')) {
      return new JsonResponse(['error' => 'csrf'], 403);
    }
    $state = $this->favorites->toggle((int) $comic->id());
    return new JsonResponse(['favorite' => $state]);
  }

  public function deleteHistory(NodeInterface $comic, Request $request): JsonResponse {
    if ($comic->bundle() !== 'comic') {
      throw new NotFoundHttpException();
    }
    $token = $request->headers->get('X-CSRF-Token') ?: $request->headers->get('x-csrf-token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'xedc_comic_history')) {
      return new JsonResponse(['error' => 'csrf'], 403);
    }
    $uid = (int) $this->currentUser()->id();
    $this->reading->deleteForUser($uid, (int) $comic->id());
    return new JsonResponse(['ok' => TRUE]);
  }

  public static function pagesAccess(AccountInterface $account, NodeInterface $comic = NULL, NodeInterface $chapter = NULL): AccessResult {
    if (!$comic || !$chapter) {
      return AccessResult::forbidden();
    }
    $access = \Drupal::service('xedc_comic.access');
    return AccessResult::allowedIf($access->canViewChapter($chapter))
      ->addCacheableDependency($chapter)
      ->addCacheContexts(['user']);
  }

  private function assertChapter(NodeInterface $comic, NodeInterface $chapter): void {
    if ($comic->bundle() !== 'comic' || $chapter->bundle() !== 'comic_chapter') {
      throw new NotFoundHttpException();
    }
    $parent = $this->access->parentComic($chapter);
    if (!$parent || (int) $parent->id() !== (int) $comic->id()) {
      throw new NotFoundHttpException();
    }
  }

}