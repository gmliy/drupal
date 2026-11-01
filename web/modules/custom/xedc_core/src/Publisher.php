<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Publishes one reviewed photo, or rolls a cover back.
 */
class Publisher {

  public function __construct(
    protected TaskRepository $tasks,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModerationInformationInterface $moderationInformation,
    protected Connection $database,
    protected AltManager $alt,
  ) {}

  /**
   * Publishes one task. Failures are returned and do not throw.
   *
   * @return array{ok: bool, message: string}
   *   Result for the publish center.
   */
  public function publish(int $taskId, AccountInterface $account): array {
    $transaction = $this->database->startTransaction();
    try {
      $status = $this->database->query('SELECT status FROM {xedc_import_task} WHERE id = :id FOR UPDATE', [
        ':id' => $taskId,
      ])->fetchField();
      if ($status === FALSE) {
        throw new ImportException('任务不存在。');
      }
      if ($status === ImportStatus::PUBLISHED) {
        return ['ok' => FALSE, 'message' => '已经发布过，未重复发布。'];
      }
      if ($status !== ImportStatus::REVIEW) {
        throw new ImportException('只有导入完成并待审核的任务可以发布。');
      }
      $task = $this->tasks->load($taskId);
      $node = $this->node((int) $task->nid);
      $this->assertReady($task, $node, $account);
      $node->xedc_import_internal = TRUE;
      if ($node->hasField('moderation_state')) {
        $node->set('moderation_state', 'published');
      }
      $node->setPublished();
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId((int) $account->id());
      $node->setRevisionCreationTime(time());
      $node->setRevisionLogMessage('图片批量发布');
      $node->save();
      $this->tasks->update($taskId, [
        'status' => ImportStatus::PUBLISHED,
        'stage' => ImportStatus::PUBLISHED,
        'progress' => 100,
        'published_uid' => (int) $account->id(),
        'published_at' => time(),
        'message' => '已发布',
      ]);
      $this->tasks->log($taskId, 'info', '已发布。操作人 uid ' . $account->id() . '。');
      return ['ok' => TRUE, 'message' => '已发布'];
    }
    catch (ImportException $e) {
      $transaction->rollBack();
      return ['ok' => FALSE, 'message' => $e->getMessage()];
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      return ['ok' => FALSE, 'message' => '发布失败：' . $e->getMessage()];
    }
  }

  /**
   * Picks a new cover from the gallery. Title edits do not do this.
   */
  public function rerollCover(int $taskId, AccountInterface $account): void {
    $task = $this->requireTask($taskId, $account);
    $node = $this->node((int) $task->nid);
    $mids = array_map('intval', array_column($node->get('field_images')->getValue(), 'target_id'));
    $mids = array_values(array_filter($mids));
    if (!$mids) {
      throw new ImportException('没有可以选择的图片。');
    }
    $pick = $mids[random_int(0, count($mids) - 1)];
    $node->xedc_import_internal = TRUE;
    $node->set('field_cover_image', ['target_id' => $pick]);
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('重新随机选择封面');
    $node->save();
    $this->tasks->unlockCover((int) $node->id());
    $this->tasks->log($taskId, 'info', '已重新随机选择封面。');
  }

  /**
   * Restores the images from before a replacement.
   */
  public function rollback(int $taskId, AccountInterface $account): void {
    $task = $this->requireTask($taskId, $account);
    if ((string) $task->mode !== 'replace' || !(int) $task->replaced) {
      throw new ImportException('没有可回滚的替换。');
    }
    $snapshot = json_decode((string) $task->snapshot, TRUE);
    if (!is_array($snapshot)) {
      throw new ImportException('找不到替换前的图片记录。');
    }
    $node = $this->node((int) $task->nid);
    $images = array_values(array_filter(array_map('intval', $snapshot['images'] ?? [])));
    $node->xedc_import_internal = TRUE;
    $node->set('field_images', array_map(static fn(int $mid): array => ['target_id' => $mid], $images));
    $cover = (int) ($snapshot['cover'] ?? 0);
    if ($cover) {
      $node->set('field_cover_image', ['target_id' => $cover]);
    }
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('回滚图片替换');
    $node->save();
    $this->alt->refresh($node);
    $this->tasks->update($taskId, [
      'replaced' => 0,
      'message' => '已回滚到替换前的图片',
    ]);
    $this->tasks->log($taskId, 'info', '已回滚到替换前的图片。新图片文件已保留，未删除。');
  }

  /**
   * Checks the task, the photo and the account before publication.
   */
  private function assertReady(object $task, NodeInterface $node, AccountInterface $account): void {
    if ((int) $task->failed_count > 0 || (int) $task->success_count < 1) {
      throw new ImportException('还有失败图片，或没有成功导入的图片。');
    }
    if (!$account->hasPermission('publish xedc image imports') && !$account->hasPermission('administer xedc image imports')) {
      throw new ImportException('没有发布权限。');
    }
    if (!$node->access('update', $account)) {
      throw new ImportException('无权修改该内容。');
    }
    if ($node->isPublished()) {
      throw new ImportException('内容已经发布。');
    }
    if (trim($node->label()) === '') {
      throw new ImportException('标题为空。');
    }
    if (!$node->hasField('field_images') || $node->get('field_images')->isEmpty()) {
      throw new ImportException('图片数量为 0。');
    }
    if (!$node->hasField('field_cover_image') || $node->get('field_cover_image')->isEmpty() || !$node->get('field_cover_image')->entity) {
      throw new ImportException('封面无效。');
    }
    foreach ($node->getFieldDefinitions() as $definition) {
      if ($definition->isRequired() && $node->get($definition->getName())->isEmpty()) {
        throw new ImportException('必填字段「' . $definition->getLabel() . '」还没有填写。');
      }
    }
    if (!$this->moderationInformation->isModeratedEntity($node)) {
      return;
    }
    $workflow = $this->moderationInformation->getWorkflowForEntity($node);
    $current = (string) ($node->get('moderation_state')->value ?: 'draft');
    $allowed = FALSE;
    foreach ($workflow->getTypePlugin()->getTransitionsForState($current) as $transition) {
      if ($transition->to()->id() !== 'published') {
        continue;
      }
      $permission = 'use ' . $workflow->id() . ' transition ' . $transition->id();
      if ($account->hasPermission($permission)) {
        $allowed = TRUE;
      }
    }
    if (!$allowed) {
      throw new ImportException('当前审核状态不允许发布。');
    }
  }

  /**
   * Loads a task the account is allowed to change.
   */
  private function requireTask(int $taskId, AccountInterface $account): object {
    $task = $this->tasks->load($taskId);
    if (!$task) {
      throw new ImportException('任务不存在。');
    }
    $node = $this->node((int) $task->nid);
    $admin = $account->hasPermission('administer xedc image imports');
    $owns = (int) $task->uid === (int) $account->id() && $node->access('update', $account);
    if (!$admin && !$owns) {
      throw new ImportException('无权操作该任务。');
    }
    return $task;
  }

  /**
   * Loads the photo node for a task.
   */
  private function node(int $nid): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'photo') {
      throw new ImportException('关联的图片内容不存在。');
    }
    return $node;
  }

}
