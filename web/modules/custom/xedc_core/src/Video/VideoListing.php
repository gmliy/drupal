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
    // Keep recommendation regions and the ordinary list from repeating the
    // same card. Exclusion is done in SQL so pager page size stays stable.
    if ($keys === '' && $request->query->get('sort') !== 'hot' && $this->isFirstPage($view)) {
      if ($view->id() === 'video_list') {
        $this->excludeFlagged($query, 'node__field_slideshow', 'field_slideshow_value');
      }
      elseif ($view->id() === 'taxonomy_term' && $this->isVideoCategoryView($view)) {
        $term = \Drupal::routeMatch()->getParameter('taxonomy_term');
        if ($term instanceof \Drupal\taxonomy\TermInterface && $term->bundle() === 'video_category' && \Drupal::hasService('xedc_core.video_recommend')) {
          $nids = \Drupal::service('xedc_core.video_recommend')->categoryHotNids((int) $term->id(), 12);
          if ($nids) {
            $query->addWhere(0, 'node_field_data.nid', $nids, 'NOT IN');
          }
        }
      }
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
    $query->addOrderBy(NULL, 'COALESCE(xedc_video_counter.totalcount, 0)', 'DESC', 'xedc_video_hot');
    $query->addOrderBy('node_field_data', 'created', 'DESC');
  }

  /**
   * Drops rows whose recommendation flag is enabled (value = 1).
   */
  private function excludeFlagged(Sql $query, string $table, string $column): void {
    if (!$this->database->schema()->tableExists($table)) {
      return;
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
      return;
    }
    $alias = 'xedc_excl_' . substr(hash('sha256', $table), 0, 8);
    if (!isset($query->relationships[$alias])) {
      $join = \Drupal::service('plugin.manager.views.join')->createInstance('standard', [
        'type' => 'LEFT',
        'table' => $table,
        'field' => 'entity_id',
        'left_table' => 'node_field_data',
        'left_field' => 'nid',
        'operator' => '=',
        'extra' => [
          [
            'field' => 'deleted',
            'value' => 0,
            'numeric' => TRUE,
          ],
          [
            'field' => 'langcode',
            'left_field' => 'langcode',
          ],
        ],
      ]);
      $query->addRelationship($alias, $join, 'node_field_data');
    }
    $query->addWhereExpression(0, 'COALESCE(' . $alias . '.' . $column . ', 0) = 0');
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

  private function isFirstPage(ViewExecutable $view): bool {
    if (!isset($view->pager) || !method_exists($view->pager, 'getCurrentPage')) {
      return TRUE;
    }
    return (int) $view->pager->getCurrentPage() === 0;
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
