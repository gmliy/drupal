<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_core\Ops\Cleanup\FileCleanupManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds an unconfirmed quarantine plan from selected findings.
 */
class UnusedPlanForm extends FormBase {

  public function __construct(
    protected FileCleanupManager $manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.cleanup_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_core_unused_plan';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $options = []): array {
    $form['fids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('选择要放入清理方案的文件'),
      '#options' => $options,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('生成隔离方案'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter(array_map('intval', (array) $form_state->getValue('fids')));
    $result = $this->manager->createQuarantinePlan(array_values($selected), (int) $this->currentUser()->id());
    foreach ($result['errors'] as $error) {
      $this->messenger()->addWarning($error);
    }
    if ($result['ok']) {
      $this->messenger()->addStatus($this->t('已生成待审核隔离方案，尚未移动文件。'));
      $form_state->setRedirectUrl(Url::fromRoute('xedc_core.files_task', ['task' => $result['task_id']]));
    }
    else {
      $this->messenger()->addError($this->t('没有生成方案。'));
    }
  }

}
