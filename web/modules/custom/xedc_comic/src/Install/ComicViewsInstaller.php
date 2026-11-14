<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Install;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\views\Entity\View;

/**
 * Installs XEDC Comic Views config on enabled sites.
 */
final class ComicViewsInstaller {

  public function __construct(
    private readonly ModuleExtensionList $modules,
  ) {}

  /**
   * @return list<string>
   */
  public function installMissing(): array {
    if (!\Drupal::moduleHandler()->moduleExists('views')) {
      return ['Views 模块未启用，跳过。'];
    }
    $path = $this->modules->getPath('xedc_comic') . '/config/install';
    $storage = new FileStorage($path);
    $ids = ['xedc_comic_list', 'xedc_comic_category', 'xedc_comic_tag'];
    $messages = [];
    foreach ($ids as $id) {
      $name = 'views.view.' . $id;
      if (View::load($id)) {
        $messages[] = $id . ' 已存在，跳过。';
        continue;
      }
      if (!$storage->exists($name)) {
        $messages[] = $name . ' 配置缺失。';
        continue;
      }
      $data = $storage->read($name);
      if (!is_array($data)) {
        $messages[] = $id . ' 配置无效。';
        continue;
      }
      View::create($data)->save();
      $messages[] = $id . ' 已安装。';
    }
    return $messages;
  }

  /**
   * Adds block displays cloned from default (idempotent).
   *
   * @return list<string>
   */
  public function ensureBlockDisplays(): array {
    if (!\Drupal::moduleHandler()->moduleExists('views')) {
      return ['Views 未启用。'];
    }
    $map = [
      'xedc_comic_list' => ['block_xedc_comic_list', '漫画列表（块）'],
      'xedc_comic_category' => ['block_xedc_comic_category', '漫画分类（块）'],
      'xedc_comic_tag' => ['block_xedc_comic_tag', '漫画标签（块）'],
    ];
    $messages = [];
    foreach ($map as $viewId => [$blockId, $title]) {
      $view = View::load($viewId);
      if (!$view) {
        $messages[] = $viewId . ' 不存在，跳过块。';
        continue;
      }
      $displays = $view->get('display');
      if (isset($displays[$blockId])) {
        $messages[] = $viewId . ' 块已存在。';
        continue;
      }
      if (!isset($displays['default'])) {
        $messages[] = $viewId . ' 无 default。';
        continue;
      }
      $block = $displays['default'];
      $block['id'] = $blockId;
      $block['display_title'] = $title;
      $block['display_plugin'] = 'block';
      $block['position'] = (int) max(array_column($displays, 'position')) + 1;
      $displays[$blockId] = $block;
      $view->set('display', $displays);
      $view->save();
      $messages[] = $viewId . ' 已添加 ' . $blockId . '。';
    }
    return $messages;
  }

}