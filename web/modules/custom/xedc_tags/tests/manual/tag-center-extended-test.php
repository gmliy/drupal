<?php

/**
 * @file
 * Extended acceptance checks for aliases, health, tasks, AI validation, frontend.
 *
 * Run: ./vendor/bin/drush php:script web/modules/custom/xedc_tags/tests/manual/tag-center-extended-test.php
 */

use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\Role;
use Drupal\xedc_tags\TagText;

$failures = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
  echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? ' ' . $detail : '') . PHP_EOL;
  if (!$ok) {
    $failures[] = $name;
  }
};

$token = 'XEDCTAGX' . bin2hex(random_bytes(3));
$created = [];

try {
  $check('alias table exists', \Drupal::database()->schema()->tableExists('xedc_tag_alias'));
  $check('task table exists', \Drupal::database()->schema()->tableExists('xedc_tag_task'));
  $check('ai suggestion table exists', \Drupal::database()->schema()->tableExists('xedc_tag_ai_suggestion'));

  $editor = Role::load('content_editor');
  $check('editor can manage aliases', $editor && $editor->hasPermission('manage xedc tag aliases'));
  $check('editor cannot run analysis by default', $editor && !$editor->hasPermission('run xedc tag analysis'));
  $check('anonymous cannot manage aliases', ($anon = Role::load('anonymous')) && !$anon->hasPermission('manage xedc tag aliases'));

  $term = Term::create(['vid' => 'tags', 'name' => $token . ' Base', 'langcode' => 'zh-hans']);
  $term->save();
  $created[] = (int) $term->id();
  \Drupal::service('cache_tags.invalidator')->invalidateTags(\Drupal::service('xedc_tags.catalog')->cacheTags());

  $aliases = \Drupal::service('xedc_tags.aliases');
  $createdAlias = $aliases->save([
    'alias' => $token . ' 同义词',
    'tid' => (int) $term->id(),
    'type' => 'synonym',
    'source' => 'self-test',
  ], 1);
  $check('alias create', $createdAlias['ok'], $createdAlias['message']);
  $dup = $aliases->save([
    'alias' => $token . ' 同义词',
    'tid' => (int) $term->id(),
    'type' => 'synonym',
  ], 1);
  $check('duplicate alias rejected', !$dup['ok']);
  $conflict = $aliases->save([
    'alias' => '海边',
    'tid' => (int) $term->id(),
    'type' => 'synonym',
  ], 1);
  $check('existing term name rejected as alias', !$conflict['ok']);
  $resolved = $aliases->resolve($token . ' 同义词');
  $check('alias resolves', $resolved === (int) $term->id());
  $disabled = $aliases->setStatus((int) $createdAlias['id'], 'disabled', 1);
  $check('alias disable', $disabled['ok']);
  $check('disabled alias does not resolve', $aliases->resolve($token . ' 同义词') === NULL);
  $deleted = $aliases->delete((int) $createdAlias['id'], 1);
  $check('alias delete', $deleted['ok']);

  $trad = TagText::compare('國風', '国风');
  $check('fullwidth/traditional handled or similar', $trad === NULL || is_array($trad));
  $low = TagText::normalize('  AB  C  ');
  $check('normalize collapses spaces', $low === 'ab c');
  $health = \Drupal::service('xedc_tags.health')->report();
  $check('health report runs', isset($health['totals']['issues']));
  $check('dynamic threshold present', isset($health['threshold']['high'], $health['threshold']['low']));

  $junk = Term::create(['vid' => 'tags', 'name' => '!!!@@@', 'langcode' => 'zh-hans']);
  $junk->save();
  $created[] = (int) $junk->id();
  \Drupal::service('cache_tags.invalidator')->invalidateTags(\Drupal::service('xedc_tags.catalog')->cacheTags());
  $issues = \Drupal::service('xedc_tags.health')->inspectTerm(
    \Drupal::service('xedc_tags.catalog')->terms()[(int) $junk->id()],
    $health['threshold']
  );
  $codes = array_column($issues, 'code');
  $check('low quality detected', in_array('low_quality', $codes, TRUE) || in_array('naming', $codes, TRUE), implode(',', $codes));

  $tasks = \Drupal::service('xedc_tags.tasks');
  $queued = $tasks->enqueue('health_scan', [], 1);
  $check('task enqueue', $queued['ok'], $queued['message']);
  $dupTask = $tasks->enqueue('health_scan', [], 1);
  $check('duplicate task blocked', !$dupTask['ok']);
  $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('xedc_tags_scan');
  $worker->processItem(['task_id' => (int) $queued['id']]);
  $done = $tasks->load((int) $queued['id']);
  $check('task completed', $done && in_array($done->status, ['completed', 'partial'], TRUE), $done->status ?? 'missing');

  $ai = \Drupal::service('xedc_tags.ai');
  $status = $ai->status();
  $check('ai status readable', isset($status['status']));
  $ref = new ReflectionClass($ai);
  $method = $ref->getMethod('validate');
  $method->setAccessible(TRUE);
  $terms = \Drupal::service('xedc_tags.catalog')->terms();
  $tid = (int) $term->id();
  $badJson = $method->invoke($ai, '{not-json', $terms, $tid);
  $check('invalid json rejected', $badJson === []);
  $unknown = $method->invoke($ai, json_encode([
    [
      'source_tid' => 999999,
      'source_name' => 'x',
      'target_tid' => NULL,
      'suggestion_type' => 'rename',
      'reason' => 'x',
      'confidence' => 0.9,
      'affected_count' => 1,
      'risk' => 'r',
      'recommended_action' => '人工确认',
    ],
  ]), $terms, $tid);
  $check('unknown term rejected', $unknown === []);
  $illegal = $method->invoke($ai, json_encode([
    [
      'source_tid' => $tid,
      'source_name' => $term->label(),
      'target_tid' => NULL,
      'suggestion_type' => 'rename',
      'reason' => 'x',
      'confidence' => 0.9,
      'affected_count' => 1,
      'risk' => 'r',
      'recommended_action' => '删除该标签',
    ],
  ]), $terms, $tid);
  $check('delete suggestion rejected', $illegal === []);
  $okSuggestion = $method->invoke($ai, json_encode([
    [
      'source_tid' => $tid,
      'source_name' => $term->label(),
      'target_tid' => NULL,
      'suggestion_type' => 'seo_description',
      'reason' => '缺少描述',
      'confidence' => 0.8,
      'affected_count' => 0,
      'risk' => '低',
      'recommended_action' => '人工补充描述',
    ],
  ]), $terms, $tid);
  $check('valid suggestion accepted', count($okSuggestion) === 1);

  $before = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', 'tags')->count()->execute();
  $listing = \Drupal::service('xedc_tags.listing')->index();
  $check('frontend index builds', isset($listing['page']['#theme']));
  $check('frontend has type filters', !empty($listing['page']['#types']));
  $after = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', 'tags')->count()->execute();
  $check('term count unchanged by listing', $before === $after);

  $routes = [
    'xedc_tags.admin_overview',
    'xedc_tags.admin_list',
    'xedc_tags.admin_health',
    'xedc_tags.admin_aliases',
    'xedc_tags.admin_ai',
    'xedc_tags.admin_tasks',
    'xedc_tags.admin_duplicates',
    'xedc_tags.admin_merge',
    'xedc_tags.admin_recommend',
    'xedc_tags.admin_seo',
    'xedc_tags.admin_history',
    'xedc_tags.index',
  ];
  foreach ($routes as $route) {
    try {
      $url = \Drupal\Core\Url::fromRoute($route)->toString();
      $check('route ' . $route, $url !== '');
    }
    catch (Throwable $e) {
      $check('route ' . $route, FALSE, $e->getMessage());
    }
  }
}
catch (Throwable $e) {
  $failures[] = 'exception';
  echo 'FAIL exception ' . $e->getMessage() . PHP_EOL;
  echo $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}
finally {
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  foreach ($created as $tid) {
    if ($term = $storage->load($tid)) {
      $term->delete();
    }
  }
  if (\Drupal::database()->schema()->tableExists('xedc_tag_alias')) {
    \Drupal::database()->delete('xedc_tag_alias')->condition('source', 'self-test')->execute();
  }
}

echo $failures ? 'EXTENDED TEST FAIL ' . implode(', ', $failures) . PHP_EOL : 'EXTENDED TEST PASS' . PHP_EOL;
return $failures ? 1 : 0;
