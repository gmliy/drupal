<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\xedc_core\Ops\Cleanup\CleanupRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Permission checks for a specific cleanup task.
 */
class CleanupAccess implements ContainerInjectionInterface {

  public function __construct(
    protected CleanupRepository $repository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.cleanup_repository'));
  }

  public function review(AccountInterface $account, int $task): AccessResultInterface {
    if (!$this->repository->loadTask($task)) {
      return AccessResult::forbidden()->cachePerPermissions();
    }
    return AccessResult::allowedIfHasPermission($account, 'review xedc cleanup plans');
  }

  public function purge(AccountInterface $account, int $task): AccessResultInterface {
    return $this->followUp($account, $task, 'confirm xedc file purge');
  }

  public function restore(AccountInterface $account, int $task): AccessResultInterface {
    return $this->followUp($account, $task, 'restore xedc quarantined files');
  }

  public function confirm(AccountInterface $account, int $task): AccessResultInterface {
    $row = $this->repository->loadTask($task);
    if (!$row) {
      return AccessResult::forbidden()->cachePerPermissions();
    }
    $permission = match ((string) $row['task_type']) {
      'merge' => 'confirm xedc reference merge',
      'quarantine' => 'confirm xedc file quarantine',
      'purge' => 'confirm xedc file purge',
      'restore' => 'restore xedc quarantined files',
      default => '',
    };
    if ($permission === '') {
      return AccessResult::forbidden()->cachePerPermissions();
    }
    return AccessResult::allowedIfHasPermission($account, $permission);
  }

  private function followUp(AccountInterface $account, int $task, string $permission): AccessResultInterface {
    $row = $this->repository->loadTask($task);
    if (!$row || $row['task_type'] !== 'quarantine' || $row['status'] !== 'success') {
      return AccessResult::forbidden()->cachePerPermissions();
    }
    return AccessResult::allowedIfHasPermission($account, $permission);
  }

}
