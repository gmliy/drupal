#!/usr/bin/env bash
# 在隔离容器里检查各发行版能否安装 PHP 8.5。不改宿主机的 PHP。
set -Eeuo pipefail

log_dir=/var/log/xedc-recovery/php85-matrix
mkdir -p "$log_dir"
chmod 700 "$log_dir"
summary=$log_dir/summary.txt
if [[ $# -eq 0 ]]; then
  : >"$summary"
else
  touch "$summary"
fi

probe() {
  local image=$1 label=$2
  local logfile=$log_dir/${label}.log
  if ! docker image inspect "$image" >/dev/null 2>&1; then
    if ! docker pull "$image" >"$logfile" 2>&1; then
      printf 'BLOCKED %s 无法获取镜像 %s\n' "$label" "$image" | tee -a "$summary"
      return 0
    fi
  fi
  if docker run --rm --memory=1536m --cpus=1 "$image" bash -lc '
    set -Eeuo pipefail
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y ca-certificates curl gnupg
    . /etc/os-release
    ondrej="https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${VERSION_CODENAME}/Release"
    sury="https://packages.sury.org/php/dists/${VERSION_CODENAME}/Release"
    if [[ $ID == ubuntu ]] && curl -fsI -o /dev/null --max-time 25 "$ondrej"; then
      apt-get install -y software-properties-common
      add-apt-repository -y ppa:ondrej/php
    elif curl -fsI -o /dev/null --max-time 25 "$sury"; then
      echo "USE_SURY ${ID} ${VERSION_CODENAME}"
      curl -fsSL -o /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
      echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" > /etc/apt/sources.list.d/sury-php.list
    else
      echo "NO_PHP_REPO ${ID} ${VERSION_CODENAME}"
    fi
    apt-get update
    apt-cache show php8.5-cli >/dev/null
    apt-cache show php8.5-fpm >/dev/null
    apt-get install -y php8.5-cli php8.5-fpm php8.5-mysql php8.5-xml php8.5-gd php8.5-mbstring php8.5-curl php8.5-zip php8.5-intl php8.5-bcmath
    php8.5 -v
    php-fpm8.5 -v
    php8.5 -r "exit(PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600 ? 0 : 1);"
  ' >"$logfile" 2>&1; then
    printf 'PASS %s PHP 8.5 CLI 和 FPM 已安装\n' "$label" | tee -a "$summary"
  else
    printf 'BLOCKED %s 不能安装 PHP 8.5，见 %s\n' "$label" "$logfile" | tee -a "$summary"
  fi
}

if [[ $# -gt 0 ]]; then
  while [[ $# -ge 2 ]]; do
    probe "$1" "$2"
    shift 2
  done
else
  probe ubuntu:20.04 ubuntu-20.04
  probe ubuntu:22.04 ubuntu-22.04
  probe ubuntu:24.04 ubuntu-24.04
  probe ubuntu:26.04 ubuntu-26.04
  probe ubuntu:26.10 ubuntu-26.10
  probe debian:11 debian-11
  probe debian:12 debian-12
  probe debian:13 debian-13
fi
printf '%s\n' '矩阵结束' | tee -a "$summary"
