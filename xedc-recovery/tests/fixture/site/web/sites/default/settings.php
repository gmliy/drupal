<?php

$databases['default']['default'] = [
  'database' => 'xedc',
  'username' => 'xedc',
  'password' => 'fixture-pass',
  'prefix' => '',
  'host' => 'localhost',
  'port' => '3306',
  'driver' => 'mysql',
];

$settings['hash_salt'] = 'fixture-salt-value-do-not-change';
$settings['trusted_host_patterns'] = [
  '^test1\\.local$',
];
$settings['file_private_path'] = '/var/www/drupal/private';
$settings['config_sync_directory'] = '../config/sync';
