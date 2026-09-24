<?php
/**
 * SwooleHttpd 冒烟/并发测试用的示例应用。
 *
 * 路由用 REQUEST_URI 的 path 部分（而不是 PATH_INFO），避免依赖 swoole 对
 * path_info 的不同填充方式。
 *
 * 用法： php dev/fixtures/app.php
 */
use SwooleHttpd\SwooleHttpd;

require __DIR__.'/../../autoload.php';

function test_handler()
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $sg = SwooleHttpd::SG();

    switch ($path) {
        case '/':
            echo "hello";
            return true;

        case '/session':
            // 文档化的用法：对象存储（协程安全）
            SwooleHttpd::session_start();
            $sg->_SESSION['n'] = ($sg->_SESSION['n'] ?? 0) + 1;
            echo "n=".$sg->_SESSION['n'];
            return true;

        case '/session-legacy':
            // 传统写法：直接改 $_SESSION
            SwooleHttpd::session_start();
            $_SESSION['n'] = ($_SESSION['n'] ?? 0) + 1;
            echo "n=".$_SESSION['n'];
            return true;

        case '/session-destroy':
            SwooleHttpd::session_start();
            SwooleHttpd::session_destroy();
            echo "destroyed";
            return true;

        case '/slow':
            // 并发探针：先让出协程，再读数据。
            // tag 走对象存储（应当隔离），tag_raw 走真 $_GET（必然会串，用于记录事实）
            $tag = $sg->_GET['tag'] ?? 'none';
            Swoole\Coroutine::sleep(0.3);
            echo json_encode([
                'tag_obj'  => $sg->_GET['tag'] ?? null,
                'tag_raw'  => $_GET['tag'] ?? null,
                'uri_obj'  => $sg->_SERVER['REQUEST_URI'] ?? null,
                'uri_raw'  => $_SERVER['REQUEST_URI'] ?? null,
                'session'  => $sg->_SESSION ?? null,
            ]);
            return true;

        case '/statics':
            // 每次请求都应从 1 开始（StaticReplacer 每请求重建）
            $n =& SwooleHttpd::STATICS('n');
            $n = ($n ?? 0) + 1;
            echo "statics=".$n;
            return true;

        case '/globals':
            $g =& SwooleHttpd::GLOBALS('g');
            $g = ($g ?? 0) + 1;
            echo "globals=".$g;
            return true;

        case '/class-statics':
            $v =& SwooleHttpd::CLASS_STATICS(TestCounter::class, 'count');
            $v++;
            echo "class_statics=".$v;
            return true;

        case '/exit':
            echo "before-exit";
            exit(7);
            // phpstan-ignore-next-line
            echo "after-exit";

        case '/error':
            throw new RuntimeException("boom");

        case '/empty':
            return true;              // 完全不输出

        case '/big':
            echo str_repeat('A', 2 * 1024 * 1024);   // 2MB，超过任何输出缓冲
            return true;

        case '/cookie':
            SwooleHttpd::setcookie('probe', 'v1');
            SwooleHttpd::header('X-Probe: yes');
            SwooleHttpd::header('', true, 201);
            echo "cookie-set";
            return true;
    }

    return false;   // 交给 404
}

class TestCounter
{
    protected static $count = 100;
}

SwooleHttpd::RunQuickly([
    'port' => (int)($argv[1] ?? 9528),
    'http_handler' => 'test_handler',
]);
