#!/usr/bin/env bash
# shellcheck shell=bash

xedc_count_tree() {
  local dir=$1
  if [[ ! -d $dir ]]; then
    printf '%s %s\n' 0 0
    return 0
  fi
  local count bytes
  count=$(find -P "$dir" -xdev -type f 2>/dev/null | wc -l | tr -d ' ')
  bytes=$(find -P "$dir" -xdev -type f -printf '%s\n' 2>/dev/null | awk '{s+=$1} END {print s+0}')
  printf '%s %s\n' "$count" "$bytes"
}

xedc_tar_to_zstd() {
  local outfile=$1
  shift
  local tar_rc=0 zstd_rc=0
  set +o pipefail
  xedc_nice tar "$@" | zstd -T2 -q -o "$outfile"
  tar_rc=${PIPESTATUS[0]} zstd_rc=${PIPESTATUS[1]}
  set -o pipefail
  if [[ $zstd_rc -ne 0 ]]; then
    return 1
  fi
  # tar 退出码 1 表示文件在读取过程中发生变化，站点仍在运行时可以接受。
  [[ $tar_rc -le 1 ]]
}

xedc_archive_project() {
  local site=$1 outfile=$2 overlay=$3
  local staging tarfile
  staging=$(mktemp -d)
  chmod 700 "$staging"
  tarfile=${outfile%.zst}
  xedc_tar_to_zstd "$outfile" \
    --exclude=.git \
    --exclude=./.git \
    --exclude=vendor \
    --exclude=./vendor \
    --exclude=web/core \
    --exclude=./web/core \
    --exclude=web/modules/contrib \
    --exclude=web/themes/contrib \
    --exclude=node_modules \
    --exclude=web/sites/default/files \
    --exclude=private \
    --exclude=_server \
    --exclude=web/sites/default/settings.php \
    --exclude=web/sites/default/settings.php.bak* \
    --exclude=web/sites/default/settings.local.php \
    --exclude=config/sync \
    -C "$site" -cf - .
  if [[ -d $overlay ]]; then
    zstd -d -q -c "$outfile" >"$tarfile"
    tar -C "$overlay" -rf "$tarfile" .
    zstd -T2 -q --rm -f "$tarfile" -o "$outfile"
  fi
  rm -rf "$staging"
}

xedc_archive_dir() {
  local source=$1 outfile=$2
  shift 2
  if [[ ! -d $source ]]; then
    printf '' | zstd -T2 -q -o "$outfile"
    return 0
  fi
  # shellcheck disable=SC2086
  xedc_tar_to_zstd "$outfile" "$@" -C "$source" -cf - .
}

xedc_count_private_archive() {
  local dir=$1
  if [[ ! -d $dir ]]; then
    printf '%s %s\n' 0 0
    return 0
  fi
  find -P "$dir" -xdev -type f \
    ! -path '*/xedc-storage-keys/*' \
    ! -path '*/xedc-ai-keys/*' \
    ! -name 'settings.php*' \
    ! -name '*.key' \
    -printf '%s\n' 2>/dev/null | awk '{n++; s+=$1} END {print n+0, s+0}'
}

xedc_archive_private() {
  local source=$1 outfile=$2
  xedc_tar_to_zstd "$outfile" \
    --exclude=xedc-storage-keys \
    --exclude=xedc-ai-keys \
    --exclude='settings.php*' \
    --exclude='*/settings.php*' \
    --exclude='*.key' \
    --exclude='*/*.key' \
    --exclude='*/*/*.key' \
    -C "$source" -cf - .
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 xedc-recovery/backup.sh 或 restore.sh 运行。" >&2
  exit 1
fi
