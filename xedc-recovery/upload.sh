#!/usr/bin/env bash
# 上传已经存在的迁移包。不重新备份，不连接生产数据库。
set -Eeuo pipefail

SCRIPT_DIR=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"
# shellcheck source=lib/package.sh
source "$SCRIPT_DIR/lib/package.sh"
# shellcheck source=lib/r2.sh
source "$SCRIPT_DIR/lib/r2.sh"
# shellcheck source=lib/environment.sh
source "$SCRIPT_DIR/lib/environment.sh"

xedc_init

FILE=""
DRY=0

while [[ $# -gt 0 ]]; do
  case $1 in
    --help|-h)
      cat <<'EOF'
用法:
  upload.sh --file /var/backups/xedc/xedc-migration-YYYYMMDDTHHMMSSZ.tar.zst
  upload.sh --dry-run --file /var/backups/xedc/xedc-migration-YYYYMMDDTHHMMSSZ.tar.zst

直接上传已经生成的迁移包、同目录的 .sha256 和 .manifest.public.json。
不会重新备份，不会读取生产数据库，也不会改写这三个本地文件。
--dry-run 只检查本地文件和 R2 配置，不连接远端。
R2 配置是 /root/.config/xedc-recovery/r2.env，权限 600 或 400。
EOF
      exit 0
      ;;
    --file)
      FILE=${2:-}
      [[ -n $FILE ]] || xedc_die "--file 需要迁移包路径。"
      shift 2
      ;;
    --dry-run) DRY=1; shift ;;
    *) xedc_die "未知参数：$1" ;;
  esac
done

xedc_require_root
[[ -n $FILE ]] || xedc_die "需要 --file。"
xedc_set_phase upload-precheck

if [[ -L $FILE ]]; then
  xedc_die "拒绝符号链接。请传入迁移包本身。"
fi
if [[ -d $FILE ]]; then
  xedc_die "拒绝目录。--file 必须是迁移包文件。"
fi
if [[ ! -f $FILE ]]; then
  xedc_die "找不到迁移包文件。"
fi

base=$(basename "$FILE")
[[ $base =~ ^xedc-migration-[0-9]{8}T[0-9]{6}Z\.tar\.zst$ ]] || xedc_die "文件名必须是 xedc-migration-YYYYMMDDTHHMMSSZ.tar.zst。"
dir=$(cd "$(dirname "$FILE")" && pwd)
archive="$dir/$base"
sha="${archive}.sha256"
manifest="${dir}/${base%.tar.zst}.manifest.public.json"
export XEDC_LOG="${XEDC_LOG_DIR}/upload-${base%.tar.zst}.log"
export XEDC_R2_LOG=$XEDC_LOG
mkdir -p "$XEDC_LOG_DIR"
touch "$XEDC_LOG"
chmod 600 "$XEDC_LOG"
printf '\n==== %s upload %s ====\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$base" >>"$XEDC_LOG"

if [[ -L $sha || ! -f $sha ]]; then
  xedc_die "找不到对应的 SHA256 文件。"
fi
if [[ -L $manifest || ! -f $manifest ]]; then
  xedc_die "找不到对应的 .manifest.public.json。"
fi
if ! (
  cd "$dir" || exit 1
  sha256sum -c "$(basename "$sha")"
) >&2; then
  xedc_die "本地 SHA-256 校验失败，未上传。"
fi
if ! zstd -t -q "$archive"; then
  xedc_die "zstd 完整性测试失败，未上传。"
fi
if ! xedc_migration_manifest_corresponds "$archive" "$manifest"; then
  xedc_die "manifest 与迁移包不对应，未上传。"
fi
if ! stats=$(xedc_migration_verify_archive "$archive" "$sha"); then
  xedc_die "迁移包内容或安全检查失败，未上传。"
fi
xedc_log INFO "本地迁移包校验通过：${stats}"

if [[ $DRY == 1 ]]; then
  export XEDC_DRY_RUN=1
fi
xedc_set_phase r2-upload
xedc_r2_upload_package "$archive"
if [[ $DRY == 1 ]]; then
  printf 'dry-run 未上传。计划对象：%s\n' "$(xedc_r2_object_key "$archive")"
else
  printf '远端对象：%s\n' "$(xedc_r2_object_key "$archive")"
fi
