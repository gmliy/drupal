<?php

/**
 * @file
 * Mocked checks for the AI operations center.
 *
 * Run with: drush php:script web/modules/custom/xedc_ai/tests/manual/ai-self-test.php
 *
 * This script never prints an API key and never calls a real model endpoint.
 */

declare(strict_types=1);

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\xedc_ai\Budget\Money;
use Drupal\xedc_ai\Client\ChatTransportInterface;
use Drupal\xedc_ai\Client\GuzzleChatTransport;
use Drupal\xedc_ai\Client\OpenAiChatClient;
use Drupal\xedc_ai\Client\Redactor;
use Drupal\xedc_ai\Client\TransportResult;
use Drupal\xedc_ai\Form\ProviderDeleteForm;
use Drupal\xedc_ai\Form\SettingsForm;
use Drupal\xedc_ai\Provider\ModelProfile;
use Drupal\xedc_ai\Provider\ProviderCatalog;
use Drupal\xedc_ai\Review\Fingerprint;
use Drupal\xedc_ai\Review\PerceptualHash;
use Drupal\xedc_ai\Review\PromptBuilder;
use Drupal\xedc_ai\Review\ReviewParser;
use Drupal\xedc_ai\Review\TextRules;
use Drupal\xedc_ai\Seo\SeoAdvisor;
use Drupal\xedc_auto_translate\Translator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

if (!class_exists(ReviewParser::class)) {
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

$secret = 'sk-xedc-ai-test-secret';
$safe = static function (string $text) use ($secret): string {
  return str_replace($secret, '[secret]', $text);
};

if (getenv('XEDC_AI_KEY_AI_TEST')) {
  fwrite(STDERR, "FAIL 环境变量 XEDC_AI_KEY_AI_TEST 已存在，测试中止。\n");
  exit(1);
}

$presets = ProviderCatalog::presets();
foreach (['deepseek', 'openai', 'dashscope', 'siliconflow', 'volcengine', 'zhipu', 'moonshot', 'custom'] as $driver) {
  $check(isset($presets[$driver]), '供应商预设 ' . $driver);
}
$check(ProviderCatalog::validBaseUrl('https://api.example.test/v1'), '接受 HTTPS 地址');
$check(ProviderCatalog::validBaseUrl('http://127.0.0.1:4010/v1'), '接受本机 HTTP');
$check(!ProviderCatalog::validBaseUrl('http://example.test/v1'), '拒绝非本机 HTTP');
$check(Redactor::text('Bearer ' . $secret . ' and ' . $secret) === 'Bearer [redacted] and sk-[redacted]', '日志文本会去掉密钥');

$parsed = ReviewParser::review('说明 {"status":"pass","risk_level":"low","score":90,"issues":[],"suggestions":["保持标题"],"seo_score":85,"summary":"文字通过"}');
$check($parsed['ok'] === TRUE && $parsed['result']['score'] === 90, '解析审核 JSON');
$fenced = ReviewParser::review("```json\n{\"status\":\"attention\",\"risk_level\":\"medium\",\"score\":60,\"issues\":[\"标题短\"],\"suggestions\":[],\"seo_score\":50,\"summary\":\"需要看一眼\"}\n```");
$check($fenced['ok'] === TRUE && $fenced['result']['risk_level'] === 'medium', '解析代码块中的 JSON');
$check(ReviewParser::review('不是 JSON')['ok'] === FALSE, '无效 JSON 被拒绝');
$check(ReviewParser::review('{"status":"publish","risk_level":"low","score":1,"issues":[],"suggestions":[],"seo_score":1,"summary":"x"}')['ok'] === FALSE, '非法审核状态被拒绝');
$structured = ReviewParser::review('{"status":"review_required","risk_level":"low","score":85,"summary":"内容整体正常","issues":[{"type":"seo","severity":"low","field":"field_summary","message":"描述较短。","suggestion":"补充具体信息"}],"suggestions":[{"field":"field_summary","original":"","suggested":"更完整的简介","reason":"提高完整性"}]}');
$check($structured['ok'] === TRUE && $structured['result']['status'] === 'review_required' && $structured['result']['issue_items'][0]['field'] === 'field_summary', '结构化审核 JSON 可以通过校验');
$check(ReviewParser::review('我不能协助完成这个请求')['error'] === '模型拒绝回答。', '模型拒绝回答时不会当成可执行结果');
$emptyTitle = TextRules::assess(['bundle' => 'article', 'title' => '', 'summary' => '', 'body' => '', 'tags' => []]);
$emptyMessages = array_column($emptyTitle, 'message');
$check(in_array('标题为空。', $emptyMessages, TRUE) && in_array('描述为空。', $emptyMessages, TRUE), '空标题和空描述会被本地规则发现');
$check(\Drupal::hasService('xedc_ai.vision') && \Drupal::service('xedc_ai.vision')->enabled() === FALSE, '视觉审核默认关闭');
$seoParsed = ReviewParser::seo('{"seo_title":"新标题","meta_description":"新描述","tags":["风景"],"stuffing":false,"relevance":"相关","summary":"建议","issues":[],"suggestions":["可改标题"]}');
$check($seoParsed['ok'] === TRUE && $seoParsed['result']['seo_title'] === '新标题', '解析 SEO JSON');
$check(Money::costMicro(1000, 2000, '1.50', '3.00') === 7500, '按百万 Token 单价计算费用');
$check(Money::costMicro(10, 10, '', '1.00') === NULL, '缺少单价时不编造费用');

$profile = new ModelProfile(0, 'ai_test', 'custom', 'Test', 'unit-model', 'https://example.test/v1', $secret, 20, 128, '0.2', '1.00', '2.00', TRUE);
$check(str_contains(print_r($profile, TRUE), 'set') && !str_contains(print_r($profile, TRUE), $secret), '调试输出不包含密钥');

$clientCases = [
  'success' => [new Response(200, [], '{"choices":[{"message":{"content":"{\"status\":\"pass\"}"}}],"usage":{"prompt_tokens":4,"completion_tokens":6}}'), TRUE, ''],
  'auth' => [new Response(401, [], '{"error":{"message":"invalid api key ' . $secret . '"}}'), FALSE, 'auth'],
  'quota' => [new Response(402, [], '{"error":{"message":"insufficient quota"}}'), FALSE, 'insufficient_quota'],
  'rate' => [new Response(429, [], '{"error":{"message":"rate limit exceeded","type":"rate_limit"}}'), FALSE, 'rate_limit'],
  'missing_model' => [new Response(404, [], '{"error":{"message":"The model does not exist","code":"model_not_found"}}'), FALSE, 'model_not_found'],
  'bad_json' => [new Response(200, [], 'not-json'), FALSE, 'bad_response'],
  'bad_shape' => [new Response(200, [], '{"ok":true}'), FALSE, 'bad_response'],
  'unavailable' => [new Response(503, [], '{"error":{"message":"unavailable"}}'), FALSE, 'unavailable'],
];
foreach ($clientCases as $name => [$response, $ok, $code]) {
  $transport = new class($response) implements ChatTransportInterface {
    public function __construct(private Response $response) {}

    public function post(string $url, array $headers, string $body, int $timeout): TransportResult {
      return new TransportResult($this->response->getStatusCode(), (string) $this->response->getBody());
    }
  };
  $result = (new OpenAiChatClient($transport))->once($profile, [['role' => 'user', 'content' => 'ping']]);
  $check($result->ok === $ok && $result->errorCode === $code, '模型客户端 ' . $name);
  $check(!str_contains($result->errorMessage, $secret), '模型客户端 ' . $name . ' 的错误信息不含密钥');
}

$tokenTransport = new class implements ChatTransportInterface {
  public int $calls = 0;
  public string $second = '';

  public function post(string $url, array $headers, string $body, int $timeout): TransportResult {
    $this->calls++;
    if ($this->calls === 1) {
      return new TransportResult(400, '{"error":{"message":"Unsupported parameter: max_tokens"}}');
    }
    $this->second = $body;
    return new TransportResult(200, '{"choices":[{"message":{"content":"ok"}}],"usage":{"prompt_tokens":1,"completion_tokens":1}}');
  }
};
$tokenResult = (new OpenAiChatClient($tokenTransport))->once($profile, [['role' => 'user', 'content' => 'ping']]);
$check($tokenResult->ok && $tokenTransport->calls === 2 && str_contains($tokenTransport->second, 'max_completion_tokens'), 'max_tokens 不被接受时改用兼容参数');

$imageTransport = new class implements ChatTransportInterface {
  public string $body = '';

  public function post(string $url, array $headers, string $body, int $timeout): TransportResult {
    $this->body = $body;
    return new TransportResult(200, '{"choices":[{"message":{"content":"ok"}}],"usage":{"prompt_tokens":1,"completion_tokens":1}}');
  }
};
(new OpenAiChatClient($imageTransport))->once($profile, [
  ['role' => 'user', 'content' => 'only text'],
  ['role' => 'user', 'image_url' => 'data:image/png;base64,aaaa', 'content' => 'ignored'],
  ['role' => 'user', 'content' => ['type' => 'image_url']],
]);
$check(!str_contains($imageTransport->body, 'image_url') && !str_contains($imageTransport->body, 'base64'), '请求体只保留文字，不上传图片');

$mock = new MockHandler([
  new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://example.test/v1/chat/completions')),
  new Response(500, [], '{"error":{"message":"down"}}'),
]);
$guzzle = new GuzzleChatTransport(new Client(['handler' => HandlerStack::create($mock)]));
$timeout = $guzzle->post('https://example.test/v1/chat/completions', ['Authorization' => 'Bearer ' . $secret], '{}', 5);
$down = $guzzle->post('https://example.test/v1/chat/completions', [], '{}', 5);
$check($timeout->errorCode === 'timeout' && !str_contains($timeout->errorMessage, $secret), 'Guzzle 超时返回 timeout 且不含密钥');
$check($down->status === 500, 'Guzzle 保留 500 状态而不是抛出');

$rules = TextRules::assess([
  'bundle' => 'article',
  'title' => '短',
  'summary' => '',
  'body' => '太短',
  'tags' => [],
], [99], []);
$messages = array_column($rules, 'message');
$check(in_array('标题过短。', $messages, TRUE) && in_array('文章正文过短或不完整。', $messages, TRUE), '本地文字规则能发现短标题和短正文');
$left = Fingerprint::fieldHash(['title' => '同一篇', 'tags' => ['乙', '甲'], 'categories' => [], 'people' => []]);
$right = Fingerprint::fieldHash(['title' => '同一篇', 'tags' => ['甲', '乙'], 'categories' => [], 'people' => []]);
$changed = Fingerprint::fieldHash(['title' => '另一篇', 'tags' => ['甲', '乙'], 'categories' => [], 'people' => []]);
$check($left === $right && $left !== $changed, '字段哈希忽略标签顺序，标题变化会改变哈希');
$one = Fingerprint::make('node', 5, 8, 'zh-hans', $left, 'ai_test:a', 1, 'content_review', 'quick');
$two = Fingerprint::make('node', 5, 9, 'zh-hans', $left, 'ai_test:a', 1, 'content_review', 'quick');
$three = Fingerprint::make('node', 5, 8, 'zh-hans', $left, 'ai_test:b', 1, 'content_review', 'quick');
$check($one !== $two && $one !== $three, '版本或模型不同时指纹不同');

$png = static function (string $kind): string {
  $image = imagecreatetruecolor(64, 64);
  if ($kind === 'solid') {
    imagefill($image, 0, 0, imagecolorallocate($image, 20, 80, 160));
  }
  else {
    for ($y = 0; $y < 64; $y++) {
      for ($x = 0; $x < 64; $x++) {
        imagesetpixel($image, $x, $y, imagecolorallocate($image, $x * 4, $y * 3, (($x + $y) % 2) * 255));
      }
    }
  }
  ob_start();
  imagepng($image);
  imagedestroy($image);
  return (string) ob_get_clean();
};
$solid = $png('solid');
$pattern = $png('pattern');
$solidPath = tempnam(sys_get_temp_dir(), 'xedc-ai');
$patternPath = tempnam(sys_get_temp_dir(), 'xedc-ai');
file_put_contents($solidPath, $solid);
file_put_contents($patternPath, $pattern);
$solidHash = PerceptualHash::fromFile($solidPath);
$patternHash = PerceptualHash::fromFile($patternPath);
$check(is_string($solidHash) && strlen($solidHash) === 16, '本地 pHash 生成 16 位结果');
$check($solidHash !== NULL && PerceptualHash::distance($solidHash, (string) PerceptualHash::fromFile($solidPath)) === 0, '相同图片的 pHash 距离为 0');
$check($solidHash !== NULL && $patternHash !== NULL && PerceptualHash::distance($solidHash, $patternHash) > 10, '不同图片的 pHash 距离大于相似阈值');

$prompt = (new PromptBuilder())->messages([
  'bundle' => 'photo',
  'title' => '海边',
  'summary' => '一张海边的照片',
  'body' => '',
  'tags' => ['海边'],
  'categories' => [],
  'people' => [],
  'extra' => '',
], ['text' => [], 'files' => [], 'vision' => FALSE], 'quick', FALSE);
$promptText = json_encode($prompt, JSON_UNESCAPED_UNICODE) ?: '';
$check(!str_contains($promptText, 'image_url') && str_contains($promptText, '不要评价画面'), '提示词只包含文字且禁止画面判断');
$check(SeoAdvisor::blockedByTranslation(TRUE, TRUE, 'published', ['zh-hans'], ['en', 'ja']) !== NULL, '已发布且缺少译文时禁止写入');
$check(SeoAdvisor::blockedByTranslation(FALSE, TRUE, 'draft', ['zh-hans'], ['en']) === NULL, '未发布草稿允许在确认后写入');

$editable = \Drupal::configFactory()->getEditable('xedc_ai.settings');
$original = $editable->getRawData();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
$bag = [
  'nodes' => [],
  'media' => [],
  'files' => [],
  'users' => [],
  'paths' => [$solidPath, $patternPath],
];
$fixture = new class implements ChatTransportInterface {
  public int $calls = 0;
  public string $lastBody = '';
  public $responder;

  public function post(string $url, array $headers, string $body, int $timeout): TransportResult {
    $this->calls++;
    $this->lastBody = $body;
    $responder = $this->responder;
    return $responder($url, $headers, $body, $timeout);
  }
};
$fixture->responder = static function (string $url, array $headers, string $body, int $timeout): TransportResult {
  $payload = json_decode($body, TRUE);
  $model = is_array($payload) ? (string) ($payload['model'] ?? '') : '';
  if ($model === 'xedc-primary') {
    return new TransportResult(404, '{"error":{"message":"The model does not exist","code":"model_not_found"}}');
  }
  $content = str_contains($body, 'seo_title')
    ? '{"seo_title":"确认后的标题","meta_description":"确认后的描述","tags":["测试"],"stuffing":false,"relevance":"标题和正文一致","summary":"只是建议","issues":[],"suggestions":["可以改标题"]}'
    : '{"status":"pass","risk_level":"low","score":91,"issues":[],"suggestions":["无需修改"],"seo_score":84,"summary":"文字审核通过"}';
  return new TransportResult(200, json_encode([
    'choices' => [['message' => ['content' => $content]]],
    'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
  ], JSON_UNESCAPED_UNICODE));
};
\Drupal::getContainer()->set('xedc_ai.client', new OpenAiChatClient($fixture));

$mode = 'fallback';
$successBody = static function (string $body): string {
  $content = str_contains($body, 'seo_title')
    ? '{"seo_title":"确认后的标题","meta_description":"确认后的描述","tags":["测试"],"stuffing":false,"relevance":"标题和正文一致","summary":"只是建议","issues":[],"suggestions":["可以改标题"]}'
    : '{"status":"attention","risk_level":"medium","score":72,"issues":["简介可以更具体"],"suggestions":["补充简介"],"seo_score":70,"summary":"文字需要人工看一下"}';
  return json_encode([
    'choices' => [['message' => ['content' => $content]]],
    'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 9],
  ], JSON_UNESCAPED_UNICODE);
};
$fixture->responder = static function (string $url, array $headers, string $body, int $timeout) use (&$mode, $successBody, $secret): TransportResult {
  if (isset($headers['Authorization']) && str_contains((string) $headers['Authorization'], $secret)) {
    // The header stays in memory for the mock. It is not written anywhere.
  }
  $payload = json_decode($body, TRUE);
  $model = is_array($payload) ? (string) ($payload['model'] ?? '') : '';
  if ($mode === 'invalid') {
    return new TransportResult(200, '{"choices":[{"message":{"content":"这不是 JSON"}}],"usage":{"prompt_tokens":2,"completion_tokens":2}}');
  }
  if ($mode === 'rate') {
    return new TransportResult(429, '{"error":{"message":"rate limit exceeded","type":"rate_limit"}}');
  }
  if ($mode === 'fallback' && $model === 'xedc-primary') {
    return new TransportResult(404, '{"error":{"message":"The model does not exist","code":"model_not_found"}}');
  }
  if ($mode === 'auth') {
    return new TransportResult(401, '{"error":{"message":"invalid api key ' . $secret . '"}}');
  }
  return new TransportResult(200, $successBody($body));
};

$queueCount = static function (): int {
  return (int) \Drupal::database()->select('queue', 'q')
    ->condition('name', 'xedc_ai_review')
    ->countQuery()
    ->execute()
    ->fetchField();
};
$dropQueued = static function (int $reviewId): void {
  $database = \Drupal::database();
  $rows = $database->select('queue', 'q')
    ->fields('q', ['item_id', 'data'])
    ->condition('name', 'xedc_ai_review')
    ->execute();
  foreach ($rows as $row) {
    $data = unserialize($row->data, ['allowed_classes' => FALSE]);
    if (is_array($data) && (int) ($data['review_id'] ?? 0) === $reviewId) {
      $database->delete('queue')->condition('item_id', $row->item_id)->execute();
    }
  }
};
$runReview = static function (int $reviewId) use ($dropQueued): string {
  $dropQueued($reviewId);
  $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('xedc_ai_review');
  try {
    $worker->processItem(['review_id' => $reviewId]);
    return 'processed';
  }
  catch (DelayedRequeueException $exception) {
    return 'delayed';
  }
};

$setConfig = static function (array $values) use ($editable): void {
  foreach ($values as $name => $value) {
    $editable->set($name, $value);
  }
  $editable->save();
};

try {
  $check(\Drupal::service('plugin.manager.queue_worker')->hasDefinition('xedc_ai_review'), '队列工人已注册');
  $check(\Drupal::service('xedc_auto_translate.translator') instanceof Translator, '内容翻译服务类没有被替换');
  $cacheClass = get_class(\Drupal::service('cache.default'));
  $check(str_contains($cacheClass, 'Redis'), '默认缓存仍是 Redis：' . $cacheClass);
  $moderated = \Drupal::service('router.route_provider')->getRouteByName('view.moderated_content.moderated_content');
  $check($moderated->getPath() === '/admin/content/moderated', '原生内容审核路由仍是 /admin/content/moderated');
  $check(\Drupal::service('router.route_provider')->getRouteByName('audit.reports') instanceof \Symfony\Component\Routing\Route, 'SEO Audit 路由仍在');
  $check(\Drupal::service('router.route_provider')->getRouteByName('site_audit.report') instanceof \Symfony\Component\Routing\Route, 'Site Audit 路由仍在');

  $authenticated = Role::load('authenticated');
  $anonymous = Role::load('anonymous');
  $editor = Role::load('content_editor');
  foreach (['administer xedc ai', 'view xedc ai reviews', 'execute xedc ai reviews', 'administer xedc ai providers'] as $permission) {
    $check($authenticated && !$authenticated->hasPermission($permission), '普通登录用户没有权限 ' . $permission);
    $check($anonymous && !$anonymous->hasPermission($permission), '匿名用户没有权限 ' . $permission);
    $check($editor && !$editor->hasPermission($permission), '内容编辑没有被自动授予 ' . $permission);
  }
  $member = User::create([
    'name' => 'xedc_ai_test_member_' . bin2hex(random_bytes(3)),
    'mail' => 'xedc-ai-test-' . bin2hex(random_bytes(3)) . '@example.test',
    'status' => 1,
  ]);
  $member->save();
  $bag['users'][] = (int) $member->id();
  $access = \Drupal::accessManager();
  $check(!$access->checkNamedRoute('xedc_ai.overview', [], $member), '普通会员不能打开运营概览');
  $check(!$access->checkNamedRoute('xedc_ai.reviews', [], User::getAnonymousUser()), '匿名用户不能打开审核列表');
  $check($access->checkNamedRoute('xedc_ai.models', [], User::load(1)), '管理员可以打开模型管理');

  require_once DRUPAL_ROOT . '/themes/custom/xedc_admin/includes/navigation.theme';
  $navigation = _xedc_admin_navigation_definitions();
  $aiMenu = NULL;
  $contentMenu = NULL;
  foreach ($navigation as $item) {
    if (($item['id'] ?? '') === 'ai') {
      $aiMenu = $item;
    }
    if (($item['id'] ?? '') === 'content') {
      $contentMenu = $item;
    }
  }
  $aiTitles = array_column($aiMenu['children'] ?? [], 'title');
  $aiTitles = array_map('strval', $aiTitles);
  $check(in_array('运营概览', $aiTitles, TRUE) && in_array('AI 内容审核', $aiTitles, TRUE) && in_array('AI 自动翻译', $aiTitles, TRUE) && in_array('AI SEO 优化', $aiTitles, TRUE) && in_array('AI 模型管理', $aiTitles, TRUE) && in_array('API 使用统计', $aiTitles, TRUE), '后台导航包含 AI 运营中心的六个入口');
  foreach (['重复内容检测', '会员投稿审核', 'AI 翻译管理', '审核规则配置', '操作日志'] as $menuTitle) {
    $check(in_array($menuTitle, $aiTitles, TRUE), '后台导航包含 ' . $menuTitle);
  }
  $translate = NULL;
  foreach ($aiMenu['children'] ?? [] as $child) {
    if ((string) $child['route'] === 'xedc_auto_translate.settings') {
      $translate = $child;
    }
  }
  $check($translate !== NULL && ($translate['prefix'] ?? '') === '/admin/config/regional/xedc-auto-translate', 'AI 自动翻译指向现有翻译页面');
  $check($contentMenu !== NULL && array_map('strval', array_column($contentMenu['children'], 'title'))[0] === '内容列表', '内容管理菜单仍独立');
  $check(\Drupal::service('plugin.manager.menu.link')->hasDefinition('xedc_ai.overview'), 'Drupal 菜单链接已注册');

  $render = static function (array $build) use ($check, $safe, $secret): string {
    try {
      $html = (string) \Drupal::service('renderer')->renderRoot($build);
    }
    catch (\Throwable $exception) {
      $check(FALSE, '页面渲染失败：' . $safe($exception->getMessage()));
      return '';
    }
    $check(!str_contains($html, $secret), '渲染结果不包含测试密钥');
    return $html;
  };
  $emptyOverview = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\OverviewController::class)->page());
  $check(str_contains($emptyOverview, '运营概览') && (str_contains($emptyOverview, '还没有调用记录') || str_contains($emptyOverview, '最近调用')), '概览可以显示空状态或已有调用');

  $providers = \Drupal::service('xedc_ai.providers');
  $models = \Drupal::service('xedc_ai.models');
  $secrets = \Drupal::service('xedc_ai.secrets');
  $reviews = \Drupal::service('xedc_ai.reviews');
  $enqueuer = \Drupal::service('xedc_ai.enqueuer');
  $check($secrets->write('ai_test', $secret), '密钥写入私有文件');
  $hint = $secrets->hint('ai_test');
  $check($hint !== '' && $hint !== $secret && !str_contains($hint, 'xedc-ai-test'), '页面提示只保留末四位');
  $keyPath = $secrets->read('ai_test');
  $check($keyPath === $secret, 'Key 模块能读回密钥');
  $storedKey = \Drupal::entityTypeManager()->getStorage('key')->load('xedc_ai_ai_test');
  $location = $storedKey ? (string) ($storedKey->get('key_provider_settings')['file_location'] ?? '') : '';
  $check($location !== '' && !str_starts_with($location, DRUPAL_ROOT), '密钥文件不在 web 根目录');
  $providers->save([
    'id' => 'ai_test',
    'label' => 'XEDC AI Test',
    'driver' => 'custom',
    'base_url' => 'https://example.test/v1',
    'enabled' => 1,
    'timeout' => 20,
    'max_tokens' => 128,
    'temperature' => '0.2',
    'key_hint' => $hint,
  ]);
  $primaryId = $models->save([
    'provider_id' => 'ai_test',
    'model_name' => 'xedc-primary',
    'label' => '测试主模型',
    'enabled' => 1,
    'input_price' => '1.00',
    'output_price' => '2.00',
  ]);
  $fallbackId = $models->save([
    'provider_id' => 'ai_test',
    'model_name' => 'xedc-fallback',
    'label' => '测试备用模型',
    'enabled' => 1,
    'input_price' => '1.00',
    'output_price' => '2.00',
  ]);
  $setConfig([
    'default_model_id' => $primaryId,
    'fallback_model_id' => $fallbackId,
    'auto_review_article' => FALSE,
    'auto_review_photo' => FALSE,
    'auto_review_video' => FALSE,
    'auto_review_submission' => FALSE,
  ]);
  $defaultProfile = $models->defaultProfile();
  $check($defaultProfile && $defaultProfile->modelName === 'xedc-primary' && $defaultProfile->apiKey === $secret, '默认模型从统一配置解析');

  $deleteForm = \Drupal::formBuilder()->getForm(ProviderDeleteForm::class, 'ai_test');
  $deleteMarkup = '';
  if (isset($deleteForm['blocked']['#markup'])) {
    $deleteMarkup = (string) $deleteForm['blocked']['#markup'];
  }
  $check(str_contains($deleteMarkup, '不能删除'), '被模型使用的供应商不能直接删除');

  $bodyToken = 'XEDC_AI_BODY_' . bin2hex(random_bytes(4));
  $makeNode = static function (string $bundle, array $extra = []) use (&$bag, $member, $switcher): Node {
    $node = Node::create([
      'type' => $bundle,
      'title' => $extra['title'] ?? ('XEDC_AI_TEST ' . $bundle . ' ' . bin2hex(random_bytes(3))),
      'langcode' => 'zh-hans',
      'status' => 0,
      'uid' => $extra['uid'] ?? 1,
    ]);
    if (isset($extra['body']) && $node->hasField('field_body')) {
      $node->set('field_body', ['value' => $extra['body'], 'format' => 'plain_text']);
    }
    if (isset($extra['summary']) && $node->hasField('field_summary')) {
      $node->set('field_summary', ['value' => $extra['summary'], 'format' => 'plain_text']);
    }
    foreach (['field_images', 'field_video_file'] as $field) {
      if (isset($extra[$field]) && $node->hasField($field)) {
        $node->set($field, $extra[$field]);
      }
    }
    $moderation = \Drupal::service('content_moderation.moderation_information');
    if ($node->hasField('moderation_state') && $moderation->isModeratedEntity($node)) {
      $node->set('moderation_state', 'draft');
    }
    // Staff saves publish new article, photo and video nodes. Saving as a
    // member keeps the draft unpublished and does not start translation.
    $switcher->switchTo($member);
    try {
      $node->save();
    }
    finally {
      $switcher->switchBack();
    }
    $bag['nodes'][] = (int) $node->id();
    return $node;
  };
  $snapshot = static function (Node $node): array {
    return [
      'title' => (string) $node->label(),
      'status' => (int) $node->isPublished(),
      'state' => $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '',
      'body' => $node->hasField('field_body') ? (string) $node->get('field_body')->value : '',
      'summary' => $node->hasField('field_summary') ? (string) $node->get('field_summary')->value : '',
      'revision' => (int) $node->getRevisionId(),
    ];
  };

  $page = $makeNode('page', ['title' => 'XEDC_AI_TEST page ' . bin2hex(random_bytes(2))]);
  $pageReviews = (int) \Drupal::database()->select('xedc_ai_review', 'r')->condition('entity_id', $page->id())->countQuery()->execute()->fetchField();
  $check($pageReviews === 0, '没有启用本次审核的内容类型不会入队');

  $setConfig(['auto_review_article' => TRUE]);
  $beforeCalls = $fixture->calls;
  $article = $makeNode('article', [
    'title' => 'XEDC_AI_TEST 文章标题完整',
    'body' => $bodyToken . ' 这是一段足够长的文章正文，用来确认审核只发送文字并且不会自动发布。',
    'summary' => '文章标题完整的简介',
  ]);
  $setConfig(['auto_review_article' => FALSE]);
  $queued = $reviews->recent(5);
  $auto = NULL;
  foreach ($queued as $row) {
    if ((int) $row['entity_id'] === (int) $article->id()) {
      $auto = $row;
      break;
    }
  }
  $check($auto !== NULL && $auto['status'] === 'pending' && $queueCount() >= 1, '新建文章进入审核队列');
  $article = Node::load($article->id());
  $before = $snapshot($article);
  $mode = 'success';
  $outcome = $runReview((int) $auto['id']);
  $article = Node::load($article->id());
  $after = $snapshot($article);
  $auto = $reviews->load((int) $auto['id']);
  $check($outcome === 'processed' && $auto['status'] === 'completed' && (int) $auto['score'] === 72, '队列任务保存结构化审核结果');
  $check((int) $auto['revision_id'] === $after['revision'], '审核记录关联当前内容版本');
  $check($after === $before && $after['status'] === 0, '审核完成不会修改或发布内容');
  $check($fixture->calls === $beforeCalls + 1, '首次审核只调用一次模型');
  $local = json_decode((string) $auto['local_checks'], TRUE);
  $check(is_array($local) && ($local['vision'] ?? TRUE) === FALSE, '本地检查标记没有使用视觉模型');

  $callsAfterFirst = $fixture->calls;
  $repeatId = $enqueuer->request($article, 1, 'content_review', 'quick', FALSE, FALSE);
  $runReview($repeatId);
  $repeat = $reviews->load($repeatId);
  $check($repeat['status'] === 'completed' && $repeat['error_code'] === 'cache' && $fixture->calls === $callsAfterFirst, '未变化内容复用审核结果，不再调用模型');

  $article->setTitle('XEDC_AI_TEST 文章标题已经修改');
  $article->set('field_body', ['value' => $bodyToken . ' 正文也做了修改，以便识别成新的文字版本。这里补足长度。', 'format' => 'plain_text']);
  $article->save();
  $changedId = $enqueuer->request($article, 1, 'content_review', 'quick', FALSE, FALSE);
  $runReview($changedId);
  $changedReview = $reviews->load($changedId);
  $check($changedReview['status'] === 'completed' && $changedReview['field_hash'] !== $auto['field_hash'] && $fixture->calls === $callsAfterFirst + 1, '修改内容后识别为新版本并重新审核');

  $staleCalls = $fixture->calls;
  $staleId = $enqueuer->request($article, 1, 'content_review', 'quick', FALSE, FALSE);
  $article->setTitle('XEDC_AI_TEST 入队后又改了标题');
  $article->save();
  $runReview($staleId);
  $stale = $reviews->load($staleId);
  $check($stale['status'] === 'failed' && $stale['error_code'] === 'stale' && $fixture->calls === $staleCalls, '入队后内容变化时不再调用模型');

  $mode = 'invalid';
  $invalidBefore = $snapshot(Node::load($article->id()));
  $invalidId = $enqueuer->request($article, 1, 'content_review', 'quick', TRUE, FALSE);
  $runReview($invalidId);
  $invalid = $reviews->load($invalidId);
  $invalidAfter = $snapshot(Node::load($article->id()));
  $check($invalid['status'] === 'failed' && $invalid['error_code'] === 'invalid_json', '无效 JSON 记为失败');
  $check($invalidAfter === $invalidBefore, '无效 JSON 不会修改内容');
  $mode = 'success';

  $manualCalls = $fixture->calls;
  $manualId = $enqueuer->request($article, 1, 'content_review', 'quick', TRUE, FALSE);
  $runReview($manualId);
  $manual = $reviews->load($manualId);
  $check($manual['status'] === 'completed' && $fixture->calls === $manualCalls + 1, '管理员手动重新审核会跳过缓存');
  $enqueuer->confirm($manualId, 1);
  $article = Node::load($article->id());
  $check($reviews->load($manualId)['handled_status'] === 'confirmed' && !(bool) $article->isPublished(), '人工确认不改变发布状态');
  $enqueuer->ignore($manualId, 1);
  $check($reviews->load($manualId)['status'] === 'ignored', '可以忽略审核结果');

  $mode = 'fallback';
  $fallbackCalls = $fixture->calls;
  $fallbackNode = $makeNode('article', [
    'title' => 'XEDC_AI_TEST 备用模型文章',
    'body' => '备用模型测试正文，长度足够，不应该发布。' . bin2hex(random_bytes(2)),
    'summary' => '备用模型测试',
  ]);
  $fallbackReviewId = $enqueuer->request($fallbackNode, 1, 'content_review', 'quick', TRUE, FALSE);
  $runReview($fallbackReviewId);
  $fallbackReview = $reviews->load($fallbackReviewId);
  $check($fallbackReview['status'] === 'completed' && (string) $fallbackReview['model_name'] === 'xedc-fallback' && $fixture->calls === $fallbackCalls + 2, '主模型不可用时切换备用模型');
  $mode = 'success';

  $setConfig(['daily_budget' => '0.00', 'monthly_budget' => '0.00']);
  $budgetCalls = $fixture->calls;
  $budgetNode = $makeNode('article', [
    'title' => 'XEDC_AI_TEST 预算文章',
    'body' => '预算测试正文，长度足够，不应该因为预算失败而发布。',
    'summary' => '预算测试',
  ]);
  $budgetId = $enqueuer->request($budgetNode, 1, 'content_review', 'quick', TRUE, FALSE);
  $runReview($budgetId);
  $budgetReview = $reviews->load($budgetId);
  $check($budgetReview['status'] === 'failed' && $budgetReview['error_code'] === 'budget' && $fixture->calls === $budgetCalls, '达到预算后不再调用模型');
  $setConfig(['daily_budget' => '2.00', 'monthly_budget' => '30.00']);

  $unpricedId = $models->save([
    'provider_id' => 'ai_test',
    'model_name' => 'xedc-unpriced',
    'label' => '未标价模型',
    'enabled' => 1,
    'input_price' => '',
    'output_price' => '',
  ]);
  $setConfig(['default_model_id' => $unpricedId]);
  $unpricedCalls = $fixture->calls;
  $unpricedNode = $makeNode('article', [
    'title' => 'XEDC_AI_TEST 未标价文章',
    'body' => '未标价模型不应被自动调用，也不应生成虚假费用。',
    'summary' => '未标价',
  ]);
  $unpricedReviewId = $enqueuer->request($unpricedNode, 1, 'content_review', 'quick', FALSE, FALSE);
  $runReview($unpricedReviewId);
  $unpricedReview = $reviews->load($unpricedReviewId);
  $check($unpricedReview['status'] === 'failed' && $unpricedReview['error_code'] === 'budget' && $fixture->calls === $unpricedCalls, '没有可靠单价时自动调用暂停');
  $manualUnpricedId = $enqueuer->request($unpricedNode, 1, 'content_review', 'quick', TRUE, FALSE);
  $runReview($manualUnpricedId);
  $usageRow = \Drupal::database()->select('xedc_ai_usage', 'u')
    ->fields('u', ['cost_known', 'model_name', 'source'])
    ->condition('review_id', $manualUnpricedId)
    ->execute()
    ->fetchAssoc();
  $check(is_array($usageRow) && (int) $usageRow['cost_known'] === 0 && $usageRow['model_name'] === 'xedc-unpriced', '未标价调用记录费用未核算');
  $setConfig(['default_model_id' => $primaryId]);

  $mode = 'rate';
  $setConfig(['retry_limit' => 1]);
  $rateNode = $makeNode('article', [
    'title' => 'XEDC_AI_TEST 限流文章',
    'body' => '限流测试正文，长度足够，失败后不能无限重试。',
    'summary' => '限流测试',
  ]);
  $rateId = $enqueuer->request($rateNode, 1, 'content_review', 'quick', TRUE, FALSE);
  $firstRate = $runReview($rateId);
  $rateRow = $reviews->load($rateId);
  $check($firstRate === 'delayed' && $rateRow['status'] === 'pending' && (int) $rateRow['attempts'] === 1, '可重试错误会延迟重新入队');
  $secondRate = $runReview($rateId);
  $rateRow = $reviews->load($rateId);
  $check($secondRate === 'processed' && $rateRow['status'] === 'failed' && $rateRow['error_code'] === 'rate_limit', '达到重试次数后失败并保留原因');
  $setConfig(['retry_limit' => 1]);
  $mode = 'success';

  $now = \Drupal::time()->getRequestTime();
  $interruptNode = $makeNode('article', [
    'title' => 'XEDC_AI_TEST 中断文章',
    'body' => '中断测试正文，长度足够，不能因为重复执行而再次计费。',
    'summary' => '中断测试',
  ]);
  $interruptId = $enqueuer->request($interruptNode, 1, 'content_review', 'quick', TRUE, FALSE);
  $dropQueued($interruptId);
  $usageId = \Drupal::service('xedc_ai.usage')->start($interruptId, 'content_review', 'ai_test', 'xedc-primary', 1);
  \Drupal::database()->update('xedc_ai_usage')->fields(['created' => $now - 200])->condition('id', $usageId)->execute();
  $interruptCalls = $fixture->calls;
  $runReview($interruptId);
  $interrupt = $reviews->load($interruptId);
  $check($interrupt['status'] === 'failed' && $interrupt['error_code'] === 'interrupted' && $fixture->calls === $interruptCalls, '中断的调用不会自动再打一次模型');

  $setConfig(['max_concurrent' => 1]);
  $busyNode = $makeNode('article', [
    'title' => 'XEDC_AI_TEST 并发文章',
    'body' => '并发测试正文，长度足够。',
    'summary' => '并发测试',
  ]);
  $busyId = $enqueuer->request($busyNode, 1, 'content_review', 'quick', TRUE, FALSE);
  $dropQueued($busyId);
  $reviews->update($manualId, ['status' => 'processing', 'changed' => $now]);
  $busyResult = $runReview($busyId);
  $check($busyResult === 'delayed' && $reviews->load($busyId)['status'] === 'pending', '达到并发上限时不调用模型');
  $reviews->update($manualId, ['status' => 'ignored']);
  $setConfig(['max_concurrent' => 2]);
  $runReview($busyId);
  $check($reviews->load($busyId)['status'] === 'completed', '并发空出来后任务可以完成');

  $setConfig(['auto_review_article' => FALSE, 'auto_review_submission' => TRUE]);
  $submission = $makeNode('article', [
    'title' => 'XEDC_AI_TEST 会员投稿标题',
    'body' => '会员投稿正文，长度足够，只做文字审核。',
    'summary' => '会员投稿简介',
    'uid' => (int) $member->id(),
  ]);
  $setConfig(['auto_review_submission' => FALSE]);
  $submissionReview = NULL;
  foreach ($reviews->recent(10) as $row) {
    if ((int) $row['entity_id'] === (int) $submission->id()) {
      $submissionReview = $row;
    }
  }
  $check($submissionReview && (int) $submissionReview['is_submission'] === 1, '会员投稿可以进入文字审核');
  if ($submissionReview) {
    $dropQueued((int) $submissionReview['id']);
  }

  $directory = 'public://xedc-ai-selftest';
  \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
  $solidUri = $directory . '/solid-' . bin2hex(random_bytes(2)) . '.png';
  $copyUri = $directory . '/solid-copy-' . bin2hex(random_bytes(2)) . '.png';
  $patternUri = $directory . '/pattern-' . bin2hex(random_bytes(2)) . '.png';
  $videoUri = $directory . '/clip-' . bin2hex(random_bytes(2)) . '.mp4';
  file_put_contents(\Drupal::service('file_system')->realpath($directory) . '/' . basename($solidUri), $solid);
  file_put_contents(\Drupal::service('file_system')->realpath($directory) . '/' . basename($copyUri), $solid);
  file_put_contents(\Drupal::service('file_system')->realpath($directory) . '/' . basename($patternUri), $pattern);
  file_put_contents(\Drupal::service('file_system')->realpath($directory) . '/' . basename($videoUri), 'not-a-real-video');
  $makeFile = static function (string $uri) use (&$bag): File {
    $file = File::create(['uri' => $uri, 'status' => 1, 'uid' => 1]);
    $file->setPermanent();
    $file->save();
    $bag['files'][] = (int) $file->id();
    return $file;
  };
  $solidFile = $makeFile($solidUri);
  $copyFile = $makeFile($copyUri);
  $patternFile = $makeFile($patternUri);
  $videoFile = $makeFile($videoUri);
  $makeMedia = static function (string $bundle, string $name, File $file) use (&$bag): Media {
    $field = $bundle === 'video' ? 'field_media_video_file' : 'field_media_image';
    $values = [
      'bundle' => $bundle,
      'name' => $name,
      'status' => 1,
      'uid' => 1,
      $field => ['target_id' => $file->id()],
    ];
    if ($bundle === 'image') {
      $values[$field]['alt'] = 'XEDC AI test';
    }
    $media = Media::create($values);
    $media->save();
    $bag['media'][] = (int) $media->id();
    return $media;
  };
  $solidMedia = $makeMedia('image', 'XEDC_AI_TEST image', $solidFile);
  $videoMedia = $makeMedia('video', 'XEDC_AI_TEST video', $videoFile);
  $inspector = \Drupal::service('xedc_ai.local');
  $firstFile = $inspector->inspectFile((int) $solidFile->id());
  $secondFile = $inspector->inspectFile((int) $copyFile->id());
  $thirdFile = $inspector->inspectFile((int) $patternFile->id());
  $videoReport = $inspector->inspectFile((int) $videoFile->id());
  $check($firstFile['vision'] === FALSE && $firstFile['sha256'] !== '' && $firstFile['phash'] !== '', '图片只做本地类型、尺寸、SHA-256 和 pHash');
  $check(implode(' ', $secondFile['messages']) !== '' && str_contains(implode(' ', $secondFile['messages']), 'SHA-256'), '相同文件通过 SHA-256 识别');
  $check($videoReport['kind'] === 'video' && str_contains(implode(' ', $videoReport['messages']), '没有抽取画面') && $videoReport['phash'] === '', '视频只核对文件本身，不抽帧');
  $photo = $makeNode('photo', [
    'title' => 'XEDC_AI_TEST 图片标题完整',
    'summary' => '图片标题完整的文字简介',
    'field_images' => ['target_id' => $solidMedia->id()],
  ]);
  $video = $makeNode('video', [
    'title' => 'XEDC_AI_TEST 视频标题完整',
    'body' => '视频标题完整的文字简介，只审核这段文字。',
    'field_video_file' => ['target_id' => $videoMedia->id()],
  ]);
  $fileCalls = $fixture->calls;
  $photoReport = $inspector->inspect($photo);
  $videoNodeReport = $inspector->inspect($video);
  $check($photoReport['vision'] === FALSE && $videoNodeReport['vision'] === FALSE && $fixture->calls === $fileCalls, '检查图片和视频节点不会产生模型调用');
  $photoId = $enqueuer->request($photo, 1, 'content_review', 'quick', TRUE, FALSE);
  $runReview($photoId);
  $check(!str_contains($fixture->lastBody, 'image_url') && !str_contains($fixture->lastBody, base64_encode(substr($solid, 0, 32))), '图片审核请求不包含图像数据');
  $check($reviews->load($photoId)['status'] === 'completed', '图片内容只把文字元数据送去审核');

  $seoNode = $makeNode('article', [
    'title' => 'XEDC_AI_TEST SEO 原标题',
    'body' => 'SEO 测试正文，长度足够，用来确认建议不会自动覆盖。',
    'summary' => 'SEO 原简介',
  ]);
  $seoBefore = $snapshot($seoNode);
  $seoId = $enqueuer->request($seoNode, 1, 'seo_optimization', 'seo', TRUE, FALSE);
  $runReview($seoId);
  $seoReview = $reviews->load($seoId);
  $seoResult = json_decode((string) $seoReview['result'], TRUE);
  $check($seoReview['status'] === 'completed' && is_array($seoResult) && ($seoResult['seo_title'] ?? '') === '确认后的标题', 'SEO 建议已保存且未写入节点');
  $check($snapshot(Node::load($seoNode->id())) === $seoBefore, '生成 SEO 建议不会覆盖字段');
  $applyError = \Drupal::service('xedc_ai.seo')->apply(Node::load($seoNode->id()), $seoResult, TRUE, TRUE, 1);
  $seoNode = Node::load($seoNode->id());
  $check($applyError === NULL && $seoNode->label() === '确认后的标题' && !(bool) $seoNode->isPublished() && (string) $seoNode->get('moderation_state')->value === $seoBefore['state'], '管理员确认后才写入，发布状态保持不变');

  $storedAudit = \Drupal::service('xedc_ai.seo')->storedAudit();
  $check(array_key_exists('available', $storedAudit), 'SEO 页面读取已保存的 Site Audit，不重新扫描');

  $usage = \Drupal::service('xedc_ai.usage')->search([
    'provider_id' => 'ai_test',
    'source' => 'content_review',
    'status' => 'success',
  ]);
  $check($usage['total_tokens'] > 0 && $usage['rows'] !== [], '调用统计可以按供应商、来源和状态筛选');
  $found = $reviews->search(['q' => '备用模型', 'bundle' => 'article', 'status' => 'completed']);
  $check($found !== [] && (int) $found[0]['entity_id'] === (int) $fallbackNode->id(), '审核列表可以搜索和筛选');

  $overview = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\OverviewController::class)->page());
  $reviewList = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\ReviewController::class)->collection());
  $detail = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\ReviewController::class)->detail((int) $auto['id']));
  $providerPage = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\ProviderController::class)->collection());
  $modelPage = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\ModelController::class)->collection());
  $usagePage = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\UsageController::class)->page());
  $seoPage = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\SeoController::class)->page());
  $check(str_contains($overview, 'XEDC AI Test') && str_contains($reviewList, 'AI 内容审核') && str_contains($detail, '文字需要人工看一下') && str_contains($providerPage, 'XEDC AI Test') && str_contains($modelPage, 'xedc-primary') && str_contains($usagePage, 'API 使用统计') && str_contains($seoPage, 'SEO'), '运营中心各页面可以渲染');
  $duplicatePage = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\DuplicateController::class)->page());
  $submissionPage = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\SubmissionController::class)->page());
  $translatePage = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\TranslateController::class)->page());
  $logPage = $render(\Drupal::classResolver(\Drupal\xedc_ai\Controller\LogController::class)->page());
  $rulesForm = \Drupal::formBuilder()->getForm(\Drupal\xedc_ai\Form\RulesForm::class);
  $check(str_contains($duplicatePage, '重复内容检测') && str_contains($submissionPage, '会员投稿审核') && str_contains($translatePage, 'AI 翻译管理') && str_contains($logPage, '操作日志') && isset($rulesForm['min_title']), '新增运营页面可以渲染');
  $check(!$access->checkNamedRoute('xedc_ai.logs', [], User::getAnonymousUser()), '匿名用户不能查看操作日志');
  $check(!$access->checkNamedRoute('xedc_ai.rules', [], $member), '普通会员不能管理审核规则');
  $check(!$access->checkNamedRoute('xedc_ai.submissions', [], User::getAnonymousUser()), '匿名用户不能打开投稿审核');
  $state = \Drupal::state();
  $callsBeforePause = $fixture->calls;
  $state->set('xedc_ai.paused', TRUE);
  $pausedId = $enqueuer->request($article, 1, 'content_review', 'quick', TRUE, FALSE);
  $pausedOutcome = $runReview($pausedId);
  $check($pausedOutcome === 'delayed' && $reviews->load($pausedId)['status'] === 'pending' && $fixture->calls === $callsBeforePause, '暂停后尚未执行的任务不会调用模型');
  $state->set('xedc_ai.paused', FALSE);
  $check($enqueuer->cancel($pausedId, 1) === TRUE && $reviews->load($pausedId)['status'] === 'cancelled', '可以取消尚未执行的任务');
  $check($runReview($pausedId) === 'processed' && $fixture->calls === $callsBeforePause, '已取消的任务不会调用模型');
  $decisionNode = $makeNode('article', [
    'title' => 'XEDC_AI_TEST 投稿处理标题足够长',
    'body' => '投稿处理正文长度足够，用来确认状态变更不会自动发生。',
    'summary' => '投稿处理简介',
    'uid' => (int) $member->id(),
  ]);
  $decisionNode->set('moderation_state', 'needs_review');
  $decisionNode->save();
  $latestNode = static function (int $nid): Node {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $revisionId = $storage->getLatestRevisionId($nid);
    $revision = $storage->loadRevision((int) $revisionId);
    return $revision instanceof Node ? $revision : Node::load($nid);
  };
  $moderator = \Drupal::service('xedc_ai.submissions');
  $blocked = $moderator->apply($decisionNode, 'changes', '先不确认', 1, FALSE);
  $decisionNode = $latestNode((int) $decisionNode->id());
  $check($blocked !== NULL && (string) $decisionNode->get('moderation_state')->value === 'needs_review', '未确认时不会变更投稿状态');
  $applied = $moderator->apply($decisionNode, 'changes', '请补充简介', 1, TRUE);
  $decisionNode = $latestNode((int) $decisionNode->id());
  $check($applied === NULL && (string) $decisionNode->get('moderation_state')->value === 'changes_requested' && !(bool) $decisionNode->isPublished(), '确认后才按现有工作流退回修改');
  $settingsForm = \Drupal::formBuilder()->getForm(SettingsForm::class);
  $check(isset($settingsForm['models']['default_model_id']), '运行配置表单可以构建');

  $formState = new FormState();
  $formState->setValues([
    'default_model_id' => $primaryId,
    'fallback_model_id' => $fallbackId,
    'timeout' => 25,
    'max_tokens' => 256,
    'temperature' => '0.2',
    'auto_review_article' => 0,
    'auto_review_photo' => 0,
    'auto_review_video' => 0,
    'auto_review_submission' => 0,
    'deep_analysis' => 0,
    'retry_limit' => 1,
    'max_concurrent' => 2,
    'rule_version' => 1,
    'retention_days' => 180,
    'daily_budget' => '2.50',
    'monthly_budget' => '30.00',
    'budget_action' => 'pause_auto',
    'allow_manual_over_budget' => 0,
    'allow_unpriced_manual' => 1,
    'unpriced_daily_tokens' => 100000,
  ]);
  \Drupal::formBuilder()->submitForm(SettingsForm::class, $formState);
  $check(!$formState->hasAnyErrors() && \Drupal::config('xedc_ai.settings')->get('daily_budget') === '2.50', '运行配置可以保存');

  $secretInConfig = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {config} WHERE data LIKE :secret', [':secret' => '%' . $secret . '%'])->fetchField();
  $check($secretInConfig === 0, '配置表没有测试密钥');
  foreach ([
    'xedc_ai_provider' => 'key_hint',
    'xedc_ai_usage' => 'error_message',
    'xedc_ai_review' => 'error_message',
    'xedc_ai_audit' => 'message',
  ] as $table => $column) {
    $hits = (int) \Drupal::database()->query("SELECT COUNT(*) FROM {{$table}} WHERE {$column} LIKE :secret", [':secret' => '%' . $secret . '%'])->fetchField();
    $check($hits === 0, $table . ' 没有保存测试密钥');
  }
  $bodyHits = (int) \Drupal::database()->query("SELECT COUNT(*) FROM {xedc_ai_usage} WHERE error_message LIKE :body", [':body' => '%' . $bodyToken . '%'])->fetchField();
  $check($bodyHits === 0, '调用日志没有保存正文');
  $check($thirdFile['phash'] !== '' && $thirdFile['phash'] !== $firstFile['phash'], '不同图片得到不同的本地 pHash');
}
catch (\Throwable $exception) {
  $check(FALSE, '测试异常：' . $safe($exception->getMessage()) . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
}
finally {
  try {
    \Drupal::state()->set('xedc_ai.paused', FALSE);
    $editable->setData($original)->save();
  }
  catch (\Throwable $exception) {
    fwrite(STDERR, "FAIL 恢复配置失败：" . $safe($exception->getMessage()) . "\n");
    $failures[] = '恢复配置失败';
  }
  $database = \Drupal::database();
  $reviewIds = $database->select('xedc_ai_review', 'r')
    ->fields('r', ['id'])
    ->condition('title', 'XEDC_AI_TEST%', 'LIKE')
    ->execute()
    ->fetchCol();
  $reviewIds = array_map('intval', $reviewIds);
  $rows = $database->select('queue', 'q')->fields('q', ['item_id', 'data'])->condition('name', 'xedc_ai_review')->execute();
  foreach ($rows as $row) {
    $data = unserialize($row->data, ['allowed_classes' => FALSE]);
    if (is_array($data) && in_array((int) ($data['review_id'] ?? 0), $reviewIds, TRUE)) {
      $database->delete('queue')->condition('item_id', $row->item_id)->execute();
    }
  }
  if ($reviewIds !== []) {
    $database->delete('xedc_ai_hold')->condition('review_id', $reviewIds, 'IN')->execute();
    $database->delete('xedc_ai_review')->condition('id', $reviewIds, 'IN')->execute();
  }
  $database->delete('xedc_ai_usage')->condition('provider_id', 'ai_test')->execute();
  $database->delete('xedc_ai_audit')->condition('created', \Drupal::time()->getRequestTime(), '>=')->execute();
  $database->delete('xedc_ai_model')->condition('provider_id', 'ai_test')->execute();
  $database->delete('xedc_ai_provider')->condition('id', 'ai_test')->execute();
  if ($bag['files'] !== []) {
    $database->delete('xedc_ai_filehash')->condition('fid', $bag['files'], 'IN')->execute();
  }
  \Drupal::service('xedc_ai.secrets')->delete('ai_test');
  $storage = \Drupal::entityTypeManager();
  foreach ($bag['nodes'] as $nid) {
    $node = $storage->getStorage('node')->load($nid);
    if ($node) {
      $node->delete();
    }
  }
  foreach ($bag['media'] as $mid) {
    $media = $storage->getStorage('media')->load($mid);
    if ($media) {
      $media->delete();
    }
  }
  foreach ($bag['files'] as $fid) {
    $file = $storage->getStorage('file')->load($fid);
    if ($file) {
      $file->delete();
    }
  }
  foreach ($bag['users'] as $uid) {
    $account = $storage->getStorage('user')->load($uid);
    if ($account) {
      $account->delete();
    }
  }
  foreach ($bag['paths'] as $path) {
    if (is_string($path) && is_file($path)) {
      @unlink($path);
    }
  }
  $switcher->switchBack();
}

if ($failures !== []) {
  fwrite(STDERR, count($failures) . " failed.\n");
  exit(1);
}
fwrite(STDOUT, "All AI self-test checks passed.\n");
