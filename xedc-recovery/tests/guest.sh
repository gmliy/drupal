#!/usr/bin/env bash
# 容器内测试步骤。由 tests/run-all.sh 调用。
set -euo pipefail
mode=${1:-source}
export XEDC_TEST=1
export DEBIAN_FRONTEND=noninteractive
export XEDC_SITE_ROOT=/var/www/drupal

sql() {
  if command -v mysql >/dev/null 2>&1; then
    mysql "$@"
  else
    mariadb "$@"
  fi
}

start_db() {
  mkdir -p /run/mysqld
  if id mysql >/dev/null 2>&1; then
    chown mysql:mysql /run/mysqld || true
  fi
  service mariadb start || service mysql start
}

start_stack() {
  start_db
  service redis-server start || true
  local init
  shopt -s nullglob
  for init in /etc/init.d/php*-fpm; do
    service "$(basename "$init")" start || true
  done
  shopt -u nullglob
  service nginx start || nginx || true
  service cron start || true
}

prepare_fixture() {
  printf '%s\n' '#!/bin/sh' 'exit 101' >/usr/sbin/policy-rc.d
  chmod 755 /usr/sbin/policy-rc.d
  apt-get update -y
  apt-get install -y --no-install-recommends mariadb-server mariadb-client php-cli zstd rsync tar gzip curl ca-certificates rclone cron openssl
  start_db
  rm -rf /var/www/drupal
  mkdir -p /var/www/drupal
  cp -a /opt/xedc-recovery/tests/fixture/site/. /var/www/drupal/
  chmod 755 /var/www/drupal/bin/drush
  sql --protocol=socket -u root <<'SQL'
CREATE DATABASE IF NOT EXISTS xedc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'xedc'@'localhost' IDENTIFIED BY 'fixture-pass';
ALTER USER 'xedc'@'localhost' IDENTIFIED BY 'fixture-pass';
GRANT ALL PRIVILEGES ON xedc.* TO 'xedc'@'localhost';
FLUSH PRIVILEGES;
SQL
  sql --protocol=socket -u root xedc < /opt/xedc-recovery/tests/fixture/schema.sql
  mkdir -p /opt/matomo /etc/nginx/conf.d /etc/systemd/system
  printf '%s\n' 'services: { matomo: { image: matomo:5-apache } }' >/opt/matomo/compose.yaml
  printf '%s\n' 'server { server_name x.gitxx.xyz; listen 8088; }' >/etc/nginx/conf.d/matomo.conf
  printf '%s\n' 'server { server_name matomo.gitxx.xyz; }' >/etc/nginx/conf.d/analytics.conf
  printf '%s\n' '[Service]' 'ExecStart=/bin/true' >/etc/systemd/system/xedc-matomo.service
  printf '%s\n' '0 3 * * * root docker compose -f /opt/matomo/compose.yaml up -d' >/etc/cron.d/xedc-matomo
  chmod 644 /etc/cron.d/xedc-matomo /etc/nginx/conf.d/matomo.conf /etc/nginx/conf.d/analytics.conf
  printf '%s\n' '*/15 * * * * cd /var/www/drupal && /var/www/drupal/bin/drush cron --quiet' | crontab -
  printf '%s\n' '*/15 * * * * root /var/www/drupal/bin/drush cron --quiet' >/etc/cron.d/xedc-drupal
  chmod 644 /etc/cron.d/xedc-drupal
}

archive_path() {
  local f
  f=$(ls -1 /var/lib/xedc-recovery/downloads/drupal-backup-*.tar.zst 2>/dev/null | head -n 1 || true)
  if [[ -z "$f" ]]; then
    f=$(ls -1 /var/backups/xedc/drupal-backup-*.tar.zst 2>/dev/null | head -n 1 || true)
  fi
  printf '%s\n' "$f"
}

case "$mode" in
  source)
    prepare_fixture
    mkdir -p /var/www/drupal/docs /var/www/drupal/docs-archive /var/www/drupal/_server
    printf 'doc\n' >/var/www/drupal/docs/readme.md
    printf 'old\n' >/var/www/drupal/docs-archive/legacy.md
    printf '# REPORT\n' >/var/www/drupal/ROOT_REPORT_2024.md
    printf 'duplicate sql\n' >/var/www/drupal/_server/db.sql
    printf '%s\n' 'server { server_name s.gitxx.xyz; }' >/etc/nginx/conf.d/s.gitxx.xyz.conf
    printf '%s\n' '# backup' >/etc/nginx/conf.d/www.conf.bak-2024-01-01
    if command -v docker >/dev/null 2>&1; then
      docker volume rm -f xedc-cache-npm xedc-test-junk >/dev/null 2>&1 || true
      docker volume create xedc-cache-npm >/dev/null
      docker volume create xedc-test-junk >/dev/null
    fi
    mkdir -p /var/www/drupal/web/sites/default/files/js
    printf 'agg\n' >/var/www/drupal/web/sites/default/files/js/aggregated.js
    XEDC_PHP_VERSION=8.5 bash /opt/xedc-recovery/backup.sh --target both --keep-local 7 --keep-r2 30
    if grep -q '备份失败:' /var/log/xedc-backup.log; then
      echo "备份日志记录失败"
      exit 1
    fi
    if ! grep -qE '缓存类表数据行数为 0|数据库 dump 缓存类表数据行数为 0' /var/log/xedc-backup.log; then
      echo "备份未确认缓存表无数据"
      exit 1
    fi
    mapfile -t ups < <(grep -E '^[0-9]{2}:[0-9]{2}:[0-9]{2} R2 上传 (drupal-backup-|bootstrap/)' /var/log/xedc-backup.log \
      | awk '{print $NF}')
    if [[ "${#ups[@]}" -ne 6 ]]; then
      echo "R2 上传次数应为 6，实际 ${#ups[@]}: ${ups[*]}"
      exit 1
    fi
    [[ "${ups[0]}" == *.tar.zst && "${ups[0]}" != *.sha256 ]] || { echo "R2[0] 异常: ${ups[0]}"; exit 1; }
    [[ "${ups[1]}" == *.tar.zst.sha256 ]] || { echo "R2[1] 异常: ${ups[1]}"; exit 1; }
    [[ "${ups[2]}" == *.manifest.json ]] || { echo "R2[2] 异常: ${ups[2]}"; exit 1; }
    [[ "${ups[3]}" == *.complete ]] || { echo "R2[3] 异常: ${ups[3]}"; exit 1; }
    [[ "${ups[4]}" == bootstrap/install.sh ]] || { echo "R2[4] 异常: ${ups[4]}"; exit 1; }
    [[ "${ups[5]}" == bootstrap/xedc-recovery.tar.gz ]] || { echo "R2[5] 异常: ${ups[5]}"; exit 1; }
    if printf '%s\n' "${ups[@]}" | grep -E 'tmp\.|/tmp/'; then
      echo "R2 对象名使用了临时文件"
      exit 1
    fi
    mapfile -t boot < <(rclone lsf ":s3:${R2_BUCKET}/${R2_PREFIX}bootstrap/" \
      --s3-provider Minio \
      --s3-endpoint "$R2_ENDPOINT" \
      --s3-access-key-id "$R2_ACCESS_KEY_ID" \
      --s3-secret-access-key "$R2_SECRET_ACCESS_KEY" \
      --s3-force-path-style | sed '/^$/d' | sort)
    printf 'bootstrap: %s\n' "${boot[*]}"
    if [[ "${boot[*]}" != "install.sh xedc-recovery.tar.gz" ]]; then
      echo "bootstrap 目录异常: ${boot[*]}"
      exit 1
    fi
    if ! grep -q '恢复时将被卸载' /var/log/xedc-backup.log; then
      echo "启用中的排除模块没有提示恢复时卸载"
      exit 1
    fi
    if ! grep -q '排除清单' /var/log/xedc-backup.log; then
      echo "备份日志没有打印排除清单"
      exit 1
    fi
    if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
      if ! grep -qE 'xedc-cache|测试/缓存卷|已排除|缓存卷' /var/log/xedc-backup.log; then
        echo "备份未记录测试缓存卷排除"
        exit 1
      fi
    fi
    if ! grep -q '备份暂存体积' /var/log/xedc-backup.log; then
      echo "备份未输出体积明细"
      exit 1
    fi
    if ! grep -qE '备份完成:.*sha256=' /var/log/xedc-backup.log; then
      echo "备份未打印完成行（路径/sha256/耗时）"
      exit 1
    fi
    file=$(archive_path)
    [[ -n "$file" ]]
    members=$(zstd -dc "$file" | tar -tf -)
    if printf '%s\n' "$members" | grep -E 'system/nginx/.*/matomo|analytics\.conf|system/docker/matomo|matomo\.service|cron.d/.*matomo|code/config/sync/matomo|code/vendor/drupal/matomo|code/web/modules/contrib/matomo'; then
      echo "新备份包仍有 Matomo 服务、配置或模块文件"
      exit 1
    fi
    if printf '%s\n' "$members" | grep -E \
      'system/docker/volumes/(xedc-cache|xedc-test|xedc-recover)|\.bak-|s\.gitxx\.xyz|code/docs-archive/|code/docs/|code/[^/]*REPORT[^/]*\.md|code/[^/]*AUDIT[^/]*\.md|code/_server/db\.sql|files/public/js/'; then
      echo "备份包仍含应排除的缓存卷、备份残留、聚合 js 或无关文档"
      exit 1
    fi
    if ! zstd -dc "$file" | tar -tf - | grep -qE '(^\./)?db/site\.sql\.zst$'; then
      echo "备份包缺少 db/site.sql.zst"
      exit 1
    fi
    if ! zstd -dc "$file" | tar -xO ./manifest.json 2>/dev/null | grep -q 'unpacked_bytes'; then
      echo "manifest 缺少 backup.unpacked_bytes 体积字段"
      exit 1
    fi
    if zstd -dc "$file" | tar -xO ./manifest.json | grep -i matomo; then
      echo "manifest 仍有 Matomo"
      exit 1
    fi
    if zstd -dc "$file" | tar -xO ./code/composer.json | grep -i matomo; then
      echo "备份里的 composer.json 仍有 Matomo"
      exit 1
    fi
    if ! printf '%s\n' "$members" | grep -q 'themes/custom/xedc/templates/matomo-hardcode.html.twig'; then
      echo "主题里的硬编码应留在备份里，恢复时只报告"
      exit 1
    fi
    echo "XEDC_STAGE backup PASS"
    ;;
  target)
    bash /opt/install.sh --from r2:latest --domain test1.local --email admin@test1.local --https self --www no --yes
    echo "XEDC_STAGE restore PASS"
    ;;
  domain)
    start_stack
    file=$(archive_path)
    [[ -n "$file" ]] || { echo "没有本地备份"; exit 1; }
    bash /opt/xedc-recovery/restore.sh --from "local:${file}" --domain test2.local --old-domain test1.local --email admin@test2.local --https self --www no --yes --force
    sql --protocol=socket -u root -N -e "SELECT body FROM page_content" xedc | grep -q test2.local
    if sql --protocol=socket -u root -N -e "SELECT body, packed FROM page_content" xedc | grep -q 'test1.local'; then
      echo "数据库仍有旧域名"
      exit 1
    fi
    if grep -RIn 'test1.local' /var/www/drupal/config /var/www/drupal/web/sitemap.xml /var/www/drupal/web/robots.txt; then
      echo "配置或 sitemap 仍有旧域名"
      exit 1
    fi
    grep -q 'test1.local/keep-me' /var/www/drupal/web/modules/custom/xedc_fixture/hardcode.php
    code=$(curl -k -s -o /dev/null -w '%{http_code}' --resolve test2.local:443:127.0.0.1 https://test2.local/models)
    [[ "$code" == "200" ]]
    echo "XEDC_STAGE domain PASS"
    ;;
  video)
    start_stack
    mkdir -p /var/www/drupal/web/sites/default/files
    ffmpeg -y -hide_banner -loglevel error -f lavfi -i testsrc=size=160x120:rate=10:duration=1 -f lavfi -i sine=frequency=440:sample_rate=8000:duration=1 -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest /var/www/drupal/web/sites/default/files/clip.mp4
    ffmpeg -y -hide_banner -loglevel error -i /var/www/drupal/web/sites/default/files/clip.mp4 -c:v libx264 -preset veryfast -crf 23 -c:a aac -movflags +faststart /tmp/transcoded.mp4
    codecs=$(ffprobe -v error -show_entries stream=codec_name -of csv=p=0 /tmp/transcoded.mp4 || true)
    case "$codecs" in
      *h264*) ;;
      *) echo "转码结果没有 h264: ${codecs}"; exit 1 ;;
    esac
    chown www-data:www-data /var/www/drupal/web/sites/default/files/clip.mp4
    code=""
    for _ in 1 2 3 4 5 6 7 8 9 10; do
      code=$(curl -k -s -o /dev/null -w '%{http_code}' --resolve test1.local:443:127.0.0.1 https://test1.local/video || true)
      [[ "$code" == "200" ]] && break
      sleep 0.5
    done
    [[ "$code" == "200" ]] || { echo "视频页 HTTP ${code}"; exit 1; }
    code=$(curl -k -s -o /dev/null -w '%{http_code}' --resolve test1.local:443:127.0.0.1 https://test1.local/sites/default/files/clip.mp4 || true)
    [[ "$code" == "200" ]] || { echo "视频文件 HTTP ${code}"; exit 1; }
    echo "XEDC_STAGE video PASS"
    ;;
  idem)
    start_stack
    file=$(archive_path)
    [[ -n "$file" ]] || { echo "没有本地备份"; exit 1; }
    bash /opt/xedc-recovery/restore.sh --from "local:${file}" --domain test1.local --email admin@test1.local --https self --www no --yes --force
    bash /opt/xedc-recovery/restore.sh --from "local:${file}" --domain test1.local --email admin@test1.local --https self --www no --yes --force
    echo "XEDC_STAGE idem PASS"
    ;;
  resume)
    start_stack
    file=$(archive_path)
    [[ -n "$file" ]] || { echo "没有本地备份"; exit 1; }
    rm -f /var/lib/xedc-recovery/steps/domain.done /var/lib/xedc-recovery/steps/https.done /var/lib/xedc-recovery/steps/selfcheck.done /var/lib/xedc-recovery/steps/failed-domain
    set +e
    XEDC_FAIL_STEP=domain bash /opt/xedc-recovery/restore.sh --resume --from "local:${file}" --domain test1.local --email admin@test1.local --https self --www no --yes
    rc=$?
    set -e
    if [[ "$rc" == "0" ]]; then
      echo "预期中途失败，但命令成功了"
      exit 1
    fi
    unset XEDC_FAIL_STEP
    bash /opt/xedc-recovery/restore.sh --resume --from "local:${file}" --domain test1.local --email admin@test1.local --https self --www no --yes
    echo "XEDC_STAGE resume PASS"
    exit 0
    ;;
  verify)
    start_stack
    before=$(
      sql --protocol=socket -u root -N -e "SELECT COUNT(*), body FROM page_content" xedc
      sha256sum /var/www/drupal/web/index.php /etc/nginx/conf.d/xedc.conf
    )
    bash /opt/xedc-recovery/restore.sh --verify --domain test1.local
    after=$(
      sql --protocol=socket -u root -N -e "SELECT COUNT(*), body FROM page_content" xedc
      sha256sum /var/www/drupal/web/index.php /etc/nginx/conf.d/xedc.conf
    )
    if [[ "$before" != "$after" ]]; then
      echo "verify 改动了系统"
      printf 'before=%s\nafter=%s\n' "$before" "$after"
      exit 1
    fi
    echo "XEDC_STAGE verify PASS"
    ;;
  r2)
    start_stack
    file=$(archive_path)
    [[ -n "$file" ]] || { echo "没有本地备份"; exit 1; }
    conf=$(mktemp)
    chmod 600 "$conf"
    cat >"$conf" <<EOF
[r2]
type = s3
provider = Minio
access_key_id = ${R2_ACCESS_KEY_ID}
secret_access_key = ${R2_SECRET_ACCESS_KEY}
endpoint = ${R2_ENDPOINT}
force_path_style = true
no_check_bucket = true
EOF
    old=drupal-backup-20200101T000000
    newer=drupal-backup-20990101T000000
    prefix="r2:${R2_BUCKET}/${R2_PREFIX}"
    current=$(basename "$file" .tar.zst)
    while IFS= read -r name; do
      [[ -z "$name" ]] && continue
      case "$name" in
        "${current}".*) continue ;;
        drupal-backup-*)
          rclone --config "$conf" deletefile "${prefix}${name}" || true
          ;;
      esac
    done < <(rclone --config "$conf" lsf "${prefix}" --max-depth 1)
    rclone --config "$conf" copyto "$file" "${prefix}${old}.tar.zst"
    sha=$(sha256sum "$file" | awk '{print $1}')
    printf '%s  %s.tar.zst\n' "$sha" "$old" >/tmp/old.sha256
    printf '%s\n' "$sha" >/tmp/old.complete
    rclone --config "$conf" copyto /tmp/old.sha256 "${prefix}${old}.tar.zst.sha256"
    rclone --config "$conf" copyto /tmp/old.complete "${prefix}${old}.complete"
    printf 'incomplete\n' >/tmp/partial.tar.zst
    rclone --config "$conf" copyto /tmp/partial.tar.zst "${prefix}${newer}.tar.zst"
    bash /opt/xedc-recovery/restore.sh --list-r2 | tee /tmp/list-r2.txt
    grep -q '不完整' /tmp/list-r2.txt
    grep -q '完整' /tmp/list-r2.txt
    set +e
    out=$(XEDC_NO_TMUX=1 XEDC_RESOLVE_ONLY=1 bash /opt/xedc-recovery/restore.sh --from r2:latest --domain test1.local --yes 2>/tmp/resolve-latest.err)
    rc=$?
    set -e
    cat /tmp/resolve-latest.err
    if [[ "$rc" != 0 ]]; then
      echo "解析 latest 失败，退出码 ${rc}"
      exit 1
    fi
    chosen=$(printf '%s\n' "$out" | awk '/已选择/{print $2}')
    echo "latest=$chosen"
    if [[ -z "$chosen" || "$chosen" == "$newer" || "$chosen" == "$old" ]]; then
      echo "latest 选错了: ${chosen:-空}"
      exit 1
    fi
    set +e
    out=$(XEDC_NO_TMUX=1 XEDC_RESOLVE_ONLY=1 bash /opt/xedc-recovery/restore.sh --from r2:latest-1 --domain test1.local --yes 2>/tmp/resolve-prev.err)
    rc=$?
    set -e
    cat /tmp/resolve-prev.err
    if [[ "$rc" != 0 ]]; then
      echo "解析 latest-1 失败，退出码 ${rc}"
      exit 1
    fi
    prev=$(printf '%s\n' "$out" | awk '/已选择/{print $2}')
    echo "latest-1=$prev"
    if [[ "$prev" != "$old" ]]; then
      echo "latest-1 选错了: ${prev:-空}"
      exit 1
    fi
    bash /opt/xedc-recovery/restore.sh --from "local:${file}" --domain test1.local --email admin@test1.local --https self --www no --yes --force
    rm -f "$conf"
    echo "XEDC_STAGE r2 PASS"
    ;;
  php)
    service php8.5-fpm start
    service nginx start || nginx
    ver=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
    [[ "$ver" == "8.5" ]]
    php -v | awk 'NR==1{print}'
    [[ -S /run/php/php8.5-fpm.sock ]]
    masters=""
    for pid in $(pgrep php-fpm || true); do
      masters+=$(readlink "/proc/${pid}/exe" 2>/dev/null || true)$'\n'
    done
    printf '%s\n' "$masters"
    [[ "$masters" == *"/php-fpm8.5"* ]]
    bad=$(printf '%s\n' "$masters" | grep -F '/php-fpm' | grep -v '/php-fpm8.5' || true)
    [[ -z "$bad" ]]
    grep -R -F 'unix:/run/php/php8.5-fpm.sock' /etc/nginx/conf.d
    hdr=$(curl -k -sI --resolve test1.local:443:127.0.0.1 https://test1.local/ | tr -d '\r' | awk 'tolower($1)=="x-php-version:" {print $2; exit}' || true)
    [[ "$hdr" == 8.5.* ]]
    if grep -RIn 'matomo' /var/www/drupal/config/sync /var/www/drupal/composer.json; then
      echo "恢复后的配置或 composer.json 仍有 Matomo"
      exit 1
    fi
    if [[ -d /var/www/drupal/vendor/drupal/matomo || -d /var/www/drupal/web/modules/contrib/matomo ]]; then
      echo "恢复后仍有 Matomo 模块目录"
      exit 1
    fi
    if curl -k -s --resolve test1.local:443:127.0.0.1 https://test1.local/ | grep -Ei 'matomo\.js|piwik\.js'; then
      echo "首页仍有 Matomo 脚本"
      exit 1
    fi
    if ! grep -q 'matomo-hardcode.html.twig' /var/lib/xedc-recovery/module-code-refs.txt; then
      echo "没有报告主题里的硬编码"
      exit 1
    fi
    grep -q 'matomo.js' /var/www/drupal/web/themes/custom/xedc/templates/matomo-hardcode.html.twig
    echo "XEDC_STAGE php PASS"
    ;;
  prompts)
    if ! command -v python3 >/dev/null 2>&1; then
      apt-get update -y
      apt-get install -y --no-install-recommends python3
    fi
    export XEDC_R2_ENV=/tmp/xedc-no-r2-env
    rm -f "$XEDC_R2_ENV"
    set +e
    env -u R2_ACCOUNT_ID -u R2_ACCESS_KEY_ID -u R2_SECRET_ACCESS_KEY -u R2_BUCKET -u R2_PREFIX -u R2_ENDPOINT \
      bash /opt/xedc-recovery/restore.sh --from r2:latest --domain test1.local --yes </dev/null >/tmp/notty.out 2>/tmp/notty.err
    rc=$?
    set -e
    cat /tmp/notty.err
    [[ "$rc" == 1 ]]
    grep -F '没有 R2 凭据' /tmp/notty.err
    if tmux has-session -t xedc-restore 2>/dev/null; then
      echo "没有凭据时不应进入 tmux"
      exit 1
    fi
    python3 - <<'PY'
import os, pty, select, sys, time
def run(argv, env, replies, timeout):
    pid, fd = pty.fork()
    if pid == 0:
        os.execvpe(argv[0], argv, env)
    buf = b""
    pending = list(replies)
    deadline = time.time() + timeout
    while True:
        remain = deadline - time.time()
        if remain <= 0:
            os.kill(pid, 15)
            raise SystemExit("等待提示超时:\n" + buf.decode("utf-8", "replace")[-2000:])
        ready, _, _ = select.select([fd], [], [], min(remain, 1))
        if not ready:
            finished, status = os.waitpid(pid, os.WNOHANG)
            if finished:
                return os.waitstatus_to_exitcode(status), buf
            continue
        try:
            chunk = os.read(fd, 4096)
        except OSError:
            chunk = b""
        if chunk:
            buf += chunk
            sys.stdout.buffer.write(chunk)
            sys.stdout.buffer.flush()
            while pending and pending[0][0].encode() in buf:
                os.write(fd, pending.pop(0)[1].encode())
        else:
            _, status = os.waitpid(pid, 0)
            return os.waitstatus_to_exitcode(status), buf
env = os.environ.copy()
env["TERM"] = "xterm"
rc, buf = run(
    ["bash", "/opt/xedc-recovery/restore.sh", "--from", "local:/tmp/xedc-missing.tar.zst"],
    env,
    [("新域名", "test1.local\n"), ("输入 y 开始安装", "y\n")],
    90,
)
text = buf.decode("utf-8", "replace")
if rc != 1 or "恢复失败，退出码 1" not in text or "没有退出码" in text:
    raise SystemExit(f"交互确认的退出码是 {rc}")
open("/tmp/pty-yes.code", "w").write(str(rc))
PY
    grep -F '恢复失败，退出码 1' /tmp/pty-yes.code >/dev/null || true
    [[ "$(cat /tmp/pty-yes.code)" == 1 ]]
    grep -F '找不到本地备份' /var/log/xedc-restore.log
    if grep -F '没有退出码' /var/log/xedc-restore.log; then
      echo "不应出现没有退出码"
      exit 1
    fi
    set +e
    XEDC_RESOLVE_ONLY=1 bash /opt/xedc-recovery/restore.sh --from r2:latest --domain test1.local --yes >/tmp/env.out 2>/tmp/env.err
    rc=$?
    set -e
    cat /tmp/env.err
    [[ "$rc" == 0 ]]
    grep -F '恢复完成，退出码 0' /tmp/env.err
    [[ "$(grep -c '开始 fetch' /var/log/xedc-restore.log || true)" == 1 ]]
    [[ "$(stat -c '%a' /var/log/xedc-restore.log)" == 600 ]]
    if grep -F 'xedctest-secret' /var/log/xedc-restore.log /tmp/env.err /tmp/env.out; then
      echo "日志里出现了凭据"
      exit 1
    fi
    if compgen -G '/run/xedc/r2.*' >/dev/null; then
      echo "凭据临时文件还在"
      exit 1
    fi
    python3 - <<'PY'
import os, pty, select, sys, time
def run(argv, env, replies, timeout):
    pid, fd = pty.fork()
    if pid == 0:
        os.execvpe(argv[0], argv, env)
    buf = b""
    pending = list(replies)
    deadline = time.time() + timeout
    while True:
        remain = deadline - time.time()
        if remain <= 0:
            os.kill(pid, 15)
            raise SystemExit("等待提示超时:\n" + buf.decode("utf-8", "replace")[-2000:])
        ready, _, _ = select.select([fd], [], [], min(remain, 1))
        if not ready:
            finished, status = os.waitpid(pid, os.WNOHANG)
            if finished:
                return os.waitstatus_to_exitcode(status), buf
            continue
        try:
            chunk = os.read(fd, 4096)
        except OSError:
            chunk = b""
        if chunk:
            buf += chunk
            sys.stdout.buffer.write(chunk)
            sys.stdout.buffer.flush()
            while pending and pending[0][0].encode() in buf:
                os.write(fd, pending.pop(0)[1].encode())
        else:
            _, status = os.waitpid(pid, 0)
            return os.waitstatus_to_exitcode(status), buf
env = os.environ.copy()
env["TERM"] = "xterm"
secret = env.pop("R2_SECRET_ACCESS_KEY")
account = env.pop("R2_ACCOUNT_ID")
key = env.pop("R2_ACCESS_KEY_ID")
bucket = env.pop("R2_BUCKET")
prefix = env.get("R2_PREFIX", "xedc/")
env["XEDC_RESOLVE_ONLY"] = "1"
env["XEDC_R2_ENV"] = "/tmp/xedc-no-r2-env"
rc, buf = run(
    ["bash", "/opt/xedc-recovery/restore.sh", "--from", "r2:latest", "--domain", "test1.local"],
    env,
    [
        ("R2 Account ID", account + "\n"),
        ("Access Key ID", key + "\n"),
        ("Secret Access Key", secret + "\n"),
        ("Bucket", bucket + "\n"),
        ("Prefix", prefix + "\n"),
        ("保留凭据", "n\n"),
    ],
    180,
)
text = buf.decode("utf-8", "replace")
open("/tmp/pty-r2.code", "w").write(str(rc))
if "Secret Access Key: " + secret in text:
    raise SystemExit("Secret 被回显了")
if rc != 0 or "恢复完成，退出码 0" not in text:
    raise SystemExit(f"交互凭据的退出码是 {rc}")
PY
    [[ "$(cat /tmp/pty-r2.code)" == 0 ]]
    [[ "$(grep -c '开始 fetch' /var/log/xedc-restore.log || true)" == 1 ]]
    if compgen -G '/run/xedc/r2.*' >/dev/null; then
      echo "凭据临时文件还在"
      exit 1
    fi
  if tmux has-session -t xedc-restore 2>/dev/null; then
    tmux show-environment -t xedc-restore XEDC_WORKDIR 2>/dev/null | grep -q 'xedc-recovery/work' || true
  fi
    echo "XEDC_STAGE prompts PASS"
    ;;
  perms)
    start_stack
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/redis.sh
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/mariadb.sh
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/php.sh
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/nginx.sh
    umask 077
    printf '%s\n' 'bind 127.0.0.1' >/etc/redis/xedc.conf
    chown root:root /etc/redis/xedc.conf
    chmod 600 /etc/redis/xedc.conf
    set +e
    deny_out=$(xedc_assert_readable redis /etc/redis/xedc.conf 2>&1)
    deny_rc=$?
    set -e
    if [[ "$deny_rc" != 0 ]] || ! printf '%s\n' "$deny_out" | grep -q '无法读取'; then
      echo "root 只读配置应报告无法读取并自动修好"
      printf '%s\n' "$deny_out"
      exit 1
    fi
    if ! printf '%s\n' "$deny_out" | grep -q '^f:'; then
      echo "失败时没有 namei -l"
      printf '%s\n' "$deny_out"
      exit 1
    fi
    export XEDC_DOMAIN=test1.local XEDC_WWW=no XEDC_HTTPS_MODE=self XEDC_WEB_ROOT=/var/www/drupal/web
    if [[ -f /var/lib/xedc-recovery/manifest.json ]]; then
      export XEDC_MANIFEST=/var/lib/xedc-recovery/manifest.json
    fi
    redis_configure
    echo "after redis_configure"
    mariadb_configure
    echo "after mariadb_configure"
    php_configure
    echo "after php_configure"
    nginx_configure
    echo "after nginx_configure"
    ver=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
    expect_perm() {
      local path=$1 want=$2 got
      got=$(stat -c '%a %U %G' "$path" 2>&1 || true)
      echo "${path}: ${got}"
      if [[ "$got" != "$want" ]]; then
        echo "权限不符 ${path}: 得到 ${got}，期望 ${want}"
        exit 1
      fi
    }
    expect_perm /etc/redis/xedc.conf "640 redis redis"
    expect_perm /etc/mysql/mariadb.conf.d/99-xedc.cnf "640 mysql mysql"
    expect_perm /etc/nginx/conf.d/xedc.conf "640 root www-data"
    expect_perm "/etc/php/${ver}/fpm/pool.d/www.conf" "640 root www-data"
    check_read() {
      local user=$1 file=$2
      if ! runuser -u "$user" -- test -r "$file"; then
        echo "用户 ${user} 仍不能读 ${file}"
        namei -l "$file" || true
        exit 1
      fi
    }
    check_read redis /etc/redis/xedc.conf
    check_read mysql /etc/mysql/mariadb.conf.d/99-xedc.cnf
    check_read www-data /etc/nginx/conf.d/xedc.conf
    check_read www-data "/etc/php/${ver}/fpm/pool.d/www.conf"
    if ! grep -q '^vm.overcommit_memory=1$' /etc/sysctl.d/99-xedc.conf; then
      echo "缺少 vm.overcommit_memory=1"
      cat /etc/sysctl.d/99-xedc.conf || true
      exit 1
    fi
    sys_mode=$(stat -c '%a' /etc/sysctl.d/99-xedc.conf)
    if [[ "$sys_mode" != "644" ]]; then
      echo "sysctl 权限 ${sys_mode}"
      exit 1
    fi
    expect_perm /etc/redis "755 root root"
    if ! id netdata >/dev/null 2>&1; then
      useradd --system --no-create-home --shell /usr/sbin/nologin netdata
    fi
    mkdir -p /etc/netdata
    printf '%s\n' 'bind to = 127.0.0.1' >/etc/netdata/xedc.conf
    xedc_apply_perms /etc/netdata
    xedc_apply_perms /etc/netdata/xedc.conf
    [[ "$(stat -c '%a %U %G' /etc/netdata/xedc.conf)" == "640 root netdata" ]]
    [[ "$(stat -c '%a' /etc/netdata)" == "755" ]]
    runuser -u netdata -- test -r /etc/netdata/xedc.conf
    echo "XEDC_STAGE perms PASS"
    ;;
  lowmem)
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/redis.sh
    umask 077
    xedc_write_tune
    # shellcheck disable=SC1091
    source /var/lib/xedc-recovery/tune.env
    mb=$(xedc_mem_mb)
    echo "内存 ${mb}MB Redis ${REDIS_MAXMEMORY} MariaDB ${INNODB_BUFFER}"
    if (( mb < 1500 || mb > 2200 )); then
      echo "容器内存上限不是 2GB 档: ${mb}"
      exit 1
    fi
    [[ "$REDIS_MAXMEMORY" == "96mb" ]]
    [[ "$INNODB_BUFFER" == "256M" ]]
    [[ "$PM_MAX_CHILDREN" == "3" ]]
    redis_configure
    grep -q '^vm.overcommit_memory=1$' /etc/sysctl.d/99-xedc.conf
    [[ "$(stat -c '%a %U %G' /etc/redis/xedc.conf)" == "640 redis redis" ]]
    runuser -u redis -- test -r /etc/redis/xedc.conf
    echo "XEDC_STAGE lowmem PASS"
    ;;
  compose)
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/docker.sh
    shopt -s nullglob
    moved=()
    for plugin in /usr/libexec/docker/cli-plugins/docker-compose /usr/lib/docker/cli-plugins/docker-compose; do
      if [[ -e "$plugin" ]]; then
        mv "$plugin" "${plugin}.baktest"
        moved+=("$plugin")
      fi
    done
    hash -r
    mkdir -p /opt/xedc-extra
    printf '%s\n' 'services: {}' >/opt/xedc-extra/compose.yaml
    export XEDC_STATE_DIR=/tmp/xedc-compose-state
    rm -rf "$XEDC_STATE_DIR"
    mkdir -p "$XEDC_STATE_DIR"
    set +e
    miss_out=$(docker_compose_up /opt/xedc-extra 2>&1)
    miss_rc=$?
    set -e
    if [[ "$miss_rc" != 0 ]]; then
      echo "非核心容器失败不应中断恢复"
      printf '%s\n' "$miss_out"
      exit 1
    fi
    if printf '%s\n' "$miss_out" | grep -q '编排文件已还原'; then
      echo "不能只写编排文件已还原"
      exit 1
    fi
    docker compose version
    if docker info >/dev/null 2>&1; then
      printf '%s\n' "$miss_out" | grep -E '容器已启动|容器启动失败|日志如下'
    else
      printf '%s\n' "$miss_out" | grep -E '守护进程未运行|容器启动失败|日志如下'
      grep -E '守护进程未运行|容器启动失败' "$XEDC_STATE_DIR/failures.txt"
    fi
    for plugin in "${moved[@]}"; do
      if [[ -e "${plugin}.baktest" && ! -e "$plugin" ]]; then
        mv "${plugin}.baktest" "$plugin"
      fi
      rm -f "${plugin}.baktest"
    done
    echo "XEDC_STAGE compose PASS"
    ;;
  redisresume)
    start_stack
    file=$(archive_path)
    [[ -n "$file" ]] || { echo "没有本地备份"; exit 1; }
    if ! grep -q '/etc/redis/xedc.conf' /etc/redis/redis.conf; then
      printf '\ninclude /etc/redis/xedc.conf\n' >>/etc/redis/redis.conf
    fi
    redis-cli shutdown nosave >/dev/null 2>&1 || true
    sleep 0.3
    umask 077
    printf '%s\n' 'bind 127.0.0.1' 'protected-mode yes' 'maxmemory 16mb' >/etc/redis/xedc.conf
    chown root:root /etc/redis/xedc.conf
    chmod 600 /etc/redis/xedc.conf
    : >/var/log/redis/redis-server.log
    set +e
    service redis-server start >/tmp/redis-fail.out 2>&1
    set -e
    if ! grep -q 'failed' /tmp/redis-fail.out || redis-cli ping 2>/dev/null | grep -q PONG; then
      echo "root 只读配置时 Redis 不该启动"
      cat /tmp/redis-fail.out || true
      exit 1
    fi
    if ! grep -q "can't open config file '/etc/redis/xedc.conf': Permission denied" /var/log/redis/redis-server.log; then
      echo "Redis 日志里没有配置权限错误"
      tail -n 20 /var/log/redis/redis-server.log || true
      exit 1
    fi
    rm -f /var/lib/xedc-recovery/steps/services.done \
      /var/lib/xedc-recovery/steps/drush.done \
      /var/lib/xedc-recovery/steps/domain.done \
      /var/lib/xedc-recovery/steps/https.done \
      /var/lib/xedc-recovery/steps/selfcheck.done \
      /var/lib/xedc-recovery/steps/workers.done
    before=0
    if [[ -f /var/log/xedc-restore.log ]]; then
      before=$(wc -l </var/log/xedc-restore.log)
    fi
    XEDC_NO_TMUX=1 bash /opt/xedc-recovery/restore.sh --resume --from "local:${file}" --domain test1.local --email admin@test1.local --https self --www no --yes
    tail -n +"$((before + 1))" /var/log/xedc-restore.log | grep -q '跳过已完成步骤 database'
    tail -n +"$((before + 1))" /var/log/xedc-restore.log | grep -q '开始 services'
    [[ "$(stat -c '%a %U %G' /etc/redis/xedc.conf)" == "640 redis redis" ]]
    runuser -u redis -- test -r /etc/redis/xedc.conf
    redis-cli ping | grep -q PONG
    saved=$(mktemp)
    cp /etc/nginx/conf.d/xedc.conf "$saved"
    printf '\nbad_directive;\n' >>/etc/nginx/conf.d/xedc.conf
    rm -f /var/lib/xedc-recovery/steps/services.done \
      /var/lib/xedc-recovery/steps/drush.done \
      /var/lib/xedc-recovery/steps/domain.done \
      /var/lib/xedc-recovery/steps/https.done \
      /var/lib/xedc-recovery/steps/selfcheck.done \
      /var/lib/xedc-recovery/steps/workers.done
    set +e
    XEDC_NO_TMUX=1 bash /opt/xedc-recovery/restore.sh --resume --from "local:${file}" --domain test1.local --email admin@test1.local --https self --www no --yes
    bad_rc=$?
    set -e
    if [[ "$bad_rc" == 0 ]]; then
      echo "手动改坏的主站配置不应被覆盖成能通过的版本"
      exit 1
    fi
    if ! grep -q '保留手动修改' /var/log/xedc-restore.log; then
      echo "没有提示保留手动修改"
      exit 1
    fi
    cp "$saved" /etc/nginx/conf.d/xedc.conf
    printf '\n# manual-gzip-marker\n' >>/etc/nginx/conf.d/gzip.conf
    rm -f /var/lib/xedc-recovery/steps/services.done \
      /var/lib/xedc-recovery/steps/drush.done \
      /var/lib/xedc-recovery/steps/domain.done \
      /var/lib/xedc-recovery/steps/https.done \
      /var/lib/xedc-recovery/steps/selfcheck.done \
      /var/lib/xedc-recovery/steps/workers.done
    XEDC_NO_TMUX=1 bash /opt/xedc-recovery/restore.sh --resume --from "local:${file}" --domain test1.local --email admin@test1.local --https self --www no --yes
    grep -q 'manual-gzip-marker' /etc/nginx/conf.d/gzip.conf
    [[ "$(stat -c '%a %U %G' /etc/redis/xedc.conf)" == "640 redis redis" ]]
    redis-cli ping | grep -q PONG
    rm -f "$saved"
    echo "XEDC_STAGE redisresume PASS"
    ;;
  certs)
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/domain.sh
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/certbot.sh
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/nginx.sh
    export XEDC_LOG_FILE=/var/log/xedc-restore.log
    export DOMAIN=tlte.top OLD_DOMAIN=gitxx.xyz EMAIL=gitxx@gmail.com
    export HTTPS=auto XEDC_HTTPS_MODE=auto WWW=no
    export MATOMO_DOMAIN=shop.tlte.top OLD_MATOMO=shop.gitxx.xyz
    MANUAL=/tmp/xedc-manual.txt
    : >"$MANUAL"
    note_manual() { printf '%s\n' "$*" >>"$MANUAL"; }
    nginx -t >/dev/null
    cat >/etc/nginx/conf.d/shop.conf <<'EOF'
server {
    server_name shop.gitxx.xyz;
    location / { proxy_pass http://127.0.0.1:8088; }
    listen 443 ssl;
    ssl_certificate /etc/letsencrypt/live/shop.gitxx.xyz/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/shop.gitxx.xyz/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
}
server {
    if ($host = shop.gitxx.xyz) {
        return 301 https://$host$request_uri;
    } # managed by Certbot
    listen 80;
    listen [::]:80;
    server_name shop.gitxx.xyz;
    return 404; # managed by Certbot
}
EOF
    rm -rf /etc/letsencrypt/live/shop.gitxx.xyz /etc/letsencrypt/live/shop.tlte.top /etc/letsencrypt/live/tlte.top
    mkdir -p /etc/letsencrypt/live/shop.gitxx.xyz
    openssl req -x509 -nodes -days 2 -newkey rsa:2048 \
      -keyout /etc/letsencrypt/live/shop.gitxx.xyz/privkey.pem \
      -out /etc/letsencrypt/live/shop.gitxx.xyz/fullchain.pem \
      -subj /CN=shop.gitxx.xyz >/dev/null 2>&1
    rm -f /etc/letsencrypt/options-ssl-nginx.conf
    set +e
    miss_include=$(nginx_test 2>&1)
    miss_rc=$?
    set -e
    [[ "$miss_rc" != 0 ]]
    printf '%s\n' "$miss_include" | grep -q 'nginx 配置检测失败'
    printf '%s\n' "$miss_include" | grep -q 'options-ssl-nginx.conf'
    certbot_ensure_snippets
    [[ -s /etc/letsencrypt/options-ssl-nginx.conf ]]
    [[ -s /etc/letsencrypt/ssl-dhparams.pem ]]
    rm -rf /etc/letsencrypt/live/shop.gitxx.xyz
    set +e
    miss_cert=$(nginx_test 2>&1)
    miss_rc=$?
    set -e
    [[ "$miss_rc" != 0 ]]
    printf '%s\n' "$miss_cert" | grep -q 'fullchain.pem'
    printf '%s\n' 'server { server_name x.gitxx.xyz; }' >/etc/nginx/conf.d/matomo.conf
    domain_apply_configs
    [[ ! -f /etc/nginx/conf.d/matomo.conf ]]
    grep -q 'server_name shop.tlte.top;' /etc/nginx/conf.d/shop.conf
    grep -q '/etc/letsencrypt/live/shop.tlte.top/fullchain.pem' /etc/nginx/conf.d/shop.conf
    if grep -q 'gitxx.xyz' /etc/nginx/conf.d/shop.conf; then
      echo "替换后 nginx 仍有旧域名"
      exit 1
    fi
    nginx_prepare_http
    nginx_ensure_certs
    grep -q 'acme-challenge' /etc/nginx/conf.d/shop.conf
    if grep -q 'if ($host' /etc/nginx/conf.d/shop.conf; then
      echo "Matomo 的 80 端口仍会跳走挑战文件"
      exit 1
    fi
    [[ -f /etc/letsencrypt/live/shop.tlte.top/.selfsigned ]]
    export XEDC_DOMAIN=tlte.top XEDC_WWW=no XEDC_WEB_ROOT=/var/www/drupal/web
    nginx_configure
    nginx_ensure_certs
    [[ -f /etc/letsencrypt/live/tlte.top/.selfsigned ]]
    nginx_test
    install -d /tmp/fakebin
    cat >/tmp/fakebin/dig <<'EOF'
#!/bin/bash
if [[ "$*" == *NS* ]]; then
  echo ns1.example.net.
  exit 0
fi
if [[ -f /tmp/dns-hit ]]; then
  hostname -I | awk '{print $1}'
  exit 0
fi
echo 203.0.113.10
EOF
    cat >/tmp/fakebin/certbot <<'EOF'
#!/bin/bash
printf '%s\n' "$*" >>/tmp/certbot-calls
domain=""
prev=""
for arg in "$@"; do
  if [[ "$prev" == "-d" ]]; then
    domain=$arg
    break
  fi
  prev=$arg
done
if [[ -f /tmp/fail-shop && "$domain" == "shop.tlte.top" ]]; then
  exit 1
fi
dir="/etc/letsencrypt/live/${domain}"
mkdir -p "$dir" /tmp/xedc-ca
if [[ ! -s /tmp/xedc-ca/ca.key ]]; then
  openssl req -x509 -nodes -newkey rsa:2048 -keyout /tmp/xedc-ca/ca.key -out /tmp/xedc-ca/ca.crt -subj /CN=FakeCA -days 30 >/dev/null 2>&1
fi
openssl req -nodes -newkey rsa:2048 -keyout "${dir}/privkey.pem" -out /tmp/xedc-ca/req.csr -subj "/CN=${domain}" >/dev/null 2>&1
openssl x509 -req -in /tmp/xedc-ca/req.csr -CA /tmp/xedc-ca/ca.crt -CAkey /tmp/xedc-ca/ca.key -CAcreateserial -out "${dir}/fullchain.pem" -days 30 >/dev/null 2>&1
rm -f "${dir}/.selfsigned"
chmod 644 "${dir}/fullchain.pem"
chmod 640 "${dir}/privkey.pem"
exit 0
EOF
    chmod 755 /tmp/fakebin/dig /tmp/fakebin/certbot
    export PATH="/tmp/fakebin:${PATH}"
    hash -r
    : >/tmp/certbot-calls
    rm -f /tmp/dns-hit /tmp/fail-shop
    certbot_upgrade_domain tlte.top main
    certbot_upgrade_domain shop.tlte.top matomo
    if [[ -s /tmp/certbot-calls ]]; then
      echo "DNS 未指向本机时不应签发"
      cat /tmp/certbot-calls
      exit 1
    fi
    [[ -f /etc/letsencrypt/live/tlte.top/.selfsigned ]]
    [[ -f /etc/letsencrypt/live/shop.tlte.top/.selfsigned ]]
    grep -q 'tlte.top 目前是临时自签证书' "$MANUAL"
    grep -q 'shop.tlte.top 目前是临时自签证书' "$MANUAL"
    grep -q 'certbot certonly --webroot' "$MANUAL"
    nginx_test
    touch /tmp/dns-hit
    : >/tmp/certbot-calls
    certbot_upgrade_domain tlte.top main
    certbot_upgrade_domain shop.tlte.top matomo
    grep -q -- '-d tlte.top' /tmp/certbot-calls
    grep -q -- '-d shop.tlte.top' /tmp/certbot-calls
    certbot_cert_is_real tlte.top
    certbot_cert_is_real shop.tlte.top
    if [[ -f /etc/letsencrypt/live/tlte.top/.selfsigned || -f /etc/letsencrypt/live/shop.tlte.top/.selfsigned ]]; then
      echo "正式证书仍带着自签标记"
      exit 1
    fi
    calls=$(wc -l </tmp/certbot-calls)
    certbot_upgrade_domain tlte.top main
    certbot_upgrade_domain shop.tlte.top matomo
    [[ "$(wc -l </tmp/certbot-calls)" == "$calls" ]]
    nginx_test
    rm -rf /etc/letsencrypt/live/shop.tlte.top
    certbot_selfsign shop.tlte.top
    touch /tmp/fail-shop
    before_calls=$(wc -l </tmp/certbot-calls)
    set +e
    certbot_upgrade_domain shop.tlte.top matomo
    matomo_rc=$?
    set -e
    [[ "$matomo_rc" == 0 ]]
    [[ "$(wc -l </tmp/certbot-calls)" -gt "$before_calls" ]]
    [[ -f /etc/letsencrypt/live/shop.tlte.top/.selfsigned ]]
    certbot_cert_is_real tlte.top
    nginx_test
    cat >/etc/nginx/conf.d/shop.conf <<'EOF'
server {
    server_name shop.gitxx.xyz;
    listen 443 ssl;
    ssl_certificate /etc/letsencrypt/live/shop.gitxx.xyz/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/shop.gitxx.xyz/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
}
EOF
    rm -rf /etc/letsencrypt/live/shop.gitxx.xyz
    printf '%s\n' 'server { server_name x.gitxx.xyz; }' >/etc/nginx/conf.d/matomo.conf
    domain_apply_configs
    [[ ! -f /etc/nginx/conf.d/matomo.conf ]]
    nginx_prepare_http
    nginx_ensure_certs
    grep -q 'server_name shop.tlte.top;' /etc/nginx/conf.d/shop.conf
    if grep -q 'gitxx.xyz' /etc/nginx/conf.d/shop.conf; then
      echo "--resume 重新复制后又变回旧域名"
      exit 1
    fi
    nginx_test
    export XEDC_HTTPS_MODE=self
    : >/tmp/certbot-calls
    certbot_upgrade_domain tlte.top main
    [[ ! -s /tmp/certbot-calls ]]
    printf '%s\n' 'server { listen 80; server_name broken.example; this is not valid }' >/etc/nginx/conf.d/broken.conf
    nginx_quarantine_sites
    [[ -f /etc/nginx/disabled/broken.conf ]]
    nginx -t
    if grep -R 'gitxx.xyz' /etc/nginx/conf.d /etc/nginx/sites-enabled 2>/dev/null; then
      echo "域名变更后仍有旧域名"
      exit 1
    fi
    echo "XEDC_STAGE certs PASS"
    ;;
  matomo)
    start_stack
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/system.sh
    rm -f /var/lib/xedc-recovery/enable-units /var/lib/xedc-recovery/deferred-units
    unitdir=$(mktemp -d)
    printf '%s\n' '[Service]' 'ExecStart=/bin/true' >"$unitdir/xedc-push-gmliy.service"
    printf '%s\n' '[Service]' 'ExecStart=/bin/true' >"$unitdir/noinstall.service"
    printf '%s\n' '[Service]' 'ExecStart=/bin/true' '[Install]' 'WantedBy=multi-user.target' >"$unitdir/xedc-import-worker.service"
    system_restore_unit "$unitdir/xedc-push-gmliy.service"
    system_restore_unit "$unitdir/noinstall.service"
    system_restore_unit "$unitdir/xedc-import-worker.service"
    [[ ! -f /etc/systemd/system/xedc-push-gmliy.service ]]
    [[ -f /etc/systemd/system/noinstall.service ]]
    if [[ -f /var/lib/xedc-recovery/enable-units ]] && grep -q 'noinstall.service' /var/lib/xedc-recovery/enable-units; then
      echo "没有 [Install] 的单元被 enable 了"
      exit 1
    fi
    grep -q 'xedc-import-worker.service' /var/lib/xedc-recovery/deferred-units
    if [[ -f /var/lib/xedc-recovery/enable-units ]] && grep -q 'xedc-import-worker.service' /var/lib/xedc-recovery/enable-units; then
      echo "worker 不应在自检前 enable"
      exit 1
    fi
    rm -f /etc/systemd/system/noinstall.service /etc/systemd/system/xedc-import-worker.service
    rm -f /var/lib/xedc-recovery/enable-units /var/lib/xedc-recovery/deferred-units
    mkdir -p /opt/matomo /etc/nginx/conf.d
    printf '%s\n' 'services: { web: { image: matomo:5-apache } }' >/opt/matomo/compose.yaml
    printf '%s\n' 'server { server_name x.gitxx.xyz; }' >/etc/nginx/conf.d/matomo.conf
    clean_out=$(bash /opt/xedc-recovery/restore.sh --clean-excluded 2>&1 || true)
    printf '%s\n' "$clean_out" | grep -q '/opt/matomo'
    [[ -d /opt/matomo ]]
    [[ -f /etc/nginx/conf.d/matomo.conf ]]
    bash /opt/xedc-recovery/restore.sh --clean-excluded --yes
    [[ ! -d /opt/matomo ]]
    [[ ! -f /etc/nginx/conf.d/matomo.conf ]]
    rule=$(mktemp)
    cp /opt/xedc-recovery/exclude.list "$rule"
    printf '\n%s\n' shoptest >>"$rule"
    export XEDC_EXCLUDE_FILE=$rule
    sys=$(mktemp -d)
    mkdir -p "$sys/nginx/conf.d"
    printf '%s\n' 'server { server_name shoptest.example; listen 127.0.0.1:9; }' >"$sys/nginx/conf.d/shoptest.conf"
    printf '%s\n' 'server { server_name ok.example; listen 127.0.0.1:9; }' >"$sys/nginx/conf.d/ok.conf"
    system_restore "$sys"
    [[ ! -f /etc/nginx/conf.d/shoptest.conf ]]
    [[ -f /etc/nginx/conf.d/ok.conf ]]
    rm -f /etc/nginx/conf.d/ok.conf
    unset XEDC_EXCLUDE_FILE
    file=$(archive_path)
    [[ -n "$file" ]]
    work=$(mktemp -d)
    zstd -dc "$file" | tar -xf - -C "$work"
    fix=/opt/xedc-recovery/tests/fixture/site
    mkdir -p "$work/code/config/sync" "$work/code/web/modules/contrib/matomo" "$work/code/vendor/drupal/matomo" \
      "$work/system/nginx/conf.d" "$work/system/docker/matomo" "$work/system/systemd"
    cp "$fix/config/sync/core.extension.yml" "$fix/config/sync/matomo.settings.yml" \
      "$fix/config/sync/block.block.matomo_script.yml" "$fix/config/sync/views.settings.yml" \
      "$work/code/config/sync/"
    cat >"$work/code/composer.json" <<'EOF'
{
  "name": "xedc/fixture-site",
  "require": {
    "php": ">=8.1",
    "drupal/matomo": "*",
    "drupal/matomo_reports": "*"
  },
  "repositories": [
    {"type": "path", "url": "vendor/drupal/matomo", "options": {"symlink": false}},
    {"type": "path", "url": "vendor/drupal/matomo_reports", "options": {"symlink": false}}
  ],
  "config": {"allow-plugins": true}
}
EOF
    cp "$fix/web/modules/contrib/matomo/matomo.info.yml" "$work/code/web/modules/contrib/matomo/"
    mkdir -p "$work/code/web/themes/custom/xedc/templates"
    cp "$fix/web/themes/custom/xedc/templates/matomo-hardcode.html.twig" "$work/code/web/themes/custom/xedc/templates/"
    mkdir -p "$work/code/vendor/drupal/matomo" "$work/code/vendor/drupal/matomo_reports"
    printf '%s\n' '{"name":"drupal/matomo","version":"1.0.0"}' >"$work/code/vendor/drupal/matomo/composer.json"
    printf '%s\n' '{"name":"drupal/matomo_reports","version":"1.0.0"}' >"$work/code/vendor/drupal/matomo_reports/composer.json"
    printf '%s\n' 'server { server_name x.gitxx.xyz; listen 8088; include /etc/letsencrypt/options-ssl-nginx.conf; }' >"$work/system/nginx/conf.d/matomo.conf"
    printf '%s\n' 'services: { web: { image: matomo:5-apache } }' >"$work/system/docker/matomo/compose.yaml"
    printf '%s\n' '[Service]' 'ExecStart=/bin/true' >"$work/system/systemd/matomo.service"
    sqlz="$work/db/site.sql.zst"
    zstd -dc "$sqlz" >"$work/db/site.sql"
    printf '%s\n' "INSERT INTO page_content (id, body, packed) VALUES (2, '<script src=\"https://x.gitxx.xyz/matomo.js\"></script>', 'matomo');" >>"$work/db/site.sql"
    zstd -f -3 -o "$sqlz" "$work/db/site.sql"
    rm -f "$work/db/site.sql"
    rm -f "$work/code/composer.lock"
    COMPOSER_DISABLE_NETWORK=1 composer update --no-install --no-interaction --working-dir="$work/code"
    old=/tmp/old-matomo.tar.zst
    tar -C "$work" -cf - . | zstd -3 -o "$old"
    export COMPOSER_DISABLE_NETWORK=1
    XEDC_NO_TMUX=1 bash /opt/xedc-recovery/restore.sh --force --from "local:${old}" --domain test1.local --email admin@test1.local --https self --www no --yes
    must_absent() {
      if [[ -e "$1" ]]; then
        echo "不应存在 $1"
        exit 1
      fi
    }
    must_absent /etc/nginx/conf.d/matomo.conf
    must_absent /opt/matomo
    if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
      if docker ps -a --format '{{.Names}}' | grep -i matomo; then echo "仍有 matomo 容器"; exit 1; fi
      if docker volume ls -q | grep -i matomo; then echo "仍有 matomo 卷"; exit 1; fi
      if docker network ls --format '{{.Name}}' | grep -i matomo; then echo "仍有 matomo 网络"; exit 1; fi
    fi
    if /var/www/drupal/bin/drush -r /var/www/drupal pm:list --status=enabled | grep -E 'matomo.*Enabled'; then
      echo "模块仍启用"
      exit 1
    fi
    if grep -RIn 'matomo' /var/www/drupal/config/sync /var/www/drupal/composer.json; then
      echo "配置或 composer 仍有 Matomo"
      exit 1
    fi
    must_absent /var/www/drupal/vendor/drupal/matomo
    must_absent /var/www/drupal/vendor/drupal/matomo_reports
    must_absent /var/www/drupal/web/modules/contrib/matomo
    if curl -k -s --resolve test1.local:443:127.0.0.1 https://test1.local/ | grep -Ei 'matomo\.js|piwik\.js'; then
      echo "首页仍有跟踪脚本"
      exit 1
    fi
    if sql --protocol=socket -u root -N -e "SELECT body FROM page_content" xedc | grep -Ei 'matomo|piwik'; then
      echo "数据库仍有跟踪脚本"
      exit 1
    fi
    grep -q 'matomo.js' /var/www/drupal/web/themes/custom/xedc/templates/matomo-hardcode.html.twig
    nginx -t
    XEDC_NO_TMUX=1 bash /opt/xedc-recovery/restore.sh --force --keep-matomo-module --from "local:${old}" --domain test1.local --email admin@test1.local --https self --www no --yes
    grep -q 'drupal/matomo' /var/www/drupal/composer.json
    [[ -f /var/www/drupal/config/sync/matomo.settings.yml ]]
    [[ ! -f /etc/nginx/conf.d/matomo.conf ]]
    [[ ! -d /opt/matomo ]]
    mkdir -p "$work/code/web/modules/custom/xedc_stats"
    printf '%s\n' 'name: Stats' 'type: module' 'dependencies:' '  - matomo' >"$work/code/web/modules/custom/xedc_stats/xedc_stats.info.yml"
    dep=/tmp/dep-matomo.tar.zst
    tar -C "$work" -cf - . | zstd -3 -o "$dep"
    rm -f /var/lib/xedc-recovery/failures.txt
    XEDC_NO_TMUX=1 bash /opt/xedc-recovery/restore.sh --force --from "local:${dep}" --domain test1.local --email admin@test1.local --https self --www no --yes
    grep -E '依赖|卸载模块' /var/lib/xedc-recovery/failures.txt
    echo "XEDC_STAGE matomo PASS"
    ;;
  junkrestore)
    prepare_fixture
    XEDC_PHP_VERSION=8.5 bash /opt/xedc-recovery/backup.sh --target local --keep-local 3
    file=$(archive_path)
    [[ -n "$file" ]] || { echo "没有基准备份"; exit 1; }
    junk=$(mktemp -d -p /var/lib/xedc-recovery/work)
    zstd -dc "$file" | tar -C "$junk" -xf -
    mkdir -p "$junk/system/docker/volumes" "$junk/code/docs-archive"
    printf 'junk' >"$junk/system/docker/volumes/xedc-cache-npm.tar.zst"
    printf '%s\n' 'server { server_name s.gitxx.xyz; }' >"$junk/system/nginx/conf.d/s.gitxx.xyz.conf"
    printf 'x' >"$junk/code/docs-archive/junk.md"
    printf 'x' >"$junk/code/OLD_REPORT.md"
    oldjunk=$(mktemp -p /var/lib/xedc-recovery/work --suffix=.tar.zst)
    tar -C "$junk" -cf - . | zstd -3 -o "$oldjunk"
    rm -rf "$junk"
    XEDC_NO_TMUX=1 bash /opt/xedc-recovery/restore.sh --from "local:${oldjunk}" --domain test1.local --email admin@test1.local --https self --www no --yes --force
    [[ ! -f /etc/nginx/conf.d/s.gitxx.xyz.conf ]]
    [[ ! -d /var/www/drupal/docs-archive ]]
    echo "XEDC_STAGE junkrestore PASS"
    ;;
  disk)
    apt-get update -y
    apt-get install -y --no-install-recommends zstd tar curl ca-certificates
    file=""
    if [[ "${XEDC_DISK_LITE:-0}" == 1 ]]; then
      mkdir -p /var/lib/xedc-recovery/work /run/xedc
      chmod 700 /var/lib/xedc-recovery/work /run/xedc
      file=$(mktemp -p /var/lib/xedc-recovery/work lite.XXXXXX)
      file="${file}.tar.zst"
      mkdir -p /tmp/xedc-lite/{code/web/sites/default,db}
      printf '{"backup":{"archive_bytes":12000,"unpacked_bytes":80000,"database_zst_bytes":2000}}' >/tmp/xedc-lite/manifest.json
      cp /opt/xedc-recovery/tests/fixture/site/web/sites/default/settings.php /tmp/xedc-lite/code/web/sites/default/
      printf 'SELECT 1;\n' | zstd -3 -f -o /tmp/xedc-lite/db/site.sql.zst
      tar -C /tmp/xedc-lite -cf - . | zstd -3 -f -o "$file"
    else
      apt-get install -y --no-install-recommends mariadb-server mariadb-client php-cli rsync gzip
      prepare_fixture
      XEDC_PHP_VERSION=8.5 bash /opt/xedc-recovery/backup.sh --target local --keep-local 3
      file=$(archive_path)
    fi
    [[ -n "$file" && -f "$file" ]] || { echo "没有备份包"; exit 1; }
    export ROOT=/opt/xedc-recovery
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/common.sh
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/disk.sh
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/db.sh
    xedc_init_runtime_dirs
    small=$(mktemp -d -p /var/lib/xedc-recovery/work)
    if ! mount -t tmpfs -o size=32m tmpfs "$small" 2>/dev/null; then
      echo "无法挂载测试 tmpfs（需真实服务器验证）"
      exit 0
    fi
    manf=$(mktemp -p /var/lib/xedc-recovery/work)
    printf '%s\n' '{"backup":{"archive_bytes":120000000,"unpacked_bytes":400000000,"database_zst_bytes":30000000}}' >"$manf"
    set +e
    ( xedc_disk_precheck_restore "$file" "$manf" "$small" ) 2>/tmp/xedc-precheck-disk.err
    prc=$?
    set -e
    umount "$small" || true
    rmdir "$small" 2>/dev/null || true
    if [[ "$prc" == 0 ]]; then
      echo "小工作目录预检应失败"
      exit 1
    fi
    grep -q '磁盘空间不足' /tmp/xedc-precheck-disk.err
    rm -f /tmp/xedc-precheck-disk.err "$manf"
    tmp_before=$(du -sk /tmp 2>/dev/null | awk '{print $1}')
    unpack=$(xedc_work_mktemp_d extract.XXXXXX)
    if ! xedc_archive_extract_manifest "$file" "$unpack"; then
      echo "无法解压 manifest 做 /tmp 占用检测"
      exit 1
    fi
    tmp_after=$(du -sk /tmp 2>/dev/null | awk '{print $1}')
    if (( tmp_after - tmp_before >= 2048 )); then
      echo "/tmp 解压期间增长过大: $((tmp_after - tmp_before))KB"
      exit 1
    fi
    rm -rf "$unpack"
    set +o pipefail
    sql_sz=$(zstd -dc "$file" | tar -xO ./db/site.sql.zst 2>/dev/null | wc -c | tr -d ' ')
    set -o pipefail
    if [[ ! "$sql_sz" =~ ^[0-9]+$ ]] || (( sql_sz < 1 )); then
      echo "无法流式读取 db/site.sql.zst（${sql_sz:-空}）"
      exit 1
    fi
    stream_copy=$(mktemp -p /var/lib/xedc-recovery/work stream.XXXXXX)
    zstd -dc "$file" | tar -xO ./db/site.sql.zst >"$stream_copy"
    if [[ "$(wc -c <"$stream_copy")" != "$sql_sz" ]]; then
      echo "流式 tarball 成员大小不一致"
      exit 1
    fi
    rm -f "$stream_copy"
    find /var/lib/xedc-recovery/work -name 'site.sql.zst' 2>/dev/null | grep -q . && { echo "不应落盘 site.sql.zst"; exit 1; }
    echo "XEDC_STAGE disk PASS"
    ;;
  workresume)
    prepare_fixture
    file=$(archive_path)
    [[ -n "$file" ]] || { echo "没有备份"; exit 1; }
    work=$(mktemp -d -p /var/lib/xedc-recovery/work restore.XXXXXX)
    printf '%s\n' "$work" >/var/lib/xedc-recovery/work.path
    printf '%s\n' "$work" >/var/lib/xedc-recovery/work.incomplete
    mkdir -p "$work/code"
    dd if=/dev/zero of="$work/code/partial.bin" bs=1M count=5 2>/dev/null
    rm -f /var/lib/xedc-recovery/steps/database.done
    XEDC_NO_TMUX=1 bash /opt/xedc-recovery/restore.sh --resume --from "local:${file}" --domain test1.local --email admin@test1.local --https self --www no --yes --force
    [[ ! -f "$work/code/partial.bin" ]] || [[ ! -d "$work" ]]
    echo "XEDC_STAGE workresume PASS"
    ;;
  workdir)
    # shellcheck source=/dev/null
    source /opt/xedc-recovery/lib/common.sh
    xedc_init_runtime_dirs
    [[ "$XEDC_WORKDIR" == */xedc-recovery/work* ]]
    [[ "$TMPDIR" == "$XEDC_WORKDIR" ]]
    [[ -d "$XEDC_RUN_DIR" && "$(stat -c '%a' "$XEDC_RUN_DIR")" == 700 ]]
    echo "XEDC_STAGE workdir PASS"
    ;;
  *)
    echo "未知模式 $mode" >&2
    exit 2
    ;;
esac
