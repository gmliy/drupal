<?php

namespace Drupal\xedc_member\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\path_alias\AliasRepositoryInterface;
use Drupal\user\UserDataInterface;

/**
 * Stores and resolves a member's root URL alias.
 *
 * The alias is a single path segment, for example /nickname, and is kept in
 * sync with the user path alias so it cannot collide with the rest of the site.
 */
class MemberAliasManager {

  /**
   * Request-local slug lookups.
   *
   * @var array<string, int>
   */
  protected array $uidBySlug = [];

  /**
   * Request-local preferred slugs.
   *
   * @var array<int, string>
   */
  protected array $slugByUid = [];

  /**
   * Member center suffixes that follow a vanity alias.
   *
   * @var array<string, string>
   */
  protected array $sections = [
    '' => '/member',
    'favorites' => '/member/favorites',
    'likes' => '/member/likes',
    'downloads' => '/member/downloads',
    'membership' => '/member/membership',
    'history' => '/member/history',
    'history/clear' => '/member/history/clear',
    'comments' => '/member/comments',
    'submissions' => '/member/submissions',
    'profile' => '/member/profile',
    'security' => '/member/security',
    'settings' => '/member/security',
  ];

  /**
   * Constructs a MemberAliasManager.
   */
  public function __construct(
    protected Connection $database,
    protected UserDataInterface $userData,
    protected LanguageManagerInterface $languageManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AliasRepositoryInterface $aliasRepository,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Preferred slugs for many members, keyed by user ID.
   *
   * One user-data read and, only for members without a stored alias, one
   * path-alias read. Missing members are returned as empty strings.
   *
   * @param int[] $uids
   * @return array<int, string>
   */
  public function slugsFor(array $uids): array {
    $wanted = [];
    foreach ($uids as $uid) {
      $uid = (int) $uid;
      if ($uid > 0) {
        $wanted[$uid] = $uid;
      }
    }
    $out = [];
    $missing = [];
    foreach ($wanted as $uid) {
      if (array_key_exists($uid, $this->slugByUid)) {
        $out[$uid] = $this->slugByUid[$uid];
      }
      else {
        $missing[$uid] = $uid;
      }
    }
    if ($missing) {
      $stored = $this->userData->get('xedc_member', NULL, 'alias');
      if (is_array($stored)) {
        foreach ($missing as $uid) {
          $value = $stored[$uid] ?? NULL;
          if (is_string($value) && $value !== '' && !str_contains($value, '/')) {
            $out[$uid] = $this->slugByUid[$uid] = $value;
            unset($missing[$uid]);
          }
        }
      }
    }
    if ($missing) {
      $paths = [];
      foreach ($missing as $uid) {
        $paths['/user/' . $uid] = $uid;
      }
      $query = $this->database->select('path_alias', 'pa');
      $query->fields('pa', ['path', 'alias', 'langcode']);
      $query->condition('status', 1);
      $query->condition('path', array_keys($paths), 'IN');
      $langcode = $this->langcode();
      $by_path = [];
      foreach ($query->execute() as $row) {
        $by_path[$row->path][] = $row;
      }
      foreach ($missing as $uid) {
        $slug = '';
        foreach ($by_path['/user/' . $uid] ?? [] as $row) {
          if (($row->langcode ?? '') !== $langcode && ($row->langcode ?? '') !== LanguageInterface::LANGCODE_NOT_SPECIFIED) {
            continue;
          }
          $candidate = rawurldecode(trim((string) $row->alias, '/'));
          if ($candidate !== '' && !str_contains($candidate, '/')) {
            $slug = $candidate;
            if (($row->langcode ?? '') === $langcode) {
              break;
            }
          }
        }
        $out[$uid] = $this->slugByUid[$uid] = $slug;
      }
    }
    return $out;
  }

  public function getSlug(int $uid): string {
    if ($uid <= 0) {
      return '';
    }
    if (array_key_exists($uid, $this->slugByUid)) {
      return $this->slugByUid[$uid];
    }
    $stored = $this->userData->get('xedc_member', $uid, 'alias');
    if (is_string($stored) && $stored !== '') {
      return $this->slugByUid[$uid] = $stored;
    }
    $record = $this->aliasRepository->lookupBySystemPath('/user/' . $uid, $this->langcode());
    $slug = '';
    if (is_array($record) && !empty($record['alias'])) {
      $candidate = rawurldecode(trim((string) $record['alias'], '/'));
      if ($candidate !== '' && !str_contains($candidate, '/')) {
        $slug = $candidate;
      }
    }
    return $this->slugByUid[$uid] = $slug;
  }

  /**
   * Returns the public profile path, such as /r/nickname.
   *
   * An empty string means the member has no custom public slug. This is not
   * the member center. The member center lives at /{slug}.
   */
  public function publicBase(int $uid): string {
    $slug = $this->getSlug($uid);
    return $slug === '' ? '' : '/r/' . $slug;
  }

  /**
   * Returns the member-center prefix, such as /nickname or /member.
   */
  public function memberBase(int $uid): string {
    $slug = $this->getSlug($uid);
    return $slug === '' ? '/member' : '/' . $slug;
  }

  /**
   * Rewrites an internal /member path to the member's public path.
   */
  public function toPublicPath(int $uid, string $path): string {
    $base = $this->memberBase($uid);
    if ($base === '/member') {
      return $path;
    }
    if ($path === '/member') {
      return $base;
    }
    if (str_starts_with($path, '/member/')) {
      $rest = substr($path, strlen('/member/'));
      if ($rest === 'settings') {
        $rest = 'security';
      }
      return $base . '/' . $rest;
    }
    return $path;
  }

  /**
   * Maps /{slug} or /{slug}/section to an internal member path.
   *
   * The result depends only on the path. It is safe to store in the shared
   * route-match cache.
   */
  public function vanityInternalPath(string $path): ?string {
    $slug = $this->vanitySlug($path);
    if ($slug === NULL) {
      return NULL;
    }
    $suffix = $this->vanitySuffix($path);
    $internal = $this->internalPath($suffix);
    if ($internal === NULL || $this->findUidBySlug($slug) <= 0) {
      return NULL;
    }
    return $internal;
  }

  /**
   * Returns the owner of a vanity member path, or NULL when it is not one.
   */
  public function ownerForVanityPath(string $path): ?int {
    if ($this->vanityInternalPath($path) === NULL) {
      return NULL;
    }
    $slug = $this->vanitySlug($path);
    if ($slug === NULL) {
      return NULL;
    }
    $uid = $this->findUidBySlug($slug);
    return $uid > 0 ? $uid : NULL;
  }

  /**
   * Maps a vanity suffix to an internal member path.
   */
  public function internalPath(string $suffix): ?string {
    $suffix = trim($suffix, '/');
    return $this->sections[$suffix] ?? NULL;
  }

  /**
   * Finds the user id that owns a single-segment alias.
   */
  public function findUidBySlug(string $slug): int {
    $slug = rawurldecode(trim($slug, '/'));
    if ($slug === '' || str_contains($slug, '/') || str_contains($slug, '.')) {
      return 0;
    }
    $key = mb_strtolower($slug);
    if (array_key_exists($key, $this->uidBySlug)) {
      return $this->uidBySlug[$key];
    }
    $cid = 'xedc_member:slug:' . hash('sha256', $key);
    $cached = $this->cache->get($cid);
    if ($cached) {
      return $this->uidBySlug[$key] = (int) $cached->data;
    }
    $uid = $this->lookupUid($slug);
    $this->cache->set($cid, $uid, Cache::PERMANENT, ['xedc_member:aliases']);
    return $this->uidBySlug[$key] = $uid;
  }

  /**
   * Validates a raw alias and returns a status payload for the form and AJAX.
   *
   * @return array{ok: bool, status: string, message: string, slug: string}
   */
  public function check(string $raw, int $uid): array {
    $slug = $this->prepare($raw);
    if ($slug === NULL) {
      return [
        'ok' => FALSE,
        'status' => 'invalid',
        'message' => (string) t('格式不正确'),
        'slug' => '',
      ];
    }
    if ($slug === '') {
      return [
        'ok' => TRUE,
        'status' => 'empty',
        'message' => (string) t('留空则会员中心使用 /member。公开主页为 /r/别名。'),
        'slug' => '',
      ];
    }
    $current = $this->getSlug($uid);
    if (mb_strlen($slug) < 2 && mb_strtolower($slug) !== mb_strtolower($current)) {
      return [
        'ok' => FALSE,
        'status' => 'invalid',
        'message' => (string) t('至少 2 个字符'),
        'slug' => $slug,
      ];
    }
    if (mb_strlen($slug) > 32) {
      return [
        'ok' => FALSE,
        'status' => 'invalid',
        'message' => (string) t('不能超过 32 个字符'),
        'slug' => $slug,
      ];
    }
    if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N}_-]*$/u', $slug)) {
      return [
        'ok' => FALSE,
        'status' => 'invalid',
        'message' => (string) t('格式不正确'),
        'slug' => $slug,
      ];
    }
    if ($this->isReservedSlug($slug)) {
      return [
        'ok' => FALSE,
        'status' => 'taken',
        'message' => (string) t('该路径不可用'),
        'slug' => $slug,
      ];
    }
    if ($this->isTaken($slug, $uid)) {
      return [
        'ok' => FALSE,
        'status' => 'taken',
        'message' => (string) t('已被占用'),
        'slug' => $slug,
      ];
    }
    return [
      'ok' => TRUE,
      'status' => 'available',
      'message' => (string) t('可用'),
      'slug' => $slug,
    ];
  }

  /**
   * Saves the alias and syncs the user path alias.
   *
   * @return string|null
   *   An error message, or NULL when the alias was saved.
   */
  public function save(int $uid, string $raw): ?string {
    $check = $this->check($raw, $uid);
    if (!$check['ok']) {
      return $check['message'] !== '' ? $check['message'] : (string) t('已被占用');
    }
    $slug = $check['slug'];
    if ($slug === '') {
      $this->userData->delete('xedc_member', $uid, 'alias');
    }
    else {
      $this->userData->set('xedc_member', $uid, 'alias', $slug);
    }
    try {
      $this->syncPathAliases($uid, $slug);
    }
    catch (\Throwable $e) {
      return (string) t('已被占用');
    }
    $this->slugByUid = [];
    $this->uidBySlug = [];
    Cache::invalidateTags(['user:' . $uid, 'xedc_member:aliases', 'route_match']);
    return NULL;
  }

  /**
   * Returns the first segment of a vanity path, or NULL when it cannot be one.
   */
  protected function vanitySlug(string $path): ?string {
    if ($path === '/' || !str_starts_with($path, '/')) {
      return NULL;
    }
    $trimmed = trim(rawurldecode($path), '/');
    if ($trimmed === '') {
      return NULL;
    }
    $slug = explode('/', $trimmed)[0];
    if ($slug === '' || str_contains($slug, '.') || $this->isReservedSlug($slug)) {
      return NULL;
    }
    return $slug;
  }

  /**
   * Returns the member-center suffix after the slug.
   */
  protected function vanitySuffix(string $path): string {
    $trimmed = trim(rawurldecode($path), '/');
    $parts = explode('/', $trimmed);
    return implode('/', array_slice($parts, 1));
  }

  /**
   * Normalizes user input into a single path segment.
   */
  protected function prepare(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
      return '';
    }
    if (preg_match('#^https?://#i', $raw)) {
      $path = parse_url($raw, PHP_URL_PATH);
      $raw = is_string($path) ? $path : '';
    }
    $raw = trim($raw, " \t\n\r\0\x0B/");
    if ($raw === '') {
      return '';
    }
    if (str_contains($raw, '/') || str_contains($raw, '\\') || str_contains($raw, '?') || str_contains($raw, '#') || str_contains($raw, '.') || preg_match('/\s/u', $raw)) {
      return NULL;
    }
    return $raw;
  }

  /**
   * Whether a single path segment is reserved for Drupal or this site.
   */
  public function isReservedSlug(string $slug): bool {
    $reserved = [
      'admin', 'user', 'users', 'node', 'system', 'core', 'batch', 'cron',
      'member', 'search', 'sites', 'themes', 'modules', 'libraries',
      'xedc-member', 'jsonapi', 'media', 'comment', 'taxonomy', 'filter',
      'file', 'ajax', 'sitemap', 'rss', 'index', 'update', 'meitu', 'video',
      'articles', 'tags', 'models', 'about', 'contact', 'history', 'views',
      'toolbar', 'editor', 'contextual', 'webform', 'image-style', 'styles',
      'favicon.ico', 'robots.txt', 'sitemap.xml', 'r',
    ];
    return in_array(mb_strtolower($slug), $reserved, TRUE);
  }

  /**
   * Whether the slug collides with a route, alias, or another member.
   */
  protected function isTaken(string $slug, int $uid): bool {
    $lower = mb_strtolower($slug);
    if ($this->isReservedSlug($lower)) {
      return TRUE;
    }
    $route_count = (int) $this->database->query("SELECT COUNT(*) FROM {router} WHERE LOWER(path) = :path", [
      ':path' => '/' . $lower,
    ])->fetchField();
    if ($route_count > 0) {
      return TRUE;
    }
    $aliases = $this->database->query("SELECT path FROM {path_alias} WHERE LOWER(alias) = :alias", [
      ':alias' => '/' . $lower,
    ]);
    foreach ($aliases as $row) {
      if ((string) $row->path !== '/user/' . $uid) {
        return TRUE;
      }
    }
    $record = $this->aliasRepository->lookupByAlias('/' . $slug, $this->langcode());
    if (is_array($record) && !empty($record['path']) && $record['path'] !== '/user/' . $uid) {
      return TRUE;
    }
    if ($this->memberDataConflicts($lower, $uid)) {
      return TRUE;
    }
    return $this->resolvedRouteConflicts($slug, $uid);
  }

  /**
   * Whether another member already stored this slug.
   */
  protected function memberDataConflicts(string $lower, int $uid): bool {
    $rows = $this->database->select('users_data', 'ud')
      ->fields('ud', ['uid', 'value', 'serialized'])
      ->condition('module', 'xedc_member')
      ->condition('name', 'alias')
      ->execute();
    foreach ($rows as $row) {
      if ((int) $row->uid === $uid) {
        continue;
      }
      $stored = $this->decodeUserData($row->value, $row->serialized);
      if (is_string($stored) && mb_strtolower($stored) === $lower) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the public path already resolves to some other page.
   */
  protected function resolvedRouteConflicts(string $slug, int $uid): bool {
    try {
      $url = \Drupal::service('path.validator')->getUrlIfValidWithoutAccessCheck('/' . $slug);
    }
    catch (\Throwable $e) {
      return FALSE;
    }
    if (!$url) {
      return FALSE;
    }
    $route = $url->getRouteName();
    if ($route === 'xedc_member.alias_denied') {
      return TRUE;
    }
    if (is_string($route) && str_starts_with($route, 'xedc_member.')) {
      return $this->findUidBySlug($slug) !== $uid;
    }
    if ($route === 'entity.user.canonical') {
      $param = $url->getRouteParameters()['user'] ?? 0;
      $owner = is_object($param) && method_exists($param, 'id') ? (int) $param->id() : (int) $param;
      return $owner !== $uid;
    }
    return TRUE;
  }

  /**
   * Looks up a slug in path aliases and stored member aliases.
   */
  protected function lookupUid(string $slug): int {
    $record = $this->aliasRepository->lookupByAlias('/' . $slug, $this->langcode());
    if (is_array($record) && !empty($record['path']) && preg_match('#^/user/(\d+)$#', (string) $record['path'], $matches)) {
      return (int) $matches[1];
    }
    $lower = mb_strtolower($slug);
    $rows = $this->database->select('users_data', 'ud')
      ->fields('ud', ['uid', 'value', 'serialized'])
      ->condition('module', 'xedc_member')
      ->condition('name', 'alias')
      ->execute();
    foreach ($rows as $row) {
      $stored = $this->decodeUserData($row->value, $row->serialized);
      if (is_string($stored) && mb_strtolower($stored) === $lower) {
        return (int) $row->uid;
      }
    }
    return 0;
  }

  /**
   * Keeps a single vanity path alias for the user.
   */
  protected function syncPathAliases(int $uid, string $slug): void {
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('path', '/user/' . $uid)
      ->sort('id', 'DESC')
      ->range(0, 100)
      ->execute();
    $aliases = $ids ? $storage->loadMultiple($ids) : [];
    $keeper = NULL;
    foreach ($aliases as $alias) {
      $value = trim((string) $alias->get('alias')->value, '/');
      if ($value === '' || str_contains($value, '/')) {
        continue;
      }
      if ($keeper === NULL) {
        $keeper = $alias;
        continue;
      }
      $alias->delete();
    }
    if ($slug === '') {
      if ($keeper) {
        $keeper->delete();
      }
      return;
    }
    $target = '/' . $slug;
    if ($keeper) {
      if ((string) $keeper->get('alias')->value !== $target) {
        $keeper->set('alias', $target);
        $keeper->save();
      }
      return;
    }
    $storage->create([
      'path' => '/user/' . $uid,
      'alias' => $target,
      'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
    ])->save();
  }

  /**
   * Reads a users_data value, which is stored raw when it is a scalar.
   */
  protected function decodeUserData(mixed $value, mixed $serialized): mixed {
    if (empty($serialized) || !is_string($value)) {
      return $value;
    }
    $decoded = unserialize($value, ['allowed_classes' => FALSE]);
    return $decoded === FALSE ? $value : $decoded;
  }

  /**
   * Returns the language used for alias lookups.
   */
  protected function langcode(): string {
    return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_URL)->getId();
  }

}
