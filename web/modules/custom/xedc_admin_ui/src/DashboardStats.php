<?php

declare(strict_types=1);

namespace Drupal\xedc_admin_ui;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Dashboard statistics for the XEDC admin overview.
 *
 * Counts use the default revision in the default language. The moderation
 * queue follows the latest translation-affected revision used by the
 * moderated content view, then keeps only the default language.
 */
final class DashboardStats {

  private const TYPES = ['photo', 'video', 'article', 'page'];

  private const OVERVIEW_TYPES = ['photo', 'video', 'article'];

  private const QUEUE_STATES = [
    'draft',
    'needs_review',
    'changes_requested',
    'rejected',
  ];

  private const COVER_FIELDS = [
    'photo' => 'field_cover_image',
    'article' => 'field_cover',
    'video' => 'field_video_cover',
  ];

  public function __construct(
    private readonly \Drupal\Core\Database\Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly LanguageManagerInterface $languageManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Permission flags that change which numbers may be returned.
   *
   * @return array{content: bool, unpublished: bool, queue: bool, members: bool}
   *   Flags for the account.
   */
  public function accessFlags(AccountInterface $account): array {
    return [
      'content' => $account->hasPermission('access content overview'),
      'unpublished' => $account->hasPermission('view any unpublished content') || $account->hasPermission('bypass node access'),
      'queue' => $account->hasPermission('view any unpublished content'),
      'members' => $account->hasPermission('administer users'),
    ];
  }

  /**
   * Cached statistics visible to this account.
   *
   * @return array<string, mixed>
   *   Numbers, recent rows, and cache metadata. Sensitive counts are omitted.
   */
  public function get(AccountInterface $account): array {
    $window = $this->shanghaiWindow();
    $flags = $this->accessFlags($account);
    $variant = implode(':', [
      $window['date'],
      $this->languageManager->getCurrentLanguage()->getId(),
      'c' . ($flags['content'] ? ($flags['unpublished'] ? 'all' : 'pub') : 'none'),
      'q' . ($flags['queue'] ? '1' : '0'),
      'm' . ($flags['members'] ? '1' : '0'),
    ]);
    $cid = 'xedc_admin_ui.dashboard.stats:' . $variant;
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      $cached->data['cache_hit'] = TRUE;
      return $cached->data;
    }

    $payload = $this->build($flags, $window);
    $payload['variant'] = $variant;
    $payload['expires'] = $window['next'];
    $payload['cache_hit'] = FALSE;
    $this->cache->set($cid, $payload, $window['next'], [
      'node_list',
      'user_list',
      'config:workflows.workflow.xedc_editorial',
    ]);
    return $payload;
  }

  /**
   * @param array{content: bool, unpublished: bool, queue: bool, members: bool} $flags
   *   Access flags.
   * @param array{date: string, label: string, start: int, next: int} $window
   *   Shanghai calendar day.
   *
   * @return array<string, mixed>
   *   Uncached payload.
   */
  private function build(array $flags, array $window): array {
    $payload = [
      'date_label' => $window['label'],
      'includes_unpublished' => $flags['content'] && $flags['unpublished'],
      'content' => NULL,
      'members' => NULL,
      'queue' => NULL,
      'recent' => NULL,
    ];

    if ($flags['content']) {
      $payload['content'] = $this->contentCounts($flags['unpublished'], $window['start'], $window['next']);
      $payload['recent'] = $this->recentPublished();
    }
    if ($flags['members']) {
      $payload['members'] = $this->activeMembers();
    }
    if ($flags['queue']) {
      $payload['queue'] = $this->moderationQueue();
    }
    return $payload;
  }

  /**
   * @return array{date: string, label: string, start: int, next: int}
   *   Start and next midnight as Unix timestamps.
   */
  private function shanghaiWindow(): array {
    $timezone = new \DateTimeZone('Asia/Shanghai');
    $start = new \DateTimeImmutable('today', $timezone);
    $next = $start->modify('+1 day');
    $weekdays = ['日', '一', '二', '三', '四', '五', '六'];
    return [
      'date' => $start->format('Y-m-d'),
      'label' => $start->format('Y年n月j日') . ' 星期' . $weekdays[(int) $start->format('w')],
      'start' => $start->getTimestamp(),
      'next' => $next->getTimestamp(),
    ];
  }

  /**
   * @return array{total: int, today: int, published: int, types: array<string, array{label: string, total: int, today: int, published: int}>}
   *   Default-language default-revision counts.
   */
  private function contentCounts(bool $includeUnpublished, int $start, int $next): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->addField('n', 'type');
    $query->addExpression('COUNT(*)', 'total');
    $query->addExpression('SUM(CASE WHEN n.status = 1 THEN 1 ELSE 0 END)', 'published');
    $query->addExpression('SUM(CASE WHEN n.created >= :created_start AND n.created < :created_next THEN 1 ELSE 0 END)', 'today', [
      ':created_start' => $start,
      ':created_next' => $next,
    ]);
    $query->condition('n.default_langcode', 1);
    $query->condition('n.type', self::TYPES, 'IN');
    if (!$includeUnpublished) {
      $query->condition('n.status', 1);
    }
    $query->groupBy('n.type');

    $labels = $this->typeLabels();
    $types = [];
    foreach (self::OVERVIEW_TYPES as $type) {
      $types[$type] = [
        'label' => $labels[$type] ?? $type,
        'total' => 0,
        'today' => 0,
        'published' => 0,
      ];
    }
    $total = 0;
    $today = 0;
    $published = 0;
    foreach ($query->execute() as $row) {
      $type = (string) $row->type;
      $rowTotal = (int) $row->total;
      $rowToday = (int) $row->today;
      $rowPublished = (int) $row->published;
      $total += $rowTotal;
      $today += $rowToday;
      $published += $rowPublished;
      if (isset($types[$type])) {
        $types[$type]['total'] = $rowTotal;
        $types[$type]['today'] = $rowToday;
        $types[$type]['published'] = $rowPublished;
      }
    }
    return [
      'total' => $total,
      'today' => $today,
      'published' => $published,
      'types' => $types,
    ];
  }

  /**
   * Active accounts in their default language. There is no member role.
   */
  private function activeMembers(): int {
    $query = $this->database->select('users_field_data', 'u');
    $query->condition('u.uid', 0, '>');
    $query->condition('u.default_langcode', 1);
    $query->condition('u.status', 1);
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Default-language rows from the moderated view's latest-revision states.
   *
   * @return array{total: int, drafts: int}
   *   Queue total and the draft subset.
   */
  private function moderationQueue(): array {
    $latest = $this->database->select('node_field_revision', 'base');
    $latest->addField('base', 'nid');
    $latest->addField('base', 'langcode');
    $latest->addExpression('MAX(base.vid)', 'vid');
    $latest->condition('base.revision_translation_affected', 1);
    $latest->groupBy('base.nid');
    $latest->groupBy('base.langcode');

    $query = $this->database->select('node_field_revision', 'nfr');
    $query->join($latest, 'latest', 'latest.nid = nfr.nid AND latest.langcode = nfr.langcode AND latest.vid = nfr.vid');
    $query->join('content_moderation_state_field_revision', 'cms', 'cms.content_entity_type_id = :entity_type AND cms.content_entity_revision_id = nfr.vid AND cms.langcode = nfr.langcode', [
      ':entity_type' => 'node',
    ]);
    $query->join('node_field_data', 'nfd', 'nfd.nid = nfr.nid AND nfd.langcode = nfr.langcode AND nfd.default_langcode = 1');
    $query->condition('nfr.revision_translation_affected', 1);
    $query->condition('cms.workflow', 'xedc_editorial');
    $query->condition('cms.moderation_state', self::QUEUE_STATES, 'IN');
    $query->addField('cms', 'moderation_state');
    $query->addExpression('COUNT(*)', 'total');
    $query->groupBy('cms.moderation_state');

    $total = 0;
    $drafts = 0;
    foreach ($query->execute() as $row) {
      $count = (int) $row->total;
      $total += $count;
      if ((string) $row->moderation_state === 'draft') {
        $drafts += $count;
      }
    }
    return [
      'total' => $total,
      'drafts' => $drafts,
    ];
  }

  /**
   * Eight newest published default-language revisions.
   *
   * @return list<array<string, mixed>>
   *   Recent rows safe to render.
   */
  private function recentPublished(): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid', 'type', 'title', 'changed']);
    $query->condition('n.default_langcode', 1);
    $query->condition('n.status', 1);
    $query->condition('n.type', self::TYPES, 'IN');
    $query->orderBy('n.changed', 'DESC');
    $query->range(0, 8);
    $rows = $query->execute()->fetchAll();

    $labels = $this->typeLabels();
    $storage = $this->entityTypeManager->getStorage('node');
    $items = [];
    foreach ($rows as $row) {
      $node = $storage->load((int) $row->nid);
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      $items[] = [
        'nid' => (int) $node->id(),
        'title' => (string) $row->title,
        'type' => (string) $row->type,
        'type_label' => $labels[(string) $row->type] ?? (string) $row->type,
        'status_label' => '已发布',
        'changed' => (int) $row->changed,
        'thumbnail' => $this->thumbnailUrl($node),
        'view_url' => $node->toUrl()->toString(),
        'edit_url' => $node->access('update') ? $node->toUrl('edit-form')->toString() : NULL,
      ];
    }
    return $items;
  }

  /**
   * @return array<string, string>
   *   Bundle labels.
   */
  private function typeLabels(): array {
    $labels = [];
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple(self::TYPES);
    foreach ($types as $type) {
      $labels[$type->id()] = (string) $type->label();
    }
    return $labels;
  }

  /**
   * Cover image URL, when the node has a viewable media image.
   */
  private function thumbnailUrl(NodeInterface $node): ?string {
    $fieldName = self::COVER_FIELDS[$node->bundle()] ?? NULL;
    if ($fieldName === NULL || !$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return NULL;
    }
    $media = $node->get($fieldName)->entity;
    if (!$media instanceof MediaInterface || !$media->access('view') || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return NULL;
    }
    $file = $media->get('field_media_image')->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $style = $this->entityTypeManager->getStorage('image_style')->load('thumbnail');
    if ($style instanceof ImageStyleInterface) {
      return $style->buildUrl($file->getFileUri());
    }
    return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
  }

}
