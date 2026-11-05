<?php

/**
 * @file
 * One-shot cancel or complete for concurrent race harness.
 *
 * Usage: drush php:script .../upload-race-one-shot.php -- --op=cancel --sid=... --sha=...
 */

declare(strict_types=1);

use Drupal\Core\Session\UserSession;

$op = (string) (getenv('XEDC_RACE_OP') ?: 'cancel');
$sid = (string) (getenv('XEDC_RACE_SID') ?: '');
$sha = (string) (getenv('XEDC_RACE_SHA') ?: '');
if ($sid === '' || !preg_match('/^[a-f0-9]{32}$/', $sid)) {
  fwrite(STDERR, "bad sid\n");
  exit(2);
}
\Drupal::service('account_switcher')->switchTo(new UserSession(['uid' => 1]));
$uploads = \Drupal::service('xedc_core.upload_session');
$result = $op === 'complete'
  ? $uploads->complete($sid, ['file_sha256' => $sha])
  : $uploads->cancel($sid);
echo json_encode($result, JSON_UNESCAPED_UNICODE), "\n";
