<?php

/**
 * Isolation acceptance: header badges, mark-all-read POST, public PM,
 * VIP hub wiring, notify admin UI.
 *
 * Usage: ./vendor/bin/drush php:script web/modules/custom/xedc_notify/tests/manual/notify-vip-admin-ui-acceptance.php
 */

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\User;
use Drupal\xedc_notify\Form\MarkAllReadForm;
use Drupal\xedc_notify\Form\OfficialCampaignForm;

$R = [];
$set = static function (string $k, string $v, string $n = '') use (&$R): void {
  $R[$k] = [$v, $n];
  echo "$v\t$k" . ($n !== '' ? "\t$n" : '') . "\n";
};

$prefix = 'nvui_' . substr(md5((string) microtime(TRUE)), 0, 6);
$pass = 'Tmp!' . random_int(100000, 999999);
$make = static function (string $s) use ($prefix, $pass): User {
  $u = User::create([
    'name' => $prefix . '_' . $s,
    'mail' => $prefix . '_' . $s . '@example.test',
    'status' => 1,
    'pass' => $pass,
  ]);
  $u->save();
  return $u;
};

$A = $make('a');
$B = $make('b');
$C = $make('c');
$au = (int) $A->id();
$bu = (int) $B->id();
$cu = (int) $C->id();
echo "USERS $au $bu $cu prefix=$prefix\n";

$accountSwitcher = \Drupal::service('account_switcher');
$routeProvider = \Drupal::service('router.route_provider');
$notifications = \Drupal::service('xedc_notify.notifications');
$messages = \Drupal::service('xedc_notify.messages');
$alias = \Drupal::service('xedc_member.alias_manager');
$db = \Drupal::database();
$createdTids = [];
$createdMids = [];
$createdNids = [];
$createdBatchId = 0;

// --- A. Header badges ---
$header = file_get_contents(DRUPAL_ROOT . '/themes/custom/xedc/templates/includes/xedc-header.html.twig');
$set('NAV_header_no_badges', (str_contains($header, 'xedc_notify_badges') || str_contains($header, 'header-badges')) ? 'FAIL' : 'PASS');
$set('NAV_header_no_pm_notify_icons', (preg_match('/notify|messages\/|notifications|私信/', $header) && str_contains($header, 'xn-badge')) ? 'FAIL' : 'PASS');

// --- B. Mark all read ---
$accountSwitcher->switchTo(User::load($au));
$createdNids[] = $notifications->create([
  'uid' => $au,
  'category' => 'official',
  'type' => 'test',
  'title' => 'NVUI unread 1',
  'body' => 'body',
  'dedupe_key' => $prefix . ':a1',
]);
$createdNids[] = $notifications->create([
  'uid' => $au,
  'category' => 'social',
  'type' => 'test',
  'title' => 'NVUI unread 2',
  'body' => 'body',
  'dedupe_key' => $prefix . ':a2',
]);
$createdNids[] = $notifications->create([
  'uid' => $bu,
  'category' => 'official',
  'type' => 'test',
  'title' => 'NVUI other user',
  'body' => 'body',
  'dedupe_key' => $prefix . ':b1',
]);
$beforeA = $notifications->unreadCount($au);
$beforeB = $notifications->unreadCount($bu);
$set('READALL_seed', $beforeA >= 2 ? 'PASS' : 'FAIL', "A=$beforeA B=$beforeB");

$form = \Drupal::formBuilder()->getForm(MarkAllReadForm::class, 'all');
$set('READALL_form_method_post', strtolower((string) ($form['#method'] ?? 'post')) === 'post' ? 'PASS' : 'FAIL');
$set('READALL_form_has_submit', isset($form['actions']['submit']) ? 'PASS' : 'FAIL');

$fs = new FormState();
$fs->setValues(['category' => 'all']);
$formObj = \Drupal::classResolver()->getInstanceFromDefinition(MarkAllReadForm::class);
$built = [];
$formObj->buildForm($built, $fs, 'all');
$formObj->submitForm($built, $fs);
$afterA = $notifications->unreadCount($au);
$afterB = $notifications->unreadCount($bu);
$set('READALL_marks_own_only', ($afterA === 0 && $afterB === $beforeB) ? 'PASS' : 'FAIL', "A=$afterA B=$afterB");

$legacy = $routeProvider->getRouteByName('xedc_notify.notification_read_all');
$set('READALL_legacy_route_exists', $legacy->getPath() === '/member/notifications/read-all' ? 'PASS' : 'FAIL');
$ctrl = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_notify\Controller\NotificationController::class);
$resp = $ctrl->markAllRead();
$set('READALL_legacy_get_redirects', str_contains($resp->getTargetUrl(), 'notifications') ? 'PASS' : 'FAIL', $resp->getTargetUrl());
$accountSwitcher->switchBack();

$accountSwitcher->switchTo(new AnonymousUserSession());
$fsAnon = new FormState();
$anonForm = \Drupal::classResolver()->getInstanceFromDefinition(MarkAllReadForm::class);
$anonBuilt = [];
$anonForm->buildForm($anonBuilt, $fsAnon, 'all');
$anonForm->submitForm($anonBuilt, $fsAnon);
$set('READALL_anon_no_crash', 'PASS');
$accountSwitcher->switchBack();

// --- C. Public profile PM ---
$slugPath = $alias->internalPath('messages/to/' . $bu);
$set('PM_alias_nested', $slugPath === '/member/messages/to/' . $bu ? 'PASS' : 'FAIL', (string) $slugPath);
$set('PM_start_route', $routeProvider->getRouteByName('xedc_notify.message_start')->getPath() === '/member/messages/to/{user}' ? 'PASS' : 'FAIL');

$accountSwitcher->switchTo(User::load($au));
$tid1 = $messages->getOrCreateThread($au, $bu);
$createdTids[] = $tid1;
$sent1 = $messages->send(User::load($au), $bu, 'NVUI hello from A');
$createdMids[] = (int) ($sent1['mid'] ?? 0);
$tid2 = $messages->getOrCreateThread($au, $bu);
$set('PM_no_dup_thread', $tid1 === $tid2 ? 'PASS' : 'FAIL', "$tid1/$tid2");
$set('PM_send_ok', ($sent1['mid'] ?? 0) > 0 ? 'PASS' : 'FAIL');

$selfFail = FALSE;
try {
  $messages->getOrCreateThread($au, $au);
}
catch (\Throwable $e) {
  $selfFail = TRUE;
}
$set('PM_no_self', $selfFail ? 'PASS' : 'FAIL');

$C->block();
$C->save();
$blockedFail = FALSE;
try {
  $mc = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_notify\Controller\MessageController::class);
  $mc->start(User::load($cu));
}
catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
  $blockedFail = TRUE;
}
$set('PM_blocked_target', $blockedFail ? 'PASS' : 'FAIL');
$C->activate();
$C->save();

$tidAC = $messages->getOrCreateThread($au, $cu);
$createdTids[] = $tidAC;
$accountSwitcher->switchBack();
$accountSwitcher->switchTo(User::load($bu));
$cross = FALSE;
try {
  $messages->assertParticipant($tidAC, $bu);
}
catch (\Throwable $e) {
  $cross = TRUE;
}
$set('PM_cross_thread_denied', $cross ? 'PASS' : 'FAIL');
$sent2 = $messages->send(User::load($bu), $au, 'NVUI reply from B');
$createdMids[] = (int) ($sent2['mid'] ?? 0);
$set('PM_reply_ok', ($sent2['mid'] ?? 0) > 0 ? 'PASS' : 'FAIL');
$accountSwitcher->switchBack();

$actionsBuilder = \Drupal::service('xedc_notify.profile_actions');
$accountSwitcher->switchTo(new AnonymousUserSession());
$anonActions = $actionsBuilder->actions((string) $bu);
$anonUrl = (string) ($anonActions['#message_url'] ?? '');
$set('PM_anon_login_link', ($anonUrl !== '' && str_contains($anonUrl, 'user/login')) ? 'PASS' : 'FAIL', $anonUrl);
$accountSwitcher->switchBack();

$accountSwitcher->switchTo(User::load($au));
$authActions = $actionsBuilder->actions((string) $bu);
$authUrl = (string) ($authActions['#message_url'] ?? '');
$set('PM_auth_start_link', ($authUrl !== '' && str_contains($authUrl, 'messages/to')) ? 'PASS' : 'FAIL', $authUrl);
$selfActions = $actionsBuilder->actions((string) $au);
$set('PM_no_self_button', (($selfActions['#markup'] ?? NULL) === '') ? 'PASS' : 'FAIL');
$accountSwitcher->switchBack();

// --- D. VIP hub ---
$set('VIP_hub_route', $routeProvider->getRouteByName('xedc_core.vip_hub')->getPath() === '/admin/people/xedc-vip' ? 'PASS' : 'FAIL');
$set('VIP_codes_redirect', str_contains((string) $routeProvider->getRouteByName('xedc_core.vip_codes')->getDefault('_controller'), 'redirectTab') ? 'PASS' : 'FAIL');
$set('VIP_admin_redirect', str_contains((string) $routeProvider->getRouteByName('xedc_core.vip_admin')->getDefault('_controller'), 'redirectTab') ? 'PASS' : 'FAIL');
$set('VIP_notify_records_redirect', str_contains((string) $routeProvider->getRouteByName('xedc_notify.admin_vip')->getDefault('_controller'), 'redirectTab') ? 'PASS' : 'FAIL');
$set('VIP_notify_settings_redirect', str_contains((string) $routeProvider->getRouteByName('xedc_notify.admin_vip_settings')->getDefault('_controller'), 'redirectTab') ? 'PASS' : 'FAIL');

$admin = User::load(1);
$accountSwitcher->switchTo($admin);
$hub = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_core\Controller\VipHubController::class);
$req = \Symfony\Component\HttpFoundation\Request::create('/admin/people/xedc-vip', 'GET', ['tab' => 'overview']);
$build = $hub->hub($req);
$set('VIP_hub_renders', isset($build['tabs'], $build['panel']) ? 'PASS' : 'FAIL');
$redir = $hub->redirectTab('grant');
$set('VIP_redirect_grant', str_contains($redir->getTargetUrl(), 'tab=grant') ? 'PASS' : 'FAIL', $redir->getTargetUrl());

$navTheme = file_get_contents(DRUPAL_ROOT . '/themes/custom/xedc_admin/includes/navigation.theme');
$set('NAV_has_vip_hub', str_contains($navTheme, 'VIP 管理') && str_contains($navTheme, 'xedc_core.vip_hub') ? 'PASS' : 'FAIL');
$set('NAV_has_notify', str_contains($navTheme, '通知管理') ? 'PASS' : 'FAIL');
$set('NAV_no_vip_records_menu', !preg_match("/'title' => t\('VIP 通知记录'\)/", $navTheme) ? 'PASS' : 'FAIL');
$set('NAV_no_vip_settings_menu', !preg_match("/'title' => t\('VIP 提醒设置'\)/", $navTheme) ? 'PASS' : 'FAIL');
$set('NAV_no_vip_codes_menu', !preg_match("/'title' => t\('VIP 卡密管理'\)/", $navTheme) ? 'PASS' : 'FAIL');
$set('NAV_engagement_not_vip_admin', str_contains($navTheme, 'xedc_core.engagement_hub') ? 'PASS' : 'FAIL');

$grantForm = \Drupal::formBuilder()->getForm(\Drupal\xedc_core\Form\VipGrantForm::class);
$set('VIP_grant_button', str_contains((string) ($grantForm['actions']['submit']['#value'] ?? ''), '开通') ? 'PASS' : 'FAIL');

$membership = \Drupal::service('xedc_core.membership');
$codes = \Drupal::service('xedc_core.vip_codes');
$now = \Drupal::time()->getRequestTime();
$membership->grant($au, $now, $now + 7 * 86400, 1, 'nvui');
$set('VIP_grant_via_manager', $membership->isActive(User::load($au)) ? 'PASS' : 'FAIL');
$batch = $codes->generateBatch(2, 7, $now + 30 * 86400, 'nvui batch', 1);
$plain = $batch['codes'] ?? [];
$createdBatchId = (int) ($batch['batch_id'] ?? 0);
$set('VIP_code_generate', count($plain) === 2 ? 'PASS' : 'FAIL');
$code1 = $plain[0] ?? '';
try {
  $codes->redeem($code1, User::load($bu));
  $set('VIP_code_redeem', $membership->isActive(User::load($bu)) ? 'PASS' : 'FAIL');
}
catch (\Throwable $e) {
  $set('VIP_code_redeem', 'FAIL', $e->getMessage());
}
$dup = FALSE;
try {
  $codes->redeem($code1, User::load($cu));
}
catch (\Throwable $e) {
  $dup = TRUE;
}
$set('VIP_code_no_reuse', $dup ? 'PASS' : 'FAIL');

$settingsForm = \Drupal::formBuilder()->getForm(\Drupal\xedc_notify\Form\VipNotifySettingsForm::class);
$set('VIP_reminders_form', isset($settingsForm['vip_reminder_offsets']) ? 'PASS' : 'FAIL');

$accountSwitcher->switchBack();
$accountSwitcher->switchTo(User::load($au));
$memberHub = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_core\Controller\VipHubController::class);
$ref = new ReflectionClass($memberHub);
$method = $ref->getMethod('availableTabs');
$method->setAccessible(TRUE);
$tabs = $method->invoke($memberHub);
$set('VIP_member_no_tabs', $tabs === [] ? 'PASS' : 'FAIL', json_encode(array_keys($tabs)));
$accountSwitcher->switchBack();

// --- E. Notify admin UI ---
$accountSwitcher->switchTo($admin);
$nac = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_notify\Controller\AdminNotificationController::class);
$overview = $nac->overview();
$set('NOTIFY_admin_overview_ui', isset($overview['stats'], $overview['header']) && in_array('xedc_notify/admin', $overview['#attached']['library'] ?? [], TRUE) ? 'PASS' : 'FAIL');
$list = $nac->officialList();
$set('NOTIFY_admin_list_ui', isset($list['panel']['table']) ? 'PASS' : 'FAIL');
$campForm = \Drupal::formBuilder()->getForm(OfficialCampaignForm::class);
$set('NOTIFY_campaign_default_uids', (($campForm['panel']['audience']['#default_value'] ?? '') === 'uids') ? 'PASS' : 'FAIL');
$saveLabel = (string) ($campForm['panel']['actions']['save']['#value'] ?? '');
$set('NOTIFY_campaign_draft_only', str_contains($saveLabel, '草稿') ? 'PASS' : 'FAIL', $saveLabel);
$accountSwitcher->switchBack();

// Cleanup isolation artifacts only.
if ($createdMids) {
  $db->delete('xedc_pm_message')->condition('mid', $createdMids, 'IN')->execute();
}
if ($createdNids) {
  $db->delete('xedc_notification')->condition('id', array_filter($createdNids), 'IN')->execute();
}
foreach (array_unique(array_filter($createdTids)) as $tid) {
  $left = (int) $db->select('xedc_pm_message', 'm')->condition('tid', $tid)->countQuery()->execute()->fetchField();
  if ($left === 0) {
    $db->delete('xedc_pm_thread_state')->condition('tid', $tid)->execute();
    $db->delete('xedc_pm_thread')->condition('tid', $tid)->execute();
  }
}
if ($createdBatchId > 0) {
  $db->delete('xedc_vip_code')->condition('batch_id', $createdBatchId)->execute();
  $db->delete('xedc_vip_code_batch')->condition('batch_id', $createdBatchId)->execute();
}
foreach ([$au, $bu, $cu] as $uid) {
  if ($db->schema()->tableExists('xedc_vip')) {
    $db->delete('xedc_vip')->condition('uid', $uid)->execute();
  }
  if ($db->schema()->tableExists('xedc_vip_log')) {
    $db->delete('xedc_vip_log')->condition('uid', $uid)->execute();
  }
  if ($db->schema()->tableExists('xedc_vip_code')) {
    $db->update('xedc_vip_code')->fields(['redeemed_uid' => 0])->condition('redeemed_uid', $uid)->execute();
  }
}
foreach ([$A, $B, $C] as $u) {
  try {
    $u->delete();
  }
  catch (\Throwable $e) {
    echo "WARN cleanup " . $u->id() . ' ' . $e->getMessage() . "\n";
  }
}

$passN = count(array_filter($R, static fn($x) => $x[0] === 'PASS'));
$failN = count(array_filter($R, static fn($x) => $x[0] === 'FAIL'));
echo "SUMMARY pass=$passN fail=$failN total=" . count($R) . "\n";
exit($failN > 0 ? 1 : 0);
