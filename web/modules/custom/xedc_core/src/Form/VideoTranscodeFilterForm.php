<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Filters the playback-copy list without writing data.
 */
final class VideoTranscodeFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_video_transcode_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $status = '', string $codec = '', string $keys = ''): array {
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('xedc_core.video_transcode')->toString();
    $form['#token'] = FALSE;
    $form['#after_build'][] = '::stripBuildId';
    $form['keys'] = [
      '#type' => 'textfield',
      '#title' => $this->t('搜索'),
      '#default_value' => $keys,
      '#size' => 24,
    ];
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('状态'),
      '#options' => [
        '' => $this->t('全部'),
        'pending' => 'pending',
        'processing' => 'processing',
        'completed' => 'completed',
        'failed' => 'failed',
        'skipped' => 'skipped',
        'cancelled' => 'cancelled',
      ],
      '#default_value' => $status,
    ];
    $form['codec'] = [
      '#type' => 'textfield',
      '#title' => $this->t('编码'),
      '#default_value' => $codec,
      '#size' => 12,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('筛选'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * @param array<string, mixed> $form
   * @return array<string, mixed>
   */
  public function stripBuildId(array $form, FormStateInterface $form_state): array {
    unset($form['form_build_id'], $form['form_id'], $form['form_token']);
    return $form;
  }

}
