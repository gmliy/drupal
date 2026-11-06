<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/sitemap.xml' && is_file(__DIR__ . '/sitemap.xml')) {
  header('Content-Type: application/xml; charset=UTF-8');
  readfile(__DIR__ . '/sitemap.xml');
  exit;
}
$ok = ['/', '/user/login', '/admin', '/models', '/articles', '/sitemap.xml', '/video'];
if (!in_array($path, $ok, true)) {
  http_response_code(404);
  echo 'missing';
  exit;
}
http_response_code(200);
header('Content-Type: text/html; charset=UTF-8');
echo '<!doctype html><title>ok</title><p>' . htmlspecialchars($path, ENT_QUOTES) . '</p>';
if ($path === '/video') {
  echo '<video src="/sites/default/files/clip.mp4" controls></video>';
}
