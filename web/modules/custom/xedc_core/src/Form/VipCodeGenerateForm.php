<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\xedc_core\Engagement\VipCodeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form to generate a VIP code batch.
 */
final class VipCodeGenerateForm extends FormBase {

  public function __construct(
    protected VipCodeManager $codes,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.vip_codes'),
      $container->get('tempstore.private'),
    );
  }

  public function getFormId(): string {
    return 'xedc_vip_code_generate';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('生成数量'),
      '#required' => TRUE,
      '#min' => 1,
      '#max' => VipCodeManager::MAX_BATCH_SIZE,
      '#default_value' => 10,
      '#description' => $this->t('单次最多 @max 张。', ['@max' => VipCodeManager::MAX_BATCH_SIZE]),
    ];
    $form['vip_days'] = [
      '#type' => 'number',
      '#title' => $this->t('每张 VIP 天数'),
      '#required' => TRUE,
      '#min' => VipCodeManager::MIN_DAYS,
      '#max' => VipCodeManager::MAX_DAYS,
      '#default_value' => 30,
    ];
    $form['expires'] = [
      '#type' => 'date',
      '#title' => $this->t('兑换截止日期（可选）'),
      '#description' => $this->t('留空表示不限制兑换截止时间。'),
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('批次备注'),
      '#maxlength' => 255,
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('生成卡密'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $qty = (int) $form_state->getValue('quantity');
    $days = (int) $form_state->getValue('vip_days');
    if ($qty < 1 || $qty > VipCodeManager::MAX_BATCH_SIZE) {
      $form_state->setErrorByName('quantity', $this->t('生成数量无效。'));
    }
    if ($days < VipCodeManager::MIN_DAYS || $days > VipCodeManager::MAX_DAYS) {
      $form_state->setErrorByName('vip_days', $this->t('VIP 天数无效。'));
    }
    $expires = trim((string) $form_state->getValue('expires'));
    if ($expires !== '') {
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
        $form_state->setErrorByName('expires', $this->t('截止日期格式不正确。'));
        return;
      }
      $zone = new \DateTimeZone(date_default_timezone_get());
      $ts = (new \DateTimeImmutable($expires . ' 23:59:59', $zone))->getTimestamp();
      if ($ts <= \Drupal::time()->getRequestTime()) {
        $form_state->setErrorByName('expires', $this->t('兑换截止日期必须晚于当前时间。'));
      }
      $form_state->set('expires_at', $ts);
    }
    else {
      $form_state->set('expires_at', 0);
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->codes->generateBatch(
        (int) $form_state->getValue('quantity'),
        (int) $form_state->getValue('vip_days'),
        (int) $form_state->get('expires_at'),
        (string) $form_state->getValue('note'),
        (int) $this->currentUser()->id(),
      );
      $store = $this->tempStoreFactory->get('xedc_vip_codes');
      $store->set('batch_' . $result['batch_id'], [
        'codes' => $result['codes'],
        'created' => \Drupal::time()->getRequestTime(),
      ]);
      $this->messenger()->addStatus($this->t('已生成 @n 张卡密。明文仅在下一页展示一次，请立即复制或导出。', [
        '@n' => count($result['codes']),
      ]));
      $form_state->setRedirectUrl(Url::fromRoute('xedc_core.vip_codes_reveal', [
        'batch_id' => $result['batch_id'],
      ]));
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('生成失败，请查看日志。'));
      $this->logger('xedc_core')->error('VIP 卡密生成失败：@m', ['@m' => $e->getMessage()]);
    }
  }

}
