<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\xedc_core\Engagement\MembershipManager;

/**
 * VIP administration and engagement statistics.
 */
final class EngagementAdminController extends ControllerBase {

  public function vip(): array {
    $request = \Drupal::request();
    $q = trim((string) $request->query->get('q'));
    $query = \Drupal::database()->select('xedc_vip', 'v');
    $query->join('users_field_data', 'u', 'u.uid = v.uid');
    $query->fields('v');
    $query->addField('u', 'name');
    if ($q !== '') {
      $like = '%' . \Drupal::database()->escapeLike($q) . '%';
      if (ctype_digit($q)) {
        $group = $query->orConditionGroup()
          ->condition('u.name', $like, 'LIKE')
          ->condition('v.uid', (int) $q);
        $query->condition($group);
      }
      else {
        $query->condition('u.name', $like, 'LIKE');
      }
    }
    $query->orderBy('v.expires', 'ASC');
    $rows = $query->extend(\Drupal\Core\Database\Query\PagerSelectExtender::class)->limit(40)->execute();
    $table = [];
    $now = \Drupal::time()->getRequestTime();
    /** @var \Drupal\xedc_core\Engagement\MembershipManager $membership */
    $membership = \Drupal::service('xedc_core.membership');
    foreach ($rows as $row) {
      $account = $this->entityTypeManager()->getStorage('user')->load((int) $row->uid);
      $active = $account instanceof UserInterface && $membership->isActive($account);
      $remaining = $active ? (int) ceil(((int) $row->expires - $now) / 86400) : 0;
      $table[] = [
        $row->name,
        (string) $row->uid,
        $active ? 'VIP 会员' : ($row->status === 'cancelled' ? '已取消' : '普通会员'),
        date('Y-m-d H:i', (int) $row->starts),
        date('Y-m-d H:i', (int) $row->expires),
        $active ? (string) $remaining : '0',
        Link::fromTextAndUrl('记录', Url::fromRoute('xedc_core.vip_history', ['user' => $row->uid]))->toString(),
      ];
    }
    $activeCount = (int) \Drupal::database()->select('xedc_vip', 'v')->condition('status', 'active')->condition('expires', $now, '>')->countQuery()->execute()->fetchField();
    $soonCount = (int) \Drupal::database()->select('xedc_vip', 'v')->condition('status', 'active')->condition('expires', $now, '>')->condition('expires', $now + 7 * 86400, '<=')->countQuery()->execute()->fetchField();
    $expiredQuery = \Drupal::database()->select('xedc_vip', 'v');
    $expiredCount = (int) $expiredQuery->condition($expiredQuery->orConditionGroup()->condition('expires', $now, '<=')->condition('status', 'active', '<>'))->countQuery()->execute()->fetchField();
    return $this->present('VIP 会员', '是否有效以 xedc_vip 的状态和到期时间为准。角色只是同步结果。不提供在线支付。', [
      'cards' => $this->cards([
        ['当前有效', $activeCount, '未过期且状态为有效'],
        ['即将到期', $soonCount, '7 天内到期'],
        ['已失效', $expiredCount, '已过期或已取消'],
      ]),
      'form' => \Drupal::formBuilder()->getForm('Drupal\xedc_core\Form\VipGrantForm'),
      'search' => [
        '#markup' => '<form method="get" class="xedc-ops__filters"><label>用户名或 UID <input name="q" value="' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '"></label> <button type="submit">搜索</button></form>',
        '#allowed_tags' => ['form', 'label', 'input', 'button'],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => ['会员', 'UID', '当前等级', '开通时间', '到期时间', '剩余天数', '操作'],
        '#rows' => $table,
        '#empty' => '还没有 VIP 记录。',
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ]);
  }

  public function history(UserInterface $user): array {
    /** @var \Drupal\xedc_core\Engagement\MembershipManager $membership */
    $membership = \Drupal::service('xedc_core.membership');
    $snapshot = $membership->snapshot($user);
    $rows = [];
    foreach ($membership->history((int) $user->id()) as $row) {
      $rows[] = [
        date('Y-m-d H:i', (int) $row->created),
        (string) $row->action,
        $row->starts ? date('Y-m-d H:i', (int) $row->starts) : '—',
        $row->expires ? date('Y-m-d H:i', (int) $row->expires) : '—',
        (string) $row->note,
        (string) $row->actor,
      ];
    }
    return [
      '#markup' => '<p>' . htmlspecialchars($user->getAccountName() . '：' . $snapshot['label'], ENT_QUOTES, 'UTF-8') . '</p>',
      'table' => [
        '#type' => 'table',
        '#header' => ['时间', '操作', '开始', '到期', '备注', '操作者'],
        '#rows' => $rows,
        '#empty' => '没有操作记录。',
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function likes(): array {
    $filters = $this->filters();
    $report = $this->report();
    $summary = $report->likeSummary();
    $bundles = $report->bundleCounts();
    $build = [
      'cards' => $this->cards([
        ['有效点赞', (int) $summary['total'], '今日 ' . (int) $summary['today']],
        ['近 7 天', (int) $summary['week'], '近 30 天 ' . (int) $summary['month']],
        ['图片', (int) ($bundles['photo'] ?? 0), '有效记录'],
        ['视频', (int) ($bundles['video'] ?? 0), '有效记录'],
        ['文章', (int) ($bundles['article'] ?? 0), '有效记录'],
      ]),
      'form' => $this->filterForm($filters, TRUE),
      'rank_title' => ['#markup' => '<h3 class="xedc-ops__section">点赞最多的内容</h3>'],
      'rank' => [
        '#type' => 'table',
        '#header' => ['排名', '内容', '封面', '类型', '有效点赞'],
        '#rows' => $report->likeRank($filters['bundle']),
        '#empty' => '还没有点赞。',
      ],
      'list_title' => ['#markup' => '<h3 class="xedc-ops__section">点赞记录</h3>'],
      'table' => [
        '#type' => 'table',
        '#header' => ['记录', '内容', '类型', '内容 ID', '来源', '标识', '点赞时间', '状态'],
        '#rows' => $report->likeRows($filters),
        '#empty' => '没有符合条件的点赞。',
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
    if ($this->currentUser()->hasPermission('administer xedc engagement')) {
      $build['state_title'] = ['#markup' => '<h3 class="xedc-ops__section">更正状态</h3><p class="xedc-ops__meta">只改变有效或已取消，不删除记录。</p>'];
      $build['state'] = \Drupal::formBuilder()->getForm(\Drupal\xedc_core\Form\LikeStateForm::class);
    }
    return $this->present('点赞管理', '游客只显示脱敏标识，不显示身份原文。已取消的记录仍保留。', $build);
  }

  public function downloads(): array {
    $filters = $this->filters();
    $report = $this->report();
    $summary = $report->downloadSummary();
    $sources = $report->downloadSources();
    $stats = \Drupal::service('xedc_core.download_recorder')->statistics();
    return $this->present('下载管理', '次数是服务器已授权的交付，不是按钮点击，也不代表浏览器已经收完文件。ZIP 缓存命中仍会重新检查权限。', [
      'note' => ['#markup' => '<p>次数统计的是服务器已授权的交付，不是按钮点击，也不代表浏览器已经收完文件。生成中和失败会单独出现在明细里。</p>'],
      'summary' => ['#markup' => '<p>已授权 ' . $summary['total'] . '。今日 ' . $summary['today'] . '，近 7 天 ' . $summary['week'] . '，近 30 天 ' . $summary['month'] . '。游客 ' . $summary['anonymous'] . '，普通会员 ' . $summary['member'] . '，VIP ' . $summary['vip'] . '。</p><p>图片 ' . (int) ($stats['bundles']['photo'] ?? 0) . '，视频 ' . (int) ($stats['bundles']['video'] ?? 0) . '，文章 ' . (int) ($stats['bundles']['article'] ?? 0) . '。原图 ' . (int) ($sources['originals'] ?? 0) . '，整组 ZIP ' . (int) ($sources['zip'] ?? 0) . '，上传文件 ' . (int) ($sources['upload'] ?? 0) . '，外链 ' . (int) ($sources['external'] ?? 0) . '。</p>'],
      'form' => $this->filterForm($filters, FALSE),
      'rank_title' => ['#markup' => '<h3>内容下载排行</h3>'],
      'rank' => [
        '#type' => 'table',
        '#header' => ['排名', '内容', '类型', '已授权次数'],
        '#rows' => $report->downloadRank(),
        '#empty' => '还没有下载记录。',
      ],
      'resource_title' => ['#markup' => '<h3>下载资源排行</h3>'],
      'resources' => [
        '#type' => 'table',
        '#header' => ['资源', '内容', '来源', '已授权次数'],
        '#rows' => $report->resourceRank(),
        '#empty' => '还没有资源记录。',
      ],
      'list_title' => ['#markup' => '<h3>下载记录</h3>'],
      'table' => [
        '#type' => 'table',
        '#header' => ['内容', '类型', '资源', '来源', '格式', '用户', '当时等级', '时间', '结果'],
        '#rows' => $report->downloadRows($filters),
        '#empty' => '没有符合条件的下载。',
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ]);
  }

  public function overview(): array {
    $report = $this->report();
    $data = $report->dashboard();
    $likes = $data['likes'];
    $downloads = $data['downloads'];
    $shares = $data['shares'];
    $shareSuccess = (int) ($shares['copy'] ?? 0) + (int) ($shares['native'] ?? 0);
    $delta = (int) $likes['week'] - (int) $data['likes_prev'];
    $cards = [
      ['有效点赞', (int) $likes['total'], '较前 7 天 ' . ($delta > 0 ? '+' : '') . $delta],
      ['游客点赞', (int) $data['like_guest'], '有效记录'],
      ['会员点赞', (int) $data['like_member'], '有效记录'],
      ['已授权下载', (int) $downloads['total'], '今日 ' . (int) $downloads['today']],
      ['普通会员下载', (int) $downloads['member'], '按当时等级'],
      ['VIP 下载', (int) $downloads['vip'], '按当时等级'],
      ['成功分享', $shareSuccess, '不含打开面板'],
      ['有效 VIP', (int) $data['vip'], '7 天内到期 ' . (int) $data['vip_soon']],
    ];
    $today = (int) $data['today_likes'] + (int) $data['today_downloads'];
    return $this->present('互动总览', '数字来自点赞、下载、分享和 VIP 记录。打开分享面板不算成功分享。统计时间 ' . date('Y-m-d H:i', (int) $data['generated']) . '。', [
      'cards' => $this->cards($cards),
      'today' => ['#markup' => '<p class="xedc-ops__meta">今日互动 ' . $today . '（有效点赞 ' . (int) $data['today_likes'] . '，下载授权 ' . (int) $data['today_downloads'] . '）。</p>'],
      'charts' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-ops__grid']],
        'likes' => $this->bars('近 7 天点赞', $data['likes_7']),
        'downloads' => $this->bars('近 7 天下载', $data['downloads_7']),
        'mix' => $this->split((int) $data['like_guest'], (int) $data['like_member']),
        'types' => $this->bars('内容类型点赞', [
          '图片' => (int) ($data['bundles']['photo'] ?? 0),
          '视频' => (int) ($data['bundles']['video'] ?? 0),
          '文章' => (int) ($data['bundles']['article'] ?? 0),
        ]),
      ],
      'month' => $this->bars('近 30 天点赞', $data['likes_30']),
      'rank_title' => ['#markup' => '<h3 class="xedc-ops__section">最受欢迎内容</h3>'],
      'likes' => [
        '#type' => 'table',
        '#header' => ['排名', '内容', '封面', '类型', '有效点赞'],
        '#rows' => $report->likeRank(),
        '#empty' => '还没有点赞。',
      ],
      'downloads' => [
        '#type' => 'table',
        '#header' => ['排名', '内容', '类型', '已授权次数'],
        '#rows' => $report->downloadRank(),
        '#empty' => '还没有下载。',
      ],
      'zip_title' => ['#markup' => '<h3 class="xedc-ops__section">ZIP 任务</h3>'],
      'zip' => [
        '#type' => 'table',
        '#header' => ['状态', '数量'],
        '#rows' => $this->zipCountRows($data['zip']),
        '#empty' => '还没有 ZIP 任务。',
      ],
      '#cache' => ['max-age' => 0],
    ]);
  }

  public function zips(): array {
    $build = [
      'note' => ['#markup' => '<p>这里是整组 ZIP 的最近一次状态。同一组原图没有变化时，成功文件保留 7 天并直接复用，不会重新压缩。临时文件约 6 小时清理，正在生成的文件不会删。已授权不等于浏览器已经收完文件。</p>'],
      'table' => [
        '#type' => 'table',
        '#header' => ['内容', '资源 ID', '状态', '说明', '创建', '更新'],
        '#rows' => $this->report()->zipJobs(),
        '#empty' => '还没有 ZIP 任务。',
      ],
      '#cache' => ['max-age' => 0],
    ];
    if (\Drupal::currentUser()->hasPermission('administer xedc downloads') || \Drupal::currentUser()->hasPermission('administer xedc vip')) {
      $build['retry'] = \Drupal::formBuilder()->getForm(\Drupal\xedc_core\Form\ZipRetryForm::class);
    }
    return $build;
  }

  public function shares(): array {
    $stats = \Drupal::service('xedc_core.share_recorder')->statistics();
    $build = $this->stats('分享统计', [
      'total' => (int) $stats['copy'] + (int) $stats['native'],
      'bundles' => [
        'copy' => (int) $stats['copy'],
        'native' => (int) $stats['native'],
        'panel' => (int) $stats['panel'],
      ],
      'days' => $stats['days'],
    ], [
      'copy' => '成功复制链接',
      'native' => '成功调用系统分享',
      'panel' => '打开分享面板（不算成功分享）',
    ], '打开面板只记为意向。成功复制和系统分享完成才计入成功分享。不能据此判断已经发到社交平台。');
    $build['events'] = [
      '#type' => 'table',
      '#header' => ['内容', '类型', '方式', '结果', '用户', '时间'],
      '#rows' => $this->report()->shareRows(),
      '#empty' => '还没有分享记录。',
    ];
    $build['pager'] = ['#type' => 'pager'];
    return $build;
  }

  protected function report(): \Drupal\xedc_core\Engagement\EngagementReport {
    return \Drupal::service('xedc_core.engagement_report');
  }

  /**
   * @return array<string, string>
   *   Sanitized GET filters.
   */
  protected function filters(): array {
    $request = \Drupal::request();
    $keys = ['bundle', 'q', 'user', 'from', 'to', 'state', 'source', 'sort', 'who', 'tier', 'range'];
    $filters = [];
    foreach ($keys as $key) {
      $value = $request->query->get($key);
      $filters[$key] = is_string($value) ? trim($value) : '';
    }
    return $filters;
  }

  /**
   * @param array<string, string> $filters
   *   Current values.
   */
  protected function filterForm(array $filters, bool $likes): array {
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $bundle = '<select name="bundle"><option value="">全部类型</option>'
      . '<option value="photo"' . ($filters['bundle'] === 'photo' ? ' selected' : '') . '>图片</option>'
      . '<option value="video"' . ($filters['bundle'] === 'video' ? ' selected' : '') . '>视频</option>'
      . '<option value="article"' . ($filters['bundle'] === 'article' ? ' selected' : '') . '>文章</option></select>';
    $extra = $likes
      ? '<label>状态 <select name="state"><option value="">全部</option><option value="1"' . ($filters['state'] === '1' ? ' selected' : '') . '>有效</option><option value="0"' . ($filters['state'] === '0' ? ' selected' : '') . '>已取消</option></select></label>'
        . ' <label>来源 <select name="who"><option value="">全部</option><option value="guest"' . (($filters['who'] ?? '') === 'guest' ? ' selected' : '') . '>游客</option><option value="member"' . (($filters['who'] ?? '') === 'member' ? ' selected' : '') . '>会员</option></select></label>'
        . ' <label>排序 <select name="sort"><option value="">按时间</option><option value="count"' . (($filters['sort'] ?? '') === 'count' ? ' selected' : '') . '>按内容点赞数</option></select></label>'
      : '<label>来源 <select name="source"><option value="">全部来源</option><option value="originals"' . ($filters['source'] === 'originals' ? ' selected' : '') . '>原图</option><option value="zip"' . ($filters['source'] === 'zip' ? ' selected' : '') . '>整组 ZIP</option><option value="upload"' . ($filters['source'] === 'upload' ? ' selected' : '') . '>上传文件</option><option value="external"' . ($filters['source'] === 'external' ? ' selected' : '') . '>外部链接</option></select></label>'
        . ' <label>等级 <select name="tier"><option value="">全部</option><option value="anonymous"' . (($filters['tier'] ?? '') === 'anonymous' ? ' selected' : '') . '>游客</option><option value="member"' . (($filters['tier'] ?? '') === 'member' ? ' selected' : '') . '>普通会员</option><option value="vip"' . (($filters['tier'] ?? '') === 'vip' ? ' selected' : '') . '>VIP</option></select></label>';
    $html = '<form method="get" class="xedc-ops__filters">'
      . '<label>类型 ' . $bundle . '</label>'
      . ' <label>标题 <input name="q" value="' . $e($filters['q']) . '"></label>'
      . ' <label>用户 <input name="user" value="' . $e($filters['user']) . '" placeholder="用户名或 UID"></label>'
      . ' <label>开始 <input name="from" value="' . $e($filters['from']) . '" placeholder="YYYY-MM-DD"></label>'
      . ' <label>结束 <input name="to" value="' . $e($filters['to']) . '" placeholder="YYYY-MM-DD"></label>'
      . ' ' . $extra
      . ' <button type="submit">筛选</button></form>';
    return [
      '#markup' => $html,
      '#allowed_tags' => ['form', 'label', 'select', 'option', 'input', 'button'],
    ];
  }

  /**
   * @param array{total: int, bundles: array<string, int>, days: array<string, int>} $stats
   *   Aggregate numbers.
   * @param array<string, string> $labels
   *   Bundle labels.
   */
  protected function stats(string $title, array $stats, array $labels, string $note = ''): array {
    $bundleRows = [];
    foreach ($labels as $key => $label) {
      $bundleRows[] = [$label, (string) ($stats['bundles'][$key] ?? 0)];
    }
    $days = [];
    foreach ($stats['days'] as $day => $count) {
      $days[] = [$day, (string) $count];
    }
    return $this->present($title, $note, [
      'cards' => $this->cards([
        ['合计', (int) $stats['total'], ''],
      ]),
      'bundles' => [
        '#type' => 'table',
        '#header' => ['分类', '数量'],
        '#rows' => $bundleRows,
      ],
      'days' => [
        '#type' => 'table',
        '#header' => ['日期', '数量'],
        '#rows' => $days,
        '#empty' => '这段时间没有记录。',
      ],
      '#cache' => ['max-age' => 0],
    ]);
  }

  public function members(): array {
    return $this->present('会员互动', '只列出有点赞、下载或成功分享记录的登录会员。游客点赞不进入这里。', [
      'table' => [
        '#type' => 'table',
        '#header' => ['UID', '用户名', '等级', '有效点赞', '下载', '成功分享', '最近互动'],
        '#rows' => $this->report()->memberRows(),
        '#empty' => '还没有会员互动。',
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ]);
  }

  public function member(UserInterface $user): array {
    $uid = (int) $user->id();
    $filters = ['bundle' => '', 'q' => '', 'user' => (string) $uid, 'from' => '', 'to' => '', 'state' => '', 'source' => '', 'sort' => '', 'who' => 'member', 'tier' => ''];
    $report = $this->report();
    return $this->present($user->getAccountName(), 'UID ' . $uid . ' 的互动记录。不包含其他会员，也不包含游客点赞。', [
      'likes' => [
        '#type' => 'table',
        '#caption' => '点赞',
        '#header' => ['记录', '内容', '类型', '内容 ID', '来源', '标识', '点赞时间', '状态'],
        '#rows' => $report->likeRows($filters),
        '#empty' => '没有点赞。',
      ],
      'downloads' => [
        '#type' => 'table',
        '#caption' => '下载',
        '#header' => ['内容', '类型', '资源', '来源', '格式', '用户', '当时等级', '时间', '结果'],
        '#rows' => $report->downloadRows($filters),
        '#empty' => '没有下载。',
      ],
      '#cache' => ['max-age' => 0],
    ]);
  }

  public function statistics(): array {
    $range = $this->range();
    $report = $this->report();
    $likes = $report->series('xedc_like', 'changed', $range['since'], 'active');
    $downloads = $report->series('xedc_download_log', 'created', $range['since'], 'authorized');
    $shares = $report->series('xedc_share', 'created', $range['since'], 'success');
    $guest = array_sum($report->series('xedc_like', 'changed', $range['since'], 'guest'));
    $member = array_sum($report->series('xedc_like', 'changed', $range['since'], 'member'));
    $export = Url::fromRoute('xedc_core.engagement_export', [], ['query' => \Drupal::request()->query->all()])->toString();
    return $this->present('互动数据', $range['label'] . '。导出的是按天汇总，不是逐条记录。', [
      'form' => $this->rangeForm($range),
      'cards' => $this->cards([
        ['点赞', array_sum($likes), '有效记录'],
        ['下载', array_sum($downloads), '已授权'],
        ['分享', array_sum($shares), '复制或系统分享成功'],
        ['游客点赞', $guest, '同期会员点赞 ' . $member],
      ]),
      'charts' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-ops__grid']],
        'likes' => $this->bars('点赞趋势', $likes),
        'downloads' => $this->bars('下载趋势', $downloads),
        'shares' => $this->bars('分享趋势', $shares),
      ],
      'types' => $this->bars('内容类型点赞', [
        '图片' => (int) ($report->bundleCounts()['photo'] ?? 0),
        '视频' => (int) ($report->bundleCounts()['video'] ?? 0),
        '文章' => (int) ($report->bundleCounts()['article'] ?? 0),
      ]),
      'hot' => [
        '#type' => 'table',
        '#caption' => '热门内容',
        '#header' => ['排名', '内容', '封面', '类型', '有效点赞'],
        '#rows' => $report->likeRank(),
        '#empty' => '还没有点赞。',
      ],
      'export' => ['#markup' => '<p><a class="button" href="' . htmlspecialchars($export, ENT_QUOTES, 'UTF-8') . '">导出 CSV</a></p>'],
      '#cache' => ['max-age' => 0],
    ]);
  }

  public function export(): \Symfony\Component\HttpFoundation\StreamedResponse {
    $range = $this->range();
    $report = $this->report();
    $likes = $report->series('xedc_like', 'changed', $range['since'], 'active');
    $downloads = $report->series('xedc_download_log', 'created', $range['since'], 'authorized');
    $shares = $report->series('xedc_share', 'created', $range['since'], 'success');
    $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($likes, $downloads, $shares): void {
      $out = fopen('php://output', 'wb');
      if ($out === FALSE) {
        return;
      }
      fwrite($out, "\xEF\xBB\xBF");
      fputcsv($out, ['日期', '有效点赞', '已授权下载', '成功分享']);
      $days = array_unique(array_merge(array_keys($likes), array_keys($downloads), array_keys($shares)));
      sort($days);
      $written = 0;
      foreach ($days as $day) {
        if ($written >= 5000) {
          break;
        }
        fputcsv($out, [$day, (string) ($likes[$day] ?? 0), (string) ($downloads[$day] ?? 0), (string) ($shares[$day] ?? 0)]);
        $written++;
      }
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="xedc-engagement.csv"');
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  /**
   * @param list<array{0: string, 1: int, 2: string}> $items
   *   Label, value and hint.
   */
  protected function cards(array $items): array {
    $html = '<div class="xedc-ops__cards">';
    foreach ($items as $item) {
      $html .= '<article class="xedc-ops__card"><span>' . htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') . '</span><strong>' . (int) $item[1] . '</strong>';
      if (($item[2] ?? '') !== '') {
        $html .= '<em>' . htmlspecialchars($item[2], ENT_QUOTES, 'UTF-8') . '</em>';
      }
      $html .= '</article>';
    }
    $html .= '</div>';
    return ['#markup' => \Drupal\Core\Render\Markup::create($html)];
  }

  /**
   * @param array<string, int> $points
   *   Label or day to count.
   */
  protected function bars(string $title, array $points): array {
    $max = 1;
    $sum = 0;
    foreach ($points as $value) {
      $max = max($max, (int) $value);
      $sum += (int) $value;
    }
    $html = '<section class="xedc-ops__chart"><h3>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';
    if ($points === [] || $sum === 0) {
      $html .= '<p class="xedc-ops__empty">这段时间没有记录。</p></section>';
      return ['#markup' => \Drupal\Core\Render\Markup::create($html)];
    }
    $html .= '<div class="xedc-ops__bars" role="img" aria-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($points as $label => $value) {
      $height = max(4, (int) round(((int) $value / $max) * 100));
      $html .= '<span title="' . htmlspecialchars((string) $label . ' ' . (int) $value, ENT_QUOTES, 'UTF-8') . '" style="height:' . $height . '%"></span>';
    }
    $html .= '</div></section>';
    return ['#markup' => \Drupal\Core\Render\Markup::create($html)];
  }

  protected function split(int $guest, int $member): array {
    $total = max(1, $guest + $member);
    $guestWidth = (int) round($guest / $total * 100);
    $html = '<section class="xedc-ops__chart"><h3>游客与会员点赞</h3>';
    if ($guest + $member === 0) {
      $html .= '<p class="xedc-ops__empty">还没有有效点赞。</p></section>';
      return ['#markup' => \Drupal\Core\Render\Markup::create($html)];
    }
    $html .= '<div class="xedc-ops__split" role="img" aria-label="游客 ' . $guest . '，会员 ' . $member . '"><i style="width:' . $guestWidth . '%"></i></div>';
    $html .= '<p class="xedc-ops__meta">游客 ' . $guest . ' · 会员 ' . $member . '</p></section>';
    return ['#markup' => \Drupal\Core\Render\Markup::create($html)];
  }

  /**
   * @param array<string, int> $counts
   *   Status to count.
   *
   * @return list<array<int, string>>
   *   Table rows.
   */
  protected function zipCountRows(array $counts): array {
    $labels = [
      'queued' => '等待处理',
      'running' => '正在生成',
      'ready' => '已完成',
      'failed' => '生成失败',
      'expired' => '缓存已过期',
    ];
    $rows = [];
    foreach ($labels as $key => $label) {
      $rows[] = [$label, (string) ($counts[$key] ?? 0)];
    }
    return $rows;
  }

  protected function present(string $title, string $lead, array $build): array {
    $build['#type'] = 'container';
    $build['#attributes']['class'][] = 'xedc-ops';
    $build['#attached']['library'][] = 'xedc_admin/engagement';
    $build['lead'] = [
      '#weight' => -50,
      '#markup' => '<p class="xedc-ops__lead">' . htmlspecialchars($lead, ENT_QUOTES, 'UTF-8') . '</p>',
    ];
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  /**
   * @return array{since: int, label: string, range: string, from: string, to: string}
   *   Selected window. Custom ranges are capped at 90 days.
   */
  protected function range(): array {
    $request = \Drupal::request();
    $range = (string) $request->query->get('range', '7');
    $now = \Drupal::time()->getRequestTime();
    $from = (string) $request->query->get('from', '');
    $to = (string) $request->query->get('to', '');
    if ($range === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
      $start = strtotime($from . ' 00:00:00') ?: $now - 7 * 86400;
      $end = strtotime($to . ' 23:59:59') ?: $now;
      if ($end < $start) {
        $end = $start + 86400;
      }
      if ($end - $start > 90 * 86400) {
        $start = $end - 90 * 86400;
      }
      return ['since' => $start, 'label' => $from . ' 至 ' . $to . '（最长 90 天）', 'range' => 'custom', 'from' => $from, 'to' => $to];
    }
    $days = match ($range) {
      '1' => 1,
      '30' => 30,
      '90' => 90,
      default => 7,
    };
    return ['since' => $now - $days * 86400, 'label' => $days === 1 ? '今日' : '最近 ' . $days . ' 天', 'range' => (string) $days, 'from' => $from, 'to' => $to];
  }

  /**
   * @param array{range: string, from: string, to: string} $range
   *   Current selection.
   */
  protected function rangeForm(array $range): array {
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $options = ['1' => '今日', '7' => '最近 7 天', '30' => '最近 30 天', '90' => '最近 90 天', 'custom' => '自定义'];
    $select = '<label>时间 <select name="range">';
    foreach ($options as $value => $label) {
      $select .= '<option value="' . $value . '"' . ($range['range'] === (string) $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    $select .= '</select></label>';
    return [
      '#markup' => '<form method="get" class="xedc-ops__filters">' . $select
        . ' <label>开始 <input name="from" value="' . $e($range['from']) . '" placeholder="YYYY-MM-DD"></label>'
        . ' <label>结束 <input name="to" value="' . $e($range['to']) . '" placeholder="YYYY-MM-DD"></label>'
        . ' <button type="submit">查看</button></form>',
      '#allowed_tags' => ['form', 'label', 'select', 'option', 'input', 'button'],
    ];
  }

}
