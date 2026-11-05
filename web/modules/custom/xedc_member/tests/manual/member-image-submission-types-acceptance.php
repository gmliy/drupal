<?php

/**
 * @file
 * Acceptance: member hub separates 图片投稿 vs 压缩包投稿; gallery entry gone.
 *
 * Run: vendor/bin/drush php:script web/modules/custom/xedc_member/tests/manual/member-image-submission-types-acceptance.php
 */

declare(strict_types=1);

use Drupal\Core\Session\UserSession;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$results = [];
$pass = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
  $results[] = ['name' => $name, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
  echo ($ok ? 'PASS' : 'FAIL') . "\t{$name}" . ($detail !== '' ? "\t{$detail}" : '') . PHP_EOL;
};

$pushRequest = static function (string $uri, array $query = []): void {
  $request = Request::create($uri, 'GET', $query);
  $request->setSession(new Session(new MockArraySessionStorage()));
  \Drupal::requestStack()->push($request);
};

$web = \Drupal::root();
$controllerSrc = file_get_contents($web . '/modules/custom/xedc_member/src/Controller/MemberController.php') ?: '';
$pass('hub_no_gallery_entry', !str_contains($controllerSrc, "'key' => 'gallery'") && !str_contains($controllerSrc, '图片集投稿'));
$pass('hub_keeps_photo_and_archive', str_contains($controllerSrc, '图片投稿') && str_contains($controllerSrc, '压缩包投稿') && str_contains($controllerSrc, "upload' => 'archive'"));
$pass('photo_desc_mentions_multi', str_contains($controllerSrc, '多张会组成同一个图片集'));

$coreSrc = file_get_contents($web . '/modules/custom/xedc_core/xedc_core.module') ?: '';
$pass('mode_helper_exists', str_contains($coreSrc, 'function xedc_core_member_photo_upload_mode'));

// Isolate the two inline templates by splitting on mode branches.
$imageTplPos = strpos($coreSrc, 'xedc-upload--images');
$archiveTplPos = strpos($coreSrc, 'xedc-upload--archive');
$pass('both_member_templates_present', $imageTplPos !== FALSE && $archiveTplPos !== FALSE);
if ($imageTplPos !== FALSE && $archiveTplPos !== FALSE) {
  $imageChunk = substr($coreSrc, $imageTplPos, 900);
  $archiveChunk = substr($coreSrc, $archiveTplPos, 900);
  $pass('image_template_has_multi', str_contains($imageChunk, 'data-xedc-images') && str_contains($imageChunk, 'multiple'));
  $pass('image_template_omits_archive_drop', !str_contains($imageChunk, 'data-xedc-archive'));
  $pass('archive_template_has_zip', str_contains($archiveChunk, 'data-xedc-archive'));
  $pass('archive_template_omits_image_drop', !str_contains($archiveChunk, 'data-xedc-images'));
}

$js = file_get_contents($web . '/modules/custom/xedc_core/js/asset-upload.js') ?: '';
$pass('js_filters_by_kinds', str_contains($js, 'allowedKinds') && str_contains($js, 'jobAllowed'));
$pass('js_blocks_cross_kind', str_contains($js, '请到「压缩包投稿」'));

$member = User::create([
  'name' => 'img_types_' . substr(uniqid('', TRUE), -8),
  'mail' => 'img_types_' . time() . '@example.invalid',
  'status' => 1,
]);
$member->save();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new UserSession([
  'uid' => (int) $member->id(),
  'roles' => $member->getRoles(),
]));

try {
  $pushRequest('/node/add/photo');
  $pass('default_mode_image', xedc_core_member_photo_upload_mode() === 'image');
  \Drupal::requestStack()->pop();

  $pushRequest('/node/add/photo', ['upload' => 'gallery']);
  $pass('legacy_gallery_folds_to_image', xedc_core_member_photo_upload_mode() === 'image');
  \Drupal::requestStack()->pop();

  $pushRequest('/node/add/photo', ['upload' => 'archive']);
  $pass('archive_query_mode', xedc_core_member_photo_upload_mode() === 'archive');
  \Drupal::requestStack()->pop();

  $renderer = \Drupal::service('renderer');

  $pushRequest('/node/add/photo');
  $node = Node::create([
    'type' => 'photo',
    'title' => 'types image',
    'uid' => (int) $member->id(),
    'status' => 0,
  ]);
  $form = [];
  xedc_core_attach_member_photo_upload($form, $node);
  $html = (string) $renderer->renderRoot($form['xedc_import']);
  $pass('image_form_has_multi_input', str_contains($html, 'data-xedc-images') && str_contains($html, 'multiple'));
  $pass('image_form_no_archive_input', !str_contains($html, 'data-xedc-archive'));
  $pass('image_form_kinds_image', str_contains($html, 'data-kinds="image"'));
  $pass('image_settings_kinds', (($form['#attached']['drupalSettings']['xedcAsset']['kinds'] ?? []) === ['image']));
  \Drupal::requestStack()->pop();

  $pushRequest('/node/add/photo', ['upload' => 'archive']);
  $node2 = Node::create([
    'type' => 'photo',
    'title' => 'types archive',
    'uid' => (int) $member->id(),
    'status' => 0,
  ]);
  $form2 = [];
  xedc_core_attach_member_photo_upload($form2, $node2);
  $html2 = (string) $renderer->renderRoot($form2['xedc_import']);
  $pass('archive_form_has_zip_input', str_contains($html2, 'data-xedc-archive'));
  $pass('archive_form_no_batch_image_input', !str_contains($html2, 'data-xedc-images'));
  $pass('archive_form_kinds_archive', str_contains($html2, 'data-kinds="archive"'));
  $pass('archive_settings_kinds', (($form2['#attached']['drupalSettings']['xedcAsset']['kinds'] ?? []) === ['archive']));
  \Drupal::requestStack()->pop();

  $controller = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\xedc_member\Controller\MemberController::class);
  $ref = new ReflectionClass($controller);
  $method = $ref->getMethod('submissionCreateLinks');
  $method->setAccessible(TRUE);
  $links = $method->invoke($controller);
  $keys = array_column($links, 'key');
  $pass('hub_keys_no_gallery', !in_array('gallery', $keys, TRUE));
  $pass('hub_keys_has_photo_archive', in_array('photo', $keys, TRUE) && in_array('archive', $keys, TRUE));
  $photoLink = NULL;
  foreach ($links as $link) {
    if (($link['key'] ?? '') === 'photo') {
      $photoLink = $link;
      break;
    }
  }
  $pass('photo_link_no_upload_query', is_array($photoLink) && !str_contains((string) $photoLink['url'], 'upload='));
}
finally {
  while (\Drupal::requestStack()->getCurrentRequest()) {
    \Drupal::requestStack()->pop();
  }
  $switcher->switchBack();
  if ($fresh = User::load($member->id())) {
    $fresh->delete();
  }
}

$failed = array_filter($results, static fn(array $r): bool => $r['status'] === 'FAIL');
echo PHP_EOL . 'SUMMARY' . "\t" . (count($failed) ? 'FAIL' : 'PASS') . "\t" . (count($results) - count($failed)) . '/' . count($results) . PHP_EOL;
return count($failed) ? 1 : 0;
