#!/usr/bin/env bash
# 并行跑恢复测试。默认五个系统；--quick 只跑两套用于修复。
set -euo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
LOG_DIR="$ROOT/tests/logs"
WORK_DIR="$ROOT/tests/work"
mkdir -p "$LOG_DIR" "$WORK_DIR"
STATUS="$LOG_DIR/status.tsv"
: >"$STATUS"

ONLY=""
JOBS_ARG=""
QUICK=0
FROM_STAGE=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --only) ONLY=${2:-}; shift 2 ;;
    --jobs) JOBS_ARG=${2:-}; shift 2 ;;
    --quick) QUICK=1; shift ;;
    --from-stage) FROM_STAGE=${2:-}; shift 2 ;;
    *) echo "未知参数: $1" >&2; exit 2 ;;
  esac
done

declare -A BASE=(
  [ubuntu-22.04]=ubuntu:22.04
  [ubuntu-24.04]=ubuntu:24.04
  [ubuntu-26.04]=ubuntu:26.04
  [ubuntu-26.10]=ubuntu:26.10
  [debian-12]=debian:12
)
systems=(ubuntu-22.04 ubuntu-24.04 ubuntu-26.04 ubuntu-26.10 debian-12)
if [[ "$QUICK" == 1 ]]; then
  systems=(ubuntu-24.04 ubuntu-26.04)
fi
if [[ -n "$ONLY" ]]; then
  systems=("$ONLY")
fi

avail_kb=$(awk '/MemAvailable/ {print $2}' /proc/meminfo)
avail_mb=$((avail_kb / 1024))
budget=$((avail_mb - 1024))
if (( budget < 1228 )); then
  jobs=1
else
  jobs=$((budget / 1228))
fi
if (( jobs > ${#systems[@]} )); then
  jobs=${#systems[@]}
fi
if (( jobs < 1 )); then
  jobs=1
fi
while (( jobs > 1 && budget / jobs < 2200 )); do
  jobs=$((jobs - 1))
done
if [[ -n "$JOBS_ARG" ]]; then
  jobs=$JOBS_ARG
fi
if (( budget < 1 )); then
  echo "可用内存不足" >&2
  exit 1
fi
per=$((budget / jobs))
if (( per < 1024 )); then
  echo "按内存只能同时跑更少的系统" >&2
  jobs=$((budget / 1024))
  (( jobs < 1 )) && jobs=1
  per=$((budget / jobs))
fi
swap_used=$(awk '/SwapTotal/ {t=$2} /SwapFree/ {f=$2} END {if (t>0) printf "%d", (t-f)*100/t; else print 0}' /proc/meminfo)
if (( swap_used > 80 && jobs > 1 )); then
  echo "交换分区占用高，并发降为 1"
  jobs=1
  per=$((budget / jobs))
fi
disk_kb=$(df -Pk /var/lib/docker 2>/dev/null | awk 'NR==2 {print $4}')
if [[ -z "$disk_kb" ]]; then
  disk_kb=$(df -Pk / | awk 'NR==2 {print $4}')
fi
if (( disk_kb < 8 * 1024 * 1024 )); then
  echo "磁盘剩余不足 8GB" >&2
  exit 1
fi
cpus=$(nproc)
cpu_each=$(awk -v n="$cpus" -v j="$jobs" 'BEGIN { c=n/j; if (c<0.5) c=0.5; if (c>2) c=2; printf "%.2f", c }')
echo "并发 ${jobs}，每容器 ${per}MB / ${cpu_each} CPU，可用内存 ${avail_mb}MB"

stage_rank() {
  case "$1" in
    ""|backup) echo 1 ;;
    restore|install|target) echo 2 ;;
    snapshot) echo 3 ;;
    domain) echo 4 ;;
    video) echo 5 ;;
    idem) echo 6 ;;
    resume) echo 7 ;;
    verify) echo 8 ;;
    r2) echo 9 ;;
    php) echo 10 ;;
    prompts) echo 11 ;;
    perms) echo 12 ;;
    lowmem) echo 13 ;;
    compose) echo 14 ;;
    redisresume) echo 15 ;;
    *) echo 1 ;;
  esac
}
START_RANK=$(stage_rank "${FROM_STAGE:-backup}")

mark() {
  local os=$1 stage=$2 state=$3 note=${4:-}
  mkdir -p /var/lock
  flock /var/lock/xedc-test-status.lock bash -c "printf '%s\t%s\t%s\t%s\n' '$os' '$stage' '$state' '$note' >> '$STATUS'"
}

show_status() {
  printf '\n系统\t阶段\t状态\t说明\n'
  if [[ -f "$STATUS" ]]; then
    column -t -s $'\t' "$STATUS" 2>/dev/null || cat "$STATUS"
  fi
}

docker network inspect xedc-test >/dev/null 2>&1 || docker network create xedc-test >/dev/null
if ! docker ps --format '{{.Names}}' | grep -qx xedc-minio; then
  docker rm -f xedc-minio >/dev/null 2>&1 || true
  docker run -d --name xedc-minio --network xedc-test \
    -e MINIO_ROOT_USER=xedctest \
    -e MINIO_ROOT_PASSWORD=xedctest-secret \
    -p 127.0.0.1:9100:9000 \
    cgr.dev/chainguard/minio:latest server /data >/dev/null
fi
ok=0
for _ in $(seq 1 40); do
  if curl -fsS http://127.0.0.1:9100/minio/health/live >/dev/null 2>&1; then
    ok=1
    break
  fi
  sleep 1
done
if [[ "$ok" != 1 ]]; then
  echo "MinIO 没有就绪" >&2
  exit 1
fi
rclone mkdir :s3:xedc-test \
  --s3-provider Minio \
  --s3-endpoint http://127.0.0.1:9100 \
  --s3-access-key-id xedctest \
  --s3-secret-access-key xedctest-secret \
  --s3-force-path-style >/dev/null

declare -A READY=()
for os in "${systems[@]}"; do
  image=${BASE[$os]}
  if ! docker image inspect "$image" >/dev/null 2>&1; then
    if ! docker pull "$image"; then
      mark "$os" image 跳过 "镜像不可用"
      READY[$os]=0
      continue
    fi
  fi
  if ! docker image inspect "xedc-base:${os}" >/dev/null 2>&1; then
    docker build -t "xedc-base:${os}" - <<DOCKER
FROM ${image}
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates && rm -rf /var/lib/apt/lists/*
DOCKER
  fi
  READY[$os]=1
  mark "$os" image 通过 ""
done

run_container() {
  local name=$1 image=$2 os=$3 mode=$4
  local prefix="os-${os}/"
  docker rm -f "$name" >/dev/null 2>&1 || true
  local -a volumes=(
    -v "xedc-cache-apt-${os}:/var/cache/apt/archives"
    -v xedc-cache-composer:/root/.composer/cache
    -v xedc-cache-npm:/root/.npm
    -v xedc-cache-node:/var/cache/xedc
  )
  if [[ "$mode" == "source" ]]; then
    volumes+=(-v "$ROOT:/opt/xedc-recovery:ro")
  elif [[ "$mode" == "target" ]]; then
    volumes+=(-v "$ROOT/install.sh:/opt/install.sh:ro")
  else
    case "$mode" in
      perms|lowmem|compose|redisresume)
        volumes+=(-v "$ROOT:/opt/xedc-recovery:ro")
        ;;
      *)
        volumes+=(-v "$ROOT/tests/guest.sh:/opt/xedc-recovery/tests/guest.sh:ro")
        ;;
    esac
  fi
  local mem="${per}m"
  if [[ "$mode" == "lowmem" ]]; then
    mem=2048m
  fi
  # shellcheck disable=SC2206
  local -a cmd=(bash ${mode_cmd[$mode]})
  timeout 3600 docker run --name "$name" \
    --network xedc-test \
    --memory "$mem" \
    --memory-swap "$mem" \
    --cpus "$cpu_each" \
    --shm-size 128m \
    "${volumes[@]}" \
    -e XEDC_TEST=1 \
    -e R2_ACCOUNT_ID=xedctest \
    -e R2_ACCESS_KEY_ID=xedctest \
    -e R2_SECRET_ACCESS_KEY=xedctest-secret \
    -e R2_BUCKET=xedc-test \
    -e R2_PREFIX="$prefix" \
    -e R2_ENDPOINT=http://xedc-minio:9000 \
    "$image" \
    "${cmd[@]}"
}

declare -A mode_cmd=(
  [source]="/opt/xedc-recovery/tests/guest.sh source"
  [target]="/opt/install.sh --from r2:latest --domain test1.local --email admin@test1.local --https self --www no --yes"
  [domain]="/opt/xedc-recovery/tests/guest.sh domain"
  [video]="/opt/xedc-recovery/tests/guest.sh video"
  [idem]="/opt/xedc-recovery/tests/guest.sh idem"
  [resume]="/opt/xedc-recovery/tests/guest.sh resume"
  [verify]="/opt/xedc-recovery/tests/guest.sh verify"
  [r2]="/opt/xedc-recovery/tests/guest.sh r2"
  [php]="/opt/xedc-recovery/tests/guest.sh php"
  [prompts]="/opt/xedc-recovery/tests/guest.sh prompts"
  [perms]="/opt/xedc-recovery/tests/guest.sh perms"
  [lowmem]="/opt/xedc-recovery/tests/guest.sh lowmem"
  [compose]="/opt/xedc-recovery/tests/guest.sh compose"
  [redisresume]="/opt/xedc-recovery/tests/guest.sh redisresume"
)

wait_slot() {
  local running
  while true; do
    running=$(jobs -rp | wc -l)
    if [[ -n "${loop_pid:-}" ]] && kill -0 "$loop_pid" 2>/dev/null; then
      running=$((running - 1))
    fi
    if (( running < jobs )); then
      break
    fi
    sleep 2
  done
}

run_main() {
  local os=$1
  local log="$LOG_DIR/${os}.log"
  if (( START_RANK <= 1 )); then
    : >"$log"
  fi
  local began
  began=$(date +%s)
  if [[ "${READY[$os]}" != 1 ]]; then
    return 0
  fi
  if (( START_RANK <= 1 )); then
    mark "$os" backup 运行中 ""
    set +e
    run_container "xedc-src-${os}" "xedc-base:${os}" "$os" source >>"$log" 2>&1
    local rc=$?
    set -e
    echo "容器退出码 ${rc}" >>"$log"
    if [[ "$rc" == 0 ]]; then
      mark "$os" backup 通过 "$(( $(date +%s) - began ))s"
    else
      mark "$os" backup 失败 "$log"
      return 1
    fi
  fi
  local began_restore
  began_restore=$(date +%s)
  if (( START_RANK <= 2 )); then
    mark "$os" restore 运行中 ""
    set +e
    run_container "xedc-dst-${os}" "xedc-base:${os}" "$os" target >>"$log" 2>&1
    local rc=$?
    set -e
    echo "容器退出码 ${rc}" >>"$log"
    if [[ "$rc" == 0 ]]; then
      docker commit "xedc-dst-${os}" "xedc-snap:${os}" >/dev/null
      docker rm -f "xedc-dst-${os}" >/dev/null 2>&1 || true
      mark "$os" restore 通过 "$(( $(date +%s) - began_restore ))s"
      mark "$os" snapshot 通过 ""
    else
      mark "$os" restore 失败 "$log"
      docker rm -f "xedc-dst-${os}" >/dev/null 2>&1 || true
      return 1
    fi
  elif ! docker image inspect "xedc-snap:${os}" >/dev/null 2>&1; then
    mark "$os" snapshot 失败 "没有快照，不能从 ${FROM_STAGE} 继续"
    return 1
  fi
  docker rm -f "xedc-src-${os}" >/dev/null 2>&1 || true
}

run_branch() {
  local os=$1 branch=$2
  local log="$LOG_DIR/${os}-${branch}.log"
  local began
  began=$(date +%s)
  mark "$os" "$branch" 运行中 ""
  if run_container "xedc-${branch}-${os}" "xedc-snap:${os}" "$os" "$branch" >"$log" 2>&1; then
    mark "$os" "$branch" 通过 "$(( $(date +%s) - began ))s"
    docker rm -f "xedc-${branch}-${os}" >/dev/null 2>&1 || true
    return 0
  fi
  mark "$os" "$branch" 失败 "$log"
  docker rm -f "xedc-${branch}-${os}" >/dev/null 2>&1 || true
  return 1
}

show_loop() {
  trap 'exit 0' TERM INT
  while true; do
    show_status
    sleep 8
  done
}
show_loop &
loop_pid=$!

fail=0
pids=()
for os in "${systems[@]}"; do
  wait_slot
  run_main "$os" &
  pids+=("$!")
done
for pid in "${pids[@]}"; do
  if ! wait "$pid"; then
    fail=1
  fi
done

branches=(domain video idem resume verify r2 php prompts perms lowmem compose redisresume)
pids=()
for os in "${systems[@]}"; do
  if [[ "${READY[$os]}" != 1 ]]; then
    continue
  fi
  if ! docker image inspect "xedc-snap:${os}" >/dev/null 2>&1; then
    for branch in "${branches[@]}"; do
      mark "$os" "$branch" 跳过 "主流程未完成"
    done
    continue
  fi
  for branch in "${branches[@]}"; do
    rank=$(stage_rank "$branch")
    if (( START_RANK > rank )); then
      mark "$os" "$branch" 跳过 "早于 --from-stage"
      continue
    fi
    wait_slot
    run_branch "$os" "$branch" &
    pids+=("$!")
  done
done
for pid in "${pids[@]}"; do
  if ! wait "$pid"; then
    fail=1
  fi
done
kill "$loop_pid" >/dev/null 2>&1 || true
sleep 0.2
kill -9 "$loop_pid" >/dev/null 2>&1 || true
wait "$loop_pid" 2>/dev/null || true
show_status
bash "$ROOT/tests/cleanup.sh" || true
if (( fail != 0 )); then
  echo "有失败项，日志在 ${LOG_DIR}" >&2
  exit 1
fi
echo "测试流程结束"
