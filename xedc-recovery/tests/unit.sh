#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"
fail=0
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
sql_db=xedc_sqlfilter_test
sql_cleanup() {
  mysql --protocol=socket -u root -e "DROP DATABASE IF EXISTS \`${sql_db}\`" >/dev/null 2>&1 || true
}
if mysql --protocol=socket -u root -N -e 'SELECT 1' >/dev/null 2>&1; then
  trap sql_cleanup EXIT
  sql_file=$(mktemp)
  cat >"$sql_file" <<'EOF'
CREATE TABLE `cache_page` (
  `cid` varchar(255) NOT NULL,
  `data` longblob,
  PRIMARY KEY (`cid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `cache_page` VALUES ('', 'one');
INSERT INTO `cache_page` VALUES ('https://gitxx.xyz/cosplay/cos?sort_by=totalcount&model=39:', 'line1
HUGEHTMLMARKER
semi;colon
');
INSERT INTO `cache_page` VALUES ('', 'two');
CREATE TABLE `queue` (
  `item_id` int NOT NULL,
  `data` longblob,
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `queue` VALUES (1, 'keep-queue');
CREATE TABLE `node` (
  `nid` int NOT NULL,
  PRIMARY KEY (`nid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `node` VALUES (7);
EOF
  sql_cleanup
  mysql --protocol=socket -u root -e "CREATE DATABASE \`${sql_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  raw_err=$(mktemp)
  if mysql --protocol=socket -u root --batch "$sql_db" <"$sql_file" >/dev/null 2>"$raw_err"; then
    echo "未过滤的重复 cid 不应该导入成功" >&2
    exit 1
  fi
  grep -q '1062' "$raw_err"
  if grep -q 'HUGEHTMLMARKER' "$raw_err"; then
    echo "原始错误里出现了缓存 HTML" >&2
    exit 1
  fi
  sql_cleanup
  mysql --protocol=socket -u root -e "CREATE DATABASE \`${sql_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  map=$(mktemp)
  if ! php "$ROOT/lib/php/sql_filter.php" --filter --prefix '' --map "$map" <"$sql_file" \
    | mysql --protocol=socket -u root --batch "$sql_db" >/dev/null; then
    echo "过滤后的缓存备份导入失败" >&2
    exit 1
  fi
  rows=$(mysql --protocol=socket -u root -N -e "SELECT COUNT(*) FROM \`${sql_db}\`.cache_page")
  queue=$(mysql --protocol=socket -u root -N -e "SELECT data FROM \`${sql_db}\`.queue")
  node=$(mysql --protocol=socket -u root -N -e "SELECT nid FROM \`${sql_db}\`.node")
  [[ "$rows" == 0 ]]
  [[ "$queue" == keep-queue ]]
  [[ "$node" == 7 ]]
  sql_cleanup
  mysql --protocol=socket -u root -e "CREATE DATABASE \`${sql_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  if ! php "$ROOT/lib/php/sql_filter.php" --filter --prefix '' --map "$map" <"$sql_file" \
    | mysql --protocol=socket -u root --batch "$sql_db" >/dev/null; then
    echo "第二次导入失败" >&2
    exit 1
  fi
  rm -f "$sql_file" "$raw_err" "$map"
  sql_cleanup
  trap - EXIT
else
  echo "本机没有 socket root，跳过真实导入"
fi
echo "unit ok"
exit "$fail"
