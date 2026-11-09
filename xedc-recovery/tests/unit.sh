#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"
fail=0
static_bad=0
while IFS= read -r f; do
  case "$f" in
    */tests/*) continue ;;
  esac
  if grep -E '\bmktemp\b' "$f" 2>/dev/null | grep -vE '(^|[^-])mktemp -p|mktemp -d -p|xedc_mktemp|xedc_work_mktemp|#\s*shellcheck'; then
    echo "mktemp 未带 -p: $f" >&2
    static_bad=1
  fi
  if grep -E '="/tmp/|/tmp/xedc|mktemp /tmp' "$f" 2>/dev/null; then
    echo "硬编码 /tmp: $f" >&2
    static_bad=1
  fi
done < <(find "$ROOT" -name '*.sh' -type f ! -path '*/tests/*')
if [[ "$static_bad" != 0 ]]; then
  fail=1
fi
while IFS= read -r file; do
  if ! bash -n "$file"; then
    echo "bash -n 失败: $file"
    fail=1
  fi
done < <(find "$ROOT" -name '*.sh' -type f)
if command -v shellcheck >/dev/null 2>&1; then
  while IFS= read -r file; do
    if ! shellcheck -x -S error "$file"; then
      echo "shellcheck 失败: $file"
      fail=1
    fi
  done < <(find "$ROOT" -name '*.sh' -type f)
else
  echo "未安装 shellcheck"
  fail=1
fi
php -r '
require "'"$ROOT"'/lib/php/domain_replace.php";
$raw = "a:2:{s:4:\"link\";s:26:\"https://test1.local/models\";s:5:\"plain\";s:15:\"see test1.local\";}";
[$out, $changed] = xedc_transform($raw, "test1.local", "test2.local");
if (!$changed) { fwrite(STDERR, "serialized not changed\n"); exit(1); }
$val = unserialize($out, ["allowed_classes" => false]);
if (($val["link"] ?? "") !== "https://test2.local/models") { fwrite(STDERR, "bad unserialize\n"); exit(1); }
[$plain, $ok] = xedc_transform("see test1.local", "test1.local", "test2.local");
if ($plain !== "see test2.local" || !$ok) { fwrite(STDERR, "plain replace failed\n"); exit(1); }
echo "domain unit ok\n";
'
# shellcheck source=../lib/r2.sh
source "$ROOT/lib/r2.sh"
got=$(xedc_choose_stamp latest drupal-backup-20200101T000000 drupal-backup-20260101T000000)
[[ "$got" == "drupal-backup-20260101T000000" ]]
got=$(xedc_choose_stamp latest-1 drupal-backup-20200101T000000 drupal-backup-20260101T000000)
[[ "$got" == "drupal-backup-20200101T000000" ]]
got=$(xedc_choose_stamp 20260101T000000 drupal-backup-20200101T000000 drupal-backup-20260101T000000)
[[ "$got" == "drupal-backup-20260101T000000" ]]
tmp=$(mktemp)
php "$ROOT/lib/php/render.php" "$ROOT/templates/gzip.conf" "$tmp" <<'JSON'
{}
JSON
grep -q 'gzip_comp_level' "$tmp"
if grep -q 'gzip on' "$tmp"; then
  echo "gzip.conf 不能再写 gzip on，会和系统 nginx.conf 冲突" >&2
  exit 1
fi
rm -f "$tmp"
settings=$(mktemp)
cat >"$settings" <<'PHP'
<?php
$databases['default']['default'] = [
  'database' => 'old',
  'username' => 'old',
  'password' => 'old-pass',
  'host' => 'localhost',
  'port' => '3306',
];
$settings['hash_salt'] = 'keep-this-salt';
PHP
php "$ROOT/lib/php/patch_settings.php" --file "$settings" --database xedc --username xedc --password abc --host localhost --port 3306 --domain test2.local --https on
php "$ROOT/lib/php/patch_settings.php" --file "$settings" --database xedc --username xedc --password abc --host localhost --port 3306 --domain test2.local --https on
count=$(php -r 'echo substr_count(file_get_contents($argv[1]), "databases");' "$settings")
[[ "$count" == "1" ]]
grep -q 'keep-this-salt' "$settings"
grep -q 'test2.local' "$settings"
rm -f "$settings"
umask 077
perm_file=$(mktemp)
printf 'secret\n' >"$perm_file"
xedc_apply_perms "$perm_file"
[[ "$(stat -c '%a' "$perm_file")" == "644" ]]
[[ "$(stat -c '%U:%G' "$perm_file")" == "root:root" ]]
unit=$(mktemp)
printf '%s\n' '[Service]' 'ExecStart=/bin/true' >"$unit"
if xedc_unit_has_install "$unit"; then
  echo "没有 [Install] 却被当成可 enable" >&2
  exit 1
fi
printf '%s\n' '[Install]' 'WantedBy=multi-user.target' >>"$unit"
xedc_unit_has_install "$unit"
printf '%s\n' 'ExecStart=/usr/local/sbin/xedc-push-gmliy.sh' >"$unit"
xedc_is_git_push_file "$unit"
xedc_restore_skip xedc-push-gmliy.service
xedc_restore_skip xedc-push-gmliy.timer
if xedc_restore_skip nginx.service; then
  echo "nginx 不应在不还原名单里" >&2
  exit 1
fi
xedc_is_worker_unit xedc-import-worker.service
if xedc_is_worker_unit nginx.service; then
  echo "nginx 不是 worker" >&2
  exit 1
fi
rm -f "$perm_file" "$unit"
# shellcheck source=../lib/domain.sh
source "$ROOT/lib/domain.sh"
dom=$(mktemp)
printf '%s\n' 'server_name x.gitxx.xyz;' 'ssl_certificate /etc/letsencrypt/live/x.gitxx.xyz/fullchain.pem;' >"$dom"
OLD_DOMAIN=gitxx.xyz
DOMAIN=tlte.top
OLD_MATOMO=x.gitxx.xyz
MATOMO_DOMAIN=stats.example.net
domain_replace_file "$dom"
grep -q 'server_name stats.example.net;' "$dom"
grep -q '/etc/letsencrypt/live/stats.example.net/fullchain.pem' "$dom"
if grep -q 'gitxx.xyz' "$dom"; then
  echo "自定义 Matomo 域名被主域名替换污染了" >&2
  exit 1
fi
printf '%s\n' 'server_name x.gitxx.xyz;' >"$dom"
MATOMO_DOMAIN=x.tlte.top
domain_replace_file "$dom"
grep -q 'server_name x.tlte.top;' "$dom"
printf '%s\n' 'server_name x.gitxx.xyz;' >"$dom"
domain_replace_file "$dom"
grep -q 'server_name x.tlte.top;' "$dom"
acme=$(mktemp)
cat >"$acme" <<'EOF'
server {
    server_name x.gitxx.xyz;
    location / { proxy_pass http://127.0.0.1:8088; }
    ssl_certificate /etc/letsencrypt/live/x.gitxx.xyz/fullchain.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
}
server {
    if ($host = x.gitxx.xyz) {
        return 301 https://$host$request_uri;
    } # managed by Certbot
    listen 80;
    listen [::]:80;
    server_name x.gitxx.xyz;
    return 404; # managed by Certbot
}
EOF
php "$ROOT/lib/php/nginx_acme.php" "$acme"
grep -q 'acme-challenge' "$acme"
grep -q 'root /var/www/letsencrypt;' "$acme"
if grep -q 'if ($host' "$acme"; then
  echo "80 端口仍会把 ACME 挑战跳走" >&2
  exit 1
fi
if grep -q 'return 404; # managed by Certbot' "$acme"; then
  echo "Certbot 的 404 还在" >&2
  exit 1
fi
count=$(grep -c 'acme-challenge' "$acme")
php "$ROOT/lib/php/nginx_acme.php" "$acme"
[[ "$(grep -c 'acme-challenge' "$acme")" == "$count" ]]
rm -f "$dom" "$acme"
state=$(mktemp -d)
XEDC_STATE_DIR=$state
hash_file=$(mktemp)
printf 'one\n' >"$hash_file"
xedc_remember_hash "$hash_file"
if xedc_user_modified "$hash_file"; then
  echo "刚写入的文件不应算手动修改" >&2
  exit 1
fi
printf 'two\n' >"$hash_file"
xedc_user_modified "$hash_file"
xedc_keep_manual "$hash_file"
grep -q 'two' "$hash_file"
ex=$(mktemp)
printf '%s\n' '# 注释' 'matomo' >"$ex"
XEDC_EXCLUDE_FILE=$ex
xedc_excluded matomo.conf
if xedc_excluded nginx.conf; then
  echo "无关配置被排除规则命中" >&2
  exit 1
fi
site=$(mktemp -d)
mkdir -p "$site/config/sync" "$site/web/themes/custom/xedc" "$site/vendor/drupal/matomo" "$site/web/modules/custom/xedc_stats"
printf '%s\n' 'module:' '  matomo: 0' '  system: 0' >"$site/config/sync/core.extension.yml"
printf '%s\n' 'url: https://x.example/matomo.js' >"$site/config/sync/matomo.settings.yml"
printf '%s\n' '{"require":{"php":">=8.1","drupal/matomo":"1.0.0"},"repositories":[{"type":"path","url":"vendor/drupal/matomo"}]}' >"$site/composer.json"
printf '%s\n' '<script src="https://x.example/matomo.js"></script>' >"$site/web/themes/custom/xedc/a.twig"
printf '%s\n' 'name: Stats' 'dependencies:' '  - matomo' >"$site/web/modules/custom/xedc_stats/xedc_stats.info.yml"
printf 'x\n' >"$site/vendor/drupal/matomo/x"
php "$ROOT/lib/php/strip_excluded.php" --site "$site" --mode list-modules | grep -qx matomo
php "$ROOT/lib/php/strip_excluded.php" --site "$site" --mode dependents --module matomo | grep -q xedc_stats.info.yml
php "$ROOT/lib/php/strip_excluded.php" --site "$site" --mode all >/dev/null
if grep -q 'matomo:' "$site/config/sync/core.extension.yml"; then
  echo "core.extension 仍有 matomo" >&2
  exit 1
fi
[[ ! -f "$site/config/sync/matomo.settings.yml" ]]
if grep -q 'drupal/matomo' "$site/composer.json"; then
  echo "composer.json 仍有包" >&2
  exit 1
fi
[[ ! -d "$site/vendor/drupal/matomo" ]]
grep -q 'matomo.js' "$site/web/themes/custom/xedc/a.twig"
printf '%s\n' '<script src="https://stats.example/matomo.js"></script>' | php "$ROOT/lib/php/strip_excluded.php" --mode stream | grep -q '<script' && {
  echo "跟踪脚本没有从流里去掉" >&2
  exit 1
}
unset XEDC_EXCLUDE_FILE
rm -rf "$state" "$hash_file" "$ex" "$site"
XEDC_TEST_ROOT="$ROOT" php <<'PHP'
<?php
require getenv('XEDC_TEST_ROOT') . '/lib/php/settings_db.php';
require getenv('XEDC_TEST_ROOT') . '/lib/php/sql_filter.php';
$empty = tempnam(sys_get_temp_dir(), 'xedc-prefix');
$main = tempnam(sys_get_temp_dir(), 'xedc-prefix');
$nested = tempnam(sys_get_temp_dir(), 'xedc-prefix');
file_put_contents($empty, "<?php\n\$databases['default']['default'] = array (\n  'prefix' => '',\n);\n");
file_put_contents($main, "<?php\n\$databases['default']['default'] = [\n  'prefix' => 'main_',\n];\n");
file_put_contents($nested, "<?php\n\$databases['default']['default'] = [\n  'prefix' => [\n    'default' => 'dr_',\n  ],\n];\n");
if (xedc_db_prefix($empty) !== '') { fwrite(STDERR, "空前缀解析错误\n"); exit(1); }
if (xedc_db_prefix($main) !== 'main_') { fwrite(STDERR, "字符串前缀解析错误\n"); exit(1); }
if (xedc_db_prefix($nested) !== 'dr_') { fwrite(STDERR, "数组前缀解析错误\n"); exit(1); }
unlink($empty); unlink($main); unlink($nested);
foreach (['cache_page' => true, 'cache' => true, 'cachetags' => true, 'watchdog' => true, 'sessions' => true, 'flood' => true, 'semaphore' => true, 'queue' => false, 'node' => false, 'main_cache_page' => true] as $table => $want) {
  $prefix = str_starts_with($table, 'main_') ? 'main_' : '';
  if (xedc_is_ephemeral_table($table, $prefix) !== $want) {
    fwrite(STDERR, "表分类错误: {$table}\n");
    exit(1);
  }
}
$sql = <<<'SQL'
CREATE TABLE `cache_page` (
  `cid` varchar(255) NOT NULL,
  PRIMARY KEY (`cid`)
);
INSERT INTO `cache_page` VALUES ('');
INSERT INTO `cache_page` VALUES ('https://gitxx.xyz/cosplay/cos?sort_by=totalcount&model=39:', 'line1
HUGEHTMLMARKER
semi;colon
');
INSERT INTO `cache_page` VALUES ('');
/* comment ; */
CREATE TABLE `queue` (`item_id` int NOT NULL, PRIMARY KEY (`item_id`));
INSERT INTO `queue` VALUES (1, 'keep-queue');
DELIMITER ;;
CREATE PROCEDURE keep_me()
BEGIN
INSERT INTO cache_page VALUES ('from-routine');
END ;;
DELIMITER ;
CREATE TABLE `node` (`nid` int NOT NULL, PRIMARY KEY (`nid`));
INSERT INTO `node` VALUES (7);
INSERT INTO `main_cache_page` VALUES ('gone');
INSERT INTO `main_node` VALUES (3);
SQL;
$in = fopen('php://temp', 'r+');
$out = fopen('php://temp', 'r+');
$map = fopen('php://temp', 'r+');
fwrite($in, $sql);
rewind($in);
xedc_filter_sql($in, $out, $map, 'main_', 5);
rewind($out);
$text = stream_get_contents($out);
if ($text === false || str_contains($text, 'HUGEHTMLMARKER') || !str_contains($text, 'from-routine')) {
  fwrite(STDERR, "缓存数据没有滤干净，或存储过程被拆坏\n");
  exit(1);
}
if (!str_contains($text, 'CREATE TABLE `cache_page`') || !str_contains($text, 'keep-queue') || !str_contains($text, 'INSERT INTO `node` VALUES (7)')) {
  fwrite(STDERR, "结构或业务数据被误删\n");
  exit(1);
}
if (preg_match('/INSERT INTO `cache_page`/i', $text) === 1 || preg_match('/INSERT INTO `main_cache_page`/i', $text) === 1) {
  fwrite(STDERR, "缓存 INSERT 仍在\n");
  exit(1);
}
if (!str_contains($text, 'INSERT INTO `main_node` VALUES (3)')) {
  fwrite(STDERR, "带前缀的业务表被误删\n");
  exit(1);
}
rewind($map);
$mapPath = tempnam(sys_get_temp_dir(), 'xedc-map');
file_put_contents($mapPath, stream_get_contents($map));
$err = "ERROR 1062 (23000) at line 1: Duplicate entry '' for key 'PRIMARY'\n" . str_repeat('H', 40) . "HUGEHTMLMARKER" . str_repeat('x', 400);
$msg = xedc_explain_sql_error($mapPath, $err);
if (!str_contains($msg, '表 cache_page') || !str_contains($msg, '第 1 行') || str_contains($msg, 'HUGEHTMLMARKER')) {
  fwrite(STDERR, "错误摘要不正确: {$msg}\n");
  exit(1);
}
unlink($mapPath);
fwrite(STDOUT, "sql filter ok\n");
PHP
# shellcheck source=../lib/db.sh
source "$ROOT/lib/db.sh"
db_ensure_sql_skip || { echo "编译 sql_skip_cache 失败" >&2; exit 1; }
SKIP_BIN=$(db_sql_skip_bin)

# --- C 过滤正确性（含 NUL、跨多行、LOCK 配对、前缀）---
corr=$(mktemp)
{
  printf '%s\n' "CREATE TABLE \`cache_page\` (\`cid\` varchar(255), \`data\` longblob);"
  printf '%s\n' "LOCK TABLES \`cache_page\` WRITE;"
  printf '%s\n' "/*!40000 ALTER TABLE \`cache_page\` DISABLE KEYS */;"
  printf "INSERT INTO \`cache_page\` VALUES ('a', _binary '%s');\n" "$(printf 'x%.0s' {1..100})"
  # 跨多行 + NUL
  printf "INSERT INTO \`cache_page\` VALUES ('b', '"
  printf 'HUGE\0BIN'
  printf '\nmore;text'
  printf "');\n"
  printf '%s\n' "/*!40000 ALTER TABLE \`cache_page\` ENABLE KEYS */;"
  printf '%s\n' "UNLOCK TABLES;"
  printf '%s\n' "CREATE TABLE \`key_value\` (\`name\` varchar(128), \`value\` longblob);"
  printf '%s\n' "INSERT INTO \`key_value\` VALUES ('system.cron_last', '12345');"
  printf '%s\n' "CREATE TABLE \`queue\` (\`item_id\` int, \`data\` longblob);"
  printf '%s\n' "INSERT INTO \`queue\` VALUES (1, 'keep-queue');"
  printf '%s\n' "CREATE TABLE \`users\` (\`uid\` int);"
  printf '%s\n' "INSERT INTO \`users\` VALUES (1);"
  printf '%s\n' "CREATE TABLE \`config\` (\`name\` varchar(128));"
  printf '%s\n' "INSERT INTO \`config\` VALUES ('system.site');"
  printf '%s\n' "CREATE TABLE \`main_cache_render\` (\`cid\` varchar(255));"
  printf '%s\n' "INSERT INTO \`main_cache_render\` VALUES ('gone');"
  printf '%s\n' "INSERT INTO \`main_node\` VALUES (3);"
} >"$corr"
filtered=$(mktemp)
"$SKIP_BIN" --prefix 'main_' <"$corr" >"$filtered"
grep -q 'CREATE TABLE `cache_page`' "$filtered"
grep -q 'LOCK TABLES `cache_page` WRITE' "$filtered"
grep -q 'DISABLE KEYS' "$filtered"
grep -q 'ENABLE KEYS' "$filtered"
grep -q 'UNLOCK TABLES' "$filtered"
grep -q "INSERT INTO \`key_value\`" "$filtered"
grep -q "INSERT INTO \`queue\`" "$filtered"
grep -q "INSERT INTO \`users\`" "$filtered"
grep -q "INSERT INTO \`config\`" "$filtered"
grep -q "INSERT INTO \`main_node\`" "$filtered"
if grep -qE 'INSERT INTO `cache_page`|INSERT INTO `main_cache_render`|HUGE' "$filtered"; then
  echo "缓存 INSERT 未被滤掉" >&2
  exit 1
fi
locks=$(grep -cE '^LOCK TABLES' "$filtered" || true)
unlocks=$(grep -cE '^UNLOCK TABLES' "$filtered" || true)
[[ "$locks" == "$unlocks" ]]
disables=$(grep -c 'DISABLE KEYS' "$filtered" || true)
enables=$(grep -c 'ENABLE KEYS' "$filtered" || true)
[[ "$disables" == "$enables" ]]
echo "c filter correctness ok"

# --- 与 grep -a 语义一致 ---
pat=$(db_cache_insert_ere '')
grep_out=$(mktemp)
LC_ALL=C grep -a -v -E "$pat" "$corr" >"$grep_out" || [[ $? -eq 1 ]]
plain=$(mktemp)
"$SKIP_BIN" --prefix '' <"$corr" >"$plain"
if grep -q 'INSERT INTO `cache_page`' "$plain"; then
  echo "无前缀时 cache_page 应被过滤" >&2
  exit 1
fi
# 无前缀时 main_cache_render 不是 ephemeral 基名，应保留
grep -q 'INSERT INTO `main_cache_render`' "$plain"
# 有前缀时 main_cache 与无前缀 cache 都应滤掉
pref=$(mktemp)
"$SKIP_BIN" --prefix 'main_' <"$corr" >"$pref"
if grep -qE 'INSERT INTO `cache_page`|INSERT INTO `main_cache_render`' "$pref"; then
  echo "有前缀时应同时过滤 cache_page 与 main_cache_render" >&2
  exit 1
fi
rm -f "$pref"
echo "grep semantics ok"

# --- 约 200MB 超长行性能：C 秒级，PHP 明显更慢 ---
perf_dir=$(mktemp -d)
perf_sql="$perf_dir/big.sql"
payload_1m=$(head -c 1048576 /dev/zero | tr '\0' 'A')
{
  printf '%s\n' "CREATE TABLE \`cache_page\` (\`cid\` varchar(255), \`data\` longblob);"
  printf '%s\n' "CREATE TABLE \`node\` (\`nid\` int, \`title\` varchar(255));"
  printf '%s\n' "CREATE TABLE \`key_value\` (\`collection\` varchar(128), \`name\` varchar(128), \`value\` longblob);"
  printf '%s\n' "LOCK TABLES \`cache_page\` WRITE;"
  # 一条含 NUL 的超长行
  printf "INSERT INTO \`cache_page\` VALUES ('nul', '"
  printf 'HEAD\0'
  printf '%s' "$payload_1m"
  printf "');\n"
  # 约 200 条 1MB 行
  for i in $(seq 1 200); do
    printf "INSERT INTO \`cache_page\` VALUES ('cid-%s', '%s');\n" "$i" "$payload_1m"
  done
  printf '%s\n' "UNLOCK TABLES;"
  for i in $(seq 1 1000); do
    printf "INSERT INTO \`node\` VALUES (%s, 'title-%s');\n" "$i" "$i"
  done
  printf '%s\n' "INSERT INTO \`key_value\` VALUES ('state', 'system.cron_last', '999');"
} >"$perf_sql"
unset payload_1m
perf_size=$(stat -c%s "$perf_sql")
echo "perf dump ${perf_size} bytes"
if (( perf_size < 200 * 1024 * 1024 )); then
  echo "性能 dump 不足 200MB: ${perf_size}" >&2
  exit 1
fi
c_out=$(mktemp)
t0=$(date +%s%N)
"$SKIP_BIN" --prefix '' <"$perf_sql" >"$c_out"
t1=$(date +%s%N)
c_ms=$(( (t1 - t0) / 1000000 ))
echo "C filter ${c_ms}ms"
if (( c_ms > 15000 )); then
  echo "C 过滤应在秒级完成，实际 ${c_ms}ms" >&2
  exit 1
fi
# 过滤后不应有 cache INSERT，应有 node/key_value
if grep -a -q 'INSERT INTO `cache_page`' "$c_out"; then
  echo "性能样本仍含 cache INSERT" >&2
  exit 1
fi
grep -a -q 'INSERT INTO `node`' "$c_out"
grep -a -q 'INSERT INTO `key_value`' "$c_out"
node_before=$(grep -a -c 'INSERT INTO `node`' "$perf_sql")
node_after=$(grep -a -c 'INSERT INTO `node`' "$c_out")
[[ "$node_before" == "$node_after" ]]
# PHP 对比：同样输入限时，应显著更慢或超时
php_out=$(mktemp)
php_map=$(mktemp)
t0=$(date +%s%N)
set +e
timeout 20 php "$ROOT/lib/php/sql_filter.php" --filter --prefix '' --map "$php_map" <"$perf_sql" >"$php_out"
php_rc=$?
set -e
t1=$(date +%s%N)
php_ms=$(( (t1 - t0) / 1000000 ))
echo "PHP filter rc=${php_rc} ${php_ms}ms"
if [[ "$php_rc" == 0 ]]; then
  if (( php_ms < c_ms * 3 && php_ms < 5000 )); then
    echo "PHP 过滤未明显慢于 C，对比无效" >&2
    exit 1
  fi
else
  echo "PHP 过滤在 20s 内未完成（符合预期，C 仅 ${c_ms}ms）"
fi
rm -f "$c_out" "$php_out" "$php_map" "$corr" "$filtered" "$grep_out" "$plain"
rm -rf "$perf_dir"
echo "perf ok"

# --- 真实导入、max_allowed_packet、幂等 ---
sql_db=xedc_sqlfilter_test
sql_cleanup() {
  mysql --protocol=socket -u root -e "DROP DATABASE IF EXISTS \`${sql_db}\`" >/dev/null 2>&1 || true
}
if mysql --protocol=socket -u root -N -e 'SELECT 1' >/dev/null 2>&1; then
  trap sql_cleanup EXIT
  export XEDC_STATE_DIR
  XEDC_STATE_DIR=$(mktemp -d)
  sql_file=$(mktemp)
  cat >"$sql_file" <<'EOF'
CREATE TABLE `cache_page` (
  `cid` varchar(255) NOT NULL,
  `data` longblob,
  PRIMARY KEY (`cid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
LOCK TABLES `cache_page` WRITE;
/*!40000 ALTER TABLE `cache_page` DISABLE KEYS */;
INSERT INTO `cache_page` VALUES ('', 'one');
INSERT INTO `cache_page` VALUES ('dup', 'line1
HUGEHTMLMARKER
semi;colon
');
INSERT INTO `cache_page` VALUES ('', 'two');
/*!40000 ALTER TABLE `cache_page` ENABLE KEYS */;
UNLOCK TABLES;
CREATE TABLE `queue` (
  `item_id` int NOT NULL,
  `data` longblob,
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `queue` VALUES (1, 'keep-queue');
CREATE TABLE `key_value` (
  `collection` varchar(128) NOT NULL,
  `name` varchar(128) NOT NULL,
  `value` longblob,
  PRIMARY KEY (`collection`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `key_value` VALUES ('state', 'system.cron_last', '42');
CREATE TABLE `users` (
  `uid` int NOT NULL,
  PRIMARY KEY (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `users` VALUES (1);
CREATE TABLE `node` (
  `nid` int NOT NULL,
  PRIMARY KEY (`nid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `node` VALUES (7);
EOF
  # 超长行（>默认 max_allowed_packet 的一部分，用会话 256M）
  long_blob=$(mktemp)
  dd if=/dev/urandom bs=1048576 count=2 of="$long_blob" status=none
  {
    printf "CREATE TABLE \`blob_keep\` (\`id\` int, \`data\` longblob);\n"
    printf "INSERT INTO \`blob_keep\` VALUES (1, _binary '"
    # hex 太慢；用 base64 不进 SQL。直接放可打印填充：
    head -c $((2 * 1024 * 1024)) /dev/zero | tr '\0' 'A'
    printf "');\n"
  } >>"$sql_file"
  rm -f "$long_blob"

  sql_cleanup
  mysql --protocol=socket -u root -e "CREATE DATABASE \`${sql_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  raw_err=$(mktemp)
  if mysql --protocol=socket -u root --batch "$sql_db" <"$sql_file" >/dev/null 2>"$raw_err"; then
    echo "未过滤的重复 cid 不应该导入成功" >&2
    exit 1
  fi
  grep -q '1062' "$raw_err"

  sql_cleanup
  mysql --protocol=socket -u root -e "CREATE DATABASE \`${sql_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  if ! db_import_sql mysql "$sql_db" '' <"$sql_file"; then
    echo "C 过滤后导入失败" >&2
    exit 1
  fi
  rows=$(mysql --protocol=socket -u root -N -e "SELECT COUNT(*) FROM \`${sql_db}\`.cache_page")
  queue=$(mysql --protocol=socket -u root -N -e "SELECT data FROM \`${sql_db}\`.queue")
  node=$(mysql --protocol=socket -u root -N -e "SELECT nid FROM \`${sql_db}\`.node")
  kv=$(mysql --protocol=socket -u root -N -e "SELECT value FROM \`${sql_db}\`.key_value")
  uid=$(mysql --protocol=socket -u root -N -e "SELECT uid FROM \`${sql_db}\`.users")
  blob=$(mysql --protocol=socket -u root -N -e "SELECT LENGTH(data) FROM \`${sql_db}\`.blob_keep")
  [[ "$rows" == 0 ]]
  [[ "$queue" == keep-queue ]]
  [[ "$node" == 7 ]]
  [[ "$kv" == 42 ]]
  [[ "$uid" == 1 ]]
  [[ "$blob" == $((2 * 1024 * 1024)) ]]
  echo "import correctness ok"

  # zstd 导入 + 进度日志（缩短间隔）
  zsql=$(mktemp --suffix=.sql.zst)
  zstd -q -f -3 -o "$zsql" "$sql_file"
  sql_cleanup
  mysql --protocol=socket -u root -e "CREATE DATABASE \`${sql_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  export XEDC_IMPORT_PROGRESS_SECS=1 XEDC_IMPORT_STALL_SECS=3
  progress_log=$(mktemp)
  if ! db_import_sql_zst mysql "$sql_db" '' "$zsql" 2>"$progress_log"; then
    echo "zst 导入失败" >&2
    cat "$progress_log" >&2
    exit 1
  fi
  grep -q '数据库导入完成，耗时' "$progress_log"
  # 小文件可能一秒内完成，未必有进度行；用慢速管道测进度/卡住
  slow=$(mktemp --suffix=.sql.zst)
  {
    printf '%s\n' "CREATE TABLE \`t\` (\`id\` int);"
    # 足够大以跨越多个进度周期
    for i in $(seq 1 5000); do
      printf "INSERT INTO \`t\` VALUES (%s);\n" "$i"
    done
  } | zstd -q -f -3 -o "$slow"
  # 卡住提示：监控器 + 假 mysql pid
  stall_log=$(mktemp)
  sleep 30 &
  fake_pid=$!
  pv_file=$(mktemp)
  echo 0 >"$pv_file"
  export XEDC_IMPORT_PROGRESS_SECS=1 XEDC_IMPORT_STALL_SECS=2
  (
    db_watch_import mysql "$sql_db" 100 "$pv_file" "" "$fake_pid"
  ) >"$stall_log" 2>&1 &
  watch_pid=$!
  sleep 6
  kill "$fake_pid" 2>/dev/null || true
  wait "$fake_pid" 2>/dev/null || true
  sleep 1
  kill "$watch_pid" 2>/dev/null || true
  wait "$watch_pid" 2>/dev/null || true
  if ! grep -q '导入进度' "$stall_log"; then
    echo "进度日志缺失" >&2
    cat "$stall_log" >&2
    exit 1
  fi
  if ! grep -q '无增长' "$stall_log"; then
    echo "卡住提示缺失" >&2
    cat "$stall_log" >&2
    exit 1
  fi
  echo "progress/stall ok"
  rm -f "$slow" "$stall_log" "$pv_file" "$progress_log" "$zsql"

  # 幂等：标记 + sha256 跳过；外来库拒绝；半成品 resume
  arch=$(mktemp)
  echo dummy >"$arch"
  sha=$(sha256sum "$arch" | awk '{print $1}')
  db_mark_owned "$sql_db"
  db_mark_imported "$sql_db" "$sha"
  if ! db_imported_matches "$sql_db" "$sha"; then
    echo "导入标记匹配失败" >&2
    exit 1
  fi
  if db_imported_matches "$sql_db" "deadbeef"; then
    echo "错误 sha 不应匹配" >&2
    exit 1
  fi
  # 外来库
  foreign=xedc_foreign_db
  mysql --protocol=socket -u root -e "DROP DATABASE IF EXISTS \`${foreign}\`; CREATE DATABASE \`${foreign}\`; CREATE TABLE \`${foreign}\`.x (id int);"
  rm -f "$(db_owned_file)"
  if db_is_owned "$foreign"; then
    echo "外来库不应标记为 owned" >&2
    exit 1
  fi
  mysql --protocol=socket -u root -e "DROP DATABASE IF EXISTS \`${foreign}\`"
  # 半成品：有 owned、无 imported → resume 应允许重建
  db_mark_owned "$sql_db"
  db_clear_import_marks
  db_is_owned "$sql_db"
  ! db_imported_matches "$sql_db" "$sha"
  echo "idempotency ok"

  rm -f "$sql_file" "$raw_err" "$arch"
  sql_cleanup
  rm -rf "$XEDC_STATE_DIR"
  trap - EXIT
else
  echo "本机没有 socket root，跳过真实导入"
fi
# --- 排除 glob、备份裁剪、磁盘预检 ---
# shellcheck source=../lib/exclude.sh
source "$ROOT/lib/exclude.sh"
xedc_excluded_glob 'www.conf.bak-2024-01-01' '*.bak-*' || { echo 'glob bak-* 失败' >&2; exit 1; }
xedc_excluded_glob 'DRUPAL_AUDIT.md' '*AUDIT*.md' || { echo 'glob AUDIT 失败' >&2; exit 1; }
xedc_excluded xedc-cache-npm || { echo 'xedc-cache 应排除' >&2; exit 1; }
xedc_excluded xedc-test-cache-npm || { echo 'xedc-test 应排除' >&2; exit 1; }
pr=$(mktemp -d)
mkdir -p "$pr/code/docs-archive" "$pr/system/docker/volumes" "$pr/system/nginx/conf.d"
printf 'x' >"$pr/system/docker/volumes/xedc-cache-npm.tar.zst"
printf 'y' >"$pr/system/nginx/conf.d/s.gitxx.xyz.conf"
printf 'z' >"$pr/code/FOO_REPORT_bar.md"
printf 'keep' >"$pr/code/index.php"
XEDC_STATE_DIR=$(mktemp -d)
xedc_prune_tree_by_exclude "$pr/code"
[[ ! -f "$pr/code/FOO_REPORT_bar.md" ]] || { echo 'REPORT 未删' >&2; exit 1; }
[[ -f "$pr/code/index.php" ]] || { echo '误删 index' >&2; exit 1; }
rm -rf "$pr" "$XEDC_STATE_DIR"
# shellcheck source=../lib/disk.sh
source "$ROOT/lib/disk.sh"
man=$(mktemp)
printf '%s\n' '{"backup":{"archive_bytes":1000000,"unpacked_bytes":5000000,"database_zst_bytes":200000}}' >"$man"
need=$(xedc_restore_need_kb /nonexist "$man")
if [[ ! "$need" =~ ^[0-9]+$ ]] || (( need < 1000 )); then
  echo "磁盘估算异常: ${need}" >&2
  exit 1
fi
small=$(mktemp -d)
mountpoint -q /tmp 2>/dev/null || true
if ! (xedc_disk_precheck_restore /dev/null "$man" "$small") 2>/tmp/xedc-precheck.err; then
  grep -q '磁盘空间不足' /tmp/xedc-precheck.err
  echo "disk precheck ok"
else
  echo "小目录预检应失败（若分区极大则跳过）"
fi
rm -f "$man" /tmp/xedc-precheck.err
rmdir "$small" 2>/dev/null || rm -rf "$small"
arch=$(mktemp --suffix=.tar.zst)
dest=$(mktemp -d)
printf 'line\n' | zstd -qf -o "$arch"
if ! xedc_archive_extract "$arch" "$dest/bad" 2>/tmp/xedc-tar.err; then
  lines=$(wc -l < /tmp/xedc-tar.err | tr -d ' ')
  [[ "${lines:-0}" -lt 50 ]]
  echo "tar brief errors ok"
fi
rm -f "$arch" /tmp/xedc-tar.err
rm -rf "$dest"
pack=$(mktemp -d)
mkdir -p "$pack/stage"
printf '{"site_root":"/var/www/drupal"}' >"$pack/stage/manifest.json"
php "$ROOT/lib/php/backup_manifest.php" --file "$pack/stage/manifest.json" --json '{"archive_bytes":1,"unpacked_bytes":2,"database_zst_bytes":3,"categories":{"code":1,"files":0,"database":3,"system":0,"docker_volumes":0}}'
grep -q '"unpacked_bytes": 2' "$pack/stage/manifest.json"
rm -rf "$pack"
echo "exclude/disk unit ok"

echo "unit ok"
exit "$fail"
