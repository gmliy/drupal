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
# shellcheck source=lib/package.sh
source "$SCRIPT_DIR/lib/package.sh"
# shellcheck source=lib/r2.sh
source "$SCRIPT_DIR/lib/r2.sh"

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
    --upload-r2) export XEDC_UPLOAD_R2=1; shift ;;
    --package-snapshot)
      PACKAGE_SNAPSHOT=${2:-}
      [[ -n $PACKAGE_SNAPSHOT ]] || xedc_die "--package-snapshot 需要快照目录。"
      shift 2
      ;;
    *) xedc_die "未知参数：$1" ;;
  esac
done

xedc_backup_on_exit() {
  local rc=$1
  [[ ${XEDC_EXIT_HANDLED:-0} == 1 ]] && return 0
  XEDC_EXIT_HANDLED=1
  trap - ERR EXIT DEBUG
  xedc_cleanup_temps || true
  if [[ $rc -ne 0 && ${XEDC_BACKUP_OK:-0} != 1 && -n ${XEDC_DEST:-} ]]; then
    xedc_log FAIL "删除未完成的备份 ${XEDC_DEST}" || true
    rm -rf "$XEDC_DEST" || true
  fi
  exit "$rc"
}
trap 'xedc_backup_on_exit $?' EXIT

if [[ -n ${PACKAGE_SNAPSHOT:-} ]]; then
  xedc_require_root
  xedc_migration_package "$PACKAGE_SNAPSHOT" "${OUTPUT:-$XEDC_BACKUP_ROOT}" >"$XEDC_STATE_ROOT/last-package-path"
  package_path=$(cat "$XEDC_STATE_ROOT/last-package-path")
  [[ -f $package_path ]] || xedc_die "迁移包生成失败。"
  if [[ ${XEDC_UPLOAD_R2:-0} == 1 ]]; then
    xedc_set_phase r2-upload
    xedc_r2_upload_package "$package_path"
  fi
  printf '%s\n' "$package_path"
  exit 0
fi

xedc_backup_main "$OUTPUT"
