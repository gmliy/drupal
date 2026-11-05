<?php

/**
 * @file
 * Acceptance checks for member import Save vs staff Publish, and preview UI CSS.
 *
 * Run: vendor/bin/drush php:script web/modules/custom/xedc_core/tests/manual/image-submission-preview-ui-acceptance.php
 */

declare(strict_types=1);

use Drupal\Core\Session\UserSession;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\xedc_core\ImportStatus;

$results = [];
$pass = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
  $results[] = ['name' => $name, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
  echo ($ok ? 'PASS' : 'FAIL') . "\t{$name}" . ($detail !== '' ? "\t{$detail}" : '') . PHP_EOL;
};

$web = \Drupal::root();
$theme = $web . '/themes/custom/xedc';
$adminTheme = $web . '/themes/custom/xedc_admin';
$core = $web . '/modules/custom/xedc_core';
$member = $web . '/modules/custom/xedc_member';

$pass('dialog_fix_css_exists', is_file($theme . '/css/dialog-fix.css'));
$pass('dialog_fix_js_exists', is_file($theme . '/js/dialog-fix.js'));

$info = file_get_contents($theme . '/xedc.info.yml') ?: '';
$pass('theme_extends_dialog', str_contains($info, 'core/drupal.dialog') && str_contains($info, 'xedc/dialog_fix'));

$libs = file_get_contents($theme . '/xedc.libraries.yml') ?: '';
$pass('dialog_fix_library', str_contains($libs, "dialog_fix:\n") || str_contains($libs, "dialog_fix:\r\n"));

$dialogCss = file_get_contents($theme . '/css/dialog-fix.css') ?: '';
$pass('close_hides_button_text', str_contains($dialogCss, '.ui-button-text') && str_contains($dialogCss, 'display: none'));
$pass('close_uses_svg_mask', str_contains($dialogCss, 'mask:') && str_contains($dialogCss, 'ui-dialog-titlebar-close::before'));
$pass('front_dialog_compact_grid', str_contains($dialogCss, 'grid-template-columns: repeat(6') && str_contains($dialogCss, 'grid-template-columns: repeat(2') && str_contains($dialogCss, 'object-fit: contain'));

$photoCss = file_get_contents($theme . '/css/photo-detail.css') ?: '';
$pass('img_sheet_two_col', preg_match('/\.img-sheet__grid\s*\{[^}]*grid-template-columns:\s*repeat\(2/s', $photoCss) === 1);
$pass('img_sheet_mobile_one_col', str_contains($photoCss, '@media (max-width: 639px)') && str_contains($photoCss, 'grid-template-columns: 1fr'));
$pass('img_sheet_object_contain', str_contains($photoCss, 'object-fit: contain'));

$adminCss = file_get_contents($core . '/css/admin.css') ?: '';
$pass('admin_import_two_col', str_contains($adminCss, 'grid-template-columns: repeat(2'));
$pass('admin_import_scroll', str_contains($adminCss, 'max-height: min(72vh') && str_contains($adminCss, 'overflow-y: auto'));
$pass('admin_import_object_contain', str_contains($adminCss, 'object-fit: contain'));

$adminDialog = file_get_contents($adminTheme . '/css/dialog.css') ?: '';
$pass('admin_close_hides_text', str_contains($adminDialog, '.ui-button-text'));
$pass('admin_dialog_compact_grid', str_contains($adminDialog, 'grid-template-columns: repeat(6') && str_contains($adminDialog, 'grid-template-columns: repeat(2') && str_contains($adminDialog, 'object-fit: contain'));

$controllerSrc = file_get_contents($core . '/src/Controller/ImportController.php') ?: '';
$pass('member_save_not_publish_route', str_contains($controllerSrc, "\$this->t('保存')") && str_contains($controllerSrc, 'xedc_import_save'));
$pass('staff_keeps_publish_route', str_contains($controllerSrc, 'xedc_core.import_publish') && str_contains($controllerSrc, "\$this->t('发布')"));
$pass('member_help_copy', str_contains($controllerSrc, '不会直接公开发布'));

$listSrc = file_get_contents($core . '/src/Form/ImportListForm.php') ?: '';
$pass('list_member_save_link', str_contains($listSrc, "\$this->t('保存')") && str_contains($listSrc, 'xedc_import_save'));

$memberModule = file_get_contents($member . '/xedc_member.module') ?: '';
$pass('member_save_status_message', str_contains($memberModule, 'xedc_import_save') && str_contains($memberModule, '保存草稿或提交审核'));

$publishAccess = file_get_contents($core . '/src/Access/TaskAccess.php') ?: '';
$pass('publish_still_staff_only', str_contains($publishAccess, 'publish xedc image imports') && str_contains($publishAccess, 'administer xedc image imports'));

/** @var \Drupal\Core\Entity\EntityTypeManagerInterface $etm */
$etm = \Drupal::entityTypeManager();
/** @var \Drupal\xedc_core\TaskRepository $tasks */
$tasks = \Drupal::service('xedc_core.tasks');
/** @var \Drupal\Core\Session\AccountSwitcherInterface $switcher */
$switcher = \Drupal::service('account_switcher');
/** @var \Drupal\Core\Render\RendererInterface $renderer */
$renderer = \Drupal::service('renderer');

$memberUser = User::create([
  'name' => 'preview_ui_' . substr(uniqid('', TRUE), -8),
  'mail' => 'preview_ui_' . time() . '@example.invalid',
  'status' => 1,
]);
$memberUser->save();
$pass('member_user_created', (int) $memberUser->id() > 0, 'uid=' . (int) $memberUser->id());

// Ensure authenticated members can import/edit their own photos for this check.
$needed = ['import photo archives', 'create photo content', 'edit own photo content'];
$available = array_keys(\Drupal::service('user.permissions')->getPermissions());
$toGrant = array_values(array_intersect($needed, $available));
$authRole = Role::load('authenticated');
if ($authRole && $toGrant) {
  $missing = array_values(array_filter($toGrant, static fn(string $p): bool => !$authRole->hasPermission($p)));
  if ($missing) {
    user_role_grant_permissions('authenticated', $missing);
  }
}
$memberUser = User::load($memberUser->id());
$hasImport = $memberUser && $memberUser->hasPermission('import photo archives');
$pass('member_can_import', (bool) $hasImport);
$pass('member_cannot_publish_import', $memberUser && !$memberUser->hasPermission('publish xedc image imports') && !$memberUser->hasPermission('administer xedc image imports'));

$node = Node::create([
  'type' => 'photo',
  'title' => 'Preview UI acceptance ' . date('H:i:s'),
  'uid' => (int) $memberUser->id(),
  'status' => 0,
]);
if ($node->hasField('moderation_state')) {
  $node->set('moderation_state', 'draft');
}
$node->save();
$pass('photo_node_created', (int) $node->id() > 0, 'nid=' . (int) $node->id());

$taskId = $tasks->create([
  'uid' => (int) $memberUser->id(),
  'nid' => (int) $node->id(),
  'status' => ImportStatus::REVIEW,
  'stage' => 'done',
  'progress' => 100,
  'mode' => 'create',
  'archive_name' => 'preview-ui-acceptance.zip',
  'archive_uri' => 'temporary://preview-ui-acceptance.zip',
  'archive_format' => 'zip',
  'archive_bytes' => 1024,
  'source' => 'upload',
  'total' => 2,
  'processed' => 2,
  'success_count' => 2,
]);
$pass('review_task_created', $taskId > 0, 'task=' . $taskId);

$controller = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_core\Controller\ImportController::class);

$switcher->switchTo(new UserSession([
  'uid' => (int) $memberUser->id(),
  'roles' => $memberUser->getRoles(),
]));
try {
  $build = $controller->detail($taskId);
  $html = (string) $renderer->renderRoot($build);
  $pass('member_detail_has_save', str_contains($html, '>保存</a>') || str_contains($html, '>保存</'), substr(strip_tags($html), 0, 120));
  $pass('member_detail_no_publish_label_as_action', !preg_match('/xedc_core\.import_publish|\/admin\/content\/image-imports\/' . $taskId . '\/publish/', $html));
  $pass('member_detail_save_points_to_node_edit', (bool) preg_match('#/node/' . (int) $node->id() . '/edit#', $html));
  $pass('member_detail_has_import_save_flag', str_contains($html, 'xedc_import_save=1'));
  $pass('member_detail_two_col_class', str_contains($html, 'xedc-import-grid--preview'));
  $pass('member_detail_no_staff_logs', !str_contains($html, '处理日志'));
  $pass('member_detail_help', str_contains($html, '不会直接公开发布'));

  $publishDenied = !\Drupal::service('access_manager')->checkNamedRoute('xedc_core.import_publish', ['task' => $taskId], $memberUser);
  $pass('member_publish_route_denied', $publishDenied);
}
finally {
  $switcher->switchBack();
}

$admin = User::load(1);
$switcher->switchTo($admin);
try {
  $build = $controller->detail($taskId);
  $html = (string) $renderer->renderRoot($build);
  $pass('staff_detail_has_publish', (bool) preg_match('#/admin/content/image-imports/' . $taskId . '/publish#', $html) || str_contains($html, '>发布</'));
  $pass('staff_detail_has_logs', str_contains($html, '处理日志'));
  $pass('staff_publish_route_allowed', \Drupal::service('access_manager')->checkNamedRoute('xedc_core.import_publish', ['task' => $taskId], $admin));
}
finally {
  $switcher->switchBack();
}

// Idempotent save path: editing the same node twice must not clone it.
$before = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {node_field_data} WHERE type = :t AND title = :title', [
  ':t' => 'photo',
  ':title' => $node->getTitle(),
])->fetchField();
$nodeReload = Node::load($node->id());
$nodeReload->setTitle($node->getTitle());
$nodeReload->save();
$after = (int) \Drupal::database()->query('SELECT COUNT(*) FROM {node_field_data} WHERE type = :t AND title = :title', [
  ':t' => 'photo',
  ':title' => $node->getTitle(),
])->fetchField();
$pass('save_is_idempotent_same_nid', $before === $after && $before === 1, "before={$before} after={$after}");

// Cleanup fixture task/node/user.
try {
  \Drupal::database()->delete('xedc_import_task')->condition('id', $taskId)->execute();
  if ($fresh = Node::load($node->id())) {
    $fresh->delete();
  }
  if ($freshUser = User::load($memberUser->id())) {
    $freshUser->delete();
  }
}
catch (\Throwable $e) {
  echo "WARN\tcleanup\t" . $e->getMessage() . PHP_EOL;
}

$failed = array_filter($results, static fn(array $r): bool => $r['status'] === 'FAIL');
echo PHP_EOL . 'SUMMARY' . "\t" . (count($failed) ? 'FAIL' : 'PASS') . "\t" . (count($results) - count($failed)) . '/' . count($results) . PHP_EOL;
return count($failed) ? 1 : 0;
