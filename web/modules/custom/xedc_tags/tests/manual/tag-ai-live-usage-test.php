<?php

/**
 * @file
 * One real low-cost tag AI call + xedc_ai_usage verification.
 *
 * Run: ./vendor/bin/drush php:script web/modules/custom/xedc_tags/tests/manual/tag-ai-live-usage-test.php
 */

use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Budget\Money;

$failures = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
  echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? ' ' . $detail : '') . PHP_EOL;
  if (!$ok) {
    $failures[] = $name;
  }
};

$db = \Drupal::database();
$catalog = \Drupal::service('xedc_tags.catalog');
$ai = \Drupal::service('xedc_tags.ai');

$beforeTerms = count($catalog->terms());
$beforeRefs = (int) $db->query('SELECT COUNT(*) FROM {node__field_tags} WHERE deleted = 0')->fetchField();
$beforeIds = array_map('intval', array_keys($catalog->terms()));
sort($beforeIds);
$beforeUsage = (int) $db->query('SELECT COUNT(*) FROM {xedc_ai_usage}')->fetchField();
$beforeTagUsage = (int) $db->query("SELECT COUNT(*) FROM {xedc_ai_usage} WHERE source = :s", [':s' => 'xedc_tags'])->fetchField();
$beforeNames = [];
foreach ($catalog->terms() as $tid => $term) {
  $beforeNames[(int) $tid] = (string) $term['name'];
}

$status = $ai->status();
$check('AI status readable', isset($status['ok'], $status['message']));
if (empty($status['ok'])) {
  echo 'STOP real call: ' . ($status['message'] ?? 'AI unavailable') . PHP_EOL;
  echo 'LIVE TEST STOPPED BEFORE API CALL' . PHP_EOL;
  return;
}

$terms = array_values($catalog->terms());
usort($terms, static fn(array $a, array $b): int => ((int) $b['published_any']) <=> ((int) $a['published_any']));
$target = $terms[0] ?? NULL;
$check('selected one high-frequency tag', $target !== NULL, $target ? ($target['tid'] . ' ' . $target['name'] . ' uses=' . $target['published_any']) : '');
if (!$target) {
  echo 'LIVE TEST STOPPED' . PHP_EOL;
  return;
}

$tid = (int) $target['tid'];
echo 'CALLING analyzeTerm once for tid=' . $tid . ' name=' . $target['name'] . PHP_EOL;
$result = $ai->analyzeTerm($tid, 1);
$check('analyze returned array', is_array($result));
echo 'RESULT ok=' . (!empty($result['ok']) ? 'Y' : 'N') . ' message=' . ($result['message'] ?? '') . PHP_EOL;
echo 'suggestions=' . count($result['suggestions'] ?? []) . ' usage_id=' . (int) ($result['usage_id'] ?? 0) . PHP_EOL;

$usageId = (int) ($result['usage_id'] ?? 0);
$check('usage id created', $usageId > 0, (string) $usageId);

$row = NULL;
if ($usageId > 0) {
  $row = $db->select('xedc_ai_usage', 'u')->fields('u')->condition('id', $usageId)->execute()->fetchAssoc() ?: NULL;
}
$check('usage row exists', is_array($row));
if (is_array($row)) {
  $check('source is xedc_tags', (string) $row['source'] === 'xedc_tags', (string) $row['source']);
  $check('provider recorded', (string) $row['provider_id'] !== '', (string) $row['provider_id']);
  $check('model recorded', (string) $row['model_name'] !== '', (string) $row['model_name']);
  $check('call finished', (string) $row['call_state'] === 'finished', (string) $row['call_state']);
  $check('status success or error', in_array((string) $row['status'], ['success', 'error'], TRUE), (string) $row['status']);
  $check('label maps for ops center', Labels::source('xedc_tags') === '标签管理（标签语义分析）');
  echo 'USAGE provider=' . $row['provider_id'] . ' model=' . $row['model_name'] . ' status=' . $row['status'] . PHP_EOL;
  echo 'TOKENS prompt=' . $row['prompt_tokens'] . ' completion=' . $row['completion_tokens'] . ' total=' . $row['total_tokens'] . PHP_EOL;
  echo 'DURATION_MS=' . $row['duration_ms'] . ' cost_known=' . $row['cost_known'] . ' cost_micro=' . $row['cost_micro'] . PHP_EOL;
  if ((int) $row['cost_known'] === 1) {
    echo 'COST_YUAN=' . Money::yuan((int) $row['cost_micro']) . PHP_EOL;
  }
  else {
    echo 'COST=未核算/未知' . PHP_EOL;
  }
  if ((string) $row['status'] === 'success') {
    $check('successful call has API connectivity', TRUE);
    if (((int) $row['prompt_tokens'] + (int) $row['completion_tokens']) === 0) {
      echo 'NOTE usage tokens missing from API response; not inventing token counts' . PHP_EOL;
    }
  }
  else {
    echo 'API_ERROR code=' . $row['error_code'] . ' message=' . $row['error_message'] . PHP_EOL;
  }
}

$afterUsage = (int) $db->query('SELECT COUNT(*) FROM {xedc_ai_usage}')->fetchField();
$afterTagUsage = (int) $db->query("SELECT COUNT(*) FROM {xedc_ai_usage} WHERE source = :s", [':s' => 'xedc_tags'])->fetchField();
$check('exactly one new usage row overall', $afterUsage === $beforeUsage + 1, $beforeUsage . '->' . $afterUsage);
$check('exactly one new xedc_tags usage row', $afterTagUsage === $beforeTagUsage + 1, $beforeTagUsage . '->' . $afterTagUsage);

// Ops center search filter.
$search = \Drupal::service('xedc_ai.usage')->search(['source' => 'xedc_tags']);
$found = FALSE;
foreach ($search['rows'] as $item) {
  if ((int) $item['id'] === $usageId) {
    $found = TRUE;
    break;
  }
}
$check('usage search by source finds the row', $found);

// Data safety.
$catalogFresh = \Drupal::service('xedc_tags.catalog');
// Invalidate so we re-read.
\Drupal\Core\Cache\Cache::invalidateTags($catalogFresh->cacheTags());
$afterTerms = count($catalogFresh->terms());
$afterRefs = (int) $db->query('SELECT COUNT(*) FROM {node__field_tags} WHERE deleted = 0')->fetchField();
$afterIds = array_map('intval', array_keys($catalogFresh->terms()));
sort($afterIds);
$check('term count unchanged', $afterTerms === $beforeTerms, $beforeTerms . '->' . $afterTerms);
$check('tag refs unchanged', $afterRefs === $beforeRefs, $beforeRefs . '->' . $afterRefs);
$check('term ids unchanged', $beforeIds === $afterIds);
$nameOk = TRUE;
foreach ($catalogFresh->terms() as $tidKey => $term) {
  if (($beforeNames[(int) $tidKey] ?? NULL) !== (string) $term['name']) {
    $nameOk = FALSE;
    break;
  }
}
$check('term names unchanged', $nameOk);

// Other sources untouched count delta should be zero for non-tag sources relative to this call.
$otherDelta = ($afterUsage - $beforeUsage) - ($afterTagUsage - $beforeTagUsage);
$check('other sources not duplicated by this call', $otherDelta === 0, (string) $otherDelta);

if ($failures) {
  echo 'LIVE TEST FAIL ' . implode(', ', $failures) . PHP_EOL;
  return;
}
echo 'LIVE TEST PASS' . PHP_EOL;
