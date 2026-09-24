#!/usr/bin/env bash
# SwooleHttpd 端到端测试：起服务器，逐项断言，最后打印汇总。
# 用法： bash dev/test.sh [port]
set -u

PORT="${1:-9530}"
ROOT=/mnt/e/ProjectGoat/swoolehttpd
LOG=/tmp/swoolehttpd-test.log
JAR=$(mktemp)
PASS=0
FAIL=0

cd "$ROOT" || exit 1

php dev/fixtures/app.php "$PORT" >"$LOG" 2>&1 &
PID=$!
cleanup() { kill "$PID" 2>/dev/null; wait "$PID" 2>/dev/null; rm -f "$JAR"; }
trap cleanup EXIT

for _ in $(seq 1 60); do
  curl -s --max-time 10 -o /dev/null "http://127.0.0.1:${PORT}/" 2>/dev/null && break
  sleep 0.2
done

B="http://127.0.0.1:${PORT}"

check() { # check <label> <expected> <actual>
  if [ "$2" = "$3" ]; then
    printf '  \033[32mPASS\033[0m %-34s %s\n' "$1" "$3"
    PASS=$((PASS+1))
  else
    printf '  \033[31mFAIL\033[0m %-34s got=%s want=%s\n' "$1" "$3" "$2"
    FAIL=$((FAIL+1))
  fi
}
checkc() { # checkc <label> <contains?> <needle> <haystack>
  case "$4" in
    *"$3"*) printf '  \033[32mPASS\033[0m %-34s\n' "$1"; PASS=$((PASS+1));;
    *)      printf '  \033[31mFAIL\033[0m %-34s got=%s want~%s\n' "$1" "$4" "$3"; FAIL=$((FAIL+1));;
  esac
}

echo "=== SwooleHttpd end-to-end tests (port $PORT) ==="

check "GET / body"            "hello"  "$(curl -s --max-time 10 "$B/")"
check "GET / status"          "200"    "$(curl -s --max-time 10 -o /dev/null -w '%{http_code}' "$B/")"
check "GET /unknown 404"      "404"    "$(curl -s --max-time 10 -o /dev/null -w '%{http_code}' "$B/no-such-page")"

# --- 会话：对象存储（协程安全路径） ---
rm -f "$JAR"
check "session obj 1st"       "n=1"    "$(curl -s --max-time 10 -c "$JAR" -b "$JAR" "$B/session")"
check "session obj 2nd"       "n=2"    "$(curl -s --max-time 10 -c "$JAR" -b "$JAR" "$B/session")"
check "session obj 3rd"       "n=3"    "$(curl -s --max-time 10 -c "$JAR" -b "$JAR" "$B/session")"

# --- 会话：传统 $_SESSION 写法 ---
rm -f "$JAR"
check "session legacy 1st"    "n=1"    "$(curl -s --max-time 10 -c "$JAR" -b "$JAR" "$B/session-legacy")"
check "session legacy 2nd"    "n=2"    "$(curl -s --max-time 10 -c "$JAR" -b "$JAR" "$B/session-legacy")"

# --- 会话：destroy ---
check "session destroy"       "destroyed" "$(curl -s --max-time 10 -c "$JAR" -b "$JAR" "$B/session-destroy")"

# --- 每请求重置：STATICS / GLOBALS 必须是干净的 ---
check "statics 1st"           "statics=1" "$(curl -s --max-time 10 "$B/statics")"
check "statics 2nd"           "statics=1" "$(curl -s --max-time 10 "$B/statics")"
check "globals 1st"           "globals=1" "$(curl -s --max-time 10 "$B/globals")"
check "globals 2nd"           "globals=1" "$(curl -s --max-time 10 "$B/globals")"
check "class statics 1st"     "class_statics=101" "$(curl -s --max-time 10 "$B/class-statics")"
check "class statics 2nd"     "class_statics=101" "$(curl -s --max-time 10 "$B/class-statics")"

# --- exit()：不应杀死 worker，且不执行 exit 之后的输出 ---
check "exit() body"           "before-exit" "$(curl -s --max-time 10 "$B/exit")"
check "exit() worker alive"   "hello"  "$(curl -s --max-time 10 "$B/")"

# --- 异常 ---
check "error 500"             "500"    "$(curl -s --max-time 10 -o /dev/null -w '%{http_code}' "$B/error")"
check "worker alive after 500" "hello" "$(curl -s --max-time 10 "$B/")"

# --- 空响应：必须立刻返回，不能挂到超时 ---
check "empty body"            ""       "$(curl -s --max-time 10 --max-time 5 "$B/empty")"
check "empty status"          "200"    "$(curl -s --max-time 10 -o /dev/null -w '%{http_code}' --max-time 5 "$B/empty")"

# --- 大响应：2MB，验证只 end() 一次、内容完整 ---
BIG_LEN=$(curl -s --max-time 10 --max-time 20 "$B/big" | wc -c)
check "big body length"       "2097152" "$BIG_LEN"

# --- header / setcookie / status ---
check "custom status"         "201"    "$(curl -s --max-time 10 -o /dev/null -w '%{http_code}' "$B/cookie")"
checkc "custom header"        -        "X-Probe: yes" "$(curl -s --max-time 10 -D - -o /dev/null "$B/cookie")"
checkc "set-cookie"           -        "probe=v1"     "$(curl -s --max-time 10 -D - -o /dev/null "$B/cookie")"

# --- ⭐ 并发：两个请求交错时，对象存储不得串数据 ---
echo
echo "--- concurrency probe (2 parallel /slow) ---"
curl -s --max-time 10 "$B/slow?tag=AAAA" >/tmp/slow-a.json &
CA=$!
curl -s --max-time 10 "$B/slow?tag=BBBB" >/tmp/slow-b.json &
CB=$!
wait $CA; wait $CB
A_OBJ=$(php -r '$j=json_decode(file_get_contents("/tmp/slow-a.json"),true); echo $j["tag_obj"] ?? "-";')
B_OBJ=$(php -r '$j=json_decode(file_get_contents("/tmp/slow-b.json"),true); echo $j["tag_obj"] ?? "-";')
A_RAW=$(php -r '$j=json_decode(file_get_contents("/tmp/slow-a.json"),true); echo $j["tag_raw"] ?? "-";')
B_RAW=$(php -r '$j=json_decode(file_get_contents("/tmp/slow-b.json"),true); echo $j["tag_raw"] ?? "-";')
check "concurrency tag_obj A" "AAAA"   "$A_OBJ"
check "concurrency tag_obj B" "BBBB"   "$B_OBJ"
echo "  \033[33minfo\033[0m raw \$_GET after yield: A=$A_RAW B=$B_RAW  (串了是预期，见 README 警告)"

A_URI=$(php -r '$j=json_decode(file_get_contents("/tmp/slow-a.json"),true); echo $j["uri_obj"] ?? "-";')
check "concurrency uri_obj A" "/slow?tag=AAAA" "$A_URI"

echo
echo "=== server log ==="
cat "$LOG"
echo
printf '=== RESULT: \033[32m%d passed\033[0m, \033[31m%d failed\033[0m ===\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
