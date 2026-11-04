#!/usr/bin/env bash
# shellcheck shell=bash

xedc_github_curl() {
  local url=$1
  local attempt=1 delay=2 code
  while [[ $attempt -le 4 ]]; do
    if [[ ${XEDC_GITHUB_MOCK:-} == "429" && $attempt -lt 3 ]]; then
      xedc_log WARN "GitHub 限流（测试模拟），第 ${attempt} 次后重试。"
      attempt=$((attempt + 1))
      sleep "$delay"
      delay=$((delay * 2))
      continue
    fi
    code=$(curl -sS -o "$2" -w '%{http_code}' -H "Accept: application/vnd.github+json" -H "User-Agent: xedc-recovery" "$url" || true)
    if [[ $code == 200 ]]; then
      return 0
    fi
    if [[ $code == 403 || $code == 429 ]]; then
      xedc_log WARN "GitHub HTTP ${code}，第 ${attempt} 次后重试。"
      attempt=$((attempt + 1))
      sleep "$delay"
      delay=$((delay * 2))
      continue
    fi
    xedc_warn "GitHub 请求失败，HTTP ${code}：${url}"
    return 1
  done
  xedc_warn "GitHub 请求在重试后仍失败：${url}"
  return 1
}

xedc_github_select_asset() {
  local repo=$1 mode=$2 outfile=$3
  local body release_url
  body=$(xedc_mktemp)
  case $mode in
    latest) release_url="https://api.github.com/repos/${repo}/releases/latest" ;;
    balanced|pinned)
      release_url="https://api.github.com/repos/${repo}/releases/latest"
      ;;
    *) xedc_die "未知版本策略：${mode}" ;;
  esac
  xedc_github_curl "$release_url" "$body"
  python3 - "$body" "$mode" "$outfile" <<'PY'
import json, sys
body, mode, outfile = sys.argv[1:]
data = json.load(open(body, encoding="utf-8"))
tag = data.get("tag_name") or ""
assets = data.get("assets") or []
chosen = None
for asset in assets:
    name = asset.get("name") or ""
    if name.endswith((".tar.gz", ".tgz", ".zip")):
        chosen = asset
        break
if chosen is None and assets:
    chosen = assets[0]
result = {
    "mode": mode,
    "tag": tag,
    "name": None if chosen is None else chosen.get("name"),
    "url": None if chosen is None else chosen.get("browser_download_url"),
    "size": None if chosen is None else chosen.get("size"),
    "sha256": None,
}
if mode == "pinned" and not tag:
    raise SystemExit("pinned 策略没有得到版本标签")
open(outfile, "w", encoding="utf-8").write(json.dumps(result))
PY
}

xedc_github_download_verified() {
  local url=$1 expected=$2 dest=$3
  [[ -n $expected ]] || xedc_die "缺少 SHA256，拒绝下载 ${url}"
  curl -fsSL -o "$dest" -H "User-Agent: xedc-recovery" "$url"
  local actual
  actual=$(sha256sum "$dest" | awk '{print $1}')
  if [[ $actual != "$expected" ]]; then
    rm -f "$dest"
    xedc_die "SHA256 不一致，已删除未校验文件。"
  fi
}

xedc_collect_github_software() {
  local outfile=$1
  local netdata_version="" node_version=""
  if [[ -x /opt/netdata/bin/netdata ]]; then
    netdata_version=$(/opt/netdata/bin/netdata -v 2>/dev/null | awk 'NR==1 {print $2}' || true)
  elif [[ -x /opt/netdata/usr/sbin/netdata ]]; then
    netdata_version=$(/opt/netdata/usr/sbin/netdata -v 2>/dev/null | awk 'NR==1 {print $2}' || true)
  fi
  if [[ -x /usr/bin/node ]]; then
    node_version=$(/usr/bin/node -v 2>/dev/null || true)
  fi
  jq -n \
    --arg netdata "$netdata_version" \
    --arg node "$node_version" \
    --arg php "$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)" \
    --arg nginx "$(nginx -v 2>&1 | awk '{print $3}')" \
    '{
      strategy_default: "balanced",
      items: [
        {name: "netdata", source: "github", repo: "netdata/netdata", path: "/opt/netdata", version: $netdata, install: "kickstart", required: true, arch: "x86_64"},
        {name: "nodejs", source: "nodesource", repo: "", path: "/usr/bin/node", version: $node, install: "apt", required: true},
        {name: "php", source: "sury", repo: "", path: "/usr/bin/php", version: $php, install: "apt", required: true},
        {name: "nginx", source: "nginx.org", repo: "", path: "/usr/sbin/nginx", version: $nginx, install: "apt", required: true},
        {name: "drush", source: "composer", repo: "", path: "vendor/bin/drush", version: "", install: "composer", required: true}
      ]
    }' >"$outfile"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 xedc-recovery/backup.sh 或 restore.sh 运行。" >&2
  exit 1
fi
