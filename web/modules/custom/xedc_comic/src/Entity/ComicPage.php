<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;

/**
 * One page image inside a comic chapter.
 */
#[ContentEntityType(
  id: 'comic_page',
  label: new TranslatableMarkup('漫画页'),
  label_collection: new TranslatableMarkup('漫画页'),
  label_singular: new TranslatableMarkup('漫画页'),
  label_plural: new TranslatableMarkup('漫画页'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
  ],
  handlers: [
    'access' => ComicPageAccessControlHandler::class,
    'storage_schema' => ComicPageStorageSchema::class,
    'views_data' => 'Drupal\views\EntityViewsData',
  ],
  admin_permission: 'manage comic pages',
  base_table: 'comic_page',
  list_cache_tags: ['comic_page_list'],
)]
class ComicPage extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['chapter_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('章节 ID'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['image_file_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('文件 ID'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['page_number'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('页码'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['original_filename'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('原始文件名'))
      ->setSetting('max_length', 512);

    $fields['image_width'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('宽度'))
      ->setSetting('unsigned', TRUE);

    $fields['image_height'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('高度'))
      ->setSetting('unsigned', TRUE);

    $fields['file_size'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('文件大小'))
      ->setSetting('unsigned', TRUE)
      ->setSetting('size', 'big');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('创建时间'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('更新时间'));

    return $fields;
  }

  public function getChapterId(): int {
    return (int) $this->get('chapter_id')->value;
  }

  public function getPageNumber(): int {
    return (int) $this->get('page_number')->value;
  }

  public function getFileId(): int {
    return (int) $this->get('image_file_id')->value;
  }

  public function getFile(): ?FileInterface {
    $fid = $this->getFileId();
  if ($fid < 1) {
      return NULL;
    }
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
    return $file instanceof FileInterface ? $file : NULL;
  }

}