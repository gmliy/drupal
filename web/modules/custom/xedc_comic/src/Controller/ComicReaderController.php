<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\xedc_comic\Service\ComicAccessManager;
use Drupal\xedc_comic\Service\ComicReaderManager;
use Drupal\xedc_comic\Service\ComicStatsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Comic reader shell. Images load via JSON API.
 */
final class ComicReaderController extends ControllerBase {

  public function __construct(
    private readonly ComicAccessManager $access,
    private readonly ComicReaderManager $reader,
    private readonly ComicStatsManager $stats,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_comic.access'),
      $container->get('xedc_comic.reader'),
      $container->get('xedc_comic.stats'),
    );
  }

  public function read(NodeInterface $comic, NodeInterface $chapter): array {
    if ($comic->bundle() !== 'comic' || $chapter->bundle() !== 'comic_chapter') {
      throw new NotFoundHttpException();
    }
    $parent = $this->access->parentComic($chapter);
    if (!$parent || (int) $parent->id() !== (int) $comic->id()) {
      throw new NotFoundHttpException();
    }
    if (!$this->access->canViewChapter($chapter)) {
      throw new AccessDeniedHttpException();
    }
    $this->stats->recordChapterView((int) $comic->id(), (int) $chapter->id());
    $context = $this->reader->readerContext($comic, $chapter);
    return [
      '#theme' => 'xedc_comic_reader',
      '#context' => $context,
      '#attached' => [
        'library' => ['xedc_comic/reader'],
        'drupalSettings' => [
          'xedcComic' => [
            'comicId' => (int) $comic->id(),
            'chapterId' => (int) $chapter->id(),
            'apiPages' => '/comic/' . $comic->id() . '/' . $chapter->id() . '/pages',
            'progressApi' => '/api/comic/reading-progress',
            'modeKey' => 'xedcComicMode',
            'progressKey' => 'xedcComicProgress:' . $comic->id(),
            'saveInterval' => (int) (\Drupal::config('xedc_comic.settings')->get('reading.progress_save_interval') ?: 3),
            'csrfToken' => \Drupal::csrfToken()->get('xedc_comic_progress'),
            'comicTitle' => $context['comic']['title'],
            'chapterTitle' => $context['chapter']['title'],
            'chapterIndex' => $context['chapter']['index'],
            'totalChapters' => $context['chapter']['total_chapters'],
            'prevUrl' => $context['nav']['prev_url'] ?? '',
            'nextUrl' => $context['nav']['next_url'] ?? '',
            'detailUrl' => $context['comic']['url'],
            'comicCover' => $context['comic']['cover'] ?? '',
            'chapters' => $context['chapters'] ?? [],
            'nextChapter' => $context['next_chapter'],
            'prevChapter' => $context['prev_chapter'],
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $chapter->id(), 'comic_page_list'],
      ],
    ];
  }

}