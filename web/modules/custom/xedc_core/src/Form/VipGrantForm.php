<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\xedc_core\Engagement\MembershipManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Grants, extends or cancels VIP for one member.
 */
final class VipGrantForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MembershipManager $membership,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('xedc_core.membership'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'xedc_vip_grant';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('用户名'),
      '#required' => TRUE,
      '#maxlength' => 60,
      '#attributes' => ['autocomplete' => 'off'],
      '#description' => $this->t('输入会员登录用户名。系统会查询真实用户实体后再开通，不会把用户名当成 UID。'),
    ];
    $form['operation'] = [
      '#type' => 'radios',
      '#title' => $this->t('操作'),
      '#options' => [
        'grant' => $this->t('开通或重设有效期'),
        'extend' => $this->t('延长'),
        'cancel' => $this->t('取消 VIP'),
      ],
      '#default_value' => 'grant',
      '#required' => TRUE,
    ];
    $form['preset'] = [
      '#type' => 'radios',
      '#title' => $this->t('时长'),
      '#options' => [
        '7' => $this->t('7 天'),
        '30' => $this->t('30 天'),
        '90' => $this->t('90 天'),
        '365' => $this->t('365 天'),
        'custom' => $this->t('自定义日期'),
      ],
      '#default_value' => '30',
      '#states' => [
        'invisible' => [
          ':input[name="operation"]' => ['value' => 'cancel'],
        ],
      ],
    ];
    $form['start'] = [
      '#type' => 'date',
      '#title' => $this->t('开始日期'),
      '#default_value' => date('Y-m-d'),
      '#states' => [
        'visible' => [
          ':input[name="preset"]' => ['value' => 'custom'],
          ':input[name="operation"]' => ['value' => 'grant'],
        ],
      ],
    ];
    $form['end'] = [
      '#type' => 'date',
      '#title' => $this->t('到期日期'),
      '#states' => [
        'visible' => [
          ':input[name="preset"]' => ['value' => 'custom'],
          ':input[name="operation"]' => ['value' => 'grant'],
        ],
      ],
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('备注'),
      '#maxlength' => 255,
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('开通 VIP'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $user = $this->resolveMember($form_state->getValue('name'));
    if (!$user instanceof UserInterface) {
      $form_state->setErrorByName('name', $this->t('找不到该用户名对应的会员，请确认用户名后重试。'));
      return;
    }
    if (!$user->isActive()) {
      $form_state->setErrorByName('name', $this->t('该账号已停用，不能开通或调整 VIP。'));
      return;
    }
    $form_state->set('vip_uid', (int) $user->id());
    $form_state->set('vip_account_name', $user->getAccountName());

    $operation = (string) $form_state->getValue('operation');
    $preset = (string) $form_state->getValue('preset');
    if ($operation === 'grant' && $preset === 'custom') {
      $start = (string) $form_state->getValue('start');
      $end = (string) $form_state->getValue('end');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $form_state->setErrorByName('end', $this->t('请填写开始和到期日期。'));
        return;
      }
      $zone = new \DateTimeZone(date_default_timezone_get());
      $starts = (new \DateTimeImmutable($start . ' 00:00:00', $zone))->getTimestamp();
      $expires = (new \DateTimeImmutable($end . ' 23:59:59', $zone))->getTimestamp();
      if ($expires <= $starts) {
        $form_state->setErrorByName('end', $this->t('到期时间必须晚于开始时间。'));
      }
      $form_state->set('vip_starts', $starts);
      $form_state->set('vip_expires', $expires);
    }
    if ($operation === 'extend' && !in_array((int) $preset, MembershipManager::PRESETS, TRUE)) {
      $form_state->setErrorByName('preset', $this->t('延长时请选择 7、30、90 或 365 天。'));
    }
    if ($operation === 'grant' && $preset !== 'custom' && $this->membership->isActive($user)) {
      $snap = $this->membership->snapshot($user);
      $expiresText = $snap['expires'] > 0
        ? \Drupal::service('date.formatter')->format($snap['expires'], 'custom', 'Y-m-d H:i')
        : '-';
      $form_state->setErrorByName('operation', $this->t('该会员已是有效 VIP（到期：@expires）。请使用「延长」操作，或选择「自定义日期」明确重设有效期。', [
        '@expires' => $expiresText,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $form_state->get('vip_uid');
    $actor = (int) $this->currentUser()->id();
    $note = trim((string) $form_state->getValue('note'));
    $operation = (string) $form_state->getValue('operation');
    $name = (string) $form_state->get('vip_account_name');
    try {
      if ($operation === 'cancel') {
        $this->membership->cancel($uid, $actor, $note);
        $this->messenger()->addStatus($this->t('已取消 @name 的 VIP，权限立即失效。', ['@name' => $name]));
      }
      elseif ($operation === 'extend') {
        $this->membership->extend($uid, (int) $form_state->getValue('preset'), $actor, $note);
        $this->messenger()->addStatus($this->t('已延长 @name 的 VIP。', ['@name' => $name]));
      }
      else {
        $preset = (string) $form_state->getValue('preset');
        if ($preset === 'custom') {
          $starts = (int) $form_state->get('vip_starts');
          $expires = (int) $form_state->get('vip_expires');
        }
        else {
          $starts = \Drupal::time()->getRequestTime();
          $expires = $starts + ((int) $preset * 86400);
        }
        $this->membership->grant($uid, $starts, $expires, $actor, $note);
        $this->messenger()->addStatus($this->t('已为 @name 开通 VIP。', ['@name' => $name]));
      }
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      $this->logger('xedc_core')->warning('VIP 表单操作被拒绝：@m', ['@m' => $e->getMessage()]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('会员操作失败，请稍后重试或查看系统日志。'));
      $this->logger('xedc_core')->error('VIP 表单操作异常：@m', ['@m' => $e->getMessage()]);
    }
    $form_state->setRedirectUrl(Url::fromRoute('xedc_core.vip_hub', [], ['query' => ['tab' => 'grant']]));
  }

  /**
   * Resolves typed username to a user entity. Numeric UID input is rejected.
   */
  protected function resolveMember(mixed $value): ?UserInterface {
    if (!is_string($value) && !is_numeric($value)) {
      return NULL;
    }
    $name = trim((string) $value);
    if ($name === '') {
      return NULL;
    }
    // Admins must use the account name, not a raw UID string.
    $storage = $this->entityTypeManager->getStorage('user');
    $users = $storage->loadByProperties(['name' => $name]);
    $user = $users ? reset($users) : NULL;
    return $user instanceof UserInterface && !$user->isAnonymous() ? $user : NULL;
  }

}
