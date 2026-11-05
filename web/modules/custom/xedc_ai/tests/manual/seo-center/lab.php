<?php

/**
 * @file
 * Lab-only fixtures for the SEO page fix. Does not call a model.
 */

use Drupal\node\Entity\Node;

\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
\Drupal::configFactory()->getEditable('xedc_auto_translate.settings')->set('enabled', FALSE)->save(TRUE);
\Drupal::configFactory()->getEditable('xedc_ai.settings')->set('retry_limit', 0)->save(TRUE);

$database = \Drupal::database();
$database->update('xedc_ai_provider')->fields(['base_url' => 'http://127.0.0.1:9'])->execute();
$key = \Drupal::entityTypeManager()->getStorage('key')->load('xedc_ai_deepseek');
if ($key !== NULL) {
  $settings = $key->get('key_provider_settings');
  if (!is_array($settings)) {
    $settings = [];
  }
  $settings['file_location'] = '/tmp/xedc-lab-no-key';
  $key->set('key_provider_settings', $settings);
  $key->save();
}

$storage = \Drupal::entityTypeManager()->getStorage('node');
$kept = $storage->load(13);
$node13 = [
  'vid' => $kept ? (int) $kept->getRevisionId() : 0,
  'title' => $kept ? (string) $kept->label() : '',
  'status' => $kept ? (int) $kept->isPublished() : -1,
  'moderation' => $kept && $kept->hasField('moderation_state') ? (string) $kept->get('moderation_state')->value : '',
];
$usageBefore = (int) $database->query('SELECT COUNT(*) FROM {xedc_ai_usage}')->fetchField();

$page = Node::create([
  'type' => 'page',
  'title' => 'SEO页面修复样本',
  'status' => 0,
  'langcode' => 'zh-hans',
]);
$page->save();
$page = $storage->load($page->id());

$queue = \Drupal::queue('xedc_ai_review');
$database->delete('queue')->condition('name', 'xedc_ai_review')->execute();

$enqueuer = \Drupal::service('xedc_ai.enqueuer');
$first = $enqueuer->request($page, 1, 'seo_optimization', 'seo', FALSE, FALSE);
$second = $enqueuer->request($page, 1, 'seo_optimization', 'seo', FALSE, FALSE);
$queued = (int) $database->query("SELECT COUNT(*) FROM {queue} WHERE name = 'xedc_ai_review'")->fetchField();
$worker = ['ran' => FALSE, 'status' => '', 'error_code' => '', 'released' => FALSE];
$item = $queue->claimItem(180);
if ($item !== FALSE) {
  try {
    \Drupal::service('plugin.manager.queue_worker')->createInstance('xedc_ai_review')->processItem($item->data);
    $queue->deleteItem($item);
    $worker['ran'] = TRUE;
  }
  catch (\Throwable) {
    $queue->releaseItem($item);
    $worker['released'] = TRUE;
  }
}
$processed = \Drupal::service('xedc_ai.reviews')->load($first);
$worker['status'] = (string) ($processed['status'] ?? '');
$worker['error_code'] = (string) ($processed['error_code'] ?? '');
$worker['revision_id'] = (int) ($processed['revision_id'] ?? 0);
$worker['same_id'] = $first === $second;
$worker['queued_once'] = $queued === 1;
$database->delete('queue')->condition('name', 'xedc_ai_review')->execute();

$reviews = \Drupal::service('xedc_ai.reviews');
$fixture = $reviews->create([
  'entity_type' => 'node',
  'entity_id' => (int) $page->id(),
  'revision_id' => (int) $page->getRevisionId(),
  'langcode' => 'zh-hans',
  'bundle' => 'page',
  'fingerprint' => 'lab-page-fix-open-' . $page->id(),
  'field_hash' => 'lab-page-fix-open',
  'model_id' => 0,
  'provider_id' => '',
  'model_name' => '',
  'rule_version' => 1,
  'source' => 'seo_optimization',
  'depth' => 'seo',
  'title' => 'SEO页面修复样本',
  'uid' => 1,
]);

$expect = static function ($node): array {
  $info = \Drupal::service('content_moderation.moderation_information');
  $bundle = match ($node->bundle()) {
    'photo' => '美图',
    'video' => '视频',
    'article' => '文章',
    'page' => '页面',
    default => (string) $node->bundle(),
  };
  $published = $node->isPublished();
  $publish = '暂无';
  if ($published) {
    $publish = '暂无';
    if ($node->hasField('field_published_at') && !$node->get('field_published_at')->isEmpty()) {
      try {
        $date = new \Drupal\Core\Datetime\DrupalDateTime((string) $node->get('field_published_at')->value, 'UTC');
        $publish = \Drupal::service('date.formatter')->format($date->getTimestamp(), 'custom', 'Y-m-d H:i');
      }
      catch (\Throwable) {
        $publish = '暂无';
      }
    }
  }
  $workflow = '未配置';
  $state = '无审核工作流';
  if ($node->hasField('moderation_state') && $info->isModeratedEntity($node)) {
    $definition = $info->getWorkflowForEntity($node);
    if ($definition !== NULL && method_exists($definition, 'status') && !$definition->status()) {
      $workflow = '未启用';
    }
    elseif ($definition !== NULL) {
      $workflow = (string) $definition->label();
      $stateId = (string) $node->get('moderation_state')->value;
      try {
        $state = (string) $definition->getTypePlugin()->getState($stateId)->label();
      }
      catch (\Throwable) {
        $state = $stateId !== '' ? $stateId : '无审核工作流';
      }
    }
  }
  return [
    'nid' => (int) $node->id(),
    'vid' => (int) $node->getRevisionId(),
    'bundle' => $bundle,
    'publish' => $publish,
    'status' => $published ? '已发布' : '未发布',
    'workflow' => $workflow,
    'state' => $state,
  ];
};

$ensure = static function (int $nid) use ($reviews, $storage, $expect): ?array {
  $node = $storage->load($nid);
  if ($node === NULL) {
    return NULL;
  }
  $row = $reviews->latestForEntity($nid, 'seo_optimization');
  if ($row === NULL) {
    $id = $reviews->create([
      'entity_type' => 'node',
      'entity_id' => $nid,
      'revision_id' => (int) $node->getRevisionId(),
      'langcode' => (string) $node->language()->getId(),
      'bundle' => $node->bundle(),
      'fingerprint' => 'lab-page-fix-' . $nid . '-' . $node->getRevisionId(),
      'field_hash' => 'lab-page-fix',
      'model_id' => 0,
      'provider_id' => '',
      'model_name' => '',
      'rule_version' => 1,
      'source' => 'seo_optimization',
      'depth' => 'seo',
      'title' => (string) $node->label(),
      'uid' => 1,
      'moderation_state' => $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '',
      'moderation_workflow' => '',
    ]);
    $reviews->update($id, [
      'status' => 'completed',
      'seo_score' => 60,
      'summary' => '实验室展示',
      'result' => '{}',
      'completed' => \Drupal::time()->getRequestTime(),
    ]);
    $reviewId = $id;
    $analysisVid = (int) $node->getRevisionId();
  }
  else {
    $reviewId = (int) $row['id'];
    $analysisVid = (int) $row['revision_id'];
  }
  $facts = $expect($node);
  $facts['review'] = $reviewId;
  $facts['analysis_vid'] = $analysisVid;
  $facts['match'] = $analysisVid === (int) $node->getRevisionId();
  return $facts;
};

$makeState = static function (string $title, array $states) use ($storage): int {
  $node = Node::create([
    'type' => 'article',
    'title' => $title,
    'status' => 0,
    'langcode' => 'zh-hans',
  ]);
  $node->xedc_keep_unpublished = TRUE;
  $node->save();
  foreach ($states as $state) {
    $node = $storage->load($node->id());
    $node->set('moderation_state', $state);
    $node->xedc_keep_unpublished = TRUE;
    $node->save();
  }
  return (int) $node->id();
};
$createdStates = [
  'draft' => $makeState('SEO草稿样本', []),
  'changes_requested' => $makeState('SEO退回修改样本', ['needs_review', 'changes_requested']),
  'rejected' => $makeState('SEO已拒绝样本', ['needs_review', 'rejected']),
];
$database->delete('queue')->condition('name', 'xedc_ai_review')->execute();

$cases = [];
$required = [
  'draft' => '草稿',
  'needs_review' => '待审核',
  'changes_requested' => '退回修改',
  'published' => '已发布',
  'rejected' => '已拒绝',
  'archived' => '已撤下',
];
foreach ($required as $state => $label) {
  if (isset($createdStates[$state])) {
    $nid = $createdStates[$state];
  }
  else {
    $nid = (int) $database->query("SELECT n.nid FROM {node_field_data} n INNER JOIN {content_moderation_state_field_data} cms ON cms.content_entity_id = n.nid AND cms.content_entity_revision_id = n.vid AND cms.langcode = n.langcode WHERE n.default_langcode = 1 AND cms.moderation_state = :state ORDER BY n.nid ASC LIMIT 1", [':state' => $state])->fetchField();
  }
  $item = $nid > 0 ? $ensure($nid) : NULL;
  if (is_array($item)) {
    $item['required_state'] = $label;
  }
  $cases[$state] = $item;
}
$timed = (int) $database->query("SELECT n.nid FROM {node_field_data} n INNER JOIN {node__field_published_at} f ON f.entity_id = n.nid AND f.deleted = 0 WHERE n.status = 1 AND n.default_langcode = 1 AND f.field_published_at_value <> '' ORDER BY n.nid ASC LIMIT 1")->fetchField();
$cases['published_time'] = $timed > 0 ? $ensure($timed) : NULL;
$pageFacts = $expect($page);
$pageFacts['review'] = $first;
$pageFacts['analysis_vid'] = (int) ($processed['revision_id'] ?? 0);
$pageFacts['match'] = $pageFacts['analysis_vid'] === (int) $page->getRevisionId();
$cases['no_workflow'] = $pageFacts;

$mismatchId = (int) $database->query("SELECT r.id FROM {xedc_ai_review} r INNER JOIN {node_field_data} n ON n.nid = r.entity_id WHERE r.source = 'seo_optimization' AND r.status = 'completed' AND r.revision_id <> n.vid ORDER BY r.id DESC LIMIT 1")->fetchField();
$mismatch = NULL;
if ($mismatchId > 0) {
  $row = $reviews->load($mismatchId);
  $live = $storage->load((int) $row['entity_id']);
  $old = $storage->loadRevision((int) $row['revision_id']);
  $mismatch = $expect($live);
  $mismatch['review'] = $mismatchId;
  $mismatch['analysis_vid'] = (int) $row['revision_id'];
  $mismatch['match'] = FALSE;
  $oldState = '';
  if ($old !== NULL && $old->hasField('moderation_state') && (string) $old->get('moderation_state')->value !== '') {
    $info = \Drupal::service('content_moderation.moderation_information');
    $definition = $info->isModeratedEntity($old) ? $info->getWorkflowForEntity($old) : NULL;
    $stateId = (string) $old->get('moderation_state')->value;
    if ($definition !== NULL) {
      try {
        $oldState = (string) $definition->getTypePlugin()->getState($stateId)->label();
      }
      catch (\Throwable) {
        $oldState = $stateId;
      }
    }
  }
  $mismatch['historical_state'] = $oldState;
}

$partialNode = $storage->load((int) ($cases['archived']['nid'] ?? $page->id()));
$partialId = $reviews->create([
  'entity_type' => 'node',
  'entity_id' => (int) $partialNode->id(),
  'revision_id' => (int) $partialNode->getRevisionId(),
  'langcode' => 'zh-hans',
  'bundle' => $partialNode->bundle(),
  'fingerprint' => 'lab-page-fix-partial-' . $partialNode->id(),
  'field_hash' => 'lab-page-fix-partial',
  'model_id' => 0,
  'provider_id' => '',
  'model_name' => '',
  'rule_version' => 1,
  'source' => 'seo_optimization',
  'depth' => 'seo',
  'title' => (string) $partialNode->label(),
  'uid' => 1,
]);
$partialResult = [
  'summary' => '实验室建议统计',
  'items' => [
    ['key' => 'title', 'label' => '标题', 'applicable' => TRUE, 'state' => 'pending', 'current' => '甲', 'suggested' => '乙'],
    ['key' => 'summary', 'label' => '简介', 'applicable' => TRUE, 'state' => 'adopted', 'current' => '甲', 'suggested' => '乙'],
    ['key' => 'tags', 'label' => '标签', 'applicable' => TRUE, 'state' => 'rejected', 'current' => '甲', 'suggested' => '乙'],
    ['key' => 'alt', 'label' => '图片说明', 'applicable' => TRUE, 'state' => 'failed', 'current' => '甲', 'suggested' => '乙'],
    ['key' => 'body', 'label' => '正文', 'applicable' => TRUE, 'state' => 'conflict', 'current' => '甲', 'suggested' => '乙'],
  ],
];
$reviews->update($partialId, [
  'status' => 'completed',
  'seo_score' => 55,
  'summary' => '实验室建议统计',
  'result' => json_encode($partialResult, JSON_UNESCAPED_UNICODE),
  'completed' => \Drupal::time()->getRequestTime(),
]);

// Extra list/detail fixtures for labeled list UI and Chinese issue labels.
$listId = $reviews->create([
  'entity_type' => 'node',
  'entity_id' => (int) $partialNode->id(),
  'revision_id' => (int) $partialNode->getRevisionId(),
  'langcode' => 'zh-hans',
  'bundle' => $partialNode->bundle(),
  'fingerprint' => 'lab-center-list-' . $partialNode->id(),
  'field_hash' => 'lab-center-list',
  'model_id' => 0,
  'provider_id' => 'deepseek',
  'model_name' => 'deepseek-flash',
  'rule_version' => 1,
  'source' => 'seo_optimization',
  'depth' => 'seo',
  'title' => '海边风景手机壁纸高清下载实验室超长标题用来验证截断与完整 title 提示',
  'uid' => 1,
]);
$listResult = [
  'summary' => '实验室列表',
  'issues' => ['页面描述偏短', '标题可更具体', '缺少内部链接', '标签相关性不足', 'ALT 可补充'],
  'issue_items' => [
    ['severity' => 'high', 'field' => 'description', 'message' => '页面描述偏短，应补充可检索信息。'],
    ['severity' => 'medium', 'field' => 'title', 'message' => '标题可更具体。'],
  ],
  'items' => [
    ['key' => 'title', 'label' => 'SEO 标题', 'applicable' => TRUE, 'state' => 'pending', 'current' => '甲', 'suggested' => '乙', 'priority' => 'high'],
    ['key' => 'summary', 'label' => '页面描述', 'applicable' => TRUE, 'state' => 'pending', 'current' => '甲', 'suggested' => '乙', 'priority' => 'high'],
  ],
  'score_rules' => ['SEO 标题 20/20。', '页面描述 6/20。', '已检查 65/95，换算 68 分。'],
];
$reviews->update($listId, [
  'status' => 'completed',
  'seo_score' => 68,
  'summary' => '实验室列表',
  'issues' => json_encode($listResult['issues'], JSON_UNESCAPED_UNICODE),
  'result' => json_encode($listResult, JSON_UNESCAPED_UNICODE),
  'completed' => \Drupal::time()->getRequestTime(),
]);
$usageFields = [
  'created' => \Drupal::time()->getRequestTime(),
  'source' => 'seo_optimization',
  'provider_id' => 'deepseek',
  'model_name' => 'deepseek-flash',
  'status' => 'success',
  'prompt_tokens' => 1000,
  'completion_tokens' => 520,
  'total_tokens' => 1520,
  'duration_ms' => 12,
  'cost_micro' => 3574,
  'cost_known' => 1,
  'error_code' => '',
  'error_message' => '',
  'review_id' => $listId,
  'uid' => 1,
  'call_state' => 'finished',
  'entity_id' => (int) $partialNode->id(),
  'revision_id' => (int) $partialNode->getRevisionId(),
  'usage_reported' => 1,
  'cache_read_tokens' => 0,
  'http_status' => 200,
  'finish_reason' => 'stop',
  'retry_index' => 0,
  'cost_status' => 'known',
];
$schema = \Drupal::database()->schema();
foreach (array_keys($usageFields) as $column) {
  if (!$schema->fieldExists('xedc_ai_usage', $column)) {
    unset($usageFields[$column]);
  }
}
\Drupal::database()->insert('xedc_ai_usage')->fields($usageFields)->execute();

file_put_contents('/tmp/xedc-seo-center-meta.json', json_encode([
  'node13' => $node13,
  'usage_before' => $usageBefore,
  'page_nid' => (int) $page->id(),
  'page_vid' => (int) $page->getRevisionId(),
  'fixture' => $fixture,
  'worker' => $worker,
  'cases' => $cases,
  'mismatch' => $mismatch,
  'partial' => $partialId,
  'list_review' => $listId,
  'schema' => (int) \Drupal::keyValue('system.schema')->get('xedc_ai'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "LAB READY\n";
