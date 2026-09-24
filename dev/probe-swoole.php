<?php
// 探测当前 swoole 的真实 API 面，用于决定兼容写法。
// 用法： wsl -e bash -lc "php /mnt/e/ProjectGoat/swoolehttpd/dev/probe-swoole.php"

function chk(string $label, $value): void
{
    printf("%-46s %s\n", $label, is_bool($value) ? ($value ? 'YES' : 'NO') : (string)$value);
}

echo "swoole_version()          : ", swoole_version(), "\n";
echo "PHP                       : ", PHP_VERSION, "\n";
echo "swoole.use_shortname      : ", var_export(ini_get('swoole.use_shortname'), true), "\n";
echo "swoole.enable_coroutine   : ", var_export(ini_get('swoole.enable_coroutine'), true), "\n";
echo "swoole.enable_exit        : ", var_export(ini_get('swoole.enable_exit'), true), "\n";
echo "swoole.display_errors     : ", var_export(ini_get('swoole.display_errors'), true), "\n";
echo "\n--- Coroutine statics ---\n";
chk('Swoole\Coroutine::getCid()', method_exists('Swoole\Coroutine', 'getCid'));
chk('Swoole\Coroutine::getuid()', method_exists('Swoole\Coroutine', 'getuid'));
chk('Swoole\Coroutine::defer()', method_exists('Swoole\Coroutine', 'defer'));
chk('Swoole\Coroutine::create()', method_exists('Swoole\Coroutine', 'create'));
chk('Swoole\Coroutine::getContext()', method_exists('Swoole\Coroutine', 'getContext'));
chk('Swoole\Coroutine::sleep()', method_exists('Swoole\Coroutine', 'sleep'));
chk('Swoole\Coroutine::stats()', method_exists('Swoole\Coroutine', 'stats'));
echo "\n--- Runtime ---\n";
chk('Swoole\Runtime::enableCoroutine()', method_exists('Swoole\Runtime', 'enableCoroutine'));
chk('Swoole\Runtime::getHookFlags()', method_exists('Swoole\Runtime', 'getHookFlags'));
try {
    $r = new ReflectionMethod('Swoole\Runtime', 'enableCoroutine');
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $ps[] = ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName()
              . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '');
    }
    chk('  signature', implode(', ', $ps));
} catch (\Throwable $e) {
    chk('  signature', 'ERR ' . $e->getMessage());
}
echo "\n--- Exceptions ---\n";
chk('class Swoole\\ExitException', class_exists('Swoole\ExitException'));
chk('Swoole\\ExitException::getStatus()', method_exists('Swoole\ExitException', 'getStatus'));
echo "\n--- Server classes ---\n";
foreach (['Swoole\Http\Server', 'Swoole\WebSocket\Server', 'Swoole\Coroutine\Http\Server'] as $c) {
    chk('class ' . $c, class_exists($c));
}
echo "\n--- Http\Response methods ---\n";
foreach (['end', 'write', 'header', 'status', 'cookie', 'sendfile', 'redirect', 'detach', 'create'] as $m) {
    chk('  Response::' . $m, method_exists('Swoole\Http\Response', $m));
}
echo "\n--- Http\Request properties ---\n";
$rp = get_class_vars('Swoole\Http\Request');
chk('  Request props', implode(', ', array_keys($rp)));
echo "\n--- short functions (swoole.use_shortname) ---\n";
foreach (['go', 'defer', 'co', 'chan', 'swoole_coroutine_create'] as $f) {
    chk('  function ' . $f . '()', function_exists($f));
}
echo "\n--- Coroutine constants ---\n";
foreach (['SWOOLE_HOOK_ALL', 'SWOOLE_HOOK_TCP', 'SWOOLE_HOOK_FILE', 'SWOOLE_BASE', 'SWOOLE_PROCESS'] as $k) {
    chk('  ' . $k, defined($k) ? constant($k) : 'undefined');
}
echo "\n--- settings accepted by Http\Server::set (doc-check) ---\n";
$ref = new ReflectionMethod('Swoole\Http\Server', 'set');
chk('  Http\\Server::set() exists', $ref ? 'YES' : 'NO');
echo "\n--- output buffering inside coroutine ---\n";
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
Swoole\Coroutine\run(function () {
    $c1 = Swoole\Coroutine::getCid();
    ob_start();
    echo "hello";
    // 在同一协程里再开一层，检查 ob 层级是否按协程隔离
    $lvl = ob_get_level();
    $out = ob_get_clean();
    printf("  cid=%d ob_level_seen=%d captured=%s\n", $c1, $lvl, var_export($out, true));
});
echo "\n=== probe done ===\n";
