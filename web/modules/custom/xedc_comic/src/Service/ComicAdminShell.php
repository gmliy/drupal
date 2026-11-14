<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Unified comic admin layout shell (nav, header, breadcrumbs).
 */
final class ComicAdminShell {

  use StringTranslationTrait;

  /**
   * Secondary navigation (route => label).
   *
   * @var array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  private const NAV = [
    'xedc_comic.admin' => '总览',
    'xedc_comic.admin_comics' => '漫画管理',
    'xedc_comic.admin_chapters' => '章节管理',
    // chapter_import is reached from chapters; no top-level nav link.
    'xedc_comic.admin_pages' => '图片管理',
    'xedc_comic.import' => '内容导入',
    'xedc_comic.import_status' => '导入任务',
    'xedc_comic.admin_stats' => '阅读统计',
    'xedc_comic.settings' => '设置',
  ];

  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Wrap page content in the comic admin layout.
   *
   * @param array<string, mixed> $content
   *   Main page build.
   * @param list<array{label: string, url: string, primary?: bool}> $actions
   *   Header actions.
   *
   * @return array<string, mixed>
   */
  public function page(
    array $content,
    string $activeRoute,
    string $title,
    string $description = '',
    array $actions = [],
  ): array {
    return [
      '#theme' => 'xedc_comic_admin_layout',
      '#active_route' => $activeRoute,
      '#page_title' => $title,
      '#page_description' => $description,
      '#header_actions' => $actions,
      '#nav_items' => $this->navigation($activeRoute),
      '#breadcrumb' => $this->breadcrumb($activeRoute, $title),
      '#content' => $content,
      '#attached' => [
        'library' => [
          'xedc_comic/admin',
          'xedc_admin/global',
        ],
      ],
      '#cache' => ['contexts' => ['user.permissions']],
    ];
  }

  /**
   * @return list<array{route: string, label: string, url: string, active: bool}>
   */
  public function navigation(string $activeRoute = ''): array {
    $items = [];
    foreach (self::NAV as $route => $label) {
      try {
        $url = Url::fromRoute($route);
      }
      catch (\Throwable) {
        continue;
      }
      if (!$url->access($this->currentUser)) {
        continue;
      }
      $items[] = [
        'route' => $route,
        'label' => (string) $this->t($label),
        'url' => $url->toString(),
        'active' => $route === $activeRoute,
      ];
    }
    return $items;
  }

  /**
   * @return list<array{label: string, url: string}>
   */
  private function breadcrumb(string $activeRoute, string $title): array {
    $crumbs = [
      [
        'label' => (string) $this->t('漫画中心'),
        'url' => Url::fromRoute('xedc_comic.admin')->toString(),
      ],
    ];
    if ($activeRoute !== 'xedc_comic.admin') {
      $crumbs[] = ['label' => $title, 'url' => ''];
    }
    return $crumbs;
  }

  /**
   * Header action if route is accessible.
   *
   * @param array<string, mixed> $parameters
   *
   * @return array{label: string, url: string, primary?: bool}|null
   */
  public function action(string $label, string $route, array $parameters = [], bool $primary = FALSE): ?array {
    try {
      $url = Url::fromRoute($route, $parameters);
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!$url->access($this->currentUser)) {
      return NULL;
    }
    return [
      'label' => $label,
      'url' => $url->toString(),
      'primary' => $primary,
    ];
  }

}