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
      'page' => '页面',
      default => $bundle !== '' ? $bundle : '未知',
    };
  }

  /**
   * SEO issue / suggestion field machine values.
   */
  public static function field(string $field): string {
    $original = trim($field);
    $field = str_replace([' ', '-'], '_', strtolower($original));
    return match ($field) {
      'title', 'seo_title', 'meta_title' => 'SEO 标题',
      'summary', 'description', 'field_summary', 'meta_description', 'suggested_description' => '页面描述',
      '图片alt', '图片_alt' => '图片 ALT',
      'keywords', 'suggested_keywords' => '关键词',
      'tags', 'field_tags' => '标签',
      'body', 'field_body', 'content' => '内容',
      'alt', 'field_media_image', 'suggested_alt' => '图片 ALT',
      'field_images' => '图片集',
      'field_cover', 'field_cover_image' => '封面',
      'field_video_cover' => '视频封面',
      'internal_links', 'internal_link' => '内部链接',
      '' => '',
      default => $original,
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

  public static function seoOps(string $ops): string {
    return match ($ops) {
      'unanalyzed' => '未分析',
      'queued' => '等待分析',
      'running' => '分析中',
      'success' => '分析成功',
      'failed' => '分析失败',
      'awaiting' => '待人工确认',
      'partial', 'partially_applied' => '部分已采纳',
      'adopted', 'applied' => '全部已采纳',
      'ignored' => '已忽略',
      'stale' => '需要重新分析',
      'conflict' => '版本冲突',
      'pending' => '待处理',
      default => $ops !== '' ? $ops : '未知',
    };
  }

  public static function handled(string $status): string {
    return match ($status) {
      'confirmed' => '已确认',
      'ignored' => '已忽略',
      default => '待处理',
    };
  }

  public static function moderation(string $state, string $label = ''): string {
    if ($label !== '' && $label !== $state && !str_contains($label, '_')) {
      return $label;
    }
    return match ($state) {
      'draft' => '草稿',
      'needs_review' => '待审核',
      'changes_requested' => '退回修改',
      'published' => '已发布',
      'rejected' => '已拒绝',
      'archived' => '已撤下',
      '' => '未启用审核',
      default => '未启用审核',
    };
  }

  public static function moderationKey(string $state): string {
    return in_array($state, ['draft', 'needs_review', 'changes_requested', 'published', 'rejected', 'archived'], TRUE) ? $state : 'none';
  }

  public static function level(string $level): string {
    return match ($level) {
      'critical' => '严重',
      'high' => '高',
      'medium' => '中',
      'low' => '低',
      default => $level !== '' ? $level : '中',
    };
  }

  /**
   * Suggestion / issue processing state for display.
   */
  public static function itemState(string $state): string {
    return match ($state) {
      'success' => '成功',
      'failed' => '失败',
      'pending' => '待处理',
      'applied', 'adopted' => '已采纳',
      'ignored', 'rejected' => '已忽略',
      'partially_applied', 'partial' => '部分已采纳',
      'conflict' => '版本冲突',
      'not_applicable' => '不适用',
      '' => '待处理',
      default => $state,
    };
  }

  public static function language(string $langcode): string {
    return match ($langcode) {
      'zh-hans' => '简体中文',
      'zh-hant' => '繁体中文',
      'en' => '英文',
      'ja' => '日文',
      'ru' => '俄文',
      '' => '未记录',
      default => '其他语言',
    };
  }

  public static function imageField(string $field): string {
    return match ($field) {
      'field_images' => '图片集',
      'field_cover_image', 'field_cover' => '封面',
      'field_video_cover' => '视频封面',
      'field_media_image' => '图片说明',
      '' => '未记录',
      default => '图片',
    };
  }

  public static function finishReason(string $reason): string {
    return match ($reason) {
      'stop' => '正常结束',
      'length' => '输出被截断',
      'content_filter' => '内容被过滤',
      'tool_calls' => '工具调用',
      '' => '',
      default => '其他原因',
    };
  }

  public static function errorCode(string $code): string {
    return match ($code) {
      'invalid_json' => '结果无法读取',
      'truncated' => '结果不完整',
      'filtered' => '结果被过滤',
      'stuck' => '任务超时',
      'cache' => '使用了已保存的结果',
      'no_model' => '没有可用模型',
      'no_key' => '没有可用密钥',
      'cancelled' => '已取消',
      'interrupted' => '调用中断',
      '' => '',
      default => '分析出错',
    };
  }

  public static function callStatus(string $status): string {
    return match ($status) {
      'success' => '成功',
      'error' => '失败',
      'pending' => '处理中',
      'processing' => '处理中',
      default => self::status($status),
    };
  }

  public static function auditAction(string $action): string {
    return match ($action) {
      'seo_apply' => '采纳建议',
      'seo_reject' => '忽略建议',
      'seo_restore' => '恢复原值',
      'seo_conflict' => '版本冲突',
      'review_cache' => '复用已有结果',
      'review_manual' => '手动分析',
      'review_queue' => '加入队列',
      'review_ignore' => '忽略审核',
      'review_cancel' => '取消审核',
      'review_confirm' => '确认审核',
      'budget_override' => '超预算确认',
      'settings_save' => '保存配置',
      'model_save' => '保存模型',
      'model_delete' => '删除模型',
      'model_default' => '更新默认模型',
      'provider_save' => '保存供应商',
      'provider_delete' => '删除供应商',
      'provider_disable' => '停用供应商',
      'key_set' => '更新密钥',
      'key_clear' => '清除密钥',
      'queue_pause' => '暂停队列',
      'queue_resume' => '恢复队列',
      'rules_save' => '保存规则',
      default => str_starts_with($action, 'submission_') ? '投稿处理' : ($action !== '' ? '系统操作' : '操作'),
    };
  }

}
