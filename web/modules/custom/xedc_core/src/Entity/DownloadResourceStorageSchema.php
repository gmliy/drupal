<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Entity;

use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Extra indexes for resource lists.
 */
final class DownloadResourceStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping) {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);
    if ($table_name === 'xedc_download' && $storage_definition->getName() === 'created') {
      $schema['indexes']['xedc_download_created'] = ['created'];
    }
    return $schema;
  }

}
