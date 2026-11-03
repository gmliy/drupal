<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;

/**
 * Asks for a source file id, then confirms detection before any work runs.
 */
final class VideoTranscodeEnqueueForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_video_transcode_enqueue';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['fid'] = [
      '#type' => 'number',
      '#title' => $this->t('原文件编号'),
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('检测编码'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load((int) $form_state->getValue('fid'));
    if (!$file instanceof FileInterface) {
      $form_state->setErrorByName('fid', $this->t('找不到这个文件。'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('xedc_core.video_transcode_action', [
      'fid' => (int) $form_state->getValue('fid'),
      'op' => 'detect',
    ]);
  }

}
