<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Controller;

use Drupal\Core\Access\AccessException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\xedc_member\Controller\MemberController;
use Drupal\xedc_notify\Form\MessageSendForm;
use Drupal\xedc_notify\Service\MemberIdentity;
use Drupal\xedc_notify\Service\MessageManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Member private message inbox and thread pages.
 */
final class MessageController extends ControllerBase {

  public function __construct(
    protected MessageManager $messages,
    protected MemberIdentity $identity,
    protected FormBuilderInterface $formBuilderService,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_notify.messages'),
      $container->get('xedc_notify.identity'),
      $container->get('form_builder'),
      $container->get('date.formatter'),
    );
  }

  public function inbox(): array {
    $uid = (int) $this->currentUser()->id();
    $threads = $this->messages->listThreads($uid);
    $items = [];
    foreach ($threads as $row) {
      $items[] = [
        'tid' => (int) $row->tid,
        'name' => $row->other_name,
        'avatar' => $row->other_avatar,
        'initial' => $row->other_initial,
        'preview' => $row->preview,
        'unread' => (int) $row->unread_count,
        'time' => (int) $row->last_created,
        'time_label' => $this->dateFormatter->format((int) $row->last_created, 'short'),
        'url' => Url::fromRoute('xedc_notify.message_thread', ['tid' => (int) $row->tid])->toString(),
        'delete_url' => Url::fromRoute('xedc_notify.message_delete', ['tid' => (int) $row->tid])->toString(),
      ];
    }
    $content = [
      '#theme' => 'xedc_notify_messages',
      '#threads' => $items,
      '#active_tid' => 0,
      '#thread' => NULL,
      '#messages' => [],
      '#form' => NULL,
      '#unread_total' => $this->messages->unreadTotal($uid),
    ];
    return $this->memberShell('messages', $content);
  }

  public function thread(int $tid): array {
    $uid = (int) $this->currentUser()->id();
    try {
      $thread = $this->messages->assertParticipant($tid, $uid);
    }
    catch (AccessException | \InvalidArgumentException $e) {
      throw new AccessDeniedHttpException($e->getMessage());
    }
    $this->messages->markThreadRead($tid, $uid);
    $otherUid = $this->messages->otherUid($thread, $uid);
    $otherName = $this->identity->displayNameByUid($otherUid);
    $rows = $this->messages->listMessages($tid, $uid);
    $messages = [];
    foreach ($rows as $row) {
      $messages[] = [
        'mid' => (int) $row->mid,
        'mine' => (int) $row->sender_uid === $uid,
        'body' => (string) $row->body,
        'time_label' => $this->dateFormatter->format((int) $row->created, 'short'),
        'is_read' => (bool) $row->is_read,
      ];
    }
    $threads = $this->messages->listThreads($uid);
    $items = [];
    foreach ($threads as $row) {
      $items[] = [
        'tid' => (int) $row->tid,
        'name' => $row->other_name,
        'avatar' => $row->other_avatar,
        'initial' => $row->other_initial,
        'preview' => $row->preview,
        'unread' => (int) $row->unread_count,
        'time' => (int) $row->last_created,
        'time_label' => $this->dateFormatter->format((int) $row->last_created, 'short'),
        'url' => Url::fromRoute('xedc_notify.message_thread', ['tid' => (int) $row->tid])->toString(),
        'delete_url' => Url::fromRoute('xedc_notify.message_delete', ['tid' => (int) $row->tid])->toString(),
        'active' => (int) $row->tid === $tid,
      ];
    }
    $content = [
      '#theme' => 'xedc_notify_messages',
      '#threads' => $items,
      '#active_tid' => $tid,
      '#thread' => [
        'tid' => $tid,
        'other_uid' => $otherUid,
        'name' => $otherName,
        'avatar' => $this->identity->avatarUrl($otherUid),
        'initial' => $this->identity->initial($otherName),
        'profile_url' => $this->identity->publicProfileUrl($otherUid),
      ],
      '#messages' => $messages,
      '#form' => $this->formBuilderService->getForm(MessageSendForm::class, $tid, $otherUid),
      '#unread_total' => $this->messages->unreadTotal($uid),
    ];
    return $this->memberShell('messages', $content);
  }

  public function start(UserInterface $user): RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    $peer = (int) $user->id();
    if ($peer < 1 || $peer === $uid || !$user->isActive() || !$this->identity->load($uid)) {
      throw new NotFoundHttpException();
    }
    try {
      $tid = $this->messages->getOrCreateThread($uid, $peer);
    }
    catch (\InvalidArgumentException $e) {
      throw new AccessDeniedHttpException($e->getMessage());
    }
    return $this->redirect('xedc_notify.message_thread', ['tid' => $tid]);
  }

  /**
   * Reuses the member center shell without duplicating its page chrome.
   */
  protected function memberShell(string $active, array $content): array {
    /** @var \Drupal\xedc_member\Controller\MemberController $member */
    $member = \Drupal::classResolver()->getInstanceFromDefinition(MemberController::class);
    $build = $member->renderShell($active, $content);
    $build['#attached']['library'][] = 'xedc_notify/member';
    return $build;
  }

}
