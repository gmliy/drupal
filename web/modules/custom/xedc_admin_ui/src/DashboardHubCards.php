<?php

declare(strict_types=1);

namespace Drupal\xedc_admin_ui;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Optional hub cards on the XEDC dashboard (module entry points).
 */
final class DashboardHubCards {

  /**
   * @return list<array{title: string, description: string, url: string, icon: string}>
   */
  public function cards(AccountInterface $account): array {
    $cards = [];
    if (\Drupal::moduleHandler()->moduleExists('xedc_comic')) {
      try {
        $url = Url::fromRoute('xedc_comic.admin');
        if ($url->access($account)) {
          $cards[] = [
            'title' => (string) t('漫画中心'),
            'description' => (string) t('管理漫画、章节、漫画图片、PDF 导入和阅读统计。'),
            'url' => $url->toString(),
            'icon' => 'comic',
          ];
        }
      }
      catch (\Throwable) {
        // Route unavailable.
      }
    }
    return $cards;
  }

}