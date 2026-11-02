<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_core\Ops\Cleanup\CleanupRepository;
use Drupal\xedc_core\Ops\Cleanup\FileCleanupManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Asks which duplicate file to keep, then stores an unconfirmed plan.
 */
class MergePlanForm extends FormBase {

  public function __construct(
    protected CleanupRepository $repository,
    protected FileCleanupManager $manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.cleanup_repository'),
      $container->get('xedc_core.cleanup_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_core_merge_plan';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $group = 0): array {
    $members = $this->repository->members($group);
    $options = [];
    foreach ($members as $member) {
      $options[(int) $member['fid']] = $member['fid'] . ' · ' . $member['filename'] . ' · ' . $member['uri'];
    }
    $form['group'] = ['#type' => 'value', '#value' => $group];
    $form['keep'] = [
      '#type' => 'radios',
      '#title' => $this->t('保留文件'),
      '#options' => $options,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('生成合并方案'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->manager->createMergePlan(
      (int) $form_state->getValue('group'),
      (int) $form_state->getValue('keep'),
      (int) $this->currentUser()->id(),
    );
    foreach ($result['errors'] as $error) {
      $this->messenger()->addError($error);
    }
    if ($result['ok']) {
      $this->messenger()->addStatus($this->t('已生成待审核方案，尚未修改任何引用。'));
      $form_state->setRedirectUrl(Url::fromRoute('xedc_core.files_task', ['task' => $result['task_id']]));
    }
  }

}
