#!/usr/bin/env bash
# shellcheck shell=bash

xedc_media_facts() {
  local outfile=$1
  local version="" encoders="" hw=""
  if xedc_have ffmpeg; then
    version=$(ffmpeg -version 2>/dev/null | awk 'NR==1 {print $3}')
    encoders=$(ffmpeg -hide_banner -encoders 2>/dev/null | awk '/^ [A-Z]/ {print $2}' | grep -E '^(libx264|libx265|libvpx|libvpx-vp9|aac|libopus)$' || true)
    hw=$(ffmpeg -hide_banner -hwaccels 2>/dev/null | awk 'NR>1 {print $1}' || true)
  fi
  local player_dir=${XEDC_SITE_ROOT}/web/themes/custom/xedc/js
  local plyr="missing" hls="missing"
  [[ -f $player_dir/plyr.min.js ]] && plyr=$(sha256sum "$player_dir/plyr.min.js" | awk '{print $1}')
  [[ -f $player_dir/hls.min.js ]] && hls=$(sha256sum "$player_dir/hls.min.js" | awk '{print $1}')
  jq -n \
    --arg version "$version" \
    --arg encoders "$encoders" \
    --arg hw "$hw" \
    --arg plyr "$plyr" \
    --arg hls "$hls" \
    '{
      ffmpeg_version: $version,
      encoders: ($encoders | split("\n") | map(select(length>0))),
      hwaccels: ($hw | split("\n") | map(select(length>0))),
      player: {name: "plyr", sha256: $plyr, hls_sha256: $hls},
      imagemagick_policy: false,
      yt_dlp: false
    }' >"$outfile"
}

xedc_media_install() {
  xedc_os_supported
  if ! xedc_have ffmpeg; then
    xedc_run apt-get install -y ffmpeg
  fi
  if ! xedc_have ffmpeg; then
    xedc_warn "ffmpeg 未能安装。"
    return 1
  fi
  xedc_log INFO "ffmpeg $(ffmpeg -version 2>/dev/null | awk 'NR==1 {print $3}')"
}

xedc_media_smoke() {
  local work=$1
  mkdir -p "$work"
  if ! xedc_have ffmpeg; then
    xedc_warn "没有 ffmpeg，跳过转码冒烟测试。"
    return 1
  fi
  xedc_nice ffmpeg -y -hide_banner -loglevel error \
    -f lavfi -i testsrc=size=160x120:rate=10 \
    -f lavfi -i sine=frequency=1000:sample_rate=8000 \
    -t 1 -c:v libx264 -pix_fmt yuv420p -c:a aac \
    "$work/sample.mp4"
  ffprobe -hide_banner -loglevel error "$work/sample.mp4"
  xedc_nice ffmpeg -y -hide_banner -loglevel error -i "$work/sample.mp4" -c:v libx264 -t 1 "$work/out.mp4"
  [[ -s $work/out.mp4 ]]
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 xedc-recovery/backup.sh 或 restore.sh 运行。" >&2
  exit 1
fi
