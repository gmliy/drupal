<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_notify\Service\MessageManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One-sided private message thread hide.
 */
final class MessageDeleteForm extends ConfirmFormBase {

  protected int $tid = 0;

  public function __construct(
    protected MessageManager $messages,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_notify.messages'));
  }

  public function getFormId(): string {
    return 'xedc_notify_message_delete';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $tid = NULL): array {
    $this->tid = (int) $tid;
    $uid = (int) $this->currentUser()->id();
    try {
      $this->messages->assertParticipant($this->tid, $uid);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('无权删除此会话。'));
      $form_state->setRedirect('xedc_notify.messages');
      return $form;
    }
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion() {
    return $this->t('确定删除这个私信会话吗？');
  }

  public function getDescription() {
    return $this->t('只会从你的列表中移除。对方仍可看到会话；若对方再次发来消息，会话会重新出现在你的列表中。');
  }

  public function getCancelUrl() {
    return Url::fromRoute('xedc_notify.message_thread', ['tid' => $this->tid]);
  }

  public function getConfirmText() {
    return $this->t('删除会话');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    try {
      $this->messages->hideThread($this->tid, $uid);
      $this->messenger()->addStatus($this->t('会话已从你的列表中删除。'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('删除失败。'));
    }
    $form_state->setRedirect('xedc_notify.messages');
  }

}
