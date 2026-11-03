<?php

declare(strict_types=1);

namespace Drupal\xedc_moderation\Plugin\Action;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\workflows\WorkflowInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Changes a moderated node's state when the workflow allows it.
 */
#[Action(
  id: 'xedc_moderation_set_state',
  label: new TranslatableMarkup('更改审核状态'),
  type: 'node',
  deriver: SetModerationStateDeriver::class,
)]
final class SetModerationState extends ViewsBulkOperationsActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly ModerationInformationInterface $moderationInfo,
    protected readonly AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('content_moderation.moderation_information'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof ContentEntityInterface) {
      return [
        'message' => (string) $this->t('跳过无法审核的内容'),
        'type' => 'warning',
      ];
    }

    $label = (string) $this->getPluginDefinition()['label'];
    if ($this->targetState() === 'published' && $entity->bundle() === 'photo' && \Drupal::hasService('xedc_core.tasks') && \Drupal::service('xedc_core.tasks')->blocksPublish((int) $entity->id())) {
      return [
        'message' => (string) $this->t('「@title」图片还在处理或有失败图片，未发布。', ['@title' => $entity->label()]),
        'type' => 'warning',
      ];
    }
    try {
      $entity->set('moderation_state', $this->targetState());
      if ($entity instanceof RevisionLogInterface) {
        $entity->setRevisionLogMessage((string) $this->t('批量审核：@action', ['@action' => $label]));
        $entity->setRevisionUserId((int) $this->currentUser->id());
        $entity->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      }
      $entity->save();
    }
    catch (\Throwable $e) {
      \Drupal::logger('xedc_moderation')->warning('Bulk moderation failed for @id: @message', [
        '@id' => $entity->id(),
        '@message' => $e->getMessage(),
      ]);
      return [
        'message' => (string) $this->t('「@title」未更新', ['@title' => $entity->label()]),
        'type' => 'warning',
      ];
    }

    return $this->t('@action', ['@action' => $label]);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = $this->checkAccess($object, $account ?? $this->currentUser);
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * Allows the change only when this workflow transition exists for the user.
   */
  private function checkAccess(mixed $object, AccountInterface $account): AccessResult {
    if (!$object instanceof ContentEntityInterface || !$object->hasField('moderation_state') || !$this->moderationInfo->isModeratedEntity($object)) {
      return AccessResult::forbidden('该内容未加入审核流程');
    }

    $target = $this->targetState();
    $current = (string) $object->get('moderation_state')->value;
    if ($current === '' || $current === $target) {
      return AccessResult::forbidden('已是目标状态');
    }

    $workflow = $this->moderationInfo->getWorkflowForEntity($object);
    if (!$workflow instanceof WorkflowInterface) {
      return AccessResult::forbidden('找不到审核工作流');
    }

    $type = $workflow->getTypePlugin();
    if (!$type->hasState($target) || !$type->hasTransitionFromStateToState($current, $target)) {
      return AccessResult::forbidden('当前工作流不允许此变更')->addCacheableDependency($workflow);
    }

    $transition = $type->getTransitionFromStateToState($current, $target);
    $result = AccessResult::allowedIfHasPermission($account, 'use ' . $workflow->id() . ' transition ' . $transition->id());
    if (!$result->isAllowed()) {
      return AccessResult::forbidden('没有使用该审核操作的权限')->addCacheableDependency($workflow);
    }

    return $result
      ->andIf($object->access('update', $account, TRUE))
      ->addCacheableDependency($workflow);
  }

  /**
   * Target moderation state from the action derivative.
   */
  private function targetState(): string {
    return (string) $this->getPluginDefinition()['state'];
  }

}
