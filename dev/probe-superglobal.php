<?php
// 关键问题：PHP 超全局变量（$_SERVER/$_GET/$_SESSION...）在 Swoole 协程间是否隔离？
// 这决定了 _SaveSuperGlobalAll() 这种"把请求数据写进真超全局"的兼容层是否可行。
// 用法： wsl -e bash -lc "php /mnt/e/ProjectGoat/swoolehttpd/dev/probe-superglobal.php"

use Swoole\Coroutine;

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

echo "=== 超全局是否按协程隔离 ===\n";
Coroutine\run(function () {
    $res = [];
    $done = new Coroutine\Channel(2);
    $mk = function (string $tag) use (&$res, $done) {
        return function () use ($tag, &$res, $done) {
            $_SERVER['MARKER'] = $tag;
            $_GET['who'] = $tag;
            Coroutine::sleep(0.05);          // 让出，制造交错
            $res[$tag] = [
                'SERVER_MARKER' => $_SERVER['MARKER'] ?? null,
                'GET_who'       => $_GET['who'] ?? null,
            ];
            $done->push(1);
        };
    };
    Coroutine::create($mk('A'));
    Coroutine::create($mk('B'));
    $done->pop();
    $done->pop();
    ksort($res);
    foreach ($res as $tag => $r) {
        printf("  %s: \$_SERVER[MARKER]=%s  \$_GET[who]=%s  %s\n",
            $tag, var_export($r['SERVER_MARKER'], true), var_export($r['GET_who'], true),
            ($r['SERVER_MARKER'] === $tag && $r['GET_who'] === $tag) ? '=> 隔离 OK' : '=> !! 全世界共享 !!');
    }
});

echo "\n=== 引用绑定超全局到对象属性是否可行 ===\n";
class Box { public $_SESSION = ['seed' => 1]; }
$box = new Box();
$_SESSION = &$box->_SESSION;      // 关键手法
$_SESSION['k'] = 'v';
printf("  box sees   : %s\n", var_export($box->_SESSION, true));
$box->_SESSION['k2'] = 'v2';
printf("  \$_SESSION sees: %s\n", var_export($_SESSION, true));

echo "\n=== 每请求重新绑定后，旧引用是否还指着旧对象 ===\n";
$box2 = new Box();
$box2->_SESSION = ['seed' => 2];
$old = &$_SESSION;
$_SESSION = &$box2->_SESSION;
$_SESSION['z'] = 1;
printf("  box(旧) = %s\n", var_export($box->_SESSION, true));
printf("  box2(新)= %s\n", var_export($box2->_SESSION, true));
printf("  \$old    = %s\n", var_export($old, true));
unset($old);

echo "\n=== 两个协程各自绑定 \$_SESSION 会怎样（竞争演示）===\n";
Coroutine\run(function () {
    $res = [];
    $done = new Coroutine\Channel(2);
    foreach (['A', 'B'] as $tag) {
        Coroutine::create(function () use ($tag, &$res, $done) {
            $obj = new Box();
            $obj->_SESSION = ['owner' => $tag];
            $_SESSION = &$obj->_SESSION;      // 全局绑定，必然互相覆盖
            Coroutine::sleep(0.05);
            $res[$tag] = $_SESSION['owner'] ?? null;
            $res[$tag . '_obj'] = $obj->_SESSION['owner'] ?? null;
            $done->push(1);
        });
    }
    $done->pop();
    $done->pop();
    ksort($res);
    foreach ($res as $k => $v) {
        printf("  %s = %s\n", $k, var_export($v, true));
    }
    echo "  （_obj 是对的、裸 \$_SESSION 串了 => 裸超全局不可作为协程内唯一真相）\n";
});

echo "\n=== probe-superglobal done ===\n";
