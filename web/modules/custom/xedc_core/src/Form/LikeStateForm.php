<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Marks one like active or cancelled. It does not delete the row.
 */
final class LikeStateForm extends FormBase {

  public function getFormId(): string {
    return 'xedc_like_state';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['id'] = [
      '#type' => 'number',
      '#title' => $this->t('点赞记录 ID'),
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['action'] = [
      '#type' => 'select',
      '#title' => $this->t('操作'),
      '#options' => [
        'cancel' => $this->t('标为已取消'),
        'restore' => $this->t('恢复为有效'),
      ],
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('保存状态'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('administer xedc engagement')) {
      $this->messenger()->addError($this->t('没有修改点赞状态的权限。'));
      return;
    }
    $id = (int) $form_state->getValue('id');
    $status = $form_state->getValue('action') === 'restore' ? 1 : 0;
    $row = \Drupal::database()->select('xedc_like', 'l')
      ->fields('l', ['id', 'entity_type', 'entity_id'])
      ->condition('id', $id)
      ->execute()
      ->fetchObject();
    if (!$row) {
      $this->messenger()->addError($this->t('没有这条点赞记录。'));
      return;
    }
    \Drupal::database()->update('xedc_like')
      ->fields([
        'status' => $status,
        'changed' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('id', $id)
      ->execute();
    \Drupal::service('xedc_core.like_manager')->invalidate((string) $row->entity_type, (int) $row->entity_id);
    \Drupal::logger('xedc_engagement')->info('管理员 @uid 将点赞记录 @id 标为 @status。', [
      '@uid' => (int) $this->currentUser()->id(),
      '@id' => $id,
      '@status' => $status === 1 ? '有效' : '已取消',
    ]);
    $this->messenger()->addStatus($status === 1 ? $this->t('已恢复这条点赞。') : $this->t('已把这条点赞标为取消。'));
  }

}
