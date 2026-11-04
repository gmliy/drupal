#!/usr/bin/env bash
# shellcheck shell=bash

XEDC_CREDENTIALS_FILE=${XEDC_CREDENTIALS_FILE:-/root/.drupal-credentials}

xedc_credentials_write() {
  local url=$1 admin=$2 database=$3 user=$4 passfile=$5 host=$6 port=$7
  local password
  password=$(cat "$passfile")
  xedc_note_secret "$password"
  umask 077
  cat >"$XEDC_CREDENTIALS_FILE" <<EOF
url=${url}
admin_user=${admin}
admin_password=与原站一致
database=${database}
database_user=${user}
database_password=${password}
database_host=${host}
database_port=${port}
EOF
  chmod 600 "$XEDC_CREDENTIALS_FILE"
  xedc_log INFO "凭据已写入 ${XEDC_CREDENTIALS_FILE}。管理员密码与原站一致，没有打印。"
}

xedc_credentials_show() {
  xedc_require_root
  [[ -f $XEDC_CREDENTIALS_FILE ]] || xedc_die "还没有凭据文件。"
  cat "$XEDC_CREDENTIALS_FILE"
}

xedc_reset_admin() {
  local root=$1 uri=$2
  xedc_require_root
  local answer=""
  if [[ ${XEDC_ASSUME_YES:-0} != 1 ]]; then
    printf '%s' "确认重置 uid 1 的密码？输入 yes: " >&2
    read -r answer
    [[ $answer == yes ]] || xedc_die "已取消重置。"
  fi
  local passfile password
  passfile=$(xedc_mktemp)
  xedc_random_password >"$passfile"
  password=$(cat "$passfile")
  xedc_note_secret "$password"
  xedc_drush "$root" --uri="$uri" php:eval "\$pass = trim((string) file_get_contents('${passfile}')); \$user = \\Drupal\\user\\Entity\\User::load(1); \$user->setPassword(\$pass); \$user->save();"
  python3 - "$XEDC_CREDENTIALS_FILE" "$passfile" <<'PY'
import os, sys
dest, passfile = sys.argv[1:]
password = open(passfile, encoding="utf-8").read().strip()
line = "admin_password=" + password
text = open(dest, encoding="utf-8").read() if os.path.isfile(dest) else ""
if any(item.startswith("admin_password=") for item in text.splitlines()):
    lines = [line if item.startswith("admin_password=") else item for item in text.splitlines()]
    text = "\n".join(lines) + "\n"
else:
    text = (text if text.endswith("\n") or text == "" else text + "\n") + line + "\n"
fd = os.open(dest, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, "w", encoding="utf-8") as handle:
    handle.write(text)
PY
  rm -f "$passfile"
  xedc_log INFO "管理员密码已写入 ${XEDC_CREDENTIALS_FILE}，没有在终端显示。"
}

xedc_login_link() {
  local root=$1 uri=$2
  xedc_require_root
  local link
  link=$(xedc_drush "$root" --uri="$uri" user:login --uid=1 --no-browser)
  printf '%s\n' "$link"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 restore.sh 运行。" >&2
  exit 1
fi
