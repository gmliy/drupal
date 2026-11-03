<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\FilterPluginBase;

/**
 * Filters the moderation list by photo processing state.
 */
#[ViewsFilter('xedc_gallery_state')]
final class GalleryState extends FilterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $state = $this->selected();
    if ($state === '' || $state === 'all') {
      return;
    }
    $nids = \Drupal::service('xedc_core.gallery_metrics')->nidsForState($state);
    if (!$nids) {
      $this->query->addWhereExpression($this->options['group'], '1 = 0');
      return;
    }
    $this->query->addWhere($this->options['group'], 'node_field_revision.nid', $nids, 'IN');
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state): void {
    $form['value'] = [
      '#type' => 'select',
      '#title' => $this->t('图片处理'),
      '#options' => $this->options(),
      '#default_value' => $this->selected() ?: 'all',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary(): \Stringable|string {
    $state = $this->selected();
    return $this->options()[$state] ?? $this->t('全部');
  }

  /**
   * @return array<string, string>
   */
  private function options(): array {
    return [
      'all' => (string) $this->t('全部'),
      'pending' => (string) $this->t('图片处理未完成'),
      'failed' => (string) $this->t('图片处理失败'),
      'missing_alt' => (string) $this->t('缺失 ALT'),
      'complete' => (string) $this->t('图片处理完成'),
    ];
  }

  private function selected(): string {
    $value = $this->value;
    if (is_array($value)) {
      $value = $value['value'] ?? reset($value);
    }
    return is_string($value) ? $value : '';
  }

}
