<?php

declare(strict_types=1);

namespace Drupal\xedc_admin_nav\Hook;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;

/**
 * Replaces the core Navigation bar on the public theme.
 */
class FrontNavHooks {

  /**
   * Registers the front drawer template.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'xedc_admin_nav_front' => [
        'variables' => [
          'nav' => [],
          'brand_url' => '',
          'site_name' => '',
        ],
      ],
    ];
  }

  /**
   * Drops the core sidebar on the public theme and renders the shared drawer.
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    $account = \Drupal::currentUser();
    if (!$account->hasPermission('access navigation')) {
      return;
    }
    if (\Drupal::theme()->getActiveTheme()->getName() !== 'xedc') {
      return;
    }
    if (!\Drupal::service('theme_handler')->themeExists('xedc_admin')) {
      return;
    }
    unset($variables['page_top']['navigation'], $variables['page_top']['top_bar']);
    $variables['page_top']['xedc_admin_nav'] = $this->buildDrawer();
  }

  /**
   * Puts content tabs back on the public theme after the top bar is removed.
   */
  #[Hook('block_build_local_tasks_block_alter', order: new OrderAfter(modules: ['navigation']))]
  public function restoreLocalTasks(array &$build, BlockPluginInterface $block): void {
    if ($block->getPluginId() !== 'local_tasks_block') {
      return;
    }
    if (\Drupal::theme()->getActiveTheme()->getName() !== 'xedc') {
      return;
    }
    if (!\Drupal::currentUser()->hasPermission('access navigation')) {
      return;
    }
    if (isset($build['#access']) && $build['#access'] === FALSE) {
      unset($build['#access']);
    }
  }

  /**
   * Prints content tabs on the public theme.
   *
   * Theme xedc has no local-tasks block. The core top bar was the only place
   * those links appeared, so removing the bar has to put the tabs back.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    $account = \Drupal::currentUser();
    if (!$account->hasPermission('access navigation')) {
      return;
    }
    if (\Drupal::theme()->getActiveTheme()->getName() !== 'xedc') {
      return;
    }
    $tasks = \Drupal::service('plugin.manager.block')->createInstance('local_tasks_block', [
      'primary' => TRUE,
      'secondary' => TRUE,
      'label_display' => '0',
    ])->build();
    $tasks['#weight'] = -1000;
    if (empty($tasks['#primary']) && empty($tasks['#secondary'])) {
      $variables['page']['content']['xedc_admin_nav_tasks'] = $tasks;
      return;
    }
    $wrapped = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-front-tasks']],
      '#weight' => -1000,
      'tasks' => $tasks,
    ];
    // Prepend. A parent that is already sorted would otherwise append this.
    $variables['page']['content'] = ['xedc_admin_nav_tasks' => $wrapped] + $variables['page']['content'];
    unset($variables['page']['content']['#sorted']);
  }

  /**
   * Drawer render array. Menu data comes from the admin theme, not a copy.
   *
   * @return array<string, mixed>
   *   A render array, empty when the theme include cannot be read.
   */
  private function buildDrawer(): array {
    $include = DRUPAL_ROOT . '/' . \Drupal::service('extension.list.theme')->getPath('xedc_admin') . '/includes/navigation.theme';
    if (!is_file($include)) {
      return [];
    }
    require_once $include;
    $nav = _xedc_admin_navigation();
    $shortcut = _xedc_admin_shortcut_cache();
    $menu = _xedc_admin_navigation_cache();
    $build = [
      '#theme' => 'xedc_admin_nav_front',
      '#nav' => $nav,
      '#brand_url' => _xedc_admin_brand_url(),
      '#site_name' => (string) (\Drupal::config('system.site')->get('name') ?: 'XEDC'),
      '#attached' => [
        'library' => ['xedc_admin_nav/front'],
      ],
      '#cache' => [
        'tags' => Cache::mergeTags(['config:system.site'], $shortcut['tags'], $menu['tags']),
        'contexts' => Cache::mergeContexts(
          ['user', 'user.permissions', 'route', 'languages:language_interface'],
          $shortcut['contexts'],
          $menu['contexts'],
        ),
      ],
    ];
    return $build;
  }

}
