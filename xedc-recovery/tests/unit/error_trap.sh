#!/usr/bin/env bash
# 隔离验证失败诊断。不运行生产备份，不连接数据库。
set -Eeuo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
fail=0

pass() { printf 'PASS %s\n' "$1"; }
bad() { printf 'FAIL %s\n' "$1"; fail=1; }

run_script() {
  local body=$1
  local dir rc
  dir=$(mktemp -d)
  rc=0
  cat >"$dir/run.sh" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
export XEDC_LOG_DIR=$dir
export XEDC_STATE_ROOT=$dir/state
export XEDC_BACKUP_ROOT=$dir/backup
export XEDC_LOG=$dir/recovery.log
# shellcheck source=lib/common.sh
source "$ROOT/lib/common.sh"
xedc_init
$body
EOF
  chmod 700 "$dir/run.sh"
  bash "$dir/run.sh" >"$dir/out" 2>"$dir/err" || rc=$?
  RUN_RC=$rc
  RUN_DIR=$dir
}

assert_one_stage() {
  local name=$1
  local count
  count=$(grep -c '阶段=' "$RUN_DIR/recovery.log" || true)
  if [[ $count == 1 ]]; then
    pass "$name"
  else
    bad "$name count=$count"
  fi
}

run_script 'xedc_set_phase backup
false'
line=$(awk '/^false$/ {print NR; exit}' "$RUN_DIR/run.sh")
if [[ $RUN_RC -eq 1 ]] && grep -q "阶段=backup" "$RUN_DIR/recovery.log" && grep -q "退出码=1" "$RUN_DIR/recovery.log" && grep -q "行=${line}" "$RUN_DIR/recovery.log" && grep -q "命令=false" "$RUN_DIR/recovery.log" && grep -q "函数=main" "$RUN_DIR/recovery.log"; then
  pass simple-command
else
  bad simple-command
  cat "$RUN_DIR/recovery.log" >&2 || true
fi
assert_one_stage simple-once
rm -rf "$RUN_DIR"

run_script 'xedc_set_phase backup
false | true'
if [[ $RUN_RC -eq 1 ]] && grep -q '管道=1 0' "$RUN_DIR/recovery.log" && grep -q '阶段=backup' "$RUN_DIR/recovery.log"; then
  pass pipeline
else
  bad pipeline
  cat "$RUN_DIR/recovery.log" >&2 || true
fi
assert_one_stage pipeline-once
rm -rf "$RUN_DIR"

run_script 'xedc_set_phase package
x=$(false)'
if [[ $RUN_RC -eq 1 ]] && grep -q '命令=false' "$RUN_DIR/recovery.log" && grep -q '阶段=package' "$RUN_DIR/recovery.log"; then
  pass command-substitution
else
  bad command-substitution
  cat "$RUN_DIR/recovery.log" >&2 || true
fi
assert_one_stage command-substitution-once
rm -rf "$RUN_DIR"

run_script 'inner() { false; }
xedc_set_phase secrets
inner'
if [[ $RUN_RC -eq 1 ]] && grep -q '函数=inner' "$RUN_DIR/recovery.log" && grep -q '命令=false' "$RUN_DIR/recovery.log" && grep -q '阶段=secrets' "$RUN_DIR/recovery.log"; then
  pass subfunction
else
  bad subfunction
  cat "$RUN_DIR/recovery.log" >&2 || true
fi
assert_one_stage subfunction-once
rm -rf "$RUN_DIR"

run_script 'xedc_set_phase r2-upload
false'
if [[ $RUN_RC -eq 1 ]] && grep -q '阶段=r2-upload' "$RUN_DIR/recovery.log"; then
  pass r2-phase
else
  bad r2-phase
fi
rm -rf "$RUN_DIR"

run_script 'xedc_cleanup_temps() { return 1; }
inner() { return 3; }
xedc_set_phase backup
inner'
if [[ $RUN_RC -eq 3 ]]; then
  pass cleanup-keeps-rc
else
  bad cleanup-keeps-rc rc=$RUN_RC
fi
rm -rf "$RUN_DIR"

run_script 'count=$XEDC_LOG_DIR/calls
xedc_log() { printf x >>"$count"; return 1; }
xedc_set_phase backup
false'
calls=$(wc -c <"$RUN_DIR/calls" | tr -d ' ')
if [[ $RUN_RC -eq 1 && $calls -le 2 ]]; then
  pass no-recursion
else
  bad no-recursion rc=$RUN_RC calls=$calls
fi
rm -rf "$RUN_DIR"

run_script 'xedc_note_secret SuperSecretValue99
xedc_set_phase r2-upload
false SuperSecretValue99'
if [[ $RUN_RC -eq 1 ]] && ! grep -q SuperSecretValue99 "$RUN_DIR/recovery.log" "$RUN_DIR/err" && grep -q '\*\*\*' "$RUN_DIR/recovery.log"; then
  pass redaction
else
  bad redaction
fi
rm -rf "$RUN_DIR"

if [[ $fail -ne 0 ]]; then
  exit 1
fi
echo PASS error-trap
