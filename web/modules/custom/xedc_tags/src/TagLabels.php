<?php

declare(strict_types=1);

namespace Drupal\xedc_tags;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Display-only Chinese labels for tag admin machine values.
 *
 * Internal status / op codes stay unchanged in PHP and the database.
 */
final class TagLabels {

  use StringTranslationTrait;

  /**
   * Task / scan / suggestion status label.
   */
  public function status(string $status): TranslatableMarkup|string {
    $map = [
      'pending' => $this->t('等待执行'),
      'queued' => $this->t('排队中'),
      'running' => $this->t('正在执行'),
      'processing' => $this->t('处理中'),
      'paused' => $this->t('已暂停'),
      'completed' => $this->t('已完成'),
      'partial' => $this->t('部分完成'),
      'failed' => $this->t('执行失败'),
      'cancelled' => $this->t('已取消'),
      'expired' => $this->t('已超时'),
      'skipped' => $this->t('已跳过'),
      'accepted' => $this->t('已采纳'),
      'rejected' => $this->t('已拒绝'),
      'ignored' => $this->t('已忽略'),
      'active' => $this->t('启用'),
      'inactive' => $this->t('停用'),
      'disabled' => $this->t('停用'),
      'enabled' => $this->t('启用'),
      'success' => $this->t('成功'),
      'error' => $this->t('错误'),
      'warning' => $this->t('警告'),
      'ok' => $this->t('正常'),
      'rolled_back' => $this->t('已回滚'),
      'unknown' => $this->t('未知状态'),
    ];
    return $map[$status] ?? $this->t('未知状态');
  }

  /**
   * Merge record status label.
   */
  public function mergeStatus(string $status): TranslatableMarkup|string {
    $map = [
      'completed' => $this->t('已完成'),
      'failed' => $this->t('执行失败'),
      'rolled_back' => $this->t('已回滚'),
      'pending' => $this->t('等待执行'),
    ];
    return $map[$status] ?? $this->t('未知状态');
  }

  /**
   * Audit / activity operation label.
   */
  public function operation(string $op): TranslatableMarkup|string {
    $map = [
      'task_enqueue' => $this->t('创建分析任务'),
      'task_start' => $this->t('开始执行任务'),
      'task_complete' => $this->t('完成分析任务'),
      'task_fail' => $this->t('分析任务失败'),
      'task_cancel' => $this->t('取消分析任务'),
      'task_pause' => $this->t('暂停分析任务'),
      'task_resume' => $this->t('恢复分析任务'),
      'alias_create' => $this->t('创建标签别名'),
      'alias_update' => $this->t('修改标签别名'),
      'alias_delete' => $this->t('删除标签别名'),
      'alias_status' => $this->t('更新别名状态'),
      'merge' => $this->t('执行标签合并'),
      'merge_preview' => $this->t('预览标签合并'),
      'merge_execute' => $this->t('执行标签合并'),
      'merge_rollback' => $this->t('回滚标签合并'),
      'merge_redirect' => $this->t('创建 301 跳转'),
      'merge_redirect_create' => $this->t('创建 301 跳转'),
      'merge_redirect_cleanup' => $this->t('清理合并创建的 301 跳转'),
      'merge_failed' => $this->t('标签合并失败'),
      'recommend_apply' => $this->t('应用标签推荐'),
      'recommendation_apply' => $this->t('应用标签推荐'),
      'recommendation_reject' => $this->t('拒绝标签推荐'),
      'ai_analyze' => $this->t('AI 标签分析'),
      'ai_failed' => $this->t('AI 分析失败'),
      'ai_budget' => $this->t('AI 预算限制'),
      'ai_decision' => $this->t('AI 建议处理'),
      'health_scan' => $this->t('标签健康检查'),
      'duplicate_scan' => $this->t('重复标签检测'),
      'seo_scan' => $this->t('SEO 检查'),
      'full_scan' => $this->t('全量标签扫描'),
      'recommend_scan' => $this->t('内容推荐扫描'),
      'ai_batch' => $this->t('AI 语义分析'),
      'delete' => $this->t('删除标签'),
      'duplicate' => $this->t('重复标签判定'),
      'not_duplicate' => $this->t('标记为非重复'),
    ];
    return $map[$op] ?? $this->t('其他操作');
  }

  /**
   * Task type label (falls back to TagTaskManager::TYPES).
   */
  public function taskType(string $type): TranslatableMarkup|string {
    if (isset(TagTaskManager::TYPES[$type])) {
      return TagTaskManager::TYPES[$type];
    }
    return $this->operation($type);
  }

  /**
   * AI suggestion type label.
   */
  public function suggestionType(string $type): TranslatableMarkup|string {
    $map = [
      'synonym' => $this->t('同义词'),
      'near_duplicate' => $this->t('近似重复'),
      'rename' => $this->t('建议重命名'),
      'merge' => $this->t('建议合并'),
      'related' => $this->t('相关标签'),
      'seo_title' => $this->t('SEO 标题建议'),
      'seo_description' => $this->t('SEO 描述建议'),
      'keep' => $this->t('建议保留'),
    ];
    return $map[$type] ?? $this->t('其他建议');
  }

  /**
   * Recommended action label from AI rows.
   */
  public function recommendedAction(string $action): TranslatableMarkup|string {
    $map = [
      'merge' => $this->t('建议合并'),
      'alias' => $this->t('建议添加别名'),
      'rename' => $this->t('建议重命名'),
      'keep' => $this->t('建议保留'),
      'review' => $this->t('建议人工复核'),
      'ignore' => $this->t('建议忽略'),
      'apply' => $this->t('建议应用'),
      'reject' => $this->t('建议拒绝'),
    ];
    if ($action === '') {
      return '—';
    }
    // Compact AI output may store free-text advice; only map known machine values.
    return $map[$action] ?? $action;
  }

  /**
   * Badge tone for a status machine value.
   */
  public function statusTone(string $status): string {
    return match ($status) {
      'completed', 'success', 'accepted', 'ok', 'active', 'enabled' => 'success',
      'running', 'processing', 'queued', 'info' => 'info',
      'pending', 'paused', 'partial', 'warning', 'skipped' => 'warning',
      'failed', 'error', 'cancelled', 'expired', 'rejected' => 'danger',
      default => 'neutral',
    };
  }

  /**
   * Health issue code label.
   */
  public function healthCode(string $code): TranslatableMarkup|string {
    $map = [
      'low_quality' => $this->t('低质量'),
      'naming' => $this->t('命名问题'),
      'orphan' => $this->t('孤立/未使用'),
      'low_frequency' => $this->t('低频'),
      'high_frequency' => $this->t('高频'),
      'unpublished' => $this->t('仅未发布'),
      'exact_duplicate' => $this->t('完全重复'),
      'similar' => $this->t('相似标签'),
    ];
    return $map[$code] ?? $this->t('其他问题');
  }

  /**
   * Health severity label.
   */
  public function severity(string $severity): TranslatableMarkup|string {
    $map = [
      'error' => $this->t('异常'),
      'warning' => $this->t('警告'),
      'info' => $this->t('提示'),
      'success' => $this->t('正常'),
    ];
    return $map[$severity] ?? $this->t('未知状态');
  }

  /**
   * Badge tone for an audit operation.
   */
  public function operationTone(string $op): string {
    if (str_contains($op, 'fail') || str_contains($op, 'cancel')) {
      return 'danger';
    }
    if (str_contains($op, 'delete') || str_contains($op, 'cleanup') || str_contains($op, 'rollback')) {
      return 'warning';
    }
    if (str_contains($op, 'merge') || str_contains($op, 'complete') || str_contains($op, 'create') || str_contains($op, 'apply')) {
      return 'success';
    }
    return 'neutral';
  }

}
