#!/usr/bin/env bash
# 新服务器一条命令：取私有仓库里的密钥，下载 R2 迁移包，校验、解密并恢复。
set -Eeuo pipefail

SCRIPT_DIR=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"
# shellcheck source=lib/secure.sh
source "$SCRIPT_DIR/lib/secure.sh"
# shellcheck source=lib/github.sh
source "$SCRIPT_DIR/lib/github.sh"
# shellcheck source=lib/package.sh
source "$SCRIPT_DIR/lib/package.sh"
# shellcheck source=lib/r2.sh
source "$SCRIPT_DIR/lib/r2.sh"

xedc_init
export XEDC_LOG="${XEDC_LOG_DIR}/recover.log"

STAMP=""
OUTPUT=""
DOMAIN=""
OLD_DOMAIN=""
NEW_DOMAIN=""
BOOTSTRAP=0
EXTRA=()

xedc_recover_usage() {
  cat <<'EOF'
用法:
  recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名
  recover.sh --backup 20261104T105132Z --domain 新域名 --old-domain 旧域名 --new-domain 新域名
  recover.sh --dry-run
  recover.sh --init-deploy-key
  bootstrap-recover.sh --domain 新域名 --old-domain 旧域名 --new-domain 新域名

日常备份:
  backup.sh
  backup.sh --upload-r2

--backup 选择 R2 上已有迁移包的时间戳。省略时用最新的一个。
域名也可以写在 /root/.config/xedc-recovery/restore.env，之后只需执行 recover.sh。
--dry-run 只检查本机、密钥和 R2 列表，不下载、不安装、不恢复。
日志：/var/log/xedc-recovery/recover.log
回滚：restore.sh --rollback
EOF
}

while [[ $# -gt 0 ]]; do
  case $1 in
    --help|-h) xedc_recover_usage; exit 0 ;;
    --dry-run) export XEDC_DRY_RUN=1; shift ;;
    --init-deploy-key) xedc_require_root; xedc_github_init_deploy_key; exit 0 ;;
    --bootstrap) BOOTSTRAP=1; shift ;;
    --backup) STAMP=${2:-}; shift 2 ;;
    --backup=*) STAMP=${1#--backup=}; shift ;;
    --output) OUTPUT=${2:-}; shift 2 ;;
    --domain) DOMAIN=${2:-}; shift 2 ;;
    --old-domain) OLD_DOMAIN=${2:-}; shift 2 ;;
    --new-domain) NEW_DOMAIN=${2:-}; shift 2 ;;
    --without-matomo|--without-netdata|--without-docker-services|--without-media|--with-matomo|--with-netdata|--with-docker-services|--with-media|--force)
      EXTRA+=("$1"); shift ;;
    *) xedc_die "未知参数：$1" ;;
  esac
done

xedc_require_root
xedc_set_phase preflight
xedc_os_info
case "$XEDC_OS_ARCH" in
  amd64|arm64|x86_64|aarch64) ;;
  *) xedc_die "不支持的 CPU 架构 ${XEDC_OS_ARCH}。" ;;
esac
if ! xedc_os_supported; then
  exit 1
fi
xedc_check_resources "${OUTPUT:-/var/lib/xedc-recovery}" 2097152
if ! getent hosts github.com >/dev/null; then
  xedc_die "无法解析 github.com。恢复已停止。"
fi
xedc_log INFO "系统 ${XEDC_OS_PRETTY}，架构 ${XEDC_OS_ARCH}。"

if [[ $BOOTSTRAP == 1 ]]; then
  xedc_set_phase github
  dest=$(xedc_github_sync)
  exec "$dest/xedc-recovery/recover.sh" ${XEDC_DRY_RUN:+--dry-run} \
    ${STAMP:+--backup "$STAMP"} ${OUTPUT:+--output "$OUTPUT"} \
    ${DOMAIN:+--domain "$DOMAIN"} ${OLD_DOMAIN:+--old-domain "$OLD_DOMAIN"} \
    ${NEW_DOMAIN:+--new-domain "$NEW_DOMAIN"} "${EXTRA[@]}"
fi

envfile=/root/.config/xedc-recovery/restore.env
if [[ -f $envfile ]]; then
  while IFS= read -r line; do
    case $line in
      DOMAIN=*) [[ -n $DOMAIN ]] || DOMAIN=${line#DOMAIN=} ;;
      OLD_DOMAIN=*) [[ -n $OLD_DOMAIN ]] || OLD_DOMAIN=${line#OLD_DOMAIN=} ;;
      NEW_DOMAIN=*) [[ -n $NEW_DOMAIN ]] || NEW_DOMAIN=${line#NEW_DOMAIN=} ;;
    esac
  done <"$envfile"
fi

xedc_set_phase github
xedc_secure_install_deploy_key
if [[ -f $(xedc_github_deploy_key) ]]; then
  if ! xedc_github_ls_remote; then
    xedc_die "GitHub 私有仓库认证失败。恢复已停止。"
  fi
  xedc_log INFO "GitHub 只读认证通过。"
elif [[ -f "$(xedc_secure_dir)/age.key" ]]; then
  xedc_log INFO "使用当前仓库里的密钥。这台机器没有 Deploy Key，不重新拉取。"
else
  xedc_die "没有 Deploy Key，也没有仓库里的 age 私钥。先执行 recover.sh --init-deploy-key。"
fi

xedc_set_phase secrets
xedc_secure_install_age
xedc_secure_install_r2

xedc_set_phase r2-list
if ! xedc_r2_list; then
  xedc_die "无法列出 R2 迁移包。"
fi

if [[ ${XEDC_DRY_RUN:-0} == 1 ]]; then
  xedc_log INFO "dry-run 结束。没有下载、没有安装软件、没有恢复网站。"
  exit 0
fi

incoming=${OUTPUT:-/var/lib/xedc-recovery/incoming}
install -d -m 700 "$incoming"
xedc_set_phase r2-download
"$SCRIPT_DIR/download.sh" --source=r2 ${STAMP:+--backup "$STAMP"} --output "$incoming" >"$XEDC_STATE_ROOT/recover-download.paths"
mapfile -t downloaded <"$XEDC_STATE_ROOT/recover-download.paths"
archive=""
for path in "${downloaded[@]}"; do
  [[ $path == *.tar.zst && $path != *.sha256 ]] && archive=$path
done
[[ -n $archive && -f $archive ]] || xedc_die "下载没有得到迁移包。"
sha="${archive}.sha256"
name=$(basename "$archive" .tar.zst)
manifest="$incoming/${name}.manifest.public.json"
[[ -f $sha && -f $manifest ]] || xedc_die "下载结果缺少校验文件或 manifest。"
xedc_set_phase verify
( cd "$incoming" && sha256sum -c "$(basename "$sha")" ) || xedc_die "SHA-256 不一致。已停止。"
zstd -t -q "$archive" || xedc_die "迁移包未通过 zstd 检查。"
# shellcheck source=lib/package.sh
xedc_migration_manifest_corresponds "$archive" "$manifest" || xedc_die "manifest 与迁移包不一致。"

extract="$incoming/${name}"
if [[ ! -f $extract/snapshot/secrets.enc ]]; then
  rm -rf "$extract"
  mkdir -p "$extract"
  tar --zstd -C "$incoming" -xf "$archive"
fi
[[ -f $extract/snapshot/secrets.enc ]] || xedc_die "迁移包里没有 secrets.enc。"
xedc_secure_decrypt_check "$extract/snapshot/secrets.enc"

source_hash=$(jq -r '.source.machine_id_sha256 // empty' "$extract/snapshot/manifest.json")
xedc_assert_restore_target "$source_hash"

DOMAIN=${DOMAIN:-${NEW_DOMAIN:-}}
[[ -n $DOMAIN ]] || xedc_die "恢复需要 --domain，或在 /root/.config/xedc-recovery/restore.env 写 DOMAIN。"
xedc_set_phase restore
xedc_log INFO "开始恢复。快照 ${extract}/snapshot ，日志 ${XEDC_LOG_DIR}/recovery.log"
"$SCRIPT_DIR/restore.sh" --mode=full --snapshot "$extract/snapshot" \
  --domain "$DOMAIN" \
  ${OLD_DOMAIN:+--old-domain "$OLD_DOMAIN"} \
  ${NEW_DOMAIN:+--new-domain "$NEW_DOMAIN"} \
  "${EXTRA[@]}"
xedc_log INFO "恢复命令已结束。日志 ${XEDC_LOG} 和 ${XEDC_LOG_DIR}/recovery.log 。凭据在 /root/.drupal-credentials 。"
