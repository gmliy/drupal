<?php

/**
 * @file
 * Moves the lab fixture through the visible task phases. No model call.
 */

$meta = json_decode((string) file_get_contents('/tmp/xedc-seo-center-meta.json'), TRUE);
$id = (int) ($meta['fixture'] ?? 0);
if ($id < 1) {
  echo "NO FIXTURE\n";
  return;
}
$reviews = \Drupal::service('xedc_ai.reviews');
$reviews->update($id, [
  'status' => 'processing',
  'local_checks' => '',
]);
sleep(3);
$reviews->update($id, [
  'local_checks' => '{"phase":"suggest"}',
]);
sleep(3);
$reviews->update($id, [
  'status' => 'completed',
  'seo_score' => 81,
  'summary' => '实验室完成',
  'result' => json_encode([
    'summary' => '实验室完成',
    'issue_items' => [['message' => '标题可以更具体', 'severity' => 'low', 'field' => 'title']],
    'items' => [[
      'key' => 'title',
      'label' => '标题',
      'applicable' => TRUE,
      'state' => 'pending',
      'current' => '原标题',
      'suggested' => '建议标题',
    ]],
  ], JSON_UNESCAPED_UNICODE),
  'error_code' => '',
  'error_message' => '',
  'completed' => \Drupal::time()->getRequestTime(),
]);
echo "FIXTURE COMPLETE\n";
