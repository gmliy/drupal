<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Plugin\views\style;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsStyle;
use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Grid wrapper for comic cards.
 */
#[ViewsStyle(
  id: 'xedc_comic_card_grid',
  title: new TranslatableMarkup('漫画卡片网格'),
  help: new TranslatableMarkup('使用前台漫画卡片样式展示行结果。'),
  theme: 'xedc_comic_view_grid',
  display_types: ['normal'],
)]
final class ComicCardGrid extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = TRUE;

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['#attached']['library'][] = 'xedc_comic/list';
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['columns'] = ['default' => 'auto'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form['columns'] = [
      '#type' => 'textfield',
      '#title' => $this->t('列数（CSS auto 可填 auto）'),
      '#default_value' => $this->options['columns'],
    ];
  }

}