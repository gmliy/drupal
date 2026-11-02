<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\xedc_core\Engagement\ExternalUrlGuard;
use Drupal\xedc_core\Form\DownloadResourceForm;

/**
 * A downloadable resource attached to one photo, video or article.
 */
#[ContentEntityType(
  id: 'xedc_download',
  label: new TranslatableMarkup('下载资源'),
  label_collection: new TranslatableMarkup('下载资源'),
  label_singular: new TranslatableMarkup('下载资源'),
  label_plural: new TranslatableMarkup('下载资源'),
  entity_keys: [
    'id' => 'id',
    'label' => 'title',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
    'published' => 'status',
  ],
  handlers: [
    'access' => DownloadResourceAccessControlHandler::class,
    'list_builder' => DownloadResourceListBuilder::class,
    'storage_schema' => DownloadResourceStorageSchema::class,
    'form' => [
      'default' => DownloadResourceForm::class,
      'add' => DownloadResourceForm::class,
      'edit' => DownloadResourceForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => DefaultHtmlRouteProvider::class,
    ],
  ],
  links: [
    'canonical' => '/admin/xedc/downloads/{xedc_download}',
    'collection' => '/admin/xedc/downloads',
    'add-form' => '/admin/xedc/downloads/add',
    'edit-form' => '/admin/xedc/downloads/{xedc_download}/edit',
    'delete-form' => '/admin/xedc/downloads/{xedc_download}/delete',
  ],
  admin_permission: 'administer xedc downloads',
  base_table: 'xedc_download',
  list_cache_tags: ['xedc_download_list'],
)]
class DownloadResource extends ContentEntityBase implements EntityPublishedInterface {

  use EntityPublishedTrait;

  public const EXTENSIONS = 'zip jpg jpeg png webp gif mp4 pdf txt';

  public const SOURCES = [
    'upload' => '上传文件',
    'external' => '外部链接',
    'originals' => '图片原图',
    'zip' => '图片整组 ZIP',
  ];

  public const LEVELS = [
    'public' => '公开下载',
    'authenticated' => '登录会员',
    'vip' => 'VIP 专属',
  ];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('资源名称'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ]);

    $fields['parent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('所属内容'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default')
      ->setSetting('handler_settings', [
        'target_bundles' => [
          'photo' => 'photo',
          'video' => 'video',
          'article' => 'article',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 1,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ]);

    $fields['source_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('下载方式'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', self::SOURCES)
      ->setDefaultValue('upload')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 2,
      ]);

    $fields['file'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('上传文件'))
      ->setSetting('file_extensions', self::EXTENSIONS)
      ->setSetting('file_directory', 'xedc-downloads')
      ->setSetting('uri_scheme', 'private')
      ->setSetting('max_filesize', '2048 MB')
      ->setDisplayOptions('form', [
        'type' => 'file_generic',
        'weight' => 3,
      ]);

    $fields['external_url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('外部下载链接'))
      ->setSetting('max_length', 2048)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 4,
      ]);

    $fields['file_size'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('文件大小'))
      ->setSetting('unsigned', TRUE)
      ->setSetting('size', 'big')
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 5,
      ]);

    $fields['file_extension'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('文件格式'))
      ->setSetting('max_length', 16)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 6,
      ]);

    $fields['access_level'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('会员权限'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', self::LEVELS)
      ->setDefaultValue('authenticated')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 7,
      ]);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('下载说明'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 8,
        'settings' => ['rows' => 3],
      ]);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('排序'))
      ->setDefaultValue(0)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 9,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('创建时间'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('更新时间'));

    $fields += static::publishedBaseFieldDefinitions($entity_type);
    $fields['status']
      ->setLabel(new TranslatableMarkup('启用'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 10,
        'settings' => ['display_label' => TRUE],
      ]);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $this->assertReady();
    $parent = (int) $this->get('parent')->target_id;
    if ($parent > 0) {
      Cache::invalidateTags(['node:' . $parent, 'xedc_download_list']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    $file = $this->get('file')->entity;
    if ($file instanceof FileInterface && !$file->isPermanent()) {
      $file->setPermanent();
      $file->save();
    }
  }

  /**
   * Checks that exactly one source is active and the URL or file is safe.
   */
  public function assertReady(): void {
    $source = (string) $this->get('source_type')->value;
    $level = (string) $this->get('access_level')->value;
    if (!isset(self::SOURCES[$source]) || !isset(self::LEVELS[$level])) {
      throw new \InvalidArgumentException('下载方式或权限无效。');
    }
    $title = trim((string) $this->label());
    if ($title === '') {
      throw new \InvalidArgumentException('请填写资源名称。');
    }
    $node = $this->get('parent')->entity;
    if (!$node || !in_array($node->bundle(), ['photo', 'video', 'article'], TRUE)) {
      throw new \InvalidArgumentException('请选择图片、视频或文章。');
    }
    if (in_array($source, ['originals', 'zip'], TRUE) && $node->bundle() !== 'photo') {
      throw new \InvalidArgumentException('原图和整组 ZIP 只能用于图片内容。');
    }
    if ($source === 'upload') {
      $file = $this->get('file')->entity;
      if (!$file instanceof FileInterface) {
        throw new \InvalidArgumentException('请上传文件。');
      }
      $uri = (string) $file->getFileUri();
      if (!str_starts_with($uri, 'private://xedc-downloads/')) {
        throw new \InvalidArgumentException('文件必须保存在私有下载目录。');
      }
      $extension = strtolower(pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION));
      if (!in_array($extension, explode(' ', self::EXTENSIONS), TRUE)) {
        throw new \InvalidArgumentException('这个文件格式不在允许列表中。');
      }
      $this->set('external_url', '');
      $this->set('file_extension', $extension);
      $this->set('file_size', (int) $file->getSize());
    }
    elseif ($source === 'external') {
      if ($level === 'vip') {
        throw new \InvalidArgumentException('VIP 专属资源不能使用外部永久链接，请上传到私有文件。');
      }
      $url = \Drupal::service('xedc_core.external_url')->normalize((string) $this->get('external_url')->value);
      $this->set('external_url', $url);
      $this->set('file', []);
      $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
      $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
      $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: '';
      if ($extension !== '') {
        $this->set('file_extension', substr($extension, 0, 16));
      }
    }
    else {
      $this->set('file', []);
      $this->set('external_url', '');
      if ($source === 'zip') {
        $this->set('file_extension', 'zip');
      }
    }
  }

  public function externalUrl(): string {
    return \Drupal::service('xedc_core.external_url')->normalize((string) $this->get('external_url')->value);
  }

}
