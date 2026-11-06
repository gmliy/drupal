<?php

/**
 * @file
 * Mock SEO, token and cost checks. This does not boot Drupal or touch a database.
 */

declare(strict_types=1);

use Drupal\xedc_ai\Budget\Money;
use Drupal\xedc_ai\Client\ChatTransportInterface;
use Drupal\xedc_ai\Client\OpenAiChatClient;
use Drupal\xedc_ai\Client\TransportResult;
use Drupal\xedc_ai\Provider\ModelProfile;
use Drupal\xedc_ai\Review\ReviewParser;
use Drupal\xedc_ai\Seo\AltInspector;
use Drupal\xedc_ai\Seo\SeoResult;
use Drupal\xedc_ai\Usage\UsageReport;

$root = dirname(__DIR__, 2);
require $root . '/src/Client/Redactor.php';
require $root . '/src/Client/TransportResult.php';
require $root . '/src/Client/ChatTransportInterface.php';
require $root . '/src/Client/ChatResponse.php';
require $root . '/src/Provider/ModelProfile.php';
require $root . '/src/Client/OpenAiChatClient.php';
require $root . '/src/Budget/Money.php';
require $root . '/src/Review/ReviewParser.php';
require $root . '/src/Seo/AltInspector.php';
require $root . '/src/Seo/SeoResult.php';
require $root . '/src/Usage/UsageReport.php';

$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
  echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
  if (!$ok) {
    $failed++;
  }
};

$photo = [
  'known' => TRUE,
  'bundle' => 'photo',
  'title' => '很多很多很好经济学家的',
  'summary' => '一组城市夜景。',
  'body' => '',
  'categories' => ['城市'],
  'tags' => ['夜景'],
  'has_tags_field' => TRUE,
  'has_summary_field' => TRUE,
  'alts' => [['mid' => 9, 'alt' => '夜景', 'label' => 'cover']],
  'duration' => 0,
  'revision_id' => 215,
];

$json = '{"summary":"标题含糊，ALT 可以更具体。","issues":[{"field":"title","severity":"medium","message":"标题没有说明画面主题。","suggestion":"改成具体场景"}],"suggestions":[{"field":"title","current":"很多很多很好经济学家的","suggested":"城市夜景与街道灯光","reason":"标题需要可检索的主题","priority":"high","applicable":true}],"suggested_title":"城市夜景与街道灯光","suggested_description":"城市夜景图片，包含街道灯光和建筑轮廓。","suggested_keywords":["夜景","城市"],"suggested_alt":[{"media_id":9,"current":"夜景","suggested":"城市街道夜景灯光","reason":"ALT 应描述画面"}],"internal_link_suggestions":[{"anchor":"更多城市图片","target":"城市栏目","reason":"相关栏目"}],"content_quality":"描述偏短。","seo_score":"不是分数","tags":["夜景"],"stuffing":false}';
$parsed = ReviewParser::seo($json, $photo);
$check($parsed['ok'] === TRUE && $parsed['result']['seo_title'] === '城市夜景与街道灯光', '正常 JSON 解析出建议标题');
$check($parsed['result']['summary'] !== '' && $parsed['result']['issues'] !== [] && $parsed['result']['items'] !== [], '摘要、问题和建议都存在');
$check($parsed['result']['seo_score'] !== 85 && $parsed['result']['analysis_metadata']['model_score_ignored'] === '不是分数', '评分不采用模型自报数字');
$check($parsed['result']['seo_score'] >= 0 && $parsed['result']['seo_score'] <= 100, '评分在 0 到 100');
$titleItem = NULL;
foreach ($parsed['result']['items'] as $item) {
  if ($item['key'] === 'title') {
    $titleItem = $item;
  }
}
$check(is_array($titleItem) && $titleItem['current'] === '很多很多很好经济学家的' && $titleItem['applicable'] === TRUE && $titleItem['state'] === 'pending', '标题建议带当前值、适用和待采纳');
$keyword = NULL;
foreach ($parsed['result']['items'] as $item) {
  if ($item['key'] === 'keywords') {
    $keyword = $item;
  }
}
$check(is_array($keyword) && $keyword['applicable'] === FALSE, '关键词只作为建议，不标记为可写入');
$tagParsed = ReviewParser::seo('{"summary":"有摘要","issues":[],"suggestions":[{"field":"tags","current":"手机","suggested":"夜景,城市","reason":"补充已有标签","priority":"medium","applicable":true}],"tags":["夜景"]}', $photo);
$tagItem = NULL;
foreach ($tagParsed['result']['items'] as $item) {
  if ($item['key'] === 'tags') {
    $tagItem = $item;
  }
}
$check(is_array($tagItem) && $tagItem['suggested'] === '夜景、城市' && $tagItem['applicable'] === TRUE, '标签建议按词拆开，不把逗号串当成一个术语');

$fenced = ReviewParser::seo("说明\n```json\n{$json}\n```\n", $photo);
$check($fenced['ok'] === TRUE && $fenced['result']['seo_title'] === '城市夜景与街道灯光', 'Markdown JSON 可以解析');
$check(ReviewParser::seo('这不是 JSON', $photo)['ok'] === FALSE, '非法 JSON 失败');
$check(ReviewParser::seo('{"summary":"","issues":[],"suggestions":[]}', $photo)['ok'] === FALSE, '空结果不会成功');
$check(ReviewParser::seo('{"summary":"有摘要","issues":"坏的","suggestions":[]}', $photo)['ok'] === FALSE, '问题字段类型错误会失败');
$missing = ReviewParser::seo('{"issues":[{"message":"标题空","severity":"high"}],"suggestions":[]}', $photo);
$check($missing['ok'] === TRUE && $missing['result']['analysis_metadata']['summary_missing'] === TRUE && str_contains($missing['result']['summary'], '模型未提供摘要'), '缺少摘要时明确标记，不伪装成没有问题');
$page = ReviewParser::seo($json, ['known' => TRUE, 'bundle' => 'page', 'title' => '关于', 'summary' => '', 'body' => '一段介绍。', 'has_summary_field' => FALSE, 'categories' => [], 'tags' => [], 'alts' => []]);
$summaryItem = NULL;
foreach ($page['result']['items'] as $item) {
  if ($item['key'] === 'summary') {
    $summaryItem = $item;
  }
}
$check(is_array($summaryItem) && $summaryItem['applicable'] === FALSE, '基础页没有简介字段时描述建议不适用');
$check(SeoResult::rejectedFinish('length') !== NULL && SeoResult::rejectedFinish('stop') === NULL, '截断响应不能当成成功');
$check(SeoResult::opsStatus('pending', 'open', [], FALSE) === 'queued', '等待分析状态');
$check(SeoResult::opsStatus('completed', 'open', $parsed['result'], FALSE) === 'awaiting', '有待采纳建议时是待人工确认');
$check(SeoResult::opsStatus('failed', 'open', [], FALSE) === 'failed', '分析失败状态');

$profile = new ModelProfile(1, 'deepseek', 'deepseek', 'Deepseek', 'deepseek-flash', 'https://api.deepseek.com', 'secret', 30, 128, '0.2', '0.02', '4', TRUE, '0.01');
$transport = new class implements ChatTransportInterface {
  public array $bodies = [];
  public array $sent = [];
  public int $index = 0;

  public function post(string $url, array $headers, string $body, int $timeout): TransportResult {
    $this->sent[] = $body;
    $payload = $this->bodies[$this->index] ?? ['status' => 200, 'body' => '{"choices":[{"message":{"content":"{}"},"finish_reason":"stop"}]}'];
    $this->index++;
    return new TransportResult((int) $payload['status'], (string) $payload['body'], (string) ($payload['error'] ?? ''), (string) ($payload['message'] ?? ''));
  }
};
$client = new OpenAiChatClient($transport);
$transport->bodies = [
  ['status' => 200, 'body' => '{"choices":[{"message":{"content":"{\"summary\":\"ok\"}"},"finish_reason":"stop"}],"usage":{"prompt_tokens":248,"completion_tokens":80,"prompt_cache_hit_tokens":40}}'],
];
$ok = $client->once($profile, [['role' => 'user', 'content' => 'ping']], NULL, NULL, TRUE);
$check($ok->ok && $ok->usageReported && $ok->promptTokens === 248 && $ok->completionTokens === 80 && $ok->cacheReadTokens === 40 && $ok->finishReason === 'stop', '记录输入、输出、缓存 Token');
$sentJson = json_decode((string) ($transport->sent[0] ?? ''), TRUE);
$check(is_array($sentJson) && ($sentJson['thinking']['type'] ?? '') === 'disabled' && ($sentJson['response_format']['type'] ?? '') === 'json_object', 'JSON 模式关闭思考，避免推理占满输出额度');
$transport->bodies[] = ['status' => 200, 'body' => '{"choices":[{"message":{"content":"{\"summary\":\"从推理中取出\"}"},"finish_reason":"stop","reasoning_content":"ignore"}]}'];
$plain = $client->once($profile, [['role' => 'user', 'content' => 'ping']]);
$check($plain->ok && $plain->usedReasoning === FALSE, '正文优先于推理文本');
$plainSent = json_decode((string) ($transport->sent[1] ?? ''), TRUE);
$check(is_array($plainSent) && !isset($plainSent['thinking']), '非 JSON 调用不改动思考模式');
$transport->bodies[] = ['status' => 200, 'body' => '{"choices":[{"message":{"content":null,"reasoning_content":"思考 {\"summary\":\"从推理中取出\",\"issues\":[],\"suggestions\":[]}"},"finish_reason":"stop"}]}'];
$reasoning = $client->once($profile, [['role' => 'user', 'content' => 'ping']]);
$check($reasoning->ok && $reasoning->usedReasoning === TRUE && str_contains($reasoning->content, 'summary'), '正文为空且正常结束时可以从推理文本取出 JSON');
$transport->bodies[] = ['status' => 200, 'body' => '{"choices":[{"message":{"content":""},"finish_reason":"length"}],"usage":{"prompt_tokens":10,"completion_tokens":128}}'];
$truncated = $client->once($profile, [['role' => 'user', 'content' => 'ping']]);
$check(!$truncated->ok && $truncated->errorCode === 'truncated' && $truncated->usageReported && $truncated->promptTokens === 10, '空正文且被截断时失败并保留 Token');
$transport->bodies[] = ['status' => 200, 'body' => '{"choices":[{"message":{"content":"{}"},"finish_reason":"stop"}]}'];
$noUsage = $client->once($profile, [['role' => 'user', 'content' => 'ping']]);
$check($noUsage->ok && $noUsage->usageReported === FALSE && $noUsage->promptTokens === 0, '没有 usage 时不把 Token 记成已确认的 0');
$transport->bodies[] = ['status' => 429, 'body' => '{"error":{"message":"rate limit"},"usage":{"prompt_tokens":3,"completion_tokens":0}}'];
$limited = $client->once($profile, [['role' => 'user', 'content' => 'ping']]);
$check(!$limited->ok && $limited->errorCode === 'rate_limit' && $limited->promptTokens === 3, '429 仍保留已返回的 Token');
$transport->bodies[] = ['status' => 500, 'body' => '{"error":{"message":"unavailable"}}'];
$down = $client->once($profile, [['role' => 'user', 'content' => 'ping']]);
$check(!$down->ok && $down->errorCode === 'unavailable', '500 记为失败');
$transport->bodies[] = ['status' => 0, 'body' => '', 'error' => 'timeout', 'message' => '超时'];
$timeout = $client->once($profile, [['role' => 'user', 'content' => 'ping']]);
$check(!$timeout->ok && $timeout->errorCode === 'timeout' && $timeout->usageReported === FALSE, '超时没有伪造 Token');

$quote = Money::quote(248, 3136, 0, '0.02', '4', '');
$check($quote['status'] === 'known' && $quote['total'] === 12548, '按模型单价核算已知调用');
$check(Money::quote(0, 0, 0, '0.02', '4', '')['status'] === 'zero', '费用为零');
$check(Money::quote(100, 20, 0, '', '', '')['status'] === 'unpriced', '单价未配置');
$check(Money::quote(100, 20, 0, 'abc', '4', '')['status'] === 'failed', '费用计算失败');
$cache = Money::quote(100, 0, 40, '1.00', '2.00', '');
$check($cache['status'] === 'cache_unpriced' && $cache['total'] === 60, '缓存命中部分在单价缺失时单独标记');
$cachePriced = Money::quote(100, 0, 40, '1.00', '2.00', '0.10');
$check($cachePriced['status'] === 'known' && $cachePriced['cache'] === 4 && $cachePriced['input'] === 60, '缓存读取按缓存单价计算');

$pending = UsageReport::summarize([], 'pending', '');
$check($pending['token_label'] === '尚未调用' && $pending['cost_label'] === '尚未调用', '未调用不显示 Token 0');
$failedBeforeCall = UsageReport::summarize([], 'failed', 'exception');
$check($failedBeforeCall['token_label'] === '尚未调用' && $failedBeforeCall['cost_label'] === '尚未调用', '调用前失败不显示 Token 未返回');
$missingUsage = UsageReport::summarize([[
  'id' => 1, 'call_state' => 'finished', 'status' => 'success', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'usage_reported' => 0, 'cost_known' => 0, 'cost_micro' => 0, 'provider_id' => 'deepseek', 'model_name' => 'deepseek-flash',
]], 'completed', '');
$check($missingUsage['token_label'] === 'Token 未返回', 'usage 缺失显示 Token 未返回');
$recorded = UsageReport::summarize([
  ['id' => 1, 'call_state' => 'finished', 'status' => 'error', 'prompt_tokens' => 3, 'completion_tokens' => 0, 'usage_reported' => 1, 'cost_status' => 'known', 'cost_known' => 1, 'cost_micro' => 1, 'provider_id' => 'deepseek', 'model_name' => 'deepseek-flash', 'retry_index' => 0],
  ['id' => 2, 'call_state' => 'finished', 'status' => 'success', 'prompt_tokens' => 10, 'completion_tokens' => 4, 'usage_reported' => 1, 'cost_status' => 'known', 'cost_known' => 1, 'cost_micro' => 5, 'provider_id' => 'deepseek', 'model_name' => 'deepseek-flash', 'retry_index' => 1],
], 'completed', '');
$check($recorded['total_tokens'] === 17 && $recorded['cost_micro'] === 6 && count($recorded['calls']) === 2, '重试和失败调用分别累计');
$legacy = UsageReport::summarize([[
  'id' => 9, 'call_state' => 'finished', 'status' => 'success', 'prompt_tokens' => 248, 'completion_tokens' => 3136, 'cost_known' => 1, 'cost_micro' => 12548, 'provider_id' => 'deepseek', 'model_name' => 'deepseek-flash',
]], 'completed', '');
$check(str_contains($legacy['token_label'], '3384') && str_contains($legacy['cost_label'], '已核算'), '旧用量行仍能显示已记录 Token 和已核算费用');
$legacyColumn = UsageReport::summarize([[
  'id' => 10, 'call_state' => 'finished', 'status' => 'success', 'prompt_tokens' => 275, 'completion_tokens' => 1958, 'usage_reported' => 0, 'cost_status' => '', 'cost_known' => 1, 'cost_micro' => 7837, 'provider_id' => 'deepseek', 'model_name' => 'deepseek-flash',
]], 'completed', '');
$check(str_contains($legacyColumn['token_label'], '2233') && str_contains($legacyColumn['cost_label'], '已核算'), '结构更新后的旧行默认 0 仍视为已返回 Token');
$explicitMissing = UsageReport::summarize([[
  'id' => 11, 'call_state' => 'finished', 'status' => 'success', 'prompt_tokens' => 4, 'completion_tokens' => 0, 'usage_reported' => 0, 'cost_status' => 'no_usage', 'cost_known' => 0, 'cost_micro' => 0,
]], 'completed', '');
$check($explicitMissing['token_label'] === 'Token 未返回', '明确标记未返回的 usage 不会被旧数据规则改回已记录');

$article = [
  'bundle' => 'article',
  'title' => '城市夜景摄影指南',
  'summary' => '这篇指南介绍城市夜景摄影的曝光和构图。',
  'body' => str_repeat('城市夜景摄影需要稳定的曝光和构图。', 6) . ' <a href="/node/1">更多教程</a>',
  'tags' => ['夜景'],
  'categories' => ['摄影'],
  'has_tags_field' => TRUE,
  'has_summary_field' => TRUE,
  'has_body_field' => TRUE,
  'meta_description' => '这篇指南介绍城市夜景摄影的曝光和构图，适合入门读者。',
  'alts' => [],
  'entity_id' => 41,
  'revision_id' => 9,
];
$scoreA = SeoResult::score($article, [], FALSE);
$scoreB = SeoResult::score($article, [['type' => 'content', 'severity' => 'high', 'field' => 'title', 'message' => '模型问题', 'suggestion' => '']], TRUE);
$check($scoreA['score'] === 100 && $scoreA === $scoreB, '完整文章按维度得 100，模型问题不改变分数，重复计算稳定');
$check(SeoResult::score([], [], FALSE)['score'] === 0, '未检查项目不是默认满分');
$photoScore = SeoResult::score($photo + ['meta_description' => '很多很多很好经济学家的 - 壁纸', 'entity_id' => 13, 'revision_id' => 215], [], FALSE);
$photoRules = implode("\n", $photoScore['rules']);
$check(str_contains($photoRules, '内部链接 不适用，不计入分母'), '美图的内部链接不计入分母');
$pageScore = SeoResult::score([
  'bundle' => 'page',
  'title' => '关于本站',
  'summary' => '',
  'body' => str_repeat('本站介绍页面说明编辑流程和联系方式。', 4),
  'tags' => [],
  'categories' => [],
  'has_tags_field' => FALSE,
  'has_body_field' => TRUE,
  'meta_description' => '本站介绍页面说明编辑流程和联系方式，供读者查阅。',
  'alts' => [],
], [], FALSE);
$pageRules = implode("\n", $pageScore['rules']);
$check(str_contains($pageRules, '标签相关性 不适用') && str_contains($pageRules, '图片 ALT 不适用'), '基础页没有标签和图片时这两项不进分母');
$short = SeoResult::score(['bundle' => 'article', 'title' => '短', 'body' => '', 'meta_description' => '', 'has_tags_field' => TRUE, 'tags' => [], 'alts' => [], 'has_body_field' => TRUE], [], FALSE);
$shortRules = implode("\n", $short['rules']);
$check($short['score'] < 100 && str_contains($shortRules, '标题短于 4 个字') && str_contains($shortRules, 'SEO 标题 8/20'), '短标题显示具体扣分原因');
$split = SeoResult::splitTerms("手机、壁纸\n海边，风景,手机");
$check($split === ['手机', '壁纸', '海边', '风景'], '标签按逗号、顿号和换行拆开并去重');
$legacyTags = SeoResult::itemRows(['items' => [[
  'key' => 'tags', 'suggested' => '手机、壁纸、海边、风景、手机,壁纸,海边,风景', 'state' => 'pending', 'applicable' => TRUE,
]]]);
$check(($legacyTags[0]['tag_names'] ?? []) === ['手机', '壁纸', '海边', '风景'] && $legacyTags[0]['state_label'] === '待确认', '历史标签串在读取时拆开，状态显示待确认');
$allowed = ['title', 'summary', 'tags'];
$check(SeoResult::chosenKeys(NULL, $allowed) === [], '空选择不产生建议 key');
$check(SeoResult::chosenKeys(['k1' => 0, 'k2' => 'title', 'k3' => 0], $allowed) === ['title'], '只识别勾选的一条建议');
$check(SeoResult::chosenKeys(['a' => 'summary', 'b' => 'title', 'c' => 'tags'], $allowed) === ['summary', 'title', 'tags'], '多选保留全部已勾选 key');
$check(SeoResult::chosenKeys(['a' => 'title', 'b' => 'title', 'c' => 'not-a-key', 'd' => ''], $allowed) === ['title'], '取消勾选、重复值和越权 key 都被丢掉');
$stored = ['seo_score' => 46, 'score' => 46, 'items' => [['key' => 'title', 'suggested' => '旧标题', 'state' => 'pending', 'applicable' => TRUE]]];
$hydrated = SeoResult::hydrate($stored);
$check((int) $hydrated['seo_score'] === 46 && (int) $hydrated['score'] === 46 && ($hydrated['items'][0]['state'] ?? '') === 'pending', '读取历史分析不改评分和建议状态');
$snapshots = [
  'article' => SeoResult::sourceSnapshot([
    'bundle' => 'article', 'title' => '夏季笔记', 'summary' => '一篇关于夏天的短文', 'body' => '正文提到海边。', 'raw_body' => '<p>正文提到<a href="/node/9">海边</a>。</p>',
    'tags' => ['夏天'], 'categories' => ['随笔'], 'alts' => [['mid' => 4, 'alt' => '海边']],
    'has_summary_field' => TRUE, 'has_body_field' => TRUE, 'has_tags_field' => TRUE, 'has_category_field' => TRUE, 'has_duration_field' => FALSE, 'has_image_field' => TRUE,
    'duration' => 0, 'entity_id' => 9, 'revision_id' => 90, 'langcode' => 'zh-hans',
  ]),
  'photo' => SeoResult::sourceSnapshot([
    'bundle' => 'photo', 'title' => '窗边', 'summary' => '', 'body' => '', 'raw_body' => '',
    'tags' => [], 'categories' => ['壁纸'], 'alts' => [['mid' => 2, 'alt' => '']],
    'has_summary_field' => TRUE, 'has_body_field' => FALSE, 'has_tags_field' => TRUE, 'has_category_field' => TRUE, 'has_duration_field' => FALSE, 'has_image_field' => TRUE,
    'entity_id' => 8, 'revision_id' => 80, 'langcode' => 'zh-hans',
  ]),
  'video' => SeoResult::sourceSnapshot([
    'bundle' => 'video', 'title' => '课程', 'summary' => '', 'body' => '讲了三件事', 'raw_body' => '<p>讲了三件事</p>',
    'tags' => ['课程'], 'categories' => [], 'alts' => [], 'duration' => 95,
    'has_summary_field' => FALSE, 'has_body_field' => TRUE, 'has_tags_field' => TRUE, 'has_category_field' => TRUE, 'has_duration_field' => TRUE, 'has_image_field' => TRUE,
    'entity_id' => 7, 'revision_id' => 70, 'langcode' => 'zh-hans',
  ]),
  'page' => SeoResult::sourceSnapshot([
    'bundle' => 'page', 'title' => '关于', 'summary' => '', 'body' => '', 'raw_body' => '',
    'tags' => [], 'categories' => [], 'alts' => [],
    'has_summary_field' => FALSE, 'has_body_field' => TRUE, 'has_tags_field' => FALSE, 'has_category_field' => FALSE, 'has_duration_field' => FALSE, 'has_image_field' => FALSE,
    'entity_id' => 6, 'revision_id' => 60, 'langcode' => 'zh-hans',
  ]),
];
$statusOf = static function (array $snapshot, string $label): string {
  foreach ($snapshot['rows'] as $row) {
    if ($row['label'] === $label) {
      return $row['status'] . '|' . $row['value'];
    }
  }
  return '';
};
$check($statusOf($snapshots['article'], '原始标题') === '已读取|夏季笔记' && str_contains($statusOf($snapshots['article'], '内部链接'), '/node/9'), '文章快照保留标题和正文里的站内链接');
$check($statusOf($snapshots['photo'], '正文提取摘要') === '字段不存在|' && $statusOf($snapshots['photo'], '原始简介') === '内容为空|' && str_starts_with($statusOf($snapshots['photo'], 'ALT'), '已读取|0/1'), '美图没有正文，空简介和空 ALT 不会显示成已分析正文');
$check($statusOf($snapshots['video'], '原始简介') === '字段不存在|' && $statusOf($snapshots['video'], '视频时长') === '已读取|95 秒' && $statusOf($snapshots['video'], '当前栏目') === '内容为空|', '视频没有简介字段，时长按实际秒数记录');
$check($statusOf($snapshots['page'], '当前标签') === '字段不存在|' && $statusOf($snapshots['page'], '图片数量') === '字段不存在|' && $statusOf($snapshots['page'], '视频时长') === '不适用|' && $statusOf($snapshots['page'], '正文提取摘要') === '内容为空|', '基础页标出不存在的标签、图片和时长');
$check(!isset($snapshots['article']['raw_body']) && $snapshots['article']['not_sent'] === ['图片文件', '视频文件'], '快照不保存正文原文和媒体文件');

$alt = AltInspector::inspect([
  ['mid' => 1, 'fid' => 1, 'alt' => NULL, 'filename' => 'IMG_20261105_001.jpg', 'field' => 'field_images'],
  ['mid' => 2, 'fid' => 2, 'alt' => '', 'filename' => 'street-night.jpg', 'field' => 'field_images'],
  ['mid' => 3, 'fid' => 3, 'alt' => '   ', 'filename' => 'a.jpg', 'field' => 'field_images'],
  ['mid' => 4, 'fid' => 4, 'alt' => '夜景街道', 'filename' => 'kept.jpg', 'field' => 'field_cover'],
  ['mid' => 5, 'fid' => 5, 'alt' => '夜景街道', 'filename' => 'copy.jpg', 'field' => 'field_images'],
  ['mid' => 6, 'fid' => 6, 'alt' => str_repeat('很长的替代文本', 20), 'filename' => 'long.jpg', 'field' => 'field_images'],
  ['mid' => 7, 'fid' => 7, 'alt' => '12345', 'filename' => 'n.jpg', 'field' => 'field_images'],
  ['mid' => 8, 'fid' => 8, 'alt' => 'jpg', 'filename' => 'x.jpg', 'field' => 'field_images'],
  ['mid' => 9, 'fid' => 9, 'alt' => '图片', 'filename' => 'p.jpg', 'field' => 'field_images'],
  ['mid' => 10, 'fid' => 10, 'alt' => 'a8f3k0qq', 'filename' => 'rand.jpg', 'field' => 'field_images'],
  ['mid' => 11, 'fid' => 11, 'alt' => '', 'filename' => 'spacer.png', 'field' => 'field_images', 'decorative' => FALSE],
  ['mid' => 12, 'fid' => 11, 'alt' => '重复文件', 'filename' => 'spacer.png', 'field' => 'field_images'],
  ['mid' => 13, 'fid' => 13, 'alt' => '', 'filename' => '日本街头.jpg', 'field' => 'field_images'],
], '日本街头写真', 'photo');
$byMid = [];
foreach ($alt['rows'] as $row) {
  $byMid[$row['mid']] = $row;
}
$check($alt['tokens'] === 0 && $alt['local'] === TRUE, 'ALT 检查 Token 为 0');
$check(!isset($byMid[12]), '同一文件在同一内容里只计一次');
$check($byMid[1]['status'] === 'missing' && $byMid[1]['candidate'] === '日本街头写真 - 图片 1', '无意义文件名时用标题和序号');
$check($byMid[2]['candidate'] === '日本街头写真 - street-night', '有意义的英文文件名可以进入候选');
$check($byMid[3]['status'] === 'missing', '只有空格视为缺失');
$check($byMid[4]['keep'] === TRUE && $byMid[4]['candidate'] === '', '有效 ALT 不生成覆盖候选');
$keptAgain = AltInspector::inspect([['mid' => 4, 'fid' => 4, 'alt' => '夜景街道', 'filename' => 'kept.jpg']], '另一个标题', 'article');
$check($keptAgain['rows'][0]['keep'] === TRUE && $keptAgain['rows'][0]['candidate'] === '', '修改标题不会覆盖已有有效 ALT');
$check($byMid[5]['status'] === 'duplicate', '同一内容里重复的 ALT 被标出');
$check($byMid[6]['status'] === 'long' && $byMid[7]['status'] === 'numeric' && $byMid[8]['status'] === 'extension', '过长、纯数字和扩展名被标为异常');
$check($byMid[9]['status'] === 'placeholder' && $byMid[10]['status'] === 'random', '占位词和无意义字符被标为异常');
$check($byMid[11]['status'] === 'decorative' && $byMid[11]['candidate'] === '', '装饰性图片保留空 ALT');
$check($byMid[13]['candidate'] === '日本街头写真 - 日本街头', '中文文件名可以进入候选');
$videoAlt = AltInspector::inspect([['mid' => 20, 'fid' => 20, 'alt' => NULL, 'filename' => 'cover.jpg']], '课程录像', 'video');
$check($videoAlt['rows'][0]['candidate'] === '课程录像', '视频封面候选使用标题，不拼接序号');
$none = AltInspector::inspect([], '关于', 'page');
$check($none['total'] === 0 && AltInspector::points([], '关于', 'page')['status'] === 'na', '没有图片时 ALT 不进评分分母');
$same = AltInspector::inspect([['mid' => 30, 'fid' => 30, 'alt' => '日本街头写真 - 图片 1', 'filename' => 'IMG_1.jpg']], '日本街头写真', 'photo');
$check($same['rows'][0]['candidate'] === '' && $same['candidates'] === 0, '已经等于规则结果时不重复生成');
$shared = AltInspector::inspect([['mid' => 40, 'fid' => 40, 'alt' => '', 'filename' => 'a.jpg', 'shared' => 2]], '共享图', 'article');
$check(str_contains($shared['rows'][0]['reason'], '其他 2 个内容'), '共享媒体在建议里说明影响范围');
$batch = [];
for ($i = 1; $i <= 500; $i++) {
  $batch[] = ['mid' => $i, 'fid' => $i, 'alt' => $i % 4 === 0 ? NULL : '图片说明' . $i, 'filename' => 'IMG_' . $i . '.jpg'];
}
$started = hrtime(true);
$bulk = AltInspector::inspect($batch, '批量', 'article');
$elapsed = (hrtime(true) - $started) / 1e6;
$check($bulk['total'] === 500 && $bulk['tokens'] === 0 && $elapsed < 1000, '500 张图片本地检查在 1 秒内完成，Token 为 0');
$check($bulk['missing'] === 125 && $bulk['valid'] === 375, '500 张里每四张有一张缺失，其余有效且不重复');
$merged = SeoResult::mergeLocalAlt(['items' => [['key' => 'title', 'state' => 'pending']]], $alt);
$mergedKeys = array_column($merged['items'], 'key');
$check(in_array('alt:1', $mergedKeys, TRUE) && in_array('title', $mergedKeys, TRUE), '本地 ALT 候选会成为可单独采纳的建议');
$check(!in_array('alt:4', $mergedKeys, TRUE), '有效 ALT 不会变成覆盖建议');
$again = SeoResult::mergeLocalAlt($merged, $alt);
$check(count(array_filter(array_column($again['items'], 'key'), static fn (string $key): bool => $key === 'alt:1')) === 1, '同一条本地 ALT 建议不会重复加入');

$matrix = [
  ['published', 1, 0, 0, TRUE, TRUE, 'keep'],
  ['draft', 1, 0, 0, TRUE, TRUE, 'keep'],
  ['needs_review', 1, 0, 0, TRUE, TRUE, 'keep'],
  ['changes_requested', 1, 0, 0, TRUE, TRUE, 'keep'],
  ['rejected', 1, 0, 0, TRUE, TRUE, 'keep'],
  ['archived', 1, 0, 0, TRUE, TRUE, 'republish'],
  ['archived', 1, 0, 0, FALSE, TRUE, 'denied'],
  ['archived', 0, 1, 0, TRUE, TRUE, 'apply_failed'],
  ['archived', 1, 0, 1, TRUE, TRUE, 'conflict'],
  ['archived', 1, 0, 0, TRUE, FALSE, 'no_transition'],
];
foreach ($matrix as [$state, $adopted, $failedCount, $conflict, $allowed, $transitionOk, $expect]) {
  $actual = SeoResult::republishDecision($state, $adopted, $failedCount, $conflict, $allowed, $transitionOk);
  $check($actual === $expect, '重新发布决策 ' . $state . ' => ' . $expect);
}

echo $failed === 0 ? "OK {$failed} failed\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
