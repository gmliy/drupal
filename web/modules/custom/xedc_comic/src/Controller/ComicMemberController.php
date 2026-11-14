<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\xedc_comic\Service\ComicFavoriteStore;
use Drupal\xedc_comic\Service\ComicManager;
use Drupal\xedc_comic\Service\ComicReadingStore;
use Drupal\xedc_member\Controller\MemberController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Member-center comic favorites and reading history (xedc_comic tables).
 */
final class ComicMemberController extends ControllerBase {

  private const PER_PAGE = 24;

  public function __construct(
    private readonly ComicFavoriteStore $favorites,
    private readonly ComicReadingStore $reading,
    private readonly ComicManager $comics,
    private readonly PagerManagerInterface $pager,
    private readonly DateFormatterInterface $dates,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_comic.favorites'),
      $container->get('xedc_comic.reading'),
      $container->get('xedc_comic.manager'),
      $container->get('pager.manager'),
      $container->get('date.formatter'),
    );
  }

  public function favorites(): array {
    $this->assertMemberCenter();
    $uid = (int) $this->currentUser()->id();
    $total = $this->favorites->countForUser($uid);
    $this->pager->createPager($total, self::PER_PAGE);
    $offset = $this->pager->findPage() * self::PER_PAGE;
    $rows = $this->favorites->listForUser($uid, self::PER_PAGE, $offset);
    $items = [];
    $nodes = [];
    foreach ($rows as $row) {
      $nid = (int) $row['comic_id'];
      $node = $this->entityTypeManager()->getStorage('node')->load($nid);
      if ($node instanceof NodeInterface && $node->bundle() === 'comic' && $node->isPublished()) {
        $nodes[$nid] = $node;
      }
    }
    $cards = $this->comics->comicCardsBatch(array_values($nodes));
    $cardById = [];
    foreach ($cards as $card) {
      $cardById[(int) ($card['id'] ?? 0)] = $card;
    }
    foreach ($rows as $row) {
      $nid = (int) $row['comic_id'];
      if (!isset($cardById[$nid])) {
        continue;
      }
      $items[] = [
        'card' => $cardById[$nid],
        'favorited_at' => $this->dates->format((int) $row['created'], 'short'),
        'detail_url' => Url::fromRoute('xedc_comic.detail', ['comic' => $nid])->toString(),
      ];
    }
    $content = [
      '#theme' => 'xedc_comic_member_favorites',
      '#total' => $total,
      '#items' => $items,
      '#pager' => ['#type' => 'pager'],
      '#attached' => [
        'library' => ['xedc_comic/member'],
        'drupalSettings' => [
          'xedcComicMember' => [
            'csrfToken' => \Drupal::csrfToken()->get('xedc_comic_favorite'),
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];
    return $this->memberShell('comic_favorites', $content);
  }

  public function history(): array {
    $this->assertMemberCenter();
    $uid = (int) $this->currentUser()->id();
    $total = $this->reading->countForUser($uid);
    $this->pager->createPager($total, self::PER_PAGE);
    $offset = $this->pager->findPage() * self::PER_PAGE;
    $rows = $this->reading->listForUser($uid, self::PER_PAGE, $offset);
    $entries = [];
    foreach ($rows as $row) {
      $comicId = (int) $row['comic_id'];
      $chapterId = (int) $row['chapter_id'];
      $comic = $this->entityTypeManager()->getStorage('node')->load($comicId);
      $chapter = $this->entityTypeManager()->getStorage('node')->load($chapterId);
      if (!$comic instanceof NodeInterface || $comic->bundle() !== 'comic') {
        continue;
      }
      $card = $this->comics->comicCard($comic);
      $progress = round((float) $row['progress'] * 100);
      $entries[] = [
        'card' => $card,
        'chapter_title' => $chapter instanceof NodeInterface ? $chapter->label() : $this->t('未知章节'),
        'page' => (int) $row['page_number'],
        'progress' => $progress,
        'last_read' => $this->dates->format((int) $row['last_read'], 'short'),
        'continue_url' => Url::fromRoute('xedc_comic.reader', [
          'comic' => $comicId,
          'chapter' => $chapterId,
        ], ['query' => ['page' => (int) $row['page_number']]])->toString(),
        'comic_id' => $comicId,
      ];
    }
    $content = [
      '#theme' => 'xedc_comic_member_history',
      '#total' => $total,
      '#entries' => $entries,
      '#pager' => ['#type' => 'pager'],
      '#attached' => [
        'library' => ['xedc_comic/member'],
        'drupalSettings' => [
          'xedcComicMember' => [
            'csrfToken' => \Drupal::csrfToken()->get('xedc_comic_history'),
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];
    return $this->memberShell('comic_history', $content);
  }

  private function memberShell(string $active, array $content): array {
    /** @var \Drupal\xedc_member\Controller\MemberController $member */
    $member = \Drupal::service('class_resolver')->getInstanceFromDefinition(MemberController::class);
    return $member->renderShell($active, $content);
  }

  private function assertMemberCenter(): void {
    if (!\Drupal::moduleHandler()->moduleExists('xedc_member')) {
      throw new NotFoundHttpException();
    }
  }

}