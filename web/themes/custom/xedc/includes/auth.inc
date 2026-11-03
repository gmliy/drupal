<?php

/**
 * @file
 * XEDC 登录 / 注册 / 找回密码 页面逻辑。
 */

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

require_once __DIR__ . '/AuthCallbacks.php';

function _xedc_auth_route(): string {
  return (string) \Drupal::routeMatch()->getRouteName();
}

function _xedc_auth_is_auth_route(): bool {
  $r = _xedc_auth_route();
  return in_array($r, ['user.login', 'user.register', 'user.pass'], TRUE)
    || str_starts_with($r, 'user.reset')
    || ($r === 'entity.user.edit_form' && \Drupal::request()->query->has('pass-reset-token'));
}

/**
 * 只传递 Drupal 原生 destination（Drupal 会拒绝外部地址）。
 */
function _xedc_auth_dest(): array {
  $dest = \Drupal::request()->query->get('destination');
  return (is_string($dest) && $dest !== '') ? ['destination' => $dest] : [];
}

function _xedc_auth_wrap(array &$form, string $key, string $title, string $desc): void {
  $form['#attributes']['class'][] = 'xedc-auth';
  $form['#attributes']['class'][] = 'xedc-auth--' . $key;
  if ($key === 'reset') {
    $form['#attributes']['class'][] = 'is-auto';
  }
  _xedc_auth_localize_messages();
  $form['#cache']['contexts'][] = 'url.query_args:destination';
  $form['#cache']['contexts'][] = 'url.query_args:xedc_embed';
  $form['#cache']['max-age'] = 0;
  $form['#pre_render'][] = 'Drupal\xedc\AuthCallbacks::messages';
  $form['xedc_head'] = [
    '#type' => 'inline_template',
    '#weight' => -1000,
    '#template' => <<<'TWIG'
<div class="xedc-auth__head">
  <a class="xedc-auth__brand" href="{{ path('<front>') }}" target="_top" aria-label="DcLili">dclili<i class="xn-dot" aria-hidden="true"></i></a>
  <h1 class="xedc-auth__title">{{ title }}</h1>
  {% if desc %}<p class="xedc-auth__desc">{{ desc }}</p>{% endif %}
</div>
TWIG,
    '#context' => ['title' => $title, 'desc' => $desc],
  ];
}

function _xedc_auth_foot(array &$form, string $text, string $label, string $route): void {
  $form['xedc_foot'] = [
    '#type' => 'container',
    '#weight' => 1000,
    '#attributes' => ['class' => ['xedc-auth__foot']],
    'text' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $text],
    'link' => [
      '#type' => 'link',
      '#title' => $label,
      '#url' => Url::fromRoute($route, [], ['query' => _xedc_auth_dest()]),
      '#attributes' => ['target' => '_top'],
    ],
  ];
}

/**
 * Replaces the document title without appending the site name twice.
 */
function _xedc_set_head_title(array &$variables, string $title): void {
  if (!is_array($variables['head_title'] ?? NULL)) {
    $variables['head_title'] = ['title' => $title];
    return;
  }
  $variables['head_title']['title'] = $title;
  unset($variables['head_title']['name'], $variables['head_title']['slogan']);
}

/**
 * Implements hook_preprocess_html().
 */
function xedc_preprocess_html(array &$variables): void {
  if (_xedc_search_is_route()) {
    [$q] = _xedc_search_params();
    $variables['head_title'] = [
      'title' => $q !== '' ? t('搜索：@query', ['@query' => $q]) : t('搜索'),
      'name' => \Drupal::config('system.site')->get('name'),
    ];
    $variables['page']['#attached']['library'][] = 'xedc/search-page';
    $variables['#cache']['contexts'][] = 'url.query_args:q';
    return;
  }
  $variables['page']['#attached']['library'][] = 'xedc/xedc_auth';
  if (_xedc_auth_is_auth_route()) {
    $variables['page']['#attached']['library'][] = 'xedc/xedc_auth_form';
  }
  $route_name = \Drupal::routeMatch()->getRouteName();
  $term = \Drupal::routeMatch()->getParameter('taxonomy_term');
  if ($route_name === 'xedc_models.detail' && $term instanceof \Drupal\taxonomy\TermInterface) {
    $term = _xedc_translated($term);
    _xedc_set_head_title($variables, (string) t('@name 的作品', ['@name' => $term->label()]) . ' | DcLili');
    $variables['#cache']['contexts'][] = 'languages:language_content';
    $variables['#cache']['contexts'][] = 'languages:language_interface';
  }
  elseif ($term instanceof \Drupal\taxonomy\TermInterface && is_array($variables['head_title'] ?? NULL)) {
    $term = _xedc_translated($term);
    _xedc_set_head_title($variables, (string) t('@name 列表页', ['@name' => $term->label()]) . ' | DcLili');
    $variables['#cache']['contexts'][] = 'languages:language_content';
    $variables['#cache']['contexts'][] = 'languages:language_interface';
  }
  elseif (\Drupal::service('path.matcher')->isFrontPage()) {
    _xedc_set_head_title($variables, (string) t('DcLili - 精选美图、视频与优质文章'));
  }
  elseif ($route_name === 'xedc_tags.index') {
    _xedc_set_head_title($variables, (string) t('全部标签') . ' | DcLili');
  }
  elseif ($route_name === 'xedc.html_sitemap') {
    _xedc_set_head_title($variables, (string) t('站点地图') . ' | DcLili');
  }
  elseif ($route_name === 'view.image_gallery.page_1') {
    _xedc_set_head_title($variables, (string) t('美图列表') . ' | DcLili');
  }
  elseif ($route_name === 'view.video_list.page_1') {
    _xedc_set_head_title($variables, (string) t('视频列表页') . ' | DcLili');
  }
  elseif ($route_name === 'view.article_list.page_1') {
    _xedc_set_head_title($variables, (string) t('文章列表') . ' | DcLili');
  }
  if (_xedc_auth_is_auth_route()) {
    $variables['attributes']['class'][] = 'xedc-auth-page';
  }
  if (\Drupal::request()->query->get('xedc_embed')) {
    $variables['attributes']['class'][] = 'xedc-embed';
  }
  if (_xedc_is_photo_list_route() && ($page = _xedc_photo_current_page()) > 0) {
    $suffix = t(' - 第 @page 页', ['@page' => $page + 1]);
    if (isset($variables['head_title']['title']) && is_string($variables['head_title']['title'])) {
      $variables['head_title']['title'] .= $suffix;
    }
    elseif (is_string($variables['head_title'])) {
      $variables['head_title'] .= $suffix;
    }
  }
  if (function_exists('_xedc_preprocess_html_video')) {
    _xedc_preprocess_html_video($variables);
  }
}

/**
 * Implements hook_form_FORM_ID_alter() for user_login_form.
 */
function xedc_form_user_login_form_alter(array &$form, FormStateInterface $form_state): void {
  if (_xedc_auth_route() !== 'user.login') {
    return;
  }
  _xedc_auth_wrap($form, 'login', t('登录'), '');

  $form['name']['#title'] = t('用户名');
  $form['name']['#description'] = '';
  $form['name']['#maxlength'] = 254;
  $form['name']['#attributes']['placeholder'] = t('请输入用户名或邮箱');
  $form['name']['#element_validate'][] = '_xedc_auth_login_email_to_name';
  $form['name']['#weight'] = 1;

  $form['pass']['#title'] = t('密码');
  $form['pass']['#description'] = '';
  $form['pass']['#attributes']['placeholder'] = t('请输入密码');
  $form['pass']['#weight'] = 2;

  $form['xedc_forgot'] = [
    '#type' => 'link',
    '#title' => t('忘记密码？'),
    '#url' => Url::fromRoute('user.pass', [], ['query' => _xedc_auth_dest()]),
    '#attributes' => ['class' => ['xedc-auth__forgot'], 'target' => '_top'],
    '#weight' => 3,
  ];

  $form['actions']['#weight'] = 10;
  $form['actions']['submit']['#value'] = t('登录');
  _xedc_auth_foot($form, t('还没有账号？'), t('注册账号'), 'user.register');

  $form['#submit'][] = '_xedc_auth_login_submit';
}

/**
 * 直接访问登录页（没有 destination）时回首页；有 destination 时 Drupal 原生处理。
 */
function _xedc_auth_login_submit(array &$form, FormStateInterface $form_state): void {
  $request = \Drupal::request();
  if ($request->query->get('xedc_embed')) {
    // 弹窗登录：iframe 跳到轻量静态文件，由父窗口回到原页面。
    $request->query->remove('destination');
    $form_state->setRedirectUrl(Url::fromUri('base:robots.txt', ['query' => ['xedc_done' => 1]]));
    return;
  }
  if (!$request->query->has('destination')) {
    $form_state->setRedirect('<front>');
  }
}

/**
 * Implements hook_form_FORM_ID_alter() for user_register_form.
 */
function xedc_form_user_register_form_alter(array &$form, FormStateInterface $form_state): void {
  _xedc_auth_add_handler($form, 'validate', '_xedc_auth_validate_password');
  // 只处理前台注册页，不影响 /admin/people/create。
  if (_xedc_auth_route() !== 'user.register') {
    return;
  }
  _xedc_auth_wrap($form, 'register', t('创建账号'), t('注册后即可评论、收藏和互动。'));

  // 精简注册表单：只留 用户名 / 邮箱 / 密码
  foreach (['user_picture', 'timezone', 'language', 'contact'] as $f) {
    if (isset($form[$f])) {
      $form[$f]['#access'] = FALSE;
    }
  }
  if (isset($form['account'])) {
    $a = &$form['account'];
    if (isset($a['name'])) {
      $a['name']['#weight'] = -30;
      $a['name']['#title'] = t('用户名');
      $a['name']['#description'] = '';
      $a['name']['#attributes']['placeholder'] = t('请输入用户名');
    }
    if (isset($a['mail'])) {
      $a['mail']['#weight'] = -20;
      $a['mail']['#title'] = t('邮箱地址');
      $a['mail']['#description'] = '';
      $a['mail']['#attributes']['placeholder'] = t('请输入邮箱地址');
    }
    if (isset($a['pass'])) {
      $a['pass']['#weight'] = -10;
      $a['pass']['#description'] = '';
      $a['pass']['#after_build'][] = '_xedc_auth_pass_after_build';
    }
    unset($a);
  }
  $form['actions']['submit']['#value'] = t('创建账号');
  _xedc_auth_foot($form, t('已有账号？'), t('登录'), 'user.login');
  _xedc_auth_add_handler($form, 'submit', '_xedc_auth_register_submit');
}

function _xedc_auth_register_submit(array &$form, FormStateInterface $form_state): void {
  if (\Drupal::request()->query->has('destination')) {
    return;
  }
  // 需要邮件激活时用户未登录：回到登录页，提示显示在卡片里。
  if (\Drupal::currentUser()->isAnonymous()) {
    $form_state->setRedirect('user.login');
    return;
  }
  $redirect = $form_state->getRedirect();
  if ($redirect instanceof Url && $redirect->isRouted() && $redirect->getRouteName() === 'entity.user.canonical') {
    $form_state->setRedirect('<front>');
  }
}

/**
 * 密码 / 确认密码：输入框内提示，不显示下方说明。
 */
function _xedc_auth_pass_after_build(array $element, FormStateInterface $form_state): array {
  if (isset($element['pass1'])) {
    $new = (bool) \Drupal::request()->query->get('pass-reset-token');
    $element['pass1']['#title'] = $new ? t('新密码') : t('密码');
    $element['pass1']['#description'] = '';
    $element['pass1']['#attributes']['placeholder'] = t('至少 8 位，包含字母和数字');
  }
  if (isset($element['pass2'])) {
    $element['pass2']['#title'] = !empty($new) ? t('确认新密码') : t('确认密码');
    $element['pass2']['#description'] = '';
    $element['pass2']['#attributes']['placeholder'] = t('请再次输入密码');
  }
  return $element;
}

/**
 * Implements hook_form_FORM_ID_alter() for user_pass.
 */
function xedc_form_user_pass_alter(array &$form, FormStateInterface $form_state): void {
  if (_xedc_auth_route() !== 'user.pass') {
    return;
  }
  _xedc_auth_wrap($form, 'pass', t('找回密码'), t('输入账号信息，我们会向账号绑定的邮箱发送重置链接。'));
  if (isset($form['name'])) {
    $form['name']['#title'] = t('用户名或邮箱');
    $form['name']['#description'] = '';
    $form['name']['#attributes']['placeholder'] = t('请输入用户名或邮箱');
  }
  if (isset($form['actions']['submit'])) {
    $form['actions']['submit']['#value'] = t('发送重置链接');
  }
  _xedc_auth_foot($form, t('想起密码了？'), t('返回登录'), 'user.login');
}

/**
 * Implements hook_form_FORM_ID_alter() for user_pass_reset (一次性登录链接页).
 */
function xedc_form_user_pass_reset_alter(array &$form, FormStateInterface $form_state): void {
  _xedc_auth_wrap($form, 'reset', t('重置密码'), t('正在验证链接，请稍候。若没有自动跳转，请点击下方按钮。'));
  if (isset($form['actions']['submit'])) {
    $form['actions']['submit']['#value'] = t('继续设置新密码');
  }
}

/**
 * 登录框输入邮箱时，换成对应的用户名再交给 Drupal 原生验证。
 */
function _xedc_auth_login_email_to_name(array &$element, FormStateInterface $form_state, array &$complete_form): void {
  $value = trim((string) $form_state->getValue('name'));
  if ($value !== '' && str_contains($value, '@')) {
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $value]);
    if ($users) {
      $form_state->setValueForElement($element, reset($users)->getAccountName());
    }
  }
}

/**
 * Implements hook_theme_suggestions_page_alter().
 */
function xedc_theme_suggestions_page_alter(array &$suggestions, array $variables): void {
  if (_xedc_auth_route() === 'user.login' && \Drupal::request()->query->get('xedc_embed')) {
    $suggestions[] = 'page__xedc_embed';
  }
}

/**
 * 密码规则：至少 8 位，且同时包含字母和数字。
 */
function _xedc_auth_validate_password(array &$form, FormStateInterface $form_state): void {
  $pass = $form_state->getValue('pass');
  if (is_array($pass)) {
    $pass = $pass['pass1'] ?? '';
  }
  $pass = (string) $pass;
  if ($pass === '') {
    return;
  }
  if (mb_strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\\d/', $pass)) {
    $form_state->setErrorByName('pass', t('密码至少 8 位，且必须同时包含字母和数字。'));
  }
}

/**
 * Implements hook_form_FORM_ID_alter() for user_form (用户编辑 / 重置后设置新密码).
 */
function xedc_form_user_form_alter(array &$form, FormStateInterface $form_state): void {
  _xedc_auth_add_handler($form, 'validate', '_xedc_auth_validate_password');
  if (_xedc_auth_route() !== 'entity.user.edit_form' || !\Drupal::request()->query->has('pass-reset-token')) {
    return;
  }
  _xedc_auth_wrap($form, 'setpass', t('设置新密码'), '');
  foreach (['user_picture', 'timezone', 'language', 'contact', 'path', 'langcode', 'roles', 'status', 'notify'] as $f) {
    if (isset($form[$f])) {
      $form[$f]['#access'] = FALSE;
    }
  }
  if (isset($form['account'])) {
    foreach (\Drupal\Core\Render\Element::children($form['account']) as $k) {
      if ($k !== 'pass') {
        $form['account'][$k]['#access'] = FALSE;
      }
    }
    $form['account']['pass']['#description'] = '';
    $form['account']['pass']['#after_build'][] = '_xedc_auth_pass_after_build';
  }
  $form['actions']['submit']['#value'] = t('保存新密码');
  _xedc_auth_add_handler($form, 'submit', '_xedc_auth_setpass_submit');
}

function _xedc_auth_setpass_submit(array &$form, FormStateInterface $form_state): void {
  $form_state->setRebuild(FALSE);
  $form_state->setRedirect('<front>');
}

/**
 * 实体表单的按钮自带 #validate/#submit 时挂在按钮上，否则挂在表单上。
 */
function _xedc_auth_add_handler(array &$form, string $type, string $callback): void {
  if (isset($form['actions']['submit']['#' . $type])) {
    $form['actions']['submit']['#' . $type][] = $callback;
  }
  else {
    $form['#' . $type][] = $callback;
  }
}

/**
 * 把常见的英文系统消息换成中文；空字符串表示不显示。
 */
function _xedc_auth_localize_messages(): void {
  $map = [
    'one-time login link that has either been used' => t('该重置链接已被使用或已过期，请在下方重新获取。'),
    'You have used a one-time login link' => '',
  ];
  $messenger = \Drupal::messenger();
  $all = $messenger->deleteAll();
  foreach ($all as $type => $messages) {
    foreach ($messages as $message) {
      $text = (string) $message;
      foreach ($map as $needle => $replacement) {
        if (stripos($text, $needle) !== FALSE) {
          $message = $replacement;
          break;
        }
      }
      if ((string) $message !== '') {
        $messenger->addMessage($message, $type);
      }
    }
  }
}
