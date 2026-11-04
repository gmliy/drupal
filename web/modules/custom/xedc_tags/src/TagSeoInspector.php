<?php

namespace Drupal\xedc_tags;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Read-only SEO checks. Nothing here changes Metatag, aliases or sitemap.
 */
final class TagSeoInspector {

  public function __construct(
    private TagCatalog $catalog,
    private TagDuplicateDetector $duplicates,
    private TagAudit $audit,
    private ConfigFactoryInterface $configFactory,
    private Connection $database,
  ) {}

  /**
   * Term IDs that still need a person to look at them.
   *
   * @return int[]
   */
  public function openTids(): array {
    $ignored = [];
    foreach (['seo', 'unused'] as $kind) {
      foreach ($this->audit->decisions($kind) as $decision) {
        $ignored[(int) $decision->subject] = TRUE;
      }
    }
    $ids = [];
    foreach ($this->all() as $tid => $row) {
      if ($row['status'] !== 'ok' && !isset($ignored[(int) $tid])) {
        $ids[] = (int) $tid;
      }
    }
    foreach ($this->catalog->terms() as $tid => $term) {
      if ($this->anomaly($term) && !isset($ignored[(int) $tid]) && !in_array((int) $tid, $ids, TRUE)) {
        $ids[] = (int) $tid;
      }
    }
    return $ids;
  }

  /**
   * @param array<string, mixed> $term
   */
  public function anomaly(array $term): bool {
    $name = (string) $term['default_name'];
    if (trim($name) === '' || $name !== trim($name) || preg_match('/\s{2,}/u', $name)) {
      return TRUE;
    }
    if (!(int) $term['status']) {
      return TRUE;
    }
    return str_starts_with((string) $term['alias'], '/taxonomy/term/');
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function all(): array {
    $terms = $this->catalog->terms();
    $mergeIds = [];
    $similarIds = [];
    foreach ($this->duplicates->groups() as $group) {
      foreach ($group['terms'] as $term) {
        if ($group['recommend_merge']) {
          $mergeIds[(int) $term['tid']] = TRUE;
        }
        else {
          $similarIds[(int) $term['tid']] = TRUE;
        }
      }
    }
    $aliasCounts = $this->aliasCounts();
    $collisions = $this->aliasCollisions();
    $indexed = (bool) $this->configFactory->get('simple_sitemap.bundle_settings.default.taxonomy_term.tags')->get('index');
    $rows = [];
    foreach ($terms as $tid => $term) {
      $rows[$tid] = $this->one($term, isset($mergeIds[$tid]), isset($similarIds[$tid]), $aliasCounts[$tid] ?? [], $collisions, $indexed);
    }
    return $rows;
  }

  /**
   * @param array<string, mixed> $term
   * @param array<string, int> $aliasCounts
   * @param array<string, int> $collisions
   * @return array<string, mixed>
   */
  private function one(array $term, bool $merge, bool $similar, array $aliasCounts, array $collisions, bool $indexed): array {
    $reasons = [];
    $status = 'ok';
    $name = trim((string) $term['default_name']);
    if ($name === '') {
      $this->raise($status, 'manual');
      $reasons[] = '没有标签名称';
    }
    elseif ($name !== trim((string) $term['default_name']) || preg_match('/\s{2,}/u', (string) $term['default_name'])) {
      $this->raise($status, 'manual');
      $reasons[] = '名称含有首尾空格或连续空格';
    }
    if (!(int) $term['status']) {
      $this->raise($status, 'manual');
      $reasons[] = '标签本身未发布';
    }
    if (empty($term['description'])) {
      $this->raise($status, 'improve');
      $reasons[] = '没有标签描述，Meta Description 也没有可引用的正文';
    }
    if ((int) $term['published_any'] === 0) {
      $this->raise($status, 'noindex');
      $reasons[] = '没有已发布内容，前台会是空标签页。这只是建议，不会自动 noindex。';
      if ($indexed) {
        $reasons[] = '标签词汇目前会进入 sitemap，空页面可能被收录。';
      }
    }
    if ($merge) {
      $this->raise($status, 'merge');
      $reasons[] = '和另一个标签规范化后同名，建议人工确认后再合并。';
    }
    if ($similar) {
      $this->raise($status, 'manual');
      $reasons[] = '名称相似，但是系统不能判断它是否重复。';
    }
    $alias = (string) $term['alias'];
    if ($alias === '' || str_starts_with($alias, '/taxonomy/term/')) {
      $this->raise($status, 'manual');
      $reasons[] = '没有友好 URL，当前仍是系统路径。';
    }
    foreach ($aliasCounts as $langcode => $count) {
      if ($count > 1) {
        $this->raise($status, 'manual');
        $reasons[] = $langcode . ' 有 ' . $count . ' 个别名，可能形成重复内容页。';
      }
    }
    if ($alias !== '' && isset($collisions[$alias]) && $collisions[$alias] > 1) {
      $this->raise($status, 'manual');
      $reasons[] = '别名 ' . $alias . ' 还指向其他路径。';
    }
    if (!$indexed && (int) $term['published_any'] > 0) {
      $reasons[] = '标签词汇当前未加入 sitemap。';
    }
    if (!$reasons) {
      $reasons[] = '名称、公开内容和别名没有发现需处理的问题。';
    }
    return [
      'tid' => (int) $term['tid'],
      'name' => $term['name'],
      'status' => $status,
      'label' => $this->label($status),
      'reasons' => $reasons,
      'published' => (int) $term['published_any'],
      'alias' => $alias,
      'indexed' => $indexed,
    ];
  }

  private function raise(string &$status, string $next): void {
    $rank = ['ok' => 0, 'improve' => 1, 'noindex' => 2, 'merge' => 3, 'manual' => 4];
    if (($rank[$next] ?? 0) > ($rank[$status] ?? 0)) {
      $status = $next;
    }
  }

  private function label(string $status): string {
    return [
      'ok' => '正常',
      'improve' => '建议完善',
      'noindex' => '建议 noindex',
      'merge' => '建议合并',
      'manual' => '需要人工检查',
    ][$status] ?? $status;
  }

  /**
   * @return array<int, array<string, int>>
   */
  private function aliasCounts(): array {
    if (!$this->database->schema()->tableExists('path_alias')) {
      return [];
    }
    $query = $this->database->select('path_alias', 'p');
    $query->fields('p', ['path', 'langcode']);
    $query->condition('path', '/taxonomy/term/%', 'LIKE');
    $out = [];
    foreach ($query->execute() as $row) {
      if (!preg_match('#^/taxonomy/term/(\d+)$#', (string) $row->path, $matches)) {
        continue;
      }
      $tid = (int) $matches[1];
      $out[$tid][$row->langcode] = ($out[$tid][$row->langcode] ?? 0) + 1;
    }
    return $out;
  }

  /**
   * @return array<string, int>
   */
  private function aliasCollisions(): array {
    if (!$this->database->schema()->tableExists('path_alias')) {
      return [];
    }
    $query = $this->database->select('path_alias', 'p');
    $query->addField('p', 'alias', 'alias');
    $query->addExpression('COUNT(DISTINCT path)', 'cnt');
    $query->groupBy('alias');
    $out = [];
    foreach ($query->execute() as $row) {
      if ((int) $row->cnt > 1) {
        $out[(string) $row->alias] = (int) $row->cnt;
      }
    }
    return $out;
  }

}
