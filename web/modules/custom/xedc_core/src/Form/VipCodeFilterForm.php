<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * GET filter for VIP code admin list.
 */
final class VipCodeFilterForm extends FormBase {

  public function getFormId(): string {
    return 'xedc_vip_code_filter';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('xedc_core.vip_codes')->toString();
    $form['batch_id'] = [
      '#type' => 'number',
      '#title' => $this->t('批次'),
      '#min' => 1,
      '#default_value' => $request->query->get('batch_id') ?: NULL,
    ];
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('状态'),
      '#options' => [
        '' => $this->t('全部状态'),
        'unused' => $this->t('未兑换'),
        'redeemed' => $this->t('已兑换'),
        'revoked' => $this->t('已作废'),
        'expired' => $this->t('已过期'),
      ],
      '#default_value' => (string) $request->query->get('status', ''),
    ];
    $form['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('卡密前缀'),
      '#size' => 10,
      '#maxlength' => 8,
      '#default_value' => (string) $request->query->get('prefix', ''),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('筛选'),
    ];
    // Avoid Drupal CSRF/token for GET filters.
    $form['#token'] = FALSE;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = array_filter([
      'batch_id' => $form_state->getValue('batch_id') ?: NULL,
      'status' => $form_state->getValue('status') ?: NULL,
      'prefix' => $form_state->getValue('prefix') ?: NULL,
    ], static fn($v) => $v !== NULL && $v !== '');
    $form_state->setRedirect('xedc_core.vip_codes', [], ['query' => $query]);
  }

}
