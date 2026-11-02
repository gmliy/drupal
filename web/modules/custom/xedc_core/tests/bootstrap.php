<?php

declare(strict_types=1);

$root = dirname(__DIR__, 5);
require $root . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
  $prefix = 'Drupal\\xedc_core\\';
  if (!str_starts_with($class, $prefix)) {
    return;
  }
  $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
  $path = dirname(__DIR__) . '/src/' . $relative . '.php';
  if (is_file($path)) {
    require $path;
  }
});
