<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\CacheBackendInterface;
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
    protected CacheBackendInterface $cache,
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
    $query->fields('l', ['id', 'entity_id', 'bundle', 'uid', 'actor_hash', 'status', 'changed']);
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
    if (($filters['who'] ?? '') === 'guest') {
      $query->condition('l.uid', 0);
    }
    elseif (($filters['who'] ?? '') === 'member') {
      $query->condition('l.uid', 0, '>');
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
      $guest = (int) $row->uid < 1;
      $rows[] = [
        (string) $row->id,
        $this->contentLink((int) $row->entity_id, (string) ($row->title ?? '')),
        $labels[(string) $row->bundle] ?? (string) $row->bundle,
        (string) $row->entity_id,
        $guest ? '游客' : (string) ($row->name ?? '用户已删除'),
        $guest ? $this->guestLabel((string) $row->actor_hash) : (string) $row->uid,
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
    if (in_array($filters['tier'] ?? '', ['anonymous', 'member', 'vip'], TRUE)) {
      $query->condition('d.tier', (string) $filters['tier']);
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
    $states = ['queued' => '等待处理', 'running' => '正在生成', 'ready' => '已完成', 'failed' => '生成失败', 'expired' => '缓存已过期'];
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

  public function guestLabel(string $hash): string {
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
      return '访客';
    }
    return '访客 · ' . substr($hash, 0, 8);
  }

  /**
   * @return array<string, mixed>
   *   Real totals and daily series for the overview.
   */
  public function dashboard(): array {
    $cid = 'xedc_engagement:dashboard';
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }
    $now = $this->time->getRequestTime();
    $today = $now - ($now % 86400);
    $data = [
      'generated' => $now,
      'likes' => $this->likeSummary(),
      'like_member' => $this->countWhere('xedc_like', 'l.status = 1 AND l.uid > 0'),
      'like_guest' => $this->countWhere('xedc_like', 'l.status = 1 AND l.uid = 0'),
      'downloads' => $this->downloadSummary(),
      'shares' => \Drupal::service('xedc_core.share_recorder')->statistics(),
      'vip' => $this->activeVipCount(),
      'vip_soon' => $this->countWhere('xedc_vip', 'v.status = \'active\' AND v.expires > ' . $now . ' AND v.expires <= ' . ($now + 7 * 86400)),
      'vip_expired' => $this->countWhere('xedc_vip', 'v.expires <= ' . $now . ' OR v.status <> \'active\''),
      'likes_7' => $this->series('xedc_like', 'changed', $now - 7 * 86400, 'active'),
      'likes_30' => $this->series('xedc_like', 'changed', $now - 30 * 86400, 'active'),
      'downloads_7' => $this->series('xedc_download_log', 'created', $now - 7 * 86400, 'authorized'),
      'downloads_30' => $this->series('xedc_download_log', 'created', $now - 30 * 86400, 'authorized'),
      'shares_7' => $this->series('xedc_share', 'created', $now - 7 * 86400, 'success'),
      'bundles' => $this->bundleCounts(),
      'today_likes' => $this->countSince('xedc_like', 'changed', $today, 'l.status = 1'),
      'today_downloads' => $this->countSince('xedc_download_log', 'created', $today, ''),
      'zip' => $this->zipCounts(),
    ];
    $data['likes_prev'] = $this->countBetween('xedc_like', 'changed', $now - 14 * 86400, $now - 7 * 86400, 'l.status = 1');
    $this->cache->set($cid, $data, $now + 60, ['xedc_engagement']);
    return $data;
  }

  /**
   * @return array<string, int>
   *   Active likes by content type.
   */
  public function bundleCounts(): array {
    $query = $this->database->select('xedc_like', 'l');
    $query->addField('l', 'bundle');
    $query->addExpression('COUNT(*)', 'total');
    $query->condition('status', 1);
    $query->groupBy('bundle');
    $counts = [];
    foreach ($query->execute() as $row) {
      $counts[(string) $row->bundle] = (int) $row->total;
    }
    return $counts;
  }

  /**
   * @return array<string, int>
   *   ZIP jobs by status.
   */
  public function zipCounts(): array {
    if (!$this->database->schema()->tableExists('xedc_zip_job')) {
      return [];
    }
    $query = $this->database->select('xedc_zip_job', 'z');
    $query->addField('z', 'status');
    $query->addExpression('COUNT(*)', 'total');
    $query->groupBy('status');
    $counts = [];
    foreach ($query->execute() as $row) {
      $counts[(string) $row->status] = (int) $row->total;
    }
    return $counts;
  }

  /**
   * @return list<array<int, string|MarkupInterface>>
   *   One page of members who have an engagement row.
   */
  public function memberRows(): array {
    $query = $this->database->select('xedc_like', 'l');
    $query->fields('l', ['uid']);
    $query->condition('l.uid', 0, '>');
    $downloads = $this->database->select('xedc_download_log', 'd');
    $downloads->fields('d', ['uid']);
    $downloads->condition('d.uid', 0, '>');
    $shares = $this->database->select('xedc_share', 's');
    $shares->fields('s', ['uid']);
    $shares->condition('s.uid', 0, '>');
    $query->union($downloads, 'DISTINCT');
    $query->union($shares, 'DISTINCT');
    $query = $query->extend(PagerSelectExtender::class)->limit(40);
    $rows = [];
    $membership = \Drupal::service('xedc_core.membership');
    foreach ($query->execute() as $row) {
      $uid = (int) $row->uid;
      $account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
      $name = $account ? $account->getAccountName() : '用户已删除';
      $tier = $account ? $membership->snapshot($account)['label'] : '—';
      $likes = (int) $this->database->select('xedc_like', 'l')->condition('uid', $uid)->condition('status', 1)->countQuery()->execute()->fetchField();
      $downloadCount = (int) $this->database->select('xedc_download_log', 'd')->condition('uid', $uid)->countQuery()->execute()->fetchField();
      $shareCount = (int) $this->database->select('xedc_share', 's')->condition('uid', $uid)->condition('outcome', 'success')->countQuery()->execute()->fetchField();
      $latest = max(
        $this->maxStamp('xedc_like', 'changed', $uid),
        $this->maxStamp('xedc_download_log', 'created', $uid),
        $this->maxStamp('xedc_share', 'created', $uid)
      );
      $rows[] = [
        (string) $uid,
        Markup::create('<a href="' . htmlspecialchars(Url::fromRoute('xedc_core.engagement_member', ['user' => $uid])->toString(), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>'),
        (string) $tier,
        (string) $likes,
        (string) $downloadCount,
        (string) $shareCount,
        $latest > 0 ? date('Y-m-d H:i', $latest) : '—',
      ];
    }
    return $rows;
  }

  /**
   * @return array<string, int>
   *   Day => count. Missing days are zero. The range is capped at 90 days.
   */
  public function series(string $table, string $column, int $since, string $mode): array {
    $allowed = [
      'xedc_like' => 'changed',
      'xedc_download_log' => 'created',
      'xedc_share' => 'created',
    ];
    if (($allowed[$table] ?? '') !== $column) {
      return [];
    }
    $now = $this->time->getRequestTime();
    $since = max($since, $now - 90 * 86400);
    $query = $this->database->select($table, 't');
    $query->addExpression("FROM_UNIXTIME(t.$column, '%Y-%m-%d')", 'day');
    $query->addExpression('COUNT(*)', 'total');
    $query->condition($column, $since, '>=');
    if ($table === 'xedc_like' && $mode === 'active') {
      $query->condition('status', 1);
    }
    elseif ($table === 'xedc_download_log' && $mode === 'authorized') {
      $query->condition('status', 'authorized');
    }
    elseif ($table === 'xedc_share' && $mode === 'success') {
      $query->condition('outcome', 'success');
    }
    elseif ($table === 'xedc_like' && $mode === 'guest') {
      $query->condition('status', 1);
      $query->condition('uid', 0);
    }
    elseif ($table === 'xedc_like' && $mode === 'member') {
      $query->condition('status', 1);
      $query->condition('uid', 0, '>');
    }
    $query->groupBy('day');
    $found = [];
    foreach ($query->execute() as $row) {
      $found[(string) $row->day] = (int) $row->total;
    }
    $days = [];
    for ($stamp = $since - ($since % 86400); $stamp <= $now; $stamp += 86400) {
      $key = date('Y-m-d', $stamp);
      $days[$key] = $found[$key] ?? 0;
    }
    return $days;
  }

  protected function maxStamp(string $table, string $column, int $uid): int {
    $allowed = [
      'xedc_like' => 'changed',
      'xedc_download_log' => 'created',
      'xedc_share' => 'created',
    ];
    if (($allowed[$table] ?? '') !== $column || $uid < 1) {
      return 0;
    }
    $query = $this->database->select($table, 't');
    $query->condition('uid', $uid);
    $query->addExpression("MAX(t.$column)", 'latest');
    return (int) $query->execute()->fetchField();
  }

  protected function countWhere(string $table, string $where): int {
    $alias = match ($table) {
      'xedc_like' => 'l',
      'xedc_vip' => 'v',
      default => 't',
    };
    $query = $this->database->select($table, $alias);
    $query->where($where);
    return (int) $query->countQuery()->execute()->fetchField();
  }

  protected function countBetween(string $table, string $column, int $from, int $to, string $extra): int {
    $alias = $table === 'xedc_like' ? 'l' : 't';
    $query = $this->database->select($table, $alias);
    $query->condition($alias . '.' . $column, $from, '>=');
    $query->condition($alias . '.' . $column, $to, '<');
    if ($extra !== '') {
      $query->where($extra);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

}
