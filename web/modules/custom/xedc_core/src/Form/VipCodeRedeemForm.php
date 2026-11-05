<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserInterface;
use Drupal\xedc_core\Engagement\VipCodeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Member-facing VIP code redeem form.
 */
final class VipCodeRedeemForm extends FormBase {

  public function __construct(
    protected VipCodeManager $codes,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.vip_codes'));
  }

  public function getFormId(): string {
    return 'xedc_vip_code_redeem';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('VIP 卡密'),
      '#required' => TRUE,
      '#maxlength' => 32,
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'placeholder' => 'XXXX-XXXX-XXXX-XXXX',
      ],
      '#description' => $this->t('输入管理员发放的卡密。每张卡密仅可成功兑换一次。'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('立即兑换'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = \Drupal::entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    if (!$account instanceof UserInterface) {
      $this->messenger()->addError($this->t('请先登录。'));
      return;
    }
    try {
      $result = $this->codes->redeem((string) $form_state->getValue('code'), $account);
      $expires = $result['expires'] > 0
        ? \Drupal::service('date.formatter')->format($result['expires'], 'custom', 'Y-m-d H:i')
        : '-';
      $this->messenger()->addStatus($this->t('兑换成功：已获得 @days 天 VIP，当前有效期至 @expires。', [
        '@days' => $result['vip_days'],
        '@expires' => $expires,
      ]));
      $form_state->setValue('code', '');
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('兑换失败，卡密未被消耗。请稍后重试。'));
      $this->logger('xedc_core')->error('VIP 卡密兑换异常：@m', ['@m' => $e->getMessage()]);
    }
  }

}
