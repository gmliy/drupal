<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\xedc_ai\Form\SeoApplyForm;
use Drupal\xedc_ai\Form\SeoSuggestForm;
use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Review\ReviewRepository;
use Drupal\xedc_ai\Review\TextExtractor;
use Drupal\xedc_ai\Seo\ContentLookup;
use Drupal\xedc_ai\Seo\SeoAdvisor;
use Drupal\xedc_ai\Seo\SeoResult;
use Drupal\xedc_ai\Usage\AuditLog;
use Drupal\xedc_ai\Usage\UsageLogger;
use Drupal\xedc_ai\Usage\UsageReport;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * SEO workbench. Opening the list does not call a model.
 */
final class SeoController extends ControllerBase {

  public function __construct(
    private readonly SeoAdvisor $seo,
    private readonly ReviewRepository $reviews,
    private readonly TextExtractor $extractor,
    private readonly UsageLogger $usage,
    private readonly AuditLog $audit,
    private readonly ContentLookup $lookup,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.seo'),
      $container->get('xedc_ai.reviews'),
      $container->get('xedc_ai.extractor'),
      $container->get('xedc_ai.usage'),
      $container->get('xedc_ai.audit'),
      $container->get('xedc_ai.lookup'),
      $container->get('date.formatter'),
    );
  }

  /**
   * SEO list and content picker.
   */
  public function page(Request $request): array {
    $score = (string) $request->query->get('score', '');
    [$min, $max] = match ($score) {
      'low' => ['0', '59'],
      'mid' => ['60', '79'],
      'high' => ['80', '100'],
      default => ['', ''],
    };
    $filters = [
      'source' => 'seo_optimization',
      'bundle' => (string) $request->query->get('bundle', ''),
      'model_name' => (string) $request->query->get('model', ''),
      'q' => (string) $request->query->get('q', ''),
      'seo_min' => $min,
      'seo_max' => $max,
      'issues' => (string) $request->query->get('issues', ''),
      'ops' => (string) $request->query->get('ops', ''),
      'from' => $this->stamp((string) $request->query->get('from', ''), FALSE),
      'to' => $this->stamp((string) $request->query->get('to', ''), TRUE),
    ];
    $rows = [];
    $pending = 0;
    $adopted = 0;
    $scoreTotal = 0;
    $scoreCount = 0;
    $needsWork = 0;
    foreach ($this->reviews->search($filters) as $row) {
      $result = json_decode((string) $row['result'], TRUE);
      $result = SeoResult::hydrate(is_array($result) ? $result : []);
      $default = $this->extractor->loadRevision('node', (int) $row['entity_id'], 0);
      $stale = $default !== NULL
        && (int) $default->getRevisionId() !== (int) $row['revision_id']
        && (int) $default->getRevisionId() !== (int) ($result['applied_revision_id'] ?? 0);
      $ops = SeoResult::opsStatus((string) $row['status'], (string) $row['handled_status'], $result, $stale);
      $report = UsageReport::summarize($this->usage->callsForReview((int) $row['id']), (string) $row['status'], (string) $row['error_code']);
      foreach (SeoResult::itemRows($result) as $item) {
        $state = (string) ($item['state'] ?? 'pending');
        if ($state === '' || $state === 'pending' || $state === 'failed') {
          $pending++;
        }
        elseif ($state === 'adopted') {
          $adopted++;
        }
      }
      if ($row['seo_score'] !== NULL) {
        $scoreTotal += (int) $row['seo_score'];
        $scoreCount++;
        if ((int) $row['seo_score'] < 80) {
          $needsWork++;
        }
      }
      $tokenTotal = (int) ($report['total_tokens'] ?? 0);
      $tokenKnown = (string) ($report['state'] ?? '') === 'recorded';
      $tokenValue = $tokenKnown ? (string) $tokenTotal : (string) $report['token_label'];
      if (!$tokenKnown && str_starts_with($tokenValue, 'Token ')) {
        $tokenValue = substr($tokenValue, strlen('Token '));
      }
      $rows[] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'bundle' => $this->bundleLabel((string) $row['bundle']),
        'entity_id' => (int) $row['entity_id'],
        'score' => $row['seo_score'] === NULL ? '—' : (string) $row['seo_score'],
        'issues' => count($result['issues'] ?? []),
        'suggestions' => count(SeoResult::itemRows($result)),
        'model' => trim((string) $row['provider_id'] . ' / ' . (string) $row['model_name'], ' /') ?: '未记录',
        'tokens' => $tokenValue,
        'tokens_known' => $tokenKnown,
        'cost' => (string) $report['cost_label'],
        'time' => $this->dates->format((int) ($row['completed'] ?: $row['created']), 'short'),
        'ops' => Labels::seoOps($ops),
        'ops_key' => $ops,
        'url' => Url::fromRoute('xedc_ai.seo_detail', ['review' => $row['id']])->toString(),
      ];
    }
    $audit = $this->seo->storedAudit();
    $audit['seo_url'] = $this->routeExists('audit.reports') ? Url::fromRoute('audit.reports')->toString() : '';
    $audit['site_url'] = $this->routeExists('site_audit.report') ? Url::fromRoute('site_audit.report')->toString() : '';
    return [
      '#theme' => 'xedc_ai_seo',
      '#audit' => $audit,
      '#rows' => $rows,
      '#summary' => [
        'analyzed' => count($rows),
        'pending' => $pending,
        'adopted' => $adopted,
        'needs_work' => $needsWork,
        'average' => $scoreCount > 0 ? (string) (int) round($scoreTotal / $scoreCount) : '—',
      ],
      '#filters' => [
        'bundle' => (string) $request->query->get('bundle', ''),
        'score' => $score,
        'issues' => (string) $request->query->get('issues', ''),
        'ops' => (string) $request->query->get('ops', ''),
        'model' => (string) $request->query->get('model', ''),
        'q' => (string) $request->query->get('q', ''),
        'from' => (string) $request->query->get('from', ''),
        'to' => (string) $request->query->get('to', ''),
      ],
      '#suggest' => $this->currentUser()->hasPermission('execute xedc ai reviews') ? $this->formBuilder()->getForm(SeoSuggestForm::class) : NULL,
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * One SEO analysis.
   */
  public function detail(int $review): array {
    $row = $this->reviews->load($review);
    if ($row === NULL || (string) $row['source'] !== 'seo_optimization') {
      throw new NotFoundHttpException();
    }
    $node = $this->extractor->loadRevision('node', (int) $row['entity_id'], (int) $row['revision_id'], TRUE);
    $current = $node ? $this->extractor->extract($node) : [];
    $result = json_decode((string) $row['result'], TRUE);
    $result = SeoResult::hydrate(is_array($result) ? $result : []);
    $row['result'] = json_encode($result, JSON_UNESCAPED_UNICODE) ?: (string) $row['result'];
    $default = $this->extractor->loadRevision('node', (int) $row['entity_id'], 0);
    $stale = $default !== NULL && (int) $default->getRevisionId() !== (int) $row['revision_id'] && (int) $default->getRevisionId() !== (int) ($result['applied_revision_id'] ?? 0);
    $report = UsageReport::summarize($this->usage->callsForReview((int) $row['id']), (string) $row['status'], (string) $row['error_code']);
    $live = $default ?? $node;
    $loaded = $live instanceof \Drupal\node\NodeInterface;
    $facts = $loaded ? $this->workflowFacts($live) : ['workflow' => '未能读取', 'state' => '未能读取', 'key' => 'none', 'workflow_entity' => NULL];
    $analyzedState = $node instanceof \Drupal\node\NodeInterface && $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
    $published = $loaded && $live->isPublished();
    $currentRevision = $default ? (int) $default->getRevisionId() : 0;
    $versionMatch = $currentRevision > 0 && $currentRevision === (int) $row['revision_id'];
    $ops = SeoResult::opsStatus((string) $row['status'], (string) $row['handled_status'], $result, $stale);
    $history = [];
    foreach ($this->reviews->history('node', (int) $row['entity_id'], 8) as $older) {
      if ((int) $older['id'] === (int) $row['id'] || (string) $older['source'] !== 'seo_optimization') {
        continue;
      }
      $history[] = [
        'id' => (int) $older['id'],
        'score' => $older['seo_score'] === NULL ? '—' : (string) $older['seo_score'],
        'time' => $this->dates->format((int) $older['created'], 'short'),
        'url' => Url::fromRoute('xedc_ai.seo_detail', ['review' => $older['id']])->toString(),
      ];
    }
    $logs = [];
    foreach ($this->audit->forTarget('node', (string) $row['entity_id'], 12) as $log) {
      $logs[] = [
        'time' => $this->dates->format((int) $log['created'], 'short'),
        'action' => Labels::auditAction((string) $log['action']),
        'message' => (string) $log['message'],
        'before' => (string) ($log['before_text'] ?? ''),
        'after' => (string) ($log['after_text'] ?? ''),
      ];
    }
    $phase = in_array((string) $row['status'], ['pending', 'processing'], TRUE) ? 'pending' : ((string) $row['status'] === 'failed' ? 'failed' : 'ready');
    return [
      '#theme' => 'xedc_ai_seo_detail',
      '#review' => [
        'id' => (int) $row['id'],
        'title' => (string) ($current['title'] ?? $row['title']),
        'bundle' => $this->bundleLabel((string) $row['bundle']),
        'entity_id' => (int) $row['entity_id'],
        'revision_id' => (int) $row['revision_id'],
        'current_revision' => $currentRevision > 0 ? (string) $currentRevision : '未能读取',
        'version_match' => $versionMatch,
        'version_message' => $currentRevision < 1 ? '当前内容不存在，无法核对版本。' : ($versionMatch ? '' : '内容已有新修订，本次分析基于历史版本。'),
        'publish_label' => $loaded ? ($published ? '已发布' : '未发布') : '未能读取',
        'publish_key' => $published ? 'published' : 'unpublished',
        'publish_time' => $this->publishTime($loaded ? $live : NULL),
        'workflow_label' => $facts['workflow'],
        'moderation_label' => $facts['state'],
        'moderation_key' => $facts['key'],
        'analyzed_moderation' => !$versionMatch && $analyzedState !== '' ? $this->stateLabel($facts['workflow_entity'], $analyzedState) : '',
        'ops' => Labels::seoOps($ops),
        'ops_key' => $ops,
        'phase' => $phase,
        'score' => $row['seo_score'] === NULL ? '—' : (string) $row['seo_score'],
        'summary' => (string) $row['summary'],
        'issues' => array_map(static function (array $item): array {
          $item['severity_label'] = Labels::level((string) ($item['severity'] ?? ''));
          $item['field_label'] = Labels::field((string) ($item['field'] ?? ''));
          return $item;
        }, is_array($result['issue_items'] ?? NULL) ? $result['issue_items'] : []),
        'items' => array_map(function (array $item): array {
          $item['acted_time'] = !empty($item['acted_at']) ? $this->dates->format((int) $item['acted_at'], 'short') : '';
          $item['priority_label'] = Labels::level((string) ($item['priority'] ?? ''));
          return $item;
        }, SeoResult::itemRows($result)),
        'counts' => self::itemCounts(SeoResult::itemRows($result)),
        'keywords' => is_array($result['keywords'] ?? NULL) ? $result['keywords'] : [],
        'links' => is_array($result['internal_links'] ?? NULL) ? $result['internal_links'] : [],
        'quality' => (string) ($result['content_quality'] ?? ''),
        'meta_title' => (string) ($current['meta_title'] ?? ''),
        'meta_description' => (string) ($current['meta_description'] ?? ''),
        'meta_title_source' => (string) ($current['meta_title_source'] ?? ''),
        'meta_description_source' => (string) ($current['meta_description_source'] ?? ''),
        'suggested_title' => (string) ($result['suggested_title'] ?? $result['seo_title'] ?? ''),
        'suggested_description' => (string) ($result['suggested_description'] ?? $result['meta_description'] ?? ''),
        'model' => trim((string) $row['provider_id'] . ' / ' . (string) $row['model_name'], ' /'),
        'finish_reason' => Labels::finishReason((string) ($result['analysis_metadata']['finish_reason'] ?? '')),
        'token_label' => (string) $report['token_label'],
        'cost_label' => (string) $report['cost_label'],
        'calls' => array_map(static function (array $call): array {
          $call['status_label'] = Labels::callStatus((string) ($call['status'] ?? ''));
          $call['finish_label'] = Labels::finishReason((string) ($call['finish_reason'] ?? ''));
          return $call;
        }, is_array($report['calls'] ?? NULL) ? $report['calls'] : []),
        'error' => (string) $row['error_message'],
        'error_label' => Labels::errorCode((string) $row['error_code']),
        'stale' => $stale,
        'history' => $history,
        'logs' => $logs,
        'node_url' => $node ? Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString() : '',
        'time' => $this->dates->format((int) ($row['completed'] ?: $row['created']), 'short'),
        'langcode' => Labels::language((string) ($row['langcode'] ?? '')),
        'evidence' => is_array($result['source_snapshot'] ?? NULL) ? $result['source_snapshot'] : NULL,
        'alt' => $this->altReport($result, $current),
      ],
      '#apply' => $phase === 'ready' ? $this->formBuilder()->getForm(SeoApplyForm::class, $row) : NULL,
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Content ID preview. Does not call a model.
   */
  public function lookup(Request $request): JsonResponse {
    $bundle = (string) $request->query->get('bundle', '');
    $id = (int) $request->query->get('id', 0);
    $text = trim((string) $request->query->get('q', ''));
    $payload = ['ok' => TRUE, 'message' => '', 'content' => NULL, 'matches' => []];
    if ($text !== '') {
      $payload['matches'] = $this->lookup->search($text, $bundle, $this->currentUser());
      if ($payload['matches'] === [] && $id < 1) {
        $payload['ok'] = FALSE;
        $payload['message'] = '没有找到匹配的内容。';
      }
    }
    if ($id > 0) {
      $found = $this->lookup->preview($id, $this->currentUser());
      $payload['ok'] = $found['ok'];
      $payload['message'] = (string) $found['message'];
      $payload['content'] = $found['content'];
    }
    $response = new JsonResponse($payload);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  private function routeExists(string $name): bool {
    return $this->routeProvider()->getRoutesByNames([$name]) !== [];
  }

  private function routeProvider(): \Drupal\Core\Routing\RouteProviderInterface {
    return \Drupal::service('router.route_provider');
  }

  /**
   * Lightweight task status. One review row, no model call.
   */
  public function status(int $review): \Symfony\Component\HttpFoundation\JsonResponse {
    $row = $this->reviews->load($review);
    if ($row === NULL || (string) $row['status'] === '' || (string) $row['source'] !== 'seo_optimization') {
      throw new NotFoundHttpException();
    }
    $node = $this->extractor->loadRevision('node', (int) $row['entity_id'], 0);
    if ($node === NULL || !$node->access('view')) {
      throw new NotFoundHttpException();
    }
    $stored = (string) $row['status'];
    $phase = match ($stored) {
      'pending' => 'waiting',
      'processing' => trim((string) $row['local_checks']) !== '' && trim((string) $row['local_checks']) !== '{}' ? 'suggesting' : 'analyzing',
      'completed' => 'done',
      'cancelled' => 'cancelled',
      default => 'failed',
    };
    $label = match ($phase) {
      'waiting' => '等待处理',
      'analyzing' => '正在分析',
      'suggesting' => '正在生成建议',
      'done' => '已完成',
      'cancelled' => '已取消',
      default => '分析失败',
    };
    $result = json_decode((string) $row['result'], TRUE);
    $issues = [];
    $suggestions = [];
    if ($phase === 'done' && is_array($result)) {
      foreach (is_array($result['issue_items'] ?? NULL) ? $result['issue_items'] : [] as $issue) {
        if (is_array($issue) && trim((string) ($issue['message'] ?? '')) !== '') {
          $issues[] = (string) $issue['message'];
        }
      }
      foreach (SeoResult::itemRows($result) as $item) {
        $suggestions[] = [
          'label' => (string) ($item['label'] ?? ''),
          'state' => (string) ($item['state_label'] ?? ''),
        ];
      }
    }
    return new \Symfony\Component\HttpFoundation\JsonResponse([
      'id' => (int) $row['id'],
      'phase' => $phase,
      'label' => $label,
      'score' => $row['seo_score'] === NULL ? NULL : (int) $row['seo_score'],
      'revision_id' => (int) $row['revision_id'],
      'entity_id' => (int) $row['entity_id'],
      'error' => trim((string) $row['error_message']) === '' ? '' : trim(Labels::errorCode((string) $row['error_code']) . ' ' . (string) $row['error_message']),
      'issues' => array_slice($issues, 0, 8),
      'suggestions' => array_slice($suggestions, 0, 8),
      'url' => Url::fromRoute('xedc_ai.seo_detail', ['review' => $row['id']])->toString(),
    ]);
  }

  /**
   * @return array{workflow: string, state: string, key: string, workflow_entity: ?object}
   *   Current workflow display for one node.
   */
  private function workflowFacts(?\Drupal\node\NodeInterface $node): array {
    $off = ['workflow' => '未启用', 'state' => '无', 'key' => 'none', 'workflow_entity' => NULL];
    $failed = ['workflow' => '未能读取', 'state' => '未能读取', 'key' => 'none', 'workflow_entity' => NULL];
    if (!$node instanceof \Drupal\node\NodeInterface) {
      return $failed;
    }
    if (!$node->hasField('moderation_state')) {
      return $off;
    }
    try {
      $info = \Drupal::service('content_moderation.moderation_information');
      if (!$info->isModeratedEntity($node)) {
        return $off;
      }
      $workflow = $info->getWorkflowForEntity($node);
    }
    catch (\Throwable) {
      return $failed;
    }
    if ($workflow === NULL) {
      return $failed;
    }
    if (method_exists($workflow, 'status') && !$workflow->status()) {
      return $off;
    }
    $stateId = (string) $node->get('moderation_state')->value;
    $label = $this->stateLabel($workflow, $stateId);
    if ($stateId === '' || $label === '' || $label === $stateId || $label === '未启用审核') {
      $label = '未能读取';
    }
    $workflowLabel = trim((string) $workflow->label());
    if ($workflowLabel === '' || $workflowLabel === (string) $workflow->id()) {
      $workflowLabel = '未能读取';
    }
    return [
      'workflow' => $workflowLabel,
      'state' => $label,
      'key' => Labels::moderationKey($stateId),
      'workflow_entity' => $workflow,
    ];
  }

  private function stateLabel(?object $workflow, string $stateId): string {
    $label = $stateId;
    if ($workflow !== NULL && $stateId !== '' && method_exists($workflow, 'getTypePlugin')) {
      try {
        $label = (string) $workflow->getTypePlugin()->getState($stateId)->label();
      }
      catch (\Throwable) {
        $label = $stateId;
      }
    }
    return Labels::moderation($stateId, $label);
  }

  /**
   * @param list<array<string, mixed>> $items
   *   Suggestion rows.
   *
   * @return array<string, int>
   *   Counts by suggestion state.
   */
  private static function itemCounts(array $items): array {
    $counts = ['pending' => 0, 'adopted' => 0, 'rejected' => 0, 'failed' => 0, 'conflict' => 0];
    foreach ($items as $item) {
      $state = (string) ($item['state'] ?? 'pending');
      if ($state === '') {
        $state = 'pending';
      }
      if (isset($counts[$state])) {
        $counts[$state]++;
      }
    }
    return $counts;
  }

  private function publishTime(?\Drupal\node\NodeInterface $node): string {
    if (!$node instanceof \Drupal\node\NodeInterface || !$node->hasField('field_published_at') || $node->get('field_published_at')->isEmpty()) {
      return '暂无';
    }
    try {
      $date = new \Drupal\Core\Datetime\DrupalDateTime((string) $node->get('field_published_at')->value, 'UTC');
      $stamp = $date->getTimestamp();
      if ($stamp < 1) {
        return '暂无';
      }
      return $this->dates->format($stamp, 'custom', 'Y年n月j日 H:i');
    }
    catch (\Throwable) {
      return '暂无';
    }
  }

  private function bundleLabel(string $bundle): string {
    if ($bundle === '') {
      return Labels::bundle('');
    }
    try {
      $type = $this->entityTypeManager()->getStorage('node_type')->load($bundle);
      if ($type !== NULL) {
        $label = trim((string) $type->label());
        if ($label !== '') {
          return $label;
        }
      }
    }
    catch (\Throwable) {
      // Fall back to the static map.
    }
    return Labels::bundle($bundle);
  }

  /**
   * @param array<string, mixed> $result
   *   Stored analysis.
   * @param array<string, mixed> $current
   *   Text extracted from the analyzed revision.
   *
   * @return array<string, mixed>
   *   Local ALT report. tokens is always 0.
   */
  private function altReport(array $result, array $current): array {
    if (is_array($result['alt_report'] ?? NULL)) {
      $report = $result['alt_report'];
      $report['stored'] = TRUE;
      $report['tokens'] = 0;
      return $this->labelAltFields($report);
    }
    $report = \Drupal\xedc_ai\Seo\AltInspector::inspect(
      is_array($current['alts'] ?? NULL) ? $current['alts'] : [],
      (string) ($current['title'] ?? ''),
      (string) ($current['bundle'] ?? ''),
    );
    $report['stored'] = FALSE;
    return $this->labelAltFields($report);
  }

  /**
   * @param array<string, mixed> $report
   *   ALT report.
   *
   * @return array<string, mixed>
   *   Report with Chinese field labels.
   */
  private function labelAltFields(array $report): array {
    if (!is_array($report['rows'] ?? NULL)) {
      return $report;
    }
    foreach ($report['rows'] as $index => $row) {
      if (is_array($row)) {
        $report['rows'][$index]['field_label'] = Labels::field((string) ($row['field'] ?? ''));
      }
    }
    return $report;
  }

  private function stamp(string $value, bool $end): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return 0;
    }
    try {
      $zone = new \DateTimeZone(date_default_timezone_get());
      return (new \DateTimeImmutable($value . ($end ? ' 23:59:59' : ' 00:00:00'), $zone))->getTimestamp();
    }
    catch (\Throwable) {
      return 0;
    }
  }

}
