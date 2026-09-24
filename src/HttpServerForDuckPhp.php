<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */
namespace SwooleHttpd;

use DuckPhp\HttpServer\HttpServerInterface;
use Swoole\Http\Server as Http_Server;

/**
 * DuckPhp's HTTP server plug-in: runs a DuckPhp application on Swoole.
 *
 * How DuckPhp picks it up
 * -----------------------
 * `DuckPhp\Component\Command::command_run()` does:
 *
 *     $cli_options['http_app_class'] = get_class($this->context());
 *     $cli_options['path']           = $this->context()->options['path'];
 *     if (!empty($cli_options['http_server'])) {
 *         $class = str_replace('/', '\\', $cli_options['http_server']);
 *         HttpServer::_($class::_());          // <- swaps the singleton
 *     }
 *     $this->context()->options['cli_enable'] = false;
 *     HttpServer::RunQuickly($cli_options);
 *
 * Because `HttpServer::RunQuickly()` is `static::_()->init($options)->run()` and
 * `static::_()` returns whatever was put in that singleton slot, simply being
 * swappable is enough. So:
 *
 *     php cli.php run --http_server=SwooleHttpd/HttpServerForDuckPhp --port=9528
 *
 * (`DuckPhpInstaller::runDemo()` swaps it the same way.)
 *
 * The four methods of DuckPhp\HttpServer\HttpServerInterface are implemented below.
 * Note that `RunQuickly($options)` deliberately keeps an untyped parameter: PHP
 * forbids an implementation from narrowing an untyped interface parameter.
 */
class HttpServerForDuckPhp implements HttpServerInterface
{
    use SwooleSingleton;

    public $options = [
        'host' => '127.0.0.1',
        'port' => 8080,
        'path' => '',
        'path_document' => 'public',
        'workers' => null,

        // DuckPhp application to drive. command_run() fills this in; when the server
        // is started by hand we fall back to DuckPhp\DuckPhp itself.
        'http_app_class' => null,
        'http_app_options' => [],
        // Components to rebuild per coroutine instead of cloning (connections).
        'http_app_renew_classes' => [],

        'swoole_server_options' => [],
        'silent_mode' => false,
        'dry' => false,
    ];

    protected $is_inited = false;
    protected $pid = 0;
    /** @var Http_Server|null */
    protected $server = null;

    public static function _($object = null)
    {
        return static::G($object);
    }
    public static function RunQuickly($options)
    {
        return static::_()->init((array)$options)->run();
    }
    public function isInited(): bool
    {
        return $this->is_inited;
    }

    /**
     * @param array<string, mixed> $options
     * @param object|null $context
     * @return static
     */
    public function init(array $options, ?object $context = null)
    {
        $this->options = $options = array_replace($this->options, $options);
        if ($this->is_inited) {
            return $this;
        }
        if ($options['dry']) {
            echo "SwooleHttpd would listen on {$options['host']}:{$options['port']}\n";

            return $this;
        }
        if (!class_exists(Http_Server::class)) {
            throw new SwooleException('SwooleHttpd: the swoole extension is required.');
        }

        $app_class = $options['http_app_class'] ?: 'DuckPhp\\DuckPhp';
        if (!class_exists($app_class)) {
            throw new SwooleException("SwooleHttpd: DuckPhp application [{$app_class}] not found.");
        }

        // Define the macro BEFORE the application initialises: DuckPhp decides once,
        // at init, whether superglobals come from us instead of the shared $_GET etc.
        SwooleSuperGlobal::DefineSuperGlobalContext();

        $app_options = array_replace(
            ['path' => $options['path']],
            (array)$options['http_app_options']
        );
        // Whatever the CLI decided, a worker must never take the console branch.
        $app_options['cli_enable'] = false;

        $server_options = (array)$options['swoole_server_options'];
        if (!empty($options['workers']) && !isset($server_options['worker_num'])) {
            $server_options['worker_num'] = (int)$options['workers'];
        }

        $this->server = SwooleHttpd::G()->init([
            'host' => $options['host'],
            'port' => (int)$options['port'],
            'http_app_class' => $app_class,
            'http_app_options' => $app_options,
            'http_app_path_document' => $options['path_document'],
            'http_app_renew_classes' => $options['http_app_renew_classes'],
            'swoole_server_options' => $server_options,
            'silent_mode' => $options['silent_mode'],
        ])->server;

        $this->is_inited = true;

        return $this;
    }

    /** Start the server. Blocks until the server stops. */
    public function run()
    {
        if (!$this->is_inited) {
            throw new SwooleException('SwooleHttpd: call init() before run().');
        }
        $this->pid = (int)getmypid();
        SwooleHttpd::G()->run();

        return $this->pid;
    }

    public function getPid(): int
    {
        return $this->pid ?: (int)getmypid();
    }

    /** Ask the in-process server to stop. */
    public function close()
    {
        if (!$this->server) {
            return false;
        }
        $this->server->shutdown();

        return true;
    }
}
