#!/usr/bin/env bash
# shellcheck shell=bash

XEDC_ACME_VERSION=3.1.6
XEDC_ACME_SHA256=0d3f9000ac44a6331314742a88c475f79134e24fc991997883652adc59efc486

xedc_ssl_decide() {
  local points_here=${1:-no} dns_ready=${2:-no} manual=${3:-no}
  if [[ $manual == yes ]]; then
    printf '%s\n' manual
  elif [[ $points_here == yes ]]; then
    printf '%s\n' http
  elif [[ $dns_ready == yes ]]; then
    printf '%s\n' dns
  else
    printf '%s\n' selfsigned-wait
  fi
}

xedc_ssl_provider_list() {
  python3 - "$XEDC_LIB/acme-dns-providers.json" <<'PY'
import json, sys
data = json.load(open(sys.argv[1], encoding="utf-8"))
aliases = {"hetzner": "hetznercloud"}
print(f"acme.sh {data['acme_version']}")
wanted = ["cf", "dp", "ali", "huaweicloud", "gd", "namecheap", "aws", "gcloud", "porkbun", "namesilo", "hetznercloud", "desec"]
by = {item["id"]: item for item in data["providers"]}
for name in wanted:
    item = by.get(name)
    if not item:
        continue
    opts = ", ".join(opt["name"] for opt in item["options"]) or "(见脚本)"
    alt = ", ".join(opt["name"] for opt in item["options_alt"])
    line = f"{name}: {opts}"
    if alt:
        line += f" | 备选 {alt}"
    print(line)
print("别名 hetzner -> hetznercloud")
print(f"共 {len(data['providers'])} 个 dnsapi 脚本，完整列表在 acme.sh dnsapi 目录。")
PY
}

xedc_public_ip() {
  curl -4 -fsS --max-time 12 https://1.1.1.1/cdn-cgi/trace | awk -F= '/^ip=/ {print $2; exit}'
}

xedc_domain_points_here() {
  local domain=$1 ip=$2 seen="" resolver
  for resolver in 1.1.1.1 8.8.8.8 223.5.5.5; do
    seen=$(dig +short "$domain" @"$resolver" 2>/dev/null | tail -n 1 || true)
    if [[ -n $seen && $seen == "$ip" ]]; then
      return 0
    fi
  done
  return 1
}

xedc_install_acme() {
  local root=/opt/xedc-acme
  if [[ -x $root/acme.sh ]]; then
    return 0
  fi
  local tar
  tar=$(xedc_mktemp)
  curl -fsSL -o "$tar" "https://github.com/acmesh-official/acme.sh/archive/refs/tags/${XEDC_ACME_VERSION}.tar.gz"
  echo "${XEDC_ACME_SHA256}  ${tar}" | sha256sum -c -
  local unpack
  unpack=$(mktemp -d)
  tar -C "$unpack" -xzf "$tar"
  mkdir -p "$root"
  cp -a "$unpack/acme.sh-${XEDC_ACME_VERSION}/." "$root/"
  rm -rf "$unpack"
  chmod 755 "$root/acme.sh"
}

xedc_self_signed() {
  local domain=$1 dest=$2
  mkdir -p "$dest"
  chmod 700 "$dest"
  openssl req -x509 -newkey rsa:2048 -sha256 -days 30 -nodes \
    -keyout "$dest/privkey.pem" \
    -out "$dest/fullchain.pem" \
    -subj "/CN=${domain}" >/dev/null 2>&1
  chmod 600 "$dest/privkey.pem"
  printf '%s\n' selfsigned >"$dest/METHOD"
  xedc_warn "已安装自签名证书。浏览器会提示不受信任。DNS 生效后可执行 --retry-ssl。"
}

xedc_ssl_wait_install() {
  local domain=$1
  cat >/etc/systemd/system/xedc-ssl-wait.service <<EOF
[Unit]
Description=Retry XEDC SSL after DNS appears
[Service]
Type=oneshot
ExecStart=${XEDC_HOME}/restore.sh --retry-ssl --domain ${domain}
EOF
  cat >/etc/systemd/system/xedc-ssl-wait.timer <<'EOF'
[Unit]
Description=Check DNS and issue XEDC certificate every 5 minutes
[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
AccuracySec=30s
[Install]
WantedBy=timers.target
EOF
  if ! xedc_have_systemd; then
    xedc_warn "没有 systemd，无法启用等待 timer。DNS 生效后请执行 restore.sh --retry-ssl --domain ${domain}"
    return 0
  fi
  systemctl daemon-reload || {
    xedc_warn "systemd 不可用，无法启用等待 timer。DNS 生效后请执行 restore.sh --retry-ssl --domain ${domain}"
    return 0
  }
  systemctl enable --now xedc-ssl-wait.timer || xedc_warn "等待 timer 未能启用。DNS 生效后请执行 restore.sh --retry-ssl --domain ${domain}"
  xedc_log INFO "已启用等待模式。请添加 A 记录后等待，或手动执行 restore.sh --retry-ssl。"
}

xedc_ssl_print_records() {
  local domain=$1 ip=$2
  [[ -n $ip ]] || ip="本机公网IP"
  xedc_log INFO "请添加 DNS：A ${domain} -> ${ip} TTL 300；A www.${domain} -> ${ip} TTL 300。生效后等待 timer 会申请证书。"
}

xedc_ssl_load_dns_env() {
  local file=${XEDC_DNS_ENV:-/root/.config/xedc-recovery/dns.env}
  [[ -f $file ]] || return 0
  local mode
  mode=$(stat -c %a "$file")
  [[ $mode == 600 || $mode == 400 ]] || xedc_die "DNS 凭据文件权限必须是 600：${file}"
  set -a
  # shellcheck disable=SC1090
  source "$file"
  set +a
}

xedc_ssl_translate() {
  local log=$1
  [[ -f $log ]] || return 0
  if grep -Eqi 'rate limit|too many requests|429' "$log"; then
    xedc_warn "证书申请被限流。等待后执行 restore.sh --retry-ssl --domain 域名。"
  elif grep -Eqi 'CAA' "$log"; then
    xedc_warn "CAA 记录不允许当前证书机构。请调整 CAA，或等待自动改用 ZeroSSL。"
  elif grep -Eqi 'connection refused|port 80|Timeout during connect|:80' "$log"; then
    xedc_warn "HTTP-01 访问 80 端口失败。检查 80 是否被占用，以及防火墙和云安全组是否放行 80。"
  elif grep -Eqi 'cloudflare|cf-ray|orange cloud' "$log"; then
    xedc_warn "CDN 可能拦截了校验。Cloudflare 源站请使用 Full (strict)，或改用 DNS-01。"
  else
    xedc_warn "证书申请失败。常见原因是解析未生效、安全组未放行 80/443，或校验记录尚未公开。日志在 ${log}"
  fi
}

xedc_cert_has_ocsp() {
  local cert=$1
  [[ -f $cert ]] || return 1
  openssl x509 -in "$cert" -noout -ocsp_uri 2>/dev/null | grep -q .
}

xedc_dns_auto_a() {
  local domain=$1 ip=$2 force=${3:-0}
  local provider=${XEDC_DNS_PROVIDER:-}
  [[ -n $ip ]] || xedc_die "无法探测公网 IP，不能自动创建 A 记录。"
  if [[ $provider != cf && $provider != cloudflare ]]; then
    xedc_die "自动创建 A 记录目前只支持 Cloudflare（--dns-provider cf）。请手动添加 A ${domain} -> ${ip}。"
  fi
  [[ -n ${CF_Token:-} && -n ${CF_Zone_ID:-} ]] || xedc_die "Cloudflare 自动 A 记录需要凭据文件中的 CF_Token 和 CF_Zone_ID。"
  [[ $domain =~ ^[A-Za-z0-9.-]+$ ]] || xedc_die "域名不合法，已停止创建 A 记录。"
  [[ $ip =~ ^[0-9.]+$ ]] || xedc_die "公网 IP 不合法，已停止创建 A 记录。"
  local -a names=("$domain")
  if [[ $domain != www.* ]]; then
    names+=("www.${domain}")
  fi
  local name
  for name in "${names[@]}"; do
    local cfg response
    cfg=$(xedc_mktemp)
    chmod 600 "$cfg"
    cat >"$cfg" <<EOF
silent
show-error
fail
header = "Authorization: Bearer ${CF_Token}"
header = "Content-Type: application/json"
EOF
    response=$(curl -K "$cfg" "https://api.cloudflare.com/client/v4/zones/${CF_Zone_ID}/dns_records?type=A&name=${name}") || {
      rm -f "$cfg"
      xedc_die "读取 Cloudflare DNS 记录失败。"
    }
    local action
    action=$(python3 -c 'import json,sys
data=json.load(sys.stdin)
if not data.get("success"):
    raise SystemExit(3)
rows=data.get("result") or []
ip=sys.argv[1]
force=sys.argv[2]=="1"
if not rows:
    print("create")
elif rows[0].get("content")==ip:
    print("keep")
elif force:
    print("update "+rows[0]["id"])
else:
    print("conflict")
' "$ip" "$force" <<<"$response") || {
      rm -f "$cfg"
      xedc_die "Cloudflare 返回了无法识别的结果。"
    }
    case $action in
      keep) xedc_log INFO "A 记录已存在：${name}" ;;
      conflict)
        rm -f "$cfg"
        xedc_die "A 记录 ${name} 已指向其他地址。确认覆盖请加 --force。"
        ;;
      create)
        local body
        body=$(xedc_mktemp)
        python3 -c 'import json,sys; json.dump({"type":"A","name":sys.argv[1],"content":sys.argv[2],"ttl":300,"proxied":False}, open(sys.argv[3],"w"))' "$name" "$ip" "$body"
        printf 'data-binary = @%s\n' "$body" >>"$cfg"
        curl -K "$cfg" -X POST "https://api.cloudflare.com/client/v4/zones/${CF_Zone_ID}/dns_records" >/dev/null || {
          rm -f "$cfg"
          xedc_die "创建 A 记录失败：${name}"
        }
        xedc_log INFO "已创建 A 记录：${name} -> ${ip}"
        ;;
      update\ *)
        local record_id=${action#update }
        local body
        body=$(xedc_mktemp)
        python3 -c 'import json,sys; json.dump({"type":"A","name":sys.argv[1],"content":sys.argv[2],"ttl":300,"proxied":False}, open(sys.argv[3],"w"))' "$name" "$ip" "$body"
        printf 'data-binary = @%s\n' "$body" >>"$cfg"
        curl -K "$cfg" -X PUT "https://api.cloudflare.com/client/v4/zones/${CF_Zone_ID}/dns_records/${record_id}" >/dev/null || {
          rm -f "$cfg"
          xedc_die "更新 A 记录失败：${name}"
        }
        xedc_log INFO "已更新 A 记录：${name} -> ${ip}"
        ;;
      *)
        rm -f "$cfg"
        xedc_die "无法处理 A 记录：${name}"
        ;;
    esac
    rm -f "$cfg"
  done
}

xedc_acme_issue_once() {
  local server=$1 domain=$2 webroot=$3 method=$4
  local home=/root/.acme.sh
  local log=/var/log/xedc-recovery/acme-issue.log
  mkdir -p /var/log/xedc-recovery
  chmod 700 /var/log/xedc-recovery
  local -a args=(--issue --home "$home" --server "$server" --keylength 2048 -d "$domain")
  if [[ $domain != www.* && $domain != *.*.*.* ]]; then
    args+=(-d "www.${domain}")
  fi
  if [[ ${XEDC_SSL_WILDCARD:-0} == 1 ]]; then
    [[ $method != http ]] || xedc_die "通配符证书不能使用 HTTP-01。"
    args+=(-d "*.${domain}")
  fi
  case $method in
    http) args+=(--webroot "$webroot") ;;
    dns)
      local provider=${XEDC_DNS_PROVIDER:-}
      [[ -n $provider ]] || xedc_die "DNS-01 需要 --dns-provider。"
      [[ $provider == hetzner ]] && provider=hetznercloud
      args+=(--dns "dns_${provider}")
      ;;
    manual)
      xedc_log INFO "手动 DNS-01：acme.sh 会输出 _acme-challenge TXT。请按输出添加记录，脚本会等待公共 DNS。"
      args+=(--dns --yes-I-know-dns-manual-mode-enough-go-ahead-please --dnssleep "${XEDC_DNS_SLEEP:-180}")
      ;;
    *) xedc_die "未知证书方式：${method}" ;;
  esac
  if /opt/xedc-acme/acme.sh "${args[@]}" 2>&1 | tee "$log"; then
    chmod 600 "$log" || true
    return 0
  fi
  chmod 600 "$log" || true
  xedc_ssl_translate "$log"
  return 1
}

xedc_ssl_issue() {
  local domain=$1 webroot=$2 method=$3
  xedc_install_acme
  local server=${XEDC_SSL_SERVER:-letsencrypt}
  if xedc_acme_issue_once "$server" "$domain" "$webroot" "$method"; then
    mkdir -p /root/.acme.sh
    printf '%s\n' "$method" >"/root/.acme.sh/${domain}.method"
    chmod 600 "/root/.acme.sh/${domain}.method"
    return 0
  fi
  if [[ $server == letsencrypt ]]; then
    xedc_warn "Let's Encrypt 签发失败，改用 ZeroSSL。"
    if xedc_acme_issue_once zerossl "$domain" "$webroot" "$method"; then
      printf '%s\n' "$method" >"/root/.acme.sh/${domain}.method"
      chmod 600 "/root/.acme.sh/${domain}.method"
      return 0
    fi
  fi
  return 1
}

xedc_ssl_install_cert() {
  local domain=$1 dest=$2
  mkdir -p "$dest"
  chmod 700 "$dest"
  /opt/xedc-acme/acme.sh --install-cert -d "$domain" --home /root/.acme.sh \
    --key-file "$dest/privkey.pem" \
    --fullchain-file "$dest/fullchain.pem" \
    --reloadcmd "nginx -t && systemctl reload nginx"
  chmod 600 "$dest/privkey.pem"
}

xedc_ssl_install_cron() {
  /opt/xedc-acme/acme.sh --install-cronjob --home /root/.acme.sh
}

xedc_ssl_renew() {
  local domain=$1
  xedc_install_acme
  local log=/var/log/xedc-recovery/acme-renew.log
  if /opt/xedc-acme/acme.sh --renew -d "$domain" --home /root/.acme.sh 2>&1 | tee "$log"; then
    chmod 600 "$log" || true
    return 0
  fi
  chmod 600 "$log" || true
  xedc_ssl_translate "$log"
  return 1
}

xedc_ssl_choose_method() {
  local domain=$1
  if [[ -n ${SSL_METHOD:-} ]]; then
    printf '%s\n' "$SSL_METHOD"
    return 0
  fi
  local ip="" points=no dns_ready=no
  ip=$(xedc_public_ip || true)
  if [[ -n $ip ]] && xedc_domain_points_here "$domain" "$ip"; then
    points=yes
  fi
  if [[ -n ${XEDC_DNS_PROVIDER:-} ]]; then
    dns_ready=yes
  fi
  xedc_ssl_decide "$points" "$dns_ready" no
}

xedc_ssl_run() {
  local domain=$1 webroot=$2 certdir=$3
  xedc_ssl_load_dns_env
  export XEDC_DNS_PROVIDER=${DNS_PROVIDER:-${XEDC_DNS_PROVIDER:-}}
  local method ip=""
  method=$(xedc_ssl_choose_method "$domain")
  xedc_log INFO "证书方式：${method}"
  if dig +short NS "$domain" 2>/dev/null | grep -qi cloudflare; then
    xedc_warn "域名 NS 指向 Cloudflare。源站 HTTPS 请使用 Full (strict)。HTTP-01 需要源站 80 可从代理访问，否则用 DNS-01。"
  fi
  if [[ ${AUTO_DNS:-0} == 1 ]]; then
    ip=$(xedc_public_ip || true)
    xedc_dns_auto_a "$domain" "$ip" "${FORCE:-0}"
  fi
  mkdir -p "$certdir"
  case $method in
    selfsigned-wait)
      ip=$(xedc_public_ip || true)
      xedc_self_signed "$domain" "$certdir"
      xedc_ssl_print_records "$domain" "${ip}"
      xedc_ssl_wait_install "$domain"
      ;;
    http|dns|manual)
      if xedc_ssl_issue "$domain" "$webroot" "$method"; then
        xedc_ssl_install_cert "$domain" "$certdir"
        xedc_ssl_install_cron || xedc_warn "自动续期任务未能安装。"
        if command -v systemctl >/dev/null 2>&1; then
          systemctl disable --now xedc-ssl-wait.timer >/dev/null 2>&1 || true
        fi
        printf '%s\n' "$method" >"$certdir/METHOD"
      else
        xedc_warn "证书申请失败，保留自签名并进入等待模式。"
        xedc_self_signed "$domain" "$certdir"
        ip=$(xedc_public_ip || true)
        xedc_ssl_print_records "$domain" "${ip}"
        xedc_ssl_wait_install "$domain"
      fi
      ;;
    *)
      xedc_die "未知证书方式：${method}"
      ;;
  esac
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 restore.sh 运行。" >&2
  exit 1
fi
