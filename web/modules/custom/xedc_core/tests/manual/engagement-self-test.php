<?php

/**
 * @file
 * Creates only its own users, nodes and files, then deletes them.
 */

declare(strict_types=1);

use Drupal\Core\Session\UserSession;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\xedc_core\Engagement\ZipPendingException;
use Drupal\xedc_core\Entity\DownloadResource;

$failed = 0;
$passed = 0;
$skipped = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed, &$passed): void {
  if ($ok) {
    $passed++;
    echo "PASS $name\n";
    return;
  }
  $failed++;
  echo "FAIL $name" . ($detail !== '' ? " :: $detail" : '') . "\n";
};
$skip = static function (string $name, string $reason) use (&$skipped): void {
  $skipped++;
  echo "NOT TESTED $name :: $reason\n";
};

$guard = \Drupal::service('xedc_core.external_url');
$okUrl = FALSE;
$bad = 0;
try {
  $okUrl = $guard->normalize('https://example.com/a/file.zip?x=1') === 'https://example.com/a/file.zip?x=1';
}
catch (\Throwable) {
  $okUrl = FALSE;
}
foreach (['javascript:alert(1)', 'file:///etc/passwd', 'https://user:pass@example.com/a', 'data:text/plain,hi', '/relative'] as $badUrl) {
  try {
    $guard->normalize($badUrl);
  }
  catch (\InvalidArgumentException) {
    $bad++;
  }
}
$check('external URL accepts https', $okUrl);
$check('external URL rejects unsafe schemes', $bad === 5, (string) $bad);

$qr = \Drupal::service('xedc_core.qr')->svg('https://example.com/node/1');
$check('qr svg is generated', str_contains($qr, '<svg') && str_contains($qr, '<rect') && strlen($qr) > 200);

$created = [
  'users' => [],
  'nodes' => [],
  'media' => [],
  'files' => [],
  'resources' => [],
];
$switcher = \Drupal::service('account_switcher');
$cleanup = static function () use (&$created): void {
  $storage = \Drupal::entityTypeManager()->getStorage('xedc_download');
  if ($created['resources']) {
    $loaded = $storage->loadMultiple($created['resources']);
    if ($loaded) {
      $storage->delete($loaded);
    }
  }
  if ($created['nodes']) {
    $nodes = Node::loadMultiple($created['nodes']);
    if ($nodes) {
      \Drupal::entityTypeManager()->getStorage('node')->delete($nodes);
    }
  }
  if ($created['media']) {
    $media = Media::loadMultiple($created['media']);
    if ($media) {
      \Drupal::entityTypeManager()->getStorage('media')->delete($media);
    }
  }
  if ($created['files']) {
    foreach (File::loadMultiple($created['files']) as $file) {
      $file->delete();
    }
  }
  if ($created['users']) {
    foreach (User::loadMultiple($created['users']) as $user) {
      $user->delete();
    }
  }
};

try {
  $stamp = \Drupal::time()->getRequestTime();
  $member = User::create([
    'name' => 'xedc_engage_member_' . $stamp,
    'mail' => 'xedc_engage_member_' . $stamp . '@example.com',
    'status' => 1,
  ]);
  $member->save();
  $vipUser = User::create([
    'name' => 'xedc_engage_vip_' . $stamp,
    'mail' => 'xedc_engage_vip_' . $stamp . '@example.com',
    'status' => 1,
  ]);
  $vipUser->save();
  $created['users'] = [(int) $member->id(), (int) $vipUser->id()];

  $article = Node::create([
    'type' => 'article',
    'title' => 'XEDC engage article ' . $stamp,
    'status' => 1,
    'moderation_state' => 'published',
    'uid' => 1,
  ]);
  $article->save();
  $created['nodes'][] = (int) $article->id();
  $check('article can be published for the test', $article->isPublished());

  $likes = \Drupal::service('xedc_core.like_manager');
  $switcher->switchTo($member);
  $first = $likes->toggle((int) $member->id(), $article);
  $second = $likes->toggle((int) $member->id(), $article);
  $third = $likes->toggle((int) $member->id(), $article);
  $switcher->switchBack();
  $check('member like starts on', $first['liked'] === TRUE && $first['count'] === 1, json_encode($first));
  $check('member unlike turns off', $second['liked'] === FALSE && $second['count'] === 0, json_encode($second));
  $check('repeat like is on again', $third['liked'] === TRUE && $third['count'] === 1, json_encode($third));
  $duplicate = $likes->add((int) $member->id(), $article);
  $check('duplicate like is rejected', $duplicate === FALSE);
  $check('like count stays one', $likes->count('node', (int) $article->id()) === 1);

  $plain = FALSE;
  try {
    $likes->toggle(0, $article);
    $plain = TRUE;
  }
  catch (\InvalidArgumentException) {
    $plain = FALSE;
  }
  $check('empty guest identity cannot like', $plain === FALSE);
  $guestA = hash('sha256', str_repeat('a', 64));
  $guestB = hash('sha256', str_repeat('b', 64));
  $guestLike = $likes->toggleActor(0, $guestA, $article);
  $guestAgain = $likes->toggleActor(0, $guestA, $article);
  $guestThird = $likes->toggleActor(0, $guestA, $article);
  $check('guest like starts on', $guestLike['liked'] === TRUE && $guestLike['count'] === 2, json_encode($guestLike));
  $check('guest unlike turns off', $guestAgain['liked'] === FALSE && $guestAgain['count'] === 1, json_encode($guestAgain));
  $check('guest repeat like does not go negative', $guestThird['liked'] === TRUE && $guestThird['count'] === 2, json_encode($guestThird));
  $check('same guest is still recognized', $likes->isLikedBy(0, $guestA, (int) $article->id()) === TRUE);
  $check('another guest is separate', $likes->isLikedBy(0, $guestB, (int) $article->id()) === FALSE);
  $otherGuest = $likes->toggleActor(0, $guestB, $article);
  $check('second guest increments the count', $otherGuest['liked'] === TRUE && $otherGuest['count'] === 3, json_encode($otherGuest));
  $check('member like was not replaced', $likes->isLiked((int) $member->id(), (int) $article->id()) === TRUE);
  $check('member list ignores guest likes', $likes->countVisible((int) $member->id()) === 1);
  $badHash = FALSE;
  try {
    $likes->toggleActor(0, 'not-a-token', $article);
    $badHash = TRUE;
  }
  catch (\InvalidArgumentException) {
    $badHash = FALSE;
  }
  $check('invalid guest token is rejected', $badHash === FALSE);
  $floodHash = hash('sha256', str_repeat('c', 64));
  $flood = \Drupal::flood();
  for ($i = 0; $i < 40; $i++) {
    $flood->register('xedc_like_actor', 3600, 'guest:' . $floodHash);
  }
  $limited = FALSE;
  try {
    $likes->toggleActor(0, $floodHash, $article);
  }
  catch (\OverflowException) {
    $limited = TRUE;
  }
  $check('guest flood limit blocks another like', $limited === TRUE);
  $identity = \Drupal::service('xedc_core.guest_identity');
  $stack = \Drupal::requestStack();
  $secret = str_repeat('d', 64);
  $request = \Symfony\Component\HttpFoundation\Request::create('/');
  $request->cookies->set('xedc_guest', $secret);
  $stack->push($request);
  $seen = $identity->existingHash();
  $request->cookies->set('xedc_guest', 'short');
  $invalidCookie = $identity->existingHash();
  $stack->pop();
  $check('guest cookie maps to a hash', $seen === hash('sha256', $secret) && $invalidCookie === '');
  $guestRows = \Drupal::service('xedc_core.engagement_report')->likeRows([
    'bundle' => 'article',
    'q' => 'XEDC engage article ' . $stamp,
    'user' => '',
    'from' => '',
    'to' => '',
    'state' => '1',
    'source' => '',
    'sort' => '',
    'who' => 'guest',
    'tier' => '',
  ]);
  $guestListed = FALSE;
  foreach ($guestRows as $guestRow) {
    $text = implode(' ', array_map(static fn($cell): string => (string) $cell, $guestRow));
    if (str_contains($text, '访客 ·') && !preg_match('/[a-f0-9]{64}/', $text)) {
      $guestListed = TRUE;
    }
  }
  $check('guest likes are listed without the secret', $guestListed);

  $switcher->switchTo($vipUser);
  $other = $likes->toggle((int) $vipUser->id(), $article);
  $switcher->switchBack();
  $check('second member like increments count', $other['count'] === 4, json_encode($other));
  $check('my likes lists the article', $likes->countVisible((int) $member->id()) === 1);

  $membership = \Drupal::service('xedc_core.membership');
  $check('new user is a normal member', $membership->tier($member) === 'member' && $membership->isActive($member) === FALSE);
  $now = \Drupal::time()->getRequestTime();
  $membership->grant((int) $vipUser->id(), $now, $now + (7 * 86400), 1, 'test 7');
  $vipUser = User::load($vipUser->id());
  $check('7 day VIP is active', $membership->isActive($vipUser) && $vipUser->hasRole('vip'));
  $check('remaining days is 7', $membership->snapshot($vipUser)['remaining'] === 7);
  $membership->extend((int) $vipUser->id(), 30, 1, 'test extend');
  $vipUser = User::load($vipUser->id());
  $check('extend adds 30 days', $membership->snapshot($vipUser)['remaining'] >= 36);
  \Drupal::database()->update('xedc_vip')
    ->fields(['expires' => $now - 10])
    ->condition('uid', $vipUser->id())
    ->execute();
  $check('expired VIP loses access immediately', $membership->isActive($vipUser) === FALSE);
  $vipUser = User::load($vipUser->id());
  $check('expired VIP loses the role', !$vipUser->hasRole('vip'));
  $membership->grant((int) $vipUser->id(), $now - 3600, $now + (30 * 86400), 1, 'test 30');
  $vipUser = User::load($vipUser->id());
  $check('30 day VIP is active again', $membership->isActive($vipUser));
  $membership->cancel((int) $vipUser->id(), 1, 'test cancel');
  $vipUser = User::load($vipUser->id());
  $check('cancelled VIP loses access immediately', $membership->isActive($vipUser) === FALSE && !$vipUser->hasRole('vip'));
  $membership->grant((int) $vipUser->id(), $now, $now + 86400, 1, 'restore');
  $vipUser = User::load($vipUser->id());

  $filesystem = \Drupal::service('file_system');
  $privateDir = 'private://xedc-downloads';
  $publicDir = 'public://xedc-engage-test';
  $filesystem->prepareDirectory($privateDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
  $filesystem->prepareDirectory($publicDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
  $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxISEhUSEhIVFhUVFRUVFRUVFRUWFxUVFRUYHSggGBolGxUVITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGhAQGy0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBIgACEQEDEQH/xAAbAAABBQEBAAAAAAAAAAAAAAADAAIEBQYBB//EABQBAQAAAAAAAAAAAAAAAAAAAAD/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AnQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA//9k=');
  $publicReal = $filesystem->realpath('public://xedc-engage-test');
  file_put_contents($publicReal . '/a.jpg', $jpeg);
  file_put_contents($publicReal . '/b.jpg', $jpeg);
  $fileA = File::create(['uri' => 'public://xedc-engage-test/a.jpg', 'filename' => 'a.jpg', 'status' => 1]);
  $fileA->setPermanent();
  $fileA->save();
  $fileB = File::create(['uri' => 'public://xedc-engage-test/b.jpg', 'filename' => 'a.jpg', 'status' => 1]);
  $fileB->setPermanent();
  $fileB->save();
  $created['files'][] = (int) $fileA->id();
  $created['files'][] = (int) $fileB->id();
  $mediaA = Media::create(['bundle' => 'image', 'name' => 'engage a', 'status' => 1, 'field_media_image' => ['target_id' => $fileA->id(), 'alt' => 'a']]);
  $mediaA->save();
  $mediaB = Media::create(['bundle' => 'image', 'name' => 'engage b', 'status' => 1, 'field_media_image' => ['target_id' => $fileB->id(), 'alt' => 'b']]);
  $mediaB->save();
  $created['media'] = [(int) $mediaA->id(), (int) $mediaB->id()];
  $photo = Node::create([
    'type' => 'photo',
    'title' => 'XEDC engage photo ' . $stamp,
    'status' => 1,
    'moderation_state' => 'published',
    'uid' => 1,
    'field_images' => [
      ['target_id' => $mediaA->id()],
      ['target_id' => $mediaB->id()],
    ],
  ]);
  $photo->save();
  $created['nodes'][] = (int) $photo->id();
  $originals = \Drupal::service('xedc_core.original_files')->forNode($photo);
  $check('originals are the uploaded files', count($originals) === 2 && $originals[0]['extension'] === 'jpg', json_encode(array_column($originals, 'extension')));

  $privateUri = 'private://xedc-downloads/engage-' . $stamp . '.txt';
  file_put_contents($filesystem->realpath('private://xedc-downloads') . '/engage-' . $stamp . '.txt', 'hello-xedc');
  $private = File::create(['uri' => $privateUri, 'filename' => 'engage-' . $stamp . '.txt', 'status' => 1]);
  $private->setPermanent();
  $private->save();
  $created['files'][] = (int) $private->id();

  $publicResource = DownloadResource::create([
    'title' => '公开说明',
    'parent' => $photo->id(),
    'source_type' => 'upload',
    'file' => $private->id(),
    'access_level' => 'public',
    'status' => 1,
  ]);
  $publicResource->save();
  $created['resources'][] = (int) $publicResource->id();

  $vipFileUri = 'private://xedc-downloads/engage-vip-' . $stamp . '.pdf';
  file_put_contents($filesystem->realpath('private://xedc-downloads') . '/engage-vip-' . $stamp . '.pdf', '%PDF-1.1 engage');
  $vipFile = File::create(['uri' => $vipFileUri, 'filename' => 'engage-vip-' . $stamp . '.pdf', 'status' => 1]);
  $vipFile->setPermanent();
  $vipFile->save();
  $created['files'][] = (int) $vipFile->id();
  $vipResource = DownloadResource::create([
    'title' => 'VIP 文件',
    'parent' => $photo->id(),
    'source_type' => 'upload',
    'file' => $vipFile->id(),
    'access_level' => 'vip',
    'status' => 1,
  ]);
  $vipResource->save();
  $created['resources'][] = (int) $vipResource->id();

  $memberResource = DownloadResource::create([
    'title' => '会员外链',
    'parent' => $article->id(),
    'source_type' => 'external',
    'external_url' => 'https://example.com/packs/demo.zip',
    'access_level' => 'authenticated',
    'status' => 1,
  ]);
  $memberResource->save();
  $created['resources'][] = (int) $memberResource->id();
  $externalBlocked = FALSE;
  try {
    DownloadResource::create([
      'title' => '危险外链',
      'parent' => $article->id(),
      'source_type' => 'external',
      'external_url' => 'javascript:alert(1)',
      'access_level' => 'public',
      'status' => 1,
    ])->save();
  }
  catch (\Throwable) {
    $externalBlocked = TRUE;
  }
  $vipExternalBlocked = FALSE;
  try {
    DownloadResource::create([
      'title' => 'VIP 外链',
      'parent' => $article->id(),
      'source_type' => 'external',
      'external_url' => 'https://example.com/secret.zip',
      'access_level' => 'vip',
      'status' => 1,
    ])->save();
  }
  catch (\Throwable) {
    $vipExternalBlocked = TRUE;
  }
  $check('dangerous external URL cannot be saved', $externalBlocked);
  $check('VIP cannot use a permanent external URL', $vipExternalBlocked);

  $zipResource = DownloadResource::create([
    'title' => '整组原图',
    'parent' => $photo->id(),
    'source_type' => 'zip',
    'access_level' => 'public',
    'status' => 1,
  ]);
  $zipResource->save();
  $created['resources'][] = (int) $zipResource->id();
  $originalResource = DownloadResource::create([
    'title' => '单张原图',
    'parent' => $photo->id(),
    'source_type' => 'originals',
    'access_level' => 'authenticated',
    'status' => 1,
  ]);
  $originalResource->save();
  $created['resources'][] = (int) $originalResource->id();

  $access = \Drupal::service('xedc_core.download_access');
  $anonymous = new UserSession(['uid' => 0]);
  $publicDecision = $access->decide($publicResource, $anonymous);
  $vipAnon = $access->decide($vipResource, $anonymous);
  $memberDecision = $access->decide($memberResource, $member);
  $memberVip = $access->decide($vipResource, $member);
  $vipOk = $access->decide($vipResource, $vipUser);
  $check('anonymous can download a public file', $publicDecision['allow'] === TRUE, json_encode($publicDecision));
  $check('anonymous cannot download VIP', $vipAnon['allow'] === FALSE && $vipAnon['status'] === 403, json_encode($vipAnon));
  $check('member can download a member resource', $memberDecision['allow'] === TRUE, json_encode($memberDecision));
  $check('member cannot download VIP', $memberVip['allow'] === FALSE, json_encode($memberVip));
  $check('active VIP can download VIP', $vipOk['allow'] === TRUE, json_encode($vipOk));

  $headers = \Drupal::moduleHandler()->invokeAll('file_download', [$privateUri]);
  $check('direct private file URL is denied', in_array(-1, $headers, TRUE), json_encode($headers));

  $controller = \Drupal\xedc_core\Controller\DownloadDeliveryController::create(\Drupal::getContainer());
  $switcher->switchTo($anonymous);
  $response = $controller->file($publicResource);
  $switcher->switchBack();
  ob_start();
  $response->sendContent();
  $body = ob_get_clean();
  $check('public upload streams the private file', $response->getStatusCode() === 200 && $body === 'hello-xedc', (string) $response->getStatusCode() . ' ' . substr((string) $body, 0, 40));
  $check('download log records an authorization', (int) \Drupal::database()->select('xedc_download_log', 'd')->condition('resource_id', $publicResource->id())->countQuery()->execute()->fetchField() === 1);

  $switcher->switchTo($anonymous);
  $zipResponse = $controller->archive($zipResource);
  $switcher->switchBack();
  ob_start();
  $zipResponse->sendContent();
  $zipBody = ob_get_clean();
  $zipOk = $zipResponse->getStatusCode() === 200 && is_string($zipBody) && str_starts_with($zipBody, 'PK');
  $check('zip of originals is generated', $zipOk, (string) $zipResponse->getStatusCode() . ' ' . substr((string) $zipBody, 0, 20));
  $opened = FALSE;
  $names = [];
  $stored = FALSE;
  $bytesOk = FALSE;
  $zipFile = new ZipArchive();
  $zipTemp = tempnam(sys_get_temp_dir(), 'xedc-zip');
  if (is_string($zipTemp) && is_string($zipBody)) {
    file_put_contents($zipTemp, $zipBody);
    $opened = $zipFile->open($zipTemp) === TRUE;
    if ($opened) {
      $stored = TRUE;
      $bytesOk = $zipFile->numFiles === 2;
      for ($i = 0; $i < $zipFile->numFiles; $i++) {
        $stat = $zipFile->statIndex($i);
        $names[] = (string) ($stat['name'] ?? '');
        if ((int) ($stat['comp_method'] ?? -1) !== ZipArchive::CM_STORE) {
          $stored = FALSE;
        }
        if ($zipFile->getFromIndex($i) !== $jpeg) {
          $bytesOk = FALSE;
        }
      }
      $zipFile->close();
    }
    @unlink($zipTemp);
  }
  $check('zip extracts both original bytes without recompressing', $opened && $names === ['a.jpg', 'a_2.jpg'] && $stored && $bytesOk, implode(',', $names));
  $switcher->switchTo($anonymous);
  $zipAgain = $controller->archive($zipResource);
  $switcher->switchBack();
  ob_start();
  $zipAgain->sendContent();
  $zipAgainBody = ob_get_clean();
  $check('second zip request reuses the finished archive', $zipAgain->getStatusCode() === 200 && $zipAgainBody === $zipBody);
  $builder = \Drupal::service('xedc_core.zip_builder');
  $check('ten files at 32MB stay synchronous', $builder->shouldQueue(10, 33554432) === FALSE);
  $check('eleven files are queued', $builder->shouldQueue(11, 1000) === TRUE);
  $check('more than 32MB is queued', $builder->shouldQueue(2, 33554433) === TRUE);
  $check('unknown size is queued', $builder->shouldQueue(2, 0) === TRUE);
  $finished = $builder->readyUri($zipResource, $photo);
  $finishedReal = \Drupal::service('file_system')->realpath($finished);
  if (is_string($finishedReal) && is_file($finishedReal)) {
    @unlink($finishedReal);
  }

  $shares = \Drupal::service('xedc_core.share_recorder');
  $switcher->switchTo($anonymous);
  $saved = $shares->record($article, 'copy', 'success', \Drupal::currentUser());
  $again = $shares->record($article, 'copy', 'success', \Drupal::currentUser());
  $panel = $shares->record($article, 'panel', 'intent', \Drupal::currentUser());
  $fake = $shares->record($article, 'panel', 'success', \Drupal::currentUser());
  $switcher->switchBack();
  $check('anonymous copy is recorded once', $saved === TRUE && $again === FALSE);
  $check('panel open is not a successful share', $panel === TRUE && $fake === FALSE);

  $articleId = (int) $article->id();
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $storage->delete([$storage->load($articleId)]);
  $created['nodes'] = array_values(array_filter($created['nodes'], static fn(int $id): bool => $id !== $articleId));
  $leftLikes = (int) \Drupal::database()->select('xedc_like', 'l')->condition('entity_id', $articleId)->countQuery()->execute()->fetchField();
  $leftResources = (int) \Drupal::entityTypeManager()->getStorage('xedc_download')->getQuery()->accessCheck(FALSE)->condition('parent', $articleId)->count()->execute();
  $check('deleting content removes likes', $leftLikes === 0, (string) $leftLikes);
  $check('deleting content removes download resources', $leftResources === 0, (string) $leftResources);
  $created['resources'] = array_values(array_filter($created['resources'], static function (int $id) use ($memberResource): bool {
    return $id !== (int) $memberResource->id();
  }));

  $editor = \Drupal\user\Entity\Role::load('content_editor');
  if (!$editor) {
    $skip('content editor permissions', '没有 content_editor 角色。');
  }
  else {
    $check('content editor cannot administer engagement', !$editor->hasPermission('view xedc engagement statistics') && !$editor->hasPermission('administer xedc engagement') && !$editor->hasPermission('administer xedc vip') && !$editor->hasPermission('administer xedc downloads'));
  }
  $access = \Drupal::accessManager();
  $anonymousAccount = new UserSession(['uid' => 0]);
  $check('anonymous cannot open the engagement center', $access->checkNamedRoute('xedc_core.engagement_hub', [], $anonymousAccount) === FALSE);
  $check('anonymous cannot export engagement csv', $access->checkNamedRoute('xedc_core.engagement_export', [], $anonymousAccount) === FALSE);

  $schemes = \Drupal::database()->query("SELECT SUBSTRING_INDEX(uri, ':', 1) AS scheme, COUNT(*) AS total FROM {file_managed} GROUP BY scheme")->fetchAllKeyed();
  echo 'INFO file schemes ' . json_encode($schemes) . "\n";
  $remote = \Drupal::database()->query("SELECT uri FROM {file_managed} WHERE uri LIKE 's3://%' OR uri LIKE 's3fs://%' ORDER BY fid DESC LIMIT 1")->fetchField();
  if (!$remote) {
    $skip('R2 object read', '没有 s3:// 或 s3fs:// 文件记录，未改动对象存储配置。');
  }
  else {
    $handle = @fopen((string) $remote, 'rb');
    $chunk = $handle ? fread($handle, 16) : FALSE;
    if (is_resource($handle)) {
      fclose($handle);
    }
    $check('R2 or S3 stream reads without loading the whole object', is_string($chunk) && $chunk !== '', (string) $remote);
  }
}
catch (\Throwable $e) {
  $failed++;
  echo 'FAIL unexpected ' . $e::class . ' ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
  try {
    $switcher->switchBack();
  }
  catch (\Throwable) {
  }
}
finally {
  try {
    $cleanup();
  }
  catch (\Throwable $e) {
    echo "FAIL cleanup " . $e->getMessage() . "\n";
    $failed++;
  }
}

echo "SUMMARY passed=$passed failed=$failed skipped=$skipped\n";
if ($failed > 0) {
  exit(1);
}
