#!/usr/bin/env bash
# shellcheck shell=bash

xedc_domain_replace() {
  local cnf=$1 database=$2 old=$3 new=$4 report=$5
  local snapshot dry=() dump_bin=mysqldump
  snapshot=$(mktemp)
  chmod 600 "$snapshot"
  if command -v mariadb-dump >/dev/null 2>&1; then
    dump_bin=mariadb-dump
  fi
  if [[ ${XEDC_DRY_RUN:-0} == 1 ]]; then
    dry=(--dry-run)
  else
    "$dump_bin" --defaults-extra-file="$cnf" --single-transaction --no-tablespaces --default-character-set=utf8mb4 "$database" | zstd -T2 -q -o "$snapshot"
  fi
  if ! php "$XEDC_LIB/domain_replace.php" \
    --defaults-file "$cnf" \
    --database "$database" \
    --old "$old" \
    --new "$new" \
    --report "$report" \
    "${dry[@]}"; then
    if [[ ${XEDC_DRY_RUN:-0} != 1 && -s $snapshot ]]; then
      xedc_warn "域名替换失败，正在回滚数据库。"
      zstdcat "$snapshot" | mariadb --defaults-extra-file="$cnf" "$database"
    fi
    rm -f "$snapshot"
    return 1
  fi
  rm -f "$snapshot"
}

xedc_scan_hardcode() {
  local root=$1 output=$2 domain=$3
  python3 - "$root" "$output" "$domain" <<'PY'
import os, re, sys
root, output, domain = sys.argv[1:]
pattern = re.compile(re.escape(domain), re.I)
skip = {".git", "vendor", "node_modules", "web/core", "web/modules/contrib", "web/themes/contrib", "_server", "files"}
rows = []
for dirpath, dirnames, filenames in os.walk(root):
    rel_dir = os.path.relpath(dirpath, root)
    dirnames[:] = [name for name in dirnames if os.path.join(rel_dir, name).strip("./") not in skip and name not in skip]
    for name in filenames:
        if not name.endswith((".php", ".twig", ".js", ".css", ".yml", ".yaml", ".module", ".theme")):
            continue
        path = os.path.join(dirpath, name)
        if os.path.getsize(path) > 2_000_000:
            continue
        try:
            lines = open(path, encoding="utf-8", errors="replace").read().splitlines()
        except OSError:
            continue
        for number, line in enumerate(lines, 1):
            if pattern.search(line):
                rel = os.path.relpath(path, root)
                rows.append((rel, number))
                if len(rows) >= 400:
                    break
    if len(rows) >= 400:
        break
with open(output, "w", encoding="utf-8") as handle:
    handle.write("# 域名硬编码\n\n")
    handle.write("本轮只列出位置和建议，不改主题和模块业务代码。\n\n")
    handle.write("| 文件 | 行 | 建议 |\n| --- | --- | --- |\n")
    for rel, number in rows:
        handle.write(f"| `{rel}` | {number} | Twig 使用 path()/url()，PHP 使用 Url API，配置放 settings |\n")
    if not rows:
        handle.write("| （无） |  | 未在代码中发现该域名 |\n")
PY
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 restore.sh 运行。" >&2
  exit 1
fi
