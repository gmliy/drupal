<?php

/**
 * @file
 * Helpers for the six XEDC status pages.
 */

use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Copy for a status code. Text stays in Chinese, the site default.
 *
 * @return array{title: string, message: string}
 */
function _xedc_status_copy(int $code): array {
  $pages = [
    403 => [
      'title' => '暂时无法访问此页面',
      'message' => '你可能没有访问该页面的权限，请确认账号权限后重试。',
    ],
    404 => [
      'title' => '这个页面不存在',
      'message' => '你访问的页面可能已被移动、删除，或者地址输入有误。',
    ],
    429 => [
      'title' => '请求有点频繁',
      'message' => '你的操作速度过快，请稍等片刻后再试。',
    ],
    500 => [
      'title' => '服务器出现了一点问题',
      'message' => '服务器暂时无法完成请求，请稍后再试。',
    ],
    502 => [
      'title' => '连接暂时中断',
      'message' => '服务器暂时无法建立正常连接，请稍后重试。',
    ],
    503 => [
      'title' => '网站正在维护中',
      'message' => '我们正在进行必要的维护工作，请稍后再来访问。',
    ],
  ];
  return $pages[$code] ?? $pages[500];
}

/**
 * Status code for the current exception subrequest, or 0.
 */
function _xedc_status_code_from_request(): int {
  $exception = \Drupal::request()->attributes->get('exception');
  if (!$exception instanceof HttpExceptionInterface) {
    return 0;
  }
  $code = $exception->getStatusCode();
  return in_array($code, [403, 404, 429], TRUE) ? $code : 0;
}

/**
 * Seconds from a Retry-After header. Unknown values stay at 0.
 */
function _xedc_status_retry_after(?HttpExceptionInterface $exception): int {
  if (!$exception) {
    return 0;
  }
  $headers = array_change_key_case($exception->getHeaders(), CASE_LOWER);
  $raw = $headers['retry-after'] ?? '';
  if (is_array($raw)) {
    $raw = reset($raw);
  }
  $raw = trim((string) $raw);
  if ($raw === '') {
    return 0;
  }
  if (ctype_digit($raw)) {
    return min(86400, (int) $raw);
  }
  $stamp = strtotime($raw);
  if ($stamp) {
    return max(0, min(86400, $stamp - \Drupal::time()->getRequestTime()));
  }
  return 0;
}

/**
 * Shows a maintenance note only when it contains a real time.
 */
function _xedc_status_eta(string $message): string {
  $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($message)) ?? '');
  if ($plain === '' || mb_strlen($plain) > 120 || str_contains($plain, '@site')) {
    return '';
  }
  if (!preg_match('/\d{4}\s*[-年\/.]\s*\d{1,2}|\d{1,2}\s*[:：]\s*\d{2}|\d{1,2}\s*点/u', $plain)) {
    return '';
  }
  return $plain;
}

/**
 * Keeps a retry link on this site.
 */
function _xedc_status_local_url(string $uri): string {
  $parts = parse_url($uri);
  $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
  if (!is_string($path) || $path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
    return '/';
  }
  $query = is_array($parts) ? ($parts['query'] ?? '') : '';
  if (!is_string($query) || $query === '' || preg_match('/[\r\n]/', $query)) {
    return $path;
  }
  return $path . '?' . $query;
}

/**
 * Variables for the shared status template.
 *
 * The exception message is never included.
 *
 * @return array<string, mixed>
 */
function _xedc_status_payload(int $code, ?HttpExceptionInterface $exception = NULL): array {
  $copy = _xedc_status_copy($code);
  $front = '/';
  $login = '/user/login';
  $search = '/search';
  try {
    $front = Url::fromRoute('<front>')->toString();
    $login = Url::fromRoute('user.login', [], [
      'query' => \Drupal::destination()->getAsArray(),
    ])->toString();
    $search = Url::fromUserInput('/search')->toString();
  }
  catch (\Throwable) {
    // The path fallbacks above still navigate.
  }
  $retry = $code === 429 ? _xedc_status_retry_after($exception) : 0;
  $labels = [
    429 => '重新尝试',
    500 => '重新加载',
    502 => '重新尝试',
    503 => '重新检查',
  ];
  return [
    'code' => $code,
    'title' => $copy['title'],
    'message' => $copy['message'],
    'front' => $front,
    'login' => $login,
    'search' => $search,
    'retry_url' => _xedc_status_local_url(\Drupal::request()->getRequestUri()),
    'retry_after' => $retry,
    'retry_label' => $labels[$code] ?? '重新尝试',
    'show_home' => $code !== 503,
    'show_login' => $code === 403 && !\Drupal::currentUser()->isAuthenticated(),
    'show_back' => in_array($code, [403, 404], TRUE),
    'show_retry' => in_array($code, [429, 500, 502, 503], TRUE),
    'show_search' => FALSE,
    'eta' => '',
  ];
}

/**
 * Inline theme script. Runs before CSS so the first paint matches.
 */
function _xedc_status_theme_js(): string {
  return "(function(){try{var t=localStorage.getItem('theme');if(t!=='dark'&&t!=='light'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}var h=document.documentElement;h.setAttribute('data-bs-theme',t);h.setAttribute('data-theme',t);}catch(e){}})();";
}

/**
 * Attaches the status library, robots tag, and early theme script.
 */
function _xedc_status_attach(array &$variables): void {
  $variables['#attached']['library'][] = 'xedc/status';
  $variables['#attached']['html_head'][] = [
    [
      '#type' => 'html_tag',
      '#tag' => 'meta',
      '#attributes' => [
        'name' => 'robots',
        'content' => 'noindex, nofollow',
      ],
    ],
    'xedc_status_robots',
  ];
  $variables['#attached']['html_head'][] = [
    [
      '#type' => 'html_tag',
      '#tag' => 'script',
      '#value' => Markup::create(_xedc_status_theme_js()),
      '#weight' => -500,
    ],
    'xedc_status_theme',
  ];
}

/**
 * Adds a class to an attribute array or an Attribute object.
 *
 * PHP 8 throws if method_exists() is given an array.
 */
function _xedc_status_add_class(array &$variables, string $key, string $class): void {
  if (!isset($variables[$key])) {
    return;
  }
  $value = $variables[$key];
  if (is_object($value) && method_exists($value, 'addClass')) {
    $value->addClass($class);
    return;
  }
  if (is_array($value)) {
    $variables[$key]['class'][] = $class;
  }
}

/**
 * Prepares the document title and assets for a status response.
 *
 * @return bool
 *   TRUE when the normal document title logic should stop.
 */
function _xedc_status_prepare_html(array &$variables): bool {
  $page_theme = (string) ($variables['page']['#theme'] ?? '');
  // Maintenance wins over a 404 on an unknown path. Flood uses its own hook.
  if ($page_theme === 'maintenance_page') {
    $code = 503;
  }
  else {
    $code = _xedc_status_code_from_request();
  }
  if (!$code) {
    return FALSE;
  }
  $copy = _xedc_status_copy($code);
  if (function_exists('_xedc_set_head_title')) {
    _xedc_set_head_title($variables, $copy['title'] . ' | DcLili');
  }
  _xedc_status_add_class($variables, 'html_attributes', 'xs-doc');
  _xedc_status_add_class($variables, 'attributes', 'xs-body');
  _xedc_status_attach($variables);
  $variables['#cache']['max-age'] = 0;
  return TRUE;
}

/**
 * Switches 403, 404, and 429 pages onto the status layout.
 *
 * @return bool
 *   TRUE when the rest of the normal page preprocess should stop.
 */
function _xedc_status_prepare_page(array &$variables): bool {
  $code = _xedc_status_code_from_request();
  if (!$code) {
    return FALSE;
  }
  $exception = \Drupal::request()->attributes->get('exception');
  $variables['xedc_status'] = _xedc_status_payload($code, $exception instanceof HttpExceptionInterface ? $exception : NULL);
  $variables['#cache']['max-age'] = 0;
  $variables['#cache']['contexts'][] = 'user.roles:anonymous';
  _xedc_status_attach($variables);
  _xedc_status_shell($variables);
  return TRUE;
}

/**
 * Loads the existing header data without rebuilding the rest of the page.
 */
function _xedc_status_shell(array &$variables): void {
  $variables['xn'] = ['items' => []];
  try {
    if (function_exists('xedc_nav_build')) {
      xedc_nav_build($variables);
    }
  }
  catch (\Throwable) {
    $variables['xn'] = ['items' => []];
  }
  $variables['xedc_lang_current'] = NULL;
  $variables['xedc_lang_others'] = [];
  try {
    if (function_exists('_xedc_language_links')) {
      foreach (_xedc_language_links() as $lang_link) {
        if (!empty($lang_link['current'])) {
          $variables['xedc_lang_current'] = $lang_link;
        }
        else {
          $variables['xedc_lang_others'][] = $lang_link;
        }
      }
    }
  }
  catch (\Throwable) {
    $variables['xedc_lang_current'] = NULL;
    $variables['xedc_lang_others'] = [];
  }
  $link = static function (string $path): string {
    try {
      return Url::fromUserInput($path)->toString();
    }
    catch (\Throwable) {
      return $path;
    }
  };
  try {
    $front = Url::fromRoute('<front>')->toString();
  }
  catch (\Throwable) {
    $front = '/';
  }
  $variables['xedc_href'] = [
    'front' => $front,
    'search' => $link('/search'),
    'about' => $link('/about'),
    'privacy' => $link('/privacy-policy'),
    'terms' => $link('/terms'),
    'sitemap' => $link('/sitemap'),
  ];
  $variables['#cache']['contexts'][] = 'languages:language_interface';
  $variables['#cache']['contexts'][] = 'user.roles:authenticated';
}

/**
 * Implements hook_preprocess_maintenance_page().
 *
 * Login flood keeps the core message. Maintenance and a dead database use
 * the 503 layout and do not print the original markup.
 */
function xedc_preprocess_maintenance_page(array &$variables): void {
  $hook = (string) ($variables['theme_hook_original'] ?? '');
  $flood = str_contains($hook, 'flood');
  $offline = str_contains($hook, 'offline');
  $maintenance = FALSE;
  try {
    $maintenance = (bool) \Drupal::state()->get('system.maintenance_mode');
  }
  catch (\Throwable) {
    $offline = TRUE;
  }
  if ($flood || (!$maintenance && !$offline)) {
    $variables['xedc_status'] = ['code' => 0];
    return;
  }
  $payload = _xedc_status_payload(503);
  $payload['show_home'] = FALSE;
  $payload['show_retry'] = TRUE;
  $payload['retry_label'] = '重新检查';
  $payload['show_back'] = FALSE;
  $payload['show_login'] = FALSE;
  $payload['show_search'] = FALSE;
  try {
    $payload['eta'] = _xedc_status_eta((string) \Drupal::service('maintenance_mode')->getSiteMaintenanceMessage());
  }
  catch (\Throwable) {
    $payload['eta'] = '';
  }
  $variables['xedc_status'] = $payload;
  _xedc_status_attach($variables);
  _xedc_status_shell($variables);
  $variables['#cache']['max-age'] = 0;
}
