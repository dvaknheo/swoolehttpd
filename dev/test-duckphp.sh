#!/usr/bin/env bash
# DuckPhp + SwooleHttpd 集成测试。
# 用法： bash dev/test-duckphp.sh [port]
set -u

PORT="${1:-9545}"
ROOT=/mnt/e/ProjectGoat/swoolehttpd
LOG=/tmp/swoolehttpd-duckphp.log
JAR=$(mktemp)
PASS=0
FAIL=0
B="http://127.0.0.1:${PORT}"

cd "$ROOT" || exit 1

php dev/fixtures/duckphp-server.php "$PORT" >"$LOG" 2>&1 &
PID=$!
cleanup() { kill "$PID" 2>/dev/null; wait "$PID" 2>/dev/null; rm -f "$JAR"; }
trap cleanup EXIT

for _ in $(seq 1 80); do
  curl -s -o /dev/null --max-time 2 "$B/" && break
  sleep 0.25
done

g() { curl -s --max-time 10 "$@"; }

check() {
  if [ "$2" = "$3" ]; then
    printf '  \033[32mPASS\033[0m %-36s %s\n' "$1" "$3"; PASS=$((PASS+1))
  else
    printf '  \033[31mFAIL\033[0m %-36s got=%s want=%s\n' "$1" "$3" "$2"; FAIL=$((FAIL+1))
  fi
}
jget() {
  php -r '
    $j = json_decode(file_get_contents($argv[1]), true);
    $v = $j[$argv[2]] ?? null;
    if (is_bool($v)) { echo $v ? "true" : "false"; }
    elseif (is_scalar($v)) { echo (string)$v; }
    else { echo json_encode($v); }
  ' "$1" "$2"
}

echo "=== DuckPhp + SwooleHttpd integration (port $PORT) ==="

check "welcome route"        "duckphp-index" "$(g "$B/")"
check "controller route"     "true"          "$(g "$B/probe/route"  | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo var_export($j["ok"] ?? null, true);')"

g "$B/probe/url" > /tmp/dp-url.json
check "url generation"       "true" "$(php -r '$j=json_decode(file_get_contents("/tmp/dp-url.json"),true); echo var_export(str_starts_with($j["url"] ?? "", "/probe/route"), true);')"
check "domain generation"    "true" "$(php -r '$j=json_decode(file_get_contents("/tmp/dp-url.json"),true); echo var_export(str_contains($j["full"] ?? "", "127.0.0.1"), true);')"

g "$B/probe/container" > /tmp/dp-container.json
check "container is ours"    "true" "$(jget /tmp/dp-container.json container_is_ours)"
check "app instance shared"  "true" "$(jget /tmp/dp-container.json app_is_shared)"
check "Route has hooks"      "true" "$(jget /tmp/dp-container.json route_has_hooks)"

check "statics reset 1st"    "statics=1" "$(g "$B/probe/statics")"
check "statics reset 2nd"    "statics=1" "$(g "$B/probe/statics")"

rm -f "$JAR"
check "session 1st"          "n=1" "$(g -c "$JAR" -b "$JAR" "$B/probe/session")"
check "session 2nd"          "n=2" "$(g -c "$JAR" -b "$JAR" "$B/probe/session")"
check "session 3rd"          "n=3" "$(g -c "$JAR" -b "$JAR" "$B/probe/session")"

check "exception -> 500"     "500" "$(g -o /dev/null -w '%{http_code}' "$B/probe/fail")"
check "worker alive after 500" "duckphp-index" "$(g "$B/")"
check "exit() output"        "before-bye" "$(g "$B/probe/bye")"
check "worker alive after exit" "duckphp-index" "$(g "$B/")"

echo
echo "--- ⭐ concurrency probe: DuckPhp superglobals must not cross ---"
g "$B/probe/slow?tag=AAAA" > /tmp/dp-a.json &
CA=$!
g "$B/probe/slow?tag=BBBB" > /tmp/dp-b.json &
CB=$!
wait $CA; wait $CB

for pair in "A /tmp/dp-a.json AAAA" "B /tmp/dp-b.json BBBB"; do
  set -- $pair
  check "slow $1 tag_before"  "$3" "$(jget "$2" tag_before)"
  check "slow $1 tag_after"   "$3" "$(jget "$2" tag_after)"
  check "slow $1 uri_after"   "/probe/slow?tag=$3" "$(jget "$2" uri_after)"
done
echo "  \033[33minfo\033[0m raw \$_GET after yield: A=$(jget /tmp/dp-a.json raw_get_after) B=$(jget /tmp/dp-b.json raw_get_after)  (shared superglobal, documented)"

echo
echo "=== server log ==="
cat "$LOG"
echo
printf '=== RESULT: \033[32m%d passed\033[0m, \033[31m%d failed\033[0m ===\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
