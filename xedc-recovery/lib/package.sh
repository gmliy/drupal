#!/usr/bin/env bash
# shellcheck shell=bash
# 把一份已经通过自检的快照打成可单独拷走的迁移包。不删除原快照。

xedc_migration_name() {
  local snapshot=$1
  printf 'xedc-migration-%s\n' "$(basename "$snapshot")"
}

xedc_migration_required_relpaths() {
  local name=$1
  cat <<EOF
${name}/restore.sh
${name}/恢复说明.md
${name}/VERSIONS.txt
${name}/snapshot/manifest.json
${name}/snapshot/checksums.sha256
${name}/snapshot/STATUS
${name}/snapshot/database.sql.zst
${name}/snapshot/files-public.tar.zst
${name}/snapshot/files-private.tar.zst
${name}/snapshot/project-source.tar.zst
${name}/snapshot/secrets.enc
${name}/snapshot/composer.lock
${name}/snapshot/system-config.tar.zst
${name}/snapshot/services-config.tar.zst
${name}/xedc-recovery/restore.sh
${name}/xedc-recovery/download.sh
${name}/xedc-recovery/lib/common.sh
${name}/xedc-recovery/lib/environment.sh
${name}/xedc-recovery/lib/database.sh
${name}/xedc-recovery/lib/drupal.sh
${name}/xedc-recovery/lib/nginx.sh
${name}/xedc-recovery/lib/ssl.sh
${name}/xedc-recovery/lib/credentials.sh
${name}/xedc-recovery/lib/services.sh
${name}/xedc-recovery/lib/media.sh
${name}/xedc-recovery/lib/files.sh
${name}/xedc-recovery/lib/domain.sh
${name}/xedc-recovery/lib/domain_replace.php
${name}/xedc-recovery/lib/rollback.sh
${name}/xedc-recovery/lib/compare.sh
${name}/xedc-recovery/lib/verify.sh
${name}/xedc-recovery/lib/github-software.sh
${name}/xedc-recovery/lib/backup.sh
${name}/xedc-recovery/lib/package.sh
${name}/xedc-recovery/lib/r2.sh
${name}/xedc-recovery/lib/settings_extract.php
${name}/xedc-recovery/lib/object_inventory.php
${name}/xedc-recovery/lib/acme-dns-providers.json
${name}/xedc-recovery/lib/redact_config.py
${name}/templates/database.txt
${name}/templates/nginx-php.txt
EOF
}

xedc_migration_write_guide() {
  local dest=$1 name=$2
  cat >"$dest" <<EOF
# XEDC 迁移包恢复说明

这个目录来自 \`${name}.tar.zst\`。它包含一份备份快照和恢复程序，不依赖原服务器上的 \`/var/www/drupal\`、Git 工作目录或环境变量。

age 私钥不在这个包里。恢复前把它放到新服务器的 \`/root/.config/xedc-recovery/age.key\`，权限 600。

包内含有数据库和业务文件。请放在私有目录，不要公开下载。

## 校验

在解压前，于压缩包所在目录执行：

\`\`\`bash
sha256sum -c ${name}.tar.zst.sha256
\`\`\`

Windows PowerShell：

\`\`\`powershell
Get-FileHash .\\${name}.tar.zst -Algorithm SHA256
\`\`\`

哈希必须与 \`.sha256\` 文件和原服务器上的值一致。不一致就不要恢复。

## 解压并恢复

\`\`\`bash
tar --zstd -xf ${name}.tar.zst
cd ${name}
sudo ./restore.sh --mode=full --domain 新域名 --old-domain 旧域名 --new-domain 新域名
\`\`\`

\`--mode=full\` 会在只有基础系统的机器上安装 Nginx、MariaDB、Composer、Docker、ffmpeg，并安装 PHP 8.5。安装不到 PHP 8.5 时会标记 BLOCKED 并停止，不会改用 PHP 8.4 或其他版本。

已有网站或同名数据库时使用：

\`\`\`bash
sudo ./restore.sh --mode=restore --domain 新域名
\`\`\`

这个模式只补缺失软件，发现已有网站或已有数据表就停止，不会覆盖。

Drupal 必须运行在 PHP 8.5。恢复后确认：

\`\`\`bash
php -v
php-fpm8.5 -v
\`\`\`

两处版本都要以 8.5 开头。Nginx 使用 \`/run/php/php8.5-fpm.sock\`。
EOF
  chmod 644 "$dest"
}

xedc_migration_write_versions() {
  local dest=$1 snapshot=$2
  {
    printf 'snapshot=%s\n' "$(basename "$snapshot")"
    if [[ -f $snapshot/manifest.json ]]; then
      jq -r '[
        "manifest_schema=\(.schema)",
        "drupal=\(.drupal.version // "")",
        "php_at_backup=\(.source.php // "")",
        "os_at_backup=\(.source.os_pretty // .source.os // "")",
        "database=\(.database.engine // "") \(.database.version // "")",
        "ffmpeg_at_backup=\(.media.ffmpeg_version // "")"
      ] | .[]' "$snapshot/manifest.json"
    fi
    printf 'packed_on=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'php_cli=%s\n' "$(php -r 'echo PHP_VERSION;' 2>/dev/null || printf missing)"
    printf 'zstd=%s\n' "$(zstd -V 2>/dev/null | awk 'NR==1 {print $0}')"
    printf 'age=%s\n' "$(age --version 2>/dev/null | awk 'NR==1 {print $0}')"
  } >"$dest"
  chmod 644 "$dest"
}

xedc_migration_public_manifest() {
  local snapshot=$1 dest=$2
  python3 - "$snapshot/manifest.json" "$dest" <<'PY'
import json, sys
src, dest = sys.argv[1:]
data = json.load(open(src, encoding="utf-8"))
drop = {
    "endpoint", "cdn_base", "bucket", "username", "password", "hash_salt",
    "root_password", "machine_id_sha256", "provider", "access_key", "secret",
    "secret_access_key", "account_id",
}

def clean(value):
    if isinstance(value, dict):
        out = {}
        for key, item in value.items():
            if key.lower() in drop or "password" in key.lower() or "secret" in key.lower():
                continue
            out[key] = clean(item)
        return out
    if isinstance(value, list):
        return [clean(item) for item in value]
    return value

public = clean(data)
public["note"] = "这份清单去掉了端点、桶名、账号和密钥字段，可随迁移包上传。私钥不在其中。"
open(dest, "w", encoding="utf-8").write(json.dumps(public, ensure_ascii=False, indent=2) + "\n")
PY
  chmod 644 "$dest"
}

xedc_migration_write_templates() {
  local snapshot=$1 dest=$2
  mkdir -p "$dest"
  jq -r '
    "engine=\(.database.engine // "")",
    "version=\(.database.version // "")",
    "charset=\(.database.charset // "")",
    "collation=\(.database.collation // "")",
    "sql_mode=\(.database.sql_mode // "")",
    "tables=\(.database.tables // "")"
  ' "$snapshot/manifest.json" >"$dest/database.txt"
  cat >"$dest/nginx-php.txt" <<'EOF'
Nginx 与 PHP-FPM 配置模板在 snapshot/system-config.tar.zst 的 nginx/ 和 php/ 目录。
恢复程序会安装 PHP 8.5，并把 Nginx 指到 /run/php/php8.5-fpm.sock。
不会把旧服务器的证书私钥复制过来。
EOF
  if zstdcat "$snapshot/services-config.tar.zst" | tar -tf - | grep -q 'matomo/compose.yaml'; then
    if ! zstdcat "$snapshot/services-config.tar.zst" | tar -C "$dest" -xf - ./matomo/compose.yaml; then
      zstdcat "$snapshot/services-config.tar.zst" | tar -C "$dest" -xf - matomo/compose.yaml
    fi
  fi
  chmod 644 "$dest/database.txt" "$dest/nginx-php.txt"
}

xedc_migration_check_tree() {
  local root=$1 name=$2
  local rel missing=0 path
  while IFS= read -r rel; do
    [[ -n $rel ]] || continue
    path="$root/$rel"
    if [[ ! -e $path ]]; then
      xedc_log FAIL "迁移包缺少 ${rel}"
      missing=1
    fi
  done < <(xedc_migration_required_relpaths "$name")
  [[ $missing -eq 0 ]] || return 1
  if find "$root/$name" \( -name 'age.key' -o -name 'r2.env' \) | grep -q .; then
    xedc_log FAIL "迁移包里出现了 age 私钥或 R2 凭据文件。"
    return 1
  fi
  local snap="$root/$name/snapshot"
  (
    cd "$snap" || exit 1
    sha256sum -c checksums.sha256
  ) >&2 || return 1
  jq -e '.schema == 2' "$snap/manifest.json" >/dev/null || return 1
  [[ $(cat "$snap/STATUS") == PASS ]] || {
    xedc_log FAIL "快照 STATUS 不是 PASS。"
    return 1
  }
  local project_list="$root/project-list.txt"
  zstdcat "$snap/project-source.tar.zst" | tar -tf - >"$project_list"
  grep -q 'composer.json' "$project_list" || return 1
  grep -q 'composer.lock' "$project_list" || return 1
  grep -q 'themes/custom' "$project_list" || return 1
  rm -f "$project_list"
  [[ -d $snap/docker-volumes ]] || {
    xedc_log FAIL "迁移包缺少 Docker 数据卷目录。"
    return 1
  }
  [[ -s $root/$name/templates/database.txt ]] || return 1
  [[ -x $root/$name/restore.sh ]] || {
    xedc_log FAIL "restore.sh 不可执行。"
    return 1
  }
  bash -n "$root/$name/restore.sh" || return 1
  bash -n "$root/$name/xedc-recovery/restore.sh" || return 1
  bash -n "$root/$name/xedc-recovery/download.sh" || return 1
  "$root/$name/restore.sh" --help >/dev/null || return 1
  local services_list="$root/services-list.txt"
  zstdcat "$snap/services-config.tar.zst" | tar -tf - >"$services_list"
  grep -q 'matomo' "$services_list" || return 1
  grep -q 'netdata' "$services_list" || return 1
  rm -f "$services_list"
  local system_list="$root/system-list.txt"
  zstdcat "$snap/system-config.tar.zst" | tar -tf - >"$system_list"
  grep -Eq 'nginx|php' "$system_list" || return 1
  rm -f "$system_list"
  return 0
}

xedc_migration_manifest_corresponds() {
  local archive=$1 manifest=$2
  local name member tmp rc
  name=$(basename "$archive" .tar.zst)
  [[ $(basename "$manifest") == "${name}.manifest.public.json" ]] || return 1
  [[ -f $archive && -f $manifest && ! -L $manifest ]] || return 1
  member="${name}/snapshot/manifest.public.json"
  tmp=$(mktemp)
  chmod 600 "$tmp"
  if ! tar --zstd -xOf "$archive" "$member" >"$tmp" 2>/dev/null; then
    rm -f "$tmp"
    return 1
  fi
  python3 - "$manifest" "$tmp" <<'PY'
import json, sys
external, internal = sys.argv[1:]
banned = {
    "password", "hash_salt", "secret", "secret_access_key", "access_key",
    "account_id", "endpoint", "bucket", "token", "cdn_base", "username",
}

def load(path):
    with open(path, encoding="utf-8") as handle:
        data = json.load(handle)
    if data.get("schema") != 2:
        raise SystemExit(1)
    return data

def walk(value, key=""):
    if isinstance(value, dict):
        for item_key, item in value.items():
            lowered = item_key.lower()
            if lowered in banned or "password" in lowered or "secret" in lowered or "token" in lowered:
                raise SystemExit(3)
            walk(item, item_key)
    elif isinstance(value, list):
        for item in value:
            walk(item, key)

outer = load(external)
inner = load(internal)
if outer != inner:
    raise SystemExit(2)
walk(outer)
PY
  rc=$?
  rm -f "$tmp"
  return "$rc"
}

xedc_migration_verify_archive() {
  local archive=$1 shafile=$2
  [[ -f $archive && -f $shafile ]] || {
    xedc_log FAIL "迁移包或校验文件不存在。"
    return 1
  }
  if ! zstd -t -q "$archive"; then
    xedc_log FAIL "归档无法通过 zstd 完整性测试。"
    return 1
  fi
  local sha_dir
  sha_dir=$(cd "$(dirname "$shafile")" && pwd)
  if ! (
    cd "$sha_dir" || exit 1
    sha256sum -c "$(basename "$shafile")"
  ) >&2; then
    xedc_log FAIL "迁移包 SHA-256 不一致。"
    return 1
  fi
  local listed
  listed=$(tar --zstd -tf "$archive" | sed 's|^\./||')
  local name rel missing=0
  name=$(basename "$archive" .tar.zst)
  while IFS= read -r rel; do
    [[ -n $rel ]] || continue
    if ! printf '%s\n' "$listed" | grep -Fxq "$rel"; then
      xedc_log FAIL "归档清单缺少 ${rel}"
      missing=1
    fi
  done < <(xedc_migration_required_relpaths "$name")
  [[ $missing -eq 0 ]] || return 1
  if printf '%s\n' "$listed" | grep -Eq '(^|/)age\.key$|(^|/)r2\.env$'; then
    xedc_log FAIL "归档清单含有私钥或 R2 凭据。"
    return 1
  fi
  local count bytes
  count=$(printf '%s\n' "$listed" | sed '/^$/d' | wc -l | tr -d ' ')
  bytes=$(stat -c %s "$archive")
  local extract
  extract=$(mktemp -d "${XEDC_BACKUP_ROOT:-/var/backups/xedc}/.verify.XXXXXX")
  chmod 700 "$extract"
  if ! tar --zstd -C "$extract" -xf "$archive"; then
    rm -rf "$extract"
    xedc_log FAIL "迁移包无法解压。"
    return 1
  fi
  if ! xedc_migration_check_tree "$extract" "$name"; then
    rm -rf "$extract"
    return 1
  fi
  local tree_files
  tree_files=$(find "$extract/$name" -type f | wc -l | tr -d ' ')
  rm -rf "$extract"
  xedc_log INFO "迁移包校验通过。大小 ${bytes} 字节，归档条目 ${count}，解压后文件 ${tree_files}。"
  printf '%s %s %s\n' "$bytes" "$count" "$tree_files"
}

xedc_migration_package() {
  local snapshot=$1
  local parent=${2:-${XEDC_BACKUP_ROOT:-/var/backups/xedc}}
  [[ -d $snapshot ]] || xedc_die "找不到快照目录。"
  [[ -f $snapshot/STATUS && $(cat "$snapshot/STATUS") == PASS ]] || xedc_die "只有 STATUS=PASS 的快照可以打包。"
  [[ -f $snapshot/manifest.json && -f $snapshot/secrets.enc && -f $snapshot/checksums.sha256 ]] || xedc_die "快照不完整，不能生成迁移包。"
  if [[ ${XEDC_RECOVERY_TEST:-0} != 1 ]]; then
    xedc_require_root
  fi
  local name archive sha stage bundle home built stats
  name=$(xedc_migration_name "$snapshot")
  mkdir -p "$parent"
  chmod 700 "$parent" 2>/dev/null || true
  local need_kb
  need_kb=$(du -sk "$snapshot" | awk '{print $1}')
  xedc_check_resources "$parent" $((need_kb + need_kb + 102400))
  archive="$parent/${name}.tar.zst"
  sha="${archive}.sha256"
  stage=$(mktemp -d "$parent/.stage.XXXXXX")
  chmod 700 "$stage"
  bundle="$stage/$name"
  mkdir -p "$bundle/snapshot" "$bundle/xedc-recovery/lib" "$bundle/templates"
  home=${XEDC_HOME:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}
  [[ -f $home/restore.sh && -f $home/download.sh && -d $home/lib ]] || xedc_die "恢复程序不完整，不能打进迁移包。"
  cp -a "$home/restore.sh" "$home/download.sh" "$bundle/xedc-recovery/"
  cp -a "$home/lib/." "$bundle/xedc-recovery/lib/"
  rm -rf "$bundle/xedc-recovery/lib/__pycache__"
  find "$bundle/xedc-recovery" \( -name 'age.key' -o -name 'r2.env' -o -name '*.pyc' \) -delete
  cat >"$bundle/restore.sh" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=\$(cd "\$(dirname "\$(readlink -f "\$0")")" && pwd)
exec "\$ROOT/xedc-recovery/restore.sh" --snapshot "\$ROOT/snapshot" "\$@"
EOF
  chmod 755 "$bundle/restore.sh" "$bundle/xedc-recovery/restore.sh" "$bundle/xedc-recovery/download.sh"
  cp -a "$snapshot/." "$bundle/snapshot/"
  printf '\n迁移包: %s\n' "$archive" >>"$bundle/snapshot/BACKUP_REPORT.md"
  xedc_migration_write_guide "$bundle/恢复说明.md" "$name"
  xedc_migration_write_versions "$bundle/VERSIONS.txt" "$bundle/snapshot"
  xedc_migration_write_templates "$bundle/snapshot" "$bundle/templates"
  xedc_migration_public_manifest "$bundle/snapshot" "$bundle/snapshot/manifest.public.json"
  built="$stage/${name}.tar.zst"
  tar --zstd -C "$stage" -cf "$built" "$name"
  (
    cd "$stage" || exit 1
    sha256sum "${name}.tar.zst" >"${name}.tar.zst.sha256"
  )
  mv -f "$built" "$archive"
  mv -f "$stage/${name}.tar.zst.sha256" "$sha"
  cp -a "$bundle/snapshot/manifest.public.json" "$parent/${name}.manifest.public.json"
  chmod 600 "$archive" "$sha"
  chmod 644 "$parent/${name}.manifest.public.json"
  rm -rf "$stage"
  if ! stats=$(XEDC_BACKUP_ROOT=$parent xedc_migration_verify_archive "$archive" "$sha"); then
    rm -f "$archive" "$sha"
    xedc_die "迁移包校验失败。原快照仍保留在 ${snapshot}。"
  fi
  printf '\n迁移包: %s\n' "$archive" >>"$snapshot/BACKUP_REPORT.md"
  printf '%s\n' "$stats" >"$parent/${name}.verify.txt"
  chmod 644 "$parent/${name}.verify.txt"
  xedc_log INFO "迁移包已生成：${archive}"
  printf '%s\n' "$archive"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 backup.sh 运行。" >&2
  exit 1
fi
