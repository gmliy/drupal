<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\TaskRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access checks for import tasks. Hiding a button is not the check.
 */
class TaskAccess implements ContainerInjectionInterface {

  public function __construct(
    protected TaskRepository $tasks,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.tasks'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * View, retry, cancel, clean, reroll or roll back.
   */
  public function view(AccountInterface $account, int|string $task): AccessResultInterface {
    if (!ctype_digit((string) $task)) {
      return AccessResult::forbidden();
    }
    $task = (int) $task;
    $row = $this->tasks->load($task);
    if (!$row) {
      return AccessResult::forbidden()->cachePerPermissions();
    }
    $node = $this->entityTypeManager->getStorage('node')->load($row->nid);
    if ($account->hasPermission('administer xedc image imports')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    $owns = (int) $row->uid === (int) $account->id();
    $canEdit = $node instanceof NodeInterface && $node->access('update', $account);
    if ($owns && $canEdit && $account->hasPermission('import photo archives')) {
      return AccessResult::allowed()->cachePerUser()->cachePerPermissions()->addCacheableDependency($node);
    }
    return AccessResult::forbidden()->cachePerUser()->cachePerPermissions();
  }

  /**
   * Publish requires the publish permission in addition to task access.
   */
  public function publish(AccountInterface $account, int $task): AccessResultInterface {
    $view = $this->view($account, $task);
    if (!$view->isAllowed()) {
      return $view;
    }
    if (!$account->hasPermission('publish xedc image imports') && !$account->hasPermission('administer xedc image imports')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }
    return AccessResult::allowed()->cachePerPermissions();
  }

}
