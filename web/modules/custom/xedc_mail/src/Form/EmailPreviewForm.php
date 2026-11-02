<?php

declare(strict_types=1);

namespace Drupal\xedc_mail\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\user\UserInterface;
use Drupal\xedc_mail\MailWrapper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Preview account mail and send one test message to the current user.
 */
final class EmailPreviewForm extends FormBase {

  public function __construct(
    private readonly MailWrapper $wrapper,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_mail.wrapper'),
      $container->get('tempstore.private'),
    );
  }

  public function getFormId(): string {
    return 'xedc_mail_preview';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $labels = [
      'password_reset' => '重置密码',
      'register_no_approval_required' => '欢迎新用户',
      'register_admin_created' => '管理员创建的账户',
      'register_pending_approval' => '等待审核',
      'register_pending_approval_admin' => '通知管理员有人注册',
      'status_activated' => '账户已激活',
      'status_blocked' => '账户已冻结',
      'status_canceled' => '账户已注销',
      'cancel_confirm' => '确认取消账户',
      'register_confirmation_with_pass' => '注册验证',
    ];
    $key = (string) ($form_state->getValue('key') ?: 'password_reset');
    if (!isset($labels[$key])) {
      $key = 'password_reset';
    }

    $form['key'] = [
      '#type' => 'select',
      '#title' => $this->t('邮件类型'),
      '#options' => $labels,
      '#default_value' => $key,
    ];
    $form['width'] = [
      '#type' => 'radios',
      '#title' => $this->t('预览宽度'),
      '#options' => [
        '600' => $this->t('桌面宽度'),
        '375' => $this->t('手机宽度'),
      ],
      '#default_value' => '600',
    ];
    $form['frame'] = [
      '#type' => 'html_tag',
      '#tag' => 'iframe',
      '#attributes' => [
        'sandbox' => '',
        'title' => '邮件预览',
        'src' => '/admin/config/people/accounts/email-preview/render/' . $key,
        'style' => 'display:block;width:600px;max-width:100%;height:720px;border:1px solid #E4E4E7;border-radius:12px;background:#F4F4F5;',
        'data-xedc-mail-frame' => '1',
      ],
    ];
    $form['#attached']['library'][] = 'xedc_mail/preview';
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['send'] = [
      '#type' => 'submit',
      '#value' => $this->t('发送测试邮件给我自己'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->currentUser();
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($account->id());
    if (!$user instanceof UserInterface || !$user->getEmail()) {
      $this->messenger()->addError($this->t('当前账号没有邮箱，测试邮件没有发送。'));
      return;
    }
    $store = $this->tempStoreFactory->get('xedc_mail');
    $last = (int) $store->get('tested');
    if ($last > 0 && (time() - $last) < 60) {
      $this->messenger()->addError($this->t('每分钟最多发送一次测试邮件。'));
      return;
    }
    $key = (string) $form_state->getValue('key');
    if (MailWrapper::moduleOf($key) === '') {
      return;
    }
    $result = \Drupal::service('plugin.manager.mail')->mail(MailWrapper::moduleOf($key), $key, $user->getEmail(), $user->getPreferredLangcode(), [
      'account' => $user,
    ]);
    if (empty($result['result'])) {
      $this->messenger()->addError($this->t('测试邮件没有发出。'));
      return;
    }
    $store->set('tested', time());
    $this->messenger()->addStatus($this->t('测试邮件已发到 @mail。', ['@mail' => $user->getEmail()]));
  }

}
