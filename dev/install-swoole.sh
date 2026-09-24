#!/usr/bin/env bash
# 在 WSL(Debian 12 / PHP 8.2) 下从源码编译安装 swoole，并以 root 身份安装到系统。
# 用法（必须以 root 运行）：
#   wsl -u root -e bash -lc "bash /mnt/e/ProjectGoat/swoolehttpd/dev/install-swoole.sh"
#
# 选 6.x：避免已废弃 API，代码里统一使用 Coroutine::getCid()（5.x/6.x 都有），
# 因此同一份代码在 swoole 5.1+ / 6.x 上都能跑。
set -euo pipefail

SWOOLE_VERSION="${SWOOLE_VERSION:-6.2.3}"
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
BUILD_ROOT="/usr/local/src"

echo "=== target: swoole ${SWOOLE_VERSION} for PHP ${PHP_VER} ==="

if php -r 'exit(extension_loaded("swoole")?0:1);'; then
  echo "swoole already loaded: $(php -r 'echo swoole_version();')"
  exit 0
fi

mkdir -p "$BUILD_ROOT"
cd "$BUILD_ROOT"

if [ ! -f "swoole-${SWOOLE_VERSION}.tgz" ]; then
  echo "--- downloading ---"
  curl -fsSL -o "swoole-${SWOOLE_VERSION}.tgz" \
    "https://pecl.php.net/get/swoole-${SWOOLE_VERSION}.tgz"
fi

rm -rf "swoole-${SWOOLE_VERSION}"
tar xzf "swoole-${SWOOLE_VERSION}.tgz"
cd "swoole-${SWOOLE_VERSION}"

echo "--- phpize ---"
phpize >/dev/null

echo "--- configure ---"
./configure \
  --with-php-config="$(command -v php-config)" \
  --enable-openssl \
  >/tmp/swoole-configure.log 2>&1 || { tail -40 /tmp/swoole-configure.log; exit 1; }

echo "--- make -j$(nproc) ---"
make -j"$(nproc)" >/tmp/swoole-make.log 2>&1 || { tail -60 /tmp/swoole-make.log; exit 1; }

echo "--- make install ---"
make install >/tmp/swoole-install.log 2>&1 || { tail -40 /tmp/swoole-install.log; exit 1; }

echo "--- enable extension ---"
mkdir -p "/etc/php/${PHP_VER}/mods-available"
echo "extension=swoole.so" > "/etc/php/${PHP_VER}/mods-available/swoole.ini"
if command -v phpenmod >/dev/null; then
  phpenmod -v "$PHP_VER" swoole || true
else
  for sapi in cli fpm; do
    mkdir -p "/etc/php/${PHP_VER}/${sapi}/conf.d"
    ln -sf "/etc/php/${PHP_VER}/mods-available/swoole.ini" \
           "/etc/php/${PHP_VER}/${sapi}/conf.d/20-swoole.ini"
  done
fi

echo "--- verify ---"
php -r 'var_dump(extension_loaded("swoole")); echo swoole_version(), PHP_EOL;'

echo "=== DONE ==="
