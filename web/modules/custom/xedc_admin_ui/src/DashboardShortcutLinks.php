<?php

declare(strict_types=1);

namespace Drupal\xedc_admin_ui;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\shortcut\ShortcutSetInterface;

/**
 * Reads the user's displayed Drupal Shortcut set for the dashboard.
 */
final class DashboardShortcutLinks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * @return list<array{label: string, url: string, source: string}>
   */
  public function shortcutLinks(AccountInterface $account): array {
    if (!\Drupal::moduleHandler()->moduleExists('shortcut')) {
      return [];
    }
    try {
      $set = $this->entityTypeManager->getStorage('shortcut_set')->getDisplayedToUser($account);
    }
    catch (\Throwable) {
      return [];
    }
    if (!$set instanceof ShortcutSetInterface) {
      return [];
    }
    $links = [];
    $repository = \Drupal::service('entity.repository');
    foreach ($set->getShortcuts() as $shortcut) {
      try {
        $shortcut = $repository->getTranslationFromContext($shortcut);
        $url = $shortcut->getUrl();
        if (!$url->access($account)) {
          continue;
        }
        $links[] = [
          'label' => (string) $shortcut->label(),
          'url' => $url->toString(),
          'source' => 'shortcut',
        ];
      }
      catch (\Throwable) {
        continue;
      }
    }
    return $links;
  }

  public function shortcutsManageUrl(AccountInterface $account): ?string {
    if (!\Drupal::moduleHandler()->moduleExists('shortcut') || !function_exists('shortcut_set_edit_access')) {
      return NULL;
    }
    try {
      $set = $this->entityTypeManager->getStorage('shortcut_set')->getDisplayedToUser($account);
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!$set instanceof ShortcutSetInterface) {
      return NULL;
    }
    if (!shortcut_set_edit_access($set)->isAllowed()) {
      return NULL;
    }
    try {
      $url = Url::fromRoute('entity.shortcut_set.customize_form', ['shortcut_set' => $set->id()]);
      return $url->access($account) ? $url->toString() : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  public function shortcutsSettingsUrl(AccountInterface $account): ?string {
    try {
      $url = Url::fromRoute('entity.shortcut_set.collection');
      return $url->access($account) ? $url->toString() : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}