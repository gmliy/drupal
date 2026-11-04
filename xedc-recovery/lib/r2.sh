#!/usr/bin/env bash
# shellcheck shell=bash
# 可选的 Cloudflare R2 上传和下载。凭据只从 root 权限文件读取，不进 Git，不进迁移包。

xedc_r2_env_file() {
  printf '%s\n' "${XEDC_R2_ENV:-/root/.config/xedc-recovery/r2.env}"
}

xedc_r2_usage_hint() {
  cat <<'EOF'
尚未配置 Cloudflare R2。迁移包仍只在本机。

1. 在 Cloudflare 创建一个专用的私有 Bucket，不要复用网站公开文件的 Bucket，也不要打开公开访问或自定义域名。
2. 创建一个 API Token，权限只给这个 Bucket 的对象读和对象写。不要授予账户管理、用户管理或其他 Bucket 的权限。
3. 记下 Account ID、Bucket 名称、Access Key ID 和 Secret Access Key。
4. 在源服务器或新服务器执行：

   sudo install -d -m 700 /root/.config/xedc-recovery
   sudo install -m 600 /dev/null /root/.config/xedc-recovery/r2.env

5. 用编辑器写入下面几行，把占位符换成自己的值。不要把这个文件提交到 Git：

   R2_ACCOUNT_ID=账户ID
   R2_BUCKET=专用私有桶名称
   R2_ACCESS_KEY_ID=访问密钥ID
   R2_SECRET_ACCESS_KEY=访问密钥
   R2_PROVIDER=Cloudflare

6. 确认权限是 600 后，再执行上传或下载。连接测试：

   sudo xedc-recovery/download.sh --source=r2 --list

更换密钥时，覆盖 r2.env 里的两行密钥，权限仍保持 600。旧 Token 在 Cloudflare 控制台撤销。
本脚本不会自动删除远端旧对象。
EOF
}

xedc_r2_load() {
  local file mode
  file=$(xedc_r2_env_file)
  if [[ ! -f $file ]]; then
    xedc_r2_usage_hint >&2
    xedc_die "找不到 R2 配置 ${file}。"
  fi
  mode=$(stat -c %a "$file")
  [[ $mode == 600 || $mode == 400 ]] || xedc_die "R2 配置 ${file} 的权限必须是 600。"
  set -a
  # shellcheck disable=SC1090
  source "$file"
  set +a
  [[ -n ${R2_BUCKET:-} && -n ${R2_ACCESS_KEY_ID:-} && -n ${R2_SECRET_ACCESS_KEY:-} ]] || xedc_die "R2 配置缺少 Bucket 或密钥。"
  if [[ -z ${R2_ENDPOINT:-} ]]; then
    [[ -n ${R2_ACCOUNT_ID:-} ]] || xedc_die "R2 配置需要 R2_ACCOUNT_ID，或直接提供 R2_ENDPOINT。"
    R2_ENDPOINT="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com"
  fi
  R2_PROVIDER=${R2_PROVIDER:-Cloudflare}
  xedc_note_secret "$R2_ACCESS_KEY_ID"
  xedc_note_secret "$R2_SECRET_ACCESS_KEY"
  export R2_BUCKET R2_ACCESS_KEY_ID R2_SECRET_ACCESS_KEY R2_ENDPOINT R2_PROVIDER
}

xedc_r2_install_rclone() {
  if command -v rclone >/dev/null 2>&1; then
    return 0
  fi
  xedc_export_apt_env
  xedc_run apt-get update
  xedc_run apt-get install -y rclone || xedc_die "无法安装 rclone。R2 上传和下载停止。"
  command -v rclone >/dev/null 2>&1 || xedc_die "rclone 安装后仍不可用。"
}

xedc_r2_rclone_config() {
  local cfg
  cfg=$(mktemp "${XEDC_STATE_ROOT:-/root}/.rclone.XXXXXX")
  chmod 600 "$cfg"
  cat >"$cfg" <<EOF
[xedc]
type = s3
provider = ${R2_PROVIDER}
env_auth = false
access_key_id = ${R2_ACCESS_KEY_ID}
secret_access_key = ${R2_SECRET_ACCESS_KEY}
endpoint = ${R2_ENDPOINT}
force_path_style = true
acl = private
no_check_bucket = true
EOF
  XEDC_TEMPS+=("$cfg")
  printf '%s\n' "$cfg"
}

xedc_r2_object_key() {
  local archive=$1
  local base year month
  base=$(basename "$archive")
  if [[ $base =~ ^xedc-migration-([0-9]{4})([0-9]{2})[0-9]{2}T ]]; then
    year=${BASH_REMATCH[1]}
    month=${BASH_REMATCH[2]}
  else
    year=$(date -u +%Y)
    month=$(date -u +%m)
  fi
  printf 'xedc-backups/%s/%s/%s\n' "$year" "$month" "$base"
}

xedc_r2_run() {
  local cfg=$1
  shift
  RCLONE_CONFIG="$cfg" rclone "$@"
}

xedc_r2_remote_size() {
  local cfg=$1 key=$2
  local line size
  line=$(xedc_r2_run "$cfg" lsl "xedc:${R2_BUCKET}/${key}" 2>/dev/null | awk 'NR==1 {print $1}')
  size=${line:-}
  [[ $size =~ ^[0-9]+$ ]] || return 1
  printf '%s\n' "$size"
}

xedc_r2_upload_package() {
  local archive=$1
  [[ -f $archive && -f ${archive}.sha256 ]] || xedc_die "找不到要上传的迁移包或校验文件。"
  xedc_r2_load
  xedc_r2_install_rclone
  local cfg key sha_key manifest_key public_manifest log_file local_size remote_size tmp base
  cfg=$(xedc_r2_rclone_config)
  key=$(xedc_r2_object_key "$archive")
  sha_key="${key}.sha256"
  manifest_key="${key%.tar.zst}.manifest.public.json"
  public_manifest="$(dirname "$archive")/$(basename "$archive" .tar.zst).manifest.public.json"
  [[ -f $public_manifest ]] || xedc_die "缺少可上传的非敏感 manifest。"
  base=$(basename "$archive" .tar.zst)
  log_file="${XEDC_LOG_DIR:-/var/log/xedc-recovery}/r2-upload-${base}.log"
  mkdir -p "$(dirname "$log_file")"
  : >"$log_file"
  chmod 600 "$log_file"
  xedc_log INFO "开始上传 R2 对象 ${key}。密钥不写入日志。"
  if ! xedc_r2_run "$cfg" copyto "$archive" "xedc:${R2_BUCKET}/${key}" --s3-no-check-bucket >>"$log_file" 2>&1; then
    xedc_log FAIL "R2 上传命令失败。见 ${log_file}"
    return 1
  fi
  if ! xedc_r2_run "$cfg" copyto "${archive}.sha256" "xedc:${R2_BUCKET}/${sha_key}" --s3-no-check-bucket >>"$log_file" 2>&1; then
    xedc_log FAIL "R2 校验文件上传失败。"
    return 1
  fi
  if ! xedc_r2_run "$cfg" copyto "$public_manifest" "xedc:${R2_BUCKET}/${manifest_key}" --s3-no-check-bucket >>"$log_file" 2>&1; then
    xedc_log FAIL "R2 非敏感 manifest 上传失败。"
    return 1
  fi
  if ! xedc_r2_run "$cfg" lsf "xedc:${R2_BUCKET}/${key}" >>"$log_file" 2>&1; then
    xedc_log FAIL "上传后在 R2 上看不到对象 ${key}。"
    return 1
  fi
  local_size=$(stat -c %s "$archive")
  if ! remote_size=$(xedc_r2_remote_size "$cfg" "$key"); then
    xedc_log FAIL "无法读取远端对象大小。"
    return 1
  fi
  if [[ $remote_size != "$local_size" ]]; then
    xedc_log FAIL "远端大小 ${remote_size} 与本地 ${local_size} 不一致。"
    return 1
  fi
  tmp=$(mktemp -d "${XEDC_BACKUP_ROOT:-/var/backups/xedc}/.r2dl.XXXXXX")
  chmod 700 "$tmp"
  if ! xedc_r2_run "$cfg" copyto "xedc:${R2_BUCKET}/${key}" "$tmp/$(basename "$archive")" >>"$log_file" 2>&1; then
    rm -rf "$tmp"
    xedc_log FAIL "回读远端迁移包失败。"
    return 1
  fi
  if ! xedc_r2_run "$cfg" copyto "xedc:${R2_BUCKET}/${sha_key}" "$tmp/$(basename "$archive").sha256" >>"$log_file" 2>&1; then
    rm -rf "$tmp"
    xedc_log FAIL "回读远端校验文件失败。"
    return 1
  fi
  if ! (
    cd "$tmp" || exit 1
    sha256sum -c "$(basename "$archive").sha256"
  ) >>"$log_file" 2>&1; then
    rm -rf "$tmp"
    xedc_log FAIL "远端对象的 SHA-256 与校验文件不一致。"
    return 1
  fi
  local local_sum remote_sum
  local_sum=$(awk '{print $1}' "${archive}.sha256")
  remote_sum=$(sha256sum "$tmp/$(basename "$archive")" | awk '{print $1}')
  rm -rf "$tmp"
  if [[ $local_sum != "$remote_sum" ]]; then
    xedc_log FAIL "远端 SHA-256 与本地迁移包不一致。"
    return 1
  fi
  printf '%s\n' "PASS key=${key} bytes=${remote_size} sha256=${remote_sum}" >>"$log_file"
  xedc_log INFO "R2 上传已核对。对象 ${key}，大小 ${remote_size}。日志 ${log_file}"
  rm -f "$cfg"
}

xedc_r2_list() {
  xedc_r2_load
  xedc_r2_install_rclone
  local cfg
  cfg=$(xedc_r2_rclone_config)
  xedc_r2_run "$cfg" lsf "xedc:${R2_BUCKET}/xedc-backups" --recursive --include 'xedc-migration-*.tar.zst' || {
    rm -f "$cfg"
    xedc_die "列出 R2 对象失败。请检查 Bucket、权限和网络。"
  }
  rm -f "$cfg"
}

xedc_r2_resolve_key() {
  local stamp=${1:-}
  local cfg=$2
  local line
  if [[ -n $stamp ]]; then
    line=$(xedc_r2_run "$cfg" lsf "xedc:${R2_BUCKET}/xedc-backups" --recursive --include "xedc-migration-${stamp}.tar.zst" | awk 'NF {print}' | head -n 1)
  else
    line=$(xedc_r2_run "$cfg" lsf "xedc:${R2_BUCKET}/xedc-backups" --recursive --include 'xedc-migration-*.tar.zst' | awk 'NF {print}' | sort | tail -n 1)
  fi
  [[ -n $line ]] || return 1
  printf 'xedc-backups/%s\n' "$line"
}

xedc_r2_download() {
  local stamp=${1:-}
  local dest_dir=${2:-${XEDC_BACKUP_ROOT:-/var/backups/xedc}}
  xedc_r2_load
  xedc_r2_install_rclone
  local cfg key tmp base archive
  cfg=$(xedc_r2_rclone_config)
  if ! key=$(xedc_r2_resolve_key "$stamp" "$cfg"); then
    rm -f "$cfg"
    xedc_die "R2 上找不到请求的迁移包。可用 download.sh --source=r2 --list 查看。"
  fi
  base=$(basename "$key")
  mkdir -p "$dest_dir"
  chmod 700 "$dest_dir" 2>/dev/null || true
  tmp=$(mktemp -d "$dest_dir/.r2partial.XXXXXX")
  chmod 700 "$tmp"
  xedc_log INFO "从 R2 下载 ${key}。密钥不写入日志。"
  if ! xedc_r2_run "$cfg" copyto "xedc:${R2_BUCKET}/${key}" "$tmp/$base"; then
    rm -rf "$tmp"
    rm -f "$cfg"
    xedc_die "R2 下载失败，已删除不完整的临时文件。"
  fi
  if ! xedc_r2_run "$cfg" copyto "xedc:${R2_BUCKET}/${key}.sha256" "$tmp/${base}.sha256"; then
    rm -rf "$tmp"
    rm -f "$cfg"
    xedc_die "R2 校验文件下载失败，已删除不完整的临时文件。"
  fi
  local remote_size local_size
  remote_size=$(xedc_r2_remote_size "$cfg" "$key" || true)
  local_size=$(stat -c %s "$tmp/$base")
  if [[ -n $remote_size && $remote_size != "$local_size" ]]; then
    rm -rf "$tmp"
    rm -f "$cfg"
    xedc_die "下载大小 ${local_size} 与远端 ${remote_size} 不一致，已删除临时文件。"
  fi
  if ! (
    cd "$tmp" || exit 1
    sha256sum -c "${base}.sha256"
  ) >&2; then
    rm -rf "$tmp"
    rm -f "$cfg"
    xedc_die "下载文件的 SHA-256 不一致，已删除临时文件。不要用它恢复。"
  fi
  mv -f "$tmp/$base" "$dest_dir/$base"
  mv -f "$tmp/${base}.sha256" "$dest_dir/${base}.sha256"
  rm -rf "$tmp"
  rm -f "$cfg"
  chmod 600 "$dest_dir/$base" "$dest_dir/${base}.sha256"
  archive="$dest_dir/$base"
  xedc_log INFO "R2 下载并校验通过：${archive}"
  printf '%s\n' "$archive"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 backup.sh 或 download.sh 运行。" >&2
  exit 1
fi
