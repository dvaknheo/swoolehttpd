#!/usr/bin/env bash
# 冒烟测试：起一个 example，curl 它，打印响应与服务端日志，然后收工。
# 用法： bash dev/smoke.sh [example.php] [port] [url]
#   bash dev/smoke.sh examples/hello.php 9528 /
set -u

EX="${1:-examples/hello.php}"
PORT="${2:-9528}"
URL="${3:-/}"
ROOT=/mnt/e/ProjectGoat/swoolehttpd
LOG=/tmp/swoolehttpd-smoke.log

cd "$ROOT" || exit 1

php "$EX" >"$LOG" 2>&1 &
PID=$!

cleanup() {
  kill "$PID" 2>/dev/null
  wait "$PID" 2>/dev/null
}
trap cleanup EXIT

# 等待端口就绪
READY=no
for _ in $(seq 1 60); do
  if curl -s -o /dev/null "http://127.0.0.1:${PORT}${URL}" 2>/dev/null; then READY=yes; break; fi
  sleep 0.2
done

echo "=== target: php $EX  port $PORT  url $URL ==="
echo "=== ready: $READY ==="
echo
echo "=== response (headers + body) ==="
curl -s -i --max-time 10 "http://127.0.0.1:${PORT}${URL}" | head -60
echo
echo "=== server stderr/stdout ==="
cat "$LOG"
echo
echo "=== done ==="
