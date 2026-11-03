<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Video;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ViewExecutable;

/**
 * Sort and search for the existing video views, without exporting configuration.
 */
final class VideoListing {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly EntityRepositoryInterface $repository,
    private readonly Connection $database,
  ) {}

  public function alter(ViewExecutable $view, QueryPluginBase $query): void {
    if (!$query instanceof Sql || !in_array($view->id(), ['video_list', 'taxonomy_term'], TRUE)) {
      return;
    }
    if ($view->id() === 'taxonomy_term' && !$this->isVideoCategoryView($view)) {
      return;
    }
    $request = \Drupal::request();
    $keys = mb_substr(trim((string) $request->query->get('keys')), 0, 64);
    if ($keys !== '') {
      $like = '%' . $this->database->escapeLike($keys) . '%';
      $query->addWhereExpression(0, 'node_field_data.title LIKE :xedc_video_keys', [
        ':xedc_video_keys' => $like,
      ]);
    }
    if ($request->query->get('sort') !== 'hot' || !$this->database->schema()->tableExists('node_counter')) {
      return;
    }
    if (!isset($query->relationships['xedc_video_counter'])) {
      $join = \Drupal::service('plugin.manager.views.join')->createInstance('standard', [
        'type' => 'LEFT',
        'table' => 'node_counter',
        'field' => 'nid',
        'left_table' => 'node_field_data',
        'left_field' => 'nid',
        'operator' => '=',
      ]);
      $query->addRelationship('xedc_video_counter', $join, 'node_field_data');
    }
    $query->orderby = [];
    $query->addOrderBy('xedc_video_counter', 'totalcount', 'DESC');
    $query->addOrderBy('node_field_data', 'created', 'DESC');
  }

  /**
   * @return array<int, array{name: string, url: string, count: int}>
   */
  public function categories(): array {
    $terms = $this->entities->getStorage('taxonomy_term')->loadTree('video_category', 0, 1, TRUE);
    $counts = [];
    if ($this->database->schema()->tableExists('node__field_categories')) {
      $query = $this->database->select('node__field_categories', 'c');
      $query->join('node_field_data', 'n', 'n.nid = c.entity_id AND n.default_langcode = 1 AND n.status = 1 AND n.type = :vtype', [':vtype' => 'video']);
      $query->addField('c', 'field_categories_target_id', 'tid');
      $query->addExpression('COUNT(DISTINCT n.nid)', 'cnt');
      $query->condition('c.deleted', 0);
      $query->groupBy('c.field_categories_target_id');
      foreach ($query->execute() as $row) {
        $counts[(int) $row->tid] = (int) $row->cnt;
      }
    }
    $out = [];
    foreach ($terms as $term) {
      $term = $this->repository->getTranslationFromContext($term);
      $out[] = [
        'name' => $term->label(),
        'url' => $term->toUrl()->toString(),
        'count' => $counts[(int) $term->id()] ?? 0,
      ];
    }
    return $out;
  }

  private function isVideoCategoryView(ViewExecutable $view): bool {
    $filters = $view->filter ?? [];
    foreach ($filters as $filter) {
      if (($filter->table ?? '') === 'node_field_data' && ($filter->field ?? '') === 'type') {
        $value = $filter->value ?? [];
        return is_array($value) && isset($value['video']) && count($value) === 1;
      }
    }
    return FALSE;
  }

}
