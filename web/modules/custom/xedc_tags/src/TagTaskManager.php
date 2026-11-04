<?php

namespace Drupal\xedc_tags;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;

/**
 * Tracks long-running tag analysis jobs and feeds the queue.
 */
final class TagTaskManager {

  public const TYPES = [
    'full_scan' => '全量标签扫描',
    'duplicate_scan' => '重复检测',
    'health_scan' => '健康检查',
    'seo_scan' => 'SEO 检查',
    'ai_batch' => 'AI 语义分析',
    'recommend_scan' => '内容推荐扫描',
  ];

  public const STATUSES = [
    'pending',
    'running',
    'paused',
    'completed',
    'partial',
    'failed',
    'cancelled',
  ];

  public function __construct(
    private Connection $database,
    private QueueFactory $queueFactory,
    private TagAudit $audit,
    private AccountInterface $currentUser,
    private TimeInterface $time,
  ) {}

  public function ready(): bool {
    return $this->database->schema()->tableExists('xedc_tag_task');
  }

  /**
   * @return object[]
   */
  public function list(int $limit = 30): array {
    if (!$this->ready()) {
      return [];
    }
    return $this->database->select('xedc_tag_task', 't')
      ->fields('t')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  public function load(int $id): ?object {
    if ($id <= 0 || !$this->ready()) {
      return NULL;
    }
    $row = $this->database->select('xedc_tag_task', 't')->fields('t')->condition('id', $id)->execute()->fetch();
    return $row ?: NULL;
  }

  /**
   * @return array{ok: bool, message: string, id: int}
   */
  public function enqueue(string $type, array $options = [], ?int $uid = NULL): array {
    if (!$this->ready()) {
      return ['ok' => FALSE, 'message' => '任务表尚未安装。', 'id' => 0];
    }
    if (!isset(self::TYPES[$type])) {
      return ['ok' => FALSE, 'message' => '未知任务类型。', 'id' => 0];
    }
    if ($this->activeOfType($type)) {
      return ['ok' => FALSE, 'message' => '同类型任务正在执行或等待，请先完成或取消。', 'id' => 0];
    }
    $now = $this->time->getRequestTime();
    $id = (int) $this->database->insert('xedc_tag_task')->fields([
      'type' => $type,
      'status' => 'pending',
      'uid' => $uid ?? (int) $this->currentUser->id(),
      'total' => 0,
      'processed' => 0,
      'success' => 0,
      'failed' => 0,
      'message' => '等待执行',
      'options' => json_encode($options, JSON_UNESCAPED_UNICODE) ?: '{}',
      'result' => '{}',
      'created' => $now,
      'started' => 0,
      'finished' => 0,
      'changed' => $now,
    ])->execute();
    $this->queueFactory->get('xedc_tags_scan')->createItem(['task_id' => $id]);
    $this->audit->log('task_enqueue', self::TYPES[$type], ['task_id' => $id], 0, $uid);
    return ['ok' => TRUE, 'message' => '任务已入队 #' . $id, 'id' => $id];
  }

  public function activeOfType(string $type): bool {
    if (!$this->ready()) {
      return FALSE;
    }
    $count = (int) $this->database->select('xedc_tag_task', 't')
      ->condition('type', $type)
      ->condition('status', ['pending', 'running', 'paused'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
    return $count > 0;
  }

  /**
   * @return array{ok: bool, message: string}
   */
  public function cancel(int $id, ?int $uid = NULL): array {
    $row = $this->load($id);
    if (!$row) {
      return ['ok' => FALSE, 'message' => '任务不存在。'];
    }
    if (!in_array($row->status, ['pending', 'running', 'paused'], TRUE)) {
      return ['ok' => FALSE, 'message' => '当前状态不能取消。'];
    }
    $this->update($id, [
      'status' => 'cancelled',
      'message' => '已取消',
      'finished' => $this->time->getRequestTime(),
    ]);
    $this->audit->log('task_cancel', '取消任务 #' . $id, [], 0, $uid);
    return ['ok' => TRUE, 'message' => '任务已取消。'];
  }

  /**
   * @return array{ok: bool, message: string}
   */
  public function pause(int $id, ?int $uid = NULL): array {
    $row = $this->load($id);
    if (!$row || $row->status !== 'running') {
      return ['ok' => FALSE, 'message' => '只能暂停运行中的任务。'];
    }
    $this->update($id, ['status' => 'paused', 'message' => '已暂停']);
    $this->audit->log('task_pause', '暂停任务 #' . $id, [], 0, $uid);
    return ['ok' => TRUE, 'message' => '任务已暂停。'];
  }

  /**
   * @return array{ok: bool, message: string}
   */
  public function resume(int $id, ?int $uid = NULL): array {
    $row = $this->load($id);
    if (!$row || $row->status !== 'paused') {
      return ['ok' => FALSE, 'message' => '只能恢复已暂停任务。'];
    }
    $this->update($id, ['status' => 'pending', 'message' => '等待继续']);
    $this->queueFactory->get('xedc_tags_scan')->createItem(['task_id' => $id]);
    $this->audit->log('task_resume', '恢复任务 #' . $id, [], 0, $uid);
    return ['ok' => TRUE, 'message' => '任务已重新入队。'];
  }

  /**
   * @param array<string, mixed> $fields
   */
  public function update(int $id, array $fields): void {
    $fields['changed'] = $this->time->getRequestTime();
    $this->database->update('xedc_tag_task')->fields($fields)->condition('id', $id)->execute();
  }

}
