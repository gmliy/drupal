#!/usr/bin/env bash
# shellcheck shell=bash

xedc_composer_install() {
  local root=$1
  xedc_maybe_swap
  (
    cd "$root" || return
    export COMPOSER_MEMORY_LIMIT=${COMPOSER_MEMORY_LIMIT:-512M}
    export COMPOSER_ALLOW_SUPERUSER=1
    export COMPOSER_NO_INTERACTION=1
    composer install --no-dev --prefer-dist --no-progress --no-interaction
  )
}

xedc_write_settings_local() {
  local secret_json=$1 passfile=$2 domain=$3 dest=$4
  XEDC_SECRET_LIST=$XEDC_SECRET_LIST php /dev/stdin "$secret_json" "$passfile" "$domain" "$dest" <<'PHP'
<?php
[$secretFile, $passFile, $domain, $dest] = array_slice($argv, 1);
$secrets = json_decode((string) file_get_contents($secretFile), true);
$password = trim((string) file_get_contents($passFile));
$database = $secrets["database"] ?? "drupal";
$user = $secrets["username"] ?? "drupal";
$salt = $secrets["hash_salt"] ?? bin2hex(random_bytes(32));
$host = getenv("XEDC_RESTORE_DB_HOST");
$port = getenv("XEDC_RESTORE_DB_PORT");
if (!is_string($host) || $host === "") {
    $host = "localhost";
}
if (!is_string($port) || $port === "") {
    $port = "3306";
}
$portCode = ctype_digit($port) ? (string) (int) $port : var_export($port, true);
$prefix = $secrets["prefix"] ?? "";
$patterns = ["'^" . str_replace(".", "\\.", $domain) . "$'"];
if (!str_starts_with($domain, "www.")) {
    $patterns[] = "'^www\\." . str_replace(".", "\\.", $domain) . "$'";
}
$body = "<?php\n";
$body .= "\$databases['default']['default'] = [\n";
$body .= "  'database' => " . var_export($database, true) . ",\n";
$body .= "  'username' => " . var_export($user, true) . ",\n";
$body .= "  'password' => " . var_export($password, true) . ",\n";
$body .= "  'prefix' => " . var_export($prefix, true) . ",\n";
$body .= "  'host' => " . var_export($host, true) . ",\n";
$body .= "  'port' => " . $portCode . ",\n";
$body .= "  'isolation_level' => 'READ COMMITTED',\n";
$body .= "  'driver' => 'mysql',\n";
$body .= "  'namespace' => 'Drupal\\\\mysql\\\\Driver\\\\Database\\\\mysql',\n";
$body .= "  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',\n";
$body .= "];\n";
$body .= "\$settings['hash_salt'] = " . var_export($salt, true) . ";\n";
$body .= "\$settings['trusted_host_patterns'] = [" . implode(", ", $patterns) . "];\n";
file_put_contents($dest, $body);
chmod($dest, 0640);
$list = getenv("XEDC_SECRET_LIST");
if (is_string($list) && $list !== "") {
    file_put_contents($list, $password . "\n", FILE_APPEND);
    file_put_contents($list, $salt . "\n", FILE_APPEND);
}
PHP
}

xedc_ensure_settings_include() {
  local settings=$1
  if grep -Eq '^[[:space:]]*(include|require)(_once)?[[:space:]].*settings\.local\.php' "$settings"; then
    return 0
  fi
  cat >>"$settings" <<'PHP'

if (is_file(__DIR__ . '/settings.local.php')) {
  include __DIR__ . '/settings.local.php';
}
PHP
}

xedc_drush() {
  local root=$1
  shift
  (
    cd "$root" || return
    ./vendor/bin/drush "$@"
  )
}

xedc_drush_rebuild() {
  local root=$1 uri=$2
  xedc_drush "$root" --uri="$uri" status --fields=bootstrap,drupal-version
  xedc_drush "$root" --uri="$uri" config:import -y
  xedc_drush "$root" --uri="$uri" updatedb -y
  xedc_drush "$root" --uri="$uri" cache:rebuild
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 restore.sh 运行。" >&2
  exit 1
fi
