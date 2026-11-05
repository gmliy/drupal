<?php

declare(strict_types=1);

namespace Drupal\xedc_ai;

/**
 * Labels for stored machine values.
 */
final class Labels {

  public static function bundle(string $bundle): string {
    return match ($bundle) {
      'article' => '文章',
      'photo' => '美图',
      'video' => '视频',
      default => $bundle !== '' ? $bundle : '未知',
    };
  }

  public static function status(string $status): string {
    return match ($status) {
      'pending' => '待审核',
      'processing' => '审核中',
      'completed' => '审核完成',
      'failed' => '审核失败',
      'ignored' => '已忽略',
      'cancelled' => '已取消',
      'success' => '成功',
      'error' => '失败',
      'started' => '进行中',
      default => $status !== '' ? $status : '未知',
    };
  }

  public static function risk(string $risk): string {
    return match ($risk) {
      'normal' => '正常',
      'low' => '低风险',
      'medium' => '中风险',
      'high' => '高风险',
      default => '未评估',
    };
  }

  /**
   * Human verdict shown beside the queue status.
   */
  public static function verdict(string $status, string $risk, string $resultStatus, string $handled): string {
    if ($handled === 'confirmed' && $status === 'completed') {
      return '已处理';
    }
    return match ($status) {
      'pending' => '待审核',
      'processing' => '审核中',
      'failed' => '审核失败',
      'cancelled' => '已取消',
      'ignored' => '已处理',
      'completed' => self::outcome($risk, $resultStatus),
      default => self::status($status),
    };
  }

  /**
   * @return array<string, string>
   *   Model capability ids.
   */
  public static function capabilities(): array {
    return [
      'content_review' => '文本审核',
      'quality' => '内容质量分析',
      'seo' => 'SEO 建议',
      'translation' => '翻译',
      'general' => '通用文本处理',
    ];
  }

  public static function capabilityList(string $stored): string {
    if ($stored === '') {
      return '未分类';
    }
    $labels = self::capabilities();
    $names = [];
    foreach (explode(',', $stored) as $id) {
      $id = trim($id);
      if ($id !== '') {
        $names[] = $labels[$id] ?? $id;
      }
    }
    return $names === [] ? '未分类' : implode('、', $names);
  }

  private static function outcome(string $risk, string $resultStatus): string {
    if ($risk === 'high' || $resultStatus === 'fail') {
      return '存在风险';
    }
    if (in_array($resultStatus, ['attention', 'review_required'], TRUE) || $risk === 'medium') {
      return '需要修改';
    }
    if ($resultStatus === 'pass' || in_array($risk, ['low', 'normal'], TRUE)) {
      return '审核通过';
    }
    return '人工复核';
  }

  public static function source(string $source): string {
    return match ($source) {
      'content_review' => '内容审核',
      'translation' => '自动翻译',
      'seo_optimization' => 'SEO 优化',
      'connection_test' => '连接测试',
      // Tag center calls share the usage table; source machine value is xedc_tags.
      'xedc_tags' => '标签管理（标签语义分析）',
      'tag_semantic_analysis' => '标签管理（标签语义分析）',
      default => $source !== '' ? $source : '未知',
    };
  }

  public static function handled(string $status): string {
    return match ($status) {
      'confirmed' => '已确认',
      'ignored' => '已忽略',
      default => '待处理',
    };
  }

}
