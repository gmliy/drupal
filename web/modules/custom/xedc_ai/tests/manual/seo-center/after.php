<?php

/**
 * @file
 * Lab checks after the browser run. Does not print secrets.
 */

$meta = json_decode((string) file_get_contents('/tmp/xedc-seo-center-meta.json'), TRUE);
$storage = \Drupal::entityTypeManager()->getStorage('node');
$kept = $storage->load(13);
$now = [
  'vid' => $kept ? (int) $kept->getRevisionId() : 0,
  'title' => $kept ? (string) $kept->label() : '',
  'status' => $kept ? (int) $kept->isPublished() : -1,
  'moderation' => $kept && $kept->hasField('moderation_state') ? (string) $kept->get('moderation_state')->value : '',
];
$page = $storage->load((int) $meta['page_nid']);
$reviews = \Drupal::database()->select('xedc_ai_review', 'r')
  ->fields('r', ['id', 'status', 'error_code', 'revision_id', 'entity_id'])
  ->condition('entity_id', (int) $meta['page_nid'])
  ->condition('source', 'seo_optimization')
  ->orderBy('id', 'DESC')
  ->execute()
  ->fetchAll(\PDO::FETCH_ASSOC);
$usage = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {xedc_ai_usage}')->fetchField();
$queued = (int) \Drupal::database()->query("SELECT COUNT(*) FROM {queue} WHERE name = 'xedc_ai_review'")->fetchField();
$report = [
  'node13_same' => $now === $meta['node13'],
  'page_status' => $page ? (int) $page->isPublished() : -1,
  'page_vid' => $page ? (int) $page->getRevisionId() : 0,
  'reviews' => $reviews,
  'usage_delta' => $usage - (int) $meta['usage_before'],
  'queue_left' => $queued,
  'schema' => (int) \Drupal::keyValue('system.schema')->get('xedc_ai'),
  'key_file_still_present' => is_file('/var/www/drupal/private/xedc-ai-keys/deepseek.key'),
];
file_put_contents('/tmp/xedc-seo-center-after.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "AFTER OK\n";
