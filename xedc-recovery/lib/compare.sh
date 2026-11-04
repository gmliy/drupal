#!/usr/bin/env bash
# shellcheck shell=bash

xedc_compare_write() {
  local manifest=$1 live=$2 output=$3
  python3 - "$manifest" "$live" "$output" <<'PY'
import json, sys
manifest = json.load(open(sys.argv[1], encoding="utf-8"))
live = json.load(open(sys.argv[2], encoding="utf-8"))
output = sys.argv[3]
rows = []

def add(name, left, right, kind):
    status = "相同" if left == right else kind
    rows.append((name, left, right, status))

pairs = [
    ("Drupal", manifest.get("source", {}).get("php"), live.get("drupal_version"), "异常差异"),
    ("PHP", manifest.get("source", {}).get("php"), live.get("php"), "预期差异"),
    ("公共文件数", manifest.get("files", {}).get("public_files"), live.get("public_files"), "异常差异"),
    ("数据表数", manifest.get("database", {}).get("tables"), live.get("tables"), "异常差异"),
    ("ffmpeg", manifest.get("media", {}).get("ffmpeg_version"), live.get("ffmpeg"), "需要用户处理"),
    ("对象数量", sum(item.get("count", 0) for item in manifest.get("object_storage", {}).get("prefixes", [])), live.get("object_count"), "异常差异"),
]
for name, left, right, kind in pairs:
    if left in (None, "", "unknown") and right in (None, "", "unknown"):
        rows.append((name, left, right, "SKIP"))
    else:
        add(name, left, right, kind)
anomaly = [row for row in rows if row[3] == "异常差异"]
lines = ["# 一致性对账", "", "| 项目 | 备份 | 恢复后 | 分类 |", "| --- | --- | --- | --- |"]
for name, left, right, status in rows:
    lines.append(f"| {name} | {left} | {right} | {status} |")
lines.append("")
lines.append(f"异常差异: {len(anomaly)}")
if anomaly:
    lines.append("")
    lines.append("异常差异需要处理，不能把恢复标记为通过。")
open(output, "w", encoding="utf-8").write("\n".join(lines) + "\n")
raise SystemExit(1 if anomaly else 0)
PY
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
  printf '%s\n' "请通过 restore.sh --compare 运行。" >&2
  exit 1
fi
