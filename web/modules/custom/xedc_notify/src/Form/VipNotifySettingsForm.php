<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_core\Engagement\MembershipManager;
use Drupal\xedc_notify\Service\VipNotifier;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * VIP reminder offsets and optional benefits-change broadcast.
 */
final class VipNotifySettingsForm extends ConfigFormBase {

  public function __construct(
    protected VipNotifier $vipNotifier,
  ) {}

  public static function create(ContainerInterface $container): static {
    $instance = new static($container->get('xedc_notify.vip'));
    $instance->setConfigFactory($container->get('config.factory'));
    return $instance;
  }

  public function getFormId(): string {
    return 'xedc_notify_vip_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['xedc_notify.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('xedc_notify.settings');
    $form['vip_reminders_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用 VIP 到期自动提醒'),
      '#default_value' => (bool) $config->get('vip_reminders_enabled'),
    ];
    $offsets = $config->get('vip_reminder_offsets') ?: [7, 3, 1];
    $form['vip_reminder_offsets'] = [
      '#type' => 'textfield',
      '#title' => $this->t('提前提醒天数'),
      '#description' => $this->t('用逗号分隔，例如 7,3,1。同一会员同一有效期周期同一天数只会提醒一次。'),
      '#default_value' => implode(',', array_map('intval', $offsets)),
      '#required' => TRUE,
    ];
    $form['favorite_notify_default'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('新会员默认开启收藏通知'),
      '#default_value' => (bool) $config->get('favorite_notify_default'),
    ];
    $form['benefits_note'] = [
      '#markup' => '<p>' . $this->t('当前 VIP 权益文案来自现有 MembershipManager::benefits()，本页不单独维护第二套权益。若管理员修改了代码中的权益文案，可在下方选择是否通知当前有效 VIP。') . '</p>',
    ];
    $form['notify_benefits'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('保存后向当前有效 VIP 发送「权益变化」通知'),
      '#default_value' => FALSE,
    ];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $raw = (string) $form_state->getValue('vip_reminder_offsets');
    $parts = array_filter(array_map('intval', preg_split('/[,\s]+/', $raw) ?: []));
    $parts = array_values(array_unique(array_filter($parts, static fn($d) => $d > 0 && $d <= 365)));
    if (!$parts) {
      $form_state->setErrorByName('vip_reminder_offsets', $this->t('请填写至少一个有效天数。'));
    }
    $form_state->setValue('vip_reminder_offsets_parsed', $parts);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('xedc_notify.settings')
      ->set('vip_reminders_enabled', (bool) $form_state->getValue('vip_reminders_enabled'))
      ->set('vip_reminder_offsets', $form_state->getValue('vip_reminder_offsets_parsed'))
      ->set('favorite_notify_default', (bool) $form_state->getValue('favorite_notify_default'))
      ->save();
    if ($form_state->getValue('notify_benefits')) {
      $benefits = MembershipManager::benefits();
      // Snapshot "before" is not persisted historically; use identical list as
      // baseline and still send an admin-triggered notice describing current benefits.
      $count = $this->vipNotifier->notifyBenefitsChanged(['（管理员触发权益说明刷新）'], $benefits, (int) $this->currentUser()->id(), TRUE);
      $this->messenger()->addStatus($this->t('已向 @count 名有效 VIP 发送权益通知。', ['@count' => $count]));
    }
    parent::submitForm($form, $form_state);
  }

}
