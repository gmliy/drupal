<?php

/**
 * @file
 * Local mock tests for compact tag AI JSON parsing. No real API calls.
 *
 * Run: ./vendor/bin/drush php:script web/modules/custom/xedc_tags/tests/manual/tag-ai-output-optimization-test.php
 */

use Drupal\xedc_ai\Client\ChatResponse;
use Drupal\xedc_ai\Client\OpenAiChatClient;

$failures = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
  echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? ' ' . $detail : '') . PHP_EOL;
  if (!$ok) {
    $failures[] = $name;
  }
};

$db = \Drupal::database();
$usageBefore = (int) $db->query('SELECT COUNT(*) FROM {xedc_ai_usage}')->fetchField();
$tagUsageBefore = (int) $db->query("SELECT COUNT(*) FROM {xedc_ai_usage} WHERE source = :s", [':s' => 'xedc_tags'])->fetchField();
$termsBefore = (int) $db->query("SELECT COUNT(*) FROM {taxonomy_term_field_data} WHERE vid = 'tags' AND default_langcode = 1")->fetchField();
$refsBefore = (int) $db->query('SELECT COUNT(*) FROM {node__field_tags} WHERE deleted = 0')->fetchField();

$ai = \Drupal::service('xedc_tags.ai');
$catalog = \Drupal::service('xedc_tags.catalog');
$terms = $catalog->terms();
$sorted = array_values($terms);
usort($sorted, static fn(array $a, array $b): int => ((int) $b['published_any']) <=> ((int) $a['published_any']));
$primary = $sorted[0] ?? NULL;
$check('has a tag for mocks', $primary !== NULL);
$tid = (int) ($primary['tid'] ?? 0);

// Build allowed related set for compact similar tests.
$relatedIds = [];
foreach (\Drupal::service('xedc_tags.relations')->forTerm($tid, 5, TRUE) as $item) {
  $relatedIds[] = (int) $item['tid'];
}
$relatedTid = $relatedIds[0] ?? 0;

$ref = new ReflectionClass($ai);
$maxConst = $ref->getConstant('MAX_OUTPUT_TOKENS');
$check('MAX_OUTPUT_TOKENS stays 500', $maxConst === 500, (string) $maxConst);

$payloadMethod = $ref->getMethod('payload');
$payloadMethod->setAccessible(TRUE);
$payload = $payloadMethod->invoke($ai, $terms[$tid], $terms);
$check('payload has tid+name only core', isset($payload['tid'], $payload['name']));
$check('payload omits sample titles', !isset($payload['sample_titles']));
$check('payload omits usage breakdown fields', !isset($payload['photo'], $payload['video'], $payload['article'], $payload['usage']));
$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '';
$check('payload stays compact', strlen($encoded) < 800, (string) strlen($encoded));

$promptMethod = $ref->getMethod('systemPrompt');
$promptMethod->setAccessible(TRUE);
$prompt = (string) $promptMethod->invoke($ai);
$check('system prompt mentions compact results', str_contains($prompt, '"results"'));
$check('system prompt forbids markdown', str_contains($prompt, 'Markdown') || str_contains($prompt, 'markdown'));
$check('system prompt is short', mb_strlen($prompt) < 280, (string) mb_strlen($prompt));

// --- Parse scenarios (no API) ---
$emptyOk = $ai->parseModelOutput(json_encode([
  'results' => [[
    'tid' => $tid,
    'similar' => [],
    'suggestions' => [],
    'reason' => '标签语义明确，暂不需要调整',
  ]],
], JSON_UNESCAPED_UNICODE), $terms, $tid, 'stop');
$check('normal empty suggestions ok', ($emptyOk['status'] ?? '') === 'empty' && ($emptyOk['suggestions'] ?? NULL) === []);
$check('empty suggestions message', str_contains((string) ($emptyOk['message'] ?? ''), '暂无'));

$withSimilar = $relatedTid > 0 ? $ai->parseModelOutput(json_encode([
  'results' => [[
    'tid' => $tid,
    'similar' => [$relatedTid],
    'suggestions' => [],
    'reason' => '存在语义接近标签',
  ]],
], JSON_UNESCAPED_UNICODE), $terms, $tid, 'stop') : ['status' => 'ok', 'suggestions' => [['x' => 1]], 'message' => 'skip'];
if ($relatedTid > 0) {
  $check('similar tid parsed', $withSimilar['status'] === 'ok' && count($withSimilar['suggestions']) >= 1);
  $check('similar maps near_duplicate', ($withSimilar['suggestions'][0]['suggestion_type'] ?? '') === 'near_duplicate');
  $check('similar target tid kept', (int) ($withSimilar['suggestions'][0]['target_tid'] ?? 0) === $relatedTid);
}
else {
  $check('similar tid parsed', TRUE, 'no related tags to mock');
}

$withText = $ai->parseModelOutput(json_encode([
  'results' => [[
    'tid' => $tid,
    'similar' => [],
    'suggestions' => ['补充简短描述'],
    'reason' => '缺少描述',
  ]],
], JSON_UNESCAPED_UNICODE), $terms, $tid, 'stop');
$check('text suggestion parsed', $withText['status'] === 'ok' && count($withText['suggestions']) === 1);
$check('text suggestion stored shape', ($withText['suggestions'][0]['recommended_action'] ?? '') === '补充简短描述');

$truncatedLen = $ai->parseModelOutput('{"results":[{"tid":' . $tid . ',"similar":[', $terms, $tid, 'length');
$check('finish_reason=length truncated', $truncatedLen['status'] === 'truncated');

$truncatedJson = $ai->parseModelOutput('{"results":[{"tid":' . $tid . ',"similar":[1,2],"suggestions":["abc"', $terms, $tid, 'stop');
$check('incomplete JSON truncated', $truncatedJson['status'] === 'truncated');

$invalid = $ai->parseModelOutput('not-json-at-all', $terms, $tid, 'stop');
$check('invalid JSON fails safely', $invalid['status'] === 'invalid');

$unknown = $ai->parseModelOutput(json_encode([
  'results' => [[
    'tid' => $tid,
    'similar' => [999999999],
    'suggestions' => [],
    'reason' => 'x',
  ]],
], JSON_UNESCAPED_UNICODE), $terms, $tid, 'stop');
$check('unknown similar tid rejected', $unknown['suggestions'] === []);

$legacy = $ai->parseModelOutput(json_encode([[
  'source_tid' => $tid,
  'source_name' => $terms[$tid]['name'],
  'target_tid' => NULL,
  'suggestion_type' => 'rename',
  'reason' => '旧格式',
  'confidence' => 0.9,
  'affected_count' => 1,
  'risk' => '',
  'recommended_action' => '人工确认',
]], JSON_UNESCAPED_UNICODE), $terms, $tid, 'stop');
$check('legacy format still accepted', $legacy['status'] === 'ok' && count($legacy['suggestions']) === 1);

$fence = $ai->parseModelOutput("```json\n" . json_encode([
  'results' => [[
    'tid' => $tid,
    'similar' => [],
    'suggestions' => [],
    'reason' => '围栏测试',
  ]],
], JSON_UNESCAPED_UNICODE) . "\n```", $terms, $tid, 'stop');
$check('markdown fence stripped safely', $fence['status'] === 'empty');

$emptyResp = $ai->parseModelOutput('', $terms, $tid, 'stop');
$check('empty response fails', $emptyResp['status'] === 'empty_response');

$badTid = $ai->parseModelOutput(json_encode([
  'results' => [[
    'tid' => 999999999,
    'similar' => [],
    'suggestions' => ['x'],
    'reason' => 'bad',
  ]],
], JSON_UNESCAPED_UNICODE), $terms, $tid, 'stop');
$check('foreign tid ignored', $badTid['suggestions'] === []);

// Two-tag compact payload shape (local only): second result ignored when sourceTid mismatches.
$two = $ai->parseModelOutput(json_encode([
  'results' => [
    ['tid' => $tid, 'similar' => [], 'suggestions' => [], 'reason' => '主标签无建议'],
    ['tid' => ($sorted[1]['tid'] ?? $tid), 'similar' => [], 'suggestions' => ['另一标签'], 'reason' => '次标签'],
  ],
], JSON_UNESCAPED_UNICODE), $terms, $tid, 'stop');
$check('two-tag results keep only source tid', $two['status'] === 'empty' && $two['suggestions'] === []);

// finishReason plumbing on ChatResponse / client interpret via reflection.
$clientRef = new ReflectionClass(OpenAiChatClient::class);
$interpret = $clientRef->getMethod('interpret');
$interpret->setAccessible(TRUE);
$client = \Drupal::service('xedc_ai.client');
$profile = \Drupal::service('xedc_ai.models')->forTask('general') ?: \Drupal::service('xedc_ai.models')->defaultProfile();
$check('model profile available for mock interpret', $profile !== NULL);
if ($profile) {
  $transportResultClass = new ReflectionClass(\Drupal\xedc_ai\Client\TransportResult::class);
  $tr = $transportResultClass->newInstance(200, json_encode([
    'choices' => [[
      'message' => ['content' => '{"results":[]}'],
      'finish_reason' => 'length',
    ]],
    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
  ], JSON_UNESCAPED_UNICODE), '', '');
  /** @var \Drupal\xedc_ai\Client\ChatResponse $resp */
  $resp = $interpret->invoke($client, $profile, $tr, 12);
  $check('client exposes finish_reason', $resp instanceof ChatResponse && $resp->finishReason === 'length', $resp->finishReason ?? '');
  $check('client tokens from usage', $resp->promptTokens === 10 && $resp->completionTokens === 20);
}

// Dangerous suggestion filtered.
$danger = $ai->parseModelOutput(json_encode([
  'results' => [[
    'tid' => $tid,
    'similar' => [],
    'suggestions' => ['自动合并全部标签', '补充描述'],
    'reason' => '混杂',
  ]],
], JSON_UNESCAPED_UNICODE), $terms, $tid, 'stop');
$check('dangerous suggestion filtered', count($danger['suggestions']) === 1 && ($danger['suggestions'][0]['recommended_action'] ?? '') === '补充描述');

// Rate limit path still blocks without API (no usage write).
\Drupal::state()->set('xedc_tags.ai_last_call', time());
$rate = $ai->analyzeTerm($tid, 1);
$check('rate limit blocks without API', !$rate['ok'] && str_contains($rate['message'], '频繁'), $rate['message']);

$usageAfter = (int) $db->query('SELECT COUNT(*) FROM {xedc_ai_usage}')->fetchField();
$tagUsageAfter = (int) $db->query("SELECT COUNT(*) FROM {xedc_ai_usage} WHERE source = :s", [':s' => 'xedc_tags'])->fetchField();
$termsAfter = (int) $db->query("SELECT COUNT(*) FROM {taxonomy_term_field_data} WHERE vid = 'tags' AND default_langcode = 1")->fetchField();
$refsAfter = (int) $db->query('SELECT COUNT(*) FROM {node__field_tags} WHERE deleted = 0')->fetchField();
$check('no new usage rows', $usageAfter === $usageBefore, $usageBefore . '->' . $usageAfter);
$check('no new xedc_tags usage', $tagUsageAfter === $tagUsageBefore, $tagUsageBefore . '->' . $tagUsageAfter);
$check('term count unchanged', $termsAfter === $termsBefore);
$check('tag refs unchanged', $refsAfter === $refsBefore);

if ($failures) {
  echo 'OPTIMIZATION TEST FAIL ' . implode(', ', $failures) . PHP_EOL;
  return;
}
echo 'OPTIMIZATION TEST PASS' . PHP_EOL;
