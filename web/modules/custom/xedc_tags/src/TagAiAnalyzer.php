<?php

declare(strict_types=1);

namespace Drupal\xedc_tags;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\xedc_ai\Budget\Money;
use Drupal\xedc_ai\Client\ChatResponse;

/**
 * Optional AI suggestions. Reuses xedc_ai; never mutates taxonomy or content.
 *
 * API calls go through xedc_ai.client and are logged once in xedc_ai_usage.
 */
final class TagAiAnalyzer {

  private const MAX_RELATED = 5;
  private const MAX_DESC_LEN = 80;
  /** Soft per-process rate limit for tag AI calls. */
  private const RATE_LIMIT_SECONDS = 3;
  private const RATE_LIMIT_STATE = 'xedc_tags.ai_last_call';
  /** Usage source machine value in xedc_ai_usage.source (task implied). */
  private const USAGE_SOURCE = 'xedc_tags';
  /** Output token cap for low-cost tag analysis. Do not raise without review. */
  private const MAX_OUTPUT_TOKENS = 500;

  public function __construct(
    private TagCatalog $catalog,
    private TagAudit $audit,
    private ModuleHandlerInterface $moduleHandler,
    private LoggerChannelFactoryInterface $logFactory,
  ) {}

  public function available(): bool {
    if (!$this->moduleHandler->moduleExists('xedc_ai')) {
      return FALSE;
    }
    try {
      $models = \Drupal::service('xedc_ai.models');
      $profile = $models->forTask('general') ?: $models->defaultProfile();
      return $profile !== NULL && $profile->enabled && $profile->apiKey !== '';
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * @return array{ok: bool, message: string, status: string}
   */
  public function status(): array {
    if (!$this->moduleHandler->moduleExists('xedc_ai')) {
      return ['ok' => FALSE, 'message' => '未启用 xedc_ai。规则检测仍可用。', 'status' => 'missing'];
    }
    try {
      $models = \Drupal::service('xedc_ai.models');
      $profile = $models->forTask('general') ?: $models->defaultProfile();
      if ($profile === NULL || !$profile->enabled) {
        return ['ok' => FALSE, 'message' => '未配置可用模型。请到 AI 管理中心设置。', 'status' => 'unconfigured'];
      }
      if ($profile->apiKey === '') {
        return ['ok' => FALSE, 'message' => 'API Key 未配置（服务端私密存储）。', 'status' => 'no_key'];
      }
      return [
        'ok' => TRUE,
        'message' => '已连接模型 ' . $profile->providerLabel . ' / ' . $profile->modelName . '（密钥已脱敏）。',
        'status' => 'ready',
      ];
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'message' => 'AI 状态检查失败。', 'status' => 'error'];
    }
  }

  /**
   * Analyze one term. Returns validated suggestions only.
   *
   * @return array{ok: bool, message: string, suggestions: array<int, array<string, mixed>>, usage_id?: int, parse_status?: string}
   */
  public function analyzeTerm(int $tid, ?int $uid = NULL): array {
    $terms = $this->catalog->terms();
    if (!isset($terms[$tid])) {
      return ['ok' => FALSE, 'message' => '标签不存在。', 'suggestions' => []];
    }
    if (!$this->available()) {
      return ['ok' => FALSE, 'message' => 'AI 不可用，请使用规则检测。', 'suggestions' => []];
    }
    $payload = $this->payload($terms[$tid], $terms);
    $usageId = 0;
    try {
      $models = \Drupal::service('xedc_ai.models');
      $client = \Drupal::service('xedc_ai.client');
      $usage = \Drupal::service('xedc_ai.usage');
      $profile = $models->forTask('general') ?: $models->defaultProfile();
      if ($profile === NULL) {
        return ['ok' => FALSE, 'message' => '没有可用模型。', 'suggestions' => []];
      }
      $last = (int) \Drupal::state()->get(self::RATE_LIMIT_STATE, 0);
      $now = time();
      if ($last > 0 && ($now - $last) < self::RATE_LIMIT_SECONDS) {
        return ['ok' => FALSE, 'message' => 'AI 调用过于频繁，请稍后再试。', 'suggestions' => []];
      }
      if (\Drupal::hasService('xedc_ai.budget')) {
        $auth = \Drupal::service('xedc_ai.budget')->authorize($profile, FALSE, FALSE);
        if (empty($auth['allowed'])) {
          $reason = (string) ($auth['reason'] ?? '预算不足');
          $this->audit->log('ai_budget', $reason, ['tid' => $tid], $tid, $uid);
          return ['ok' => FALSE, 'message' => 'AI 预算限制：' . $reason, 'suggestions' => []];
        }
      }

      $caller = $uid ?? (int) \Drupal::currentUser()->id();
      // One real HTTP request => one usage row. Start before the call.
      $usageId = (int) $usage->start(0, self::USAGE_SOURCE, $profile->providerId, $profile->modelName, $caller);
      \Drupal::state()->set(self::RATE_LIMIT_STATE, $now);

      try {
        $response = $client->once($profile, [
          ['role' => 'system', 'content' => $this->systemPrompt()],
          ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}'],
        ], self::MAX_OUTPUT_TOKENS, '0.1');
      }
      catch (\Throwable $e) {
        $response = ChatResponse::failure(
          $profile->providerId,
          $profile->modelName,
          'network',
          '网络请求失败。',
          0,
          TRUE,
          TRUE,
        );
        $this->logFactory->get('xedc_tags')->warning('AI tag analysis transport failed: @m', ['@m' => $e->getMessage()]);
      }

      $cost = NULL;
      $tokenTotal = $response->promptTokens + $response->completionTokens;
      // Do not invent cost when the API returned no usage tokens.
      if ($response->ok && $profile->priceKnown() && $tokenTotal > 0) {
        $cost = Money::costMicro($response->promptTokens, $response->completionTokens, $profile->inputPrice, $profile->outputPrice);
      }
      $usage->finish($usageId, $response, $cost, $caller);

      if (!$response->ok) {
        $this->audit->log('ai_failed', 'AI 分析失败: ' . $response->errorCode, [
          'tid' => $tid,
          'usage_id' => $usageId,
        ], $tid, $uid);
        return [
          'ok' => FALSE,
          'message' => 'AI 调用失败：' . $response->errorCode,
          'suggestions' => [],
          'usage_id' => $usageId,
          'parse_status' => 'api_error',
        ];
      }

      $parsed = $this->parseModelOutput($response->content, $terms, $tid, $response->finishReason);
      if ($parsed['status'] === 'truncated' || $parsed['status'] === 'invalid' || $parsed['status'] === 'empty_response') {
        $this->audit->log('ai_failed', $parsed['message'], [
          'tid' => $tid,
          'usage_id' => $usageId,
          'parse_status' => $parsed['status'],
          'finish_reason' => $response->finishReason,
        ], $tid, $uid);
        return [
          'ok' => FALSE,
          'message' => $parsed['message'],
          'suggestions' => [],
          'usage_id' => $usageId,
          'parse_status' => $parsed['status'],
        ];
      }

      $suggestions = $parsed['suggestions'];
      $this->audit->log('ai_analyze', 'AI 分析完成，建议 ' . count($suggestions) . ' 条', [
        'count' => count($suggestions),
        'usage_id' => $usageId,
        'prompt_tokens' => $response->promptTokens,
        'completion_tokens' => $response->completionTokens,
        'finish_reason' => $response->finishReason,
        'parse_status' => $parsed['status'],
      ], $tid, $uid);
      $this->store($tid, $suggestions);
      $tokenNote = $tokenTotal > 0
        ? ' Token ' . $response->promptTokens . '+' . $response->completionTokens . '=' . $tokenTotal . '。'
        : ' API 未返回 usage，Token 记为未知。';
      return [
        'ok' => TRUE,
        'message' => $parsed['message'] . $tokenNote,
        'suggestions' => $suggestions,
        'usage_id' => $usageId,
        'parse_status' => $parsed['status'],
      ];
    }
    catch (\Throwable $e) {
      if ($usageId > 0 && \Drupal::hasService('xedc_ai.usage')) {
        try {
          \Drupal::service('xedc_ai.usage')->interrupt($usageId, '标签 AI 分析异常中止。');
        }
        catch (\Throwable) {
          // Ignore secondary logging failures.
        }
      }
      $this->logFactory->get('xedc_tags')->warning('AI tag analysis failed: @m', ['@m' => $e->getMessage()]);
      return ['ok' => FALSE, 'message' => 'AI 分析异常，已中止。', 'suggestions' => [], 'usage_id' => $usageId];
    }
  }

  /**
   * Parse model text without calling the API. Used by analyzeTerm and local tests.
   *
   * @param array<int, array<string, mixed>> $terms
   *
   * @return array{status: string, message: string, suggestions: array<int, array<string, mixed>>}
   */
  public function parseModelOutput(string $content, array $terms, int $sourceTid, string $finishReason = ''): array {
    $raw = trim($content);
    if ($raw === '') {
      return [
        'status' => 'empty_response',
        'message' => '模型返回为空。',
        'suggestions' => [],
      ];
    }

    if ($this->isLengthFinish($finishReason) || $this->looksTruncatedJson($raw)) {
      return [
        'status' => 'truncated',
        'message' => '模型输出被截断（达到长度上限或 JSON 不完整），未保存建议。',
        'suggestions' => [],
      ];
    }

    $normalized = $this->stripCodeFence($raw);
    $decoded = json_decode($normalized, TRUE);
    if (!is_array($decoded)) {
      return [
        'status' => 'invalid',
        'message' => '模型返回的 JSON 无效，未保存建议。',
        'suggestions' => [],
      ];
    }

    $suggestions = $this->normalizeSuggestions($decoded, $terms, $sourceTid);
    if ($suggestions === [] && $this->isExplicitEmptyResult($decoded, $sourceTid)) {
      return [
        'status' => 'empty',
        'message' => '暂无优化建议。',
        'suggestions' => [],
      ];
    }
    if ($suggestions === []) {
      // Parsed JSON but nothing usable after Term ID / schema checks.
      return [
        'status' => 'empty',
        'message' => '暂无优化建议。',
        'suggestions' => [],
      ];
    }
    return [
      'status' => 'ok',
      'message' => '已生成 ' . count($suggestions) . ' 条经验证的建议。',
      'suggestions' => $suggestions,
    ];
  }

  /**
   * Legacy helper kept for phase2 reflection tests: returns rows or [].
   *
   * @param array<int, array<string, mixed>> $terms
   *
   * @return array<int, array<string, mixed>>
   */
  private function validate(string $content, array $terms, int $sourceTid): array {
    $parsed = $this->parseModelOutput($content, $terms, $sourceTid, '');
    return $parsed['suggestions'];
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function stored(int $tid = 0, int $limit = 50): array {
    $db = \Drupal::database();
    if (!$db->schema()->tableExists('xedc_tag_ai_suggestion')) {
      return [];
    }
    $query = $db->select('xedc_tag_ai_suggestion', 's')->fields('s')->orderBy('id', 'DESC')->range(0, $limit);
    if ($tid > 0) {
      $query->condition('source_tid', $tid);
    }
    $out = [];
    foreach ($query->execute() as $row) {
      $decoded = json_decode((string) $row->payload, TRUE);
      if (is_array($decoded)) {
        $decoded['id'] = (int) $row->id;
        $decoded['status'] = (string) $row->status;
        $out[] = $decoded;
      }
    }
    return $out;
  }

  public function decide(int $id, string $decision, ?int $uid = NULL): array {
    $db = \Drupal::database();
    if (!$db->schema()->tableExists('xedc_tag_ai_suggestion')) {
      return ['ok' => FALSE, 'message' => '建议表不存在。'];
    }
    if (!in_array($decision, ['accepted', 'rejected', 'ignored'], TRUE)) {
      return ['ok' => FALSE, 'message' => '决策无效。'];
    }
    $row = $db->select('xedc_tag_ai_suggestion', 's')->fields('s')->condition('id', $id)->execute()->fetch();
    if (!$row) {
      return ['ok' => FALSE, 'message' => '建议不存在。'];
    }
    $db->update('xedc_tag_ai_suggestion')->fields([
      'status' => $decision,
      'changed' => time(),
    ])->condition('id', $id)->execute();
    $this->audit->log('ai_decision', $decision . ' #' . $id, [], (int) $row->source_tid, $uid);
    return ['ok' => TRUE, 'message' => '已记录人工决策。不会自动改标签。'];
  }

  /**
   * Compact user payload: tid, name, optional short desc, optional related ids.
   *
   * @param array<string, mixed> $term
   * @param array<int, array<string, mixed>> $terms
   *
   * @return array<string, mixed>
   */
  private function payload(array $term, array $terms): array {
    $out = [
      'tid' => (int) $term['tid'],
      'name' => (string) ($term['default_name'] ?? $term['name'] ?? ''),
    ];
    $desc = trim(strip_tags((string) ($term['description'] ?? '')));
    if ($desc !== '') {
      $out['desc'] = mb_substr($desc, 0, self::MAX_DESC_LEN);
    }
    $related = [];
    foreach (\Drupal::service('xedc_tags.relations')->forTerm((int) $term['tid'], self::MAX_RELATED, TRUE) as $item) {
      $other = (int) $item['tid'];
      if (isset($terms[$other])) {
        $related[] = [
          'tid' => $other,
          'name' => (string) $terms[$other]['name'],
        ];
      }
    }
    if ($related !== []) {
      $out['related'] = $related;
    }
    return $out;
  }

  private function systemPrompt(): string {
    return <<<'TXT'
你是标签语义分析助手。只分析输入标签。只输出 JSON，无 Markdown，无其它文字。
格式：{"results":[{"tid":数字,"similar":[相关tid],"suggestions":["短建议"],"reason":"一句话"}]}
规则：tid 必须来自输入；similar 只能是输入 related 中的 tid；不确定则 similar/suggestions 用 []；reason 最多一句；禁止编造 tid；禁止删除/自动合并/改 URL。
TXT;
  }

  /**
   * @param array<int|string, mixed> $decoded
   * @param array<int, array<string, mixed>> $terms
   *
   * @return array<int, array<string, mixed>>
   */
  private function normalizeSuggestions(array $decoded, array $terms, int $sourceTid): array {
    if (isset($decoded['results']) && is_array($decoded['results'])) {
      return $this->fromCompactResults($decoded['results'], $terms, $sourceTid);
    }
    // Legacy list / {suggestions:[...]} formats.
    $list = $decoded;
    if (isset($decoded['suggestions']) && is_array($decoded['suggestions']) && $this->looksLegacySuggestionList($decoded['suggestions'])) {
      $list = $decoded['suggestions'];
    }
    return $this->fromLegacyList($list, $terms, $sourceTid);
  }

  /**
   * @param array<int, mixed> $results
   * @param array<int, array<string, mixed>> $terms
   *
   * @return array<int, array<string, mixed>>
   */
  private function fromCompactResults(array $results, array $terms, int $sourceTid): array {
    $allowedRelated = $this->allowedRelatedIds($terms, $sourceTid);
    $out = [];
    foreach ($results as $row) {
      if (!is_array($row)) {
        continue;
      }
      $tid = (int) ($row['tid'] ?? 0);
      if ($tid !== $sourceTid || !isset($terms[$tid])) {
        continue;
      }
      $reason = mb_substr(trim(strip_tags((string) ($row['reason'] ?? ''))), 0, 120);
      $similar = is_array($row['similar'] ?? NULL) ? $row['similar'] : [];
      $seen = [];
      foreach ($similar as $rawId) {
        $targetTid = (int) $rawId;
        if ($targetTid <= 0 || $targetTid === $tid || isset($seen[$targetTid])) {
          continue;
        }
        if (!isset($terms[$targetTid]) || !isset($allowedRelated[$targetTid])) {
          continue;
        }
        $seen[$targetTid] = TRUE;
        $out[] = $this->row($terms, $tid, $targetTid, 'near_duplicate', $reason !== '' ? $reason : '语义接近', '人工确认是否合并或建别名');
        if (count($out) >= 5) {
          return $out;
        }
      }
      $suggestions = is_array($row['suggestions'] ?? NULL) ? $row['suggestions'] : [];
      foreach ($suggestions as $text) {
        if (!is_string($text) && !is_numeric($text)) {
          continue;
        }
        $action = mb_substr(trim(strip_tags((string) $text)), 0, 80);
        if ($action === '' || preg_match('/删除|自动合并|覆盖|改审核|修改\s*URL|noindex/ui', $action)) {
          continue;
        }
        $out[] = $this->row($terms, $tid, NULL, 'keep', $reason !== '' ? $reason : $action, $action);
        if (count($out) >= 5) {
          return $out;
        }
      }
    }
    return $out;
  }

  /**
   * @param array<int|string, mixed> $list
   * @param array<int, array<string, mixed>> $terms
   *
   * @return array<int, array<string, mixed>>
   */
  private function fromLegacyList(array $list, array $terms, int $sourceTid): array {
    $allowed = ['synonym', 'near_duplicate', 'rename', 'merge', 'related', 'seo_title', 'seo_description', 'keep'];
    $out = [];
    foreach ($list as $item) {
      if (!is_array($item)) {
        continue;
      }
      $type = (string) ($item['suggestion_type'] ?? '');
      if (!in_array($type, $allowed, TRUE)) {
        continue;
      }
      $src = (int) ($item['source_tid'] ?? 0);
      if ($src !== $sourceTid || !isset($terms[$src])) {
        continue;
      }
      $targetTid = $item['target_tid'] ?? NULL;
      if ($targetTid !== NULL && $targetTid !== '') {
        $targetTid = (int) $targetTid;
        if ($targetTid <= 0 || !isset($terms[$targetTid]) || $targetTid === $src) {
          continue;
        }
      }
      else {
        $targetTid = NULL;
      }
      $confidence = (float) ($item['confidence'] ?? 0.7);
      if ($confidence < 0 || $confidence > 1) {
        continue;
      }
      $action = trim((string) ($item['recommended_action'] ?? ''));
      if (preg_match('/删除|自动合并|覆盖|改审核|修改\s*URL/ui', $action)) {
        continue;
      }
      $out[] = [
        'source_name' => (string) $terms[$src]['name'],
        'source_tid' => $src,
        'target_name' => $targetTid ? (string) $terms[$targetTid]['name'] : (string) ($item['target_name'] ?? ''),
        'target_tid' => $targetTid,
        'suggestion_type' => $type,
        'reason' => mb_substr(trim(strip_tags((string) ($item['reason'] ?? ''))), 0, 120),
        'confidence' => round($confidence, 3),
        'affected_count' => max(0, (int) ($item['affected_count'] ?? $terms[$src]['published_any'] ?? 0)),
        'risk' => mb_substr(trim(strip_tags((string) ($item['risk'] ?? ''))), 0, 80),
        'recommended_action' => mb_substr($action !== '' ? $action : '仅供人工确认', 0, 80),
      ];
      if (count($out) >= 5) {
        break;
      }
    }
    return $out;
  }

  /**
   * @param array<int, array<string, mixed>> $terms
   *
   * @return array<string, mixed>
   */
  private function row(array $terms, int $tid, ?int $targetTid, string $type, string $reason, string $action): array {
    return [
      'source_name' => (string) $terms[$tid]['name'],
      'source_tid' => $tid,
      'target_name' => $targetTid ? (string) $terms[$targetTid]['name'] : '',
      'target_tid' => $targetTid,
      'suggestion_type' => $type,
      'reason' => $reason,
      'confidence' => 0.7,
      'affected_count' => max(0, (int) ($terms[$tid]['published_any'] ?? 0)),
      'risk' => '',
      'recommended_action' => $action,
    ];
  }

  /**
   * @param array<int, array<string, mixed>> $terms
   *
   * @return array<int, true>
   */
  private function allowedRelatedIds(array $terms, int $sourceTid): array {
    $allowed = [];
    foreach (\Drupal::service('xedc_tags.relations')->forTerm($sourceTid, self::MAX_RELATED, TRUE) as $item) {
      $other = (int) $item['tid'];
      if (isset($terms[$other])) {
        $allowed[$other] = TRUE;
      }
    }
    // Also allow any tid present in catalog for legacy payloads; compact format
    // already filtered above. For compact similar[], require related set.
    return $allowed;
  }

  /**
   * @param array<int|string, mixed> $decoded
   */
  private function isExplicitEmptyResult(array $decoded, int $sourceTid): bool {
    if (!isset($decoded['results']) || !is_array($decoded['results'])) {
      return FALSE;
    }
    foreach ($decoded['results'] as $row) {
      if (!is_array($row)) {
        continue;
      }
      if ((int) ($row['tid'] ?? 0) !== $sourceTid) {
        continue;
      }
      $similar = is_array($row['similar'] ?? NULL) ? $row['similar'] : [];
      $suggestions = is_array($row['suggestions'] ?? NULL) ? $row['suggestions'] : [];
      return $similar === [] && $suggestions === [];
    }
    return FALSE;
  }

  /**
   * @param array<int, mixed> $list
   */
  private function looksLegacySuggestionList(array $list): bool {
    foreach ($list as $item) {
      if (is_array($item) && isset($item['suggestion_type'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function stripCodeFence(string $content): string {
    $content = trim($content);
    if (str_starts_with($content, '```')) {
      $content = preg_replace('/^```(?:json)?\s*/u', '', $content) ?? $content;
      $content = preg_replace('/\s*```$/u', '', $content) ?? $content;
    }
    return trim($content);
  }

  private function isLengthFinish(string $finishReason): bool {
    $reason = strtolower(trim($finishReason));
    return in_array($reason, ['length', 'max_tokens', 'max_completion_tokens'], TRUE);
  }

  private function looksTruncatedJson(string $content): bool {
    $text = $this->stripCodeFence($content);
    if ($text === '') {
      return FALSE;
    }
    json_decode($text);
    if (json_last_error() === JSON_ERROR_NONE) {
      return FALSE;
    }
    $opens = substr_count($text, '{') + substr_count($text, '[');
    $closes = substr_count($text, '}') + substr_count($text, ']');
    if ($opens > $closes) {
      return TRUE;
    }
    if (preg_match('/[,:{\[\(]\s*$/u', $text)) {
      return TRUE;
    }
    // Unclosed JSON string.
    if (preg_match('/(?<!\\\\)"(?:\\\\.|[^"\\\\])*$/u', $text)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @param array<int, array<string, mixed>> $suggestions
   */
  private function store(int $tid, array $suggestions): void {
    $db = \Drupal::database();
    if (!$db->schema()->tableExists('xedc_tag_ai_suggestion') || !$suggestions) {
      return;
    }
    $now = time();
    foreach ($suggestions as $item) {
      $db->insert('xedc_tag_ai_suggestion')->fields([
        'source_tid' => $tid,
        'target_tid' => (int) ($item['target_tid'] ?? 0),
        'suggestion_type' => (string) $item['suggestion_type'],
        'status' => 'pending',
        'payload' => json_encode($item, JSON_UNESCAPED_UNICODE) ?: '{}',
        'created' => $now,
        'changed' => $now,
      ])->execute();
    }
  }

}
