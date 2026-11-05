<?php

/**
 * @file
 * Verify XEDC admin content nav and Chinese labels.
 */

use Drupal\user\Entity\User;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

require_once DRUPAL_ROOT . '/themes/custom/xedc_admin/includes/navigation.theme';

$account = User::load(1);
\Drupal::currentUser()->setAccount($account);

echo "=== content children definitions ===\n";
foreach (_xedc_admin_navigation_definitions() as $item) {
  if (($item['id'] ?? '') !== 'content') {
    continue;
  }
  foreach ($item['children'] as $child) {
    echo '- ' . (string) $child['title'] . ' | ' . $child['route'] . "\n";
  }
}

echo "=== visible navigation for uid1 ===\n";
foreach (_xedc_admin_navigation() as $item) {
  if (($item['id'] ?? '') !== 'content') {
    continue;
  }
  foreach ($item['children'] as $child) {
    echo '- ' . $child['title'] . ' => ' . $child['url'] . "\n";
  }
}

$labels = \Drupal::service('xedc_tags.labels');
$ops = [
  'completed', 'pending', 'running', 'failed', 'cancelled',
  'task_enqueue', 'task_start', 'task_complete', 'task_fail', 'task_cancel',
  'alias_delete', 'alias_create', 'alias_update', 'merge_redirect_cleanup',
];
echo "=== label map ===\n";
foreach ($ops as $op) {
  if (str_contains($op, '_') && !in_array($op, ['completed', 'pending', 'running', 'failed', 'cancelled'], TRUE)) {
    echo $op . ' => ' . (string) $labels->operation($op) . "\n";
  }
  else {
    echo $op . ' => ' . (string) $labels->status($op) . "\n";
  }
}

$kernel = \Drupal::service('http_kernel');
$paths = [
  '/admin/content/tags',
  '/admin/content/tags/history',
  '/admin/content/tags/tasks',
  '/admin/content/tags/aliases',
  '/admin/content/tags/merge',
  '/tags',
];
$forbidden = [
  'completed',
  'task_enqueue',
  'alias_delete',
  'alias_create',
  'merge_redirect_cleanup',
  '智能标签管理',
];
echo "=== page chinese scan ===\n";
foreach ($paths as $path) {
  $req = Request::create($path);
  $res = $kernel->handle($req, \Drupal\Core\DrupalKernelInterface::SUB_REQUEST);
  $body = (string) $res->getContent();
  $hits = [];
  foreach ($forbidden as $needle) {
    // Avoid false positives inside JS/CSS class names by checking table/text contexts roughly.
    if (preg_match('/>([^<]*' . preg_quote($needle, '/') . '[^<]*)</u', $body)) {
      $hits[] = $needle;
    }
    elseif (str_contains($body, '>' . $needle . '<') || str_contains($body, '"' . $needle . '"')) {
      // attribute values for statuses are ok to flag if plain text cells.
    }
  }
  // Stronger: check for machine values as plain text cell content.
  foreach (['>completed<', '>task_enqueue<', '>alias_delete<', '>alias_create<', '>merge_redirect_cleanup<', '>pending<', '>running<', '>failed<', '>cancelled<'] as $needle) {
    if (str_contains($body, $needle)) {
      $hits[] = $needle;
    }
  }
  $hasLabel = str_contains($body, '标签统计') || $path === '/tags';
  echo $path . ' HTTP' . $res->getStatusCode() . ' title_ok=' . ($hasLabel ? 'Y' : 'N') . ' machine_hits=' . ($hits ? implode(',', $hits) : 'none') . "\n";
  $kernel->terminate($req, $res);
}

// Active match for tags paths via link definition.
$tagChild = [
  'title' => '标签统计',
  'route' => 'xedc_tags.admin_overview',
  'match' => 'prefix',
  'prefix' => '/admin/content/tags',
];
echo "=== active match ===\n";
foreach (['/admin/content/tags', '/admin/content/tags/list', '/admin/content/tags/history', '/admin/content'] as $path) {
  $req = Request::create($path);
  $route = match ($path) {
    '/admin/content/tags' => 'xedc_tags.admin_overview',
    '/admin/content/tags/list' => 'xedc_tags.admin_list',
    '/admin/content/tags/history' => 'xedc_tags.admin_history',
    default => 'system.admin_content',
  };
  try {
    $route_object = \Drupal::service('router.route_provider')->getRouteByName($route);
  }
  catch (\Throwable $e) {
    echo $path . " route missing\n";
    continue;
  }
  $req->attributes->set(\Drupal\Core\Routing\RouteObjectInterface::ROUTE_NAME, $route);
  $req->attributes->set(\Drupal\Core\Routing\RouteObjectInterface::ROUTE_OBJECT, $route_object);
  \Drupal::requestStack()->push($req);
  // Path.current reads from request stack.
  $active = _xedc_admin_link_active($tagChild);
  echo $path . ' active=' . ($active ? 'Y' : 'N') . "\n";
  \Drupal::requestStack()->pop();
}

// Permission: editor can see, anon cannot.
$am = \Drupal::service('access_manager');
$anon = new \Drupal\Core\Session\AnonymousUserSession();
echo "anon overview=" . ($am->checkNamedRoute('xedc_tags.admin_overview', [], $anon) ? 'Y' : 'N') . "\n";

// Menu link title.
$def = \Drupal::service('plugin.manager.menu.link')->getDefinition('xedc_tags.admin');
echo 'menu_api_title=' . (string) $def['title'] . " parent=" . $def['parent'] . "\n";
