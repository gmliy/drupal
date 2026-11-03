<?php

namespace Drupal\xedc_member\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\xedc_core\Video\DurationFormatter;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the public member profile from published works only.
 *
 * Counts and lists are anonymous-visible, published, and in a published
 * moderation state. Member-center data is never read here.
 */
class PublicProfileBuilder implements TrustedCallbackInterface {

  use StringTranslationTrait;

  public const PAGE_SIZE = 12;

  /**
   * Public work bundles, in navigation order.
   */
  protected const TYPES = ['photo', 'video', 'article'];

  /**
   * Cover media fields already used by the public content pages.
   */
  protected const COVER_FIELDS = [
    'photo' => 'field_cover_image',
    'video' => 'field_video_cover',
    'article' => 'field_cover',
  ];

  /**
   * Existing image styles, small then large.
   */
  protected const COVER_STYLES = [
    'photo' => ['photo_card_640', 'max_1300x1300'],
    'video' => ['video_cover', 'max_1300x1300'],
    'article' => ['xa_cover', 'xa_hero'],
  ];

  /**
   * Published workflow state IDs for this request.
   *
   * @var string[]
   */
  protected array $publishedStates = [];

  /**
   * Workflow cache tags collected with the published states.
   *
   * @var string[]
   */
  protected array $workflowTags = [];

  /**
   * Constructs a PublicProfileBuilder.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected MemberAliasManager $aliasManager,
    protected DateFormatterInterface $dateFormatter,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected DurationFormatter $durationFormatter,
    protected RequestStack $requestStack,
    protected EntityRepositoryInterface $entityRepository,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['ownerLink'];
  }

  /**
   * Builds the public profile render array.
   */
  public function build(UserInterface $account, string $requested_slug): array {
    $uid = (int) $account->id();
    $slug = $this->aliasManager->getSlug($uid);
    if ($slug === '') {
      $slug = $requested_slug;
    }
    $request = $this->requestStack->getCurrentRequest();
    $type = $this->selectedType($request ? $request->query->get('type') : NULL);
    $sort = $this->selectedSort($request ? $request->query->get('sort') : NULL);
    $page = $this->selectedPage($request ? $request->query->get('page') : 0);

    $counts = $this->counts($uid);
    $total_public = array_sum($counts);
    $list_total = $type === 'all' ? $total_public : ($counts[$type] ?? 0);
    $ids = $this->workIds($uid, $type, $sort, $page);
    $cards = $this->cards($ids, $uid);
    $cache_tags = $this->cardTags($cards);
    foreach ($cards as &$card) {
      unset($card['tags']);
    }
    unset($card);
    $avatar = $this->avatar($account);

    $offer = $total_public === 0;
    $share = Url::fromRoute('xedc_member.public_profile', ['slug' => $slug], ['absolute' => TRUE])->toString();
    $name = $this->publicName($account);

    $build = [
      '#theme' => 'xedc_public_profile',
      '#name' => $name,
      '#bio' => $this->bio($account),
      '#joined' => $this->dateFormatter->format($account->getCreatedTime(), 'custom', 'Y年n月j日'),
      '#avatar' => $avatar['src'],
      '#stats' => $this->stats($slug, $sort, $counts),
      '#filters' => $this->filters($slug, $type, $sort, $counts, $total_public),
      '#sorts' => $this->sorts($slug, $type, $sort),
      '#cards' => $cards,
      '#pager' => $this->pager($slug, $type, $sort, $page, $list_total),
      '#empty' => $this->emptyMessage($type, $list_total, $page),
      '#share_url' => $share,
      '#offer_submission' => $offer,
      '#owner_link' => $offer ? [
        '#lazy_builder' => ['xedc_member.public_profile:ownerLink', [(string) $uid]],
        '#create_placeholder' => TRUE,
      ] : [],
      '#attached' => [
        'library' => ['xedc/public_profile'],
      ],
      '#cache' => [
        'keys' => [
          'xedc_public_profile',
          (string) $uid,
          $type,
          $sort,
          (string) $page,
        ],
        'tags' => Cache::mergeTags($account->getCacheTags(), $avatar['tags'], $this->workflowTags, $cache_tags, [
          'node_list',
          'node_list:photo',
          'node_list:video',
          'node_list:article',
          'xedc_member:aliases',
        ]),
        'contexts' => [
          'url.path',
          'url.query_args:type',
          'url.query_args:sort',
          'url.query_args:page',
          'languages:language_interface',
          'languages:language_content',
          'timezone',
        ],
        'max-age' => Cache::PERMANENT,
      ],
    ];
    return $build;
  }

  /**
   * Submission link shown only to the member who owns an empty profile.
   */
  public function ownerLink(string $uid): array {
    $uid = (int) $uid;
    $build = [
      '#cache' => [
        'keys' => ['xedc_public_profile_owner', (string) $uid],
        'contexts' => ['user'],
        'tags' => ['user:' . $uid, 'xedc_member:aliases'],
      ],
    ];
    if ($uid <= 0 || (int) $this->currentUser->id() !== $uid) {
      return $build;
    }
    $build['link'] = [
      '#type' => 'link',
      '#title' => $this->t('去投稿'),
      '#url' => Url::fromRoute('xedc_member.submissions'),
      '#attributes' => ['class' => ['xp-empty__action']],
    ];
    return $build;
  }

  /**
   * Public counts for the three work types.
   *
   * @return array<string, int>
   */
  protected function counts(int $uid): array {
    $counts = ['photo' => 0, 'video' => 0, 'article' => 0];
    $query = $this->baseSelect($uid, self::TYPES);
    $query->addField('n', 'type');
    $query->addExpression('COUNT(n.nid)', 'total');
    $query->groupBy('n.type');
    foreach ($query->execute() as $row) {
      $bundle = (string) $row->type;
      if (isset($counts[$bundle])) {
        $counts[$bundle] = (int) $row->total;
      }
    }
    return $counts;
  }

  /**
   * Page of public node IDs in the requested order.
   *
   * @return int[]
   */
  protected function workIds(int $uid, string $type, string $sort, int $page): array {
    $bundles = $type === 'all' ? self::TYPES : [$type];
    $query = $this->baseSelect($uid, $bundles);
    $query->leftJoin('node__field_published_at', 'pub', 'pub.entity_id = n.nid AND pub.deleted = 0 AND pub.delta = 0 AND pub.langcode = n.langcode');
    $query->addField('n', 'nid');
    $query->addExpression("CASE WHEN n.type = :sort_article THEN COALESCE(TIMESTAMPDIFF(SECOND, :sort_epoch, REPLACE(LEFT(pub.field_published_at_value, 19), 'T', ' ')), n.created) ELSE n.created END", 'sort_time', [
      ':sort_article' => 'article',
      ':sort_epoch' => '1970-01-01 00:00:00',
    ]);
    $direction = $sort === 'oldest' ? 'ASC' : 'DESC';
    $query->orderBy('sort_time', $direction);
    $query->orderBy('n.nid', $direction);
    $query->range($page * self::PAGE_SIZE, self::PAGE_SIZE);
    $ids = [];
    foreach ($query->execute() as $row) {
      $ids[] = (int) $row->nid;
    }
    return array_values(array_unique($ids));
  }

  /**
   * Shared public-work query.
   *
   * Status alone is not enough: the default revision must also be in a
   * published moderation state, and node grants are applied for the
   * anonymous account.
   *
   * @param string[] $bundles
   */
  protected function baseSelect(int $uid, array $bundles): SelectInterface {
    $states = $this->publishedStates();
    $query = $this->database->select('node_field_data', 'n');
    $query->condition('n.uid', $uid);
    $query->condition('n.status', 1);
    $query->condition('n.default_langcode', 1);
    $query->condition('n.type', $bundles, 'IN');
    $query->innerJoin('content_moderation_state_field_data', 'cms', 'cms.content_entity_type_id = :cms_entity AND cms.content_entity_id = n.nid AND cms.content_entity_revision_id = n.vid', [
      ':cms_entity' => 'node',
    ]);
    $query->condition('cms.moderation_state', $states, 'IN');
    $query->addTag('node_access');
    $query->addMetaData('account', new AnonymousUserSession());
    $query->addMetaData('op', 'view');
    return $query;
  }

  /**
   * Published moderation states from the configured workflows.
   *
   * @return string[]
   */
  protected function publishedStates(): array {
    if ($this->publishedStates) {
      return $this->publishedStates;
    }
    $states = [];
    $storage = $this->entityTypeManager->getStorage('workflow');
    $workflows = $storage->loadByProperties(['type' => 'content_moderation']);
    foreach ($workflows as $workflow) {
      $this->workflowTags = Cache::mergeTags($this->workflowTags, $workflow->getCacheTags());
      foreach ($workflow->getTypePlugin()->getStates() as $id => $state) {
        if ($state->isPublishedState()) {
          $states[$id] = $id;
        }
      }
    }
    if (!$states) {
      $states['published'] = 'published';
    }
    $this->publishedStates = array_values($states);
    return $this->publishedStates;
  }

  /**
   * Cards for one page, loaded in a batch.
   *
   * @param int[] $ids
   *
   * @return array<int, array<string, mixed>>
   */
  protected function cards(array $ids, int $uid): array {
    if (!$ids) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $loaded = $storage->loadMultiple($ids);
    $nodes = [];
    foreach ($ids as $id) {
      $node = $loaded[$id] ?? NULL;
      if ($node instanceof NodeInterface) {
        $translated = $this->entityRepository->getTranslationFromContext($node);
        $nodes[$id] = $translated instanceof NodeInterface ? $translated : $node;
      }
    }
    [$media_map, $file_map] = $this->preloadCovers($nodes);
    $anonymous = new AnonymousUserSession();
    $states = $this->publishedStates();
    $cards = [];
    foreach ($ids as $id) {
      $node = $nodes[$id] ?? NULL;
      if (!$node instanceof NodeInterface || !$this->isPublicWork($node, $uid, $states, $anonymous)) {
        continue;
      }
      $cover = $this->coverFor($node, $media_map, $file_map);
      $cards[] = [
        'nid' => (int) $node->id(),
        'type' => $node->bundle(),
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'cover' => $cover['src'],
        'srcset' => $cover['srcset'],
        'width' => $cover['width'],
        'height' => $cover['height'],
        'date' => $this->dateFormatter->format($this->publishedTimestamp($node), 'custom', 'Y-m-d'),
        'summary' => $node->bundle() === 'article' ? $this->summary($node) : '',
        'duration' => $node->bundle() === 'video' ? $this->duration($node) : '',
        'image_count' => $node->bundle() === 'photo' && $node->hasField('field_images') ? (int) $node->get('field_images')->count() : 0,
        'tags' => Cache::mergeTags($node->getCacheTags(), $cover['tags']),
      ];
    }
    return $cards;
  }

  /**
   * Whether this default revision may appear on a public profile.
   *
   * @param string[] $states
   */
  protected function isPublicWork(NodeInterface $node, int $uid, array $states, AccountInterface $anonymous): bool {
    if ((int) $node->getOwnerId() !== $uid || !in_array($node->bundle(), self::TYPES, TRUE)) {
      return FALSE;
    }
    if (!$node->isPublished() || !$node->access('view', $anonymous)) {
      return FALSE;
    }
    if (!$node->hasField('moderation_state') || $node->get('moderation_state')->isEmpty()) {
      return FALSE;
    }
    return in_array((string) $node->get('moderation_state')->value, $states, TRUE);
  }

  /**
   * Loads cover media and files for the page in two queries.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *
   * @return array{0: array<int, \Drupal\media\MediaInterface>, 1: array<int, \Drupal\file\FileInterface>}
   */
  protected function preloadCovers(array $nodes): array {
    $media_ids = [];
    foreach ($nodes as $node) {
      $field = self::COVER_FIELDS[$node->bundle()] ?? '';
      if ($field !== '' && $node->hasField($field) && !$node->get($field)->isEmpty()) {
        $media_ids[] = (int) $node->get($field)->target_id;
      }
    }
    $media_ids = array_values(array_unique(array_filter($media_ids)));
    $medias = $media_ids ? $this->entityTypeManager->getStorage('media')->loadMultiple($media_ids) : [];
    $file_ids = [];
    foreach ($medias as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }
      $source = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
      if ($source !== '' && $media->hasField($source) && !$media->get($source)->isEmpty()) {
        $file_ids[] = (int) $media->get($source)->target_id;
      }
    }
    $file_ids = array_values(array_unique(array_filter($file_ids)));
    $files = $file_ids ? $this->entityTypeManager->getStorage('file')->loadMultiple($file_ids) : [];
    $media_map = [];
    foreach ($medias as $media) {
      if ($media instanceof MediaInterface) {
        $media_map[(int) $media->id()] = $media;
      }
    }
    $file_map = [];
    foreach ($files as $file) {
      if ($file instanceof FileInterface) {
        $file_map[(int) $file->id()] = $file;
      }
    }
    return [$media_map, $file_map];
  }

  /**
   * Responsive cover data for one node.
   *
   * @param array<int, \Drupal\media\MediaInterface> $media_map
   * @param array<int, \Drupal\file\FileInterface> $file_map
   *
   * @return array{src: string, srcset: string, width: int, height: int, tags: string[]}
   */
  protected function coverFor(NodeInterface $node, array $media_map, array $file_map): array {
    $empty = ['src' => '', 'srcset' => '', 'width' => 0, 'height' => 0, 'tags' => []];
    $field = self::COVER_FIELDS[$node->bundle()] ?? '';
    if ($field === '' || !$node->hasField($field) || $node->get($field)->isEmpty()) {
      return $empty;
    }
    $media = $media_map[(int) $node->get($field)->target_id] ?? NULL;
    if (!$media instanceof MediaInterface) {
      return $empty;
    }
    $source = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    if ($source === '' || !$media->hasField($source) || $media->get($source)->isEmpty()) {
      return $empty;
    }
    $item = $media->get($source)->first();
    $file = $file_map[(int) $media->get($source)->target_id] ?? NULL;
    if (!$item || !$file instanceof FileInterface) {
      return $empty;
    }
    $uri = $file->getFileUri();
    if (!is_string($uri) || $uri === '') {
      return $empty;
    }
    $styles = self::COVER_STYLES[$node->bundle()] ?? ['max_650x650', 'max_1300x1300'];
    $small = $this->styleUrl($styles[0] ?? '', $uri);
    $large = $this->styleUrl($styles[1] ?? '', $uri);
    $src = $small !== '' ? $small : $this->fileUrlGenerator->generateString($uri);
    $srcset = '';
    if ($large !== '' && $large !== $src) {
      $srcset = $src . ' 1x, ' . $large . ' 2x';
    }
    $tags = Cache::mergeTags($media->getCacheTags(), $file->getCacheTags());
    foreach ($styles as $style_id) {
      if ($style_id !== '') {
        $tags[] = 'config:image.style.' . $style_id;
      }
    }
    return [
      'src' => $src,
      'srcset' => $srcset,
      'width' => (int) ($item->width ?? 0),
      'height' => (int) ($item->height ?? 0),
      'tags' => $tags,
    ];
  }

  /**
   * Builds a relative image-style URL, or an empty string when it cannot.
   */
  protected function styleUrl(string $style_id, string $uri): string {
    if ($style_id === '') {
      return '';
    }
    $style = $this->entityTypeManager->getStorage('image_style')->load($style_id);
    if (!$style instanceof ImageStyleInterface) {
      return '';
    }
    try {
      return $this->fileUrlGenerator->transformRelative($style->buildUrl($uri));
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Publish instant. Articles prefer field_published_at.
   */
  protected function publishedTimestamp(NodeInterface $node): int {
    $created = (int) $node->getCreatedTime();
    if ($node->bundle() !== 'article' || !$node->hasField('field_published_at') || $node->get('field_published_at')->isEmpty()) {
      return $created;
    }
    $date = $node->get('field_published_at')->date;
    if ($date instanceof DrupalDateTime) {
      $stamp = $date->getTimestamp();
      if ($stamp > 0) {
        return $stamp;
      }
    }
    return $created;
  }

  /**
   * Short public excerpt from the article summary field.
   */
  protected function summary(NodeInterface $node): string {
    if (!$node->hasField('field_summary') || $node->get('field_summary')->isEmpty()) {
      return '';
    }
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $node->get('field_summary')->value)) ?? '');
    if ($text === '') {
      return '';
    }
    if (mb_strlen($text) > 72) {
      $text = rtrim(mb_substr($text, 0, 72)) . '…';
    }
    return $text;
  }

  /**
   * Video duration from field_duration seconds.
   */
  protected function duration(NodeInterface $node): string {
    if (!$node->hasField('field_duration') || $node->get('field_duration')->isEmpty()) {
      return '';
    }
    return $this->durationFormatter->format((int) $node->get('field_duration')->value);
  }

  /**
   * @param array<int, array<string, mixed>> $cards
   *
   * @return string[]
   */
  protected function cardTags(array $cards): array {
    $tags = [];
    foreach ($cards as $card) {
      $tags = Cache::mergeTags($tags, $card['tags'] ?? []);
      unset($card);
    }
    return $tags;
  }

  /**
   * Public display name. Login name and email are not used when a nickname exists.
   */
  protected function publicName(UserInterface $account): string {
    if ($account->hasField('field_xedc_nickname') && !$account->get('field_xedc_nickname')->isEmpty()) {
      $nickname = trim((string) $account->get('field_xedc_nickname')->value);
      if ($nickname !== '') {
        return $nickname;
      }
    }
    return (string) $this->t('会员');
  }

  /**
   * Plain-text biography.
   */
  protected function bio(UserInterface $account): string {
    if (!$account->hasField('field_xedc_bio') || $account->get('field_xedc_bio')->isEmpty()) {
      return '';
    }
    return trim((string) $account->get('field_xedc_bio')->value);
  }

  /**
   * Relative avatar URL from the existing user picture, or an empty string.
   *
   * @return array{src: string, tags: string[]}
   */
  protected function avatar(UserInterface $account): array {
    $empty = ['src' => '', 'tags' => []];
    if (!$account->hasField('user_picture') || $account->get('user_picture')->isEmpty()) {
      return $empty;
    }
    $file = $account->get('user_picture')->entity;
    if (!$file instanceof FileInterface) {
      return $empty;
    }
    $uri = $file->getFileUri();
    if (!is_string($uri) || $uri === '') {
      return $empty;
    }
    $src = $this->styleUrl('max_325x325', $uri);
    if ($src === '') {
      $src = $this->fileUrlGenerator->generateString($uri);
    }
    return [
      'src' => $src,
      'tags' => $file->getCacheTags(),
    ];
  }

  /**
   * @param array<string, int> $counts
   *
   * @return array<int, array<string, mixed>>
   */
  protected function stats(string $slug, string $sort, array $counts): array {
    $items = [];
    foreach (self::TYPES as $type) {
      $items[] = [
        'label' => $this->typeLabel($type),
        'count' => $counts[$type] ?? 0,
        'url' => $this->profileUrl($slug, $type, $sort, 0),
      ];
    }
    return $items;
  }

  /**
   * @param array<string, int> $counts
   *
   * @return array<int, array<string, mixed>>
   */
  protected function filters(string $slug, string $active, string $sort, array $counts, int $total): array {
    $items = [[
      'key' => 'all',
      'label' => $this->t('全部作品'),
      'count' => $total,
      'url' => $this->profileUrl($slug, 'all', $sort, 0),
      'active' => $active === 'all',
    ]];
    foreach (self::TYPES as $type) {
      $items[] = [
        'key' => $type,
        'label' => $this->typeLabel($type),
        'count' => $counts[$type] ?? 0,
        'url' => $this->profileUrl($slug, $type, $sort, 0),
        'active' => $active === $type,
      ];
    }
    return $items;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  protected function sorts(string $slug, string $type, string $active): array {
    return [
      [
        'key' => 'newest',
        'label' => $this->t('最新发布'),
        'url' => $this->profileUrl($slug, $type, 'newest', 0),
        'active' => $active !== 'oldest',
      ],
      [
        'key' => 'oldest',
        'label' => $this->t('最早发布'),
        'url' => $this->profileUrl($slug, $type, 'oldest', 0),
        'active' => $active === 'oldest',
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  protected function pager(string $slug, string $type, string $sort, int $page, int $total): array {
    if ($total <= self::PAGE_SIZE) {
      return [];
    }
    $last = (int) ceil($total / self::PAGE_SIZE);
    $current = min($page + 1, $last + 1);
    $window = array_unique(array_filter(
      [1, $current - 1, $current, $current + 1, $last],
      static fn(int $n): bool => $n >= 1 && $n <= $last,
    ));
    sort($window);
    $items = [];
    $previous = 0;
    foreach ($window as $number) {
      if ($previous && $number - $previous > 1) {
        $items[] = ['gap' => TRUE];
      }
      $items[] = [
        'n' => $number,
        'url' => $this->profileUrl($slug, $type, $sort, $number - 1),
        'active' => $number === $current && $current <= $last,
        'gap' => FALSE,
      ];
      $previous = $number;
    }
    return [
      'current' => min($current, $last),
      'last' => $last,
      'prev' => $page > 0 ? $this->profileUrl($slug, $type, $sort, min($page, $last) - 1) : '',
      'next' => ($page + 1) < $last ? $this->profileUrl($slug, $type, $sort, $page + 1) : '',
      'items' => $items,
    ];
  }

  /**
   * Profile URL that keeps the slug, filter and sort, and resets paging when asked.
   */
  protected function profileUrl(string $slug, string $type, string $sort, int $page): string {
    $query = [];
    if ($type !== 'all') {
      $query['type'] = $type;
    }
    if ($sort === 'oldest') {
      $query['sort'] = 'oldest';
    }
    if ($page > 0) {
      $query['page'] = $page;
    }
    return Url::fromRoute('xedc_member.public_profile', ['slug' => $slug], ['query' => $query])->toString();
  }

  /**
   * Empty-state copy.
   */
  protected function emptyMessage(string $type, int $total, int $page): string {
    if ($total > 0 && $page * self::PAGE_SIZE >= $total) {
      return (string) $this->t('这一页没有作品');
    }
    return match ($type) {
      'photo' => (string) $this->t('这里还没有公开图片'),
      'video' => (string) $this->t('这里还没有公开视频'),
      'article' => (string) $this->t('这里还没有公开文章'),
      default => (string) $this->t('这里还没有公开作品'),
    };
  }

  /**
   * Whitelisted type, or all.
   */
  protected function selectedType(mixed $raw): string {
    return is_string($raw) && in_array($raw, self::TYPES, TRUE) ? $raw : 'all';
  }

  /**
   * Whitelisted sort. Newest is the default.
   */
  protected function selectedSort(mixed $raw): string {
    return $raw === 'oldest' ? 'oldest' : 'newest';
  }

  /**
   * Zero-based page, capped so a bad query cannot scan the whole table.
   */
  protected function selectedPage(mixed $raw): int {
    if (is_array($raw)) {
      $raw = reset($raw);
    }
    if (is_string($raw) && str_contains($raw, ',')) {
      $raw = explode(',', $raw)[0];
    }
    $page = (int) $raw;
    if ($page < 0) {
      return 0;
    }
    return min($page, 10000);
  }

  /**
   * Public type label.
   */
  protected function typeLabel(string $type): string {
    return match ($type) {
      'photo' => (string) $this->t('图片'),
      'video' => (string) $this->t('视频'),
      'article' => (string) $this->t('文章'),
      default => (string) $this->t('作品'),
    };
  }

}
