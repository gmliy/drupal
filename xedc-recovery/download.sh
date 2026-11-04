#!/usr/bin/env bash
# 从 Cloudflare R2 下载迁移包。凭据读 /root/.config/xedc-recovery/r2.env，权限必须是 600。
set -Eeuo pipefail

SCRIPT_DIR=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"
# shellcheck source=lib/r2.sh
source "$SCRIPT_DIR/lib/r2.sh"
# shellcheck source=lib/environment.sh
source "$SCRIPT_DIR/lib/environment.sh"

xedc_init

SOURCE=""
STAMP=""
OUTPUT=""
LIST_ONLY=0

while [[ $# -gt 0 ]]; do
  case $1 in
    --help|-h)
      cat <<'EOF'
用法:
  download.sh --source=r2 --list
  download.sh --source=r2 [--output 目录]
  download.sh --source=r2 --backup 20261104T082520Z [--output 目录]

--list 列出 xedc-backups 下的迁移包。没有迁移包时会明确说明，退出码仍是 0。
省略 --backup 时下载最新的一个迁移包，同时下载 .sha256 和 .manifest.public.json。
已有同名文件时不会覆盖。校验失败只删除本次临时文件。
R2 配置写在 /root/.config/xedc-recovery/r2.env，权限 600 或 400。
缺少 rclone 时会用系统包安装。日志不打印 Access Key 和 Secret。
EOF
      exit 0
      ;;
    --source=*) SOURCE=${1#--source=}; shift ;;
    --source) SOURCE=${2:-}; shift 2 ;;
    --backup=*) STAMP=${1#--backup=}; shift ;;
    --backup) STAMP=${2:-}; shift 2 ;;
    --output) OUTPUT=${2:-}; shift 2 ;;
    --list) LIST_ONLY=1; shift ;;
    *) xedc_die "未知参数：$1" ;;
  esac
done

[[ $SOURCE == r2 ]] || xedc_die "目前只支持 --source=r2。"
xedc_require_root
if [[ $LIST_ONLY == 1 ]]; then
  xedc_r2_list
  exit 0
fi
xedc_r2_download "$STAMP" "${OUTPUT:-$XEDC_BACKUP_ROOT}"
