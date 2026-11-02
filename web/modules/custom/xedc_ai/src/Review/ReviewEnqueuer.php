<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Provider\ModelRepository;
use Drupal\xedc_ai\Usage\AuditLog;

/**
 * Creates review rows and queue items. It does not call a model.
 */
final class ReviewEnqueuer {

  public function __construct(
    private readonly ReviewRepository $reviews,
    private readonly ModelRepository $models,
    private readonly TextExtractor $extractor,
    private readonly QueueFactory $queueFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModerationInformationInterface $moderation,
    private readonly AuditLog $audit,
    private readonly TimeInterface $time,
  ) {}

  public function onInsert(NodeInterface $node): void {
    if (!$node->isDefaultRevision() || !$node->isDefaultTranslation()) {
      return;
    }
    if (!in_array($node->bundle(), ['article', 'photo', 'video'], TRUE)) {
      return;
    }
    $settings = $this->configFactory->get('xedc_ai.settings');
    $submission = $this->isSubmission($node);
    $enabled = match ($node->bundle()) {
      'article' => (bool) $settings->get('auto_review_article'),
      'photo' => (bool) $settings->get('auto_review_photo'),
      'video' => (bool) $settings->get('auto_review_video'),
      default => FALSE,
    };
    if (!$enabled && !($submission && (bool) $settings->get('auto_review_submission'))) {
      return;
    }
    $this->request($node, (int) $node->getOwnerId(), 'content_review', 'quick', FALSE, FALSE);
  }

  /**
   * Queues a manual or automatic text review.
   */
  public function request(NodeInterface $node, int $uid, string $source, string $depth, bool $force, bool $overBudget, string $taskNote = ''): int {
    $settings = $this->configFactory->get('xedc_ai.settings');
    if ($depth === 'deep' && !(bool) $settings->get('deep_analysis')) {
      $depth = 'quick';
    }
    if (!in_array($depth, ['quick', 'deep', 'seo', 'translation'], TRUE)) {
      $depth = 'quick';
    }
    if (!in_array($source, ['content_review', 'seo_optimization', 'translation'], TRUE)) {
      $source = 'content_review';
    }
    if ($source === 'translation') {
      $depth = 'translation';
    }
    $fields = $this->extractor->extract($node);
    $profile = $this->models->forTask($this->taskFor($source, $depth));
    $modelId = $profile ? $profile->id : 0;
    $providerId = $profile ? $profile->providerId : '';
    $modelName = $profile ? $profile->modelName : '';
    $modelKey = $profile ? $profile->modelKey() : 'none';
    $hash = Fingerprint::fieldHash($fields);
    $rule = (int) $settings->get('rule_version');
    $fingerprint = Fingerprint::make('node', (int) $node->id(), (int) $node->getRevisionId(), (string) $fields['langcode'], $hash, $modelKey, $rule, $source, $depth);
    if (!$force) {
      $open = $this->reviews->findOpen($fingerprint);
      if ($open !== NULL) {
        return (int) $open['id'];
      }
    }
    [$state, $workflow] = $this->moderationSnapshot($node);
    $id = $this->reviews->create([
      'entity_type' => 'node',
      'entity_id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'langcode' => (string) $fields['langcode'],
      'bundle' => $node->bundle(),
      'fingerprint' => $fingerprint,
      'field_hash' => $hash,
      'model_id' => $modelId,
      'provider_id' => $providerId,
      'model_name' => $modelName,
      'rule_version' => $rule,
      'source' => $source,
      'depth' => $depth,
      'force_call' => $force,
      'allow_over_budget' => $overBudget,
      'is_submission' => $this->isSubmission($node),
      'moderation_state' => $state,
      'moderation_workflow' => $workflow,
      'title' => (string) $fields['title'],
      'task_note' => substr($taskNote, 0, 64),
      'uid' => $uid,
    ]);
    if ($profile === NULL) {
      $this->reviews->update($id, [
        'status' => 'failed',
        'error_code' => 'no_model',
        'error_message' => '没有可用的默认文本模型，未调用 API。',
      ]);
      return $id;
    }
    $this->queueFactory->get('xedc_ai_review')->createItem(['review_id' => $id]);
    $this->audit->log($force ? 'review_manual' : 'review_queue', '内容 ' . $node->id() . ' 进入审核队列 #' . $id . '。', $uid);
    return $id;
  }

  public function ignore(int $reviewId, int $uid): void {
    $this->reviews->update($reviewId, [
      'status' => 'ignored',
      'handled_status' => 'ignored',
      'handled_uid' => $uid,
      'handled_at' => $this->time->getRequestTime(),
    ]);
    $this->audit->log('review_ignore', '忽略审核 #' . $reviewId . '。', $uid);
  }

  public function cancel(int $reviewId, int $uid): bool {
    $row = $this->reviews->load($reviewId);
    if ($row === NULL || (string) $row['status'] !== 'pending') {
      return FALSE;
    }
    $this->reviews->update($reviewId, [
      'status' => 'cancelled',
      'error_code' => 'cancelled',
      'error_message' => '管理员取消了尚未执行的任务。',
      'handled_status' => 'ignored',
      'handled_uid' => $uid,
      'handled_at' => $this->time->getRequestTime(),
    ]);
    $this->dropQueue($reviewId);
    $this->audit->log('review_cancel', '取消尚未执行的审核 #' . $reviewId . '。', $uid, [
      'target_type' => 'review',
      'target_id' => (string) $reviewId,
      'confirmed' => 1,
      'result' => 'cancelled',
    ]);
    return TRUE;
  }

  public function confirm(int $reviewId, int $uid): void {
    $this->reviews->update($reviewId, [
      'handled_status' => 'confirmed',
      'handled_uid' => $uid,
      'handled_at' => $this->time->getRequestTime(),
    ]);
    $this->audit->log('review_confirm', '确认审核建议 #' . $reviewId . '。内容状态未改。', $uid);
  }

  private function taskFor(string $source, string $depth): string {
    if ($source === 'translation' || $depth === 'translation') {
      return 'translation';
    }
    if ($source === 'seo_optimization' || $depth === 'seo') {
      return 'seo_optimization';
    }
    if ($depth === 'deep') {
      return 'quality';
    }
    return 'content_review';
  }

  private function dropQueue(int $reviewId): void {
    $rows = $this->databaseItems($reviewId);
    foreach ($rows as $itemId) {
      $this->queueFactory->get('xedc_ai_review')->deleteItem((object) ['item_id' => $itemId]);
    }
  }

  /**
   * @return list<int>
   *   Queue item ids for one review.
   */
  private function databaseItems(int $reviewId): array {
    $connection = \Drupal::database();
    if (!$connection->schema()->tableExists('queue')) {
      return [];
    }
    $ids = [];
    $rows = $connection->select('queue', 'q')
      ->fields('q', ['item_id', 'data'])
      ->condition('name', 'xedc_ai_review')
      ->execute();
    foreach ($rows as $row) {
      $data = unserialize((string) $row->data, ['allowed_classes' => FALSE]);
      if (is_array($data) && (int) ($data['review_id'] ?? 0) === $reviewId) {
        $ids[] = (int) $row->item_id;
      }
    }
    return $ids;
  }

  private function isSubmission(NodeInterface $node): bool {
    if ((int) $node->getOwnerId() < 1) {
      return FALSE;
    }
    $roles = $node->getOwner()->getRoles(TRUE);
    return !in_array('administrator', $roles, TRUE) && !in_array('content_editor', $roles, TRUE);
  }

  /**
   * @return array{0: string, 1: string}
   *   Moderation state and workflow id. Empty when the node has no workflow.
   */
  private function moderationSnapshot(NodeInterface $node): array {
    if (!$node->hasField('moderation_state') || !$this->moderation->isModeratedEntity($node)) {
      return ['', ''];
    }
    $workflow = $this->moderation->getWorkflowForEntity($node);
    return [
      (string) $node->get('moderation_state')->value,
      $workflow ? (string) $workflow->id() : '',
    ];
  }

}
