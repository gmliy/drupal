#!/usr/bin/env bash
# shellcheck shell=bash
# 从仓库 secure/ 安装 age 私钥和 R2 配置。不打印密钥内容。

xedc_secure_dir() {
  printf '%s\n' "${XEDC_SECURE_DIR:-${XEDC_HOME}/secure}"
}

xedc_secure_note_age() {
  local file=$1 line
  line=$(awk '/^AGE-SECRET-KEY-1/{print; exit}' "$file" || true)
  [[ -n $line ]] && xedc_note_secret "$line"
}

xedc_secure_note_env() {
  local file=$1 key value
  while IFS= read -r line; do
    [[ $line == *=* && $line != \#* ]] || continue
    key=${line%%=*}
    value=${line#*=}
    value=${value%\"}
    value=${value#\"}
    [[ ${#value} -ge 6 ]] && xedc_note_secret "$value"
  done <"$file"
}

xedc_secure_install_file() {
  local src=$1 dest=$2 label=$3
  [[ -f $src ]] || xedc_die "找不到${label}。恢复已停止。"
  [[ ! -L $src && -f $src ]] || xedc_die "${label}必须是普通文件。"
  install -d -m 700 "$(dirname "$dest")"
  if [[ -f $dest ]] && ! cmp -s "$src" "$dest"; then
    xedc_die "本机${label}与仓库中的文件不一致。没有覆盖现有文件。"
  fi
  if [[ ! -f $dest ]]; then
    install -m 600 "$src" "$dest"
  fi
  chmod 600 "$src" "$dest" 2>/dev/null || chmod 600 "$dest"
}

xedc_secure_install_age() {
  local src dest
  src="$(xedc_secure_dir)/age.key"
  dest=${XEDC_AGE_KEY:-/root/.config/xedc-recovery/age.key}
  xedc_secure_install_file "$src" "$dest" "age 私钥"
  if ! awk '/^AGE-SECRET-KEY-1/ {found=1} END {exit !found}' "$dest"; then
    xedc_die "age 私钥格式不正确。恢复已停止。"
  fi
  xedc_secure_note_age "$dest"
  export XEDC_AGE_KEY=$dest
}

xedc_secure_install_r2() {
  local src dest
  src="$(xedc_secure_dir)/r2.env"
  dest=${XEDC_R2_ENV:-/root/.config/xedc-recovery/r2.env}
  xedc_secure_install_file "$src" "$dest" "R2 配置"
  xedc_secure_note_env "$dest"
  export XEDC_R2_ENV=$dest
}

xedc_secure_decrypt_check() {
  local enc=$1
  local out
  [[ -f $enc ]] || xedc_die "找不到 secrets.enc。"
  [[ -n ${XEDC_AGE_KEY:-} && -f $XEDC_AGE_KEY ]] || xedc_die "age 私钥还没有就绪。"
  out=$(mktemp)
  chmod 600 "$out"
  if ! age -d -i "$XEDC_AGE_KEY" -o "$out" "$enc"; then
    shred -u "$out" 2>/dev/null || rm -f "$out"
    xedc_die "age 解密失败。已停止，不会继续恢复。"
  fi
  if ! tar -tf "$out" | sed 's#^\./##' | grep -qx 'db.json'; then
    shred -u "$out" 2>/dev/null || rm -f "$out"
    xedc_die "解密结果里没有 db.json。已停止。"
  fi
  shred -u "$out" 2>/dev/null || rm -f "$out"
  xedc_log INFO "age 解密检查通过。明文已删除。"
}
