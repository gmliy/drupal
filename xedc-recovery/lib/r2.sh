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

   sudo xedc-recovery/upload.sh --file /var/backups/xedc/迁移包.tar.zst
   sudo xedc-recovery/download.sh --source=r2 --list

更换密钥时，覆盖 r2.env 里的两行密钥，权限仍保持 600。旧 Token 在 Cloudflare 控制台撤销。
本脚本不会自动删除远端旧对象。
EOF
}

xedc_r2_load() {
  local file mode explicit=0
  file=$(xedc_r2_env_file)
  if [[ ! -f $file ]]; then
    xedc_r2_usage_hint >&2
    xedc_die "找不到 R2 配置文件。请创建 r2.env 并设为 600。"
  fi
  if [[ -L $file ]]; then
    xedc_die "R2 配置不能是符号链接。"
  fi
  mode=$(stat -c %a "$file")
  [[ $mode == 600 || $mode == 400 ]] || xedc_die "R2 配置权限不正确，只允许 600 或 400。"
  unset R2_ACCOUNT_ID R2_BUCKET R2_ACCESS_KEY_ID R2_SECRET_ACCESS_KEY R2_ENDPOINT R2_PROVIDER
  set -a
  # shellcheck disable=SC1090
  source "$file"
  set +a
  [[ -n ${R2_ENDPOINT:-} ]] && explicit=1
  R2_PROVIDER=${R2_PROVIDER:-Cloudflare}
  xedc_r2_validate_config "$explicit"
  xedc_note_secret "$R2_ACCESS_KEY_ID"
  xedc_note_secret "$R2_SECRET_ACCESS_KEY"
  xedc_note_secret "${R2_ACCOUNT_ID:-}"
  xedc_note_secret "$R2_ENDPOINT"
  export R2_BUCKET R2_ACCESS_KEY_ID R2_SECRET_ACCESS_KEY R2_ENDPOINT R2_PROVIDER R2_ACCOUNT_ID
}

xedc_r2_validate_config() {
  local explicit=$1
  [[ -n ${R2_BUCKET:-} && -n ${R2_ACCESS_KEY_ID:-} && -n ${R2_SECRET_ACCESS_KEY:-} ]] || xedc_die "R2 配置缺少 R2_BUCKET、R2_ACCESS_KEY_ID 或 R2_SECRET_ACCESS_KEY。"
  if [[ ${R2_PROVIDER} == Cloudflare ]]; then
    [[ ${R2_ACCOUNT_ID:-} =~ ^[a-f0-9]{32}$ ]] || xedc_die "R2_ACCOUNT_ID 格式不正确。请填写 32 位账户 ID，不要填写 API Token。"
    [[ ${R2_ACCESS_KEY_ID} =~ ^[a-f0-9]{32}$ ]] || xedc_die "R2_ACCESS_KEY_ID 不是 S3 Access Key。不要填写 Cloudflare API Token。"
    [[ ${R2_SECRET_ACCESS_KEY} =~ ^[A-Fa-f0-9]{64}$ ]] || xedc_die "R2_SECRET_ACCESS_KEY 格式不正确。请填写 S3 Secret Access Key，不要填写 Cloudflare API Token。"
    [[ ${R2_BUCKET} =~ ^[a-z0-9][a-z0-9._-]{1,62}$ ]] || xedc_die "R2_BUCKET 格式不正确。"
    local endpoint="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com"
    if [[ $explicit == 1 && ${R2_ENDPOINT} != "$endpoint" ]]; then
      xedc_die "R2_ENDPOINT 与 R2_ACCOUNT_ID 不对应。"
    fi
    R2_ENDPOINT=$endpoint
    [[ $R2_ENDPOINT =~ ^https://[a-f0-9]{32}\.r2\.cloudflarestorage\.com$ ]] || xedc_die "R2_ENDPOINT 格式不正确。"
  else
    [[ -n ${R2_ENDPOINT:-} ]] || xedc_die "R2_ENDPOINT 未设置。"
    [[ $R2_ENDPOINT =~ ^https?://[A-Za-z0-9._:-]+(/.*)?$ ]] || xedc_die "R2_ENDPOINT 格式不正确。"
  fi
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

xedc_r2_rclone_bin() {
  printf '%s\n' "${XEDC_RCLONE_BIN:-rclone}"
}

xedc_r2_rclone_config() {
  local cfg mode
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
  mode=$(stat -c %a "$cfg")
  [[ $mode == 600 ]] || xedc_die "临时 rclone 配置权限不是 600。"
  XEDC_R2_CFG=$cfg
  export XEDC_R2_CFG
  XEDC_TEMPS+=("$cfg")
  if [[ -n ${XEDC_R2_LOG:-} ]]; then
    printf '临时配置权限=600\n' >>"$XEDC_R2_LOG"
  fi
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
  local bin
  bin=$(xedc_r2_rclone_bin)
  RCLONE_CONFIG="$cfg" "$bin" --contimeout 15s --timeout 2h --retries 2 "$@"
}

xedc_r2_note_log() {
  local log=$1
  shift
  [[ -n $log ]] || return 0
  xedc_redact "$*" >>"$log"
  printf '\n' >>"$log"
}

xedc_r2_classify_error() {
  local err_file=$1
  local text
  text=$(xedc_redact "$(cat "$err_file" 2>/dev/null || true)")
  if printf '%s\n' "$text" | grep -Eqi 'SignatureDoesNotMatch|InvalidAccessKeyId|AccessDenied|403 Forbidden|401 Unauthorized|Unauthorized'; then
    printf '%s\n' auth
    return 0
  fi
  if printf '%s\n' "$text" | grep -Eqi 'NoSuchBucket|bucket does not exist|Bucket not found'; then
    printf '%s\n' bucket
    return 0
  fi
  if printf '%s\n' "$text" | grep -Eqi "directory not found|object not found|404 Not Found|didn't find|not found"; then
    printf '%s\n' missing
    return 0
  fi
  printf '%s\n' network
}

xedc_r2_remote_size() {
  local cfg=$1 key=$2
  local out err rc size
  out=$(mktemp)
  err=$(mktemp)
  rc=0
  xedc_r2_run "$cfg" ls "xedc:${R2_BUCKET}/${key}" >"$out" 2>"$err" || rc=$?
  if [[ $rc -eq 0 ]]; then
    local base
    base=$(basename "$key")
    size=$(awk -v name="$base" '{
      path = $2
      for (i = 3; i <= NF; i++) path = path " " $i
      if (path == name || substr(path, length(path) - length(name)) == "/" name) print $1
    }' "$out" | awk 'NR==1 {print $1}')
    rm -f "$out" "$err"
    [[ $size =~ ^[0-9]+$ ]] || return 2
    printf '%s\n' "$size"
    return 0
  fi
  if [[ $(xedc_r2_classify_error "$err") == missing ]]; then
    rm -f "$out" "$err"
    return 2
  fi
  xedc_r2_note_log "${XEDC_R2_LOG:-}" "读取远端大小失败"
  xedc_redact "$(cat "$err")" >>"${XEDC_R2_LOG:-/dev/null}"
  rm -f "$out" "$err"
  return "$rc"
}

xedc_r2_fail() {
  local stage=$1
  shift
  local log=${XEDC_R2_LOG:-}
  if [[ -n ${XEDC_ERR_MARKER:-} ]]; then
    printf '1\n' >"$XEDC_ERR_MARKER"
  fi
  if [[ -n $log ]]; then
    printf 'result=FAIL\nstage=%s\n' "$stage" >>"$log"
  fi
  xedc_log FAIL "R2 阶段=${stage} $* 本地迁移包未修改。"
  exit 1
}

xedc_r2_upload_package() {
  local archive=$1
  local base key sha_key manifest_key public_manifest log_file cfg
  local local_size sha_size manifest_size remote_size remote_sha_size remote_manifest_size
  local tmp same=0 rc
  [[ -f $archive && ! -L $archive ]] || xedc_die "找不到要上传的迁移包。"
  [[ -f ${archive}.sha256 && ! -L ${archive}.sha256 ]] || xedc_die "找不到要上传的校验文件。"
  base=$(basename "$archive" .tar.zst)
  public_manifest="$(dirname "$archive")/${base}.manifest.public.json"
  [[ -f $public_manifest && ! -L $public_manifest ]] || xedc_die "缺少可上传的非敏感 manifest。"
  xedc_set_phase r2-upload
  if ! (
    cd "$(dirname "$archive")" || exit 1
    sha256sum -c "$(basename "$archive").sha256"
  ) >&2; then
    xedc_r2_fail local-sha256 "本地 SHA-256 不一致。"
  fi
  if ! zstd -t -q "$archive"; then
    xedc_r2_fail local-zstd "本地归档无法通过 zstd 测试。"
  fi
  xedc_r2_load
  log_file=${XEDC_R2_LOG:-${XEDC_LOG_DIR:-/var/log/xedc-recovery}/r2-upload-${base}.log}
  mkdir -p "$(dirname "$log_file")"
  touch "$log_file"
  chmod 600 "$log_file"
  export XEDC_R2_LOG=$log_file
  key=$(xedc_r2_object_key "$archive")
  sha_key="${key}.sha256"
  manifest_key="${key%.tar.zst}.manifest.public.json"
  local_size=$(stat -c %s "$archive")
  sha_size=$(stat -c %s "${archive}.sha256")
  manifest_size=$(stat -c %s "$public_manifest")
  {
    printf 'local_file=%s\n' "$(basename "$archive")"
    printf 'remote_key=%s\n' "$key"
    printf 'local_bytes=%s\n' "$local_size"
    printf 'sha_bytes=%s\n' "$sha_size"
    printf 'manifest_bytes=%s\n' "$manifest_size"
  } >>"$log_file"
  if [[ ${XEDC_DRY_RUN:-0} == 1 ]]; then
    printf 'sha256=local-pass\nmanifest=local-pass\nstage=dry-run\nresult=DRY-RUN\n' >>"$log_file"
    xedc_log INFO "dry-run: 已检查本地迁移包和 R2 配置，未连接远端，未上传。"
    return 0
  fi
  xedc_r2_install_rclone
  cfg=$(xedc_r2_rclone_config)
  local err
  err=$(mktemp)
  chmod 600 "$err"
  rc=0
  xedc_r2_remote_size "$cfg" "$key" >"$err.size" 2>"$err" || rc=$?
  remote_size=$(cat "$err.size" 2>/dev/null || true)
  if [[ $rc -ne 0 && $rc -ne 2 ]]; then
    xedc_r2_note_log "$log_file" "$(cat "$err")"
    rm -f "$err" "$err.size"
    xedc_r2_fail remote-stat "上传前无法确认远端对象是否已存在。"
  fi
  if [[ $rc -eq 0 ]]; then
    local tmpcmp
    tmpcmp=$(mktemp -d)
    chmod 700 "$tmpcmp"
    rc=0
    xedc_r2_run "$cfg" copyto "xedc:${R2_BUCKET}/${sha_key}" "$tmpcmp/remote.sha256" >"$err" 2>&1 || rc=$?
    if [[ $rc -ne 0 ]]; then
      rm -rf "$tmpcmp"
      rm -f "$err" "$err.size"
      xedc_r2_fail overwrite-protected "远端已有迁移包，但校验文件不一致或不存在。不会覆盖。"
    fi
    rc=0
    xedc_r2_run "$cfg" copyto "xedc:${R2_BUCKET}/${manifest_key}" "$tmpcmp/remote.manifest.json" >"$err" 2>&1 || rc=$?
    remote_sha_size=$(xedc_r2_remote_size "$cfg" "$sha_key" || true)
    remote_manifest_size=$(xedc_r2_remote_size "$cfg" "$manifest_key" || true)
    if [[ $rc -ne 0 ]] || ! cmp -s "${archive}.sha256" "$tmpcmp/remote.sha256" || ! cmp -s "$public_manifest" "$tmpcmp/remote.manifest.json" || [[ $remote_size != "$local_size" || $remote_sha_size != "$sha_size" || $remote_manifest_size != "$manifest_size" ]]; then
      rm -rf "$tmpcmp"
      rm -f "$err" "$err.size"
      xedc_r2_fail overwrite-protected "远端已有同名对象且内容不同。不会覆盖或删除。"
    fi
    rm -rf "$tmpcmp"
    same=1
    printf 'manifest=already-present\n' >>"$log_file"
    xedc_log INFO "远端已有相同的三个对象，未覆盖。对象 ${key}"
  fi
  if [[ $same == 0 ]]; then
    rc=0
    xedc_r2_run "$cfg" copyto "$archive" "xedc:${R2_BUCKET}/${key}" --s3-no-check-bucket >"$err" 2>&1 || rc=$?
    if [[ $rc -ne 0 ]]; then
      xedc_r2_note_log "$log_file" "$(cat "$err")"
      rm -f "$err" "$err.size"
      xedc_r2_fail upload-archive "迁移包上传失败。"
    fi
    rc=0
    xedc_r2_run "$cfg" copyto "${archive}.sha256" "xedc:${R2_BUCKET}/${sha_key}" --s3-no-check-bucket >"$err" 2>&1 || rc=$?
    if [[ $rc -ne 0 ]]; then
      xedc_r2_note_log "$log_file" "$(cat "$err")"
      rm -f "$err" "$err.size"
      xedc_r2_fail upload-sha256 "校验文件上传失败。"
    fi
    rc=0
    xedc_r2_run "$cfg" copyto "$public_manifest" "xedc:${R2_BUCKET}/${manifest_key}" --s3-no-check-bucket >"$err" 2>&1 || rc=$?
    if [[ $rc -ne 0 ]]; then
      xedc_r2_note_log "$log_file" "$(cat "$err")"
      rm -f "$err" "$err.size"
      xedc_r2_fail upload-manifest "manifest 上传失败。"
    fi
    printf 'manifest=uploaded\n' >>"$log_file"
  fi
  remote_size=$(xedc_r2_remote_size "$cfg" "$key" || true)
  remote_sha_size=$(xedc_r2_remote_size "$cfg" "$sha_key" || true)
  remote_manifest_size=$(xedc_r2_remote_size "$cfg" "$manifest_key" || true)
  printf 'remote_bytes=%s\nremote_sha_bytes=%s\nremote_manifest_bytes=%s\n' "$remote_size" "$remote_sha_size" "$remote_manifest_size" >>"$log_file"
  if [[ ! $remote_size =~ ^[0-9]+$ || ! $remote_sha_size =~ ^[0-9]+$ || ! $remote_manifest_size =~ ^[0-9]+$ ]]; then
    rm -f "$err" "$err.size"
    xedc_r2_fail remote-stat "上传后无法读取三个远端对象的大小。"
  fi
  if [[ $remote_size != "$local_size" || $remote_sha_size != "$sha_size" || $remote_manifest_size != "$manifest_size" ]]; then
    rm -f "$err" "$err.size"
    xedc_r2_fail remote-size "远端大小与本地不一致。本地 ${local_size}，远端 ${remote_size:-未知}。"
  fi
  tmp=$(mktemp -d "${TMPDIR:-/tmp}/xedc-r2dl.XXXXXX")
  chmod 700 "$tmp"
  rc=0
  xedc_r2_run "$cfg" copyto "xedc:${R2_BUCKET}/${key}" "$tmp/$(basename "$archive")" >"$err" 2>&1 || rc=$?
  if [[ $rc -ne 0 ]]; then
    rm -rf "$tmp"
    xedc_r2_note_log "$log_file" "$(cat "$err")"
    rm -f "$err" "$err.size"
    xedc_r2_fail reread "回读远端迁移包失败。"
  fi
  rc=0
  xedc_r2_run "$cfg" copyto "xedc:${R2_BUCKET}/${sha_key}" "$tmp/$(basename "$archive").sha256" >"$err" 2>&1 || rc=$?
  if [[ $rc -ne 0 ]]; then
    rm -rf "$tmp"
    rm -f "$err" "$err.size"
    xedc_r2_fail reread-sha256 "回读远端校验文件失败。"
  fi
  if ! (
    cd "$tmp" || exit 1
    sha256sum -c "$(basename "$archive").sha256"
  ) >"$err" 2>&1; then
    rm -rf "$tmp"
    rm -f "$err" "$err.size"
    xedc_r2_fail remote-sha256 "远端对象的 SHA-256 与校验文件不一致。"
  fi
  local local_sum remote_sum
  local_sum=$(awk '{print $1}' "${archive}.sha256")
  remote_sum=$(sha256sum "$tmp/$(basename "$archive")" | awk '{print $1}')
  rm -rf "$tmp"
  rm -f "$err" "$err.size"
  if [[ $local_sum != "$remote_sum" ]]; then
    xedc_r2_fail remote-sha256 "远端 SHA-256 与本地迁移包不一致。"
  fi
  {
    printf 'sha256=PASS\n'
    printf 'stage=verified\n'
    printf 'result=PASS\n'
  } >>"$log_file"
  xedc_log INFO "R2 上传已核对。对象 ${key}，大小 ${remote_size}。日志 ${log_file}"
  rm -f "$cfg"
}

xedc_r2_fail_access() {
  local err_file=$1 action=$2
  local kind rc
  kind=$(xedc_r2_classify_error "$err_file")
  rc=${3:-1}
  if [[ -n ${XEDC_ERR_MARKER:-} ]]; then
    printf '1\n' >"$XEDC_ERR_MARKER"
  fi
  case $kind in
    auth) xedc_log FAIL "R2 鉴权失败。请检查 R2_ACCESS_KEY_ID 和 R2_SECRET_ACCESS_KEY，不要把 API Token 填进这两项。" ;;
    bucket) xedc_log FAIL "R2 Bucket 不存在或当前密钥无权访问。" ;;
    missing) xedc_log FAIL "R2 中没有找到迁移包。" ;;
    *) xedc_log FAIL "无法连接 R2。${action}" ;;
  esac
  exit "$rc"
}

xedc_r2_collect_packages() {
  local cfg=$1
  local err out rc kind path size name
  err=$(mktemp)
  out=$(mktemp)
  rc=0
  xedc_r2_run "$cfg" lsf "xedc:${R2_BUCKET}" --max-depth 1 >"$out" 2>"$err" || rc=$?
  if [[ $rc -ne 0 ]]; then
    if [[ $(xedc_r2_classify_error "$err") == missing ]]; then
      if [[ -n ${XEDC_ERR_MARKER:-} ]]; then
        printf '1\n' >"$XEDC_ERR_MARKER"
      fi
      xedc_log FAIL "R2 Bucket 不存在或当前密钥无权访问。"
      exit "$rc"
    fi
    xedc_r2_fail_access "$err" "列出 Bucket 失败。" "$rc"
  fi
  : >"$out"
  rc=0
  xedc_r2_run "$cfg" ls "xedc:${R2_BUCKET}/xedc-backups" >"$out" 2>"$err" || rc=$?
  if [[ $rc -ne 0 ]]; then
    kind=$(xedc_r2_classify_error "$err")
    if [[ $kind == missing ]]; then
      rm -f "$err" "$out"
      return 0
    fi
    xedc_r2_fail_access "$err" "列出 xedc-backups 失败。" "$rc"
  fi
  while IFS= read -r line; do
    [[ -n $line ]] || continue
    size=$(printf '%s\n' "$line" | awk '{print $1}')
    path=$(printf '%s\n' "$line" | awk '{print substr($0, index($0,$2))}')
    name=$(basename "$path")
    [[ $name =~ ^xedc-migration-[0-9]{8}T[0-9]{6}Z\.tar\.zst$ ]] || continue
    printf '%s\t%s\t%s\n' "$name" "$size" "xedc-backups/${path}"
  done <"$out" | sort -t $'\t' -k1,1r
  rm -f "$err" "$out"
}

xedc_r2_list() {
  xedc_set_phase r2-list
  xedc_r2_load
  xedc_r2_install_rclone
  local cfg rows count now
  cfg=$(xedc_r2_rclone_config)
  rows=$(xedc_r2_collect_packages "$cfg")
  now=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  if [[ -z $rows ]]; then
    count=0
  else
    count=$(printf '%s\n' "$rows" | sed '/^$/d' | wc -l | tr -d ' ')
  fi
  printf '%s\n' "XEDC R2 迁移包列表" "Bucket: 已连接" "路径: xedc-backups/" "列表时间: ${now}"
  if [[ $count == 0 ]]; then
    printf '%s\n' "R2 中没有找到迁移包。" "对象数量：0" "列表状态：PASS"
  else
    printf '找到 %s 个迁移包：\n' "$count"
    printf '%-44s %14s  %s\n' "文件名" "大小" "对象路径"
    local name size key
    while IFS=$'\t' read -r name size key; do
      [[ -n $name ]] || continue
      printf '%-44s %14s  %s\n' "$name" "${size} 字节" "$key"
    done <<<"$rows"
    printf '%s\n' "对象数量：${count}" "列表状态：PASS"
  fi
  rm -f "$cfg"
}

xedc_r2_resolve_key() {
  local stamp=${1:-}
  local cfg=$2
  local rows name key
  rows=$(xedc_r2_collect_packages "$cfg")
  if [[ -z $rows ]]; then
    return 1
  fi
  if [[ -n $stamp ]]; then
    name="xedc-migration-${stamp}.tar.zst"
    key=$(printf '%s\n' "$rows" | awk -F '\t' -v want="$name" '$1 == want {print $3; exit}')
  else
    key=$(printf '%s\n' "$rows" | awk -F '\t' 'NR==1 {print $3}')
  fi
  [[ -n $key ]] || return 1
  printf '%s\n' "$key"
}

xedc_r2_download() {
  local stamp=${1:-}
  local dest_dir=${2:-${XEDC_BACKUP_ROOT:-/var/backups/xedc}}
  xedc_set_phase r2-download
  xedc_r2_load
  xedc_r2_install_rclone
  local cfg key tmp base archive manifest_name err rc remote_size local_size
  cfg=$(xedc_r2_rclone_config)
  err=$(mktemp)
  if ! key=$(xedc_r2_resolve_key "$stamp" "$cfg" 2>"$err"); then
    if [[ -s $err ]] && [[ $(xedc_r2_classify_error "$err") != missing ]]; then
      xedc_r2_fail_access "$err" "下载前列出对象失败。" 1
    fi
    rm -f "$cfg" "$err"
    xedc_die "R2 中没有找到迁移包。"
  fi
  base=$(basename "$key")
  manifest_name="${base%.tar.zst}.manifest.public.json"
  if [[ ! -d $dest_dir ]]; then
    mkdir -p "$dest_dir"
    chmod 700 "$dest_dir"
  fi
  if [[ -e $dest_dir/$base || -e $dest_dir/${base}.sha256 || -e $dest_dir/$manifest_name ]]; then
    rm -f "$cfg" "$err"
    xedc_die "目标目录已有同名文件，不会覆盖：${dest_dir}/${base}"
  fi
  tmp=$(mktemp -d "${TMPDIR:-/tmp}/xedc-r2partial.XXXXXX")
  chmod 700 "$tmp"
  xedc_log INFO "从 R2 下载 ${key}。密钥不写入日志。"
  rc=0
  xedc_r2_run "$cfg" copyto "xedc:${R2_BUCKET}/${key}" "$tmp/$base" >"$err" 2>&1 || rc=$?
  if [[ $rc -ne 0 ]]; then
    rm -rf "$tmp"
    xedc_r2_fail_access "$err" "迁移包下载失败，已删除临时文件。" "$rc"
  fi
  rc=0
  xedc_r2_run "$cfg" copyto "xedc:${R2_BUCKET}/${key}.sha256" "$tmp/${base}.sha256" >"$err" 2>&1 || rc=$?
  if [[ $rc -ne 0 ]]; then
    rm -rf "$tmp"
    local kind
    kind=$(xedc_r2_classify_error "$err")
    rm -f "$cfg" "$err"
    if [[ $kind == missing ]]; then
      xedc_die "远端缺少 SHA256 文件，已删除临时下载。"
    fi
    xedc_die "SHA256 下载失败，已删除临时文件。"
  fi
  rc=0
  xedc_r2_run "$cfg" copyto "xedc:${R2_BUCKET}/${key%.tar.zst}.manifest.public.json" "$tmp/$manifest_name" >"$err" 2>&1 || rc=$?
  if [[ $rc -ne 0 ]]; then
    rm -rf "$tmp"
    local kind
    kind=$(xedc_r2_classify_error "$err")
    rm -f "$cfg" "$err"
    if [[ $kind == missing ]]; then
      xedc_die "远端缺少公共 manifest，已删除临时下载。"
    fi
    xedc_die "manifest 下载失败，已删除临时文件。"
  fi
  remote_size=$(xedc_r2_remote_size "$cfg" "$key" || true)
  local_size=$(stat -c %s "$tmp/$base")
  if [[ ! $remote_size =~ ^[0-9]+$ || $remote_size != "$local_size" ]]; then
    rm -rf "$tmp"
    rm -f "$cfg" "$err"
    xedc_die "下载大小 ${local_size} 与远端 ${remote_size:-未知} 不一致，已删除临时文件。"
  fi
  if ! (
    cd "$tmp" || exit 1
    sha256sum -c "${base}.sha256"
  ) >&2; then
    rm -rf "$tmp"
    rm -f "$cfg" "$err"
    xedc_die "下载文件的 SHA-256 不一致，已删除临时文件。不要用它恢复。"
  fi
  if ! zstd -t -q "$tmp/$base"; then
    rm -rf "$tmp"
    rm -f "$cfg" "$err"
    xedc_die "下载文件无法通过 zstd 测试，已删除临时文件。"
  fi
  if ! declare -F xedc_migration_manifest_corresponds >/dev/null 2>&1; then
    # shellcheck disable=SC1091
    source "$(dirname "${BASH_SOURCE[0]}")/package.sh"
  fi
  if ! xedc_migration_manifest_corresponds "$tmp/$base" "$tmp/$manifest_name"; then
    rm -rf "$tmp"
    rm -f "$cfg" "$err"
    xedc_die "下载的 manifest 与迁移包不对应，已删除临时文件。"
  fi
  if [[ -e $dest_dir/$base || -e $dest_dir/${base}.sha256 || -e $dest_dir/$manifest_name ]]; then
    rm -rf "$tmp"
    rm -f "$cfg" "$err"
    xedc_die "目标目录已有同名文件，不会覆盖：${dest_dir}/${base}"
  fi
  mv "$tmp/$base" "$dest_dir/$base"
  mv "$tmp/${base}.sha256" "$dest_dir/${base}.sha256"
  mv "$tmp/$manifest_name" "$dest_dir/$manifest_name"
  rm -rf "$tmp"
  rm -f "$cfg" "$err"
  chmod 600 "$dest_dir/$base" "$dest_dir/${base}.sha256"
  chmod 644 "$dest_dir/$manifest_name"
  archive="$dest_dir/$base"
  xedc_log INFO "R2 下载并校验通过：${archive}"
  printf '%s\n' "$archive" "$dest_dir/${base}.sha256" "$dest_dir/$manifest_name"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 backup.sh 或 download.sh 运行。" >&2
  exit 1
fi
