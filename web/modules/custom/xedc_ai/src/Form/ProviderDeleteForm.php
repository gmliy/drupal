<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_ai\Provider\ModelRepository;
use Drupal\xedc_ai\Provider\ProviderRepository;
use Drupal\xedc_ai\Provider\SecretStore;
use Drupal\xedc_ai\Review\ReviewRepository;
use Drupal\xedc_ai\Usage\AuditLog;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Deletes a provider only when nothing still references it.
 */
final class ProviderDeleteForm extends ConfirmFormBase {

  private string $providerId = '';

  private string $blocked = '';

  public function __construct(
    private readonly ProviderRepository $providers,
    private readonly ModelRepository $models,
    private readonly ReviewRepository $reviews,
    private readonly SecretStore $secrets,
    private readonly AuditLog $audit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.providers'),
      $container->get('xedc_ai.models'),
      $container->get('xedc_ai.reviews'),
      $container->get('xedc_ai.secrets'),
      $container->get('xedc_ai.audit'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_provider_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('删除供应商 @id？', ['@id' => $this->providerId]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('xedc_ai.providers');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $provider = NULL): array {
    $this->providerId = (string) $provider;
    $row = $this->providers->load($this->providerId);
    if ($row === NULL) {
      throw new NotFoundHttpException();
    }
    $reasons = [];
    $modelCount = $this->models->countForProvider($this->providerId);
    if ($modelCount > 0) {
      $reasons[] = $this->t('仍有 @count 个模型使用它。', ['@count' => $modelCount]);
    }
    $reviewCount = $this->reviews->countForProvider($this->providerId);
    if ($reviewCount > 0) {
      $reasons[] = $this->t('已有 @count 条审核记录引用它。', ['@count' => $reviewCount]);
    }
    $default = $this->models->defaultProfile();
    $fallback = $this->models->fallbackProfile(0);
    if (($default && $default->providerId === $this->providerId) || ($fallback && $fallback->providerId === $this->providerId)) {
      $reasons[] = $this->t('它是默认模型或备用模型的供应商。');
    }
    if ($reasons !== []) {
      $this->blocked = implode(' ', array_map('strval', $reasons));
      $form['blocked'] = [
        '#markup' => '<p>' . $this->t('不能删除。请先调整关联配置或停用供应商。') . ' ' . $this->blocked . '</p>',
      ];
      $form['disable'] = [
        '#type' => 'submit',
        '#value' => $this->t('改为停用'),
        '#submit' => ['::disable'],
      ];
      $form['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('返回'),
        '#url' => $this->getCancelUrl(),
        '#attributes' => ['class' => ['button']],
      ];
      return $form;
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->blocked !== '') {
      return;
    }
    $this->secrets->delete($this->providerId);
    $this->providers->delete($this->providerId);
    $this->audit->log('provider_delete', '删除了供应商 ' . $this->providerId . '。');
    $this->messenger()->addStatus($this->t('供应商已删除。'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Disables the provider instead of deleting it.
   */
  public function disable(array &$form, FormStateInterface $form_state): void {
    $this->providers->setEnabled($this->providerId, FALSE);
    $this->audit->log('provider_disable', '停用了供应商 ' . $this->providerId . '。');
    $this->messenger()->addStatus($this->t('供应商已停用。'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
