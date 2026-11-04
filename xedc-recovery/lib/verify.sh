#!/usr/bin/env bash
# shellcheck shell=bash

xedc_verify_http() {
  local base=$1 path=$2 expect=$3
  local code
  code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 "${base}${path}" || true)
  case $code in
    200|301|302|303|307|308)
      if [[ $expect == redirect && $code == 200 ]]; then
        printf 'WARN %s %s 期望跳转\n' "$path" "$code"
        return 0
      fi
      printf 'PASS %s %s\n' "$path" "$code"
      ;;
    "")
      printf 'FAIL %s 无响应\n' "$path"
      return 1
      ;;
    *)
      printf 'FAIL %s %s\n' "$path" "$code"
      return 1
      ;;
  esac
}

xedc_verify_site() {
  local base=$1
  local failed=0
  xedc_verify_http "$base" "/" 200 || failed=1
  xedc_verify_http "$base" "/user/login" 200 || failed=1
  xedc_verify_http "$base" "/admin" redirect || failed=1
  xedc_verify_http "$base" "/articles" 200 || failed=1
  xedc_verify_http "$base" "/models" 200 || failed=1
  xedc_verify_http "$base" "/video" 200 || failed=1
  xedc_verify_http "$base" "/sitemap.xml" 200 || failed=1
  return $failed
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 restore.sh 运行。" >&2
  exit 1
fi
