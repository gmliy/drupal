<?php
require '/var/www/drupal/web/modules/custom/xedc_ai/src/Labels.php';
use Drupal\xedc_ai\Labels;
$checks = [
  Labels::level('high') === '高',
  Labels::level('critical') === '严重',
  Labels::field('description') === '页面描述',
  Labels::field('title') === 'SEO 标题',
  Labels::field('alt') === '图片 ALT',
  Labels::field('keywords') === '关键词',
  Labels::field('internal_links') === '内部链接',
  Labels::seoOps('partial') === '部分已采纳',
  Labels::itemState('applied') === '已采纳',
  Labels::bundle('page') === '页面',
];
$display = Labels::level('high') . ' · ' . Labels::field('description');
echo ($display === '高 · 页面描述' && !in_array(FALSE, $checks, TRUE) ? 'LABELS_OK ' : 'LABELS_FAIL ') . $display . PHP_EOL;
