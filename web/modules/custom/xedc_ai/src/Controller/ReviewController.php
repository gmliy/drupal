<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\xedc_ai\Budget\Money;
use Drupal\xedc_ai\Form\EnqueueForm;
use Drupal\xedc_ai\Form\QueueControlForm;
use Drupal\xedc_ai\Form\ReviewActionForm;
use Drupal\xedc_ai\Form\ReviewBatchForm;
use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Review\Fingerprint;
use Drupal\xedc_ai\Review\ReviewRepository;
use Drupal\xedc_ai\Review\TextExtractor;
use Drupal\xedc_ai\Usage\UsageLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Review list and detail. Opening these pages does not call a model.
 */
final class ReviewController extends ControllerBase {

  public function __construct(
    private readonly ReviewRepository $reviews,
    private readonly TextExtractor $extractor,
    private readonly DateFormatterInterface $dates,
    private readonly UsageLogger $usage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.reviews'),
      $container->get('xedc_ai.extractor'),
      $container->get('date.formatter'),
      $container->get('xedc_ai.usage'),
    );
  }

  /**
   * Review list with filters and pager.
   */
  public function collection(): array {
    $request = \Drupal::request();
    $filters = [
      'bundle' => (string) $request->query->get('bundle', ''),
      'status' => (string) $request->query->get('status', ''),
      'risk_level' => (string) $request->query->get('risk_level', ''),
      'source' => $request->query->has('source') ? (string) $request->query->get('source') : 'content_review',
      'submission' => (string) $request->query->get('submission', ''),
      'q' => (string) $request->query->get('q', ''),
      'from' => $this->stamp((string) $request->query->get('from', ''), FALSE),
      'to' => $this->stamp((string) $request->query->get('to', ''), TRUE),
    ];
    $rows = [];
    foreach ($this->reviews->search($filters) as $row) {
      $rows[] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'bundle' => Labels::bundle((string) $row['bundle']),
        'status' => Labels::status((string) $row['status']),
        'status_key' => (string) $row['status'],
        'verdict' => Labels::verdict((string) $row['status'], (string) $row['risk_level'], $this->resultStatus($row), (string) $row['handled_status']),
        'risk' => Labels::risk((string) $row['risk_level']),
        'risk_key' => (string) $row['risk_level'],
        'score' => $row['score'] === NULL ? '—' : (string) $row['score'],
        'submission' => (int) $row['is_submission'] === 1,
        'time' => $this->dates->format((int) $row['created'], 'short'),
        'url' => Url::fromRoute('xedc_ai.review', ['review' => $row['id']])->toString(),
        'raw' => $row,
      ];
    }
    $batchRows = array_map(static fn (array $row): array => $row['raw'], $rows);
    return [
      '#theme' => 'xedc_ai_reviews',
      '#rows' => $rows,
      '#counts' => $this->reviews->counts(),
      '#filters' => [
        'bundle' => (string) $request->query->get('bundle', ''),
        'status' => (string) $request->query->get('status', ''),
        'risk_level' => (string) $request->query->get('risk_level', ''),
        'source' => $request->query->has('source') ? (string) $request->query->get('source') : 'content_review',
        'submission' => (string) $request->query->get('submission', ''),
        'q' => (string) $request->query->get('q', ''),
        'from' => (string) $request->query->get('from', ''),
        'to' => (string) $request->query->get('to', ''),
      ],
      '#queue' => $this->currentUser()->hasPermission('execute xedc ai reviews') ? $this->formBuilder()->getForm(QueueControlForm::class) : NULL,
      '#enqueue' => $this->currentUser()->hasPermission('execute xedc ai reviews') ? $this->formBuilder()->getForm(EnqueueForm::class) : NULL,
      '#batch' => $batchRows !== [] ? $this->formBuilder()->getForm(ReviewBatchForm::class, $batchRows) : NULL,
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * One review and its human actions.
   */
  public function detail($review): array {
    $review = (int) $review;
    $row = $this->reviews->load($review);
    if ($row === NULL) {
      throw new NotFoundHttpException();
    }
    $node = $this->extractor->loadRevision((string) $row['entity_type'], (int) $row['entity_id'], (int) $row['revision_id']);
    $current = $node ? $this->extractor->extract($node) : [];
    $stale = $current !== [] && Fingerprint::fieldHash($current) !== (string) $row['field_hash'];
    $handler = '';
    if ((int) $row['handled_uid'] > 0) {
      $account = $this->entityTypeManager()->getStorage('user')->load((int) $row['handled_uid']);
      $handler = $account instanceof UserInterface ? (string) $account->getDisplayName() : (string) $row['handled_uid'];
    }
    $stateLabel = (string) $row['moderation_state'];
    if ($row['moderation_workflow'] !== '') {
      $workflow = $this->entityTypeManager()->getStorage('workflow')->load((string) $row['moderation_workflow']);
      if ($workflow !== NULL && $stateLabel !== '') {
        try {
          $stateLabel = (string) $workflow->getTypePlugin()->getState($stateLabel)->label();
        }
        catch (\Throwable) {
          $stateLabel = (string) $row['moderation_state'];
        }
      }
    }
    $result = json_decode((string) $row['result'], TRUE);
    $localLines = $this->localLines((string) $row['local_checks']);
    $duplicateLines = [];
    $otherLines = [];
    foreach ($localLines as $line) {
      if (str_contains($line, 'SHA-256') || str_contains($line, '感知哈希')) {
        $duplicateLines[] = $line;
      }
      else {
        $otherLines[] = $line;
      }
    }
    $localLines = $otherLines;
    $usage = $this->usage->totalsForReview((int) $row['id']);
    $history = [];
    foreach ($this->reviews->history((string) $row['entity_type'], (int) $row['entity_id'], 8) as $older) {
      if ((int) $older['id'] === (int) $row['id']) {
        continue;
      }
      $history[] = [
        'id' => (int) $older['id'],
        'status' => Labels::verdict((string) $older['status'], (string) $older['risk_level'], $this->resultStatus($older), (string) $older['handled_status']),
        'time' => $this->dates->format((int) $older['created'], 'short'),
        'url' => Url::fromRoute('xedc_ai.review', ['review' => $older['id']])->toString(),
      ];
    }
    $view = [
      'id' => (int) $row['id'],
      'title' => (string) $row['title'],
      'bundle' => Labels::bundle((string) $row['bundle']),
      'entity_id' => (int) $row['entity_id'],
      'revision_id' => (int) $row['revision_id'],
      'current_revision' => $node ? (int) $node->getRevisionId() : 0,
      'time' => $row['completed'] ? $this->dates->format((int) $row['completed'], 'short') : $this->dates->format((int) $row['created'], 'short'),
      'model' => trim((string) $row['provider_id'] . ' / ' . (string) $row['model_name'], ' /'),
      'score' => $row['score'] === NULL ? '—' : (string) $row['score'],
      'seo_score' => $row['seo_score'] === NULL ? '—' : (string) $row['seo_score'],
      'risk' => Labels::risk((string) $row['risk_level']),
      'risk_key' => (string) $row['risk_level'],
      'status' => Labels::status((string) $row['status']),
      'status_key' => (string) $row['status'],
      'verdict' => Labels::verdict((string) $row['status'], (string) $row['risk_level'], is_array($result) ? (string) ($result['status'] ?? '') : '', (string) $row['handled_status']),
      'issues' => $this->issueItems(is_array($result) ? ($result['issue_items'] ?? []) : [], (string) $row['issues']),
      'suggestions' => $this->lines((string) $row['suggestions']),
      'comparisons' => $this->comparisons(is_array($result) ? $result : [], $current),
      'history' => $history,
      'tokens' => $usage['tokens'],
      'cost' => $usage['known'] ? Money::yuan($usage['cost_micro']) : '未核算',
      'summary' => (string) $row['summary'],
      'handler' => $handler !== '' ? $handler : '—',
      'handled' => Labels::handled((string) $row['handled_status']),
      'error' => (string) $row['error_message'],
      'error_code' => (string) $row['error_code'],
      'cached' => (string) $row['error_code'] === 'cache',
      'stale' => $stale,
      'moderation' => $stateLabel !== '' ? $stateLabel : '未启用工作流',
      'workflow' => (string) $row['moderation_workflow'],
      'local' => $localLines,
      'duplicates' => $duplicateLines,
      'seo_title' => is_array($result) ? (string) ($result['seo_title'] ?? '') : '',
      'meta_description' => is_array($result) ? (string) ($result['meta_description'] ?? '') : '',
      'tags' => is_array($result) && isset($result['tags']) && is_array($result['tags']) ? $result['tags'] : [],
      'node_url' => $node ? Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString() : '',
      'edit_url' => $node ? Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])->toString() : '',
      'moderation_url' => Url::fromRoute('view.moderated_content.moderated_content')->toString(),
    ];
    return [
      '#theme' => 'xedc_ai_review',
      '#review' => $view,
      '#node' => ['title' => $node ? (string) $node->label() : (string) $row['title']],
      '#action' => $this->formBuilder()->getForm(ReviewActionForm::class, $row),
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * @return list<string>
   *   Decoded lines.
   */
  private function lines(string $json): array {
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    $lines = [];
    foreach ($decoded as $item) {
      if (is_string($item) && $item !== '') {
        $lines[] = $item;
      }
    }
    return $lines;
  }

  /**
   * @return list<string>
   *   Local check messages.
   */
  private function localLines(string $json): array {
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    $lines = [];
    foreach ((array) ($decoded['text'] ?? []) as $issue) {
      if (is_array($issue) && !empty($issue['message'])) {
        $lines[] = (string) $issue['message'];
      }
    }
    foreach ((array) ($decoded['files'] ?? []) as $file) {
      if (!is_array($file)) {
        continue;
      }
      foreach ((array) ($file['messages'] ?? []) as $message) {
        $lines[] = (string) $message;
      }
    }
    return $lines;
  }

  /**
   * @param array<string, mixed> $row
   *   Review row.
   */
  private function resultStatus(array $row): string {
    $result = json_decode((string) ($row['result'] ?? ''), TRUE);
    return is_array($result) ? (string) ($result['status'] ?? '') : '';
  }

  /**
   * @param mixed $items
   *   Structured issues from the stored result.
   *
   * @return list<array{type: string, severity: string, field: string, message: string, suggestion: string}>
   *   Issues safe to print.
   */
  private function issueItems(mixed $items, string $fallback): array {
    $rows = [];
    if (is_array($items)) {
      foreach ($items as $item) {
        if (!is_array($item) || trim((string) ($item['message'] ?? '')) === '') {
          continue;
        }
        $rows[] = [
          'type' => (string) ($item['type'] ?? ''),
          'severity' => (string) ($item['severity'] ?? ''),
          'field' => (string) ($item['field'] ?? ''),
          'message' => (string) $item['message'],
          'suggestion' => (string) ($item['suggestion'] ?? ''),
        ];
      }
    }
    if ($rows === []) {
      foreach ($this->lines($fallback) as $line) {
        $rows[] = [
          'type' => '',
          'severity' => '',
          'field' => '',
          'message' => $line,
          'suggestion' => '',
        ];
      }
    }
    return $rows;
  }

  /**
   * @param array<string, mixed> $result
   *   Stored model result.
   * @param array<string, mixed> $current
   *   Current extracted text.
   *
   * @return list<array{field: string, original: string, suggested: string, reason: string}>
   *   Before and after text. Nothing here is written automatically.
   */
  private function comparisons(array $result, array $current): array {
    $rows = [];
    $items = $result['suggestion_items'] ?? [];
    if (is_array($items)) {
      foreach ($items as $item) {
        if (!is_array($item) || trim((string) ($item['suggested'] ?? '')) === '') {
          continue;
        }
        $rows[] = [
          'field' => (string) ($item['field'] ?? ''),
          'original' => (string) ($item['original'] ?? ''),
          'suggested' => (string) $item['suggested'],
          'reason' => (string) ($item['reason'] ?? ''),
        ];
      }
    }
    if (!empty($result['seo_title'])) {
      $rows[] = [
        'field' => 'title',
        'original' => (string) ($current['title'] ?? ''),
        'suggested' => (string) $result['seo_title'],
        'reason' => 'SEO 标题建议',
      ];
    }
    if (!empty($result['meta_description'])) {
      $rows[] = [
        'field' => 'field_summary',
        'original' => (string) ($current['summary'] ?? ''),
        'suggested' => (string) $result['meta_description'],
        'reason' => '简介建议。站点没有单独的 Meta Description 字段。',
      ];
    }
    return $rows;
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
