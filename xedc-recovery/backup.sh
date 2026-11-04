#!/usr/bin/env bash
# 从任意目录运行。备份产物写到仓库外的 /var/backups/xedc。
set -Eeuo pipefail

SCRIPT_DIR=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"
# shellcheck source=lib/database.sh
source "$SCRIPT_DIR/lib/database.sh"
# shellcheck source=lib/files.sh
source "$SCRIPT_DIR/lib/files.sh"
# shellcheck source=lib/media.sh
source "$SCRIPT_DIR/lib/media.sh"
# shellcheck source=lib/github-software.sh
source "$SCRIPT_DIR/lib/github-software.sh"
# shellcheck source=lib/services.sh
source "$SCRIPT_DIR/lib/services.sh"
# shellcheck source=lib/backup.sh
source "$SCRIPT_DIR/lib/backup.sh"

xedc_init
OUTPUT=""
while [[ $# -gt 0 ]]; do
  case $1 in
    --help|-h) xedc_backup_usage; exit 0 ;;
    --dry-run) export XEDC_DRY_RUN=1; shift ;;
    --output)
      OUTPUT=${2:-}
      [[ -n $OUTPUT ]] || xedc_die "--output 需要目录。"
      shift 2
      ;;
    *) xedc_die "未知参数：$1" ;;
  esac
done

xedc_backup_on_exit() {
  local rc=$1
  xedc_cleanup_temps
  if [[ $rc -ne 0 && ${XEDC_BACKUP_OK:-0} != 1 && -n ${XEDC_DEST:-} ]]; then
    xedc_log FAIL "删除未完成的备份 ${XEDC_DEST}"
    rm -rf "$XEDC_DEST"
  fi
}
trap 'xedc_backup_on_exit $?' EXIT

xedc_backup_main "$OUTPUT"
