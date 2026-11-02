<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\xedc_core\Engagement\MembershipManager;

/**
 * Grants, extends or cancels VIP for one member.
 */
final class VipGrantForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'xedc_vip_grant';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['name'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('会员'),
      '#target_type' => 'user',
      '#required' => TRUE,
      '#description' => $this->t('按用户名选择。不会使用前端传入的用户 ID。'),
    ];
    $form['operation'] = [
      '#type' => 'radios',
      '#title' => $this->t('操作'),
      '#options' => [
        'grant' => $this->t('开通或重设有效期'),
        'extend' => $this->t('延长'),
        'cancel' => $this->t('取消 VIP'),
      ],
      '#default_value' => 'grant',
      '#required' => TRUE,
    ];
    $form['preset'] = [
      '#type' => 'radios',
      '#title' => $this->t('时长'),
      '#options' => [
        '7' => $this->t('7 天'),
        '30' => $this->t('30 天'),
        '90' => $this->t('90 天'),
        '365' => $this->t('365 天'),
        'custom' => $this->t('自定义日期'),
      ],
      '#default_value' => '30',
      '#states' => [
        'invisible' => [
          ':input[name="operation"]' => ['value' => 'cancel'],
        ],
      ],
    ];
    $form['start'] = [
      '#type' => 'date',
      '#title' => $this->t('开始日期'),
      '#default_value' => date('Y-m-d'),
      '#states' => [
        'visible' => [
          ':input[name="preset"]' => ['value' => 'custom'],
          ':input[name="operation"]' => ['value' => 'grant'],
        ],
      ],
    ];
    $form['end'] = [
      '#type' => 'date',
      '#title' => $this->t('到期日期'),
      '#states' => [
        'visible' => [
          ':input[name="preset"]' => ['value' => 'custom'],
          ':input[name="operation"]' => ['value' => 'grant'],
        ],
      ],
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('备注'),
      '#maxlength' => 255,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('保存'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $form_state->getValue('name');
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface || $user->isAnonymous()) {
      $form_state->setErrorByName('name', $this->t('请选择一个会员。'));
      return;
    }
    $operation = (string) $form_state->getValue('operation');
    $preset = (string) $form_state->getValue('preset');
    if ($operation === 'grant' && $preset === 'custom') {
      $start = (string) $form_state->getValue('start');
      $end = (string) $form_state->getValue('end');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $form_state->setErrorByName('end', $this->t('请填写开始和到期日期。'));
        return;
      }
      $zone = new \DateTimeZone(date_default_timezone_get());
      $starts = (new \DateTimeImmutable($start . ' 00:00:00', $zone))->getTimestamp();
      $expires = (new \DateTimeImmutable($end . ' 23:59:59', $zone))->getTimestamp();
      if ($expires <= $starts) {
        $form_state->setErrorByName('end', $this->t('到期时间必须晚于开始时间。'));
      }
      $form_state->set('vip_starts', $starts);
      $form_state->set('vip_expires', $expires);
    }
    if ($operation === 'extend' && !in_array((int) $preset, MembershipManager::PRESETS, TRUE)) {
      $form_state->setErrorByName('preset', $this->t('延长时请选择 7、30、90 或 365 天。'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $form_state->getValue('name');
    $actor = (int) $this->currentUser()->id();
    $note = trim((string) $form_state->getValue('note'));
    $operation = (string) $form_state->getValue('operation');
    /** @var \Drupal\xedc_core\Engagement\MembershipManager $membership */
    $membership = \Drupal::service('xedc_core.membership');
    if ($operation === 'cancel') {
      $membership->cancel($uid, $actor, $note);
      $this->messenger()->addStatus($this->t('已取消 VIP，权限立即失效。'));
    }
    elseif ($operation === 'extend') {
      $membership->extend($uid, (int) $form_state->getValue('preset'), $actor, $note);
      $this->messenger()->addStatus($this->t('已延长 VIP。'));
    }
    else {
      $preset = (string) $form_state->getValue('preset');
      if ($preset === 'custom') {
        $starts = (int) $form_state->get('vip_starts');
        $expires = (int) $form_state->get('vip_expires');
      }
      else {
        $starts = \Drupal::time()->getRequestTime();
        $expires = $starts + ((int) $preset * 86400);
      }
      $membership->grant($uid, $starts, $expires, $actor, $note);
      $this->messenger()->addStatus($this->t('已开通 VIP。'));
    }
    $form_state->setRedirectUrl(Url::fromRoute('xedc_core.vip_admin'));
  }

}
