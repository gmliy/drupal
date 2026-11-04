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
use Drupal\xedc_quick\QuickIntake;
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
    protected QuickIntake $intake,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('xedc_quick.store'),
      $container->get('xedc_quick.intake'),
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
    $maxImage = (int) (\Drupal::config('xedc_core.settings')->get('member_max_image_bytes') ?: 26214400);
    if ($maxImage < 1) {
      $maxImage = 26214400;
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['id' => 'xq-app'],
      '#attached' => [
        'library' => ['xedc_quick/app', 'xedc_core/upload_manager', 'xedc_core/upload_panel'],
        'drupalSettings' => [
          'xedcUpload' => [
            'sessionsUrl' => '/admin/quick-gallery/upload/sessions',
            'sessionUrl' => '/admin/quick-gallery/upload/session',
            'sessionBase' => '/admin/quick-gallery/upload/session/',
            'probeUrl' => '',
            'reuseUrl' => '/admin/quick-gallery/reuse',
            'draftUrl' => '',
            'closeUrl' => '',
            'progressBase' => '/admin/content/image-imports/',
            'syncUrl' => '/admin/content/image-imports/sync',
            'centerUrl' => '/admin/quick-gallery',
            'maxArchive' => 0,
            'maxImage' => $maxImage,
            'chunkBytes' => (int) (\Drupal::config('xedc_core.settings')->get('upload_chunk_bytes') ?: 5242880),
            'headBytes' => 65536,
            'concurrency' => 2,
            'entry' => 'quick',
          ],
        ],
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
    $uploaded = $request->files->get('file');
    if (!$uploaded || !$uploaded->isValid()) {
      return new JsonResponse(['error' => '没有收到有效图片。'], 400);
    }
    $title = trim((string) $request->request->get('title', 'gallery'));
    $index = max(1, (int) $request->request->get('n', 1));
    $path = $uploaded->getRealPath();
    if (!is_string($path) || $path === '') {
      return new JsonResponse(['error' => '没有收到有效图片。'], 400);
    }
    $saved = $this->intake->storePath($path, $uploaded->getClientOriginalName(), $title, $index, (int) $uploaded->getSize());
    $http = (int) ($saved['http'] ?? 500);
    unset($saved['http'], $saved['ok']);
    return new JsonResponse($saved, $http);
  }

  /**
   * Reuses a healthy file when the browser already knows its SHA-256.
   */
  public function reuse(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['error' => '请求不是 JSON。'], 400);
    }
    $saved = $this->intake->match(
      (string) ($payload['sha256'] ?? ''),
      (int) ($payload['size'] ?? 0),
      (string) ($payload['title'] ?? 'gallery'),
      (int) ($payload['n'] ?? 1),
    );
    $http = (int) ($saved['http'] ?? 200);
    unset($saved['http']);
    return new JsonResponse($saved, $http);
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
      $model = trim((string) ($data['model'] ?? ''));
      if ($node->hasField('field_model') && $model !== '') {
        $node->set('field_model', $this->terms($bundle, 'field_model', [$model]));
      }
      if ($node->hasField('field_cover_image')) {
        $cover_media = $loaded[$cover] ?? NULL;
        if ($cover_media instanceof MediaInterface) {
          if ($node->getFieldDefinition('field_cover_image')->getType() === 'image') {
            $coverAlt = \Drupal::hasService('xedc_core.alt')
              ? \Drupal::service('xedc_core.alt')->compose($model, $title)
              : $title;
            $node->set('field_cover_image', [
              'target_id' => $cover_media->getSource()->getSourceFieldValue($cover_media),
              'alt' => $coverAlt !== '' ? $coverAlt : $title,
            ]);
          }
          else {
            $node->set('field_cover_image', ['target_id' => $cover]);
          }
        }
      }
      if ($node->hasField('field_tags')) {
        $node->set('field_tags', $this->terms($bundle, 'field_tags', $data['tags'] ?? []));
      }
      if ($node->hasField('field_categories')) {
        $node->set('field_categories', $this->terms($bundle, 'field_categories', $data['cats'] ?? []));
      }
      $node->save();
      $node = Node::load((int) $node->id()) ?: $node;
      if (\Drupal::hasService('xedc_core.alt')) {
        \Drupal::service('xedc_core.alt')->refresh($node);
      }
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
