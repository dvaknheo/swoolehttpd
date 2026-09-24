<?php declare(strict_types=1);
/**
 * Starts the DuckPhp fixture app on Swoole through SwooleHttpd.
 *
 * Usage: php dev/fixtures/duckphp-server.php [port]
 */
$dnmvcs = getenv('DNMVCS_PATH');
if (!$dnmvcs) {
    $dnmvcs = '/mnt/e/ProjectGoat/DNMVCS';
}
if (!is_file($dnmvcs.'/autoload.php')) {
    fwrite(STDERR, "DuckPhp (DNMVCS) not found at {$dnmvcs}. Set DNMVCS_PATH.\n");
    exit(1);
}
require $dnmvcs.'/autoload.php';
require __DIR__.'/../../autoload.php';

// The fixture project is not a composer package, so map its namespace by hand.
spl_autoload_register(function ($class) {
    $prefix = 'DpFixture\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return false;
    }
    $file = __DIR__.'/duckphp/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) {
        require $file;

        return true;
    }

    return false;
});

use SwooleHttpd\HttpServerForDuckPhp;

$server = HttpServerForDuckPhp::_();
$server->init([
    'host' => '127.0.0.1',
    'port' => (int)($argv[1] ?? 9540),
    'path' => __DIR__.'/duckphp/',
    'http_app_class' => DpFixture\System\App::class,
    // Give DB-ish components a fresh connection per coroutine instead of sharing one.
    'http_app_renew_classes' => [],
]);

// Captured in the master context, so the probe controller can prove that each
// request reused THIS instance rather than silently building a new application.
$GLOBALS['__dp_fixture_app'] = DpFixture\System\App::_();

$server->run();
