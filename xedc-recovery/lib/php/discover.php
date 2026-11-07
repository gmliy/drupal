<?php

declare(strict_types=1);

function xedc_discover_rules(): array
{
  $file = getenv('XEDC_EXCLUDE_FILE');
  if (!is_string($file) || $file === '') {
    $file = dirname(__DIR__, 2) . '/exclude.list';
  }
  if (!is_file($file)) {
    return [];
  }
  $rules = [];
  foreach (file($file) ?: [] as $line) {
    $line = trim((string) (preg_replace('/\s+#.*$/', '', $line) ?? $line));
    if ($line === '' || str_starts_with($line, '#')) {
      continue;
    }
    $rules[] = $line;
  }
  return $rules;
}

function xedc_discover_excluded(string $value): bool
{
  if ($value === '') {
    return false;
  }
  foreach (xedc_discover_rules() as $rule) {
    if (str_contains($value, $rule)) {
      return true;
    }
  }
  return false;
}

function xedc_sh(string $command): string
{
  $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $proc = proc_open(['bash', '-lc', $command], $spec, $pipes);
  if (!is_resource($proc)) {
    return '';
  }
  $out = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  proc_close($proc);
  return is_string($out) ? trim($out) : '';
}

function xedc_redact(string $line): string
{
  $line = preg_replace('/\b([A-Z0-9_]*(?:TOKEN|SECRET|PASSWORD|KEY)[A-Z0-9_]*)=\S+/i', '$1=***', $line) ?? $line;
  return $line;
}

function xedc_cron_summary(string $line): string
{
  $line = xedc_redact($line);
  if (str_contains($line, 'drush cron')) {
    return 'drush cron';
  }
  if (str_contains($line, 'traffic-sample')) {
    return 'drush xedc:traffic-sample';
  }
  if (str_contains($line, 'backup')) {
    return 'backup';
  }
  if (preg_match('/\s(\/[^\s]+)/', $line, $m) === 1) {
    return basename($m[1]);
  }
  return 'custom';
}

function xedc_site_root(): string
{
  $env = getenv('XEDC_SITE_ROOT');
  if (is_string($env) && $env !== '' && is_file($env . '/web/sites/default/settings.php')) {
    return $env;
  }
  if (is_file('/var/www/drupal/web/sites/default/settings.php')) {
    return '/var/www/drupal';
  }
  return '';
}

function xedc_active_lines(string $src): string
{
  $kept = [];
  foreach (preg_split("/\r\n|\n|\r/", $src) ?: [] as $line) {
    if (preg_match('/^\s*(\*|\/\*|#)/', $line) === 1) {
      continue;
    }
    $kept[] = $line;
  }
  return implode("\n", $kept);
}

function xedc_ini_value(string $dir, string $key): string
{
  if (!is_dir($dir)) {
    return '';
  }
  $value = '';
  $files = glob($dir . '/*.ini') ?: [];
  sort($files);
  foreach ($files as $file) {
    foreach (file($file) ?: [] as $line) {
      if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.+)$/', $line, $m) === 1) {
        $value = trim($m[1]);
      }
    }
  }
  return $value;
}

$site = xedc_site_root();
$hints = [];
if ($site !== '' && is_file($site . '/.xedc-site.json')) {
  $decoded = json_decode((string) file_get_contents($site . '/.xedc-site.json'), true);
  if (is_array($decoded)) {
    $hints = $decoded;
  }
}

$os = [];
$release = @file_get_contents('/etc/os-release') ?: '';
foreach (preg_split("/\n/", $release) ?: [] as $line) {
  if (preg_match('/^([A-Z_]+)="?(.*?)"?$/', $line, $m) === 1) {
    $os[$m[1]] = $m[2];
  }
}

$domain = (string) ($hints['domain'] ?? '');
$www = (bool) ($hints['www'] ?? false);
$settings = $site !== '' ? $site . '/web/sites/default/settings.php' : '';
$db = ['name' => '', 'host' => 'localhost', 'port' => '3306', 'driver' => 'mysql', 'prefix' => ''];
if ($settings !== '' && is_file($settings)) {
  $active = xedc_active_lines((string) file_get_contents($settings));
  if (preg_match_all("/['\"]database['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $active, $m) && $m[1] !== []) {
    $db['name'] = (string) end($m[1]);
  }
  if (preg_match_all("/['\"]host['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $active, $m) && $m[1] !== []) {
    $db['host'] = (string) end($m[1]);
  }
  if (preg_match_all("/['\"]port['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $active, $m) && $m[1] !== []) {
    $db['port'] = (string) end($m[1]);
  }
  require_once __DIR__ . '/settings_db.php';
  try {
    $db['prefix'] = xedc_db_prefix($settings);
  } catch (Throwable $e) {
    $db['prefix'] = '';
  }
  if (preg_match("/trusted_host_patterns[\\s\\S]{0,500}?'\\^([^']+)'/", $active, $m) === 1) {
    $candidate = str_replace(['\\\\.', '\\.'], '.', $m[1]);
    $candidate = rtrim($candidate, '$');
    if ($candidate !== '' && !str_contains($candidate, ' ') && !str_starts_with($candidate, 'www.')) {
      $domain = $candidate;
    }
  }
}

$phpVersion = xedc_sh("php -r 'echo PHP_VERSION;'");
$phpExt = array_values(array_unique(array_filter(explode("\n", xedc_sh("php -m")), static function (string $line): bool {
  return $line !== '' && !str_contains($line, 'Modules') && !str_contains($line, '[');
})));
$phpMinor = '';
if (preg_match('/^(\d+\.\d+)/', $phpVersion, $m) === 1) {
  $phpMinor = $m[1];
}
$forcedPhp = getenv('XEDC_PHP_VERSION');
if (is_string($forcedPhp) && preg_match('/^\d+\.\d+$/', $forcedPhp) === 1) {
  $phpMinor = $forcedPhp;
  $phpVersion = $forcedPhp . '.0';
}
$fpmDir = $phpMinor !== '' ? "/etc/php/{$phpMinor}/fpm/conf.d" : '';
$ini = [
  'memory_limit' => xedc_ini_value($fpmDir, 'memory_limit'),
  'upload_max_filesize' => xedc_ini_value($fpmDir, 'upload_max_filesize'),
  'post_max_size' => xedc_ini_value($fpmDir, 'post_max_size'),
  'max_execution_time' => xedc_ini_value($fpmDir, 'max_execution_time'),
  'max_input_time' => xedc_ini_value($fpmDir, 'max_input_time'),
  'max_input_vars' => xedc_ini_value($fpmDir, 'max_input_vars'),
  'max_file_uploads' => xedc_ini_value($fpmDir, 'max_file_uploads'),
  'date.timezone' => xedc_ini_value($fpmDir, 'date.timezone'),
];
foreach (['upload_max_filesize', 'post_max_size', 'date.timezone'] as $key) {
  if ($ini[$key] === '' && isset($hints[$key])) {
    $ini[$key] = (string) $hints[$key];
  }
}
if (($ini['date.timezone'] ?? '') === '' && isset($hints['timezone'])) {
  $ini['date.timezone'] = (string) $hints['timezone'];
}

$clientMax = (string) ($hints['client_max_body_size'] ?? '');
$nginxConf = xedc_sh("nginx -V 2>&1 || true");
$serverNames = [];
foreach (glob('/etc/nginx/conf.d/*.conf') ?: [] as $conf) {
  if (xedc_discover_excluded(basename($conf))) {
    continue;
  }
  $text = (string) file_get_contents($conf);
  if (preg_match('/client_max_body_size\s+([^;]+);/', $text, $m) === 1 && $clientMax === '') {
    $clientMax = trim($m[1]);
  }
  if (preg_match('/return\s+30[12]\s+https:\/\/www\./', $text) === 1) {
    $www = true;
  }
  if (preg_match_all('/server_name\s+([^;]+);/', $text, $m)) {
    foreach ($m[1] as $names) {
      foreach (preg_split('/\s+/', trim($names)) ?: [] as $name) {
        if ($name !== '' && $name !== '_') {
          $serverNames[] = $name;
        }
      }
    }
  }
}
$serverNames = array_values(array_unique($serverNames));
if ($domain === '' && $serverNames !== []) {
  $domain = $serverNames[0];
}
if ($clientMax === '') {
  $clientMax = '220m';
}

$packages = [];
$pkgRaw = xedc_sh("dpkg-query -W -f='\${Package}\t\${Version}\n' 2>/dev/null | awk '/^(nginx|php|mariadb|redis|ffmpeg|certbot|docker|nodejs|zstd|rclone|7zip|p7zip|imagemagick|fail2ban|logrotate|composer)/{print}'");
foreach (preg_split("/\n/", $pkgRaw) ?: [] as $line) {
  if (!str_contains($line, "\t")) {
    continue;
  }
  [$name, $version] = explode("\t", $line, 2);
  $packages[] = ['name' => $name, 'version' => $version];
}

$commands = [];
foreach (['ffmpeg', 'ffprobe', '7z', '7za', '7zz', 'convert', 'magick', 'wkhtmltopdf', 'pdftotext', 'gs', 'node', 'npm', 'composer', 'drush', 'rclone', 'docker', 'certbot', 'redis-cli', 'nginx'] as $bin) {
  $preferred = '/usr/bin/' . $bin;
  if (($bin === 'node' || $bin === 'npm') && is_executable($preferred)) {
    $path = $preferred;
  } else {
    $path = xedc_sh('command -v ' . escapeshellarg($bin) . ' || true');
  }
  if ($path !== '') {
    $commands[] = ['name' => $bin, 'path' => $path];
  }
}

$ffmpegVersion = xedc_sh('ffmpeg -version 2>/dev/null | head -1 || true');
$encoders = [];
$encoderRaw = xedc_sh('ffmpeg -hide_banner -encoders 2>/dev/null || true');
foreach (['libx264', 'libx265', 'libvpx', 'libvpx-vp9', 'aac'] as $encoder) {
  if (preg_match('/\b' . preg_quote($encoder, '/') . '\b/', $encoderRaw) === 1) {
    $encoders[] = $encoder;
  }
}

$services = [];
$svcRaw = xedc_sh("systemctl list-unit-files --type=service --state=enabled --no-legend 2>/dev/null || true");
foreach (preg_split("/\n/", $svcRaw) ?: [] as $line) {
  if (preg_match('/^(nginx|php.*fpm|mariadb|mysql|redis|docker|netdata|cron|certbot|ufw|fail2ban|xedc-[^.\s]+)\.service\b/', $line, $m) === 1) {
    $services[] = $m[1] . '.service';
  }
}
$services = array_values(array_unique($services));

$restoreSkip = [
  ['name' => 'xedc-push-gmliy.service', 'reason' => '只属于旧服务器，向 Git 推送代码，脚本里有访问令牌'],
  ['name' => 'xedc-push-gmliy.timer', 'reason' => '上面这个推送任务的定时器，新服务器不还原'],
  ['name' => 'xedc-push-gmliy.sh', 'reason' => '推送脚本只在旧服务器使用，不还原到新机器'],
];
$seenSkip = array_column($restoreSkip, 'name');
$pushScan = array_merge(
  glob('/etc/systemd/system/xedc-*') ?: [],
  glob('/usr/local/sbin/xedc-*') ?: [],
  glob('/usr/local/bin/xedc-*') ?: [],
  glob('/etc/cron.d/xedc-*') ?: []
);
foreach ($pushScan as $pushFile) {
  $base = basename($pushFile);
  if (in_array($base, $seenSkip, true)) {
    continue;
  }
  $text = (string) file_get_contents($pushFile);
  if (preg_match('/git\s+push|xedc-push-gmliy/', $text) === 1) {
    $restoreSkip[] = ['name' => $base, 'reason' => '内容是向 Git 推送，只属于旧服务器'];
    $seenSkip[] = $base;
  }
}
$services = array_values(array_filter(
  $services,
  static fn (string $name): bool => !in_array($name, $seenSkip, true)
));

$timers = [];
$timerRaw = xedc_sh("systemctl list-unit-files --type=timer --state=enabled --no-legend 2>/dev/null || true");
foreach (preg_split("/\n/", $timerRaw) ?: [] as $line) {
  if (preg_match('/^(certbot|phpsessionclean|xedc-[^.\s]+)\.timer\b/', $line, $m) === 1) {
    $timers[] = $m[1] . '.timer';
  }
}
$timers = array_values(array_filter(
  array_values(array_unique($timers)),
  static fn (string $name): bool => !in_array($name, $seenSkip, true)
));

$cron = [];
foreach (['root', 'www-data'] as $user) {
  $raw = xedc_sh('crontab -u ' . escapeshellarg($user) . ' -l 2>/dev/null || true');
  foreach (preg_split("/\n/", $raw) ?: [] as $line) {
    $trim = trim($line);
    if ($trim === '' || str_starts_with($trim, '#')) {
      continue;
    }
    if (preg_match('/^(@\S+|\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.+)$/', $trim, $m) === 1) {
      $cron[] = [
        'user' => $user,
        'schedule' => $m[1],
        'summary' => xedc_cron_summary($m[2]),
      ];
    }
  }
}
foreach (glob('/etc/cron.d/*') ?: [] as $cronFile) {
  $base = basename($cronFile);
  if (!str_starts_with($base, 'xedc') && $base !== 'certbot' && $base !== 'php') {
    continue;
  }
  foreach (file($cronFile) ?: [] as $line) {
    $trim = trim($line);
    if ($trim === '' || str_starts_with($trim, '#')) {
      continue;
    }
    if (preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+\S+\s+(.+)$/', $trim, $m) === 1) {
      $cron[] = [
        'user' => 'cron.d/' . $base,
        'schedule' => $m[1],
        'summary' => xedc_cron_summary($m[2]),
      ];
    }
  }
}

$listeners = [];
$seenListeners = [];
$listenRaw = xedc_sh("ss -tlnp 2>/dev/null || netstat -tlnp 2>/dev/null || true");
foreach (preg_split("/\n/", $listenRaw) ?: [] as $line) {
  if (preg_match('/:(\d+)\s+/', $line, $m) !== 1) {
    continue;
  }
  $port = (int) $m[1];
  if (!in_array($port, [80, 443, 3306, 6379, 8088, 19999], true)) {
    continue;
  }
  $process = 'unknown';
  if (preg_match('/\(\("([^"]+)"/', $line, $pm) === 1) {
    $process = $pm[1];
  }
  $key = $port . ' ' . $process;
  if (isset($seenListeners[$key])) {
    continue;
  }
  $seenListeners[$key] = true;
  $listeners[] = ['port' => $port, 'process' => $process];
}

$nodeBin = is_executable('/usr/bin/node') ? '/usr/bin/node' : 'node';
$nodeVersion = xedc_sh(escapeshellarg($nodeBin) . ' -v 2>/dev/null || true');
$compose = [];
foreach (glob('/opt/*/compose.yaml') ?: [] as $composeFile) {
  if (xedc_discover_excluded($composeFile) || xedc_discover_excluded(basename(dirname($composeFile)))) {
    continue;
  }
  $compose[] = $composeFile;
}
foreach (glob('/opt/*/docker-compose.yml') ?: [] as $composeFile) {
  if (xedc_discover_excluded($composeFile) || xedc_discover_excluded(basename(dirname($composeFile)))) {
    continue;
  }
  if (!in_array($composeFile, $compose, true)) {
    $compose[] = $composeFile;
  }
}
$images = [];
$imageRaw = xedc_sh("docker ps --format '{{.Names}}\t{{.Image}}' 2>/dev/null || true");
foreach (preg_split("/\n/", $imageRaw) ?: [] as $line) {
  if (!str_contains($line, "\t")) {
    continue;
  }
  [$name, $image] = explode("\t", $line, 2);
  if (preg_match('/^(xedc-|minio)/', $name) === 1 || str_contains($name, 'lab') || str_contains($name, 'recover')) {
    continue;
  }
  if (xedc_discover_excluded($name) || xedc_discover_excluded($image)) {
    continue;
  }
  $images[] = $name . '=' . $image;
}
$netdataVersion = xedc_sh('/opt/netdata/bin/netdata -v 2>/dev/null || netdata -v 2>/dev/null || true');
$netdataInstall = is_dir('/opt/netdata') ? 'kickstart' : (xedc_sh('command -v netdata || true') !== '' ? 'package' : 'absent');
$claim = is_file('/var/lib/netdata/cloud.d/claimed_id') || is_file('/opt/netdata/var/lib/netdata/cloud.d/claimed_id');

$smtpHost = '';
$smtpOn = false;
$storageProvider = '';
if ($site !== '') {
  $smtpFile = '';
  foreach ([$site . '/config/sync/smtp.settings.yml', glob($site . '/web/sites/default/files/config_*/sync/smtp.settings.yml')[0] ?? ''] as $candidate) {
    if ($candidate !== '' && is_file($candidate)) {
      $smtpFile = $candidate;
      break;
    }
  }
  if ($smtpFile !== '') {
    $text = (string) file_get_contents($smtpFile);
    if (preg_match('/^smtp_host:\s*(\S+)/m', $text, $m) === 1) {
      $smtpHost = trim($m[1], "'\"");
    }
    if (preg_match('/^smtp_on:\s*(\S+)/m', $text, $m) === 1) {
      $smtpOn = trim($m[1]) === 'true';
    }
  }
  $storageFile = $site . '/config/sync/xedc_core.storage.yml';
  if (is_file($storageFile)) {
    $text = (string) file_get_contents($storageFile);
    if (preg_match('/provider:\s*(\S+)/', $text, $m) === 1) {
      $storageProvider = trim($m[1], "'\"");
    }
  }
}

$extensionsRequired = [];
if ($site !== '' && is_file($site . '/composer.lock')) {
  $lock = json_decode((string) file_get_contents($site . '/composer.lock'), true);
  if (is_array($lock)) {
    foreach ($lock['packages'] ?? [] as $package) {
      foreach ($package['require'] ?? [] as $req => $_v) {
        if (is_string($req) && str_starts_with($req, 'ext-')) {
          $extensionsRequired[] = substr($req, 4);
        }
      }
    }
  }
}
$extensionsRequired = array_values(array_unique($extensionsRequired));
sort($extensionsRequired);

$externalCommands = [];
if ($site !== '') {
  $scan = xedc_sh('grep -RIn --exclude-dir=tests --exclude-dir=node_modules --include=*.php --include=*.module --include=*.inc -E "proc_open|shell_exec|passthru|Process\\(|ffmpeg|ffprobe|7zz|7za|/bin/7z" ' . escapeshellarg($site . '/web/modules/custom') . ' ' . escapeshellarg($site . '/web/themes/custom') . ' 2>/dev/null | head -120 || true');
  if (str_contains($scan, 'ffmpeg')) {
    $externalCommands[] = 'ffmpeg';
  }
  if (str_contains($scan, 'ffprobe')) {
    $externalCommands[] = 'ffprobe';
  }
  if (str_contains($scan, '7z')) {
    $externalCommands[] = '7z';
  }
  if (str_contains($scan, 'chattr')) {
    $externalCommands[] = 'chattr';
  }
}
$externalCommands = array_values(array_unique($externalCommands));

$drupalVersion = '';
if ($site !== '' && is_file($site . '/composer.json')) {
  $composer = json_decode((string) file_get_contents($site . '/composer.json'), true);
  if (is_array($composer)) {
    $drupalVersion = (string) ($composer['require']['drupal/core-recommended'] ?? '');
  }
}

$mem = (int) xedc_sh("awk '/MemTotal/{print int(\$2/1024)}' /proc/meminfo");
$timezone = xedc_sh('timedatectl show -p Timezone --value 2>/dev/null || cat /etc/timezone 2>/dev/null || true');
$ufw = xedc_sh('ufw status 2>/dev/null | head -1 || true');
$private = '';
if ($settings !== '' && is_file($settings)) {
  $active = xedc_active_lines((string) file_get_contents($settings));
  if (preg_match("/file_private_path['\"]\\s*\\]\\s*=\\s*['\"]([^'\"]+)['\"]/", $active, $m) === 1) {
    $private = $m[1];
  }
}
if ($private === '' && $site !== '' && is_dir($site . '/private')) {
  $private = $site . '/private';
}

$queues = [];
if ($site !== '' && xedc_sh('command -v drush || true') !== '') {
  $queueRaw = xedc_sh('cd ' . escapeshellarg($site) . ' && timeout 25 drush queue:list --format=list 2>/dev/null || true');
  $queues = array_values(array_filter(explode("\n", $queueRaw)));
}

$manifest = [
  'schema' => 1,
  'generated_at' => gmdate('c'),
  'os' => [
    'id' => $os['ID'] ?? '',
    'version' => $os['VERSION_ID'] ?? '',
    'name' => $os['PRETTY_NAME'] ?? '',
  ],
  'hostname' => php_uname('n'),
  'domain' => $domain,
  'www' => $www,
  'site_root' => $site,
  'web_root' => $site !== '' ? $site . '/web' : '',
  'private_path' => $private,
  'drupal_version' => $drupalVersion,
  'web_server' => 'nginx',
  'database' => $db,
  'php' => [
    'version' => $phpVersion,
    'minor' => $phpMinor,
    'extensions' => $phpExt,
    'extensions_required' => $extensionsRequired,
    'ini' => $ini,
  ],
  'nginx' => [
    'client_max_body_size' => $clientMax,
    'server_names' => $serverNames,
    'mp4' => str_contains($nginxConf, 'http_mp4'),
    'slice' => str_contains($nginxConf, 'http_slice'),
  ],
  'packages' => $packages,
  'commands' => $commands,
  'external_commands' => $externalCommands,
  'services' => $services,
  'timers' => $timers,
  'restore_skip' => $restoreSkip,
  'cron' => $cron,
  'listeners' => $listeners,
  'queues' => $queues,
  'node' => ['version' => $nodeVersion, 'engines' => '>=18'],
  'ffmpeg' => ['version' => $ffmpegVersion, 'encoders' => $encoders],
  'docker' => ['compose' => $compose, 'images' => $images],
  'netdata' => [
    'version' => $netdataVersion,
    'install' => $netdataInstall,
    'claim_present' => $claim,
  ],
  'memory_mb' => $mem,
  'timezone' => $timezone,
  'ufw_active' => str_starts_with(strtolower($ufw), 'status: active'),
  'external_services' => [
    'redis' => in_array('redis', $phpExt, true) || is_file('/etc/redis/redis.conf'),
    'memcached' => false,
    'solr' => false,
    'elasticsearch' => false,
    'smtp' => ['enabled' => $smtpOn, 'host' => $smtpHost],
    'object_storage' => [
      'provider' => $storageProvider,
      'credentials' => 'environment-or-private-files',
    ],
    'recaptcha' => false,
    'turnstile' => false,
    'oauth' => false,
  ],
];

$skipPorts = [];
$portRaw = xedc_sh("docker ps --format '{{.Names}}\t{{.Ports}}' 2>/dev/null || true");
foreach (preg_split("/\n/", $portRaw) ?: [] as $line) {
  if (!str_contains($line, "\t")) {
    continue;
  }
  [$name, $ports] = explode("\t", $line, 2);
  if (!xedc_discover_excluded($name)) {
    continue;
  }
  if (preg_match_all('/:(\d+)->/', $ports, $pm)) {
    foreach ($pm[1] as $port) {
      $skipPorts[(int) $port] = true;
    }
  }
}
$manifest['listeners'] = array_values(array_filter(
  $manifest['listeners'],
  static fn (array $row): bool => !isset($skipPorts[(int) ($row['port'] ?? 0)]) && !xedc_discover_excluded((string) ($row['process'] ?? ''))
));
$manifest['nginx']['server_names'] = array_values(array_filter(
  $manifest['nginx']['server_names'],
  static fn (string $name): bool => !xedc_discover_excluded($name)
));
$manifest['services'] = array_values(array_filter(
  $manifest['services'],
  static fn (string $name): bool => !xedc_discover_excluded($name)
));
$manifest['timers'] = array_values(array_filter(
  $manifest['timers'],
  static fn (string $name): bool => !xedc_discover_excluded($name)
));
$manifest['cron'] = array_values(array_filter(
  $manifest['cron'],
  static function (array $row): bool {
    return !xedc_discover_excluded((string) ($row['summary'] ?? '')) && !xedc_discover_excluded(json_encode($row) ?: '');
  }
));
$manifest['packages'] = array_values(array_filter(
  $manifest['packages'],
  static fn (array $row): bool => !xedc_discover_excluded((string) ($row['name'] ?? ''))
));
$manifest['docker']['compose'] = array_values(array_filter(
  $manifest['docker']['compose'],
  static fn (string $path): bool => !xedc_discover_excluded($path)
));
$manifest['docker']['images'] = array_values(array_filter(
  $manifest['docker']['images'],
  static fn (string $row): bool => !xedc_discover_excluded($row)
));
$manifest['restore_skip'] = array_values(array_filter(
  $manifest['restore_skip'],
  static fn (array $row): bool => !xedc_discover_excluded((string) ($row['name'] ?? ''))
));

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
  fwrite(STDERR, "无法生成清单\n");
  exit(1);
}
$out = '';
foreach ($argv as $i => $arg) {
  if ($arg === '-o' && isset($argv[$i + 1])) {
    $out = $argv[$i + 1];
  }
}
if ($out !== '') {
  $dir = dirname($out);
  if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
  }
  file_put_contents($out, $json . "\n");
} else {
  echo $json, "\n";
}
