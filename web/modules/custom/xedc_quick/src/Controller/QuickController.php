<?php

namespace Drupal\xedc_quick\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\xedc_quick\QuickStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin form that uploads photos and creates one photo node.
 */
class QuickController extends ControllerBase {

  public function __construct(
    protected QuickStore $store,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('xedc_quick.store'),
    );
  }

  /**
   * Cached [node bundle, media bundle, source field].
   *
   * @var array{0: string, 1: string, 2: string}|null
   */
  protected ?array $info = NULL;

  /**
   * @return array{0: string, 1: string, 2: string}
   *   Node bundle, media bundle and the media source field name.
   */
  protected function info(): array {
    if ($this->info) {
      return $this->info;
    }
    $manager = \Drupal::service('entity_field.manager');
    $bundle = array_key_first($manager->getFieldMap()['node']['field_images']['bundles'] ?? []) ?: 'photo';
    $definitions = $manager->getFieldDefinitions('node', $bundle);
    $definition = $definitions['field_images'] ?? NULL;
    $media_type = $definition ? array_key_first($definition->getSetting('handler_settings')['target_bundles'] ?? []) : 'image';
    $media_type = $media_type ?: 'image';
    $type = $this->entityTypeManager()->getStorage('media_type')->load($media_type);
    $source = $type ? ($type->getSource()->getConfiguration()['source_field'] ?? 'field_media_image') : 'field_media_image';
    return $this->info = [$bundle, $media_type, $source];
  }

  /**
   * Vocabulary id referenced by a node field.
   */
  protected function vocab(string $bundle, string $field): ?string {
    $definition = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $bundle)[$field] ?? NULL;
    if (!$definition) {
      return NULL;
    }
    $vocab = array_key_first($definition->getSetting('handler_settings')['target_bundles'] ?? []);
    return $vocab ? (string) $vocab : NULL;
  }

  /**
   * Resolves term names in one query, creating only the missing ones.
   *
   * @return array<int, array{target_id: int|string}>
   *   Entity reference values.
   */
  protected function terms(string $bundle, string $field, array $names): array {
    $vocab = $this->vocab($bundle, $field);
    if (!$vocab) {
      return [];
    }
    $clean = [];
    foreach ($names as $name) {
      $name = trim((string) $name);
      if ($name !== '' && !in_array($name, $clean, TRUE)) {
        $clean[] = $name;
      }
    }
    $clean = array_slice($clean, 0, 30);
    if (!$clean) {
      return [];
    }
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $by_name = [];
    foreach ($storage->loadByProperties(['name' => $clean, 'vid' => $vocab]) as $term) {
      $by_name[$term->label()] = $term;
    }
    $out = [];
    foreach ($clean as $name) {
      if (!isset($by_name[$name])) {
        $term = Term::create(['name' => $name, 'vid' => $vocab]);
        $term->save();
        $by_name[$name] = $term;
      }
      $out[] = ['target_id' => $by_name[$name]->id()];
    }
    return $out;
  }

  /**
   * Empty shell. Term lists are loaded by the suggest endpoint.
   */
  public function page(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['id' => 'xq-app'],
      '#attached' => [
        'library' => ['xedc_quick/app'],
      ],
      '#cache' => [
        'contexts' => ['user.permissions'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Term name suggestions for person, tag and category fields.
   */
  public function suggest(Request $request): JsonResponse {
    $key = (string) $request->query->get('field', '');
    $map = [
      'models' => 'field_model',
      'tags' => 'field_tags',
      'cats' => 'field_categories',
    ];
    if (!isset($map[$key])) {
      return new JsonResponse([], 400);
    }
    [$bundle] = $this->info();
    $vocab = $this->vocab($bundle, $map[$key]);
    if (!$vocab) {
      return new JsonResponse([]);
    }
    $q = trim((string) $request->query->get('q', ''));
    $q = mb_substr($q, 0, 80);
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('vid', $vocab);
    if ($q !== '') {
      $query->condition('name', $q, 'CONTAINS');
    }
    $ids = $query->sort('name')->range(0, 20)->execute();
    $names = [];
    foreach ($storage->loadMultiple($ids) as $term) {
      $label = trim((string) $term->label());
      if ($label !== '') {
        $names[] = $label;
      }
    }
    return new JsonResponse($names);
  }

  /**
   * Stores one already-compressed image as media, reusing an identical file.
   */
  public function upload(Request $request): JsonResponse {
    [, $media_type, $source] = $this->info();
    $uploaded = $request->files->get('file');
    if (!$uploaded || !$uploaded->isValid()) {
      return new JsonResponse(['error' => '没有收到有效图片。'], 400);
    }
    if ($uploaded->getSize() > 25 * 1024 * 1024) {
      return new JsonResponse(['error' => '文件过大'], 400);
    }
    $info = @getimagesize($uploaded->getPathname());
    $type = (int) ($info[2] ?? 0);
    $extension = match ($type) {
      IMAGETYPE_JPEG => 'jpg',
      IMAGETYPE_PNG => 'png',
      IMAGETYPE_GIF => 'gif',
      IMAGETYPE_WEBP => 'webp',
      default => (defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF) ? 'avif' : '',
    };
    if ($extension === '') {
      return new JsonResponse(['error' => '不是受支持的图片，文件没有公开。'], 400);
    }
    $this->store->ensureTables();
    $hash = hash_file('sha256', $uploaded->getPathname());
    $lock_name = 'xedc_quick:' . $hash;
    $lock = \Drupal::lock();
    if (!$lock->acquire($lock_name, 30)) {
      $lock->wait($lock_name, 5);
      $existing = $this->mediaForHash($hash);
      if ($existing) {
        return new JsonResponse(['mid' => (int) $existing, 'dup' => TRUE]);
      }
      if (!$lock->acquire($lock_name, 30)) {
        return new JsonResponse(['error' => '请稍后重试'], 429);
      }
    }
    $optimizedPath = NULL;
    try {
      $existing = $this->mediaForHash($hash);
      if ($existing) {
        return new JsonResponse(['mid' => (int) $existing, 'dup' => TRUE]);
      }
      $title = trim((string) $request->request->get('title', 'gallery'));
      $title = $title !== '' ? mb_substr($title, 0, 200) : 'gallery';
      $n = max(1, (int) $request->request->get('n', 1));
      $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', \Drupal::service('transliteration')->transliterate($title, 'en')), '-'));
      $slug = substr($slug !== '' ? $slug : 'gallery', 0, 60);
      $originalBytes = (int) $uploaded->getSize();
      $sourcePath = $uploaded->getRealPath();
      $optimizedMime = '';
      if (!\Drupal::hasService('xedc_core.image_optimizer') || !\Drupal::hasService('xedc_core.image_optimize_repository')) {
        return new JsonResponse(['error' => '图片优化服务不可用，文件没有公开'], 500);
      }
      $settings = \Drupal::service('xedc_core.image_optimize_repository')->settings();
      $decision = \Drupal::service('xedc_core.image_optimizer')->optimize($sourcePath, $settings);
      if ($decision->status === 'rejected') {
        return new JsonResponse(['error' => $decision->error !== '' ? $decision->error : '图片未通过优化'], 400);
      }
      if (!$decision->keptOriginal && is_file($decision->path)) {
        $sourcePath = $decision->path;
        $optimizedPath = $decision->path;
        $extension = $decision->extension !== '' ? $decision->extension : $extension;
        $optimizedMime = $decision->mime;
      }
      if (\Drupal::state()->get('xedc_quick_fail_public_copy') === TRUE) {
        throw new \RuntimeException('公开存储写入失败（测试模拟），文件没有公开。');
      }
      $directory = 'public://gallery/' . date('Y-m');
      $filesystem = \Drupal::service('file_system');
      $filesystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $destination = sprintf('%s/%s-%03d.%s', $directory, $slug, $n, $extension);
      $uri = $filesystem->copy($sourcePath, $destination, FileExists::Rename);
      $file = File::create([
        'uri' => $uri,
        'uid' => $this->currentUser()->id(),
        'status' => FileInterface::STATUS_PERMANENT,
        'filename' => basename($uri),
      ]);
      if ($optimizedMime !== '') {
        $file->setMimeType($optimizedMime);
      }
      $file->save();
      $media = Media::create([
        'bundle' => $media_type,
        'name' => $title . ' ' . $n,
        'uid' => $this->currentUser()->id(),
        'status' => 1,
        $source => [
          'target_id' => $file->id(),
          'alt' => \Drupal::service('xedc_core.alt')->format($title, $n, $n),
        ],
      ]);
      $media->save();
      $this->store->rememberUpload($hash, (int) $media->id(), $originalBytes);
      if (\Drupal::hasService('xedc_core.alt')) {
        \Drupal::service('xedc_core.alt')->remember((int) $media->id(), (string) $media->get($source)->alt, 'auto');
      }
      return new JsonResponse([
        'mid' => (int) $media->id(),
        'original_bytes' => $originalBytes,
        'optimized_bytes' => (int) $file->getSize(),
        'mime' => (string) $file->getMimeType(),
        'public' => str_starts_with((string) $file->getFileUri(), 'public://') && !str_contains((string) $file->getFileUri(), 'public/public/'),
      ]);
    }
    catch (\Throwable $e) {
      $this->getLogger('xedc_quick')->error('Gallery upload failed: @message', ['@message' => $e->getMessage()]);
      $safe = str_contains($e->getMessage(), '公开存储') ? $e->getMessage() : '上传失败';
      return new JsonResponse(['error' => $safe], 500);
    }
    finally {
      if (is_string($optimizedPath) && is_file($optimizedPath)) {
        $optimizedDir = dirname($optimizedPath);
        @unlink($optimizedPath);
        @rmdir($optimizedDir);
      }
      $lock->release($lock_name);
    }
  }

  /**
   * Media id already stored for this file hash.
   */
  protected function mediaForHash(string $hash): int {
    $id = $this->store->mediaIdForHash($hash);
    if ($id && Media::load($id)) {
      return $id;
    }
    return 0;
  }

  /**
   * Creates the photo node from uploaded media ids.
   */
  public function createNode(Request $request): JsonResponse {
    [$bundle, $media_type] = $this->info();
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $title = trim((string) ($data['title'] ?? ''));
    $title = mb_substr($title, 0, 255);
    $mids = array_values(array_unique(array_filter(array_map('intval', $data['mids'] ?? []))));
    if ($title === '' || !$mids) {
      return new JsonResponse(['error' => '缺少标题或图片'], 400);
    }
    $loaded = $this->entityTypeManager()->getStorage('media')->loadMultiple($mids);
    $ordered = [];
    foreach ($mids as $mid) {
      $media = $loaded[$mid] ?? NULL;
      if ($media instanceof MediaInterface && $media->bundle() === $media_type) {
        $ordered[] = (int) $media->id();
      }
    }
    if (!$ordered) {
      return new JsonResponse(['error' => '图片无效'], 400);
    }
    $cover = (int) ($data['cover'] ?? 0);
    if (!in_array($cover, $ordered, TRUE)) {
      $cover = $ordered[0];
    }
    $publish = !empty($data['publish']);
    try {
      $node = Node::create([
        'type' => $bundle,
        'title' => $title,
        'uid' => $this->currentUser()->id(),
        'status' => $publish ? 1 : 0,
        'field_images' => array_map(static fn(int $mid): array => ['target_id' => $mid], $ordered),
      ]);
      if (!$publish) {
        $node->xedc_keep_unpublished = TRUE;
      }
      if ($node->hasField('moderation_state')) {
        $node->set('moderation_state', $publish ? 'published' : 'draft');
      }
      if ($node->hasField('field_cover_image')) {
        $cover_media = $loaded[$cover] ?? NULL;
        if ($cover_media instanceof MediaInterface) {
          if ($node->getFieldDefinition('field_cover_image')->getType() === 'image') {
            $node->set('field_cover_image', [
              'target_id' => $cover_media->getSource()->getSourceFieldValue($cover_media),
              'alt' => $title,
            ]);
          }
          else {
            $node->set('field_cover_image', ['target_id' => $cover]);
          }
        }
      }
      $model = trim((string) ($data['model'] ?? ''));
      if ($node->hasField('field_model') && $model !== '') {
        $node->set('field_model', $this->terms($bundle, 'field_model', [$model]));
      }
      if ($node->hasField('field_tags')) {
        $node->set('field_tags', $this->terms($bundle, 'field_tags', $data['tags'] ?? []));
      }
      if ($node->hasField('field_categories')) {
        $node->set('field_categories', $this->terms($bundle, 'field_categories', $data['cats'] ?? []));
      }
      $node->save();
      $node = Node::load((int) $node->id()) ?: $node;
      $metrics = \Drupal::hasService('xedc_core.gallery_metrics') ? \Drupal::service('xedc_core.gallery_metrics')->forNode($node) : [];
      $url = $publish ? $node->toUrl()->toString() : $node->toUrl('edit-form')->toString();
      return new JsonResponse([
        'edit' => $url,
        'nid' => (int) $node->id(),
        'published' => $node->isPublished(),
        'moderation' => $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '',
        'original_bytes' => $metrics['original_bytes'] ?? NULL,
        'optimized_bytes' => $metrics['optimized_bytes'] ?? NULL,
        'original_label' => $metrics['original_label'] ?? '',
        'optimized_label' => $metrics['optimized_label'] ?? '',
        'count' => $metrics['count'] ?? count($ordered),
      ]);
    }
    catch (\Throwable $e) {
      $this->getLogger('xedc_quick')->error('Gallery create failed: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => '创建失败'], 500);
    }
  }

}
