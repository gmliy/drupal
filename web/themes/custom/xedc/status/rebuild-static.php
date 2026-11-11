<?php

/**
 * @file
 * Rebuilds 500/502/503 static HTML from status assets and a frozen shell.
 *
 * Usage: php web/themes/custom/xedc/status/rebuild-static.php
 */

$theme = dirname(__DIR__);
$statusDir = __DIR__;
$css = file_get_contents($theme . '/css/status.css');
$js = file_get_contents($theme . '/js/status.js');
$shell = file_get_contents($statusDir . '/502.html');

if (!is_string($css) || !is_string($js) || !is_string($shell)) {
  fwrite(STDERR, "Missing shell or assets.\n");
  exit(1);
}

$pages = [
  500 => [
    'title' => '服务器出现了一点问题',
    'lead' => '服务器暂时无法完成请求，请稍后再试。',
    'main' => <<<'HTML'
<main id="main-content" class="xs" data-xs-code="500">
  <div class="xs-group">
    <div class="xs-stage">
      <div class="xs-icon-box" data-xs-icon="500" role="img" aria-label="服务器出现了一点问题">
        <svg class="xs-icon xs-icon-svg" viewBox="0 0 24 24" aria-hidden="true">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" x2="12" y1="8" y2="12"/>
          <line x1="12" x2="12.01" y1="16" y2="16"/>
        </svg>
      </div>
    </div>
    <p class="xs-code">500</p>
    <h1 class="xs-title">服务器出现了一点问题</h1>
    <p class="xs-lead">服务器暂时无法完成请求，请稍后再试。</p>
    <div class="xs-actions">
      <a class="xs-btn xs-btn-primary" href="" data-xs-reload>重新加载</a>
      <a class="xs-btn xs-btn-secondary" href="/">返回首页</a>
    </div>
  </div>
</main>
HTML,
  ],
  502 => [
    'title' => '连接暂时中断',
    'lead' => '服务器暂时无法建立正常连接，请稍后重试。',
    'main' => <<<'HTML'
<main id="main-content" class="xs" data-xs-code="502">
  <div class="xs-group">
    <div class="xs-stage">
      <div class="xs-icon-box" data-xs-icon="502" role="img" aria-label="连接暂时中断">
        <svg class="xs-icon xs-icon-svg xs-net" viewBox="0 0 24 24" aria-hidden="true">
          <circle class="xs-node xs-node-l" cx="7" cy="12" r="2.5"/>
          <circle class="xs-node xs-node-r" cx="17" cy="12" r="2.5"/>
          <line class="xs-link" x1="9.5" y1="12" x2="14.5" y2="12"/>
        </svg>
      </div>
    </div>
    <p class="xs-code">502</p>
    <h1 class="xs-title">连接暂时中断</h1>
    <p class="xs-lead">服务器暂时无法建立正常连接，请稍后重试。</p>
    <div class="xs-actions">
      <a class="xs-btn xs-btn-primary" href="" data-xs-reload>重新尝试</a>
      <a class="xs-btn xs-btn-secondary" href="/">返回首页</a>
    </div>
  </div>
</main>
HTML,
  ],
  503 => [
    'title' => '网站正在维护中',
    'lead' => '我们正在进行必要的维护工作，请稍后再来访问。',
    'main' => <<<'HTML'
<main id="main-content" class="xs" data-xs-code="503">
  <div class="xs-group">
    <div class="xs-stage">
      <div class="xs-icon-box" data-xs-icon="503" role="img" aria-label="网站正在维护中">
        <svg class="xs-icon xs-icon-svg" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
        </svg>
      </div>
    </div>
    <p class="xs-code">503</p>
    <h1 class="xs-title">网站正在维护中</h1>
    <p class="xs-lead">我们正在进行必要的维护工作，请稍后再来访问。</p>
    <div class="xs-actions">
      <a class="xs-btn xs-btn-primary" href="" data-xs-reload>重新检查</a>
    </div>
  </div>
</main>
HTML,
  ],
];

foreach ($pages as $code => $page) {
  $html = $shell;
  $html = preg_replace('/<title>[^<]*<\/title>/', '<title>' . $page['title'] . '</title>', $html, 1);
  if (!preg_match('/\/\* Status pages.*?\n}\n\n<\/style>/s', $html)) {
    fwrite(STDERR, "Could not find status CSS block in shell.\n");
    exit(1);
  }
  $html = preg_replace(
    '/\/\* Status pages.*?\n}\n\n<\/style>/s',
    rtrim($css) . "\n\n</style>",
    $html,
    1
  );
  if (!preg_match('/<main id="main-content" class="xs".*?<\/main>/s', $html)) {
    fwrite(STDERR, "Could not find main block in shell.\n");
    exit(1);
  }
  $html = preg_replace('/<main id="main-content" class="xs".*?<\/main>/s', trim($page['main']), $html, 1);
  $marker = "/**\n * @file\n * Interaction for XEDC status pages";
  $pos = strpos($html, $marker);
  if ($pos === FALSE) {
    fwrite(STDERR, "Could not find status JS in shell.\n");
    exit(1);
  }
  $end = strrpos($html, '</script>', $pos);
  if ($end === FALSE) {
    exit(1);
  }
  $html = substr($html, 0, $pos) . $js . substr($html, $end);
  $out = $statusDir . '/' . $code . '.html';
  file_put_contents($out, $html);
  echo "Wrote $out\n";
}
