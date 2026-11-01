<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

/**
 * Status values stored on an import task.
 */
final class ImportStatus {

  public const QUEUED = 'queued';

  public const VALIDATING = 'validating';

  public const EXTRACTING = 'extracting';

  public const SCANNING = 'scanning';

  public const CONVERTING = 'converting';

  public const IMPORTING = 'importing';

  public const VERIFYING = 'verifying';

  public const COMPLETED = 'completed';

  public const PARTIAL = 'partial';

  public const FAILED = 'failed';

  public const CANCELLED = 'cancelled';

  public const REVIEW = 'review';

  public const PUBLISHED = 'published';

  /**
   * Statuses that mean the worker is still busy.
   *
   * @return string[]
   *   Status ids.
   */
  public static function processing(): array {
    return [
      self::VALIDATING,
      self::EXTRACTING,
      self::SCANNING,
      self::CONVERTING,
      self::IMPORTING,
      self::VERIFYING,
    ];
  }

  /**
   * Statuses that stop the worker until somebody retries the task.
   */
  public static function isTerminal(string $status): bool {
    return in_array($status, [
      self::COMPLETED,
      self::PARTIAL,
      self::FAILED,
      self::CANCELLED,
      self::REVIEW,
      self::PUBLISHED,
    ], TRUE);
  }

  /**
   * Chinese label for a status id.
   */
  public static function label(string $status): string {
    return self::labels()[$status] ?? $status;
  }

  /**
   * @return array<string, string>
   *   Status id to label.
   */
  public static function labels(): array {
    return [
      self::QUEUED => '等待处理',
      self::VALIDATING => '验证压缩包',
      self::EXTRACTING => '解压',
      self::SCANNING => '扫描图片',
      self::CONVERTING => '转换图片',
      self::IMPORTING => '导入图片',
      self::VERIFYING => '数据校验',
      self::COMPLETED => '完成',
      self::PARTIAL => '部分失败',
      self::FAILED => '失败',
      self::CANCELLED => '已取消',
      self::REVIEW => '待审核',
      self::PUBLISHED => '已发布',
    ];
  }

  /**
   * Filter groups used by the publish center.
   *
   * @return array<string, string>
   *   Filter id to label.
   */
  public static function filters(): array {
    return [
      'all' => '全部任务',
      'queued' => '等待处理',
      'processing' => '正在处理',
      'review' => '导入完成待审核',
      'failed' => '处理失败',
      'cancelled' => '已取消',
      'published' => '已发布',
    ];
  }

}
