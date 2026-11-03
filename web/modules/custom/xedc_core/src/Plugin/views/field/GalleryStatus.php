<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Plugin\views\field;

use Drupal\node\NodeInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Compact photo-processing status for the existing moderation list.
 */
#[ViewsField('xedc_gallery_status')]
final class GalleryStatus extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {}

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $node = $values->_entity ?? NULL;
    if (!$node instanceof NodeInterface || $node->bundle() !== 'photo') {
      return ['#markup' => '—'];
    }
    $metrics = \Drupal::service('xedc_core.gallery_metrics')->forNode($node);
    $tasks = \Drupal::service('xedc_core.tasks')->forNode((int) $node->id());
    $active = FALSE;
    $failedTasks = 0;
    foreach ($tasks as $task) {
      $status = (string) $task->status;
      if (!\Drupal\xedc_core\ImportStatus::isTerminal($status) || $status === \Drupal\xedc_core\ImportStatus::QUEUED) {
        $active = TRUE;
      }
      if (in_array($status, [\Drupal\xedc_core\ImportStatus::FAILED, \Drupal\xedc_core\ImportStatus::PARTIAL], TRUE)) {
        $failedTasks++;
      }
    }
    if ((int) $metrics['count'] === 0 && !$active && $failedTasks === 0) {
      return ['#markup' => '—'];
    }
    $label = '已完成';
    if ($active) {
      $label = '处理中';
    }
    elseif ($failedTasks > 0 || (int) $metrics['failed'] > 0) {
      $label = '失败';
    }
    $text = $label . ' · ' . (int) $metrics['count'] . '张';
    if ((int) $metrics['failed'] > 0 || $failedTasks > 0) {
      $text .= ' · 失败' . max((int) $metrics['failed'], $failedTasks);
    }
    $detail = '原图 ' . $metrics['original_label'] . ' · 优化后 ' . $metrics['optimized_label'] . ' · 缺ALT ' . (int) $metrics['missing_alt'];
    return [
      '#type' => 'inline_template',
      '#template' => '<span class="xedc-gallery-status">{{ text }}<small>{{ detail }}</small></span>',
      '#context' => ['text' => $text, 'detail' => $detail],
      '#attached' => ['library' => ['xedc_core/asset_upload']],
    ];
  }

}
