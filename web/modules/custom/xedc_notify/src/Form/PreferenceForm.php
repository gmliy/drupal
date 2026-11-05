<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_member\Controller\MemberController;
use Drupal\xedc_notify\Service\PreferenceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Member social notification preferences.
 */
final class PreferenceForm extends FormBase {

  public function __construct(
    protected PreferenceManager $preferences,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_notify.preferences'));
  }

  public function getFormId(): string {
    return 'xedc_notify_preferences';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $uid = (int) $this->currentUser()->id();
    $values = $this->preferences->get($uid);
    $labels = $this->preferences->labels();
    $form['intro'] = [
      '#markup' => '<p class="xn-pref-intro">' . $this->t('以下开关只控制是否创建对应的社交通知，不会关闭点赞、收藏、关注或评论功能。重要安全通知、账号状态变化和必要的违规处理结果不受这些开关影响。') . '</p>',
    ];
    foreach ($labels as $key => $meta) {
      $form[$key] = [
        '#type' => 'checkbox',
        '#title' => $meta['label'],
        '#description' => $meta['description'],
        '#default_value' => !empty($values[$key]),
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('保存设置'),
      '#button_type' => 'primary',
    ];

    /** @var \Drupal\xedc_member\Controller\MemberController $member */
    $member = \Drupal::classResolver()->getInstanceFromDefinition(MemberController::class);
    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xn-pref']],
      'form' => $form,
    ];
    // Wrap later via alter — return form normally; controller route uses _form.
    // We still want member shell: use form alter in module or rebuild.
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    $values = [];
    foreach (PreferenceManager::KEYS as $key) {
      $values[$key] = (bool) $form_state->getValue($key);
    }
    $this->preferences->save($uid, $values);
    $this->messenger()->addStatus($this->t('通知设置已保存。'));
  }

}
