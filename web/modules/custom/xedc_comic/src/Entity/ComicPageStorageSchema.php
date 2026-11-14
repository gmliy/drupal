<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Entity;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Indexes for chapter page lookups.
 */
class ComicPageStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema['comic_page']['indexes']['chapter_page'] = ['chapter_id', 'page_number'];
    $schema['comic_page']['indexes']['chapter'] = ['chapter_id'];
    return $schema;
  }

}