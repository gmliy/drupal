<?php

namespace Drupal\xedc_member\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\comment\CommentInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\xedc_core\Engagement\DownloadRecorder;
use Drupal\xedc_core\Engagement\LikeManager;
use Drupal\xedc_core\Form\LikeRemoveForm;
use Drupal\xedc_member\Form\FavoriteToggleForm;
use Drupal\xedc_member\Form\HistoryDeleteForm;
use Drupal\xedc_member\Form\HistorySettingsForm;
use Drupal\xedc_member\Form\ProfileForm;
use Drupal\xedc_member\Service\FavoriteManager;
use Drupal\xedc_member\Service\HistoryManager;
use Drupal\xedc_member\Service\MemberAliasManager;
use Drupal\xedc_member\Service\PublicProfileBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the XEDC member center.
 */
class MemberController extends ControllerBase {

  /**
   * Constructs a MemberController.
   */
  public function __construct(
    protected FavoriteManager $favoriteManager,
    protected HistoryManager $historyManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected DateFormatterInterface $dateFormatter,
    protected RequestStack $requestStack,
    protected MemberAliasManager $aliasManager,
    protected PublicProfileBuilder $publicProfiles,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('xedc_member.favorite_manager'),
      $container->get('xedc_member.history_manager'),
      $container->get('file_url_generator'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
      $container->get('xedc_member.alias_manager'),
      $container->get('xedc_member.public_profile'),
    );
  }

  /**
   * Member center overview.
   */
  public function overview() {
    $account = $this->loadAccount();
    $uid = (int) $account->id();

    $recent_rows = $this->favoriteManager->getRecentRows($uid, 4);
    $recent_nodes = $this->loadNodesInOrder(array_map(static fn($row) => (int) $row->entity_id, $recent_rows));
    $recent_favorites = [];
    foreach ($recent_rows as $row) {
      $node = $recent_nodes[(int) $row->entity_id] ?? NULL;
      if ($node instanceof NodeInterface && $node->access('view')) {
        $recent_favorites[] = $this->favoriteCard($node);
      }
    }

    $content = [
      '#theme' => 'xedc_member_overview',
      '#account' => $this->accountData($account),
      '#stats' => [
        'favorites' => $this->favoriteManager->countVisible($uid),
        'likes' => \Drupal::service('xedc_core.like_manager')->countVisible($uid),
        'downloads' => \Drupal::service('xedc_core.download_recorder')->countForUser($uid),
        'history' => $this->historyManager->countVisible($uid),
        'comments' => $this->commentCount($uid),
        'submissions' => $this->submissionCount($uid),
      ],
      '#recent_favorites' => $recent_favorites,
      '#urls' => $this->memberUrls(),
    ];

    return $this->shell('overview', $content);
  }

  /**
   * Member favorites list.
   */
  public function favorites() {
    $account = $this->loadAccount();
    $uid = (int) $account->id();
    $request = $this->requestStack->getCurrentRequest();
    $type = $request ? $request->query->get('type') : NULL;
    $type = is_string($type) && in_array($type, FavoriteManager::ALLOWED_BUNDLES, TRUE) ? $type : NULL;

    $favorite_rows = $this->favoriteManager->getFavoriteRows($uid, $type);
    $favorite_nodes = $this->loadNodesInOrder(array_map(static fn($row) => (int) $row->entity_id, $favorite_rows));
    $cards = [];
    foreach ($favorite_rows as $row) {
      $node = $favorite_nodes[(int) $row->entity_id] ?? NULL;
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      $remove_form = $this->formBuilder()->getForm(FavoriteToggleForm::class, (int) $node->id(), 'list');
      $cards[] = $this->favoriteCard($node, $remove_form);
    }

    $content = [
      '#theme' => 'xedc_member_favorites',
      '#cards' => $cards,
      '#filters' => $this->favoriteFilters($type),
      '#total' => $this->favoriteManager->countVisible($uid, $type),
      '#pager' => [
        '#type' => 'pager',
        '#element' => FavoriteManager::PAGER_ELEMENT,
        '#quantity' => 5,
      ],
      '#empty' => $type ? $this->t('这个分类还没有收藏。') : $this->t('还没有收藏。'),
    ];

    return $this->shell('favorites', $content);
  }

  /**
   * Likes for the current member.
   */
  public function likes() {
    $uid = (int) $this->currentUser()->id();
    $request = $this->requestStack->getCurrentRequest();
    $type = $request ? $request->query->get('type') : NULL;
    $type = is_string($type) && in_array($type, LikeManager::ALLOWED_BUNDLES, TRUE) ? $type : NULL;
    /** @var \Drupal\xedc_core\Engagement\LikeManager $likes */
    $likes = \Drupal::service('xedc_core.like_manager');
    $rows = $likes->getRows($uid, $type);
    $nodes = $this->loadNodesInOrder(array_map(static fn($row) => (int) $row->entity_id, $rows));
    $cards = [];
    foreach ($rows as $row) {
      $node = $nodes[(int) $row->entity_id] ?? NULL;
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      $card = $this->favoriteCard($node, $this->formBuilder()->getForm(LikeRemoveForm::class, (int) $node->id()));
      $when = (int) ($row->changed ?: $row->created);
      $card['date'] = $this->dateFormatter->format($when, 'custom', 'Y-m-d H:i');
      $card['meta'] = $this->typeLabel($node->bundle());
      $cards[] = $card;
    }
    $filters = [[
      'key' => 'all',
      'label' => $this->t('全部'),
      'url' => Url::fromRoute('xedc_member.likes')->toString(),
      'active' => $type === NULL,
    ]];
    foreach (LikeManager::ALLOWED_BUNDLES as $bundle) {
      $filters[] = [
        'key' => $bundle,
        'label' => $this->typeLabel($bundle),
        'url' => Url::fromRoute('xedc_member.likes', [], ['query' => ['type' => $bundle]])->toString(),
        'active' => $type === $bundle,
      ];
    }
    return $this->shell('likes', [
      '#theme' => 'xedc_member_likes',
      '#cards' => $cards,
      '#filters' => $filters,
      '#total' => $likes->countVisible($uid, $type),
      '#pager' => [
        '#type' => 'pager',
        '#element' => LikeManager::PAGER_ELEMENT,
        '#quantity' => 5,
      ],
      '#empty' => $type ? $this->t('这个分类还没有点赞。') : $this->t('还没有点赞。'),
    ]);
  }

  /**
   * Authorized download history. This is not proof the browser finished the file.
   */
  public function downloads() {
    $uid = (int) $this->currentUser()->id();
    /** @var \Drupal\xedc_core\Engagement\DownloadRecorder $recorder */
    $recorder = \Drupal::service('xedc_core.download_recorder');
    $labels = ['photo' => '图片', 'video' => '视频', 'article' => '文章'];
    $states = [
      'authorized' => $this->t('已授权'),
      'zip_pending' => $this->t('ZIP 生成中'),
      'zip_failed' => $this->t('ZIP 生成失败'),
      'failed' => $this->t('交付失败'),
    ];
    $rows = [];
    $nids = [];
    $history = $recorder->history($uid);
    foreach ($history as $row) {
      $nids[] = (int) $row->content_id;
    }
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple(array_unique($nids));
    foreach ($history as $row) {
      $node = $nodes[(int) $row->content_id] ?? NULL;
      $url = '';
      $title = $this->t('内容不可用或已下架');
      $cover = '';
      if ($node instanceof NodeInterface && $node->access('view')) {
        $title = $node->label();
        $url = $node->toUrl()->toString();
        $image = $this->coverImage($node);
        $cover = is_array($image) ? (string) ($image['src'] ?? '') : '';
      }
      $rows[] = [
        'content' => $title,
        'url' => $url,
        'cover' => $cover,
        'bundle' => $labels[(string) $row->bundle] ?? (string) $row->bundle,
        'title' => (string) $row->title,
        'extension' => strtoupper((string) $row->extension),
        'bytes' => \Drupal\xedc_core\Ops\OpsFormat::bytes((int) $row->bytes),
        'time' => $this->dateFormatter->format((int) $row->created, 'custom', 'Y-m-d H:i'),
        'status' => $states[(string) $row->status] ?? $this->t('已授权'),
        'status_id' => (string) $row->status,
      ];
    }
    return $this->shell('downloads', [
      '#theme' => 'xedc_member_downloads',
      '#rows' => $rows,
      '#total' => $recorder->countForUser($uid),
      '#pager' => [
        '#type' => 'pager',
        '#element' => DownloadRecorder::PAGER_ELEMENT,
        '#quantity' => 5,
      ],
    ]);
  }

  /**
   * Current membership level. Expired VIP is shown as a normal member.
   */
  public function membership() {
    $account = $this->loadAccount();
    $snapshot = \Drupal::service('xedc_core.membership')->snapshot($account);
    $format = static function (int $timestamp) {
      return $timestamp > 0 ? \Drupal::service('date.formatter')->format($timestamp, 'custom', 'Y-m-d H:i') : '—';
    };
    return $this->shell('membership', [
      '#theme' => 'xedc_member_membership',
      '#membership' => [
        'label' => $snapshot['label'],
        'active' => $snapshot['active'],
        'starts' => $format((int) $snapshot['starts']),
        'expires' => $format((int) $snapshot['expires']),
        'remaining' => (int) $snapshot['remaining'],
        'expiring_soon' => $snapshot['expiring_soon'],
        'expired' => $snapshot['expired'],
        'cancelled' => $snapshot['cancelled'],
        'benefits' => $snapshot['benefits'],
      ],
    ]);
  }

  /**
   * Member browsing history.
   */
  public function history() {
    $account = $this->loadAccount();
    $uid = (int) $account->id();
    $request = $this->requestStack->getCurrentRequest();
    $type = $request ? $request->query->get('type') : NULL;
    $type = is_string($type) && in_array($type, HistoryManager::ALLOWED_BUNDLES, TRUE) ? $type : NULL;

    $history_rows = $this->historyManager->getHistoryRows($uid, $type);
    $history_nodes = $this->loadNodesInOrder(array_map(static fn($row) => (int) $row->entity_id, $history_rows));
    $items = [];
    foreach ($history_rows as $row) {
      $node = $history_nodes[(int) $row->entity_id] ?? NULL;
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      $delete_form = $this->formBuilder()->getForm(HistoryDeleteForm::class, (int) $node->id());
      $items[] = $this->historyCard($node, $row, $delete_form);
    }

    $content = [
      '#theme' => 'xedc_member_history',
      '#items' => $items,
      '#filters' => $this->historyFilters($type),
      '#total' => $this->historyManager->countVisible($uid, $type),
      '#pager' => [
        '#type' => 'pager',
        '#element' => HistoryManager::PAGER_ELEMENT,
        '#quantity' => 5,
      ],
      '#enabled' => $this->historyManager->isEnabled($uid),
      '#urls' => $this->memberUrls(),
      '#empty' => $type ? $this->t('这个分类还没有浏览记录。') : $this->t('还没有浏览记录。打开内容后会自动记在这里。'),
    ];

    return $this->shell('history', $content);
  }

  /**
   * Comments written by the current member.
   */
  public function comments() {
    $account = $this->loadAccount();
    $uid = (int) $account->id();
    $storage = $this->entityTypeManager()->getStorage('comment');

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->sort('created', 'DESC')
      ->pager(20);
    $cids = $query->execute();
    $comments = $storage->loadMultiple($cids);

    $items = [];
    foreach ($comments as $comment) {
      if ($comment instanceof CommentInterface) {
        $items[] = $this->commentCard($comment);
      }
    }

    $count = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->count()
      ->execute();

    $content = [
      '#theme' => 'xedc_member_comments',
      '#items' => $items,
      '#total' => (int) $count,
      '#pager' => ['#type' => 'pager'],
      '#empty' => $this->t('还没有评论。'),
    ];

    return $this->shell('comments', $content);
  }

  /**
   * Submissions owned by the current member.
   */
  public function submissions() {
    $account = $this->loadAccount();
    $uid = (int) $account->id();
    $storage = $this->entityTypeManager()->getStorage('node');

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->condition('type', FavoriteManager::ALLOWED_BUNDLES, 'IN')
      ->sort('changed', 'DESC')
      ->pager(20);
    $nids = $query->execute();
    $nodes = $storage->loadMultiple($nids);

    $items = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $latest = $this->latestRevision($node);
      $items[] = $this->submissionCard($latest, $this->moderationState($latest));
    }

    $count = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->condition('type', FavoriteManager::ALLOWED_BUNDLES, 'IN')
      ->count()
      ->execute();

    $content = [
      '#theme' => 'xedc_member_submissions',
      '#items' => $items,
      '#total' => (int) $count,
      '#pager' => ['#type' => 'pager'],
      '#urls' => $this->memberUrls(),
      '#create_links' => $this->submissionCreateLinks(),
      '#empty' => $this->t('还没有投稿。'),
    ];

    return $this->shell('submissions', $content);
  }

  /**
   * Placeholder pages for later phases.
   */
  public function placeholder(string $section = 'comments') {
    $sections = [
      'comments' => [
        'heading' => $this->t('我的评论'),
        'description' => $this->t('这里会显示你发表的评论和审核状态。'),
        'note' => $this->t('评论管理将在后续阶段接入，目前先保留入口。'),
      ],
      'submissions' => [
        'heading' => $this->t('我的投稿'),
        'description' => $this->t('这里会显示你的投稿、草稿和审核状态。'),
        'note' => $this->t('投稿与审核将在后续阶段接入，目前先保留入口。'),
      ],
    ];

    if (!isset($sections[$section])) {
      throw new NotFoundHttpException();
    }

    $content = [
      '#theme' => 'xedc_member_placeholder',
    ] + $sections[$section];

    return $this->shell($section, $content);
  }

  /**
   * Legacy account settings URL. The page now lives under account security.
   */
  public function settings(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('xedc_member.security')->toString(), 302);
  }

  /**
   * Checks whether a vanity alias is free while the member is typing.
   */
  public function aliasCheck(): JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    $raw = $request ? (string) $request->query->get('q', '') : '';
    $result = $this->aliasManager->check($raw, (int) $this->currentUser()->id());
    $response = new JsonResponse([
      'ok' => $result['ok'],
      'status' => $result['status'],
      'message' => $result['message'],
    ]);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * Public member profile at /r/{slug}.
   *
   * This is not the member center. Email, favorites, downloads, history and
   * account forms are not included.
   */
  public function publicProfile(string $slug): array {
    $account = $this->loadPublicAccount($slug);
    $build = $this->publicProfiles->build($account, $slug);
    $build['#title'] = $this->publicName($account);
    return $build;
  }

  /**
   * Title for the public profile route.
   */
  public function publicProfileTitle(string $slug): string {
    try {
      return $this->publicName($this->loadPublicAccount($slug));
    }
    catch (NotFoundHttpException) {
      return (string) $this->t('会员主页');
    }
  }

  /**
   * Blocks a vanity URL that belongs to another member.
   */
  public function aliasDenied() {
    if ($this->currentUser()->isAnonymous()) {
      $request = $this->requestStack->getCurrentRequest();
      $destination = $request ? $request->getRequestUri() : '/';
      return new RedirectResponse(Url::fromRoute('user.login', [], [
        'query' => ['destination' => $destination],
      ])->toString());
    }
    throw new AccessDeniedHttpException('无权访问该会员中心。');
  }

  /**
   * Member profile page.
   */
  public function profile() {
    $account = $this->loadAccount();
    $content = [
      '#theme' => 'xedc_member_profile',
      '#account' => $this->accountData($account),
      '#avatar' => $this->avatar($account),
      '#form' => $this->formBuilder()->getForm(ProfileForm::class),
      '#urls' => $this->memberUrls(),
    ];

    return $this->shell('profile', $content);
  }

  /**
   * Account security page.
   */
  public function security() {
    $account = $this->loadAccount();
    $content = [
      '#theme' => 'xedc_member_security',
      '#account' => $this->accountData($account),
      '#avatar' => $this->avatar($account),
      '#form' => $this->entityFormBuilder()->getForm($account, 'default'),
      '#history_form' => $this->formBuilder()->getForm(HistorySettingsForm::class),
      '#urls' => $this->memberUrls(),
    ];

    return $this->shell('security', $content);
  }

  /**
   * Builds the shared member center shell.
   */
  /**
   * Loads nodes once, in the same order as the source IDs.
   *
   * @param int[] $ids
   *   Node IDs.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Nodes keyed by ID.
   */
  protected function loadNodesInOrder(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
      return [];
    }
    $loaded = $this->entityTypeManager()->getStorage('node')->loadMultiple($ids);
    $ordered = [];
    foreach ($ids as $id) {
      if (isset($loaded[$id]) && $loaded[$id] instanceof NodeInterface) {
        $ordered[$id] = $loaded[$id];
      }
    }
    return $ordered;
  }

  protected function shell(string $active, array $content): array {
    $account = $this->loadAccount();
    $urls = $this->memberUrls();

    $nav = [
      ['key' => 'overview', 'label' => $this->t('概览'), 'url' => $urls['overview']],
      ['key' => 'favorites', 'label' => $this->t('我的收藏'), 'url' => $urls['favorites']],
      ['key' => 'likes', 'label' => $this->t('我的点赞'), 'url' => $urls['likes']],
      ['key' => 'downloads', 'label' => $this->t('我的下载'), 'url' => $urls['downloads']],
      ['key' => 'membership', 'label' => $this->t('会员等级'), 'url' => $urls['membership']],
      ['key' => 'history', 'label' => $this->t('浏览历史'), 'url' => $urls['history']],
      ['key' => 'comments', 'label' => $this->t('我的评论'), 'url' => $urls['comments']],
      ['key' => 'submissions', 'label' => $this->t('我的投稿'), 'url' => $urls['submissions']],
      ['key' => 'profile', 'label' => $this->t('个人资料'), 'url' => $urls['profile']],
      ['key' => 'security', 'label' => $this->t('账户安全'), 'url' => $urls['security']],
    ];

    return [
      '#theme' => 'xedc_member_shell',
      '#account' => $this->accountData($account),
      '#avatar' => $this->avatar($account),
      '#nav' => $nav,
      '#active' => $active,
      '#urls' => $urls,
      '#content' => $content,
      '#attached' => [
        'library' => ['xedc_member/member'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Loads the active user that owns a public slug.
   */
  protected function loadPublicAccount(string $slug): UserInterface {
    $slug = rawurldecode(trim($slug));
    if ($slug === '' || str_contains($slug, '/') || str_contains($slug, '.') || mb_strlen($slug) > 32 || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N}_-]*$/u', $slug)) {
      throw new NotFoundHttpException();
    }
    $uid = $this->aliasManager->findUidBySlug($slug);
    if ($uid <= 0) {
      throw new NotFoundHttpException();
    }
    $account = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$account instanceof UserInterface || !$account->isActive()) {
      throw new NotFoundHttpException();
    }
    return $account;
  }

  /**
   * Public display name. The login email is never used.
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
   * Loads the current user entity.
   */
  protected function loadAccount(): UserInterface {
    $account = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    if (!$account instanceof UserInterface) {
      throw new AccessDeniedHttpException();
    }
    return $account;
  }

  /**
   * Builds account display data.
   */
  protected function accountData(UserInterface $account): array {
    $username = $account->getAccountName();
    $nickname = '';
    if ($account->hasField('field_xedc_nickname') && !$account->get('field_xedc_nickname')->isEmpty()) {
      $nickname = (string) $account->get('field_xedc_nickname')->value;
    }
    $name = $nickname !== '' ? $nickname : $username;
    $last_login = (int) $account->getLastLoginTime();
    $snapshot = \Drupal::service('xedc_core.membership')->snapshot($account);
    return [
      'name' => $name,
      'username' => $username,
      'nickname' => $nickname,
      'mail' => $account->getEmail() ?: $this->t('未设置邮箱'),
      'initial' => $name !== '' ? mb_substr($name, 0, 1) : '?',
      'member_since' => $this->dateFormatter->format($account->getCreatedTime(), 'custom', 'Y-m-d'),
      'last_login' => $last_login > 0 ? $this->dateFormatter->format($last_login, 'custom', 'Y-m-d H:i') : $this->t('尚未登录'),
      'tier' => $snapshot['tier'],
      'tier_label' => $snapshot['label'],
    ];
  }

  /**
   * Builds the user avatar render array.
   */
  protected function avatar(UserInterface $account): array {
    if ($account->get('user_picture')->isEmpty()) {
      return [];
    }
    $style = ImageStyle::load('max_325x325') ? 'max_325x325' : '';
    return $account->get('user_picture')->view([
      'label' => 'hidden',
      'type' => 'image',
      'settings' => [
        'image_style' => $style,
        'image_link' => '',
        'image_loading' => ['attribute' => 'eager'],
      ],
    ]);
  }

  /**
   * Builds the member center URLs.
   */
  protected function memberUrls(): array {
    return [
      'overview' => Url::fromRoute('xedc_member.overview')->toString(),
      'favorites' => Url::fromRoute('xedc_member.favorites')->toString(),
      'likes' => Url::fromRoute('xedc_member.likes')->toString(),
      'downloads' => Url::fromRoute('xedc_member.downloads')->toString(),
      'membership' => Url::fromRoute('xedc_member.membership')->toString(),
      'history' => Url::fromRoute('xedc_member.history')->toString(),
      'history_clear' => Url::fromRoute('xedc_member.history_clear')->toString(),
      'comments' => Url::fromRoute('xedc_member.comments')->toString(),
      'submissions' => Url::fromRoute('xedc_member.submissions')->toString(),
      'profile' => Url::fromRoute('xedc_member.profile')->toString(),
      'security' => Url::fromRoute('xedc_member.security')->toString(),
      'settings' => Url::fromRoute('xedc_member.security')->toString(),
      'logout' => Url::fromRoute('user.logout')->toString(),
      'login' => Url::fromRoute('user.login')->toString(),
    ];
  }

  /**
   * Builds favorite filter tabs.
   */
  protected function favoriteFilters(?string $active): array {
    $filters = [
      [
        'key' => 'all',
        'label' => $this->t('全部'),
        'url' => Url::fromRoute('xedc_member.favorites')->toString(),
        'active' => $active === NULL,
      ],
    ];
    foreach (FavoriteManager::ALLOWED_BUNDLES as $bundle) {
      $filters[] = [
        'key' => $bundle,
        'label' => $this->typeLabel($bundle),
        'url' => Url::fromRoute('xedc_member.favorites', [], ['query' => ['type' => $bundle]])->toString(),
        'active' => $active === $bundle,
      ];
    }
    return $filters;
  }

  /**
   * Builds history filter tabs.
   */
  protected function historyFilters(?string $active): array {
    $filters = [
      [
        'key' => 'all',
        'label' => $this->t('全部'),
        'url' => Url::fromRoute('xedc_member.history')->toString(),
        'active' => $active === NULL,
      ],
    ];
    foreach (HistoryManager::ALLOWED_BUNDLES as $bundle) {
      $filters[] = [
        'key' => $bundle,
        'label' => $this->typeLabel($bundle),
        'url' => Url::fromRoute('xedc_member.history', [], ['query' => ['type' => $bundle]])->toString(),
        'active' => $active === $bundle,
      ];
    }
    return $filters;
  }

  /**
   * Builds a favorite card.
   */
  protected function favoriteCard(NodeInterface $node, ?array $remove_form = NULL): array {
    $node = $this->translatedNode($node);
    return [
      'nid' => (int) $node->id(),
      'title' => $node->label(),
      'url' => $node->toUrl()->toString(),
      'type' => $node->bundle(),
      'type_label' => $this->typeLabel($node->bundle()),
      'category' => $this->categoryLabel($node),
      'cover' => $this->coverImage($node),
      'date' => $this->dateFormatter->format($node->getCreatedTime(), 'custom', 'Y-m-d'),
      'remove_form' => $remove_form,
    ];
  }

  /**
   * Builds a history card.
   */
  protected function historyCard(NodeInterface $node, object $row, ?array $delete_form = NULL): array {
    $node = $this->translatedNode($node);
    return [
      'card_id' => 'xedc-member-history-' . $node->id(),
      'nid' => (int) $node->id(),
      'title' => $node->label(),
      'url' => $node->toUrl()->toString(),
      'type' => $node->bundle(),
      'type_label' => $this->typeLabel($node->bundle()),
      'category' => $this->categoryLabel($node),
      'cover' => $this->coverImage($node),
      'date' => $this->dateFormatter->format((int) $row->last_viewed, 'custom', 'Y-m-d H:i'),
      'meta' => $this->t('浏览 @count 次', ['@count' => (int) $row->view_count]),
      'remove_form' => $delete_form,
    ];
  }

  /**
   * Builds a comment card.
   */
  protected function commentCard(CommentInterface $comment): array {
    $parent = $comment->getCommentedEntity();
    $parent_title = $this->t('内容不可用或已下架');
    $parent_url = '';
    $parent_accessible = FALSE;
    if ($parent instanceof NodeInterface && $parent->access('view')) {
      $parent = $this->translatedNode($parent);
      $parent_title = $parent->label();
      $parent_url = $parent->toUrl()->toString();
      $parent_accessible = TRUE;
    }

    $edit_url = '';
    if ($comment->access('update')) {
      $edit_url = Url::fromRoute('entity.comment.edit_form', ['comment' => $comment->id()], [
        'query' => ['destination' => Url::fromRoute('xedc_member.comments')->toString()],
      ])->toString();
    }

    $comment_url = '';
    if ($parent_accessible && $comment->isPublished()) {
      $comment_url = $parent->toUrl()->setOption('fragment', 'comment-' . $comment->id())->toString();
    }

    return [
      'cid' => (int) $comment->id(),
      'subject' => $comment->getSubject(),
      'body' => $comment->get('comment_body')->view(['label' => 'hidden']),
      'created' => $this->dateFormatter->format($comment->getCreatedTime(), 'custom', 'Y-m-d H:i'),
      'status_id' => $comment->isPublished() ? 'published' : 'pending',
      'status_label' => $comment->isPublished() ? $this->t('已发布') : $this->t('待审核'),
      'parent_title' => $parent_title,
      'parent_url' => $parent_url,
      'edit_url' => $edit_url,
      'comment_url' => $comment_url,
    ];
  }

  /**
   * Builds a submission card.
   */
  protected function submissionCard(NodeInterface $node, array $state): array {
    $node = $this->translatedNode($node);
    $review_note = '';
    if ($node->hasField('revision_log') && in_array($state['id'], ['changes_requested', 'rejected'], TRUE)) {
      $review_note = (string) $node->get('revision_log')->value;
    }

    $edit_url = '';
    if ($node->access('update')) {
      $edit_url = Url::fromRoute('entity.node.edit_form', ['node' => $node->id()], [
        'query' => ['destination' => Url::fromRoute('xedc_member.submissions')->toString()],
      ])->toString();
    }
    $view_url = $node->isPublished() ? $node->toUrl()->toString() : '';

    return [
      'nid' => (int) $node->id(),
      'title' => $node->label(),
      'type' => $node->bundle(),
      'type_label' => $this->typeLabel($node->bundle()),
      'state_id' => $state['id'],
      'state_label' => $state['label'],
      'is_published' => $node->isPublished(),
      'changed' => $this->dateFormatter->format($node->getChangedTime(), 'custom', 'Y-m-d H:i'),
      'review_note' => $review_note,
      'edit_url' => $edit_url,
      'view_url' => $view_url,
    ];
  }

  /**
   * Loads the latest node revision.
   */
  protected function latestRevision(NodeInterface $node): NodeInterface {
    $storage = $this->entityTypeManager()->getStorage('node');
    $latest_id = $storage->getLatestRevisionId($node->id());
    if ($latest_id) {
      $latest = $storage->loadRevision($latest_id);
      if ($latest instanceof NodeInterface) {
        return $latest;
      }
    }
    return $node;
  }

  /**
   * Resolves moderation state ID, label and published status.
   */
  protected function moderationState(NodeInterface $node): array {
    $state_id = $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
    if ($state_id === '') {
      $state_id = $node->isPublished() ? 'published' : 'draft';
    }
    $label = $state_id;
    if (\Drupal::hasService('content_moderation.moderation_information')) {
      try {
        $workflow = \Drupal::service('content_moderation.moderation_information')->getWorkflowForEntity($node);
        if ($workflow) {
          $state = $workflow->getTypePlugin()->getState($state_id);
          if ($state) {
            $label = $state->label();
          }
        }
      }
      catch (\Throwable $e) {
        // Keep the machine state as fallback.
      }
    }
    return [
      'id' => $state_id,
      'label' => $label,
      'published' => $node->isPublished(),
    ];
  }

  /**
   * Builds new submission links.
   */
  protected function submissionCreateLinks(): array {
    return [
      [
        'label' => $this->t('投稿美图'),
        'url' => Url::fromRoute('node.add', ['node_type' => 'photo'])->toString(),
      ],
      [
        'label' => $this->t('投稿视频'),
        'url' => Url::fromRoute('node.add', ['node_type' => 'video'])->toString(),
      ],
      [
        'label' => $this->t('投稿文章'),
        'url' => Url::fromRoute('node.add', ['node_type' => 'article'])->toString(),
      ],
    ];
  }

  /**
   * Resolves the referenced category term name.
   */
  protected function categoryLabel(NodeInterface $node): string {
    if (!$node->hasField('field_categories') || $node->get('field_categories')->isEmpty()) {
      return '';
    }
    $term = $node->get('field_categories')->entity;
    if (!$term) {
      return '';
    }
    $translated = \Drupal::service('entity.repository')->getTranslationFromContext($term);
    return (string) $translated->label();
  }

  /**
   * Node in the current content language.
   */
  protected function translatedNode(NodeInterface $node): NodeInterface {
    $translated = \Drupal::service('entity.repository')->getTranslationFromContext($node);
    return $translated instanceof NodeInterface ? $translated : $node;
  }

  /**
   * Resolves a content type label.
   */
  protected function typeLabel(string $bundle) {
    return match ($bundle) {
      'photo' => $this->t('美图'),
      'video' => $this->t('视频'),
      'article' => $this->t('文章'),
      default => $this->t('内容'),
    };
  }

  /**
   * Resolves responsive cover image data for a node.
   */
  protected function coverImage(NodeInterface $node): array {
    $field = match ($node->bundle()) {
      'photo' => 'field_cover_image',
      'video' => 'field_video_cover',
      'article' => 'field_cover',
      default => NULL,
    };

    if ($field === NULL || !$node->hasField($field) || $node->get($field)->isEmpty()) {
      return [];
    }

    $media = $node->get($field)->entity;
    if (!$media instanceof MediaInterface) {
      return [];
    }

    $source = $media->getSource()->getConfiguration()['source_field'] ?? NULL;
    if (!$source || !$media->hasField($source) || $media->get($source)->isEmpty()) {
      return [];
    }

    $file = $media->get($source)->entity;
    if (!$file instanceof FileInterface) {
      return [];
    }

    $small = ImageStyle::load('max_650x650') ?: ImageStyle::load('medium');
    $large = ImageStyle::load('max_1300x1300') ?: $small;
    $uri = $file->getFileUri();
    $src = $small ? $small->buildUrl($uri) : $this->fileUrlGenerator->generateString($uri);
    $src_2x = $large ? $large->buildUrl($uri) : $src;
    $src = $this->fileUrlGenerator->transformRelative($src);
    $src_2x = $this->fileUrlGenerator->transformRelative($src_2x);

    return [
      'src' => $src,
      'srcset' => $src_2x !== $src ? $src . ' 1x, ' . $src_2x . ' 2x' : '',
      'alt' => $node->label(),
    ];
  }

  /**
   * Counts the current user's comments.
   */
  protected function commentCount(int $uid): int {
    return (int) $this->entityTypeManager()->getStorage('comment')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->count()
      ->execute();
  }

  /**
   * Counts the current user's submissions.
   */
  protected function submissionCount(int $uid): int {
    return (int) $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('type', FavoriteManager::ALLOWED_BUNDLES, 'IN')
      ->count()
      ->execute();
  }

}
