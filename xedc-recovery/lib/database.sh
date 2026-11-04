#!/usr/bin/env bash
# shellcheck shell=bash

xedc_write_client_cnf() {
  local json=$1 dest=$2
  XEDC_SECRET_LIST=$XEDC_SECRET_LIST php /dev/stdin "$json" "$dest" <<'PHP'
<?php
$secrets = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($secrets) || empty($secrets["username"]) || !isset($secrets["password"])) {
    fwrite(STDERR, "数据库凭据不完整\n");
    exit(1);
}
foreach (["username", "password", "database"] as $key) {
    $value = (string) ($secrets[$key] ?? "");
    if (preg_match('/[\r\n#;]/', $value)) {
        fwrite(STDERR, "数据库凭据包含无法写入临时配置的字符\n");
        exit(1);
    }
}
$lines = [
    "[client]",
    "user=" . $secrets["username"],
    "password=" . $secrets["password"],
    "host=" . ($secrets["host"] ?: "localhost"),
    "port=" . ($secrets["port"] ?: "3306"),
];
file_put_contents($argv[2], implode("\n", $lines) . "\n");
chmod($argv[2], 0600);
$list = getenv("XEDC_SECRET_LIST");
if (is_string($list) && $list !== "") {
    file_put_contents($list, $secrets["password"] . "\n", FILE_APPEND);
    if (!empty($secrets["hash_salt"])) {
        file_put_contents($list, $secrets["hash_salt"] . "\n", FILE_APPEND);
    }
}
PHP
  chmod 600 "$dest"
}

xedc_dump_database() {
  local cnf=$1 database=$2 outfile=$3
  if [[ -n ${XEDC_SQL_DUMP_FILE:-} ]]; then
    if [[ $XEDC_SQL_DUMP_FILE == *.zst ]]; then
      cp "$XEDC_SQL_DUMP_FILE" "$outfile"
    else
      zstd -T2 -q -c "$XEDC_SQL_DUMP_FILE" -o "$outfile"
    fi
    xedc_log INFO "使用测试 SQL 文件，未连接生产数据库。"
    return 0
  fi
  local dump_bin=mysqldump
  if command -v mariadb-dump >/dev/null 2>&1; then
    dump_bin=mariadb-dump
  fi
  xedc_nice "$dump_bin" \
    --defaults-extra-file="$cnf" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --no-tablespaces \
    --default-character-set=utf8mb4 \
    "$database" | zstd -T2 -q -o "$outfile"
}

xedc_database_fact() {
  local file=$1 key=$2
  awk -v key="$key" 'index($0, key "=") == 1 { print substr($0, length(key) + 2); exit }' "$file"
}

xedc_database_engines() {
  local file=$1
  awk 'index($0, "engine=") == 1 { print substr($0, 8) }' "$file" | paste -sd ';' -
}

xedc_database_server_facts() {
  local database=$1 outfile=$2 cnf=${3:-}
  if [[ ! $database =~ ^[A-Za-z0-9_]+$ ]]; then
    xedc_die "数据库名不合法，已停止读取服务器信息。"
  fi
  local sql args=()
  if [[ -n $cnf ]]; then
    args=(--defaults-extra-file="$cnf")
  fi
  sql=$(cat <<SQL
SELECT CONCAT('version=', VERSION());
SELECT CONCAT('charset=', @@character_set_server);
SELECT CONCAT('collation=', @@collation_server);
SELECT CONCAT('sql_mode=', @@sql_mode);
SELECT CONCAT('engine=', ENGINE, ':', COUNT(*)) FROM information_schema.tables WHERE table_schema='${database}' GROUP BY ENGINE;
SELECT CONCAT('tables=', COUNT(*)) FROM information_schema.tables WHERE table_schema='${database}';
SELECT CONCAT('routines=', COUNT(*)) FROM information_schema.routines WHERE routine_schema='${database}';
SELECT CONCAT('triggers=', COUNT(*)) FROM information_schema.triggers WHERE trigger_schema='${database}';
SQL
)
  if ! mariadb "${args[@]}" -N -e "$sql" >"$outfile" 2>"$outfile.err"; then
    xedc_warn "无法读取该站点配置指向的数据库服务器信息。"
    printf '%s\n' "unavailable" >"$outfile"
  fi
  rm -f "$outfile.err"
}

xedc_create_database_user() {
  local database=$1 user=$2 passfile=$3
  local sql_file
  sql_file=$(xedc_mktemp)
  XEDC_SECRET_LIST=$XEDC_SECRET_LIST php /dev/stdin "$database" "$user" "$passfile" "$sql_file" <<'PHP'
<?php
[$database, $user, $passfile, $dest] = array_slice($argv, 1);
$password = trim((string) file_get_contents($passfile));
foreach ([$database, $user] as $value) {
    if (preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
        fwrite(STDERR, "数据库名或用户名不合法\n");
        exit(1);
    }
}
if ($password === "" || preg_match('/[\r\n]/', $password)) {
    fwrite(STDERR, "数据库密码无效\n");
    exit(1);
}
$escaped = str_replace("'", "''", $password);
$sql = "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
$sql .= "CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY '{$escaped}';\n";
$sql .= "ALTER USER '{$user}'@'localhost' IDENTIFIED BY '{$escaped}';\n";
$sql .= "GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$user}'@'localhost';\n";
$sql .= "FLUSH PRIVILEGES;\n";
file_put_contents($dest, $sql);
chmod($dest, 0600);
$list = getenv("XEDC_SECRET_LIST");
if (is_string($list) && $list !== "") {
    file_put_contents($list, $password . "\n", FILE_APPEND);
}
PHP
  if [[ ${XEDC_DRY_RUN:-0} == 1 ]]; then
    xedc_log INFO "dry-run: 将创建数据库和用户"
    rm -f "$sql_file"
    return 0
  fi
  mariadb <"$sql_file"
  rm -f "$sql_file"
}

xedc_import_database() {
  local cnf=$1 database=$2 dump=$3
  if [[ ${XEDC_DRY_RUN:-0} == 1 ]]; then
    xedc_log INFO "dry-run: 将导入 ${database}"
    return 0
  fi
  zstdcat "$dump" | mariadb --defaults-extra-file="$cnf" "$database"
}

xedc_random_password() {
  local value
  value=$(openssl rand -base64 24 | tr -d '\n=+/' | cut -c1-24)
  printf '%s\n' "$value"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 xedc-recovery/backup.sh 或 restore.sh 运行。" >&2
  exit 1
fi
