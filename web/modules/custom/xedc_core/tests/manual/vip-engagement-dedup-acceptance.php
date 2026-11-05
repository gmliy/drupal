<?php

/**
 * Isolation acceptance: VIP hub vs engagement hub dedup.
 *
 * Usage: ./vendor/bin/drush php:script web/modules/custom/xedc_core/tests/manual/vip-engagement-dedup-acceptance.php
 */

use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

$R = [];
$set = static function (string $k, string $v, string $n = '') use (&$R): void {
  $R[$k] = [$v, $n];
  echo "$v\t$k" . ($n !== '' ? "\t$n" : '') . "\n";
};

$routeProvider = \Drupal::service('router.route_provider');
$accountSwitcher = \Drupal::service('account_switcher');
$admin = User::load(1);
$accountSwitcher->switchTo($admin);

// Routes
$set('ROUTE_vip_hub', $routeProvider->getRouteByName('xedc_core.vip_hub')->getPath() === '/admin/people/xedc-vip' ? 'PASS' : 'FAIL');
$set('ROUTE_engagement_hub', $routeProvider->getRouteByName('xedc_core.engagement_hub')->getPath() === '/admin/xedc/engagement' ? 'PASS' : 'FAIL');
$set('ROUTE_vip_admin_redirect', str_contains((string) $routeProvider->getRouteByName('xedc_core.vip_admin')->getDefault('_controller'), 'redirectTab') ? 'PASS' : 'FAIL');

// Local tasks: engagement base must not be vip_admin; no VIP tab.
$tasks = \Drupal::service('plugin.manager.menu.local_task')->getLocalTasksForRoute('xedc_core.engagement_hub');
$titles = [];
$bases = [];
foreach ($tasks as $level) {
  foreach ($level as $task) {
    $def = $task->getPluginDefinition();
    $titles[] = (string) ($def['title'] ?? '');
    $bases[] = (string) ($def['base_route'] ?? '');
  }
}
$set('TASK_no_vip_in_engagement', !preg_match('/VIP/', implode('|', $titles)) ? 'PASS' : 'FAIL', implode('|', $titles));
$set('TASK_base_engagement_hub', !in_array('xedc_core.vip_admin', $bases, TRUE) && in_array('xedc_core.engagement_hub', $bases, TRUE) ? 'PASS' : 'FAIL', implode(',', array_unique($bases)));

// VIP hub render
$hub = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_core\Controller\VipHubController::class);
$req = Request::create('/admin/people/xedc-vip', 'GET', ['tab' => 'overview']);
$build = $hub->hub($req);
$html = '';
if (isset($build['header']['#markup'])) {
  $html .= (string) $build['header']['#markup'];
}
$set('VIP_hub_title', str_contains($html, 'VIP 管理') ? 'PASS' : 'FAIL');
$set('VIP_hub_has_tabs', isset($build['tabs']['#items']) && count($build['tabs']['#items']) >= 3 ? 'PASS' : 'FAIL');
$set('VIP_hub_has_header_stats', isset($build['summary']) ? 'PASS' : 'FAIL');

$reqGrant = Request::create('/admin/people/xedc-vip', 'GET', ['tab' => 'grant']);
$grant = $hub->hub($reqGrant);
$hasGrantForm = isset($grant['panel']['content']['legacy']['form']);
$set('VIP_grant_has_form', $hasGrantForm ? 'PASS' : 'FAIL');

// Engagement overview must not embed VIP grant form / VIP tabs.
$eng = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_core\Controller\EngagementAdminController::class);
$overview = $eng->overview();
$set('ENG_no_vip_grant_form', !isset($overview['form']) || (($overview['form']['#form_id'] ?? '') !== 'xedc_core_vip_grant') ? 'PASS' : 'FAIL');
$set('ENG_no_vip_hub_tabs', !isset($overview['tabs']) ? 'PASS' : 'FAIL');
$set('ENG_has_ops_chrome', in_array('xedc-ops', $overview['#attributes']['class'] ?? [], TRUE) ? 'PASS' : 'FAIL');
$set('ENG_title_front_stats', str_contains((string) ($overview['header']['#markup'] ?? ''), '前台统计') ? 'PASS' : 'FAIL');
// No dedicated "有效 VIP" management card.
$set('ENG_no_vip_active_card', !str_contains((string) ($overview['cards']['#markup'] ?? ''), '有效 VIP') ? 'PASS' : 'FAIL');

// Navigation theme source checks
$nav = file_get_contents(DRUPAL_ROOT . '/themes/custom/xedc_admin/includes/navigation.theme');
$set('NAV_engagement_route', str_contains($nav, "'route' => 'xedc_core.engagement_hub'") ? 'PASS' : 'FAIL');
$set('NAV_vip_route', str_contains($nav, "'route' => 'xedc_core.vip_hub'") ? 'PASS' : 'FAIL');
$set('NAV_no_vip_under_engagement_prefixes', !preg_match("/前台统计[\s\S]{0,400}'\/admin\/xedc\/vip'/", $nav) ? 'PASS' : 'FAIL');
$set('NAV_exclude_people_xedc', str_contains($nav, '/admin/people/xedc-vip') && str_contains($nav, 'exclude_prefixes') ? 'PASS' : 'FAIL');

// Menu YAML: legacy VIP menus disabled
$menuCore = file_get_contents(DRUPAL_ROOT . '/modules/custom/xedc_core/xedc_core.links.menu.yml');
$menuNotify = file_get_contents(DRUPAL_ROOT . '/modules/custom/xedc_notify/xedc_notify.links.menu.yml');
$set('MENU_vip_admin_disabled', preg_match("/xedc_core\.vip_admin:[\s\S]*?enabled:\s*false/", $menuCore) ? 'PASS' : 'FAIL');
$set('MENU_notify_vip_disabled', preg_match("/xedc_notify\.admin_vip:[\s\S]*?enabled:\s*false/", $menuNotify) ? 'PASS' : 'FAIL');

// Permissions: member denied
$accountSwitcher->switchBack();
$member = User::load(323) ?: User::load(2);
if ($member) {
  $access = \Drupal::service('access_manager');
  $accountSwitcher->switchTo($member);
  $set('PERM_member_vip_deny', !$access->checkNamedRoute('xedc_core.vip_hub', [], $member, TRUE)->isAllowed() ? 'PASS' : 'FAIL');
  $set('PERM_member_eng_check', $access->checkNamedRoute('xedc_core.engagement_hub', [], $member, TRUE)->isAllowed() ? 'PASS' : 'NOTE', 'depends on role');
  $accountSwitcher->switchBack();
}
else {
  $set('PERM_member_vip_deny', 'NOT_TESTED', 'no member user');
}

$accountSwitcher->switchTo($admin);
$set('PERM_admin_vip_allow', \Drupal::service('access_manager')->checkNamedRoute('xedc_core.vip_hub', [], $admin, TRUE)->isAllowed() ? 'PASS' : 'FAIL');
$accountSwitcher->switchBack();

$passN = count(array_filter($R, static fn($x) => $x[0] === 'PASS'));
$failN = count(array_filter($R, static fn($x) => $x[0] === 'FAIL'));
$skipN = count(array_filter($R, static fn($x) => !in_array($x[0], ['PASS', 'FAIL'], TRUE)));
echo "SUMMARY pass=$passN fail=$failN other=$skipN total=" . count($R) . "\n";
return;
