<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Read models for the engagement admin pages. Writes stay in the managers.
 */
final class EngagementReport {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * @return array<string, int>
   *   Active-like totals.
   */
  public function likeSummary(): array {
    $now = $this->time->getRequestTime();
    return [
      'total' => $this->countSince('xedc_like', 'changed', 0, 'l.status = 1'),
      'today' => $this->countSince('xedc_like', 'changed', $now - ($now % 86400), 'l.status = 1'),
      'week' => $this->countSince('xedc_like', 'changed', $now - 7 * 86400, 'l.status = 1'),
      'month' => $this->countSince('xedc_like', 'changed', $now - 30 * 86400, 'l.status = 1'),
    ];
  }

  /**
   * @param array<string, string> $filters
   *   bundle, q, user, from, to, state, sort.
   *
   * @return list<array<int, string>>
   *   Table rows.
   */
  public function likeRows(array $filters): array {
    $query = $this->database->select('xedc_like', 'l');
    $query->leftJoin('node_field_data', 'n', 'n.nid = l.entity_id AND n.default_langcode = 1');
    $query->leftJoin('users_field_data', 'u', 'u.uid = l.uid');
    $query->fields('l', ['entity_id', 'bundle', 'uid', 'status', 'changed']);
    $query->addField('n', 'title');
    $query->addField('u', 'name');
    $query->condition('l.entity_type', 'node');
    $this->applyContentFilters($query, $filters, 'l.changed', 'l.bundle');
    if (($filters['state'] ?? '') === '1' || ($filters['state'] ?? '') === '0') {
      $query->condition('l.status', (int) $filters['state']);
    }
    if (($filters['user'] ?? '') !== '') {
      $user = (string) $filters['user'];
      if (ctype_digit($user)) {
        $query->condition('l.uid', (int) $user);
      }
      else {
        $query->condition('u.name', '%' . $this->database->escapeLike($user) . '%', 'LIKE');
      }
    }
    if (($filters['sort'] ?? '') === 'count') {
      $query->addExpression('(SELECT COUNT(*) FROM {xedc_like} lc WHERE lc.entity_type = l.entity_type AND lc.entity_id = l.entity_id AND lc.status = 1)', 'like_total');
      $query->orderBy('like_total', 'DESC');
    }
    $query->orderBy('l.changed', 'DESC');
    $query = $query->extend(PagerSelectExtender::class)->limit(40);
    $labels = ['photo' => '图片', 'video' => '视频', 'article' => '文章'];
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        $this->contentLink((int) $row->entity_id, (string) ($row->title ?? '')),
        $labels[(string) $row->bundle] ?? (string) $row->bundle,
        (string) $row->entity_id,
        (string) ($row->name ?? '用户已删除'),
        (string) $row->uid,
        date('Y-m-d H:i', (int) $row->changed),
        ((int) $row->status === 1) ? '有效' : '已取消',
      ];
    }
    return $rows;
  }

  /**
   * @return list<array<int, string>>
   *   Ranked content.
   */
  public function likeRank(string $bundle = ''): array {
    $query = $this->database->select('xedc_like', 'l');
    $query->leftJoin('node_field_data', 'n', 'n.nid = l.entity_id AND n.default_langcode = 1');
    $query->addField('l', 'entity_id');
    $query->addField('l', 'bundle');
    $query->addField('n', 'title');
    $query->addExpression('COUNT(*)', 'total');
    $query->condition('l.status', 1);
    $query->condition('l.entity_type', 'node');
    if (in_array($bundle, LikeManager::ALLOWED_BUNDLES, TRUE)) {
      $query->condition('l.bundle', $bundle);
    }
    $query->groupBy('l.entity_id');
    $query->groupBy('l.bundle');
    $query->groupBy('n.title');
    $query->orderBy('total', 'DESC');
    $query->range(0, 20);
    $labels = ['photo' => '图片', 'video' => '视频', 'article' => '文章'];
    $fetched = $query->execute()->fetchAll();
    $covers = $this->coverMarkup(array_map(static fn(object $row): int => (int) $row->entity_id, $fetched));
    $rows = [];
    $rank = 1;
    foreach ($fetched as $row) {
      $nid = (int) $row->entity_id;
      $rows[] = [
        (string) $rank,
        $this->contentLink($nid, (string) ($row->title ?? '')),
        $covers[$nid] ?? '—',
        $labels[(string) $row->bundle] ?? (string) $row->bundle,
        (string) $row->total,
      ];
      $rank++;
    }
    return $rows;
  }

  /**
   * @return array<string, int>
   *   Authorized download totals. Failures are counted separately.
   */
  public function downloadSummary(): array {
    $now = $this->time->getRequestTime();
    $base = "d.status = 'authorized'";
    return [
      'total' => $this->countSince('xedc_download_log', 'created', 0, $base),
      'today' => $this->countSince('xedc_download_log', 'created', $now - ($now % 86400), $base),
      'week' => $this->countSince('xedc_download_log', 'created', $now - 7 * 86400, $base),
      'month' => $this->countSince('xedc_download_log', 'created', $now - 30 * 86400, $base),
      'anonymous' => $this->countSince('xedc_download_log', 'created', 0, $base . " AND d.tier = 'anonymous'"),
      'member' => $this->countSince('xedc_download_log', 'created', 0, $base . " AND d.tier = 'member'"),
      'vip' => $this->countSince('xedc_download_log', 'created', 0, $base . " AND d.tier = 'vip'"),
    ];
  }

  /**
   * @return array<string, int>
   *   Authorized counts by source.
   */
  public function downloadSources(): array {
    if (!$this->database->schema()->fieldExists('xedc_download_log', 'source')) {
      return [];
    }
    $query = $this->database->select('xedc_download_log', 'd');
    $query->addField('d', 'source');
    $query->addExpression('COUNT(*)', 'total');
    $query->condition('status', 'authorized');
    $query->groupBy('source');
    $counts = [];
    foreach ($query->execute() as $row) {
      $counts[(string) $row->source] = (int) $row->total;
    }
    return $counts;
  }

  /**
   * @param array<string, string> $filters
   *   bundle, q, user, from, to, source.
   *
   * @return list<array<int, string>>
   *   Detail rows.
   */
  public function downloadRows(array $filters): array {
    $query = $this->database->select('xedc_download_log', 'd');
    $query->leftJoin('node_field_data', 'n', 'n.nid = d.content_id AND n.default_langcode = 1');
    $query->leftJoin('users_field_data', 'u', 'u.uid = d.uid');
    $query->fields('d', ['uid', 'content_id', 'resource_id', 'bundle', 'title', 'extension', 'bytes', 'source', 'tier', 'status', 'created']);
    $query->addField('n', 'title', 'content_title');
    $query->addField('u', 'name');
    $this->applyContentFilters($query, $filters, 'd.created', 'd.bundle', 'n.title', 'content_title');
    if (($filters['source'] ?? '') !== '') {
      $query->condition('d.source', (string) $filters['source']);
    }
    if (($filters['user'] ?? '') !== '') {
      $user = (string) $filters['user'];
      if (ctype_digit($user)) {
        $query->condition('d.uid', (int) $user);
      }
      else {
        $query->condition('u.name', '%' . $this->database->escapeLike($user) . '%', 'LIKE');
      }
    }
    $query->orderBy('d.created', 'DESC');
    $query = $query->extend(PagerSelectExtender::class)->limit(40);
    $labels = ['photo' => '图片', 'video' => '视频', 'article' => '文章'];
    $sources = ['upload' => '上传文件', 'external' => '外部链接', 'originals' => '原图', 'zip' => '整组 ZIP'];
    $tiers = ['anonymous' => '游客', 'member' => '普通会员', 'vip' => 'VIP'];
    $states = [
      'authorized' => '已授权',
      'zip_pending' => 'ZIP 生成中',
      'zip_failed' => 'ZIP 生成失败',
      'failed' => '交付失败',
    ];
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        $this->contentLink((int) $row->content_id, (string) ($row->content_title ?? '')),
        $labels[(string) $row->bundle] ?? (string) $row->bundle,
        (string) $row->title,
        $sources[(string) $row->source] ?? ((string) $row->source !== '' ? (string) $row->source : '—'),
        strtoupper((string) $row->extension),
        $row->uid ? ((string) ($row->name ?? '用户已删除')) : '游客',
        $tiers[(string) $row->tier] ?? ((string) $row->tier !== '' ? (string) $row->tier : '—'),
        date('Y-m-d H:i', (int) $row->created),
        $states[(string) $row->status] ?? (string) $row->status,
      ];
    }
    return $rows;
  }

  /**
   * @return list<array<int, string>>
   *   Content download ranking.
   */
  public function downloadRank(): array {
    $query = $this->database->select('xedc_download_log', 'd');
    $query->leftJoin('node_field_data', 'n', 'n.nid = d.content_id AND n.default_langcode = 1');
    $query->addField('d', 'content_id');
    $query->addField('d', 'bundle');
    $query->addField('n', 'title');
    $query->addExpression('COUNT(*)', 'total');
    $query->condition('d.status', 'authorized');
    $query->groupBy('d.content_id');
    $query->groupBy('d.bundle');
    $query->groupBy('n.title');
    $query->orderBy('total', 'DESC');
    $query->range(0, 20);
    $labels = ['photo' => '图片', 'video' => '视频', 'article' => '文章'];
    $rows = [];
    $rank = 1;
    foreach ($query->execute() as $row) {
      $rows[] = [
        (string) $rank,
        $this->contentLink((int) $row->content_id, (string) ($row->title ?? '')),
        $labels[(string) $row->bundle] ?? (string) $row->bundle,
        (string) $row->total,
      ];
      $rank++;
    }
    return $rows;
  }

  /**
   * @return list<array<int, string>>
   *   Resource download ranking. Counts authorized handoffs only.
   */
  public function resourceRank(): array {
    $query = $this->database->select('xedc_download_log', 'd');
    $query->leftJoin('node_field_data', 'n', 'n.nid = d.content_id AND n.default_langcode = 1');
    $query->addField('d', 'resource_id');
    $query->addField('d', 'title');
    $query->addField('d', 'source');
    $query->addField('d', 'content_id');
    $query->addField('n', 'title', 'content_title');
    $query->addExpression('COUNT(*)', 'total');
    $query->condition('d.status', 'authorized');
    $query->groupBy('d.resource_id');
    $query->groupBy('d.title');
    $query->groupBy('d.source');
    $query->groupBy('d.content_id');
    $query->groupBy('n.title');
    $query->orderBy('total', 'DESC');
    $query->range(0, 20);
    $sources = ['upload' => '上传文件', 'external' => '外部链接', 'originals' => '原图', 'zip' => '整组 ZIP'];
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        (string) ($row->title !== '' ? $row->title : '资源已删除'),
        $this->contentLink((int) $row->content_id, (string) ($row->content_title ?? '')),
        $sources[(string) $row->source] ?? ((string) $row->source !== '' ? (string) $row->source : '—'),
        (string) $row->total,
      ];
    }
    return $rows;
  }

  /**
   * @return list<array<int, string>>
   *   Recent share events. Panel opens stay marked as intent.
   */
  public function shareRows(): array {
    $query = $this->database->select('xedc_share', 's');
    $query->leftJoin('node_field_data', 'n', 'n.nid = s.entity_id AND n.default_langcode = 1');
    $query->leftJoin('users_field_data', 'u', 'u.uid = s.uid');
    $query->fields('s', ['entity_id', 'bundle', 'uid', 'method', 'outcome', 'created']);
    $query->addField('n', 'title');
    $query->addField('u', 'name');
    $query->orderBy('s.created', 'DESC');
    $query = $query->extend(PagerSelectExtender::class)->limit(40);
    $labels = ['photo' => '图片', 'video' => '视频', 'article' => '文章'];
    $methods = ['panel' => '打开面板', 'copy' => '复制链接', 'native' => '系统分享'];
    $outcomes = ['intent' => '分享意向', 'success' => '成功'];
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        $this->contentLink((int) $row->entity_id, (string) ($row->title ?? '')),
        $labels[(string) $row->bundle] ?? (string) $row->bundle,
        $methods[(string) $row->method] ?? (string) $row->method,
        $outcomes[(string) $row->outcome] ?? (string) $row->outcome,
        $row->uid ? (string) ($row->name ?? '用户已删除') : '游客',
        date('Y-m-d H:i', (int) $row->created),
      ];
    }
    return $rows;
  }

  /**
   * @return list<array<int, string>>
   *   ZIP jobs.
   */
  public function zipJobs(): array {
    if (!$this->database->schema()->tableExists('xedc_zip_job')) {
      return [];
    }
    $query = $this->database->select('xedc_zip_job', 'z');
    $query->leftJoin('node_field_data', 'n', 'n.nid = z.content_id AND n.default_langcode = 1');
    $query->fields('z');
    $query->addField('n', 'title');
    $query->orderBy('z.changed', 'DESC');
    $query->range(0, 50);
    $states = ['queued' => '等待处理', 'running' => '正在生成', 'ready' => '已完成', 'failed' => '生成失败'];
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        $this->contentLink((int) $row->content_id, (string) ($row->title ?? '')),
        (string) $row->resource_id,
        $states[(string) $row->status] ?? (string) $row->status,
        (string) $row->message,
        date('Y-m-d H:i', (int) $row->created),
        date('Y-m-d H:i', (int) $row->changed),
      ];
    }
    return $rows;
  }

  public function activeVipCount(): int {
    $now = $this->time->getRequestTime();
    return (int) $this->database->select('xedc_vip', 'v')
      ->condition('status', 'active')
      ->condition('expires', $now, '>')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  protected function countSince(string $table, string $column, int $since, string $extra): int {
    $alias = $table === 'xedc_like' ? 'l' : 'd';
    $query = $this->database->select($table, $alias);
    if ($since > 0) {
      $query->condition($alias . '.' . $column, $since, '>=');
    }
    if ($extra !== '') {
      $query->where($extra);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * @param array<string, string> $filters
   *   Shared content filters.
   */
  protected function applyContentFilters($query, array $filters, string $timeField, string $bundleField, string $titleField = 'n.title', string $titleAlias = 'title'): void {
    if (in_array($filters['bundle'] ?? '', LikeManager::ALLOWED_BUNDLES, TRUE)) {
      $query->condition($bundleField, $filters['bundle']);
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
      $query->condition($titleAlias === 'title' ? 'n.title' : $titleField, '%' . $this->database->escapeLike($q) . '%', 'LIKE');
    }
    $from = strtotime((string) ($filters['from'] ?? ''));
    if ($from) {
      $query->condition($timeField, $from, '>=');
    }
    $to = strtotime((string) ($filters['to'] ?? ''));
    if ($to) {
      $query->condition($timeField, $to + 86399, '<=');
    }
  }

  /**
   * @param list<int> $nids
   *   Node ids from one ranking query.
   *
   * @return array<int, string|MarkupInterface>
   *   A small cover, or a dash when the content has none.
   */
  protected function coverMarkup(array $nids): array {
    $nids = array_values(array_unique(array_filter(array_map('intval', $nids))));
    $markup = array_fill_keys($nids, '—');
    if ($nids === []) {
      return $markup;
    }
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
    $style = ImageStyle::load('thumbnail') ?: ImageStyle::load('medium');
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $field = match ($node->bundle()) {
        'photo' => 'field_cover_image',
        'video' => 'field_video_cover',
        'article' => 'field_cover',
        default => NULL,
      };
      if ($field === NULL || !$node->hasField($field) || $node->get($field)->isEmpty()) {
        continue;
      }
      $media = $node->get($field)->entity;
      if (!$media instanceof MediaInterface) {
        continue;
      }
      $source = $media->getSource()->getConfiguration()['source_field'] ?? NULL;
      if (!is_string($source) || !$media->hasField($source) || $media->get($source)->isEmpty()) {
        continue;
      }
      $file = $media->get($source)->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }
      try {
        $url = $style ? $style->buildUrl($file->getFileUri()) : \Drupal::service('file_url_generator')->generateString($file->getFileUri());
      }
      catch (\Throwable) {
        continue;
      }
      $markup[(int) $node->id()] = Markup::create('<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="" width="40" height="40" loading="lazy" style="width:40px;height:40px;object-fit:cover;border-radius:6px">');
    }
    return $markup;
  }

  protected function contentLink(int $nid, string $title): string|MarkupInterface {
    if ($nid < 1 || $title === '') {
      return '内容已删除';
    }
    $label = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $view = Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
    $html = '<a href="' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
    if (\Drupal::currentUser()->hasPermission('administer nodes')) {
      $edit = Url::fromRoute('entity.node.edit_form', ['node' => $nid])->toString();
      $html .= ' <a href="' . htmlspecialchars($edit, ENT_QUOTES, 'UTF-8') . '">编辑</a>';
    }
    return Markup::create($html);
  }

}
