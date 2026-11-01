<?php

declare(strict_types=1);

namespace Drupal\xedc_admin_ui;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\workflows\WorkflowInterface;

/**
 * Read-only queries for the content operations center.
 *
 * Node lists use the default revision in each node's default language.
 * The moderation list uses the latest translation-affected revision of that
 * same default language, matching the moderated content view.
 */
final class ContentOps {

  use StringTranslationTrait;

  public const TYPES = ['photo', 'video', 'article'];

  public const PAGE_SIZE = 20;

  public const QUEUE_STATES = [
    'draft',
    'needs_review',
    'changes_requested',
    'rejected',
  ];

  public const REVIEW_STATES = [
    'draft',
    'needs_review',
    'changes_requested',
    'rejected',
    'archived',
  ];

  /**
   * Vocabulary to the node reference field that stores it.
   *
   * @var array<string, string>
   */
  public const VOCABULARIES = [
    'photo_category' => 'field_categories',
    'video_category' => 'field_categories',
    'article_category' => 'field_categories',
    'tags' => 'field_tags',
    'models' => 'field_model',
  ];

  private const COVER_FIELDS = [
    'photo' => 'field_cover_image',
    'article' => 'field_cover',
    'video' => 'field_video_cover',
  ];

  private const SUMMARY_TYPES = ['photo', 'article'];

  /**
   * @var array<string, string>|null
   */
  private ?array $stateLabels = NULL;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * Whether this account may see unpublished nodes in operations lists.
   */
  public function canSeeUnpublished(AccountInterface $account): bool {
    return $account->hasPermission('bypass node access') || $account->hasPermission('view any unpublished content');
  }

  /**
   * Whether this account may see the moderation queue titles.
   */
  public function canSeeQueue(AccountInterface $account): bool {
    return $account->hasPermission('view any unpublished content');
  }

  /**
   * Default-language content list for one bundle.
   *
   * @param array<string, mixed> $filters
   *   Whitelisted filter values.
   *
   * @return array{rows: list<array<string, mixed>>, total: int}
   *   Page rows and the unpaged total.
   */
  public function listing(string $type, array $filters, AccountInterface $account): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid', 'vid', 'title', 'type', 'uid', 'status', 'created', 'changed', 'langcode']);
    $query->condition('n.default_langcode', 1);
    $query->condition('n.type', $type);
    $this->applyListingFilters($query, $type, $filters, $account);
    $sort = ($filters['sort'] ?? '') === 'changed' ? 'changed' : 'created';
    $direction = strtoupper((string) ($filters['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $query->orderBy('n.' . $sort, $direction);
    $query->orderBy('n.nid', 'DESC');

    /** @var \Drupal\Core\Database\Query\PagerSelectExtender $paged */
    $paged = $query->extend(PagerSelectExtender::class);
    $paged->limit(self::PAGE_SIZE);
    $records = $paged->execute()->fetchAll();
    $pager = $this->pagerManager->getPager();
    $total = $pager ? (int) $pager->getTotalItems() : count($records);

    $storage = $this->entityTypeManager->getStorage('node');
    $rows = [];
    foreach ($records as $record) {
      $node = $storage->load((int) $record->nid);
      if (!$node instanceof NodeInterface || !$node->access('view', $account)) {
        continue;
      }
      $langcode = (string) $record->langcode;
      if ($node->language()->getId() !== $langcode && $node->hasTranslation($langcode)) {
        $node = $node->getTranslation($langcode);
      }
      $rows[] = $this->contentRow($node, $account, (int) $record->created);
    }
    return [
      'rows' => $rows,
      'total' => $total,
    ];
  }

  /**
   * Term rows for one existing vocabulary, with default-language usage.
   *
   * @return array{rows: list<array<string, mixed>>, total: int}
   *   The current page.
   */
  public function terms(string $vocabulary, string $keyword, bool $emptyOnly, AccountInterface $account): array {
    $rows = $this->termUsage($vocabulary);
    if ($keyword !== '') {
      $needle = mb_strtolower($keyword);
      $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
        return str_contains(mb_strtolower($row['name']), $needle);
      }));
    }
    if ($emptyOnly) {
      $rows = array_values(array_filter($rows, static function (array $row): bool {
        return $row['usage'] === 0;
      }));
    }

    $total = count($rows);
    $pager = $this->pagerManager->createPager($total, 40);
    $slice = array_slice($rows, $pager->getCurrentPage() * 40, 40);
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $visible = [];
    foreach ($slice as $row) {
      $term = $storage->load($row['tid']);
      if ($term === NULL || !$term->access('view', $account)) {
        continue;
      }
      $row['edit_url'] = $term->access('update', $account) ? $term->toUrl('edit-form')->toString() : NULL;
      $row['view_url'] = $term->toUrl()->toString();
      $visible[] = $row;
    }
    return [
      'rows' => $visible,
      'total' => $total,
    ];
  }

  /**
   * Latest affected revisions waiting in the editorial workflow.
   *
   * @return array{rows: list<array<string, mixed>>, total: int}
   *   Page rows. Empty when the account cannot view unpublished content.
   */
  public function moderationList(string $state, string $type, AccountInterface $account): array {
    if (!$this->canSeeQueue($account)) {
      return ['rows' => [], 'total' => 0];
    }

    $states = $state === 'queue' ? self::QUEUE_STATES : [$state];
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
    $query->fields('nfr', ['nid', 'vid', 'title', 'type', 'uid', 'changed', 'langcode']);
    $query->addField('cms', 'moderation_state', 'state');
    $query->addField('nfd', 'uid', 'owner_uid');
    $query->condition('nfr.revision_translation_affected', 1);
    $query->condition('cms.workflow', 'xedc_editorial');
    $query->condition('cms.moderation_state', $states, 'IN');
    $query->condition('nfr.type', $type === '' ? self::TYPES : [$type], 'IN');
    $query->orderBy('nfr.changed', 'DESC');

    /** @var \Drupal\Core\Database\Query\PagerSelectExtender $paged */
    $paged = $query->extend(PagerSelectExtender::class);
    $paged->limit(self::PAGE_SIZE);
    $records = $paged->execute()->fetchAll();
    $pager = $this->pagerManager->getPager();
    $total = $pager ? (int) $pager->getTotalItems() : count($records);

    $names = $this->userNames(array_merge(
      array_map(static fn ($row): int => (int) $row->uid, $records),
      array_map(static fn ($row): int => (int) $row->owner_uid, $records),
    ));
    $labels = $this->typeLabels();
    $rows = [];
    foreach ($records as $record) {
      $revision = $this->loadAffectedRevision((int) $record->nid, (string) $record->langcode);
      if (!$revision instanceof NodeInterface || !$revision->access('view', $account)) {
        continue;
      }
      $ownerId = (int) $record->owner_uid;
      $authorId = (int) $record->uid;
      $rows[] = [
        'nid' => (int) $record->nid,
        'vid' => (int) $record->vid,
        'title' => (string) $record->title,
        'type' => (string) $record->type,
        'type_label' => $labels[(string) $record->type] ?? (string) $record->type,
        'state' => (string) $record->state,
        'state_label' => $this->stateLabel((string) $record->state),
        'author' => $names[$authorId] ?? (string) $this->t('匿名'),
        'owner' => $names[$ownerId] ?? (string) $this->t('匿名'),
        'changed' => (int) $record->changed,
        'langcode' => (string) $record->langcode,
        'edit_url' => $revision->access('update', $account) ? $revision->toUrl('edit-form')->toString() : NULL,
        'view_url' => $revision->toUrl()->toString(),
      ];
    }
    return [
      'rows' => $rows,
      'total' => $total,
    ];
  }

  /**
   * Lightweight operational reminders. No SEO audit is executed.
   *
   * @return array<string, mixed>
   *   Counts and recent rows visible to the account.
   */
  public function hub(AccountInterface $account): array {
    $unpublished = $this->canSeeUnpublished($account);
    $covers = [];
    foreach (self::COVER_FIELDS as $type => $field) {
      $covers[$type] = $this->countMissingReference($type, $field, $unpublished);
    }
    $summaries = [];
    foreach (self::SUMMARY_TYPES as $type) {
      $summaries[$type] = $this->countMissingSummary($type, $unpublished);
    }
    $empty = [];
    foreach (array_keys(self::VOCABULARIES) as $vocabulary) {
      $empty[$vocabulary] = 0;
      foreach ($this->termUsage($vocabulary) as $term) {
        if ($term['usage'] === 0) {
          $empty[$vocabulary]++;
        }
      }
    }
    return [
      'queue' => $this->canSeeQueue($account) ? $this->queueCount() : NULL,
      'missing_cover' => $covers,
      'missing_summary' => $summaries,
      'empty_terms' => $empty,
      'recent' => $this->recentEdits($account, $unpublished),
    ];
  }

  /**
   * Select options for a vocabulary the account may view.
   *
   * @return array<int, string>
   *   Term id to label.
   */
  public function termOptions(string $vocabulary, AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', $vocabulary)
      ->sort('name', 'ASC')
      ->execute();
    $options = [];
    foreach ($storage->loadMultiple($ids) as $term) {
      if ($term->access('view', $account)) {
        $options[(int) $term->id()] = (string) $term->label();
      }
    }
    return $options;
  }

  /**
   * Loads the latest translation-affected revision for a language.
   */
  public function loadAffectedRevision(int $nid, string $langcode): ?NodeInterface {
    $vid = $this->latestAffectedVid($nid, $langcode);
    if ($vid === NULL) {
      return NULL;
    }
    $revision = $this->entityTypeManager->getStorage('node')->loadRevision($vid);
    if (!$revision instanceof NodeInterface) {
      return NULL;
    }
    if ($revision->hasTranslation($langcode)) {
      $revision = $revision->getTranslation($langcode);
    }
    return $revision;
  }

  /**
   * Highest translation-affected revision id, if one exists.
   */
  public function latestAffectedVid(int $nid, string $langcode): ?int {
    $query = $this->database->select('node_field_revision', 'r');
    $query->addExpression('MAX(r.vid)', 'vid');
    $query->condition('r.nid', $nid);
    $query->condition('r.langcode', $langcode);
    $query->condition('r.revision_translation_affected', 1);
    $vid = $query->execute()->fetchField();
    return $vid ? (int) $vid : NULL;
  }

  /**
   * Bundle label.
   */
  public function typeLabel(string $type): string {
    return $this->typeLabels()[$type] ?? $type;
  }

  /**
   * Vocabulary label.
   */
  public function vocabularyLabel(string $vocabulary): string {
    $vocabularyEntity = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vocabulary);
    return $vocabularyEntity ? (string) $vocabularyEntity->label() : $vocabulary;
  }

  /**
   * Workflow state label.
   */
  public function stateLabel(string $state): string {
    return $this->stateLabels()[$state] ?? $state;
  }

  /**
   * Category vocabulary used by a content type.
   */
  public function categoryVocabulary(string $type): ?string {
    return match ($type) {
      'photo' => 'photo_category',
      'video' => 'video_category',
      'article' => 'article_category',
      default => NULL,
    };
  }

  /**
   * @param array<int> $uids
   *   User ids.
   *
   * @return array<int, string>
   *   Display names.
   */
  public function userNames(array $uids): array {
    $uids = array_values(array_unique(array_filter($uids)));
    if ($uids === []) {
      return [];
    }
    $names = [];
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
    foreach ($users as $user) {
      $names[(int) $user->id()] = $user->isAnonymous() ? (string) $this->t('匿名') : $user->getDisplayName();
    }
    return $names;
  }

  /**
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   Listing query.
   * @param array<string, mixed> $filters
   *   Filter values.
   */
  private function applyListingFilters($query, string $type, array $filters, AccountInterface $account): void {
    if (!$this->canSeeUnpublished($account)) {
      $query->condition('n.status', 1);
    }
    elseif (($filters['status'] ?? 'all') === '1' || ($filters['status'] ?? 'all') === '0') {
      $query->condition('n.status', (int) $filters['status']);
    }

    $keyword = trim((string) ($filters['q'] ?? ''));
    if ($keyword !== '') {
      $group = $query->orConditionGroup()
        ->condition('n.title', '%' . $this->database->escapeLike($keyword) . '%', 'LIKE');
      if (ctype_digit($keyword)) {
        $group->condition('n.nid', (int) $keyword);
      }
      $query->condition($group);
    }

    $category = (int) ($filters['category'] ?? 0);
    if ($category > 0 && $this->termInVocabulary($category, (string) $this->categoryVocabulary($type))) {
      $this->whereReferenceExists($query, 'field_categories', $category);
    }
    $tag = (int) ($filters['tag'] ?? 0);
    if ($tag > 0 && $this->termInVocabulary($tag, 'tags')) {
      $this->whereReferenceExists($query, 'field_tags', $tag);
    }
    $model = (int) ($filters['model'] ?? 0);
    if ($type === 'photo' && $model > 0 && $this->termInVocabulary($model, 'models')) {
      $this->whereReferenceExists($query, 'field_model', $model);
    }
    if (($filters['cover'] ?? '') === 'missing') {
      $this->whereReferenceMissing($query, self::COVER_FIELDS[$type]);
    }
    if (($filters['summary'] ?? '') === 'missing' && in_array($type, self::SUMMARY_TYPES, TRUE)) {
      $this->whereSummaryMissing($query);
    }
  }

  /**
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   Listing query.
   */
  private function whereReferenceExists($query, string $field, int $targetId): void {
    $table = 'node__' . $field;
    $column = $field . '_target_id';
    $subquery = $this->database->select($table, 'ref');
    $subquery->addExpression('1');
    $subquery->where("ref.entity_id = n.nid AND ref.deleted = 0 AND ref.$column = :target_id", [
      ':target_id' => $targetId,
    ]);
    $query->exists($subquery);
  }

  /**
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   Listing query.
   */
  private function whereReferenceMissing($query, string $field): void {
    $table = 'node__' . $field;
    $subquery = $this->database->select($table, 'ref');
    $subquery->addExpression('1');
    $subquery->where('ref.entity_id = n.nid AND ref.deleted = 0 AND ref.langcode = n.langcode');
    $query->notExists($subquery);
  }

  /**
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   Listing query.
   */
  private function whereSummaryMissing($query): void {
    $subquery = $this->database->select('node__field_summary', 'summary');
    $subquery->addExpression('1');
    $subquery->where("summary.entity_id = n.nid AND summary.deleted = 0 AND summary.langcode = n.langcode AND summary.field_summary_value <> ''");
    $query->notExists($subquery);
  }

  /**
   * @return array<string, mixed>
   *   Row for the content table.
   */
  private function contentRow(NodeInterface $node, AccountInterface $account, int $created): array {
    $state = $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
    $categories = $this->referenceLabels($node, 'field_categories');
    $tags = $this->referenceLabels($node, 'field_tags');
    $meta = array_values(array_filter([$categories, $tags]));
    $publishedFlag = $node->isPublished();
    $statePublished = $state === 'published';
    if ($state !== '' && $publishedFlag !== $statePublished) {
      $meta[] = (string) $this->t('发布标记与审核状态不一致');
    }
    $owner = $node->getOwner();
    return [
      'nid' => (int) $node->id(),
      'title' => (string) $node->label(),
      'thumb' => $this->thumbnailUrl($node),
      'state' => $state === '' ? (string) $this->t('无审核状态') : $this->stateLabel($state),
      'author' => $owner->isAnonymous() ? (string) $this->t('匿名') : $owner->getDisplayName(),
      'created' => $created,
      'meta' => implode(' · ', $meta),
      'langcode' => $node->language()->getId(),
      'edit_url' => $node->access('update', $account) ? $node->toUrl('edit-form')->toString() : NULL,
      'view_url' => $node->access('view', $account) ? $node->toUrl()->toString() : NULL,
    ];
  }

  /**
   * @return list<array{tid: int, name: string, status: int, usage: int}>
   *   Terms with default-language node counts.
   */
  private function termUsage(string $vocabulary): array {
    $field = self::VOCABULARIES[$vocabulary];
    $table = 'node__' . $field;
    $column = $field . '_target_id';
    $query = $this->database->select('taxonomy_term_field_data', 't');
    $query->fields('t', ['tid', 'name', 'status']);
    $query->condition('t.vid', $vocabulary);
    $query->condition('t.default_langcode', 1);
    $query->addExpression('COUNT(DISTINCT n.nid)', 'usage_count');
    $query->leftJoin($table, 'ref', "ref.$column = t.tid AND ref.deleted = 0");
    $query->leftJoin('node_field_data', 'n', 'n.nid = ref.entity_id AND n.langcode = ref.langcode AND n.default_langcode = 1');
    $query->groupBy('t.tid');
    $query->groupBy('t.name');
    $query->groupBy('t.status');
    $query->orderBy('usage_count', 'ASC');
    $query->orderBy('t.name', 'ASC');

    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        'tid' => (int) $row->tid,
        'name' => (string) $row->name,
        'status' => (int) $row->status,
        'usage' => (int) $row->usage_count,
      ];
    }
    return $rows;
  }

  /**
   * Default-language rows whose latest affected revision is still in review.
   */
  private function queueCount(): int {
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
    $query->condition('nfr.type', self::TYPES, 'IN');
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * @return list<array<string, mixed>>
   *   Recently changed default-language nodes.
   */
  private function recentEdits(AccountInterface $account, bool $unpublished): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid', 'title', 'type', 'changed', 'langcode']);
    $query->condition('n.default_langcode', 1);
    $query->condition('n.type', self::TYPES, 'IN');
    if (!$unpublished) {
      $query->condition('n.status', 1);
    }
    $query->orderBy('n.changed', 'DESC');
    $query->range(0, 8);
    $records = $query->execute()->fetchAll();

    $storage = $this->entityTypeManager->getStorage('node');
    $labels = $this->typeLabels();
    $rows = [];
    foreach ($records as $record) {
      $node = $storage->load((int) $record->nid);
      if (!$node instanceof NodeInterface || !$node->access('view', $account)) {
        continue;
      }
      $state = $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
      $rows[] = [
        'nid' => (int) $node->id(),
        'title' => (string) $record->title,
        'type_label' => $labels[(string) $record->type] ?? (string) $record->type,
        'state' => $state === '' ? '' : $this->stateLabel($state),
        'changed' => (int) $record->changed,
        'edit_url' => $node->access('update', $account) ? $node->toUrl('edit-form')->toString() : NULL,
        'view_url' => $node->toUrl()->toString(),
      ];
    }
    return $rows;
  }

  private function countMissingReference(string $type, string $field, bool $unpublished): int {
    $table = 'node__' . $field;
    $query = $this->database->select('node_field_data', 'n');
    $query->leftJoin($table, 'c', 'c.entity_id = n.nid AND c.deleted = 0 AND c.langcode = n.langcode');
    $query->isNull('c.entity_id');
    $query->condition('n.default_langcode', 1);
    $query->condition('n.type', $type);
    if (!$unpublished) {
      $query->condition('n.status', 1);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  private function countMissingSummary(string $type, bool $unpublished): int {
    $query = $this->database->select('node_field_data', 'n');
    $query->leftJoin('node__field_summary', 's', "s.entity_id = n.nid AND s.deleted = 0 AND s.langcode = n.langcode AND s.field_summary_value <> ''");
    $query->isNull('s.entity_id');
    $query->condition('n.default_langcode', 1);
    $query->condition('n.type', $type);
    if (!$unpublished) {
      $query->condition('n.status', 1);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  private function termInVocabulary(int $tid, string $vocabulary): bool {
    if ($vocabulary === '') {
      return FALSE;
    }
    $query = $this->database->select('taxonomy_term_field_data', 't');
    $query->addField('t', 'tid');
    $query->condition('t.tid', $tid);
    $query->condition('t.vid', $vocabulary);
    $query->condition('t.default_langcode', 1);
    $query->range(0, 1);
    return (bool) $query->execute()->fetchField();
  }

  private function referenceLabels(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    $labels = [];
    foreach ($node->get($field)->referencedEntities() as $entity) {
      if ($entity->access('view')) {
        $labels[] = (string) $entity->label();
      }
    }
    return implode('、', $labels);
  }

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

  /**
   * @return array<string, string>
   *   Bundle labels.
   */
  private function typeLabels(): array {
    $labels = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple(self::TYPES) as $type) {
      $labels[$type->id()] = (string) $type->label();
    }
    return $labels;
  }

  /**
   * @return array<string, string>
   *   State id to label.
   */
  private function stateLabels(): array {
    if ($this->stateLabels !== NULL) {
      return $this->stateLabels;
    }
    $this->stateLabels = [];
    $workflow = $this->entityTypeManager->getStorage('workflow')->load('xedc_editorial');
    if ($workflow instanceof WorkflowInterface) {
      foreach ($workflow->getTypePlugin()->getStates() as $id => $state) {
        $this->stateLabels[$id] = (string) $state->label();
      }
    }
    return $this->stateLabels;
  }

}
