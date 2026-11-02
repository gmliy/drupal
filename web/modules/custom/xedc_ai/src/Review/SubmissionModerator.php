<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\xedc_ai\Usage\AuditLog;

/**
 * Confirmed member-submission decisions. It does not edit the workflow.
 */
final class SubmissionModerator {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ModerationInformationInterface $moderation,
    private readonly AuditLog $audit,
    private readonly ReviewRepository $reviews,
    private readonly TimeInterface $time,
    private readonly Connection $database,
  ) {}

  /**
   * @return list<array<string, mixed>>
   *   Nodes currently waiting in the existing review state.
   */
  public function waiting(int $limit = 40): array {
    $latest = $this->database->select('node_field_revision', 'base');
    $latest->addField('base', 'nid');
    $latest->addField('base', 'langcode');
    $latest->addExpression('MAX(base.vid)', 'vid');
    $latest->groupBy('base.nid');
    $latest->groupBy('base.langcode');

    $query = $this->database->select('node_field_revision', 'nfr');
    $query->join($latest, 'latest', 'latest.nid = nfr.nid AND latest.langcode = nfr.langcode AND latest.vid = nfr.vid');
    $query->join('content_moderation_state_field_revision', 'cms', 'cms.content_entity_type_id = :entity_type AND cms.content_entity_revision_id = nfr.vid AND cms.langcode = nfr.langcode', [
      ':entity_type' => 'node',
    ]);
    $query->join('node_field_data', 'nfd', 'nfd.nid = nfr.nid AND nfd.langcode = nfr.langcode AND nfd.default_langcode = 1');
    $query->fields('nfr', ['nid', 'vid']);
    $query->condition('cms.moderation_state', 'needs_review');
    $query->condition('nfd.type', ['article', 'photo', 'video'], 'IN');
    $query->orderBy('nfr.changed', 'DESC');
    $query->range(0, 200);

    $storage = $this->entities->getStorage('node');
    $rows = [];
    foreach ($query->execute() as $record) {
      $revision = $storage->loadRevision((int) $record->vid);
      if (!$revision instanceof NodeInterface || !$this->isSubmission($revision)) {
        continue;
      }
      $node = $revision;
      if (count($rows) >= $limit) {
        break;
      }
      $review = $this->latestReview((int) $node->id());
      $owner = $node->getOwner();
      $rows[] = [
        'nid' => (int) $node->id(),
        'title' => (string) $node->label(),
        'bundle' => $node->bundle(),
        'author' => $owner ? (string) $owner->getDisplayName() : '',
        'uid' => (int) $node->getOwnerId(),
        'created' => (int) $node->getCreatedTime(),
        'changed' => (int) $node->getChangedTime(),
        'state' => (string) $node->get('moderation_state')->value,
        'gaps' => $this->gaps($node),
        'review_id' => $review ? (int) $review['id'] : 0,
        'review_status' => $review ? (string) $review['status'] : '',
        'risk_level' => $review ? (string) $review['risk_level'] : '',
      ];
    }
    return $rows;
  }

  /**
   * Applies one existing transition after an explicit confirmation.
   *
   * @return string|null
   *   Error text, or NULL when the node was saved.
   */
  public function apply(NodeInterface $node, string $action, string $reason, int $uid, bool $confirmed): ?string {
    if (!$confirmed) {
      return '需要管理员确认后才能变更投稿状态。';
    }
    $targets = [
      'approve' => 'published',
      'changes' => 'changes_requested',
      'reject' => 'rejected',
    ];
    if (!isset($targets[$action])) {
      return '未知的投稿操作。';
    }
    $reason = trim($reason);
    if ($action === 'reject' && $reason === '') {
      return '拒绝时必须填写原因。';
    }
    $node = $this->latest($node);
    if (!$node->hasField('moderation_state') || !$this->moderation->isModeratedEntity($node)) {
      return '内容没有启用审核工作流。';
    }
    $workflow = $this->moderation->getWorkflowForEntity($node);
    $from = (string) $node->get('moderation_state')->value;
    $to = $targets[$action];
    if ($workflow === NULL || !$workflow->getTypePlugin()->hasTransitionFromStateToState($from, $to)) {
      return '当前工作流不允许把「' . $from . '」改为「' . $to . '」。工作流配置没有被修改。';
    }
    $node->setNewRevision(TRUE);
    $node->setRevisionCreationTime($this->time->getRequestTime());
    $node->setRevisionUserId($uid);
    $node->setRevisionLogMessage($reason !== '' ? $reason : '管理员在 AI 运营中心确认后变更投稿状态。');
    $node->set('moderation_state', $to);
    $node->save();
    $this->audit->log('submission_' . $action, '投稿 ' . $node->id() . ' 从 ' . $from . ' 变为 ' . $to . '。', $uid, [
      'target_type' => 'node',
      'target_id' => (string) $node->id(),
      'before' => $from,
      'after' => $to,
      'suggestion' => $reason,
      'confirmed' => 1,
      'result' => 'saved',
    ]);
    return NULL;
  }

  /**
   * @return list<string>
   *   Missing fields. This does not hash files.
   */
  public function gaps(NodeInterface $node): array {
    $gaps = [];
    if (trim((string) $node->label()) === '') {
      $gaps[] = '标题为空';
    }
    $summary = $node->hasField('field_summary') ? trim((string) $node->get('field_summary')->value) : '';
    $body = $node->hasField('field_body') ? trim(strip_tags((string) $node->get('field_body')->value)) : '';
    if ($summary === '' && $body === '') {
      $gaps[] = '描述为空';
    }
    if ($node->bundle() === 'article' && $body === '') {
      $gaps[] = '正文为空';
    }
    if ($node->bundle() === 'photo' && $this->emptyFields($node, ['field_images', 'field_cover_image'])) {
      $gaps[] = '缺少图片';
    }
    if ($node->bundle() === 'video' && $this->emptyFields($node, ['field_video_file', 'field_video_url'])) {
      $gaps[] = '缺少视频';
    }
    $duration = $node->hasField('field_duration') ? (int) $node->get('field_duration')->value : -1;
    if ($node->bundle() === 'video' && ($duration < 1 || $duration > 86400)) {
      $gaps[] = '视频时长不合理';
    }
    return $gaps;
  }

  private function isSubmission(NodeInterface $node): bool {
    if ((int) $node->getOwnerId() < 1) {
      return FALSE;
    }
    $owner = $node->getOwner();
    if (!$owner instanceof UserInterface) {
      return FALSE;
    }
    $roles = $owner->getRoles(TRUE);
    return !in_array('administrator', $roles, TRUE) && !in_array('content_editor', $roles, TRUE);
  }

  private function latest(NodeInterface $node): NodeInterface {
    $storage = $this->entities->getStorage('node');
    $id = $storage->getLatestRevisionId((int) $node->id());
    if (is_numeric($id)) {
      $revision = $storage->loadRevision((int) $id);
      if ($revision instanceof NodeInterface) {
        return $revision;
      }
    }
    return $node;
  }

  /**
   * @return array<string, mixed>|null
   *   Newest review row.
   */
  private function latestReview(int $nid): ?array {
    $rows = $this->reviews->history('node', $nid, 1);
    return $rows[0] ?? NULL;
  }

  /**
   * @param list<string> $fields
   *   Field names that can satisfy the requirement.
   */
  private function emptyFields(NodeInterface $node, array $fields): bool {
    foreach ($fields as $name) {
      if ($node->hasField($name) && !$node->get($name)->isEmpty()) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
