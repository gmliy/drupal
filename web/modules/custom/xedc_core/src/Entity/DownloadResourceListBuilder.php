<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Admin list of download resources.
 */
final class DownloadResourceListBuilder extends EntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected RequestStack $requestStack,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $request = $this->requestStack->getCurrentRequest();
    $q = htmlspecialchars((string) ($request?->query->get('q') ?? ''), ENT_QUOTES, 'UTF-8');
    $bundle = (string) ($request?->query->get('bundle') ?? '');
    $source = (string) ($request?->query->get('source') ?? '');
    $access = (string) ($request?->query->get('access') ?? '');
    $status = (string) ($request?->query->get('status') ?? '');
    $option = static function (array $options, string $current): string {
      $html = '';
      foreach ($options as $value => $label) {
        $selected = (string) $value === $current ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
      }
      return $html;
    };
    $build['filters'] = [
      '#markup' => '<form method="get" class="xedc-download-filters" style="display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin:0 0 16px">'
        . '<label>搜索<br><input name="q" value="' . $q . '" size="24"></label>'
        . '<label>内容类型<br><select name="bundle">' . $option(['' => '全部', 'photo' => '图片', 'video' => '视频', 'article' => '文章'], $bundle) . '</select></label>'
        . '<label>来源<br><select name="source">' . $option(['' => '全部'] + DownloadResource::SOURCES, $source) . '</select></label>'
        . '<label>权限<br><select name="access">' . $option(['' => '全部'] + DownloadResource::LEVELS, $access) . '</select></label>'
        . '<label>状态<br><select name="status">' . $option(['' => '全部', '1' => '启用', '0' => '停用'], $status) . '</select></label>'
        . '<button type="submit">筛选</button></form>',
      '#allowed_tags' => ['form', 'label', 'br', 'input', 'select', 'option', 'button'],
    ];
    $build += parent::render();
    $build['table']['#empty'] = $this->t('还没有下载资源。');
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'title' => $this->t('资源名称'),
      'parent' => $this->t('所属内容'),
      'bundle' => $this->t('内容类型'),
      'source' => $this->t('来源'),
      'access' => $this->t('权限'),
      'size' => $this->t('大小'),
      'status' => $this->t('状态'),
      'created' => $this->t('创建时间'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\xedc_core\Entity\DownloadResource $entity */
    $node = $entity->get('parent')->entity;
    $bundle = $node ? $node->bundle() : '';
    $labels = ['photo' => '图片', 'video' => '视频', 'article' => '文章'];
    $source = (string) $entity->get('source_type')->value;
    $access = (string) $entity->get('access_level')->value;
    $size = (int) $entity->get('file_size')->value;
    return [
      'title' => $entity->label(),
      'parent' => $node ? $node->label() : $this->t('内容已删除'),
      'bundle' => $labels[$bundle] ?? $bundle,
      'source' => DownloadResource::SOURCES[$source] ?? $source,
      'access' => DownloadResource::LEVELS[$access] ?? $access,
      'size' => $size > 0 ? \Drupal\xedc_core\Ops\OpsFormat::bytes($size) : '—',
      'status' => $entity->isPublished() ? $this->t('启用') : $this->t('停用'),
      'created' => date('Y-m-d H:i', (int) $entity->get('created')->value),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds() {
    $query = $this->getStorage()->getQuery()->accessCheck(TRUE);
    $request = $this->requestStack->getCurrentRequest();
    $q = trim((string) ($request?->query->get('q') ?? ''));
    if ($q !== '') {
      $query->condition('title', $q, 'CONTAINS');
    }
    $bundle = (string) ($request?->query->get('bundle') ?? '');
    if (in_array($bundle, ['photo', 'video', 'article'], TRUE)) {
      $query->condition('parent.entity:node.type', $bundle);
    }
    $source = (string) ($request?->query->get('source') ?? '');
    if (isset(DownloadResource::SOURCES[$source])) {
      $query->condition('source_type', $source);
    }
    $access = (string) ($request?->query->get('access') ?? '');
    if (isset(DownloadResource::LEVELS[$access])) {
      $query->condition('access_level', $access);
    }
    $status = (string) ($request?->query->get('status') ?? '');
    if ($status === '0' || $status === '1') {
      $query->condition('status', (int) $status);
    }
    $query->sort('created', 'DESC');
    $query->pager(50);
    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    $token = \Drupal::csrfToken()->get('xedc-download-toggle:' . $entity->id());
    $operations['toggle'] = [
      'title' => $entity->isPublished() ? $this->t('禁用') : $this->t('启用'),
      'weight' => 20,
      'url' => Url::fromRoute('xedc_core.download_toggle', ['xedc_download' => $entity->id()], [
        'query' => ['token' => $token],
      ]),
    ];
    return $operations;
  }

}
