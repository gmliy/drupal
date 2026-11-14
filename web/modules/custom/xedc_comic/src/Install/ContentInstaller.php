<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Install;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Creates comic bundles, fields and display modes without touching config/sync.
 */
final class ContentInstaller {

  /**
   * Idempotent repair: fields + form/view displays (safe on enabled sites).
   */
  public function repairContentModel(): string {
    $created = [];
    $this->ensureVocabulary();
    if ($this->ensureNodeType('comic', '漫画')) {
      $created[] = 'node.type.comic';
    }
    if ($this->ensureNodeType('comic_chapter', '漫画章节')) {
      $created[] = 'node.type.comic_chapter';
    }
    $this->ensureComicFields();
    $this->ensureChapterFields();
    $this->syncFormDisplay('comic', $this->comicFormComponents(), $this->comicHiddenFormFields());
    $this->syncFormDisplay('comic_chapter', $this->chapterFormComponents(), $this->chapterHiddenFormFields());
    $this->syncViewDisplay('comic', $this->comicViewComponents());
    $this->syncViewDisplay('comic_chapter', $this->chapterViewComponents());
    return $created === []
      ? '漫画内容模型与表单显示已校验并补齐。'
      : '漫画内容模型已创建：' . implode(', ', $created) . '；表单显示已补齐。';
  }

  /**
   * @deprecated Use repairContentModel(). Kept for hook_install().
   */
  public function installContentModel(): void {
    $this->repairContentModel();
  }

  /**
   * Removes bundles created by this module when empty.
   */
  public function uninstallContentModel(): void {
    foreach (['comic_chapter', 'comic'] as $bundle) {
      $type = NodeType::load($bundle);
      if (!$type) {
        continue;
      }
      $count = (int) \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->count()
        ->execute();
      if ($count === 0) {
        $this->removeBundleFields($bundle);
        $type->delete();
      }
    }
    $voc = Vocabulary::load('comic_category');
    if ($voc) {
      $tids = \Drupal::entityQuery('taxonomy_term')
        ->accessCheck(FALSE)
        ->condition('vid', 'comic_category')
        ->count()
        ->execute();
      if ((int) $tids === 0) {
        $voc->delete();
      }
    }
  }

  private function ensureVocabulary(): void {
    if (Vocabulary::load('comic_category')) {
      return;
    }
    Vocabulary::create([
      'vid' => 'comic_category',
      'name' => '漫画分类',
      'description' => '漫画栏目分类。',
    ])->save();
  }

  private function ensureNodeType(string $id, string $label): bool {
    if (NodeType::load($id)) {
      return FALSE;
    }
    NodeType::create([
      'type' => $id,
      'name' => $label,
      'description' => $label,
      'new_revision' => TRUE,
    ])->save();
    return TRUE;
  }

  private function ensureComicFields(): void {
    $bundle = 'comic';
    $this->ensureField('node', 'field_comic_cover', 'image', $bundle, '封面', [
      'uri_scheme' => 'public',
      'file_extensions' => 'png jpg jpeg webp gif',
      'file_directory' => 'comic-covers',
    ]);
    $this->ensureField('node', 'field_comic_author', 'string', $bundle, '作者', []);
    $this->ensureField('node', 'field_comic_category', 'entity_reference', $bundle, '分类', [
      'target_type' => 'taxonomy_term',
      'handler_settings' => ['target_bundles' => ['comic_category' => 'comic_category']],
    ]);
    $this->ensureField('node', 'field_comic_tags', 'entity_reference', $bundle, '标签', [
      'target_type' => 'taxonomy_term',
      'handler_settings' => ['target_bundles' => ['tags' => 'tags']],
    ]);
    $this->ensureField('node', 'field_comic_description', 'text_long', $bundle, '简介', []);
    $this->ensureField('node', 'field_comic_status', 'list_string', $bundle, '连载状态', [
      'allowed_values' => [
        'ongoing' => '连载中',
        'completed' => '已完结',
        'paused' => '暂停',
      ],
    ]);
    $this->ensureField('node', 'field_comic_update_time', 'timestamp', $bundle, '最后更新时间', []);
    $this->ensureField('node', 'field_comic_sort', 'integer', $bundle, '排序', []);
    $this->ensureField('node', 'field_comic_views', 'integer', $bundle, '阅读量', []);
    $this->ensureField('node', 'field_comic_favorites', 'integer', $bundle, '收藏量', []);
  }

  private function ensureChapterFields(): void {
    $bundle = 'comic_chapter';
    $this->ensureField('node', 'field_comic_parent', 'entity_reference', $bundle, '所属漫画', [
      'target_type' => 'node',
      'handler_settings' => ['target_bundles' => ['comic' => 'comic']],
    ], TRUE);
    $this->ensureField('node', 'field_comic_number', 'decimal', $bundle, '章节序号', [
      'precision' => 10,
      'scale' => 2,
    ]);
    $this->ensureField('node', 'field_comic_vip', 'boolean', $bundle, 'VIP 章节', []);
    $this->ensureField('node', 'field_comic_free', 'boolean', $bundle, '免费章节', []);
    $this->ensureField('node', 'field_comic_publish_time', 'timestamp', $bundle, '发布时间', []);
    $this->ensureField('node', 'field_comic_page_count', 'integer', $bundle, '图片数量', []);
    // 章节状态：与漫画连载 field_comic_status 分离，避免共用 list 存储冲突。
    $this->ensureField('node', 'field_comic_chapter_status', 'list_string', $bundle, '章节状态', [
      'allowed_values' => [
        'draft' => '草稿',
        'published' => '已发布',
        'hidden' => '已下架',
      ],
    ]);
  }

  /**
   * @param array<string, mixed> $settings
   */
  private function ensureField(
    string $entity,
    string $name,
    string $type,
    string $bundle,
    string $label,
    array $settings = [],
    bool $required = FALSE,
  ): void {
    [$storageSettings, $fieldSettings] = $this->splitFieldSettings($type, $settings);
    if (!FieldStorageConfig::loadByName($entity, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entity,
        'type' => $type,
        'cardinality' => $type === 'entity_reference' && $name === 'field_comic_tags' ? -1 : 1,
        'settings' => $storageSettings,
      ])->save();
    }
    elseif ($name === 'field_comic_tags' && FieldStorageConfig::loadByName($entity, $name)->getCardinality() === 1) {
      // 允许多标签；仅在仍为单值时放宽（无节点数据冲突时安全）。
      $storage = FieldStorageConfig::loadByName($entity, $name);
      if ($storage) {
        $storage->setCardinality(-1);
        $storage->save();
      }
    }
    if (!FieldConfig::loadByName($entity, $bundle, $name)) {
      FieldConfig::create([
        'field_storage' => FieldStorageConfig::loadByName($entity, $name),
        'bundle' => $bundle,
        'label' => $label,
        'required' => $required,
        'settings' => $fieldSettings,
      ])->save();
    }
  }

  /**
   * @param array<string, mixed> $settings
   * @return array{0: array<string, mixed>, 1: array<string, mixed>}
   */
  private function splitFieldSettings(string $type, array $settings): array {
    return match ($type) {
      'list_string' => [['allowed_values' => $settings['allowed_values'] ?? []], []],
      'entity_reference' => [
        ['target_type' => $settings['target_type'] ?? 'node'],
        [
          'handler' => ($settings['target_type'] ?? 'node') === 'taxonomy_term'
            ? 'default:taxonomy_term'
            : 'default:node',
          'handler_settings' => $settings['handler_settings'] ?? [],
        ],
      ],
      'image' => [
        [
          'target_type' => 'file',
          'display_field' => FALSE,
          'display_default' => FALSE,
          'uri_scheme' => $settings['uri_scheme'] ?? 'public',
        ],
        [
          'file_directory' => $settings['file_directory'] ?? '',
          'file_extensions' => $settings['file_extensions'] ?? 'png gif jpg jpeg',
          'alt_field' => FALSE,
          'title_field' => FALSE,
          'max_filesize' => '',
          'handler' => 'default:file',
          'handler_settings' => [],
        ],
      ],
      'decimal' => [
        ['precision' => (int) ($settings['precision'] ?? 10), 'scale' => (int) ($settings['scale'] ?? 2)],
        [],
      ],
      'boolean' => [[], ['on_label' => '是', 'off_label' => '否']],
      default => [[], []],
    };
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function comicFormComponents(): array {
    return [
      'title' => [
        'type' => 'string_textfield',
        'weight' => -10,
        'region' => 'content',
        'settings' => ['size' => 60, 'placeholder' => ''],
      ],
      'field_comic_cover' => [
        'type' => 'image_image',
        'weight' => 0,
        'region' => 'content',
        'settings' => ['progress_indicator' => 'throbber', 'preview_image_style' => 'thumbnail'],
      ],
      'field_comic_author' => [
        'type' => 'string_textfield',
        'weight' => 1,
        'region' => 'content',
        'settings' => ['size' => 60, 'placeholder' => ''],
      ],
      'field_comic_category' => [
        'type' => 'options_select',
        'weight' => 2,
        'region' => 'content',
        'settings' => [],
      ],
      'field_comic_tags' => [
        'type' => 'entity_reference_autocomplete_tags',
        'weight' => 3,
        'region' => 'content',
        'settings' => ['match_operator' => 'CONTAINS', 'match_limit' => 10, 'size' => 60, 'placeholder' => ''],
      ],
      'field_comic_description' => [
        'type' => 'text_textarea',
        'weight' => 4,
        'region' => 'content',
        'settings' => ['rows' => 5, 'placeholder' => ''],
      ],
      'field_comic_status' => [
        'type' => 'options_select',
        'weight' => 5,
        'region' => 'content',
        'settings' => [],
      ],
      'field_comic_sort' => [
        'type' => 'number',
        'weight' => 6,
        'region' => 'content',
        'settings' => ['placeholder' => ''],
      ],
    ];
  }

  /**
   * @return list<string>
   */
  private function comicHiddenFormFields(): array {
    return ['field_comic_update_time', 'field_comic_views', 'field_comic_favorites'];
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function chapterFormComponents(): array {
    return [
      'title' => [
        'type' => 'string_textfield',
        'weight' => -10,
        'region' => 'content',
        'settings' => ['size' => 60, 'placeholder' => ''],
      ],
      'field_comic_parent' => [
        'type' => 'entity_reference_autocomplete',
        'weight' => 0,
        'region' => 'content',
        'settings' => ['match_operator' => 'CONTAINS', 'match_limit' => 10, 'size' => 60, 'placeholder' => ''],
      ],
      'field_comic_number' => [
        'type' => 'number',
        'weight' => 1,
        'region' => 'content',
        'settings' => ['placeholder' => ''],
      ],
      'field_comic_vip' => [
        'type' => 'boolean_checkbox',
        'weight' => 2,
        'region' => 'content',
        'settings' => ['display_label' => TRUE],
      ],
      'field_comic_free' => [
        'type' => 'boolean_checkbox',
        'weight' => 3,
        'region' => 'content',
        'settings' => ['display_label' => TRUE],
      ],
      'field_comic_publish_time' => [
        'type' => 'datetime_timestamp',
        'weight' => 4,
        'region' => 'content',
        'settings' => [],
      ],
      'field_comic_chapter_status' => [
        'type' => 'options_select',
        'weight' => 5,
        'region' => 'content',
        'settings' => [],
      ],
    ];
  }

  /**
   * @return list<string>
   */
  private function chapterHiddenFormFields(): array {
    return ['field_comic_page_count'];
  }

  /**
   * @param array<string, array<string, mixed>> $components
   * @param list<string> $hidden
   */
  private function syncFormDisplay(string $bundle, array $components, array $hidden = []): void {
    $id = "node.$bundle.default";
    $display = EntityFormDisplay::load($id);
    if (!$display) {
      $display = EntityFormDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }
    $manager = \Drupal::service('entity_field.manager');
    foreach ($components as $fieldName => $component) {
      $definitions = $manager->getFieldDefinitions('node', $bundle);
      if (!isset($definitions[$fieldName])) {
        continue;
      }
      $display->setComponent($fieldName, $component);
    }
    foreach ($hidden as $fieldName) {
      if ($manager->getFieldDefinitions('node', $bundle)[$fieldName] ?? NULL) {
        $display->removeComponent($fieldName);
      }
    }
    $display->save();
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function comicViewComponents(): array {
    return [
      'field_comic_cover' => [
        'type' => 'image',
        'weight' => 0,
        'label' => 'above',
        'settings' => ['image_style' => '', 'image_link' => ''],
      ],
      'field_comic_author' => ['type' => 'string', 'weight' => 1, 'label' => 'inline'],
      'field_comic_category' => ['type' => 'entity_reference_label', 'weight' => 2, 'label' => 'inline'],
      'field_comic_tags' => ['type' => 'entity_reference_label', 'weight' => 3, 'label' => 'above'],
      'field_comic_description' => ['type' => 'text_default', 'weight' => 4, 'label' => 'above'],
      'field_comic_status' => ['type' => 'list_default', 'weight' => 5, 'label' => 'inline'],
    ];
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function chapterViewComponents(): array {
    return [
      'field_comic_parent' => ['type' => 'entity_reference_label', 'weight' => 0, 'label' => 'inline'],
      'field_comic_number' => ['type' => 'number_decimal', 'weight' => 1, 'label' => 'inline'],
      'field_comic_vip' => ['type' => 'boolean', 'weight' => 2, 'label' => 'inline'],
      'field_comic_free' => ['type' => 'boolean', 'weight' => 3, 'label' => 'inline'],
      'field_comic_publish_time' => ['type' => 'timestamp', 'weight' => 4, 'label' => 'inline'],
      'field_comic_chapter_status' => ['type' => 'list_default', 'weight' => 5, 'label' => 'inline'],
    ];
  }

  /**
   * @param array<string, array<string, mixed>> $components
   */
  private function syncViewDisplay(string $bundle, array $components): void {
    $id = "node.$bundle.default";
    $display = EntityViewDisplay::load($id);
    if (!$display) {
      $display = EntityViewDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }
    foreach ($components as $fieldName => $component) {
      if (\Drupal::service('entity_field.manager')->getFieldDefinitions('node', $bundle)[$fieldName] ?? NULL) {
        $display->setComponent($fieldName, $component);
      }
    }
    $display->save();
  }

  private function removeBundleFields(string $bundle): void {
    $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $bundle);
    foreach ($fields as $name => $definition) {
      if (!$definition->isConfigurable() || !str_starts_with($name, 'field_')) {
        continue;
      }
      FieldConfig::loadByName('node', $bundle, $name)?->delete();
    }
  }

}