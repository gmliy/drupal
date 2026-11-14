<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\xedc_comic\Service\ComicAccessManager;
use Drupal\xedc_comic\Service\ComicFavoriteStore;
use Drupal\xedc_comic\Service\ComicManager;
use Drupal\xedc_comic\Service\ComicReadingStore;
use Drupal\xedc_comic\Service\ComicSeoBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public comic list, taxonomy and detail pages.
 */
final class ComicController extends ControllerBase {

  private const PER_PAGE = 24;

  public function __construct(
    private readonly ComicManager $comics,
    private readonly ComicAccessManager $access,
    private readonly ComicReadingStore $reading,
    private readonly ComicFavoriteStore $favorites,
    private readonly ComicSeoBuilder $seo,
    private readonly PagerManagerInterface $pager,
    private readonly DateFormatterInterface $dates,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_comic.manager'),
      $container->get('xedc_comic.access'),
      $container->get('xedc_comic.reading'),
      $container->get('xedc_comic.favorites'),
      $container->get('xedc_comic.seo'),
      $container->get('pager.manager'),
      $container->get('date.formatter'),
    );
  }

  public function home(Request $request): array {
    return $this->listingPage($request, [
      'mode' => 'home',
      'title' => (string) $this->t('漫画'),
      'intro' => (string) $this->t('浏览连载漫画，按分类与标签发现作品。'),
      'route' => 'xedc_comic.home',
    ]);
  }

  public function category(TermInterface $taxonomy_term): array {
    if ($taxonomy_term->bundle() !== 'comic_category') {
      throw new NotFoundHttpException();
    }
    $desc = $taxonomy_term->hasField('description') ? strip_tags((string) $taxonomy_term->get('description')->value) : '';
    $build = $this->listingPage(\Drupal::request(), [
      'mode' => 'category',
      'title' => $taxonomy_term->label(),
      'intro' => $desc,
      'route' => 'xedc_comic.category',
      'route_params' => ['taxonomy_term' => $taxonomy_term->id()],
      'term' => $taxonomy_term,
    ], ['category' => (int) $taxonomy_term->id()]);
    $build += $this->seo->termAttachments($taxonomy_term, 'category');
    return $build;
  }

  public function tag(TermInterface $taxonomy_term): array {
    if ($taxonomy_term->bundle() !== 'tags') {
      throw new NotFoundHttpException();
    }
    $desc = $taxonomy_term->hasField('description') ? strip_tags((string) $taxonomy_term->get('description')->value) : '';
    $build = $this->listingPage(\Drupal::request(), [
      'mode' => 'tag',
      'title' => $taxonomy_term->label(),
      'intro' => $desc !== '' ? $desc : (string) $this->t('标签「@label」下的漫画', ['@label' => $taxonomy_term->label()]),
      'route' => 'xedc_comic.tag',
      'route_params' => ['taxonomy_term' => $taxonomy_term->id()],
      'term' => $taxonomy_term,
    ], ['tag' => (int) $taxonomy_term->id()]);
    $build += $this->seo->termAttachments($taxonomy_term, 'tag');
    return $build;
  }

  /**
   * @param array<string, mixed> $context
   *
   * @return array<string, mixed>
   */
  /**
   * @param array<string, mixed> $fixedFilters
   */
  private function listingPage(Request $request, array $context, array $fixedFilters = []): array {
    $filters = array_merge($this->filtersFromRequest($request), $fixedFilters);
    $total = $this->comics->countPublicComics($filters);
    $this->pager->createPager($total, self::PER_PAGE);
    $offset = $this->pager->findPage() * self::PER_PAGE;
    $comics = $this->comics->listComics($filters, self::PER_PAGE, $offset);
    $items = $this->comics->comicCardsBatch($comics);
    $categories = $this->categoryOptions();
    $tags = $this->popularTags();
    $recent = [];
    if ($this->currentUser()->isAuthenticated()) {
      foreach ($this->reading->recentForUser((int) $this->currentUser()->id(), 8) as $row) {
        $node = $this->entityTypeManager()->getStorage('node')->load((int) $row['comic_id']);
        if ($node instanceof NodeInterface) {
          $recent[] = $this->comics->comicCard($node);
        }
      }
    }
    $route = (string) $context['route'];
    $routeParams = $context['route_params'] ?? [];
    $build = [
      '#theme' => 'xedc_comic_list',
      '#title' => (string) $context['title'],
      '#intro' => (string) ($context['intro'] ?? ''),
      '#total' => $total,
      '#items' => $items,
      '#recent' => $recent,
      '#filters' => $filters,
      '#categories' => $categories,
      '#tags' => $tags,
      '#base_route' => $route,
      '#base_route_params' => $routeParams,
      '#empty_message' => (string) $this->t('暂无符合条件的漫画。'),
      '#pager' => ['#type' => 'pager'],
      '#cache' => [
        'contexts' => ['url.query_args', 'user'],
        'tags' => ['node_list:comic', 'taxonomy_term_list'],
      ],
      '#member_links' => $this->currentUser()->isAuthenticated() && \Drupal::moduleHandler()->moduleExists('xedc_member')
        ? [
          ['label' => $this->t('漫画收藏'), 'url' => Url::fromRoute('xedc_comic.member_favorites')->toString()],
          ['label' => $this->t('阅读记录'), 'url' => Url::fromRoute('xedc_comic.member_history')->toString()],
        ]
        : [],
      '#attached' => ['library' => ['xedc_comic/list']],
    ];
    if ($context['mode'] === 'home') {
      $build += $this->seo->listAttachments();
    }
    return $build;
  }

  public function detail(NodeInterface $comic): array {
    if ($comic->bundle() !== 'comic') {
      throw new NotFoundHttpException();
    }
    if (!$this->access->canViewComic($comic)) {
      throw new AccessDeniedHttpException();
    }
    $chapters = [];
    foreach ($this->comics->listChapters((int) $comic->id(), 500) as $chapter) {
      $vip = $this->access->chapterIsVip($chapter) && !$this->access->chapterIsFree($chapter);
      $canRead = $this->access->canViewChapter($chapter);
      $chapters[] = [
        'id' => (int) $chapter->id(),
        'title' => $chapter->label(),
        'number' => $chapter->hasField('field_comic_number') ? (float) $chapter->get('field_comic_number')->value : 0,
        'vip' => $vip,
        'free' => $this->access->chapterIsFree($chapter),
        'locked' => $vip && !$canRead,
        'url' => Url::fromRoute('xedc_comic.reader', ['comic' => $comic->id(), 'chapter' => $chapter->id()])->toString(),
        'pages' => $chapter->hasField('field_comic_page_count') ? (int) $chapter->get('field_comic_page_count')->value : 0,
        'published' => $chapter->hasField('field_comic_publish_time') && !$chapter->get('field_comic_publish_time')->isEmpty()
          ? $this->dates->format((int) $chapter->get('field_comic_publish_time')->value, 'custom', 'Y-m-d', 'Asia/Shanghai')
          : $this->dates->format((int) $chapter->getCreatedTime(), 'custom', 'Y-m-d', 'Asia/Shanghai'),
      ];
    }
    usort($chapters, static fn(array $a, array $b) => $a['number'] <=> $b['number']);
    $start = '';
    foreach ($chapters as $chapter) {
      if (!$chapter['locked']) {
        $start = $chapter['url'];
        break;
      }
    }
    $card = $this->comics->comicCardsBatch([$comic])[0] ?? $this->comics->comicCard($comic);
    $description = '';
    $description_plain = '';
    if ($comic->hasField('field_comic_description') && !$comic->get('field_comic_description')->isEmpty()) {
      $description = (string) $comic->get('field_comic_description')->value;
      $description_plain = trim(strip_tags($description));
    }
    $categories = [];
    if ($comic->hasField('field_comic_category') && !$comic->get('field_comic_category')->isEmpty()) {
      $term = $comic->get('field_comic_category')->entity;
      if ($term instanceof TermInterface) {
        $categories[] = [
          'label' => $term->label(),
          'url' => Url::fromRoute('xedc_comic.category', ['taxonomy_term' => $term->id()])->toString(),
        ];
      }
    }
    $tags = [];
    if ($comic->hasField('field_comic_tags')) {
      foreach ($comic->get('field_comic_tags') as $item) {
        $term = $item->entity;
        if ($term instanceof TermInterface && $term->bundle() === 'tags') {
          $tags[] = [
            'label' => $term->label(),
            'url' => Url::fromRoute('xedc_comic.tag', ['taxonomy_term' => $term->id()])->toString(),
          ];
        }
      }
    }
    $updated = $comic->hasField('field_comic_update_time') && !$comic->get('field_comic_update_time')->isEmpty()
      ? (int) $comic->get('field_comic_update_time')->value
      : (int) $comic->getChangedTime();
    $build = [
      '#theme' => 'xedc_comic_detail',
      '#comic' => $card,
      '#description' => $description,
      '#description_plain' => $description_plain,
      '#chapters' => $chapters,
      '#chapter_total' => count($chapters),
      '#page_total' => $this->comics->totalPagesForComic((int) $comic->id()),
      '#updated_label' => $this->dates->format($updated, 'custom', 'Y-m-d', 'Asia/Shanghai'),
      '#categories' => $categories,
      '#tags' => $tags,
      '#start_url' => $start,
      '#favorite' => $this->favorites->isFavorited((int) $comic->id()),
      '#share_url' => Url::fromRoute('xedc_comic.detail', ['comic' => $comic->id()], ['absolute' => TRUE])->toString(),
      '#related' => $this->comics->relatedComics($comic, 8),
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $comic->id(), 'node_list:comic_chapter'],
      ],
    ];
    $build += $this->seo->attachments($comic);
    $build['#attached']['library'][] = 'xedc_comic/detail';
    $build['#attached']['drupalSettings']['xedcComicDetail'] = [
      'csrfFavorite' => \Drupal::csrfToken()->get('xedc_comic_favorite'),
    ];
    if ($this->currentUser()->isAuthenticated()) {
      $build['#attached']['drupalSettings']['xedcComicMemberUrls'] = [
        'favorites' => Url::fromRoute('xedc_comic.member_favorites')->toString(),
        'history' => Url::fromRoute('xedc_comic.member_history')->toString(),
      ];
    }
    return $build;
  }

  /**
   * @return array<string, mixed>
   */
  private function filtersFromRequest(Request $request): array {
    $sort = (string) $request->query->get('sort', 'update');
    $allowedSort = ['update', 'created', 'views', 'favorites', 'title'];
    if (!in_array($sort, $allowedSort, TRUE)) {
      $sort = 'update';
    }
    $access = (string) $request->query->get('access', '');
    if (!in_array($access, ['', 'vip', 'free'], TRUE)) {
      $access = '';
    }
    $status = (string) $request->query->get('status', '');
    if (!in_array($status, ['', 'ongoing', 'completed', 'paused'], TRUE)) {
      $status = '';
    }
    return [
      'q' => trim((string) $request->query->get('q', '')),
      'category' => (int) $request->query->get('category', 0),
      'tag' => (int) $request->query->get('tag', 0),
      'status' => $status,
      'access' => $access,
      'sort' => $sort,
    ];
  }

  /**
   * @return list<array{id: int, label: string, url: string}>
   */
  private function categoryOptions(): array {
    $options = [];
    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadTree('comic_category', 0, NULL, TRUE);
    foreach ($terms as $term) {
      if ($term instanceof TermInterface) {
        $options[] = [
          'id' => (int) $term->id(),
          'label' => $term->label(),
          'url' => Url::fromRoute('xedc_comic.category', ['taxonomy_term' => $term->id()])->toString(),
        ];
      }
    }
    return $options;
  }

  /**
   * @return list<array{id: int, label: string, url: string}>
   */
  private function popularTags(): array {
    $options = [];
    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadTree('tags', 0, NULL, TRUE);
    foreach (array_slice($terms, 0, 24) as $term) {
      if ($term instanceof TermInterface) {
        $options[] = [
          'id' => (int) $term->id(),
          'label' => $term->label(),
          'url' => Url::fromRoute('xedc_comic.tag', ['taxonomy_term' => $term->id()])->toString(),
        ];
      }
    }
    return $options;
  }

}