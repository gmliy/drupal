<?php

/**
 * Isolation acceptance for VIP grant fix + redeem codes + prefs removal.
 *
 * Usage: ./vendor/bin/drush php:script web/modules/custom/xedc_core/tests/manual/vip-redeem-acceptance.php
 */

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\xedc_core\Form\VipGrantForm;

$R = [];
$set = static function (string $k, string $v, string $n = '') use (&$R): void {
  $R[$k] = [$v, $n];
  echo "$v\t$k" . ($n !== '' ? "\t$n" : '') . "\n";
};

$prefix = 'vr_' . substr(md5((string) microtime(TRUE)), 0, 6);
$make = static function (string $s) use ($prefix): User {
  $u = User::create([
    'name' => $prefix . '_' . $s,
    'mail' => $prefix . '_' . $s . '@example.test',
    'status' => 1,
    'pass' => 'Tmp!' . random_int(1000, 9999),
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

$membership = \Drupal::service('xedc_core.membership');
$codes = \Drupal::service('xedc_core.vip_codes');
$now = \Drupal::time()->getRequestTime();

$form = \Drupal::formBuilder()->getForm(VipGrantForm::class);
$btn = (string) ($form['actions']['submit']['#value'] ?? '');
$set('GRANT_form_button', $btn === '开通' || str_contains($btn, '开通') ? 'PASS' : 'FAIL', $btn);

$submitGrant = static function (array $values) {
  $form_state = new FormState();
  $form_state->setValues($values);
  $form_obj = \Drupal::formBuilder()->getForm(VipGrantForm::class);
  /** @var \Drupal\xedc_core\Form\VipGrantForm $form */
  $form = \Drupal::classResolver()->getInstanceFromDefinition(VipGrantForm::class);
  $form->validateForm($form_obj, $form_state);
  if (!$form_state->getErrors()) {
    $form->submitForm($form_obj, $form_state);
  }
  return $form_state;
};

$fs = $submitGrant(['name' => $A->getAccountName(), 'operation' => 'grant', 'preset' => '7', 'note' => 'test']);
$set('GRANT_valid_user', empty($fs->getErrors()) && $membership->isActive(User::load($au)) ? 'PASS' : 'FAIL', json_encode($fs->getErrors()));

$fs2 = $submitGrant(['name' => 'no_such_user_xyz', 'operation' => 'grant', 'preset' => '7', 'note' => '']);
$set('GRANT_missing_user', !empty($fs2->getErrors()) ? 'PASS' : 'FAIL');

$C->block();
$C->save();
$fs3 = $submitGrant(['name' => $C->getAccountName(), 'operation' => 'grant', 'preset' => '7', 'note' => '']);
$set('GRANT_blocked', !empty($fs3->getErrors()) ? 'PASS' : 'FAIL');
$C->activate();
$C->save();

$fs4 = $submitGrant(['name' => $A->getAccountName(), 'operation' => 'grant', 'preset' => '30', 'note' => '']);
$set('GRANT_dup_active_blocked', !empty($fs4->getErrors()) ? 'PASS' : 'FAIL');

$before = (int) $membership->load($au)->expires;
$fs5 = $submitGrant(['name' => $A->getAccountName(), 'operation' => 'extend', 'preset' => '7', 'note' => 'ext']);
$after = (int) $membership->load($au)->expires;
$set('GRANT_extend', ($after > $before && empty($fs5->getErrors())) ? 'PASS' : 'FAIL');

$prefs = \Drupal::service('xedc_notify.preferences');
$prefs->save($bu, [
  'follow' => 0,
  'like_photo' => 0,
  'like_article' => 0,
  'like_video' => 0,
  'comment_photo' => 0,
  'comment_reply' => 0,
  'favorite' => 0,
]);
$set('PREF_ignored_follow', $prefs->isEnabled($bu, 'follow') ? 'PASS' : 'FAIL');
$set('PREF_route_exists', \Drupal::service('router.route_provider')->getRouteByName('xedc_notify.preferences')->getPath() === '/member/notification-settings' ? 'PASS' : 'FAIL');

$gen = $codes->generateBatch(3, 14, 0, $prefix . ' batch', 1);
$set('CODE_generate', count($gen['codes']) === 3 ? 'PASS' : 'FAIL');
$hashPlain = $gen['codes'][0];
$row = \Drupal::database()->select('xedc_vip_code', 'c')->fields('c')->condition('code_hash', $codes->hash($codes->normalize($hashPlain)))->execute()->fetchObject();
$set('CODE_hashed_not_plain', ($row && $row->code_hash !== $hashPlain) ? 'PASS' : 'FAIL');
$set('CODE_unique', count(array_unique($gen['codes'])) === 3 ? 'PASS' : 'FAIL');

$r1 = $codes->redeem($hashPlain, User::load($bu));
$set('CODE_redeem', ($r1['vip_days'] === 14 && $membership->isActive(User::load($bu))) ? 'PASS' : 'FAIL');
try {
  $codes->redeem($hashPlain, User::load($au));
  $set('CODE_reuse', 'FAIL');
}
catch (\InvalidArgumentException $e) {
  $set('CODE_reuse', 'PASS', $e->getMessage());
}

$code2 = $gen['codes'][1];
$id2 = (int) \Drupal::database()->select('xedc_vip_code', 'c')->fields('c', ['code_id'])->condition('code_hash', $codes->hash($codes->normalize($code2)))->execute()->fetchField();
$codes->revoke($id2, 1);
try {
  $codes->redeem($code2, User::load($au));
  $set('CODE_revoked', 'FAIL');
}
catch (\InvalidArgumentException $e) {
  $set('CODE_revoked', 'PASS', $e->getMessage());
}

try {
  $codes->generateBatch(1, 7, $now - 10, $prefix . ' exp', 1);
  $set('CODE_reject_past_deadline', 'FAIL');
}
catch (\InvalidArgumentException $e) {
  $set('CODE_reject_past_deadline', 'PASS');
}

$exp2 = $codes->generateBatch(1, 7, $now + 86400, $prefix . ' exp2', 1);
$hid = $codes->hash($codes->normalize($exp2['codes'][0]));
\Drupal::database()->update('xedc_vip_code')->fields(['expires_at' => $now - 5])->condition('code_hash', $hid)->execute();
try {
  $codes->redeem($exp2['codes'][0], User::load($au));
  $set('CODE_expired', 'FAIL');
}
catch (\InvalidArgumentException $e) {
  $set('CODE_expired', 'PASS', $e->getMessage());
}

try {
  $codes->redeem('AAAA-BBBB-CCCC-DDDD', User::load($au));
  $set('CODE_invalid', 'FAIL');
}
catch (\InvalidArgumentException $e) {
  $set('CODE_invalid', 'PASS');
}

try {
  $codes->redeem($gen['codes'][2], new AnonymousUserSession());
  $set('CODE_anon', 'FAIL');
}
catch (\Throwable $e) {
  $set('CODE_anon', 'PASS');
}

$beforeB = (int) $membership->load($bu)->expires;
$code3 = $codes->generateBatch(1, 10, 0, $prefix . ' ext', 1);
$codes->redeem($code3['codes'][0], User::load($bu));
$afterB = (int) $membership->load($bu)->expires;
$set('CODE_extend_active', $afterB >= $beforeB + 10 * 86400 - 5 ? 'PASS' : 'FAIL', "before=$beforeB after=$afterB");

$c4 = $codes->generateBatch(1, 5, 0, $prefix . ' race', 1);
$plain4 = $c4['codes'][0];
$ok = 0;
try {
  $codes->redeem($plain4, User::load($au));
  $ok++;
}
catch (\Throwable) {
}
try {
  $codes->redeem($plain4, User::load($cu));
}
catch (\InvalidArgumentException) {
  $ok++;
}
$redeemedOnce = (int) \Drupal::database()->select('xedc_vip_code', 'c')
  ->condition('code_hash', $codes->hash($codes->normalize($plain4)))
  ->condition('status', 'redeemed')
  ->countQuery()->execute()->fetchField();
$set('CODE_concurrent_serial', ($ok === 2 && $redeemedOnce === 1) ? 'PASS' : 'FAIL');

$set('CODE_tables', (\Drupal::database()->schema()->tableExists('xedc_vip_code') && \Drupal::database()->schema()->tableExists('xedc_vip_code_batch')) ? 'PASS' : 'FAIL');
$set('PERM_redeem_auth', Role::load('authenticated')->hasPermission('redeem xedc vip codes') ? 'PASS' : 'FAIL');
$set('ROUTE_admin_codes', \Drupal::service('router.route_provider')->getRouteByName('xedc_core.vip_codes')->getPath() === '/admin/people/xedc-vip-codes' ? 'PASS' : 'FAIL');
$set('ROUTE_member_redeem', \Drupal::service('router.route_provider')->getRouteByName('xedc_core.vip_redeem')->getPath() === '/member/redeem' ? 'PASS' : 'FAIL');

$navFile = file_get_contents('/var/www/drupal/web/themes/custom/xedc_admin/includes/navigation.theme');
$set('NAV_notify', str_contains($navFile, '通知管理') && str_contains($navFile, 'VIP 卡密管理') ? 'PASS' : 'FAIL');
$set('NAV_keep_three', str_contains($navFile, '会员管理') && str_contains($navFile, '内容审核') && (str_contains($navFile, '前台统计') || str_contains($navFile, '会员与互动')) ? 'PASS' : 'FAIL');
$set('NAV_no_pm_admin', !str_contains($navFile, '私信管理') ? 'PASS' : 'FAIL');

// Member nav no notification settings.
$ctrl = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_member\Controller\MemberController::class);
$ref = new ReflectionClass($ctrl);
$m = $ref->getMethod('shell');
$m->setAccessible(TRUE);
\Drupal::service('account_switcher')->switchTo(User::load($au));
$build = $m->invoke($ctrl, 'overview', ['#markup' => 'x']);
$keys = array_column($build['#nav'], 'key');
\Drupal::service('account_switcher')->switchBack();
$set('NAV_member_no_pref', !in_array('notification_settings', $keys, TRUE) ? 'PASS' : 'FAIL');
$set('NAV_member_redeem', in_array('redeem', $keys, TRUE) ? 'PASS' : 'FAIL');

// Social notify still works with prefs forced off in DB.
$social = \Drupal::service('xedc_notify.social');
$follow = \Drupal::service('xedc_notify.follow');
$follow->unfollow($au, $bu);
$fid = $follow->follow($au, $bu);
$social->onFollow($au, $bu, $fid);
$set('SOC_follow_notify_default_on', ((int) \Drupal::database()->select('xedc_notification', 'n')->condition('dedupe_key', 'follow:' . $fid)->countQuery()->execute()->fetchField() === 1) ? 'PASS' : 'FAIL');

$pass = 0;
$failn = 0;
foreach ($R as $k => $v) {
  if ($v[0] === 'PASS') {
    $pass++;
  }
  else {
    $failn++;
    echo "FAILED $k {$v[1]}\n";
  }
}
echo "SUMMARY pass=$pass fail=$failn\n";
file_put_contents('/tmp/vr_meta.json', json_encode([
  'prefix' => $prefix,
  'a' => $au,
  'b' => $bu,
  'c' => $cu,
  'codes' => $gen['codes'],
], JSON_PRETTY_PRINT));
