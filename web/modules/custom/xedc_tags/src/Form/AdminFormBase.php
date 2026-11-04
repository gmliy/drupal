<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Shared admin page chrome for tag forms.
 */
abstract class AdminFormBase extends FormBase {

  protected function chrome(array $form): array {
    $form['#attached']['library'][] = 'xedc_tags/admin';
    $form['#prefix'] = '<div class="xt-admin">';
    $form['#suffix'] = '</div>';
    $form['kicker'] = [
      '#weight' => -100,
      '#markup' => '<p class="xt-admin-kicker">' . $this->t('智能标签管理中心') . '</p>',
    ];
    $form['#cache']['contexts'][] = 'user.permissions';
    $form['#cache']['contexts'][] = 'url.query_args';
    return $form;
  }

  /**
   * @param array<string, mixed> $query
   */
  protected function redirectTo(string $route, array $query = []): void {
  }

  protected function date(int $timestamp): string {
    if ($timestamp <= 0) {
      return '';
    }
    return \Drupal::service('date.formatter')->format($timestamp, 'custom', 'Y-m-d H:i');
  }

}
