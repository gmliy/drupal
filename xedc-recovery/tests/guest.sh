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
    XEDC_PHP_VERSION=8.5 bash /opt/xedc-recovery/backup.sh --target both --keep-local 7 --keep-r2 30
    if grep -E 'ERROR|NotImplemented|重试|Attempt [0-9]+/' /var/log/xedc-backup.log; then
      echo "备份日志出现 ERROR、501 或重试"
      exit 1
    fi
    mapfile -t ups < <(grep -oE 'R2 上传 [^[:space:]]+' /var/log/xedc-backup.log | awk '{print $NF}')
    [[ "${#ups[@]}" -eq 6 ]]
    [[ "${ups[0]}" == *.tar.zst && "${ups[0]}" != *.sha256 ]]
    [[ "${ups[1]}" == *.tar.zst.sha256 ]]
    [[ "${ups[2]}" == *.manifest.json ]]
    [[ "${ups[3]}" == *.complete ]]
    [[ "${ups[4]}" == bootstrap/install.sh ]]
    [[ "${ups[5]}" == bootstrap/xedc-recovery.tar.gz ]]
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
    [[ "${boot[*]}" == "install.sh xedc-recovery.tar.gz" ]]
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
    ffprobe -v error -show_entries stream=codec_name -of csv=p=0 /tmp/transcoded.mp4 | grep -q h264
    chown www-data:www-data /var/www/drupal/web/sites/default/files/clip.mp4
    code=$(curl -k -s -o /dev/null -w '%{http_code}' --resolve test1.local:443:127.0.0.1 https://test1.local/video)
    [[ "$code" == "200" ]]
    code=$(curl -k -s -o /dev/null -w '%{http_code}' --resolve test1.local:443:127.0.0.1 https://test1.local/sites/default/files/clip.mp4)
    [[ "$code" == "200" ]]
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
    XEDC_FAIL_STEP=domain bash /opt/xedc-recovery/restore.sh --resume --from "local:${file}" --domain test1.local --email admin@test1.local --https self --www no --yes
    echo "XEDC_STAGE resume PASS"
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
    [[ "$prev" == "$old" ]]
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
    if compgen -G '/tmp/xedc-r2.*' >/dev/null; then
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
    if compgen -G '/tmp/xedc-r2.*' >/dev/null; then
      echo "凭据临时文件还在"
      exit 1
    fi
    echo "XEDC_STAGE prompts PASS"
    ;;
  *)
    echo "未知模式 $mode" >&2
    exit 2
    ;;
esac
