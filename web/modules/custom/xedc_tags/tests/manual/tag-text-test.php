<?php

/**
 * @file
 * Rule checks for tag name comparison. No Drupal bootstrap.
 */

use Drupal\xedc_tags\TagText;

require __DIR__ . '/../../src/TagText.php';

$failures = 0;
$check = function (string $name, bool $ok) use (&$failures): void {
  if ($ok) {
    echo "PASS $name\n";
    return;
  }
  $failures++;
  echo "FAIL $name\n";
};

$same = TagText::compare('ABC', 'abc');
$check('case', $same && $same['recommend_merge'] && in_array('大小写不同', $same['reasons'], TRUE));

$width = TagText::compare('ABC', 'ＡＢＣ');
$check('width', $width && $width['recommend_merge'] && in_array('全角半角不同', $width['reasons'], TRUE));

$space = TagText::compare('A  B', 'A B');
$check('space', $space && $space['recommend_merge']);

$trim = TagText::compare(' ABC', 'ABC');
$check('trim', $trim && $trim['recommend_merge'] && in_array('首尾空格', $trim['reasons'], TRUE));

$order = TagText::compare('美女写真', '写真美女');
$check('order is not a merge', $order && !$order['recommend_merge'] && in_array('词序不同', $order['reasons'], TRUE));

$contain = TagText::compare('美女', '美女热舞');
$check('contain is not a merge', $contain && !$contain['recommend_merge'] && in_array('名称存在包含关系', $contain['reasons'], TRUE));

$different = TagText::compare('高清写真', '性感写真');
$check('different phrases stay apart', $different === NULL);

$near = TagText::compare('墨迹', '墨迹2');
$check('suffix stays a review item', $near && !$near['recommend_merge']);

echo $failures === 0 ? "TAG TEXT PASS\n" : "TAG TEXT FAIL $failures\n";
exit($failures === 0 ? 0 : 1);
