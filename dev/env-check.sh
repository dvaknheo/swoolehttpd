#!/usr/bin/env bash
# 环境体检：确认 WSL 下的 PHP / swoole / composer 状态
# 用法： wsl -e bash -lc "bash /mnt/e/ProjectGoat/swoolehttpd/dev/env-check.sh"

echo "=== OS ==="
uname -a
cat /etc/os-release 2>/dev/null | head -3

echo
echo "=== php ==="
php -v 2>&1 | head -3

echo
echo "=== swoole extension ==="
php -r 'var_dump(extension_loaded("swoole"));' 2>&1
php -r 'echo function_exists("swoole_version") ? swoole_version() : "no swoole_version()"; echo PHP_EOL;' 2>&1

echo
echo "=== php -m (interesting) ==="
php -m 2>/dev/null | grep -iE 'swoole|openswoole|json|pdo|curl|redis|mbstring|fileinfo' || echo "(none matched)"

echo
echo "=== php ini ==="
php --ini 2>&1 | head -6

echo
echo "=== toolchain ==="
for t in composer phpize pecl php-config gcc make autoconf; do
  printf '%-12s ' "$t"
  command -v "$t" || echo "(missing)"
done

echo
echo "=== php-config version ==="
php-config --version 2>&1 || echo "(no php-config)"

echo
echo "=== apt php packages installed ==="
dpkg -l 2>/dev/null | grep -E '^ii +php' | awk '{print $2, $3}' || echo "(none)"

echo
echo "=== network to pecl/php.net? ==="
(timeout 8 curl -sSI https://pecl.php.net/ 2>&1 | head -1) || echo "(curl failed)"
