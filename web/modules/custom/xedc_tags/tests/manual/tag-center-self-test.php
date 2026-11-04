<?php

/**
 * @file
 * Creates temporary tags and one photo, then merges, rolls back and deletes them.
 *
 * Run: ./vendor/bin/drush php:script web/modules/custom/xedc_tags/tests/manual/tag-center-self-test.php
 */

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\Role;

$failures = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$failures): void {
  echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? ' ' . $detail : '') . PHP_EOL;
  if (!$ok) {
    $failures[] = $name;
  }
};

$token = 'XEDCTEST' . bin2hex(random_bytes(4));
$created = [];
$node = NULL;

try {
  $editor = Role::load('content_editor');
  $check('editor can view the center', $editor && $editor->hasPermission('view xedc tag center'));
  $check('editor can apply recommendations', $editor && $editor->hasPermission('apply xedc tag recommendations'));
  $check('editor cannot merge', $editor && !$editor->hasPermission('merge xedc tags'));
  $check('editor cannot delete tags', $editor && !$editor->hasPermission('delete xedc tags'));
  $anonymous = Role::load('anonymous');
  $check('anonymous cannot open the center', $anonymous && !$anonymous->hasPermission('view xedc tag center'));

  $source = Term::create([
    'vid' => 'tags',
    'name' => $token . ' Source',
    'langcode' => 'zh-hans',
  ]);
  $source->save();
  $target = Term::create([
    'vid' => 'tags',
    'name' => $token . ' Target',
    'langcode' => 'zh-hans',
    'description' => ['value' => '人工描述保留', 'format' => 'plain_text'],
  ]);
  $target->save();
  $created = [(int) $source->id(), (int) $target->id()];

  $node = Node::create([
    'type' => 'photo',
    'title' => $token . ' photo',
    'uid' => 1,
    'status' => 1,
    'moderation_state' => 'published',
    'field_tags' => [
      ['target_id' => $source->id()],
      ['target_id' => $target->id()],
    ],
  ]);
  $node->save();
  $draft = Node::create([
    'type' => 'photo',
    'title' => $token . ' draft',
    'uid' => 1,
    'status' => 0,
    'moderation_state' => 'draft',
    'field_tags' => [['target_id' => $source->id()]],
  ]);
  $draft->save();

  $catalog = \Drupal::service('xedc_tags.catalog');
  \Drupal::service('cache_tags.invalidator')->invalidateTags($catalog->cacheTags());
  $rows = $catalog->terms();
  $check('source is counted', isset($rows[$source->id()]) && (int) $rows[$source->id()]['all'] >= 1);
  $check('draft is not published', (int) $rows[$source->id()]['published_any'] === 1);
  $check('target description is kept in the catalog', !empty($rows[$target->id()]['description']));

  $preview = \Drupal::service('xedc_tags.merger')->preview([(int) $source->id(), (int) $target->id()], (int) $target->id());
  $check('target cannot also be a source', !$preview['ok']);
  $models = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'models']);
  $model = $models ? reset($models) : NULL;
  if ($model) {
    $cross = \Drupal::service('xedc_tags.merger')->preview([(int) $source->id()], (int) $model->id());
    $check('other vocabulary is rejected', !$cross['ok']);
  }

  $result = \Drupal::service('xedc_tags.merger')->execute([(int) $source->id()], (int) $target->id(), 1);
  $check('merge executes', $result['ok'], $result['message']);
  $reloaded = Node::load($node->id());
  $ids = array_map('intval', array_column($reloaded->get('field_tags')->getValue(), 'target_id'));
  $check('published node points at the target once', $ids === [(int) $target->id()] || (in_array((int) $target->id(), $ids, TRUE) && !in_array((int) $source->id(), $ids, TRUE) && count(array_unique($ids)) === count($ids)));
  $check('source has no content references', \Drupal::service('xedc_tags.guard')->contentCount((int) $source->id()) === 0);
  $check('target description was not replaced', Term::load($target->id())->get('description')->value === '人工描述保留');
  $draftReloaded = Node::load($draft->id());
  $draftIds = array_map('intval', array_column($draftReloaded->get('field_tags')->getValue(), 'target_id'));
  $check('draft moved without being published', !(int) $draftReloaded->isPublished() && in_array((int) $target->id(), $draftIds, TRUE) && !in_array((int) $source->id(), $draftIds, TRUE));

  $rollback = \Drupal::service('xedc_tags.merger')->rollback((int) $result['id'], 1);
  $check('rollback restores the source tag', $rollback['ok'], $rollback['message']);
  $restored = array_map('intval', array_column(Node::load($node->id())->get('field_tags')->getValue(), 'target_id'));
  sort($restored);
  $expected = [(int) $source->id(), (int) $target->id()];
  sort($expected);
  $check('original tags are back', $restored === $expected, implode(',', $restored));

  $draft->delete();
  $node->delete();
  $node = NULL;
  $blocked = \Drupal::service('xedc_tags.guard')->contentCount((int) $source->id());
  $check('delete guard is clear after the nodes are gone', $blocked === 0);
  $source->delete();
  $target->delete();
  $created = [];

  $text = \Drupal::service('xedc_tags.recommender')->suggest('海边的照片', '正文没有其他词', '', [], 'photo');
  $names = array_column($text, 'name');
  $check('recommendation can find an existing tag', in_array('海边', $names, TRUE), implode(',', $names));

  $relations = \Drupal::service('xedc_tags.relations')->topPairs(5, TRUE);
  $check('relation query runs', is_array($relations));
  $seo = \Drupal::service('xedc_tags.seo')->all();
  $check('seo checks every tag', count($seo) >= 30);
}
catch (Throwable $e) {
  $failures[] = 'exception';
  echo 'FAIL exception ' . $e->getMessage() . PHP_EOL;
  echo $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}
finally {
  if ($node) {
    $node->delete();
  }
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $leftover = $storage->getQuery()->accessCheck(FALSE)->condition('title', $token, 'CONTAINS')->execute();
  if ($leftover) {
    $storage->delete($storage->loadMultiple($leftover));
  }
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  foreach ($created as $tid) {
    $term = $terms->load($tid);
    if ($term) {
      $term->delete();
    }
  }
}

echo $failures ? 'SELF TEST FAIL ' . implode(', ', $failures) . PHP_EOL : 'SELF TEST PASS' . PHP_EOL;
return $failures ? 1 : 0;
