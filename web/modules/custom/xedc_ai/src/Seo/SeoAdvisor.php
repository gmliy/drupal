<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Seo;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Usage\AuditLog;

/**
 * Explains saved SEO audits and applies a suggestion only after a safe check.
 */
final class SeoAdvisor {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ModuleHandlerInterface $modules,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AuditLog $audit,
    private readonly ModerationInformationInterface $moderation,
    private readonly StateTransitionValidationInterface $transitions,
  ) {}

  /**
   * @return array<string, mixed>
   *   Stored Site Audit summary. This does not run a new audit.
   */
  public function storedAudit(): array {
    if (!$this->entities->hasDefinition('site_audit_report')) {
      return [
        'available' => FALSE,
        'message' => '当前没有可读取的 Site Audit 报告。请在 Site Audit 页面生成报告后再回来查看解释。',
        'items' => [],
      ];
    }
    $storage = $this->entities->getStorage('site_audit_report');
    $idKey = $storage->getEntityType()->getKey('id');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort($idKey, 'DESC')->range(0, 1)->execute();
    $report = $ids ? $storage->load(reset($ids)) : NULL;
    if ($report === NULL) {
      return [
        'available' => FALSE,
        'message' => '还没有保存过 Site Audit 报告。这里不会为了打开页面重新扫描全站。',
        'items' => [],
      ];
    }
    $map = [
      'field_meta_tags' => '元数据',
      'field_image_alt' => '图片 alt',
      'field_url_alias' => 'URL 别名',
      'field_sitemap' => '站点地图',
      'field_translation' => '翻译',
      'field_cache_status' => '缓存',
    ];
    $items = [];
    foreach ($map as $field => $label) {
      if (!$report->hasField($field)) {
        continue;
      }
      $value = (string) $report->get($field)->value;
      $detailName = $field . '_detail';
      if ($field === 'field_meta_tags') {
        $detailName = 'field_meta_detail';
      }
      $detail = $report->hasField($detailName) ? trim(strip_tags((string) $report->get($detailName)->value)) : '';
      $items[] = [
        'label' => $label,
        'value' => $value !== '' ? $value : '无',
        'detail' => $detail,
        'meaning' => match ($value) {
          'pass' => '已保存的检查没有发现问题。',
          'warn' => '已保存的检查提示需要处理。',
          'fail' => '已保存的检查未通过。',
          default => '这是已保存的结果，不是一次新的扫描。',
        },
      ];
    }
    return [
      'available' => TRUE,
      'message' => '下面是最近一份已保存的 Site Audit 报告，不是重新执行的全站审计。',
      'items' => $items,
    ];
  }

  /**
   * Whether the editorial workflow can move this node from 已撤下 to 已发布.
   *
   * @return array{ok: bool, archived: bool, message: string, permission: string, targets: list<string>}
   *   ok is true only when a real transition exists.
   */
  public function publishRoute(NodeInterface $node): array {
    $empty = ['ok' => FALSE, 'archived' => FALSE, 'message' => '', 'permission' => '', 'targets' => []];
    if (!$node->hasField('moderation_state') || !$this->moderation->isModeratedEntity($node)) {
      return $empty;
    }
    $stateId = (string) $node->get('moderation_state')->value;
    $workflow = $this->moderation->getWorkflowForEntity($node);
    if ($workflow === NULL) {
      return $empty;
    }
    $targets = [];
    $transitionId = '';
    foreach ($workflow->getTypePlugin()->getTransitions() as $id => $transition) {
      $from = [];
      foreach ($transition->from() as $fromState) {
        $from[] = $fromState->id();
      }
      if (!in_array($stateId, $from, TRUE)) {
        continue;
      }
      $targets[] = (string) $transition->to()->label();
      if ($transition->to()->id() === 'published' && ($transitionId === '' || $id === 'republish')) {
        $transitionId = (string) $id;
      }
    }
    if ($stateId !== 'archived') {
      return ['ok' => FALSE, 'archived' => FALSE, 'message' => '', 'permission' => '', 'targets' => $targets];
    }
    if ($transitionId === '') {
      $list = $targets === [] ? '没有' : implode('、', $targets);
      return [
        'ok' => FALSE,
        'archived' => TRUE,
        'message' => '当前状态是已撤下。工作流「' . $workflow->label() . '」没有从已撤下到已发布的转换。从已撤下可以到达：' . $list . '。这里不会绕过工作流，也不会把内容公开。普通采纳仍会保持已撤下。',
        'permission' => '',
        'targets' => $targets,
      ];
    }
    return [
      'ok' => TRUE,
      'archived' => TRUE,
      'message' => '本次将应用选中的 SEO 建议。采纳成功后将执行正式的「重新发布」转换，内容将恢复公开。如果工作流权限不足或转换失败，内容不会自动公开。',
      'permission' => 'use ' . $workflow->id() . ' transition ' . $transitionId,
      'targets' => $targets,
    ];
  }

  /**
   * Returns a reason when saving the node could trigger content translation.
   */
  public function writeBlocked(NodeInterface $node): ?string {
    $targets = array_keys(array_filter((array) $this->configFactory->get('xedc_auto_translate.settings')->get('targets')));
    $languages = array_keys($node->getTranslationLanguages());
    $moderated = $node->hasField('moderation_state') && $this->moderation->isModeratedEntity($node);
    $state = $moderated ? (string) $node->get('moderation_state')->value : '';
    return self::blockedByTranslation($node->isPublished(), $moderated, $state, $languages, $targets);
  }

  /**
   * @param list<string> $languages
   *   Existing translation languages.
   * @param list<string> $targets
   *   Configured translation targets.
   */
  public static function blockedByTranslation(bool $published, bool $moderated, string $state, array $languages, array $targets): ?string {
    $live = $published && (!$moderated || $state === 'published');
    if (!$live) {
      return NULL;
    }
    foreach ($targets as $language) {
      if (!in_array($language, $languages, TRUE)) {
        return '内容已处于发布状态，并且还缺少自动翻译的目标语言。保存会走到现有翻译流程，所以这里不写入。请手工复制建议。';
      }
    }
    return NULL;
  }

  /**
   * Writes selected text fields. Returns an error message, or NULL on success.
   *
   * @param array<string, mixed> $result
   *   Stored SEO result.
   */
  public function apply(NodeInterface $node, array $result, bool $useTitle, bool $useSummary, int $uid): ?string {
    $keys = [];
    if ($useTitle) {
      $keys[] = 'title';
    }
    if ($useSummary) {
      $keys[] = 'summary';
    }
    $outcome = $this->applySelected($node, $result, $keys, $uid, 0, 0, FALSE, TRUE);
    return ($outcome['tally']['adopted'] ?? 0) > 0 ? NULL : $outcome['error'];
  }

  /**
   * Writes the selected suggestions. Returns the updated result for storage.
   *
   * @param array<string, mixed> $result
   *   Stored SEO result.
   * @param list<string> $keys
   *   Item keys to adopt or reject.
   *
   * @return array{error: ?string, result: array<string, mixed>, revision_id: int, tally: array<string, int>}
   *   error is set when nothing was saved. tally counts each item outcome.
   */
  public function applySelected(NodeInterface $node, array $result, array $keys, int $uid, int $expectedRevision, int $appliedRevision, bool $reject = FALSE, bool $confirmed = FALSE, int $reviewId = 0): array {
    $keys = array_values(array_unique(array_filter($keys, static fn ($key): bool => is_string($key) && $key !== '')));
    $currentRevision = (int) $node->getRevisionId();
    if (!$reject && !$confirmed) {
      return $this->outcome('需要人工确认后才能写入。内容没有修改。', $result, $currentRevision);
    }
    if ($keys === []) {
      return $this->outcome('请至少选择一条要处理的建议。', $result, $currentRevision);
    }
    if ($expectedRevision > 0 && $currentRevision !== $expectedRevision && $currentRevision !== $appliedRevision) {
      $result = $this->mark($result, $keys, 'conflict', $uid, (int) $node->id(), $currentRevision, $reviewId);
      $this->audit->log('seo_conflict', '内容 ' . $node->id() . ' 的修订已从 ' . $expectedRevision . ' 变为 ' . $currentRevision . '，未覆盖。', $uid, [
        'target_type' => 'node',
        'target_id' => (string) $node->id(),
        'suggestion' => implode("\n", $keys),
        'confirmed' => 1,
        'result' => 'conflict',
      ]);
      return $this->outcome('内容修订版本已从 ' . $expectedRevision . ' 变为 ' . $currentRevision . '，未覆盖新版本。', $result, $currentRevision, ['conflict' => count($keys)]);
    }
    if (!$reject && !$node->access('update')) {
      return $this->outcome('没有编辑这个内容的权限，未写入。', $result, $currentRevision);
    }
    if ($reject) {
      $eligible = $this->keysInState($result, $keys, ['pending', 'failed', '']);
      if ($eligible === []) {
        return $this->outcome('没有可以忽略的待处理建议。已采纳的建议请使用恢复原值。', $result, $currentRevision);
      }
      $result = $this->mark($result, $eligible, 'rejected', $uid, (int) $node->id(), $currentRevision, $reviewId);
      $this->audit->log('seo_reject', '已拒绝内容 ' . $node->id() . ' 的部分 SEO 建议。内容未改。', $uid, [
        'target_type' => 'node',
        'target_id' => (string) $node->id(),
        'suggestion' => implode("\n", $eligible),
        'confirmed' => 1,
        'result' => 'rejected',
      ]);
      return $this->outcome(NULL, $result, $currentRevision, ['rejected' => count($eligible)]);
    }
    $blocked = $this->writeBlocked($node);
    if ($blocked !== NULL) {
      return $this->outcome($blocked, $result, $currentRevision);
    }
    $items = $this->selected($result, $keys);
    $actionable = [];
    $already = 0;
    $ignored = 0;
    foreach ($items as $item) {
      $state = (string) ($item['state'] ?? 'pending');
      if ($state === 'adopted') {
        $already++;
        continue;
      }
      if ($state === 'rejected') {
        $ignored++;
        continue;
      }
      $actionable[] = $item;
    }
    if ($actionable === []) {
      if ($already > 0) {
        return $this->outcome('所选建议已经采纳，不会重复写入。', $result, $currentRevision, ['skipped' => $already]);
      }
      if ($ignored > 0) {
        return $this->outcome('所选建议已忽略，不能再采纳。', $result, $currentRevision);
      }
      return $this->outcome('请至少选择一条要处理的建议。', $result, $currentRevision);
    }
    $status = (int) $node->isPublished();
    $state = $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
    $before = [];
    $after = [];
    $ready = [];
    $failed = [];
    $failMessages = [];
    $nodeChanged = FALSE;
    foreach ($actionable as $item) {
      $key = (string) $item['key'];
      $suggested = trim((string) $item['suggested']);
      if (str_starts_with($key, 'alt:')) {
        $ready[] = $item;
        continue;
      }
      if ($key === 'title' && $suggested !== '') {
        $before[] = '标题：' . $node->label();
        $node->setTitle($suggested);
        $after[] = '标题：' . $suggested;
        $ready[] = $item;
        $nodeChanged = TRUE;
        continue;
      }
      if ($key === 'summary') {
        if (!$node->hasField('field_summary')) {
          $failed[] = $key;
          $failMessages[] = '这个内容类型没有简介字段，未写入。';
          continue;
        }
        $before[] = '简介：' . trim((string) $node->get('field_summary')->value);
        $node->set('field_summary', $suggested);
        $after[] = '简介：' . $suggested;
        $ready[] = $item;
        $nodeChanged = TRUE;
        continue;
      }
      if ($key === 'tags') {
        $names = is_array($item['tag_names'] ?? NULL) && $item['tag_names'] !== [] ? $item['tag_names'] : SeoResult::splitTerms($suggested);
        $attached = $this->attachExistingTags($node, $names);
        if ($attached['error'] !== NULL) {
          $failed[] = $key;
          $failMessages[] = (string) $attached['error'];
          continue;
        }
        $before[] = '标签：' . (string) $item['current'];
        $after[] = '标签：' . implode('、', $attached['names']);
        $ready[] = $item;
        $nodeChanged = TRUE;
        continue;
      }
      $failed[] = $key;
      $failMessages[] = '这条建议没有对应的可写入字段。';
    }
    $savedRevision = $currentRevision;
    $nodeKeys = [];
    foreach ($ready as $item) {
      if (!str_starts_with((string) $item['key'], 'alt:')) {
        $nodeKeys[] = (string) $item['key'];
      }
    }
    if ($nodeChanged) {
      $node->set('status', $status);
      if ($state !== '' && $node->hasField('moderation_state')) {
        $node->set('moderation_state', $state);
      }
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId($uid);
      $node->setRevisionLogMessage('SEO 建议经人工确认后写入。');
      try {
        $node->save();
      }
      catch (\Throwable $exception) {
        $failed = array_values(array_unique(array_merge($failed, $nodeKeys)));
        $result = $this->mark($result, $failed, 'failed', $uid, (int) $node->id(), $currentRevision, $reviewId);
        $failMessages[] = '写入失败，内容未标记为已采纳。' . $exception->getMessage();
        return $this->outcome(implode(' ', array_unique($failMessages)), $result, $currentRevision, ['failed' => count($failed), 'skipped' => $already]);
      }
      $savedRevision = (int) $node->getRevisionId();
      $result = $this->mark($result, $nodeKeys, 'adopted', $uid, (int) $node->id(), $savedRevision, $reviewId);
      $result['applied_revision_id'] = $savedRevision;
      $kept = $this->restoreWorkflow($node, $status, $state);
      if ($kept !== NULL) {
        $failMessages[] = $kept;
      }
    }
    $adopted = count($nodeKeys);
    foreach ($ready as $item) {
      if (!str_starts_with((string) $item['key'], 'alt:')) {
        continue;
      }
      $altError = $this->writeAlt($node, $item, $uid);
      if ($altError !== NULL) {
        $failed[] = (string) $item['key'];
        $failMessages[] = $altError;
        $result = $this->mark($result, [(string) $item['key']], 'failed', $uid, (int) $node->id(), $savedRevision, $reviewId);
        continue;
      }
      $before[] = 'ALT ' . (int) $item['media_id'] . '：' . (string) $item['current'];
      $after[] = 'ALT ' . (int) $item['media_id'] . '：' . (string) $item['suggested'];
      $others = $this->otherMediaUses((int) ($item['media_id'] ?? 0), (int) $node->id());
      if ($others > 0) {
        $after[] = '这张图片还被其他 ' . $others . ' 个内容引用，那些内容也会看到新的 ALT。';
      }
      $result = $this->mark($result, [(string) $item['key']], 'adopted', $uid, (int) $node->id(), $savedRevision, $reviewId);
      $adopted++;
    }
    if ($failed !== []) {
      $result = $this->mark($result, $failed, 'failed', $uid, (int) $node->id(), $savedRevision, $reviewId);
    }
    $tally = [
      'adopted' => $adopted,
      'failed' => count(array_unique($failed)),
      'skipped' => $already,
    ];
    if ($adopted === 0) {
      $message = $failMessages !== [] ? implode(' ', array_unique($failMessages)) : '请至少选择一条要处理的建议。';
      return $this->outcome($message, $result, $savedRevision, $tally);
    }
    $result['applied_revision_id'] = $savedRevision;
    $this->audit->log('seo_apply', '已按确认写入内容 ' . $node->id() . '。发布状态未改。', $uid, [
      'target_type' => 'node',
      'target_id' => (string) $node->id(),
      'before' => implode("\n", $before),
      'after' => implode("\n", $after),
      'suggestion' => implode("\n", $after),
      'confirmed' => 1,
      'result' => $failed === [] ? 'saved' : 'partial',
    ]);
    $warning = $failMessages === [] ? NULL : implode(' ', array_unique($failMessages));
    return $this->outcome($warning, $result, $savedRevision, $tally);
  }

  /**
   * @param array<string, mixed> $result
   *   Stored result.
   *
   * @return list<array<string, mixed>>
   *   Applicable items for the requested keys.
   */
  private function selected(array $result, array $keys): array {
    $rows = [];
    foreach (SeoResult::itemRows($result) as $item) {
      if (!in_array((string) ($item['key'] ?? ''), $keys, TRUE) || empty($item['applicable'])) {
        continue;
      }
      if (trim((string) ($item['suggested'] ?? '')) === '') {
        continue;
      }
      $rows[] = $item;
    }
    if ($rows === [] && in_array('title', $keys, TRUE) && trim((string) ($result['seo_title'] ?? '')) !== '') {
      $rows[] = ['key' => 'title', 'suggested' => (string) $result['seo_title'], 'current' => '', 'applicable' => TRUE, 'media_id' => 0];
    }
    if ($rows === [] && in_array('summary', $keys, TRUE) && trim((string) ($result['meta_description'] ?? '')) !== '') {
      $rows[] = ['key' => 'summary', 'suggested' => (string) $result['meta_description'], 'current' => '', 'applicable' => TRUE, 'media_id' => 0];
    }
    elseif (in_array('summary', $keys, TRUE) && !$this->hasItem($rows, 'summary') && trim((string) ($result['meta_description'] ?? '')) !== '') {
      $rows[] = ['key' => 'summary', 'suggested' => (string) $result['meta_description'], 'current' => '', 'applicable' => TRUE, 'media_id' => 0];
    }
    if (in_array('title', $keys, TRUE) && !$this->hasItem($rows, 'title') && trim((string) ($result['seo_title'] ?? '')) !== '') {
      $rows[] = ['key' => 'title', 'suggested' => (string) $result['seo_title'], 'current' => '', 'applicable' => TRUE, 'media_id' => 0];
    }
    return $rows;
  }

  /**
   * @param list<array<string, mixed>> $rows
   *   Selected items.
   */
  private function hasItem(array $rows, string $key): bool {
    foreach ($rows as $row) {
      if ((string) ($row['key'] ?? '') === $key) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $item
   *   ALT suggestion.
   */
  private function writeAlt(NodeInterface $node, array $item, int $uid, bool $protect = TRUE): ?string {
    $mid = (int) ($item['media_id'] ?? 0);
    if ($mid < 1 || !$this->referencesMedia($node, $mid)) {
      return 'ALT 建议没有对应到这个内容上的图片，未写入。';
    }
    $media = $this->entities->getStorage('media')->load($mid);
    if (!$media instanceof MediaInterface || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return '图片媒体不存在，ALT 未写入。';
    }
    if (!$media->access('update')) {
      return '没有修改这张图片 ALT 的权限。';
    }
    $suggested = trim((string) ($item['suggested'] ?? ''));
    if ($suggested === '' && $protect) {
      return 'ALT 建议是空的，未写入。';
    }
    $image = $media->get('field_media_image');
    $current = $image->alt;
    $file = $image->entity;
    if ($protect) {
      $check = AltInspector::inspect([[
        'mid' => $mid,
        'alt' => $current,
        'filename' => $file !== NULL && method_exists($file, 'getFilename') ? (string) $file->getFilename() : '',
        'label' => (string) $media->label(),
      ]], '', 'article');
      $row = $check['rows'][0] ?? [];
      if (!empty($row['keep'])) {
        return '这张图片已有有效 ALT，未覆盖。';
      }
      if (trim((string) $current) === $suggested) {
        return '建议和当前 ALT 相同，未重复写入。';
      }
    }
    $media->get('field_media_image')->alt = $suggested;
    $media->setRevisionUserId($uid);
    $media->save();
    return NULL;
  }

  private function otherMediaUses(int $mid, int $nid): int {
    if ($mid < 1) {
      return 0;
    }
    $database = \Drupal::database();
    $uses = 0;
    foreach (['field_images', 'field_cover_image', 'field_cover', 'field_video_cover'] as $field) {
      $table = 'node__' . $field;
      if (!$database->schema()->tableExists($table)) {
        continue;
      }
      $uses += (int) $database->select($table, 't')
        ->condition($field . '_target_id', $mid)
        ->condition('entity_id', $nid, '<>')
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    return $uses;
  }

  private function referencesMedia(NodeInterface $node, int $mid): bool {
    foreach (['field_images', 'field_cover_image', 'field_cover', 'field_video_cover'] as $name) {
      if (!$node->hasField($name)) {
        continue;
      }
      foreach ($node->get($name) as $item) {
        if ((int) $item->target_id === $mid) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * @return array{error: ?string, names: list<string>}
   *   Existing terms that were attached.
   */
  private function attachExistingTags(NodeInterface $node, array $names): array {
    if (!$node->hasField('field_tags')) {
      return ['error' => '这个内容类型没有标签字段，未写入。', 'names' => []];
    }
    $names = SeoResult::splitTerms($names);
    $storage = $this->entities->getStorage('taxonomy_term');
    $ids = array_map('intval', array_column($node->get('field_tags')->getValue(), 'target_id'));
    $kept = [];
    $missing = [];
    foreach ($names as $name) {
      $name = trim($name);
      if ($name === '') {
        continue;
      }
      $found = $storage->loadByProperties(['vid' => 'tags', 'name' => $name]);
      $term = $found ? reset($found) : NULL;
      if ($term === NULL || $term === FALSE) {
        $missing[] = $name;
        continue;
      }
      $ids[] = (int) $term->id();
      $kept[] = $name;
    }
    if ($kept === []) {
      return ['error' => '标签在词表中不存在，未自动创建：' . implode('、', $missing), 'names' => []];
    }
    $node->set('field_tags', array_map(static fn (int $id): array => ['target_id' => $id], array_values(array_unique($ids))));
    if ($missing !== []) {
      $kept[] = '未创建：' . implode('、', $missing);
    }
    return ['error' => NULL, 'names' => $kept];
  }

  private function restoreWorkflow(NodeInterface $node, int $status, string $state): ?string {
    $published = (int) $node->isPublished();
    $current = $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
    if ($published === $status && ($state === '' || $current === $state)) {
      return NULL;
    }
    $node->set('status', $status);
    if ($state !== '' && $node->hasField('moderation_state')) {
      $node->set('moderation_state', $state);
    }
    try {
      $node->save();
    }
    catch (\Throwable) {
      return '写入后发布状态发生变化，且恢复失败。请检查修订版本。';
    }
    $published = (int) $node->isPublished();
    $current = $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
    if ($published !== $status || ($state !== '' && $current !== $state)) {
      return '写入后发布状态或工作流被改变，已尝试恢复。请人工核对修订版本。';
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $result
   *   Stored result.
   * @param list<string> $keys
   *   Keys to mark.
   *
   * @return array<string, mixed>
   *   Updated result.
   */
  private function mark(array $result, array $keys, string $state, int $uid = 0, int $entityId = 0, int $revisionId = 0, int $reviewId = 0): array {
    if (!isset($result['items']) || !is_array($result['items'])) {
      $result['items'] = [];
    }
    $now = time();
    foreach ($result['items'] as $index => $item) {
      if (!is_array($item) || !in_array((string) ($item['key'] ?? ''), $keys, TRUE)) {
        continue;
      }
      $item['state'] = $state;
      $item['entity_id'] = $entityId > 0 ? $entityId : (int) ($item['entity_id'] ?? 0);
      $item['revision_id'] = $revisionId > 0 ? $revisionId : (int) ($item['revision_id'] ?? 0);
      $item['review_id'] = $reviewId > 0 ? $reviewId : (int) ($item['review_id'] ?? 0);
      $item['acted_uid'] = $uid;
      $item['acted_at'] = $now;
      $result['items'][$index] = $item;
    }
    return $result;
  }

  /**
   * Writes the stored original values back for suggestions that were adopted.
   *
   * @param array<string, mixed> $result
   *   Stored SEO result.
   * @param list<string> $keys
   *   Item keys.
   *
   * @return array{error: ?string, result: array<string, mixed>, revision_id: int}
   *   error is set when nothing was restored.
   */
  public function restoreSelected(NodeInterface $node, array $result, array $keys, int $uid, int $expectedRevision, int $appliedRevision, bool $confirmed, int $reviewId = 0): array {
    $keys = array_values(array_unique(array_filter($keys, static fn ($key): bool => is_string($key) && $key !== '')));
    $currentRevision = (int) $node->getRevisionId();
    if (!$confirmed) {
      return $this->outcome('需要人工确认后才能恢复原值。内容没有修改。', $result, $currentRevision);
    }
    if ($expectedRevision > 0 && $currentRevision !== $expectedRevision && $currentRevision !== $appliedRevision) {
      $result = $this->mark($result, $keys, 'conflict', $uid, (int) $node->id(), $currentRevision, $reviewId);
      $this->audit->log('seo_conflict', '内容 ' . $node->id() . ' 的修订已从 ' . $expectedRevision . ' 变为 ' . $currentRevision . '，未恢复。', $uid, [
        'target_type' => 'node',
        'target_id' => (string) $node->id(),
        'confirmed' => 1,
        'result' => 'conflict',
      ]);
      return $this->outcome('内容修订版本已从 ' . $expectedRevision . ' 变为 ' . $currentRevision . '，未恢复旧建议。', $result, $currentRevision, ['conflict' => count($keys)]);
    }
    if (!$node->access('update')) {
      return $this->outcome('没有编辑这个内容的权限，未恢复。', $result, $currentRevision);
    }
    $blocked = $this->writeBlocked($node);
    if ($blocked !== NULL) {
      return $this->outcome($blocked, $result, $currentRevision);
    }
    $items = [];
    foreach (SeoResult::itemRows($result) as $item) {
      if (!in_array((string) ($item['key'] ?? ''), $keys, TRUE) || (string) ($item['state'] ?? '') !== 'adopted') {
        continue;
      }
      $items[] = $item;
    }
    if ($items === []) {
      return $this->outcome('所选建议还没有采纳，没有可恢复的原值。', $result, $currentRevision);
    }
    $status = (int) $node->isPublished();
    $state = $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
    $before = [];
    $after = [];
    $changed = FALSE;
    $restored = [];
    foreach ($items as $item) {
      $key = (string) $item['key'];
      $original = (string) ($item['current'] ?? '');
      if ($key === 'title') {
        $before[] = '标题：' . $node->label();
        $node->setTitle($original);
        $after[] = '标题：' . $original;
        $changed = TRUE;
        $restored[] = $key;
      }
      elseif ($key === 'tags' && $node->hasField('field_tags')) {
        $names = SeoResult::splitTerms($original);
        $storage = $this->entities->getStorage('taxonomy_term');
        $ids = [];
        $kept = [];
        foreach ($names as $name) {
          $found = $storage->loadByProperties(['vid' => 'tags', 'name' => $name]);
          $term = $found ? reset($found) : NULL;
          if ($term) {
            $ids[] = (int) $term->id();
            $kept[] = $name;
          }
        }
        $currentTags = [];
        foreach ($node->get('field_tags') as $tagItem) {
          if ($tagItem->entity !== NULL) {
            $currentTags[] = (string) $tagItem->entity->label();
          }
        }
        $before[] = '标签：' . implode('、', $currentTags);
        $node->set('field_tags', array_map(static fn (int $id): array => ['target_id' => $id], array_values(array_unique($ids))));
        $after[] = '标签：' . implode('、', $kept);
        $changed = TRUE;
        $restored[] = $key;
      }
      elseif ($key === 'summary' && $node->hasField('field_summary')) {
        $before[] = '简介：' . trim((string) $node->get('field_summary')->value);
        $node->set('field_summary', $original);
        $after[] = '简介：' . $original;
        $changed = TRUE;
        $restored[] = $key;
      }
      elseif (str_starts_with($key, 'alt:')) {
        $copy = $item;
        $copy['suggested'] = $original;
        $altError = $this->writeAlt($node, $copy, $uid, FALSE);
        if ($altError !== NULL) {
          $result = $this->mark($result, [$key], 'failed', $uid, (int) $node->id(), $currentRevision, $reviewId);
          return $this->outcome($altError, $result, $currentRevision);
        }
        $before[] = 'ALT ' . (int) $item['media_id'] . '：' . (string) $item['suggested'];
        $after[] = 'ALT ' . (int) $item['media_id'] . '：' . $original;
        $restored[] = $key;
      }
    }
    if ($changed) {
      $node->set('status', $status);
      if ($state !== '' && $node->hasField('moderation_state')) {
        $node->set('moderation_state', $state);
      }
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId($uid);
      $node->setRevisionLogMessage('恢复 SEO 采纳前的原值。');
      try {
        $node->save();
      }
      catch (\Throwable $exception) {
        $result = $this->mark($result, $restored, 'failed', $uid, (int) $node->id(), $currentRevision, $reviewId);
        return $this->outcome('恢复失败，内容未改。' . $exception->getMessage(), $result, $currentRevision);
      }
      $currentRevision = (int) $node->getRevisionId();
      $kept = $this->restoreWorkflow($node, $status, $state);
      if ($kept !== NULL) {
        return $this->outcome($kept, $result, $currentRevision);
      }
    }
    $result = $this->mark($result, $restored, 'pending', $uid, (int) $node->id(), $currentRevision, $reviewId);
    $result['applied_revision_id'] = $currentRevision;
    $this->audit->log('seo_restore', '已恢复内容 ' . $node->id() . ' 的采纳前原值。发布状态未改。', $uid, [
      'target_type' => 'node',
      'target_id' => (string) $node->id(),
      'before' => implode("\n", $before),
      'after' => implode("\n", $after),
      'confirmed' => 1,
      'result' => 'restored',
    ]);
    return $this->outcome(NULL, $result, $currentRevision, ['restored' => count($restored)]);
  }

  /**
   * @param array<string, mixed> $result
   *   Stored result.
   * @param list<string> $keys
   *   Requested keys.
   * @param list<string> $states
   *   States that can be handled. Empty string means pending.
   *
   * @return list<string>
   *   Matching keys.
   */
  private function keysInState(array $result, array $keys, array $states): array {
    $matched = [];
    foreach (SeoResult::itemRows($result) as $item) {
      $key = (string) ($item['key'] ?? '');
      if ($key === '' || !in_array($key, $keys, TRUE) || empty($item['applicable'])) {
        continue;
      }
      $state = (string) ($item['state'] ?? 'pending');
      if (in_array($state, $states, TRUE) && !in_array($key, $matched, TRUE)) {
        $matched[] = $key;
      }
    }
    return $matched;
  }

  /**
   * @param array<string, mixed> $result
   *   Stored result.
   *
   * @return array{error: ?string, result: array<string, mixed>, revision_id: int}
   *   Failed outcome.
   */
  /**
   * Applies the archived → published workflow transition after suggestions were saved.
   *
   * The moderation state is set to the transition target only after
   * StateTransitionValidation accepts it. Publication is not forced with
   * setPublished() or a direct status write.
   *
   * @return array{ok: bool, message: string}
   *   ok is true only when the default revision is published.
   */
  public function republish(NodeInterface $node, AccountInterface $account, int $reviewId, int $expectedRevision, string $suggestions = ''): array {
    $from = $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
    $workflow = $this->moderation->isModeratedEntity($node) ? $this->moderation->getWorkflowForEntity($node) : NULL;
    $plugin = $workflow?->getTypePlugin();
    $transitionOk = $plugin !== NULL && $plugin->hasTransition('republish');
    $transition = $transitionOk ? $plugin->getTransition('republish') : NULL;
    if ($transition !== NULL) {
      $fromIds = array_map(static fn ($state): string => $state->id(), $transition->from());
      $transitionOk = in_array('archived', $fromIds, TRUE) && $transition->to()->id() === 'published';
    }
    $permission = $workflow !== NULL ? 'use ' . $workflow->id() . ' transition republish' : '';
    $allowed = $transitionOk && $permission !== '' && $account->hasPermission($permission) && $workflow !== NULL && $this->transitions->isTransitionValid($workflow, $plugin->getState('archived'), $transition->to(), $account, $node);
    $decision = SeoResult::republishDecision($from, 1, 0, 0, $allowed, $transitionOk);
    $log = function (string $result, string $message) use ($account, $node, $reviewId, $suggestions, $from): void {
      $this->audit->log('seo_republish', $message, (int) $account->id(), [
        'target_type' => 'node',
        'target_id' => (string) $node->id(),
        'suggestion' => $suggestions,
        'before' => $from,
        'after' => $result === 'republished' ? 'published' : $from,
        'confirmed' => 1,
        'result' => $result,
      ]);
    };
    if ($expectedRevision > 0 && (int) $node->getRevisionId() !== $expectedRevision) {
      $message = '内容修订已变化，没有重新发布。内容保持已撤下。';
      $log('conflict', '内容 ' . $node->id() . ' 分析 ' . $reviewId . '：' . $message);
      return ['ok' => FALSE, 'message' => $message];
    }
    if ($decision !== 'republish') {
      $message = match ($decision) {
        'denied' => '没有重新发布权限，内容保持已撤下。已经写入的建议仍然保留。',
        'no_transition' => '工作流没有从已撤下到已发布的转换，内容保持已撤下。',
        default => '内容已经不是已撤下，没有重新发布。',
      };
      $log($decision === 'denied' ? 'denied' : 'skipped', '内容 ' . $node->id() . ' 分析 ' . $reviewId . '：' . $message);
      return ['ok' => FALSE, 'message' => $message];
    }
    $node->set('moderation_state', $transition->to()->id());
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) $account->id());
    $node->setRevisionLogMessage('SEO 建议确认后，使用工作流转换「重新发布」。分析 ' . $reviewId . '。');
    try {
      $node->save();
    }
    catch (\Throwable $exception) {
      $message = '工作流转换失败，内容保持已撤下。' . $exception->getMessage();
      $log('failed', '内容 ' . $node->id() . ' 分析 ' . $reviewId . '：' . $message);
      return ['ok' => FALSE, 'message' => $message];
    }
    $this->entities->getStorage('node')->resetCache([(int) $node->id()]);
    $fresh = $this->entities->getStorage('node')->load((int) $node->id());
    $state = $fresh instanceof NodeInterface && $fresh->hasField('moderation_state') ? (string) $fresh->get('moderation_state')->value : '';
    $published = $fresh instanceof NodeInterface && $fresh->isPublished();
    $revision = $fresh instanceof NodeInterface ? (int) $fresh->getRevisionId() : 0;
    if (!$published || $state !== 'published' || $revision < (int) $node->getRevisionId()) {
      $message = '工作流没有把内容改为已发布，内容没有公开。';
      $log('failed', '内容 ' . $node->id() . ' 分析 ' . $reviewId . '：' . $message);
      return ['ok' => FALSE, 'message' => $message];
    }
    $log('republished', '内容 ' . $node->id() . ' 分析 ' . $reviewId . ' 已从已撤下重新发布。修订 ' . $revision . '。');
    return ['ok' => TRUE, 'message' => '已通过工作流「重新发布」公开。'];
  }

  private function outcome(?string $error, array $result, int $revisionId, array $tally = []): array {
    $tally += ['adopted' => 0, 'rejected' => 0, 'conflict' => 0, 'failed' => 0, 'skipped' => 0, 'restored' => 0];
    return ['error' => $error, 'result' => $result, 'revision_id' => $revisionId, 'tally' => $tally];
  }

}
