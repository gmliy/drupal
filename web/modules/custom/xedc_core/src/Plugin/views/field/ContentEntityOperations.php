<?php

namespace Drupal\xedc_core\Plugin\views\field;

use Drupal\Core\Cache\Cache;
use Drupal\views\Plugin\views\field\EntityOperations;
use Drupal\views\ResultRow;

/**
 * Caches content-list operation menus for one user and one return URL.
 *
 * The links are ordinary edit, view, delete, and translate URLs. They do not
 * contain a form token. The render cache varies by user, so one account never
 * receives another account's links.
 */
class ContentEntityOperations extends EntityOperations {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    if ($this->view->id() !== 'content') {
      return parent::render($values);
    }

    $entity = $this->getEntity($values);
    if (empty($entity)) {
      return '';
    }
    $entity = $this->getEntityTranslationByRelationship($entity, $values);
    $keys = [
      'xedc_content_operations',
      $entity->getEntityTypeId(),
      (string) $entity->id(),
      $entity->language()->getId(),
      hash('sha256', (string) \Drupal::destination()->get()),
    ];
    $contexts = Cache::mergeContexts($this->requiredCacheContexts(), [
      'user',
      'languages:language_url',
    ]);
    $tags = Cache::mergeTags($entity->getCacheTags(), [
      'user:' . \Drupal::currentUser()->id(),
    ]);
    $cached = \Drupal::service('render_cache')->get([
      '#cache' => [
        'keys' => $keys,
        'contexts' => $contexts,
        'tags' => $tags,
      ],
    ]);
    if ($cached !== FALSE) {
      return $cached;
    }

    $build = parent::render($values);
    if (!is_array($build)) {
      return $build;
    }
    $build['#cache']['keys'] = $keys;
    $build['#cache']['contexts'] = Cache::mergeContexts($build['#cache']['contexts'] ?? [], $contexts);
    $build['#cache']['tags'] = Cache::mergeTags($build['#cache']['tags'] ?? [], $tags);
    return $build;
  }

  /**
   * Contexts the renderer adds before it reads or writes the render cache.
   *
   * @return string[]
   *   Cache context IDs.
   */
  protected function requiredCacheContexts(): array {
    $config = \Drupal::getContainer()->getParameter('renderer.config');
    return $config['required_cache_contexts'] ?? [];
  }

}
