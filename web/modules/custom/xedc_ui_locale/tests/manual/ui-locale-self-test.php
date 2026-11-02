<?php

/**
 * @file
 * Regression checks for interface translation and content translation.
 *
 * Run with: drush php:script web/modules/custom/xedc_ui_locale/tests/manual/ui-locale-self-test.php
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\xedc_auto_translate\Translator;
use Drupal\xedc_ui_locale\InterfaceStringExtractor;
use Drupal\xedc_ui_locale\LocaleTranslationWriter;
use Drupal\xedc_ui_locale\ProtectedTermGuard;
use Drupal\xedc_ui_locale\TranslationTaskRepository;
use Drupal\xedc_ui_locale\TranslationValidator;
use Drupal\xedc_ui_locale\UiLocale;
use Drupal\xedc_ui_locale\UiTranslationManager;

if (!class_exists(UiTranslationManager::class)) {
  fwrite(STDERR, "Drupal is not bootstrapped.\n");
  exit(1);
}

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
  if (!$condition) {
    $failures[] = $message;
    fwrite(STDERR, "FAIL {$message}\n");
    return;
  }
  fwrite(STDOUT, "OK {$message}\n");
};

$extractor = new InterfaceStringExtractor();
$fixture = DRUPAL_ROOT . '/modules/custom/xedc_ui_locale/tests/fixtures/ui_sample';
$found = [];
foreach ($extractor->extractDirectory($fixture) as $item) {
  $found[$item['context'] . "\n" . $item['source']] = $item['source'];
}
$check(in_array('Save configuration', $found, TRUE), '收集按钮文字');
$check(in_array('Sample menu', $found, TRUE), '收集菜单标题');
$check(in_array('Administer UI sample', $found, TRUE), '收集权限名称');
$check(in_array('Sample page', $found, TRUE), '收集路由标题');
$check(in_array('Refresh preview', $found, TRUE), '收集 Twig 字符串');
$check(isset($found["Greeting\nHello @name"]), '保留字符串上下文');
$check(!in_array('ui_sample.page', $found, TRUE), '不收集路由机器名');
$check(!in_array('node.photo', $found, TRUE), '不收集内容字段名');
$meta = $extractor->readMeta($fixture, 'ui_sample');
$check($meta['label'] === 'UI Sample', '读取模块项目名称');
$check($meta['package'] === 'drupal/ui_sample', '读取 Composer 包名');
$check(!in_array('UI Sample', $found, TRUE), '项目名称不进入普通字符串列表');

$guard = \Drupal::service(ProtectedTermGuard::class);
$validator = new TranslationValidator();
$terms = $guard->termsForModule(['UI Sample', 'ui_sample', 'drupal/ui_sample']);
$check($guard->isExactTerm('UI Sample', $terms), '完整项目名称受保护');
$check($guard->isExactTerm('DcLili', $terms), 'DcLili 受保护');
$check(!$guard->isExactTerm('Save configuration', $terms), '按钮文字不会因为项目名称被跳过');
[$shielded, $map] = $guard->shield('Save the DcLili @name', $terms);
$restored = $guard->unshield(str_replace('Save the', '保存', $shielded), $map);
$check($restored === '保存 DcLili @name', '品牌和占位符可以还原');
$check($validator->validate('Save @name', '保存 @name', $terms, $guard) === NULL, '占位符完整时通过校验');
$check($validator->validate('Save @name', '保存', $terms, $guard) === '占位符丢失：@name', '占位符缺失时拒绝');
$check($validator->validate('Read <em>this</em>', '阅读 this', $terms, $guard) === 'HTML 结构不一致', 'HTML 被破坏时拒绝');

$module_file = file_get_contents(DRUPAL_ROOT . '/modules/custom/xedc_auto_translate/xedc_auto_translate.module');
$translator_file = file_get_contents(DRUPAL_ROOT . '/modules/custom/xedc_auto_translate/src/Translator.php');
$check(is_string($module_file) && str_contains($module_file, '->translateEntity($entity)'), '内容发布仍调用 translateEntity');
$check(is_string($translator_file) && !str_contains($translator_file, 'xedc_ui_locale'), '内容翻译类不依赖界面翻译');
$reflection = new ReflectionClass(Translator::class);
$check(in_array('text_with_summary', $reflection->getReflectionConstant('TEXT_TYPES')->getValue(), TRUE), '正文字段类型仍在映射中');
$check(in_array('path', $reflection->getReflectionConstant('SKIP_FIELDS')->getValue(), TRUE), '路径字段仍被跳过');

$config = \Drupal::config('xedc_auto_translate.settings');
$bundles = $config->get('bundles') ?? [];
$check(in_array('node.photo', $bundles, TRUE), '图片内容包仍在翻译范围');
$check(in_array('node.video', $bundles, TRUE), '视频内容包仍在翻译范围');
$check(in_array('node.article', $bundles, TRUE), '文章内容包仍在翻译范围');
$check($config->get('source_langcode') === 'zh-hans', '内容翻译源语言仍是简体中文');
$check($config->get('overwrite') === FALSE, '内容翻译默认不覆盖已有译文');

$translator = \Drupal::service('xedc_auto_translate.translator');
$manager = \Drupal::service(UiTranslationManager::class);
$check($translator instanceof Translator, '内容翻译服务类没有被替换');
$check($translator->apiKey() !== '', '现有 API Key 仍可从 State 读到');
$node = Node::create([
  'type' => 'article',
  'title' => 'baseline do not save',
  'langcode' => 'zh-hans',
  'status' => 0,
]);
$check($translator->translateEntity($node) === [], '未保存的文章不会触发翻译');
$user = User::load(1);
$check($user && $translator->translateEntity($user) === [], '不在字段映射中的实体不会触发翻译');

$editable = \Drupal::configFactory()->getEditable('xedc_auto_translate.settings');
$original_provider = (string) $editable->get('provider');
try {
  $editable->set('provider', $original_provider === 'microsoft' ? 'deepl' : 'microsoft')->save();
  $switched = $translator->provider();
  $check($switched !== $original_provider, '更换提供商后界面翻译读取同一配置');
  $check($manager->platform()['provider'] === $switched, '界面翻译没有第二套提供商配置');
}
finally {
  $editable->set('provider', $original_provider)->save();
}
$check($translator->provider() === $original_provider, '测试后恢复原来的翻译提供商');

$writer = \Drupal::service(LocaleTranslationWriter::class);
$repository = \Drupal::service(TranslationTaskRepository::class);
$transaction = \Drupal::database()->startTransaction();
try {
  $human = 'XEDC_UI_KEEP_' . \Drupal::time()->getRequestTime();
  $check($writer->apply($human, 'test', '人工译文', LOCALE_CUSTOMIZED, FALSE) === 'written', '可以写入一条新的界面译文');
  $check($writer->apply($human, 'test', '机器译文', LOCALE_NOT_CUSTOMIZED, TRUE) === 'kept', '人工译文不会被覆盖');
  $check($writer->currentText($human, 'test') === '人工译文', '人工译文保持原值');
  $ai = 'XEDC_UI_AI_' . \Drupal::time()->getRequestTime();
  $check($writer->apply($ai, '', '临时AI', LOCALE_NOT_CUSTOMIZED, FALSE) === 'written', '可以写入 AI 译文');
  $repository->upsertItem('xedc_ui_locale', $ai, '', [
    'source' => $ai,
    'context' => '',
    'translation' => '临时AI',
    'origin' => 'ai',
    'status' => 'completed',
    'reason' => '',
  ]);
  $check($writer->apply($ai, '', '官方译文', LOCALE_NOT_CUSTOMIZED, TRUE) === 'replaced', '官方译文可以替换本系统的 AI 译文');
  $check($writer->currentText($ai, '') === '官方译文', '替换后保存的是官方译文');
  $repository->markBySource($ai, '', [
    'origin' => 'official',
    'status' => 'completed',
    'translation' => '官方译文',
    'reason' => '',
  ]);
  $check($writer->apply($ai, '', '另一条官方', LOCALE_NOT_CUSTOMIZED, TRUE) === 'kept', '已经不是 AI 来源的译文不会再次覆盖');
}
finally {
  $transaction->rollBack();
}

xedc_ui_locale_modules_installed(['xedc_ui_locale_missing']);
$missing = $repository->getJob('xedc_ui_locale_missing');
$check(($missing['status'] ?? '') === 'failed', '启用钩子会创建任务，未知模块记为失败');
\Drupal::database()->delete('xedc_ui_locale_job')->condition('module', 'xedc_ui_locale_missing')->execute();
\Drupal::database()->delete('xedc_ui_locale_log')->condition('module', 'xedc_ui_locale_missing')->execute();
\Drupal::database()->delete('xedc_ui_locale_item')->condition('module', 'xedc_ui_locale_missing')->execute();

$real = $translator->translatePlainTexts(['Save configuration'], 'text');
$check(isset($real[0]) && is_string($real[0]) && preg_match('/\p{Han}/u', $real[0]) === 1, '真实 API 把 Save configuration 译成中文：' . ($real[0] ?? ''));

if ($failures !== []) {
  fwrite(STDERR, count($failures) . " failed.\n");
  exit(1);
}
fwrite(STDOUT, "All self-test checks passed.\n");
