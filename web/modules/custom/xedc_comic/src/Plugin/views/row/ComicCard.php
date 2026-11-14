<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Plugin\views\row;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\views\Attribute\ViewsRow;
use Drupal\views\Plugin\views\row\RowPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a comic node as a XEDC comic card.
 */
#[ViewsRow(
  id: 'xedc_comic_card',
  title: new TranslatableMarkup('漫画卡片'),
  base: ['node'],
)]
final class ComicCard extends RowPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render($row) {
    $node = $row->_entity;
    if (!$node instanceof NodeInterface || $node->bundle() !== 'comic') {
      return [];
    }
    $cards = \Drupal::service('xedc_comic.manager')->comicCardsBatch([$node]);
    $item = $cards[0] ?? \Drupal::service('xedc_comic.manager')->comicCard($node);
    return [
      '#theme' => 'comic_card',
      '#item' => $item,
    ];
  }

}