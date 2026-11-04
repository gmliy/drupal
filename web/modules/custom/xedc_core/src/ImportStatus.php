<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

/**
 * Status values stored on an import task.
 */
final class ImportStatus {

  public const QUEUED = 'queued';

  public const UPLOADING = 'uploading';

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
      self::UPLOADING,
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
    return self::labels()[$status] ?? '未知状态';
  }

  /**
   * User-facing stage. Terminal statuses win over the in-progress phase.
   *
   * @return array{id: string, label: string}
   *   Stable id and Chinese label.
   */
  public static function phase(string $status, string $stage): array {
    $terminal = [
      self::COMPLETED => ['done', '已完成'],
      self::REVIEW => ['done', '已完成'],
      self::PUBLISHED => ['done', '已完成'],
      self::PARTIAL => ['partial', '部分失败'],
      self::FAILED => ['failed', '全部失败'],
      self::CANCELLED => ['cancelled', '已取消'],
    ];
    if (isset($terminal[$status])) {
      return ['id' => $terminal[$status][0], 'label' => $terminal[$status][1]];
    }
    $map = [
      self::UPLOADING => ['upload', '上传压缩包'],
      'open' => ['upload', '上传压缩包'],
      self::VALIDATING => ['validate', '校验压缩包'],
      self::EXTRACTING => ['extract', '解压中'],
      self::SCANNING => ['scan', '扫描图片'],
      self::QUEUED => ['wait', '等待图片处理'],
      self::IMPORTING => ['wait', '等待图片处理'],
      'optimizing' => ['optimize', '图片优化中'],
      self::CONVERTING => ['optimize', '图片优化中'],
      'storing' => ['store', '上传 R2'],
      self::VERIFYING => ['verify', '正在校验'],
    ];
    $key = $stage !== '' && isset($map[$stage]) ? $stage : $status;
    $row = $map[$key] ?? ['wait', '等待图片处理'];
    return ['id' => $row[0], 'label' => $row[1]];
  }

  /**
   * @return array<string, string>
   *   Status id to label.
   */
  public static function labels(): array {
    return [
      'pending' => '等待上传',
      'open' => '等待上传',
      self::QUEUED => '排队等待处理',
      self::UPLOADING => '正在上传',
      'uploaded' => '上传完成',
      'merging' => '正在合并文件',
      'processing' => '正在处理',
      self::VALIDATING => '正在校验',
      'hashing' => '正在校验',
      self::EXTRACTING => '正在解压',
      self::SCANNING => '正在处理',
      self::CONVERTING => '正在处理',
      'optimizing' => '正在处理',
      'storing' => '正在处理',
      self::IMPORTING => '正在导入',
      'retrying' => '正在重试',
      'paused' => '已暂停',
      'interrupted' => '上传中断',
      'expired' => '已过期',
      self::VERIFYING => '正在校验',
      self::COMPLETED => '已完成',
      'success' => '成功',
      'duplicate' => '文件已存在，已复用',
      'skipped' => '已跳过',
      self::PARTIAL => '部分失败',
      self::FAILED => '失败',
      self::CANCELLED => '已取消',
      'canceled' => '已取消',
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
      'queued' => '排队等待处理',
      'processing' => '正在处理',
      'review' => '导入完成待审核',
      'failed' => '处理失败',
      'cancelled' => '已取消',
      'published' => '已发布',
    ];
  }

}
