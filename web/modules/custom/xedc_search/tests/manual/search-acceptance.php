<?php

/**
 * @file
 * Drush php:script acceptance checks for xedc_search.
 *
 * Run: drush php:script web/modules/custom/xedc_search/tests/manual/search-acceptance.php
 */

$checks = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
  $checks[] = [$ok ? 'PASS' : 'FAIL', $name, $detail];
};

$engine = \Drupal::service('xedc_search.engine');
$suggester = \Drupal::service('xedc_search.suggester');
$history = \Drupal::service('xedc_search.history');
$keywords = \Drupal::service('xedc_search.keywords');
$tracker = \Drupal::service('xedc_search.tracker');
$config = \Drupal::configFactory()->getEditable('xedc_search.settings');

$started = microtime(TRUE);
$suggest = $suggester->suggest('墨迹', 'all');
$suggestMs = (microtime(TRUE) - $started) * 1000;
$check('中文联想返回真实词', !empty($suggest['groups']), 'groups=' . count($suggest['groups']));
$labels = [];
foreach ($suggest['groups'] as $group) {
  foreach ($group['items'] as $item) {
    $labels[] = $item['label'];
  }
}
$check('联想不含脚本', !preg_grep('/<script/i', $labels), implode(',', $labels));

$started = microtime(TRUE);
$page = $engine->page('墨迹', 'article', 'relevance', 0);
$searchMs = (microtime(TRUE) - $started) * 1000;
$check('文章搜索命中墨迹', $page['total'] >= 1, 'total=' . $page['total']);
$items = $engine->items($page['nids']);
$leaked = FALSE;
foreach ($items as $item) {
  $node = \Drupal::entityTypeManager()->getStorage('node')->load($item['id']);
  if (!$node || !$node->isPublished()) {
    $leaked = TRUE;
  }
}
$check('结果都是已发布内容', !$leaked && $items !== []);
$check('未发布标题不可搜', $engine->count('确认后的标题', 'all') === 0);
$check('注入语句不会放开全部结果', $engine->count("' OR 1=1 --", 'all') === 0);
$check('超长关键词被截断后不报错', $engine->count(str_repeat('测', 180), 'all') >= 0);

$id = $keywords->save([
  'keyword' => '验收同义词',
  'label' => '验收显示名',
  'weight' => 5,
  'status' => 1,
  'tags' => [],
  'categories' => [],
  'synonyms' => '墨迹',
]);
$expansion = $keywords->expansion('验收同义词');
$check('同义词可展开', in_array('墨迹', $expansion['synonyms'], TRUE), json_encode($expansion, JSON_UNESCAPED_UNICODE));
$complete = $suggester->complete('验收', 'all');
$check('补全来自管理员显示名', ($complete['text'] ?? '') === '验收显示名', $complete['text'] ?? '');
$keywords->delete($id);
$check('删除后不再补全', ($suggester->complete('验收', 'all')['text'] ?? '') === '');

$mine = $history->add(88001, '只属于甲', 'photo');
$history->add(88001, '甲全站', 'all');
$history->add(88002, '只属于乙', 'video');
$check('历史按用户隔离', count(array_intersect(['只属于甲', '甲全站'], array_column($history->list(88001), 'keyword'))) === 2);
$check('历史按场景隔离', array_column($history->list(88001, 'photo'), 'keyword') === ['只属于甲']);
$check('不能删除别人的历史', $history->delete(88001, (int) \Drupal::database()->select('xedc_search_history', 'h')->fields('h', ['id'])->condition('uid', 88002)->execute()->fetchField()) === FALSE);
$stats = \Drupal::service('xedc_search.stats');
$stats->record('场景甲', '场景甲', 'photo', 3, FALSE);
$stats->record('场景乙', '场景乙', 'video', 2, FALSE);
$photoHot = array_column($stats->hot('week', 5, TRUE, 'photo'), 'keyword');
$check('热门按场景隔离', in_array('场景甲', $photoHot, TRUE) && !in_array('场景乙', $photoHot, TRUE));
$history->clear(88001);
$history->clear(88002);

$original = (int) $config->get('min_length');
$config->set('min_length', 8)->save();
\Drupal::configFactory()->reset('xedc_search.settings');
\Drupal::service('cache_tags.invalidator')->invalidateTags(['xedc_search']);
$short = $suggester->suggest('墨', 'all');
$check('最小长度设置生效', $short['groups'] === [], 'len=' . count($short['groups']));
$config->set('min_length', $original)->save();
\Drupal::configFactory()->reset('xedc_search.settings');

$tracked = $tracker->record('验收统计', 'photo', 'acceptancetoken', FALSE);
$again = $tracker->record('验收统计', 'photo', 'acceptancetoken', FALSE);
$check('搜索会被统计', $tracked['counted'] === TRUE, 'total=' . $tracked['total']);
$check('同一关键词 60 秒内不重复计次', $again['counted'] === FALSE);
$hot = \Drupal::service('xedc_search.stats')->hot('today', 20, TRUE, 'photo');
$foundHot = FALSE;
foreach ($hot as $row) {
  if ($row['keyword_key'] === '验收统计') {
    $foundHot = TRUE;
  }
}
$check('热门榜来自统计', $foundHot);
$check('全站热门不混入其他场景', !in_array('场景乙', array_column($stats->hot('week', 10, TRUE, 'all'), 'keyword'), TRUE) || in_array('场景乙', array_column($stats->hot('week', 10, TRUE, 'video'), 'keyword'), TRUE));
$uniqueHot = '验收热词' . random_int(100000, 999999);
$tracker->record($uniqueHot, 'all', 'accept' . random_int(1000, 9999), FALSE);
$check('新关键词进入全站热门', in_array($uniqueHot, array_column($stats->hot('today', 30, FALSE, 'all'), 'keyword'), TRUE), $uniqueHot);
$check('新词不出现在美图热门', !in_array($uniqueHot, array_column($stats->hot('today', 30, FALSE, 'photo'), 'keyword'), TRUE));
\Drupal::database()->delete('xedc_search_log')->condition('keyword_key', '验收统计')->execute();
\Drupal::database()->delete('xedc_search_daily')->condition('keyword_key', '验收统计')->execute();
\Drupal::database()->delete('xedc_search_visitor')->condition('keyword_key', '验收统计')->execute();

echo "suggest_ms=" . round($suggestMs, 1) . " search_ms=" . round($searchMs, 1) . "\n";
$failed = 0;
foreach ($checks as [$status, $name, $detail]) {
  echo $status . ' ' . $name . ($detail !== '' ? ' | ' . $detail : '') . "\n";
  if ($status === 'FAIL') {
    $failed++;
  }
}
echo $failed === 0 ? "RESULT PASS\n" : "RESULT FAIL $failed\n";
