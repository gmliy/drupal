<?php

declare(strict_types=1);

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "需要夹具目录\n");
    exit(1);
}
$pass = bin2hex(random_bytes(8));
$salt = bin2hex(random_bytes(16));
$private = $root . '/private';
$settings = <<<PHP
<?php
\$databases['default']['default'] = array(
  'database' => 'xedcfix',
  'username' => 'xedcfix',
  'password' => '{$pass}',
  'host' => 'localhost',
  'port' => '3306',
  'prefix' => '',
);
\$settings['hash_salt'] = '{$salt}';
\$settings['file_private_path'] = '{$private}';
PHP;
file_put_contents($root . '/web/sites/default/settings.php', $settings);
$payload = serialize(['url' => 'https://old.example.test/node/1']);
$sql = "CREATE TABLE config (name varchar(128) NOT NULL, data longtext, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
$sql .= "INSERT INTO config (name, data) VALUES ('system.site', 0x" . bin2hex($payload) . ");\n";
$sql .= "CREATE TABLE users_field_data (uid int NOT NULL, name varchar(60), PRIMARY KEY (uid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
$sql .= "INSERT INTO users_field_data (uid, name) VALUES (1, 'fixture-admin');\n";
$sql .= "-- Dump completed on fixture\n";
file_put_contents($root . '/fixture.sql', $sql);
chmod($root . '/fixture.sql', 0600);
chmod($root . '/web/sites/default/settings.php', 0600);
