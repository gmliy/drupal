#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "$(readlink -f "$0")")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
fail=0
export XEDC_LOG_DIR=$work/log
export XEDC_STATE_ROOT=$work/state
export XEDC_BACKUP_ROOT=$work/backups
export XEDC_SECRET_LIST=$work/secrets
unset XEDC_R2_ENV XEDC_AGE_KEY XEDC_GITHUB_DEPLOY_KEY XEDC_DRY_RUN || true
export XEDC_RECOVERY_TEST=1
mkdir -p "$XEDC_LOG_DIR" "$XEDC_STATE_ROOT"
# shellcheck source=../../lib/common.sh
source "$ROOT/lib/common.sh"
# shellcheck source=../../lib/secure.sh
source "$ROOT/lib/secure.sh"
# shellcheck source=../../lib/github.sh
source "$ROOT/lib/github.sh"
xedc_init
trap 'rm -rf "$work"' EXIT

export XEDC_SECURE_DIR=$work/secure
export XEDC_AGE_KEY=$work/runtime/age.key
export XEDC_R2_ENV=$work/runtime/r2.env
mkdir -p "$work/secure" "$work/runtime"
age-keygen -o "$work/secure/age.key" >/dev/null 2>&1
chmod 600 "$work/secure/age.key"
printf '%s\n' 'R2_BUCKET=bucket-a' 'R2_SECRET_ACCESS_KEY=fixturesecret' >"$work/secure/r2.env"
chmod 600 "$work/secure/r2.env"
xedc_secure_install_age
xedc_secure_install_r2
if [[ $(stat -c %a "$XEDC_AGE_KEY") != 600 ]]; then
  echo FAIL age-mode
  fail=1
else
  echo PASS age-mode
fi
if ! cmp -s "$work/secure/age.key" "$XEDC_AGE_KEY"; then
  echo FAIL age-copied
  fail=1
else
  echo PASS age-copied
fi

age-keygen -o "$work/other.key" >/dev/null 2>&1
printf 'db-password-fixture' >"$work/db.json"
tar -C "$work" -cf - db.json | age -r "$(age-keygen -y "$work/other.key")" -o "$work/wrong.age"
if ( xedc_secure_decrypt_check "$work/wrong.age" >"$work/dec.out" 2>"$work/dec.err" ); then
  echo FAIL wrong-key
  fail=1
else
  echo PASS wrong-key
fi
if grep -q 'db-password-fixture' "$work/dec.out" "$work/dec.err" "$XEDC_LOG" 2>/dev/null; then
  echo FAIL secret-leaked
  fail=1
else
  echo PASS secret-not-logged
fi

printf 'x\n' >"$work/runtime/age.key"
echo other >"$work/secure/age.key"
if ( xedc_secure_install_age >"$work/mis.out" 2>"$work/mis.err" ); then
  echo FAIL mismatch-overwrite
  fail=1
else
  echo PASS mismatch-kept
fi
if [[ $(cat "$work/runtime/age.key") != 'x' ]]; then
  echo FAIL mismatch-kept-bytes
  fail=1
else
  echo PASS mismatch-kept-bytes
fi

export XEDC_GITHUB_DEPLOY_KEY=$work/deploy
export XEDC_GITHUB_KNOWN_HOSTS=$work/known
xedc_github_init_deploy_key >"$work/pub.out"
if [[ $(stat -c %a "$work/deploy") != 600 ]]; then
  echo FAIL deploy-mode
  fail=1
else
  echo PASS deploy-mode
fi
if grep -q 'PRIVATE' "$work/pub.out" || grep -q "$(awk 'NR==2' "$work/deploy")" "$work/pub.out"; then
  echo FAIL deploy-private-printed
  fail=1
else
  echo PASS deploy-pub-only
fi
if ! grep -q '^ssh-ed25519 ' "$work/pub.out"; then
  echo FAIL deploy-pub
  fail=1
else
  echo PASS deploy-pub
fi

"$ROOT/recover.sh" --help >/dev/null
echo PASS recover-help
env XEDC_GITHUB_DEPLOY_KEY=$work/missing-key "$ROOT/bootstrap-recover.sh" >/tmp/xedc-bootstrap-missing.out 2>/tmp/xedc-bootstrap-missing.err || true
if grep -q 'Deploy Key' /tmp/xedc-bootstrap-missing.err; then
  echo PASS bootstrap-needs-key
else
  echo FAIL bootstrap-needs-key
  fail=1
fi
rm -f /tmp/xedc-bootstrap-missing.out /tmp/xedc-bootstrap-missing.err

if [[ $fail -ne 0 ]]; then
  exit 1
fi
echo PASS secure-recover
