<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_notify\Service\MessageManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends a private message in an existing thread.
 */
final class MessageSendForm extends FormBase {

  protected int $tid = 0;
  protected int $recipientUid = 0;

  public function __construct(
    protected MessageManager $messages,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_notify.messages'));
  }

  public function getFormId(): string {
    return 'xedc_notify_message_send';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $tid = NULL, ?int $recipient_uid = NULL): array {
    $this->tid = (int) $tid;
    $this->recipientUid = (int) $recipient_uid;
    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('消息'),
      '#title_display' => 'invisible',
      '#required' => TRUE,
      '#rows' => 3,
      '#attributes' => [
        'placeholder' => $this->t('输入文字消息…'),
        'maxlength' => 2000,
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('发送'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('access send xedc messages')) {
      $form_state->setErrorByName('body', $this->t('没有发送私信的权限。'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->messages->send($this->currentUser(), $this->recipientUid, (string) $form_state->getValue('body'));
      $form_state->setRedirect('xedc_notify.message_thread', ['tid' => $result['tid']]);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
