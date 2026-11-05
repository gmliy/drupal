<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_notify\Service\OfficialCampaignManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Preview and confirm queued delivery of an official campaign.
 */
final class OfficialSendConfirmForm extends ConfirmFormBase {

  protected int $cid = 0;

  public function __construct(
    protected OfficialCampaignManager $campaigns,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_notify.official'));
  }

  public function getFormId(): string {
    return 'xedc_notify_official_send';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $cid = NULL): array {
    $this->cid = (int) $cid;
    $campaign = $this->campaigns->load($this->cid);
    if (!$campaign) {
      $this->messenger()->addError($this->t('通知不存在。'));
      $form_state->setRedirect('xedc_notify.admin_official');
      return $form;
    }
    $count = $this->campaigns->countRecipients($campaign);
    $form['preview'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('发送前预览'),
      '#items' => [
        $this->t('标题：@t', ['@t' => $campaign->title]),
        $this->t('分类：@c', ['@c' => OfficialCampaignManager::CATEGORIES[$campaign->category] ?? $campaign->category]),
        $this->t('接收对象：@a', ['@a' => $campaign->audience]),
        $this->t('预计接收人数：@n', ['@n' => $count]),
        $this->t('重要：@v', ['@v' => $campaign->important ? '是' : '否']),
        $this->t('置顶：@v', ['@v' => $campaign->pinned ? '是' : '否']),
        $this->t('正文：@b', ['@b' => mb_substr((string) $campaign->body, 0, 200)]),
      ],
    ];
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion() {
    return $this->t('确认发送这条官方通知？');
  }

  public function getDescription() {
    return $this->t('将通过队列分批发送，避免一次请求超时。发送过程可在详情页查看进度。');
  }

  public function getCancelUrl() {
    return Url::fromRoute('xedc_notify.admin_official_view', ['cid' => $this->cid]);
  }

  public function getConfirmText() {
    return $this->t('立即发送');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->campaigns->queueSend($this->cid);
      $this->messenger()->addStatus($this->t('已加入发送队列。请运行 cron 或等待队列处理。'));
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirect('xedc_notify.admin_official_view', ['cid' => $this->cid]);
  }

}
