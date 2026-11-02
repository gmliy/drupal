<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Removes one like from the member center.
 */
final class LikeRemoveForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'xedc_like_remove';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $nid = NULL): array {
    if ($nid === NULL) {
      $nid = (int) $form_state->get('nid');
    }
    else {
      $form_state->set('nid', $nid);
    }
    $form['#attributes']['class'][] = 'xedc-like-remove';
    $form['nid'] = [
      '#type' => 'value',
      '#value' => (int) $nid,
    ];
    $form['remove'] = [
      '#type' => 'submit',
      '#value' => $this->t('取消点赞'),
      '#button_type' => 'danger',
      '#attributes' => ['class' => ['xm-btn']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = (int) $form_state->getValue('nid');
    $uid = (int) $this->currentUser()->id();
    $node = $this->entityTypeManager()->getStorage('node')->load($nid);
    if ($uid > 0 && $node instanceof NodeInterface) {
      \Drupal::service('xedc_core.like_manager')->remove($uid, $nid);
      $this->messenger()->addStatus($this->t('已取消点赞。'));
    }
    $form_state->setRedirectUrl(Url::fromRoute('xedc_member.likes'));
  }

}
