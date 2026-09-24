<?php
// 探测对架构最关键的三个行为：
//  1) swoole 实际存在的 ini（ini_get 对不存在的项返回 false，容易误判）
//  2) ob_* 是否按协程隔离  ← 决定 §5.5 的 ob_start -> response->end() 方案能否用
//  3) 协程内 exit 的行为    ← 决定 exit 与 __EXIT_EXCEPTION 的处理
// 用法： wsl -e bash -lc "php /mnt/e/ProjectGoat/swoolehttpd/dev/probe-concurrency.php"

use Swoole\Coroutine;

echo "=== 1) 实际存在的 swoole ini ===\n";
$all = ini_get_all('swoole');
if (!$all) {
    echo "(ini_get_all('swoole') 返回空)\n";
    foreach (['swoole.use_shortname', 'swoole.enable_coroutine', 'swoole.enable_exit',
              'swoole.enable_preemptive_scheduler', 'swoole.display_errors'] as $k) {
        printf("  %-40s ini_get=%s  exists=%s\n", $k, var_export(ini_get($k), true),
            ini_get($k) === false ? 'NO' : 'yes');
    }
} else {
    foreach ($all as $k => $v) {
        printf("  %-45s local=%s global=%s\n", $k, var_export($v['local_value'], true), var_export($v['global_value'], true));
    }
}

echo "\n=== 2) ob_* 是否按协程隔离 ===\n";
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
Coroutine\run(function () {
    $res = [];
    $done = new Coroutine\Channel(2);

    $mk = function (string $tag, array &$res, $done) {
        return function () use ($tag, &$res, $done) {
            $cid = Coroutine::getCid();
            $lvlBefore = ob_get_level();
            ob_start();
            echo "{$tag}1";
            Coroutine::sleep(0.05);          // 让出，制造交错
            echo "{$tag}2";
            Coroutine::sleep(0.05);
            echo "{$tag}3";
            $captured = ob_get_clean();
            $res[$tag] = ['cid' => $cid, 'lvlBefore' => $lvlBefore, 'captured' => $captured];
            $done->push(1);
        };
    };

    Coroutine::create($mk('A', $res, $done));
    Coroutine::create($mk('B', $res, $done));
    $done->pop();
    $done->pop();

    ksort($res);
    foreach ($res as $tag => $r) {
        printf("  %s: cid=%s lvlBefore=%d captured=%s  %s\n",
            $tag, $r['cid'], $r['lvlBefore'], var_export($r['captured'], true),
            ($r['captured'] === "{$tag}1{$tag}2{$tag}3") ? '=> 隔离 OK' : '=> !! 串了 !!');
    }
});

echo "\n=== 3) 协程内 exit 的行为（子进程执行）===\n";
$code = <<<'PHP'
<?php
use Swoole\Coroutine;
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
Coroutine\run(function () {
    Coroutine::create(function () {
        try {
            exit(7);
        } catch (\Throwable $e) {
            echo "CAUGHT: ", get_class($e), " code=", $e->getCode(),
                 (method_exists($e, 'getStatus') ? " status=" . var_export($e->getStatus(), true) : ""), "\n";
        }
        echo "after-exit-reached\n";
    });
    Coroutine::sleep(0.2);
    echo "parent still alive\n";
});
echo "run() returned\n";
PHP;
$tmp = tempnam(sys_get_temp_dir(), 'exitprobe') . '.php';
file_put_contents($tmp, $code);
$out = shell_exec('php -d swoole.enable_exit=1 ' . escapeshellarg($tmp) . ' 2>&1');
echo "  [enable_exit=1] " . str_replace("\n", "\n  ", trim((string)$out)) . "\n";
$out = shell_exec('php -d swoole.enable_exit=0 ' . escapeshellarg($tmp) . ' 2>&1');
echo "  [enable_exit=0] " . str_replace("\n", "\n  ", trim((string)$out)) . "\n";
@unlink($tmp);

echo "\n=== 4) Coroutine::getContext() 可否做 per-cid 存储 ===\n";
Coroutine\run(function () {
    $c = Coroutine::getContext();
    $c['marker'] = 'hello';
    Coroutine::create(function () {
        $inner = Coroutine::getContext();
        // 子协程默认继承父的 context 数组？
        printf("  child sees marker: %s\n", var_export($inner['marker'] ?? '(unset)', true));
        $inner['marker'] = 'child-wrote';
    });
    Coroutine::sleep(0.05);
    printf("  parent marker now: %s\n", var_export(Coroutine::getContext()['marker'], true));
});

echo "\n=== probe-concurrency done ===\n";
