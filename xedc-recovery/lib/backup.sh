#!/usr/bin/env bash
# shellcheck shell=bash

xedc_backup_usage() {
  cat <<'EOF'
用法:
  backup.sh [--dry-run] [--output 目录] [--upload-r2]
  backup.sh --package-snapshot 快照目录 [--upload-r2]

每次运行都会重新扫描并生成一份新的时间戳快照，不覆盖上一份成功备份。
成功后自动生成 /var/backups/xedc/xedc-migration-时间.tar.zst 和同名 .sha256。
--upload-r2 把迁移包传到专用私有 Bucket。配置见 /root/.config/xedc-recovery/r2.env。
备份写到 /var/backups/xedc，不会进入 Git。
EOF
}

xedc_age_prepare() {
  local keydir=${XEDC_AGE_DIR:-/root/.config/xedc-recovery}
  XEDC_AGE_KEY=${XEDC_AGE_KEY:-$keydir/age.key}
  XEDC_AGE_PUB=${XEDC_AGE_PUB:-$XEDC_BACKUP_ROOT/age.pub}
  mkdir -p "$keydir" "$(dirname "$XEDC_AGE_PUB")"
  chmod 700 "$keydir"
  if [[ ! -s $XEDC_AGE_KEY ]]; then
    age-keygen -o "$XEDC_AGE_KEY" >/dev/null 2>&1 || xedc_die "无法生成 age 密钥。"
    chmod 600 "$XEDC_AGE_KEY"
  fi
  age-keygen -y "$XEDC_AGE_KEY" >"${XEDC_AGE_PUB}.tmp"
  if [[ -f $XEDC_AGE_PUB ]] && ! cmp -s "$XEDC_AGE_PUB" "${XEDC_AGE_PUB}.tmp"; then
    rm -f "${XEDC_AGE_PUB}.tmp"
    xedc_die "age 公钥与私钥不匹配。为避免把备份加密给错误的密钥，已停止。"
  fi
  mv "${XEDC_AGE_PUB}.tmp" "$XEDC_AGE_PUB"
  chmod 644 "$XEDC_AGE_PUB"
}

xedc_backup_encrypt_secrets() {
  local workdir=$1 outfile=$2
  local pub
  pub=$(tr -d '[:space:]' <"$XEDC_AGE_PUB")
  tar -C "$workdir" -cf - . | age -r "$pub" -o "$outfile"
  local check
  check=$(mktemp -d)
  chmod 700 "$check"
  if ! age -d -i "$XEDC_AGE_KEY" -o "$check/secrets.tar" "$outfile"; then
    rm -rf "$check"
    xedc_die "secrets 加密后无法用私钥解密，备份不能标记为成功。"
  fi
  tar -tf "$check/secrets.tar" | grep -q 'db.json'
  rm -rf "$check"
}

xedc_backup_self_test() {
  local dest=$1
  (
    cd "$dest" || exit 1
    sha256sum -c checksums.sha256
  )
  local archive
  for archive in "$dest"/*.zst "$dest"/docker-volumes/*.zst; do
    [[ -f $archive ]] || continue
    zstd -t -q "$archive"
  done
  if ! zstdcat "$dest/database.sql.zst" | tail -n 30 | grep -q "Dump completed"; then
    xedc_die "数据库导出不完整。"
  fi
  jq -e '.schema == 2 and .database.database != null' "$dest/manifest.json" >/dev/null
  local listed expected
  listed=$(zstdcat "$dest/files-public.tar.zst" | tar -tf - | wc -l | tr -d ' ')
  expected=$(jq -r '.files.public_files' "$dest/manifest.json")
  if [[ $listed -lt $expected ]]; then
    xedc_die "公共文件归档数量 ${listed} 小于扫描数量 ${expected}。"
  fi
  if ! cmp -s "$XEDC_SITE_ROOT/composer.lock" "$dest/composer.lock"; then
    xedc_die "composer.lock 与站点不一致。"
  fi
}

xedc_backup_checksums() {
  local dest=$1
  (
    cd "$dest" || exit 1
    find . -type f ! -name checksums.sha256 ! -name BACKUP_REPORT.md ! -name STATUS -print0 \
      | sort -z | xargs -0 sha256sum > checksums.sha256
  )
  chmod 600 "$dest/checksums.sha256"
}

xedc_backup_offsite() {
  local dest=$1
  [[ -n ${XEDC_OFFSITE_DEST:-} ]] || return 0
  local name remote
  name=$(basename "$dest")
  remote=${XEDC_OFFSITE_DEST%/}/$name
  mkdir -p "$remote"
  rsync -a "$dest/" "$remote/"
  xedc_log INFO "已复制到异地目录 ${remote}。age 私钥不在这份副本里。"
}

xedc_backup_retain() {
  local root=$1 keep=${2:-14}
  local -a ok=()
  local dir
  for dir in "$root"/*; do
    [[ -f $dir/STATUS && $(cat "$dir/STATUS") == PASS ]] || continue
    ok+=("$dir")
  done
  local count=${#ok[@]}
  if [[ $count -le $keep ]]; then
    return 0
  fi
  local extra=$((count - keep))
  local index=0
  while [[ $index -lt $extra && $((count - index)) -gt 1 ]]; do
    xedc_log INFO "按保留策略删除旧备份 $(basename "${ok[$index]}")"
    rm -rf "${ok[$index]}"
    index=$((index + 1))
  done
}

xedc_backup_main() {
  local output=${1:-}
  xedc_require_root
  xedc_have age || xedc_die "缺少 age。"
  xedc_have zstd || xedc_die "缺少 zstd。"
  xedc_have jq || xedc_die "缺少 jq。"
  xedc_have php || xedc_die "缺少 php。"
  [[ -f $XEDC_SITE_ROOT/web/sites/default/settings.php ]] || xedc_die "找不到 Drupal settings.php。"
  [[ -f $XEDC_SITE_ROOT/composer.lock ]] || xedc_die "找不到 composer.lock。"
  local parent=${output:-$XEDC_BACKUP_ROOT}
  mkdir -p "$parent"
  xedc_check_resources "$parent" 8388608
  if [[ ${XEDC_DRY_RUN:-0} == 1 ]]; then
    xedc_log INFO "dry-run: 将在 ${parent} 创建新快照，包含数据库、文件、服务配置和加密密钥。"
    return 0
  fi
  xedc_lock "$XEDC_STATE_ROOT/backup.lock"
  xedc_age_prepare
  local stamp dest
  stamp=$(date -u +%Y%m%dT%H%M%SZ)
  dest="$parent/$stamp"
  mkdir -p "$dest"
  chmod 700 "$dest"
  export XEDC_DEST=$dest
  XEDC_SECRET_WORKDIR=$(mktemp -d)
  chmod 700 "$XEDC_SECRET_WORKDIR"
  local facts cnf
  facts=$(mktemp -d)
  chmod 700 "$facts"
  cnf=$(xedc_mktemp)

  php "$XEDC_LIB/settings_extract.php" \
    --source "$XEDC_SITE_ROOT/web/sites/default/settings.php" \
    --public "$facts/settings-public.json" \
    --secrets "$XEDC_SECRET_WORKDIR/db.json" \
    --redacted "$XEDC_SECRET_WORKDIR/settings.redacted.php" >/dev/null
  # shellcheck disable=SC1090
  local database
  database=$(jq -r '.database' "$facts/settings-public.json")
  xedc_write_client_cnf "$XEDC_SECRET_WORKDIR/db.json" "$cnf"
  if ! php "$XEDC_LIB/object_inventory.php" --site-root "$XEDC_SITE_ROOT" --output "$facts/object_storage.json"; then
    xedc_die "对象存储清点失败。"
  fi
  if [[ -n ${XEDC_SQL_DUMP_FILE:-} ]]; then
    local fixture_tables=0
    if [[ -f $XEDC_SQL_DUMP_FILE && $XEDC_SQL_DUMP_FILE != *.zst ]]; then
      fixture_tables=$(grep -c 'CREATE TABLE' "$XEDC_SQL_DUMP_FILE" || true)
    fi
    [[ $fixture_tables =~ ^[0-9]+$ ]] || fixture_tables=0
    cat >"$facts/database-server.txt" <<EOF
version=fixture
charset=utf8mb4
collation=utf8mb4_unicode_ci
sql_mode=STRICT_TRANS_TABLES
engine=InnoDB:${fixture_tables}
tables=${fixture_tables}
routines=0
triggers=0
EOF
    xedc_log INFO "使用测试 SQL 文件，不读取本机数据库服务器信息。"
  else
    xedc_database_server_facts "$database" "$facts/database-server.txt" "$cnf"
  fi
  xedc_dump_database "$cnf" "$database" "$dest/database.sql.zst"

  local public_dir private_dir public_count public_bytes private_count private_bytes
  public_dir=$XEDC_SITE_ROOT/web/sites/default/files
  private_dir=$(jq -r '.private_path // empty' "$facts/settings-public.json")
  if [[ -z $private_dir && -d $XEDC_SITE_ROOT/private ]]; then
    private_dir=$XEDC_SITE_ROOT/private
  fi
  read -r public_count public_bytes < <(xedc_count_tree "$public_dir")
  xedc_archive_dir "$public_dir" "$dest/files-public.tar.zst"
  if [[ -n $private_dir && -d $private_dir ]]; then
    mkdir -p "$XEDC_SECRET_WORKDIR/storage-keys" "$XEDC_SECRET_WORKDIR/private-settings" "$XEDC_SECRET_WORKDIR/private-keys"
    if [[ -d $private_dir/xedc-storage-keys ]]; then
      cp -a "$private_dir/xedc-storage-keys" "$XEDC_SECRET_WORKDIR/storage-keys"
    fi
    while IFS= read -r -d '' secret_file; do
      rel=${secret_file#"$private_dir"/}
      mkdir -p "$XEDC_SECRET_WORKDIR/private-settings/$(dirname "$rel")"
      cp -a "$secret_file" "$XEDC_SECRET_WORKDIR/private-settings/$rel"
    done < <(find "$private_dir" -type f -name 'settings.php*' -print0)
    while IFS= read -r -d '' secret_file; do
      rel=${secret_file#"$private_dir"/}
      mkdir -p "$XEDC_SECRET_WORKDIR/private-keys/$(dirname "$rel")"
      cp -a "$secret_file" "$XEDC_SECRET_WORKDIR/private-keys/$rel"
    done < <(find "$private_dir" -type f \( -name '*.key' -o -path '*/xedc-ai-keys/*' \) -print0)
    xedc_archive_private "$private_dir" "$dest/files-private.tar.zst"
    read -r private_count private_bytes < <(xedc_count_private_archive "$private_dir")
  else
    private_count=0
    private_bytes=0
    printf '' | zstd -T2 -q -o "$dest/files-private.tar.zst"
    xedc_warn "没有私有文件目录。"
  fi

  local overlay=$facts/overlay
  mkdir -p "$overlay/web/sites/default" "$overlay/config"
  cp "$XEDC_SECRET_WORKDIR/settings.redacted.php" "$overlay/web/sites/default/settings.php"
  if [[ -d $XEDC_SITE_ROOT/config/sync ]]; then
    rm -rf "$overlay/config/sync"
    cp -a "$XEDC_SITE_ROOT/config/sync" "$overlay/config/"
    python3 "$XEDC_LIB/redact_config.py" \
      "$overlay/config/sync" \
      "$XEDC_SECRET_WORKDIR/config-sync" \
      INPLACE >/dev/null
  fi
  xedc_archive_project "$XEDC_SITE_ROOT" "$dest/project-source.tar.zst" "$overlay"
  cp -a "$XEDC_SITE_ROOT/composer.lock" "$dest/composer.lock"
  chmod 600 "$dest/composer.lock"

  mkdir -p "$facts/system/nginx" "$facts/system/php" "$facts/services"
  cp -a /etc/nginx/conf.d "$facts/system/nginx/" 2>/dev/null || true
  cp -a /etc/nginx/mime.types "$facts/system/nginx/" 2>/dev/null || true
  if [[ -d /etc/php ]]; then
    cp -a /etc/php "$facts/system/php/php"
  fi
  xedc_tar_to_zstd "$dest/system-config.tar.zst" -C "$facts/system" -cf - .
  xedc_collect_services "$facts/services" "$XEDC_SECRET_WORKDIR"
  xedc_tar_to_zstd "$dest/services-config.tar.zst" -C "$facts/services" -cf - .
  if [[ ${XEDC_SKIP_DOCKER:-0} != 1 ]]; then
    xedc_export_docker_volumes "$dest/docker-volumes" || xedc_die "Docker 数据卷导出失败。"
  else
    mkdir -p "$dest/docker-volumes"
    printf '%s\n' "skipped by test" >"$dest/docker-volumes/SKIP.txt"
  fi

  xedc_media_facts "$facts/media.json"
  xedc_collect_github_software "$facts/github.json"
  cp "$facts/github.json" "$dest/github-software.json"
  chmod 600 "$dest/github-software.json"

  local version charset collation sql_mode engines tables routines triggers
  version=$(xedc_database_fact "$facts/database-server.txt" version)
  charset=$(xedc_database_fact "$facts/database-server.txt" charset)
  collation=$(xedc_database_fact "$facts/database-server.txt" collation)
  sql_mode=$(xedc_database_fact "$facts/database-server.txt" sql_mode)
  engines=$(xedc_database_engines "$facts/database-server.txt")
  tables=$(xedc_database_fact "$facts/database-server.txt" tables)
  routines=$(xedc_database_fact "$facts/database-server.txt" routines)
  triggers=$(xedc_database_fact "$facts/database-server.txt" triggers)
  [[ $tables =~ ^[0-9]+$ ]] || tables=0
  [[ $routines =~ ^[0-9]+$ ]] || routines=0
  [[ $triggers =~ ^[0-9]+$ ]] || triggers=0
  xedc_os_info
  local drupal_version
  drupal_version=$(php -r '
$lock = json_decode((string) file_get_contents($argv[1]), true);
foreach (($lock["packages"] ?? []) as $package) {
    if (($package["name"] ?? "") === "drupal/core") {
        echo $package["version"] ?? "";
        exit;
    }
}
' "$XEDC_SITE_ROOT/composer.lock")
  jq -n \
    --arg engine "MariaDB" \
    --arg version "$version" \
    --arg name "$database" \
    --arg charset "$charset" \
    --arg collation "$collation" \
    --arg sql_mode "$sql_mode" \
    --arg engines "$engines" \
    --argjson tables "$tables" \
    --argjson routines "$routines" \
    --argjson triggers "$triggers" \
    --argjson public_files "$public_count" \
    --argjson public_bytes "$public_bytes" \
    --argjson private_files "$private_count" \
    --argjson private_bytes "$private_bytes" \
    --arg machine "$(xedc_machine_hash)" \
    --arg php "$(php -r 'echo PHP_VERSION;')" \
    --arg os "${XEDC_OS_ID} ${XEDC_OS_VERSION}" \
    --arg ospretty "$XEDC_OS_PRETTY" \
    --arg arch "$XEDC_OS_ARCH" \
    --arg drupal "$drupal_version" \
    '{
      drupal: {version:$drupal},
      database: {engine:$engine, version:$version, database:$name, charset:$charset, collation:$collation, sql_mode:$sql_mode, engines:$engines, tables:$tables, routines:$routines, triggers:$triggers, root_password:"not-stored"},
      files: {public_files:$public_files, public_bytes:$public_bytes, private_files:$private_files, private_bytes:$private_bytes},
      source: {machine_id_sha256:$machine, php:$php, os:$os, os_pretty:$ospretty, arch:$arch},
      warnings: ["公共文件和私有文件在站点运行期间打包，不是冻结快照。","Docker 数据卷是在容器运行时做的文件级复制。"]
    }' >"$facts/summary.json"
  python3 - "$facts" "$dest/manifest.json" <<'PY'
import json, sys
from datetime import datetime, timezone
fact_dir, output = sys.argv[1:]
manifest = {
    "schema": 2,
    "created": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    "warnings": [],
}
for name in ("summary", "object_storage", "media", "settings-public", "github"):
    path = f"{fact_dir}/{name}.json"
    try:
        data = json.load(open(path, encoding="utf-8"))
    except FileNotFoundError:
        continue
    warnings = data.pop("warnings", []) if isinstance(data, dict) else []
    manifest["warnings"].extend(warnings)
    key = name.replace("-", "_")
    if key == "summary":
        manifest.update(data)
    else:
        manifest[key] = data
open(output, "w", encoding="utf-8").write(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")
PY
  chmod 600 "$dest/manifest.json"

  python3 - "$dest/files-private.tar.zst" "$dest/project-source.tar.zst" "$XEDC_SECRET_WORKDIR/db.json" <<'PY'
import json, os, subprocess, sys, tarfile
archives = sys.argv[1:-1]
secret_file = sys.argv[-1]
secret_dir = os.path.dirname(secret_file)
secrets = json.load(open(secret_file, encoding="utf-8"))
needles = [secrets.get("password") or "", secrets.get("hash_salt") or ""]
needles = [item.encode() for item in needles if len(item) >= 8]
for folder in ("storage-keys", "private-settings", "private-keys"):
    root = os.path.join(secret_dir, folder)
    for dirpath, _, filenames in os.walk(root):
        for filename in filenames:
            path = os.path.join(dirpath, filename)
            if os.path.getsize(path) > 200_000:
                continue
            data = open(path, "rb").read()
            if len(data) >= 8:
                needles.append(data)
overlap = max((len(item) for item in needles), default=1) - 1

def scan(archive):
    proc = subprocess.Popen(["zstdcat", archive], stdout=subprocess.PIPE)
    try:
        tar = tarfile.open(fileobj=proc.stdout, mode="r|")
        for member in tar:
            if not member.isfile():
                continue
            handle = tar.extractfile(member)
            if handle is None:
                continue
            previous = b""
            while True:
                chunk = handle.read(1024 * 1024)
                if not chunk:
                    break
                data = previous + chunk
                if any(needle in data for needle in needles):
                    raise SystemExit(f"归档仍包含敏感配置: {os.path.basename(archive)} {member.name}")
                previous = data[-overlap:] if overlap > 0 else b""
    except tarfile.TarError:
        if os.path.getsize(archive) >= 64:
            raise SystemExit(f"无法读取归档: {os.path.basename(archive)}")
    finally:
        if proc.stdout:
            proc.stdout.close()
        proc.wait()

for archive in archives:
    scan(archive)
PY
  xedc_backup_encrypt_secrets "$XEDC_SECRET_WORKDIR" "$dest/secrets.enc"
  rm -rf "$XEDC_SECRET_WORKDIR"
  XEDC_SECRET_WORKDIR=
  chmod 600 "$dest/secrets.enc"
  xedc_backup_checksums "$dest"
  xedc_backup_self_test "$dest"
  cat >"$dest/BACKUP_REPORT.md" <<EOF
# 备份报告

结果: PASS
目录: ${dest}
时间: ${stamp}
数据库: ${database}
公共文件: ${public_count}
私有文件: ${private_count}
警告数: ${XEDC_WARN_COUNT}

密钥已用 age 加密。私钥不在这个目录里。
对象存储和 Docker 卷的细节见 manifest.json。Docker 卷是运行中的文件级复制，不是事务快照。
EOF
  chmod 600 "$dest/BACKUP_REPORT.md"
  printf '%s\n' PASS >"$dest/STATUS"
  chmod 600 "$dest/STATUS"
  export XEDC_BACKUP_OK=1
  xedc_backup_offsite "$dest"
  xedc_backup_retain "$parent" 14
  local package_path=""
  xedc_migration_package "$dest" "$parent" >"$XEDC_STATE_ROOT/last-package-path"
  package_path=$(cat "$XEDC_STATE_ROOT/last-package-path")
  [[ -f $package_path ]] || xedc_die "迁移包生成失败。分项快照已保留：${dest}"
  if [[ ${XEDC_UPLOAD_R2:-0} == 1 ]]; then
    xedc_r2_upload_package "$package_path" || xedc_die "R2 上传失败。分项快照和本地迁移包已保留。"
  fi
  xedc_log INFO "备份完成：${dest}"
  xedc_log INFO "迁移包：${package_path}"
  printf '%s\n' "$dest"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 xedc-recovery/backup.sh 运行。" >&2
  exit 1
fi
