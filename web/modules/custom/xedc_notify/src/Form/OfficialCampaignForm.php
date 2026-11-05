<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_notify\Service\OfficialCampaignManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates an official notification campaign draft.
 */
final class OfficialCampaignForm extends FormBase {

  public function __construct(
    protected OfficialCampaignManager $campaigns,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_notify.official'));
  }

  public function getFormId(): string {
    return 'xedc_notify_official_campaign';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'xedc-notify-admin';
    $form['#attached']['library'][] = 'xedc_notify/admin';
    $form['intro'] = [
      '#markup' => '<div class="xedc-notify-admin__header"><h1>' . $this->t('创建官方通知') . '</h1><p class="xedc-notify-admin__lead">' . $this->t('先保存草稿，再预览确认收件人范围后发送。打开本页不会自动发送通知。') . '</p></div>',
    ];
    $form['panel'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-notify-admin__panel']],
    ];
    $form['panel']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('通知标题'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['panel']['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('通知正文'),
      '#required' => TRUE,
      '#rows' => 8,
    ];
    $form['panel']['category'] = [
      '#type' => 'select',
      '#title' => $this->t('通知分类'),
      '#options' => OfficialCampaignManager::CATEGORIES,
      '#required' => TRUE,
      '#default_value' => 'announcement',
    ];
    $form['panel']['audience'] = [
      '#type' => 'radios',
      '#title' => $this->t('接收对象'),
      '#options' => [
        'all' => $this->t('全体会员'),
        'member' => $this->t('普通会员（非有效 VIP）'),
        'vip' => $this->t('当前有效 VIP 会员'),
        'uids' => $this->t('指定会员'),
      ],
      '#default_value' => 'uids',
      '#required' => TRUE,
      '#description' => $this->t('默认选择指定会员，降低误发全体通知的风险。发送前仍需二次确认。'),
    ];
    $form['panel']['audience_uids'] = [
      '#type' => 'textarea',
      '#title' => $this->t('指定会员 UID'),
      '#description' => $this->t('每行一个 UID，或用逗号分隔。仅在选择「指定会员」时生效。收件人身份由服务器校验。'),
      '#states' => [
        'visible' => [
          ':input[name="audience"]' => ['value' => 'uids'],
        ],
      ],
    ];
    $form['panel']['important'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('标记为重要'),
    ];
    $form['panel']['pinned'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('置顶显示'),
    ];
    $form['panel']['actions'] = ['#type' => 'actions'];
    $form['panel']['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('保存草稿'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $raw = (string) $form_state->getValue('audience_uids');
    $uids = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
      $part = trim($part);
      if ($part !== '' && ctype_digit($part)) {
        $uids[] = (int) $part;
      }
    }
    try {
      $cid = $this->campaigns->createDraft([
        'title' => (string) $form_state->getValue('title'),
        'body' => (string) $form_state->getValue('body'),
        'category' => (string) $form_state->getValue('category'),
        'audience' => (string) $form_state->getValue('audience'),
        'audience_uids' => $uids,
        'important' => (bool) $form_state->getValue('important'),
        'pinned' => (bool) $form_state->getValue('pinned'),
        'created_by' => (int) $this->currentUser()->id(),
      ]);
      $this->messenger()->addStatus($this->t('草稿已保存。请预览后发送。'));
      $form_state->setRedirect('xedc_notify.admin_official_view', ['cid' => $cid]);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
