<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\xedc_ai\Budget\BudgetService;
use Drupal\xedc_ai\Budget\Money;
use Drupal\xedc_ai\Client\ChatResponse;
use Drupal\xedc_ai\Client\OpenAiChatClient;
use Drupal\xedc_ai\Client\Redactor;
use Drupal\xedc_ai\Seo\SeoResult;
use Drupal\xedc_ai\Provider\ModelProfile;
use Drupal\xedc_ai\Provider\ModelRepository;
use Drupal\xedc_ai\Usage\AuditLog;
use Drupal\xedc_ai\Usage\UsageLogger;
use Psr\Log\LoggerInterface;

/**
 * Runs one queued text review. It never saves or publishes the node.
 */
final class ReviewRunner {

  public function __construct(
    private readonly ReviewRepository $reviews,
    private readonly ModelRepository $models,
    private readonly OpenAiChatClient $client,
    private readonly UsageLogger $usage,
    private readonly BudgetService $budget,
    private readonly TextExtractor $extractor,
    private readonly LocalInspector $local,
    private readonly AuditLog $audit,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly StateInterface $state,
    private readonly PromptBuilder $prompts = new PromptBuilder(),
  ) {}

  /**
   * Processes one review.
   *
   * @return string
   *   done, retry, or busy.
   */
  public function process(int $reviewId): string {
    if ($reviewId < 1 || $this->reviews->load($reviewId) === NULL) {
      return 'done';
    }
    $name = 'xedc_ai_review_' . $reviewId;
    if (!$this->lock->acquire($name, 180)) {
      return 'busy';
    }
    try {
      return $this->run($reviewId);
    }
    catch (\Throwable $exception) {
      $this->failUnexpected($reviewId, $exception);
      return 'done';
    }
    finally {
      $this->lock->release($name);
    }
  }

  public function failUnexpected(int $reviewId, \Throwable $exception): void {
    if ($reviewId < 1) {
      return;
    }
    try {
      $open = $this->usage->openCall($reviewId);
      if ($open !== NULL) {
        $this->usage->interrupt((int) $open['id'], '任务异常结束，已停止自动重试。');
      }
      $this->fail($reviewId, 'exception', $exception->getMessage());
    }
    catch (\Throwable $ignored) {
      $this->logger->error('AI review @id could not be marked failed.', ['@id' => $reviewId]);
    }
  }

  private function run(int $reviewId): string {
    $review = $this->reviews->load($reviewId);
    if ($review === NULL || in_array($review['status'], ['completed', 'failed', 'ignored', 'cancelled'], TRUE)) {
      return 'done';
    }
    if ($this->state->get('xedc_ai.paused', FALSE)) {
      return 'busy';
    }
    $settings = $this->configFactory->get('xedc_ai.settings');
    $maxCalls = 1 + max(0, (int) $settings->get('retry_limit'));
    if ((int) $review['attempts'] >= $maxCalls) {
      $this->fail($reviewId, 'retry_exhausted', '已达到自动重试次数。');
      return 'done';
    }
    $maxConcurrent = max(1, (int) $settings->get('max_concurrent'));
    if ($this->reviews->countProcessing($reviewId) >= $maxConcurrent) {
      return 'busy';
    }
    $open = $this->usage->openCall($reviewId);
    if ($open !== NULL) {
      $age = $this->time->getRequestTime() - (int) $open['created'];
      if ($age < 180) {
        return 'busy';
      }
      $this->usage->interrupt((int) $open['id'], '上一次调用中断，已停止自动重试以避免重复计费。');
      $this->fail($reviewId, 'interrupted', '上一次调用中断，已停止自动重试。请人工重新审核。');
      return 'done';
    }

    $pinned = (string) $review['depth'] === 'seo';
    $node = $this->extractor->loadRevision((string) $review['entity_type'], (int) $review['entity_id'], $pinned ? (int) $review['revision_id'] : 0, $pinned);
    if ($node === NULL) {
      $this->fail($reviewId, $pinned ? 'missing_revision' : 'missing_entity', $pinned ? '指定的修订版本不存在，未改用其他版本。' : '内容不存在。');
      return 'done';
    }
    $fields = $this->extractor->extract($node);
    $local = $this->local->inspect($node);
    $this->reviews->update($reviewId, [
      'local_checks' => json_encode($local, JSON_UNESCAPED_UNICODE) ?: '{}',
    ]);
    $hash = Fingerprint::fieldHash($fields);
    $force = (int) $review['force_call'] === 1;
    if ($hash !== (string) $review['field_hash']) {
      if (!$force) {
        $this->fail($reviewId, 'stale', '内容在入队后已变化，未调用模型。');
        return 'done';
      }
      $profile = $this->models->profile((int) $review['model_id']);
      $modelKey = $profile ? $profile->modelKey() : (string) $review['provider_id'] . ':' . (string) $review['model_name'];
      $fingerprint = Fingerprint::make('node', (int) $node->id(), (int) $node->getRevisionId(), (string) $fields['langcode'], $hash, $modelKey, (int) $review['rule_version'], (string) $review['source'], (string) $review['depth']);
      $this->reviews->update($reviewId, [
        'field_hash' => $hash,
        'fingerprint' => $fingerprint,
        'title' => (string) $fields['title'],
        'revision_id' => (int) $node->getRevisionId(),
      ]);
      $review['field_hash'] = $hash;
      $review['fingerprint'] = $fingerprint;
      $review['revision_id'] = (int) $node->getRevisionId();
    }
    elseif ((int) $node->getRevisionId() !== (int) $review['revision_id']) {
      $this->reviews->update($reviewId, ['revision_id' => (int) $node->getRevisionId()]);
      $review['revision_id'] = (int) $node->getRevisionId();
    }
    if (!$force) {
      $cached = $this->reviews->findReusableText($review, $reviewId);
      if ($cached !== NULL) {
        $this->copy($reviewId, $cached);
        $this->audit->log('review_cache', '复用审核结果 #' . $cached['id'] . ' 到 #' . $reviewId . '。', (int) $review['uid']);
        return 'done';
      }
    }

    $model = $this->models->profile((int) $review['model_id']);
    if ($model === NULL || !$model->enabled) {
      $this->fail($reviewId, 'no_model', '没有可用的文本模型。');
      return 'done';
    }
    if ($model->apiKey === '') {
      $this->fail($reviewId, 'no_key', '供应商没有可用的 API Key，未调用模型。');
      return 'done';
    }
    $override = (int) $review['allow_over_budget'] === 1;
    $decision = $this->reserve($model, !$force, $override, $reviewId);
    if ($decision === NULL) {
      return 'busy';
    }
    if ($decision['allowed'] !== TRUE) {
      $this->fail($reviewId, 'budget', (string) $decision['reason']);
      return 'done';
    }
    if ($override) {
      $without = $this->budget->authorize($model, !$force, FALSE);
      if (!$without['allowed']) {
        $this->audit->log('budget_override', '审核 #' . $reviewId . ' 使用了手动超预算确认。', (int) $review['uid']);
      }
    }

    $this->reviews->update($reviewId, ['status' => 'processing']);
    if ((string) ($review['task_note'] ?? '') !== '') {
      $fields['target_lang'] = (string) $review['task_note'];
    }
    $messages = $this->prompts->messages($fields, $local, (string) $review['depth'], (int) $review['is_submission'] === 1);
    $response = $this->call($review, $model, $messages, (int) $decision['hold_id'], (bool) $decision['price_known']);
    if (!$response->ok && $response->fallbackEligible) {
      $fallback = $this->models->fallbackProfile($model->id);
      if ($fallback !== NULL && $fallback->apiKey !== '') {
        $second = $this->reserve($fallback, !$force, $override, $reviewId);
        if ($second !== NULL && $second['allowed'] === TRUE) {
          $response = $this->call($review, $fallback, $messages, (int) $second['hold_id'], (bool) $second['price_known']);
          $fingerprint = Fingerprint::make('node', (int) $review['entity_id'], (int) $review['revision_id'], (string) $review['langcode'], (string) $review['field_hash'], $fallback->modelKey(), (int) $review['rule_version'], (string) $review['source'], (string) $review['depth']);
          $this->reviews->update($reviewId, [
            'model_id' => $fallback->id,
            'provider_id' => $fallback->providerId,
            'model_name' => $fallback->modelName,
            'fingerprint' => $fingerprint,
          ]);
        }
      }
    }
    if (!$response->ok) {
      return $this->finishError($review, $response, $maxCalls);
    }
    if ((string) $review['depth'] === 'seo') {
      $rejected = SeoResult::rejectedFinish($response->finishReason);
      if ($rejected !== NULL) {
        $this->fail($reviewId, $response->finishReason === 'length' ? 'truncated' : 'filtered', $rejected . ' 内容没有被修改。');
        return 'done';
      }
    }
    $parsed = match (TRUE) {
      (string) $review['source'] === 'translation' || (string) $review['depth'] === 'translation' => ReviewParser::translation($response->content),
      (string) $review['depth'] === 'seo' => ReviewParser::seo($response->content, $fields + ['known' => TRUE]),
      default => ReviewParser::review($response->content),
    };
    if (!$parsed['ok']) {
      $snippet = trim(preg_replace('/\s+/u', ' ', $response->content) ?? '');
      $this->fail($reviewId, 'invalid_json', (string) $parsed['error'] . ' finish_reason=' . ($response->finishReason !== '' ? $response->finishReason : '无') . '。内容没有被修改。片段：' . $snippet);
      return 'done';
    }
    $result = $parsed['result'];
    if ((string) $review['depth'] === 'seo') {
      $result['base_revision_id'] = (int) $review['revision_id'];
      $result['source_snapshot'] = $this->extractor->snapshot($node);
      $alts = is_array($fields['alts'] ?? NULL) ? $fields['alts'] : [];
      $shared = $this->sharedMediaCounts($node, $alts);
      foreach ($alts as $index => $alt) {
        if (is_array($alt)) {
          $alts[$index]['shared'] = $shared[(int) ($alt['mid'] ?? 0)] ?? 0;
        }
      }
      $result['alt_report'] = \Drupal\xedc_ai\Seo\AltInspector::inspect($alts, (string) ($fields['title'] ?? ''), (string) ($fields['bundle'] ?? ''));
      $result = \Drupal\xedc_ai\Seo\SeoResult::mergeLocalAlt($result, $result['alt_report']);
      $result['analysis_metadata']['finish_reason'] = $response->finishReason;
      $result['analysis_metadata']['usage_reported'] = $response->usageReported;
      $result['analysis_metadata']['from_reasoning'] = $response->usedReasoning;
    }
    $this->complete($reviewId, $result);
    return 'done';
  }

  /**
   * @param array<string, mixed> $review
   *   Review row.
   *
   * @return array<string, mixed>|null
   *   Budget decision, or NULL when the budget lock is busy.
   */
  private function reserve(ModelProfile $model, bool $automatic, bool $override, int $reviewId): ?array {
    if (!$this->lock->acquire('xedc_ai_budget', 20)) {
      return NULL;
    }
    try {
      $decision = $this->budget->authorize($model, $automatic, $override);
      $decision['hold_id'] = 0;
      if ($decision['allowed'] === TRUE) {
        $decision['hold_id'] = $this->budget->hold($reviewId, (int) $decision['estimate_micro']);
      }
      return $decision;
    }
    finally {
      $this->lock->release('xedc_ai_budget');
    }
  }

  /**
   * @param array<string, mixed> $review
   *   Review row.
   * @param list<array{role: string, content: string}> $messages
   *   Text messages.
   */
  private function call(array $review, ModelProfile $model, array $messages, int $hold, bool $priceKnown): ChatResponse {
    $usageId = $this->usage->start((int) $review['id'], (string) $review['source'], $model->providerId, $model->modelName, (int) $review['uid'], [
      'entity_id' => (int) $review['entity_id'],
      'revision_id' => (int) $review['revision_id'],
      'retry_index' => (int) $review['attempts'],
    ]);
    try {
      $response = $this->client->once($model, $messages, NULL, NULL, (string) $review['depth'] === 'seo');
    }
    catch (\Throwable $exception) {
      $response = ChatResponse::failure($model->providerId, $model->modelName, 'network', $exception->getMessage(), 0, TRUE, TRUE);
    }
    $quote = $response->usageReported
      ? Money::quote($response->promptTokens, $response->completionTokens, $response->cacheReadTokens, $priceKnown ? $model->inputPrice : '', $priceKnown ? $model->outputPrice : '', $priceKnown ? $model->cachePrice : '')
      : ['status' => 'no_usage', 'total' => 0];
    $costStatus = (string) $quote['status'];
    $cost = in_array($costStatus, ['known', 'zero', 'cache_unpriced'], TRUE) ? (int) $quote['total'] : NULL;
    $this->usage->finish($usageId, $response, $cost, (int) $review['uid'], $costStatus);
    if ($cost !== NULL && ($response->promptTokens + $response->completionTokens) > 0) {
      $this->budget->settle($hold);
    }
    else {
      $this->budget->release($hold);
    }
    return $response;
  }

  /**
   * @param array<string, mixed> $review
   *   Review row.
   */
  private function finishError(array $review, ChatResponse $response, int $maxCalls): string {
    $next = (int) $review['attempts'] + 1;
    if ($response->retryable && $next < $maxCalls) {
      $this->reviews->update((int) $review['id'], [
        'status' => 'pending',
        'attempts' => $next,
        'error_code' => $response->errorCode,
        'error_message' => $response->errorMessage,
      ]);
      return 'retry';
    }
    $this->fail((int) $review['id'], $response->errorCode !== '' ? $response->errorCode : 'http', $response->errorMessage !== '' ? $response->errorMessage : '模型请求失败。');
    return 'done';
  }

  /**
   * @param array<string, mixed> $cached
   *   Completed review.
   */
  private function copy(int $reviewId, array $cached): void {
    $this->reviews->update($reviewId, [
      'status' => 'completed',
      'risk_level' => $cached['risk_level'],
      'score' => $cached['score'],
      'seo_score' => $cached['seo_score'],
      'issues' => $cached['issues'],
      'suggestions' => $cached['suggestions'],
      'summary' => $cached['summary'],
      'result' => $cached['result'],
      'error_code' => 'cache',
      'error_message' => '',
      'completed' => $this->time->getRequestTime(),
    ]);
  }

  /**
   * @param array<string, mixed> $result
   *   Validated model result.
   */
  private function complete(int $reviewId, array $result): void {
    $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
    $this->reviews->update($reviewId, [
      'status' => 'completed',
      'risk_level' => (string) $result['risk_level'],
      'score' => (int) $result['score'],
      'seo_score' => (int) $result['seo_score'],
      'issues' => json_encode($result['issues'], JSON_UNESCAPED_UNICODE) ?: '[]',
      'suggestions' => json_encode($result['suggestions'], JSON_UNESCAPED_UNICODE) ?: '[]',
      'summary' => (string) $result['summary'],
      'result' => $encoded === FALSE ? '' : $encoded,
      'error_code' => '',
      'error_message' => '',
      'completed' => $this->time->getRequestTime(),
    ]);
  }

  /**
   * @param list<array<string, mixed>> $alts
   *   Image rows on this node.
   *
   * @return array<int, int>
   *   Other nodes referencing each media id. Four grouped queries at most.
   */
  private function sharedMediaCounts(NodeInterface $node, array $alts): array {
    $mids = [];
    foreach ($alts as $alt) {
      $mid = (int) ($alt['mid'] ?? 0);
      if ($mid > 0) {
        $mids[$mid] = $mid;
      }
    }
    if ($mids === []) {
      return [];
    }
    $counts = array_fill_keys($mids, 0);
    $database = \Drupal::database();
    foreach (['field_images', 'field_cover_image', 'field_cover', 'field_video_cover'] as $field) {
      $table = 'node__' . $field;
      if (!$database->schema()->tableExists($table)) {
        continue;
      }
      $query = $database->select($table, 't');
      $query->addField('t', $field . '_target_id', 'mid');
      $query->addExpression('COUNT(DISTINCT t.entity_id)', 'uses');
      $query->condition($field . '_target_id', array_values($mids), 'IN');
      $query->condition('entity_id', (int) $node->id(), '<>');
      $query->groupBy($field . '_target_id');
      foreach ($query->execute() ?? [] as $row) {
        $counts[(int) $row->mid] = ($counts[(int) $row->mid] ?? 0) + (int) $row->uses;
      }
    }
    return $counts;
  }

  private function fail(int $reviewId, string $code, string $message): void {
    $this->budget->releaseReview($reviewId);
    $this->reviews->update($reviewId, [
      'status' => 'failed',
      'error_code' => substr($code, 0, 32),
      'error_message' => Redactor::text($message),
    ]);
  }

}
