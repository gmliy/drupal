#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
# shellcheck source=common.sh
source "$ROOT/lib/common.sh"
out=${1:-"$ROOT/manifest.json"}
if ! xedc_have php; then
  xedc_die "发现依赖需要 php 命令"
fi
php "$ROOT/lib/php/discover.php" -o "$out"
xedc_log "已写入清单 $out"
