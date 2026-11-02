<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
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
      $query->condition('u.name', '%' . \Drupal::database()->escapeLike($q) . '%', 'LIKE');
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
        $active ? 'VIP 会员' : ($row->status === 'cancelled' ? '已取消' : '普通会员'),
        date('Y-m-d H:i', (int) $row->starts),
        date('Y-m-d H:i', (int) $row->expires),
        $active ? (string) $remaining : '0',
        Link::fromTextAndUrl('记录', Url::fromRoute('xedc_core.vip_history', ['user' => $row->uid]))->toString(),
      ];
    }
    return [
      '#type' => 'container',
      'help' => ['#markup' => '<p>VIP 是否有效以到期时间和状态为准。过期或取消后，下一次权限检查立即失效，不等待定时任务。</p>'],
      'form' => \Drupal::formBuilder()->getForm('Drupal\xedc_core\Form\VipGrantForm'),
      'search' => [
        '#markup' => '<form method="get"><label>用户名 <input name="q" value="' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '"></label> <button type="submit">搜索</button></form>',
        '#allowed_tags' => ['form', 'label', 'input', 'button'],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => ['会员', '当前等级', '开通时间', '到期时间', '剩余天数', '操作'],
        '#rows' => $table,
        '#empty' => '还没有 VIP 记录。',
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
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
    $stats = \Drupal::service('xedc_core.like_manager')->statistics();
    return [
      'summary' => ['#markup' => '<p>有效点赞 ' . $summary['total'] . '。今日 ' . $summary['today'] . '，近 7 天 ' . $summary['week'] . '，近 30 天 ' . $summary['month'] . '。图片 ' . (int) ($stats['bundles']['photo'] ?? 0) . '，视频 ' . (int) ($stats['bundles']['video'] ?? 0) . '，文章 ' . (int) ($stats['bundles']['article'] ?? 0) . '。已取消的点赞不计入有效数量。</p>'],
      'form' => $this->filterForm($filters, TRUE),
      'rank_title' => ['#markup' => '<h3>点赞最多的内容</h3>'],
      'rank' => [
        '#type' => 'table',
        '#header' => ['排名', '内容', '封面', '类型', '有效点赞'],
        '#rows' => $report->likeRank($filters['bundle']),
        '#empty' => '还没有点赞。',
      ],
      'list_title' => ['#markup' => '<h3>点赞记录</h3>'],
      'table' => [
        '#type' => 'table',
        '#header' => ['内容', '类型', '内容 ID', '会员', 'UID', '点赞时间', '状态'],
        '#rows' => $report->likeRows($filters),
        '#empty' => '没有符合条件的点赞。',
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function downloads(): array {
    $filters = $this->filters();
    $report = $this->report();
    $summary = $report->downloadSummary();
    $sources = $report->downloadSources();
    $stats = \Drupal::service('xedc_core.download_recorder')->statistics();
    return [
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
    ];
  }

  public function overview(): array {
    $report = $this->report();
    $likes = $report->likeSummary();
    $downloads = $report->downloadSummary();
    $shares = \Drupal::service('xedc_core.share_recorder')->statistics();
    $likeDays = \Drupal::service('xedc_core.like_manager')->statistics(7)['days'];
    $downloadDays = \Drupal::service('xedc_core.download_recorder')->statistics(7)['days'];
    $likeTrend = [];
    $downloadTrend = [];
    foreach ($likeDays as $day => $count) {
      $likeTrend[] = [$day, (string) $count, (string) ($downloadDays[$day] ?? 0)];
    }
    return [
      'summary' => ['#markup' => '<p>有效点赞 ' . $likes['total'] . '，今日 ' . $likes['today'] . '。已授权下载 ' . $downloads['total'] . '，今日 ' . $downloads['today'] . '。成功分享 ' . ((int) $shares['copy'] + (int) $shares['native']) . '。有效 VIP ' . $report->activeVipCount() . '。打开分享面板不计为成功分享。</p>'],
      'trend' => [
        '#type' => 'table',
        '#header' => ['日期', '有效点赞', '已授权下载'],
        '#rows' => $likeTrend,
        '#caption' => '近 7 天',
        '#empty' => '还没有趋势数据。',
      ],
      'likes' => [
        '#type' => 'table',
        '#header' => ['排名', '内容', '封面', '类型', '有效点赞'],
        '#rows' => $report->likeRank(),
        '#caption' => '点赞最多的内容',
        '#empty' => '还没有点赞。',
      ],
      'downloads' => [
        '#type' => 'table',
        '#header' => ['排名', '内容', '类型', '已授权次数'],
        '#rows' => $report->downloadRank(),
        '#caption' => '下载最多的内容',
        '#empty' => '还没有下载。',
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function zips(): array {
    $build = [
      'note' => ['#markup' => '<p>这里是整组 ZIP 的最近一次状态。已授权不等于浏览器已经收完文件。</p>'],
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
    $keys = ['bundle', 'q', 'user', 'from', 'to', 'state', 'source', 'sort'];
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
      ? '<select name="state"><option value="">全部状态</option><option value="1"' . ($filters['state'] === '1' ? ' selected' : '') . '>有效</option><option value="0"' . ($filters['state'] === '0' ? ' selected' : '') . '>已取消</option></select>'
        . ' <select name="sort"><option value="">按点赞时间</option><option value="count"' . (($filters['sort'] ?? '') === 'count' ? ' selected' : '') . '>按内容点赞数</option></select>'
      : '<select name="source"><option value="">全部来源</option><option value="originals"' . ($filters['source'] === 'originals' ? ' selected' : '') . '>原图</option><option value="zip"' . ($filters['source'] === 'zip' ? ' selected' : '') . '>整组 ZIP</option><option value="upload"' . ($filters['source'] === 'upload' ? ' selected' : '') . '>上传文件</option><option value="external"' . ($filters['source'] === 'external' ? ' selected' : '') . '>外部链接</option></select>';
    $html = '<form method="get" class="xedc-admin-filters">'
      . $bundle
      . ' <input name="q" value="' . $e($filters['q']) . '" placeholder="内容标题">'
      . ' <input name="user" value="' . $e($filters['user']) . '" placeholder="用户名或 UID">'
      . ' <input name="from" value="' . $e($filters['from']) . '" placeholder="开始日期">'
      . ' <input name="to" value="' . $e($filters['to']) . '" placeholder="结束日期">'
      . ' ' . $extra
      . ' <button type="submit">筛选</button></form>';
    return [
      '#markup' => $html,
      '#allowed_tags' => ['form', 'select', 'option', 'input', 'button'],
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
    return [
      '#type' => 'container',
      'title' => ['#markup' => '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>'],
      'note' => ['#markup' => $note !== '' ? '<p>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</p>' : ''],
      'total' => ['#markup' => '<p>合计：' . (int) $stats['total'] . '</p>'],
      'bundles' => [
        '#type' => 'table',
        '#header' => ['分类', '数量'],
        '#rows' => $bundleRows,
      ],
      'days' => [
        '#type' => 'table',
        '#header' => ['日期', '数量'],
        '#rows' => $days,
        '#caption' => '近 14 天',
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
