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
echo "unit ok"
exit "$fail"
