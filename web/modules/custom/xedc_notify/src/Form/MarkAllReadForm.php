<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_notify\Service\NotificationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Marks every notification for the current member as read via POST.
 */
final class MarkAllReadForm extends FormBase {

  public function __construct(
    protected NotificationManager $notifications,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_notify.notifications'));
  }

  public function getFormId(): string {
    return 'xedc_notify_mark_all_read';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $category = 'all'): array {
    $form['category'] = [
      '#type' => 'hidden',
      '#value' => $category,
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('全部已读'),
      '#attributes' => [
        'class' => ['xn-ntf__read-all-btn'],
      ],
    ];
    $form['#attributes']['class'][] = 'xn-ntf__read-all-form';
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    if ($uid < 1 || !$this->currentUser()->hasPermission('access own xedc notifications')) {
      $this->messenger()->addError($this->t('没有权限执行此操作。'));
      return;
    }
    $this->notifications->markAllRead($uid);
    $this->messenger()->addStatus($this->t('已全部标记为已读。'));
    $category = (string) $form_state->getValue('category');
    if (in_array($category, ['social', 'vip', 'official'], TRUE)) {
      $form_state->setRedirect('xedc_notify.notifications_category', ['category' => $category]);
      return;
    }
    $form_state->setRedirectUrl(Url::fromRoute('xedc_notify.notifications'));
  }

}
