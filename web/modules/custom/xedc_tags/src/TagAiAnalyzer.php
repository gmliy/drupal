<?php

namespace Drupal\xedc_tags;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Optional AI suggestions. Reuses xedc_ai; never mutates taxonomy or content.
 */
final class TagAiAnalyzer {

  private const MAX_TITLES = 5;
  private const MAX_TITLE_LEN = 80;
  private const MAX_RELATED = 8;

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
   * @return array{ok: bool, message: string, suggestions: array<int, array<string, mixed>>}
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
    try {
      $models = \Drupal::service('xedc_ai.models');
      $client = \Drupal::service('xedc_ai.client');
      $profile = $models->forTask('general') ?: $models->defaultProfile();
      if ($profile === NULL) {
        return ['ok' => FALSE, 'message' => '没有可用模型。', 'suggestions' => []];
      }
      $response = $client->once($profile, [
        ['role' => 'system', 'content' => $this->systemPrompt()],
        ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}'],
      ], 1200, '0.1');
      if (!$response->ok) {
        $this->audit->log('ai_failed', 'AI 分析失败: ' . $response->errorCode, ['tid' => $tid], $tid, $uid);
        return ['ok' => FALSE, 'message' => 'AI 调用失败：' . $response->errorCode, 'suggestions' => []];
      }
      $suggestions = $this->validate($response->content, $terms, $tid);
      $this->audit->log('ai_analyze', 'AI 分析完成，建议 ' . count($suggestions) . ' 条', ['count' => count($suggestions)], $tid, $uid);
      $this->store($tid, $suggestions);
      return [
        'ok' => TRUE,
        'message' => count($suggestions) ? '已生成 ' . count($suggestions) . ' 条经验证的建议。' : '模型未返回可接受的建议。',
        'suggestions' => $suggestions,
      ];
    }
    catch (\Throwable $e) {
      $this->logFactory->get('xedc_tags')->warning('AI tag analysis failed: @m', ['@m' => $e->getMessage()]);
      return ['ok' => FALSE, 'message' => 'AI 分析异常，已中止。', 'suggestions' => []];
    }
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
   * @param array<string, mixed> $term
   * @param array<int, array<string, mixed>> $terms
   * @return array<string, mixed>
   */
  private function payload(array $term, array $terms): array {
    $titles = [];
    $db = \Drupal::database();
    $query = $db->select('node__field_tags', 't');
    $query->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.status = 1');
    $query->condition('t.field_tags_target_id', (int) $term['tid']);
    $query->condition('t.deleted', 0);
    $query->fields('n', ['title', 'type']);
    $query->orderBy('n.created', 'DESC');
    $query->range(0, self::MAX_TITLES);
    $query->distinct();
    foreach ($query->execute() as $row) {
      $titles[] = [
        'type' => (string) $row->type,
        'title' => mb_substr(trim(strip_tags((string) $row->title)), 0, self::MAX_TITLE_LEN),
      ];
    }
    $related = [];
    foreach (\Drupal::service('xedc_tags.relations')->forTerm((int) $term['tid'], self::MAX_RELATED, TRUE) as $item) {
      $other = (int) $item['tid'];
      if (isset($terms[$other])) {
        $related[] = ['tid' => $other, 'name' => (string) $terms[$other]['name'], 'shared' => (int) $item['shared']];
      }
    }
    return [
      'term_id' => (int) $term['tid'],
      'name' => (string) $term['default_name'],
      'usage' => (int) $term['published_any'],
      'photo' => (int) $term['photo_pub'],
      'video' => (int) $term['video_pub'],
      'article' => (int) $term['article_pub'],
      'alias' => (string) $term['alias'],
      'has_description' => !empty($term['description']),
      'sample_titles' => $titles,
      'related_tags' => $related,
    ];
  }

  private function systemPrompt(): string {
    return <<<'TXT'
你是标签治理助手。只输出 JSON 数组，不要 markdown。每项字段：
source_name, source_tid, target_name, target_tid(可为null), suggestion_type,
reason, confidence(0-1), affected_count, risk, recommended_action。
suggestion_type 仅允许: synonym, near_duplicate, rename, merge, related, seo_title, seo_description, keep。
不得建议删除标签、改审核状态、改 URL、批量 noindex。
target_tid 必须是输入里出现过的术语 ID，或 null。
TXT;
  }

  /**
   * @param array<int, array<string, mixed>> $terms
   * @return array<int, array<string, mixed>>
   */
  private function validate(string $content, array $terms, int $sourceTid): array {
    $content = trim($content);
    if (str_starts_with($content, '```')) {
      $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content) ?? $content;
    }
    $decoded = json_decode($content, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    if (isset($decoded['suggestions']) && is_array($decoded['suggestions'])) {
      $decoded = $decoded['suggestions'];
    }
    $allowed = ['synonym', 'near_duplicate', 'rename', 'merge', 'related', 'seo_title', 'seo_description', 'keep'];
    $out = [];
    foreach ($decoded as $item) {
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
      $confidence = (float) ($item['confidence'] ?? 0);
      if ($confidence < 0 || $confidence > 1) {
        continue;
      }
      $action = trim((string) ($item['recommended_action'] ?? ''));
      if ($action === '' || preg_match('/删除|自动合并|覆盖|noindex|改审核|修改URL/ui', $action)) {
        if (preg_match('/删除|自动合并|覆盖|改审核|修改\s*URL/ui', $action)) {
          continue;
        }
      }
      $out[] = [
        'source_name' => (string) $terms[$src]['name'],
        'source_tid' => $src,
        'target_name' => $targetTid ? (string) $terms[$targetTid]['name'] : (string) ($item['target_name'] ?? ''),
        'target_tid' => $targetTid,
        'suggestion_type' => $type,
        'reason' => mb_substr(trim(strip_tags((string) ($item['reason'] ?? ''))), 0, 500),
        'confidence' => round($confidence, 3),
        'affected_count' => max(0, (int) ($item['affected_count'] ?? $terms[$src]['published_any'])),
        'risk' => mb_substr(trim(strip_tags((string) ($item['risk'] ?? ''))), 0, 500),
        'recommended_action' => mb_substr($action !== '' ? $action : '仅供人工确认', 0, 255),
      ];
      if (count($out) >= 12) {
        break;
      }
    }
    return $out;
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
