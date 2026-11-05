<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\xedc_member\Controller\MemberController;
use Drupal\xedc_notify\Form\MarkAllReadForm;
use Drupal\xedc_notify\Service\NotificationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Member notification center.
 */
final class NotificationController extends ControllerBase {

  public function __construct(
    protected NotificationManager $notifications,
    protected DateFormatterInterface $dateFormatter,
    protected FormBuilderInterface $formBuilderService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_notify.notifications'),
      $container->get('date.formatter'),
      $container->get('form_builder'),
    );
  }

  public function list(string $category = 'all'): array {
    $uid = (int) $this->currentUser()->id();
    $cat = $category === 'all' ? NULL : $category;
    $rows = $this->notifications->listForUser($uid, $cat);
    $items = [];
    foreach ($rows as $row) {
      $items[] = [
        'id' => (int) $row->id,
        'title' => (string) $row->title,
        'body' => (string) ($row->body ?? ''),
        'category' => (string) $row->category,
        'type' => (string) $row->type,
        'important' => (bool) $row->important,
        'pinned' => (bool) $row->pinned,
        'is_read' => (bool) $row->is_read,
        'actor_name' => (string) ($row->actor_name ?? ''),
        'actor_avatar' => (string) ($row->actor_avatar ?? ''),
        'time_label' => $this->dateFormatter->format((int) $row->created, 'short'),
        'url' => Url::fromRoute('xedc_notify.notification_open', ['id' => (int) $row->id])->toString(),
        'delete_url' => Url::fromRoute('xedc_notify.notification_delete', ['id' => (int) $row->id])->toString(),
      ];
    }
    $tabs = [];
    foreach (['all' => '全部通知', 'social' => '社交通知', 'vip' => 'VIP 权益通知', 'official' => '官方系统通知'] as $key => $label) {
      $route = $key === 'all' ? 'xedc_notify.notifications' : 'xedc_notify.notifications_category';
      $params = $key === 'all' ? [] : ['category' => $key];
      $tabs[] = [
        'key' => $key,
        'label' => $this->t($label),
        'url' => Url::fromRoute($route, $params)->toString(),
        'active' => $category === $key,
        'unread' => $this->notifications->unreadCount($uid, $key === 'all' ? NULL : $key),
      ];
    }
    $content = [
      '#theme' => 'xedc_notify_notifications',
      '#items' => $items,
      '#tabs' => $tabs,
      '#category' => $category,
      '#unread_total' => $this->notifications->unreadCount($uid),
      '#read_all_form' => $this->formBuilderService->getForm(MarkAllReadForm::class, $category),
    ];
    /** @var \Drupal\xedc_member\Controller\MemberController $member */
    $member = \Drupal::classResolver()->getInstanceFromDefinition(MemberController::class);
    $build = $member->renderShell('notifications', $content);
    $build['#attached']['library'][] = 'xedc_notify/member';
    return $build;
  }

  public function markRead(int $id): RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    $this->notifications->markRead($uid, $id);
    return $this->redirect('xedc_notify.notifications');
  }

  public function markAllRead(): RedirectResponse {
    // Legacy GET + CSRF link is retired. Mutation must use MarkAllReadForm POST.
    $this->messenger()->addWarning($this->t('请在通知中心使用「全部已读」按钮完成操作。'));
    return $this->redirect('xedc_notify.notifications');
  }

  public function delete(int $id): RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    if (!$this->notifications->softDelete($uid, $id)) {
      throw new NotFoundHttpException();
    }
    $this->messenger()->addStatus($this->t('通知已删除。'));
    return $this->redirect('xedc_notify.notifications');
  }

  public function open(int $id): RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    $row = $this->notifications->loadForUser($uid, $id);
    if (!$row) {
      throw new NotFoundHttpException();
    }
    $this->notifications->markRead($uid, $id);
    $url = (string) ($row->url ?? '');
    if ($url !== '') {
      try {
        return new LocalRedirectResponse($url);
      }
      catch (\InvalidArgumentException) {
        // Ignore unsafe/external stored targets and fall back.
      }
    }
    return $this->redirect('xedc_notify.notifications');
  }

}
