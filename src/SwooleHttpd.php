<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */

//dvaknheo@github.com
//OK，Lazy
namespace SwooleHttpd;

use SwooleHttpd\SwooleSingleton;
use SwooleHttpd\SwooleCoroutineSingleton;

use SwooleHttpd\SimpleWebSocketd;

// NOTE: the traits below live in this very namespace and are declared at the
// bottom of this file. They need no `use` import; the phantom imports that used
// to be here (SwooleHttpd_Static / _SuperGlobal / _Singleton) referenced traits
// that do not exist anywhere — they were leftovers of the 2019-12-21 split.

use Swoole\ExitException;
use Swoole\Http\Server as Http_Server;
use Swoole\WebSocket\Server as Websocket_Server;
use Swoole\Runtime;
use Swoole\Coroutine;

// Optional: only touched when a DuckPhp application class is configured.
use DuckPhp\Core\SystemWrapper;
use DuckPhp\Core\ExceptionManager;

class SwooleHttpd //implements SwooleExtServerInterface
{
    const VERSION = '1.1.4-dev';
    use SwooleSingleton;
    
    use SwooleHttpd_SimpleHttpd;
    use SimpleWebSocketd;
    
    use SwooleHttpd_Handler;
    use SwooleHttpd_Glue;
    use SwooleHttpd_SystemWrapper;
    use SwooleHttpd_SingletonHandle;
    use SwooleHttpd_Runner;
    
    public $options = [
            'host' => '127.0.0.1',
            'port' => 8080,
            'swoole_server' => null,        // inject a ready Swoole\Http\Server instead of creating one
            'swoole_server_options' => [],  // merged into Swoole\Http\Server::set()
            
            'http_app_class' => null,       // DuckPhp application class (see HttpServerForDuckPhp)
            'http_app_options' => [],       // extra options handed to that application's init()
            'http_app_path_document' => 'public',  // doc root inside the project (falls back to the root)
            'http_app_renew_classes' => [], // components rebuilt per coroutine instead of cloned
            'http_handler' => null,
            'http_handler_basepath' => '',
            'http_handler_root' => null,
            'http_handler_file' => null,
            'http_exception_handler' => null,
            'http_404_handler' => null,
            
            'with_http_handler_root' => false,
            'with_http_handler_file' => false,
            
            'enable_fix_index' => true,
            'enable_path_info' => true,
            'enable_resource_file' => true,
            
            'websocket_open_handler' => null,
            'websocket_handler' => null,
            'websocket_exception_handler' => null,
            'websocket_close_handler' => null,
            
            'base_class' => '',
            'silent_mode' => false,
            'enable_coroutine' => true,
        ];
    public $server = null;
    
    public $http_handler = null;

    /** The DuckPhp application class, once initApp() has run. */
    public $app_class = '';

    protected $static_root = null;
    protected $auto_clean_autoload = true;
    protected $old_autoloads = [];
    
    public $is_shutdown = false;
    public static function RunQuickly(array $options = [], callable $after_init = null)
    {
        if (!$after_init) {
            return static::G()->init($options)->run();
        }
        static::G()->init($options);
        ($after_init)();
        return static::G()->run();
    }

    public function is_with_http_handler_root()
    {
        return $this->options['with_http_handler_root'];
    }

    /////
    public function _exit($code = 0)
    {
        exit($code);
    }
    public function set_http_exception_handler($ex)
    {
        $this->options['http_exception_handler'] = $ex;
    }
    public function set_http_404_handler($handler)
    {
        $this->options['http_404_handler'] = $handler;
    }
    /**
     * Throw when a condition fails. Unlike DuckPhp (which has its own ThrowOn via
     * BusinessHelper), this raises SwooleHttpd\SwooleException.
     */
    public static function ThrowOn($flag, string $message = '', int $code = 0)
    {
        if ($flag) {
            throw new SwooleException($message, $code);
        }
    }
    /** Abort the current request as a 404. */
    public static function Throw404(string $message = 'Not Found')
    {
        throw new Swoole404Exception($message, 404);
    }
    /** End the current request, like exit() but named so it reads as intent. */
    public static function exit_request($code = 0)
    {
        return static::G()->_exit($code);
    }
    ////


    
    protected function onHttpException($ex)
    {
        if ($ex instanceof ExitException) {
            return;
        }
        static::OnException($ex);
    }
    protected function onHttpClean()
    {
        if (!$this->auto_clean_autoload) {
            return;
        }
        $functions = spl_autoload_functions();
        $this->old_autoloads = $this->old_autoloads?:[];
        $functions = is_array($functions)?$functions:[];
        foreach ($functions as $function) {
            if (in_array($function, $this->old_autoloads)) {
                continue;
            }
            spl_autoload_unregister($function);
        }
    }
    protected function check_swoole()
    {
        if (!function_exists('swoole_version')) {
            echo 'SwooleHttpd: PHP Extension swoole needed;';
            exit;
        }
        if (version_compare(swoole_version(), '4.2.0', '<')) {
            echo 'SwooleHttpd: swoole >=4.2.0 needed;';
            exit;
        }
    }

    
    public function init(array $options, $server = null)
    {
        $this->options = $options = array_merge($this->options, $options);

        // Hand the whole initialization over to the configured base class, so a
        // project can replace SwooleHttpd without touching its own entry file.
        $base_class = $this->options['base_class'];
        if ($base_class && !is_a($this, $base_class)) {
            $object = new $base_class();
            static::G($object);                 // make ClassName::G() return the replacement
            return $object->init($this->options, $server);
        }

        $this->options['http_handler_basepath'] = rtrim((string)realpath($this->options['http_handler_basepath']), '/').'/';        
        $this->options['http_handler_basepath'] = ($this->options['http_handler_basepath'] === '/')?'':$this->options['http_handler_basepath'];
        
        // A pre-built server object may be injected either as the 2nd argument or via
        // the 'swoole_server' option (handy for tests, and for sharing one server
        // between several handlers).
        $server = $server ?? $this->options['swoole_server'];
        if ($server) {
            $this->server = $server;
        } else {
            $this->createServer();
        }
        /////////
        
        
        $this->server->set($this->options['swoole_server_options']);
        $this->server->on('request', [$this,'onRequest']);
        if ($this->server->setting['enable_static_handler'] ?? false) {
            $this->static_root = $this->server->setting['document_root'];
        }
        
        $this->websocket_open_handler = $this->options['websocket_open_handler'];
        $this->websocket_handler = $this->options['websocket_handler'];
        $this->websocket_exception_handler = $this->options['websocket_exception_handler'];
        $this->websocket_close_handler = $this->options['websocket_close_handler'];
        
        if ($this->websocket_handler) {
            $this->server->set(['open_websocket_close_frame' => true]);
            $this->server->on('message', [$this,'onMessage']);
            $this->server->on('open', [$this,'onOpen']);
        }
        if($this->options['http_app_class']){
            $this->initApp();
        }
        
        
        ////[[[[
        if ($this->options['enable_coroutine']) {
            Runtime::enableCoroutine();
        }
        SwooleCoroutineSingleton::ReplaceDefaultSingletonHandler();
        
        return $this;
    }
    /**
     * Set up the DuckPhp application this server will drive.
     *
     * Rewritten for DuckPhp 1.4.x: the 2019-era calls (::G(),
     * assignExceptionHandler()/system_wrapper_replace() as App statics,
     * skip_404_handler, getDynamicComponentClasses()) no longer exist.
     */
    protected function initApp()
    {
        $app_class = $this->options['http_app_class'];
        $app_options = (array)$this->options['http_app_options'];

        // Under Swoole PHP_SAPI is 'cli' and DuckPhp's cli_enable defaults to true;
        // with it on, run()/RunQuickly() would take the console branch instead of
        // serve(), so a request would execute a command instead of rendering a page.
        $app_options['cli_enable'] = false;

        if (empty($app_options['path'])) {
            $app_options['path'] = $this->options['http_handler_basepath'];
        }

        // Isolation lives in the container (see CoroutinePhaseContainer): install it
        // BEFORE init() so the master template is built inside it.
        CoroutinePhaseContainer::install(
            (array)$this->options['http_app_renew_classes'],
            [],
            [$app_class]
        );

        $app_class::_()->init($app_options);
        $this->app_class = $app_class;

        // Route header()/setcookie()/exit()/session_*()/mime_content_type() to Swoole.
        // DuckPhp's default implementations are no-ops under the CLI SAPI.
        if (class_exists(SystemWrapper::class)) {
            SystemWrapper::_()->_system_wrapper_replace(static::system_wrapper_get_providers());
        }

        // Under Swoole, exit() inside a coroutine raises Swoole\ExitException instead
        // of killing the worker. DuckPhp only recognises its OWN __EXIT_EXCEPTION, so
        // without this the exception reaches the 500 handler and a plain `exit` ends
        // up rendering an "Internal Error" page after the real output.
        if (class_exists(ExceptionManager::class)) {
            ExceptionManager::_()->assignExceptionHandler(ExitException::class, function () {
                // Deliberately empty: stopping output is the whole request.
            });
        }
    }
    public function createServer()
    {
        $this->check_swoole();
        
        if (!$this->options['port']) {
            echo static::class . ': No port ,set the port';
            exit;
        }
        if (!$this->options['websocket_handler']) {
            $this->server = new Http_Server($this->options['host'], (int) $this->options['port']);
        } else {
            echo static::class . ": use WebSocket\n";
            $this->server = new Websocket_Server($this->options['host'], $this->options['port']);
        }
    }
    public function run()
    {
        if (!$this->options['silent_mode']) {
            fwrite(STDOUT, "[".DATE(DATE_ATOM)."] ".get_class($this)." run at http://".$this->server->host.':'.$this->server->port."/ ...\n");
        }
        $this->server->start();
        if (!$this->options['silent_mode']) {
            fwrite(STDOUT, get_class($this)." run end ".DATE(DATE_ATOM)." ...\n");
        }
    }
}
trait SwooleHttpd_RunFile
{
    //
}
trait SwooleHttpd_SimpleHttpd
{
    protected function deferGC()
    {
        Coroutine::defer(
            function () {
                gc_collect_cycles();
            }
        );
    }
    protected function checkShutdown()
    {
        if (!$this->is_shutdown) {
            return;
        }
        throw new \Exception("Shutdowning...".date(DATE_ATOM));
    }
    public function onRequest($request, $response)
    {
        $this->deferGC();
        SwooleCoroutineSingleton::EnableCurrentCoSingleton();
        $this->checkShutdown();

        // Everything the handler echoes is buffered here and sent as ONE response at
        // the very end of the request. The old code called $response->end() from an
        // ob_start() callback instead, which fires on every implicit flush — so any
        // body larger than the buffer (or an explicit flush) triggered a second
        // end() and lost the payload.
        $init_ob_level = ob_get_level();
        ob_start();

        Coroutine::defer(
            function () use ($init_ob_level) {
                // This runs outside the handler's try/catch, so it must never throw:
                // an exception here escapes into Swoole's request shutdown and can
                // take the whole worker down.
                $content = '';
                try {
                    // 1) user-registered shutdown functions (session writeClose etc.)
                    SwooleContext::G()->onShutdown();
                } catch (\Throwable $ex) {
                    try {
                        $this->_OnException($ex);
                    } catch (\Throwable $ignored) {
                        // nothing sensible left to do
                    }
                }

                try {
                    // 2) collapse every buffer this request opened. They are unwound
                    //    innermost-first, so prepend to keep the original order.
                    for ($i = ob_get_level(); $i > $init_ob_level; $i--) {
                        $content = ob_get_clean() . $content;
                    }

                    // 3) send exactly one response, then tidy up. sendResponse() is a
                    //    no-op when the body was already completed (sendfile(), or a
                    //    handler that ended it explicitly).
                    SwooleContext::G()->sendResponse($content);
                } catch (\Throwable $ex) {
                    // last resort: never leave the client hanging
                    for ($i = ob_get_level(); $i > $init_ob_level; $i--) {
                        ob_end_clean();
                    }
                    SwooleContext::G()->sendResponse('');
                }
                SwooleContext::G()->cleanUp();
            }
        );

        ///////////////////
        SwooleContext::G(new SwooleContext())->initHttp($request, $response);
        // Fresh per request: in php-fpm a static/global resets between requests, and
        // the whole point of these objects is to emulate that under coroutines.
        StaticReplacer::G(new StaticReplacer());
        SwooleSuperGlobal::G(new SwooleSuperGlobal())->SaveSuperGlobalAll();
        
        $flag = true;
        try {
            $flag = $this->onHttpRun($request, $response);
        } catch (ExitException $ex) {
            // exit() inside a coroutine throws Swoole\ExitException instead of
            // killing the worker. That is a normal "stop producing output", not an
            // error: just fall through so the buffered body still gets flushed.
            $flag = true;
        } catch (Swoole404Exception $ex) {
            $flag = false;
        } catch (\Throwable $ex) {
            $this->_OnException($ex);
        }
        if (!$flag) {
            $this->_OnShow404();
        }
        $this->onHttpClean();
    }
}

trait SwooleHttpd_Handler
{
    public static function OnShow404()
    {
        return static::G()->_OnShow404();
    }
    public static function OnException($ex)
    {
        return static::G()->_OnException($ex);
    }
    public function _OnShow404()
    {
        //
        if ($this->options['http_404_handler']) {
            ($this->options['http_404_handler'])();
            return;
        }
        static::header('', true, 404);
        echo "SwooleHttpd: Server 404 \n";
    }
    public function _OnException($ex)
    {
        static::header('', true, 500);
        
        if ($this->options['http_exception_handler']) {
            ($this->options['http_exception_handler'])($ex);
            return;
        }
        
        echo static::class . ": Server Error. \n".$ex;
    }
}
trait SwooleHttpd_Glue
{
    public static function Server()
    {
        return static::G()->server;
    }
    public static function Request()
    {
        return SwooleContext::G()->request;
    }
    public static function Response()
    {
        return SwooleContext::G()->response;
    }
    public static function Frame()
    {
        return SwooleContext::G()->frame;
    }
    public static function Fd()
    {
        return SwooleContext::G()->fd;
    }
    public static function IsClosing()
    {
        return SwooleContext::G()->isWebSocketClosing();
    }
    /**
     * The per-request superglobal store — the coroutine-safe replacement for
     * $_GET/$_POST/$_SERVER/$_COOKIE/$_SESSION/$_FILES.
     *
     * Pass an object to replace the store (DuckPhp plugs its own in through the
     * __SUPERGLOBAL_CONTEXT macro).
     *
     * @param object|null $replacement_object
     * @return SwooleSuperGlobal
     */
    public static function SG($replacement_object = null)
    {
        return SwooleSuperGlobal::G($replacement_object);
    }
    /////////////
    public static function &GLOBALS($k, $v = null)
    {
        return StaticReplacer::G()->_GLOBALS($k, $v);
    }
    public static function &STATICS($k, $v = null)
    {
        return StaticReplacer::G()->_STATICS($k, $v, 1);
    }
    public static function &CLASS_STATICS($class_name, $var_name)
    {
        return StaticReplacer::G()->_CLASS_STATICS($class_name, $var_name);
    }
    
    public static function ReplaceDefaultSingletonHandler()
    {
        return SwooleCoroutineSingleton::ReplaceDefaultSingletonHandler();
    }
    public static function EnableCurrentCoSingleton()
    {
        return SwooleCoroutineSingleton::EnableCurrentCoSingleton();
    }
}
trait SwooleHttpd_SystemWrapper
{
    public static function header(string $string, bool $replace = true, int $http_status_code = 0)
    {
        return SwooleContext::G()->header($string, $replace, $http_status_code);
    }
    public static function setcookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false)
    {
        return SwooleContext::G()->setcookie($key, $value, $expire, $path, $domain, $secure, $httponly);
    }
    public static function exit($code = 0)
    {
        return static::G()->_exit($code);
    }
    public static function set_exception_handler(callable $exception_handler)
    {
        return static::G()->set_http_exception_handler($exception_handler);
    }
    public static function register_shutdown_function(callable $callback, ...$args)
    {
        return SwooleContext::G()->regShutDown($callback, $args);
    }
    
    // Session state lives in SwooleContext (which owns SwooleSessionHandler), NOT
    // in SwooleSuperGlobal. The old code called these three on SwooleSuperGlobal,
    // which never had them — every session_start() died with a fatal error.
    public static function session_start(array $options = [])
    {
        return SwooleContext::G()->session_start($options);
    }
    public static function session_id($session_id = null)
    {
        return SwooleContext::G()->session_id($session_id);
    }
    public static function session_destroy()
    {
        return SwooleContext::G()->session_destroy();
    }
    public static function session_set_save_handler(\SessionHandlerInterface $handler)
    {
        return SwooleContext::G()->session_set_save_handler($handler);
    }
    public static function mime_content_type($file)
    {
        return SwooleContext::G()->mime_content_type($file);
    }
    
    public static function system_wrapper_get_providers():array
    {
        // Must cover every key in DuckPhp\Core\SystemWrapper::$system_handlers,
        // otherwise the missing ones silently fall back to the native PHP function
        // (which does nothing useful under Swoole).
        // NOTE: 'mime_content_type' is routed through SwooleContext too, so static
        // file serving does not depend on the fileinfo extension.
        $ret = [
            'header' => [static::class,'header'],
            'setcookie' => [static::class,'setcookie'],
            'exit' => [static::class,'exit'],
            'set_exception_handler' => [static::class,'set_exception_handler'],
            'register_shutdown_function' => [static::class,'register_shutdown_function'],
            'session_start' => [static::class,'session_start'],
            'session_id' => [static::class,'session_id'],
            'session_destroy' => [static::class,'session_destroy'],
            'session_set_save_handler' => [static::class,'session_set_save_handler'],
            'mime_content_type' => [static::class,'mime_content_type'],
        ];
        return $ret;
    }
}
trait SwooleHttpd_SingletonHandle
{

    //@inteface;
    public function getDynamicComponentClasses()
    {
        // StaticReplacer belongs here: without it, GLOBALS()/STATICS()/CLASS_STATICS()
        // fall back to the master instance and leak values across requests.
        $classes = [
            SwooleSuperGlobal::class,
            SwooleContext::class,
            StaticReplacer::class,
        ];
        return $classes;
    }
    //@inteface;
    public function forkMasterInstances($classes, $exclude_classes = [])
    {
        return SwooleCoroutineSingleton::G()->forkMasterInstances($classes, $exclude_classes);
    }
}
trait SwooleHttpd_Runner
{
    ///////////////////////////////
    // 这段要独立成 trait
    protected function fixIndex()
    {
        // 需要调整 script_filename 等。
        $index_file = 'index.php';
        $index_path = '/'.$index_file;
        $path_info = $_SERVER['PATH_INFO'];
        if (substr($path_info, 0, strlen($index_path)) === $index_path) {
            if (strlen($path_info) === strlen($index_path)) {
                $_SERVER['PATH_INFO'] = '';
            } else {
                if ($index_path.'/' === substr($path_info, 0, strlen($index_path) + 1)) {
                    $_SERVER['PATH_INFO'] = substr($path_info, strlen($index_path) + 1);
                }
            }
        }
    }
    public function _OnServerRequest()
    {
        $app_class = $this->options['http_app_class'];
        $app = $app_class::_();

        // Swoole reports none of DOCUMENT_ROOT / SCRIPT_FILENAME / REQUEST_SCHEME, but
        // DuckPhp needs them: KernelTrait::getDefaultProjectPath() derives the project
        // root from SCRIPT_FILENAME, and Route::getUrlBasePath() realpath()s
        // DOCUMENT_ROOT plus _Domain() reads REQUEST_SCHEME. Without them, generated
        // links degrade to "http:///".
        $path = rtrim((string)($app->options['path'] ?? ''), '/');
        $doc_root = $path;
        if ($this->options['http_app_path_document']) {
            $doc_root = $path.'/'.trim($this->options['http_app_path_document'], '/');
            if (!is_dir($doc_root)) {
                // No public/ directory in this project: fall back to the root, which
                // is what a front-controller layout expects anyway.
                $doc_root = $path;
            }
        }
        $entry = $path.'/index.php';

        $this->setServerValue('DOCUMENT_ROOT', $doc_root);
        $this->setServerValue('SCRIPT_FILENAME', $entry);
        $this->setServerValue('SCRIPT_NAME', '/index.php');
        $this->setServerValue('PHP_SELF', '/index.php');

        // Swoole does not report the scheme, and Route::_Domain() reads it.
        if (!isset(SwooleSuperGlobal::G()->_SERVER['REQUEST_SCHEME'])) {
            $this->setServerValue('REQUEST_SCHEME', 'http');
        }

        // serve() is the per-request entry point. It is NOT run(): that dispatches to
        // the console when cli_enable is on. All DuckPhp output goes through echo and
        // is captured by onRequest()'s output buffer.
        $app->serve();

        return true;
    }
    /**
     * Write a value into both the superglobal store (what DuckPhp reads through
     * __SUPERGLOBAL_CONTEXT) and the real $_SERVER (what legacy code reads).
     */
    protected function setServerValue(string $key, $value): void
    {
        $sg = SwooleSuperGlobal::G();
        $sg->_SERVER[$key] = $value;
        $_SERVER[$key] = $value;
    }
    
    protected function onHttpRun($request, $response)
    {
        if ($this->options['http_app_class']) {
            $this->old_autoloads = spl_autoload_functions();
            $this->_OnServerRequest();
            return true;
        }
        if ($this->options['http_handler']) {
            $this->auto_clean_autoload = false;
            if ($this->options['enable_fix_index']) {
                $this->fixIndex();
            }
            
            $flag = ($this->options['http_handler'])();
            if ($flag) {
                return true;
            }
            if (!$this->options['with_http_handler_root'] && !$this->options['http_handler_file']) {
                return false;
            }
            $this->auto_clean_autoload = true;
        }
        $this->old_autoloads = spl_autoload_functions();
        
        if ($this->options['http_handler_root']) {
            list($path, $document_root) = $this->prepareRootMode();
            /////
            $flag = $this->runHttpFile($path, $document_root);
            if ($flag) {
                return true;
            }
            if (!$this->options['with_http_handler_file'] || $this->options['http_handler']) {
                return false;
            }
        }
        if ($this->options['http_handler_file']) {
            $path_info = $_SERVER['REQUEST_URI'];
            $file = $this->options['http_handler_basepath'].$this->options['http_handler_file'];
            $document_root = dirname($file);
            $this->runPhpFile($file, $document_root, $path_info);
            // Must report success: onHttpRun()'s result decides whether the caller
            // emits a 404. Returning null here used to append "Server 404" to every
            // single-file-mode response.
            return true;
        }
        return false;
    }
    protected function prepareRootMode()
    {
        $http_handler_root = $this->options['http_handler_basepath'].$this->options['http_handler_root'];
        $http_handler_root = rtrim($http_handler_root, '/').'/';
        
        $document_root = $this->static_root?:rtrim($http_handler_root, '/');
        
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        return [$path,$document_root];
    }
    
    protected function runHttpFile($path, $document_root)
    {
        if (strpos($path, '/../') !== false || strpos($path, '/./') !== false) {
            return false;
        }
        
        $full_file = $document_root.$path;
        if ($path === '/') {
            $this->runPhpFile($document_root.'/index.php', $document_root, '');
            return true;
        }
        if (is_file($full_file)) {
            $this->includeHttpFullFile($full_file, $document_root, '');
            return true;
        }
        if (!$this->options['enable_path_info']) {
            if (is_dir($full_file)) {
                $full_file = rtrim($full_file, '/').'/index.php';
                if (is_file($full_file)) {
                    $this->includeHttpFullFile($full_file, $document_root, '');
                    return true;
                }
            }
            return false;
        }
        
        // x..php/abc/d
        $max = 1024;
        $offset = 0;
        for ($i = 0;$i < $max;$i++) {
            $offset = strpos($path, '.php/', $offset);
            if (false === $offset) {
                break;
            }
            $file = substr($path, 0, $offset).'.php';
            $path_info = substr($path, $offset + strlen('.php'));
            $file = $document_root.$file;
            if (is_file($file)) {
                $this->runPhpFile($file, $document_root, $path_info);
                return true;
            }
            
            $offset++;
        }
        
        $dirs = explode('/', $path);
        $prefix = '';
        foreach ($dirs as $block) {
            $prefix .= $block.'/';
            $file = $document_root.$prefix.'index.php';
            if (is_file($file)) {
                $path_info = substr($path, strlen($prefix) - 1);
                $this->runPhpFile($file, $document_root, $path_info);
                return true;
            }
        }
        return false;
    }
    protected function includeHttpFullFile($full_file, $document_root, $path_info = '')
    {
        $ext = pathinfo($full_file, PATHINFO_EXTENSION);
        if ($ext === 'php') {
            $this->runPhpFile($full_file, $document_root, $path_info);
            return;
        }
        if (!$this->options['enable_resource_file']) {
            return;
        }
        $this->send_file($full_file);
    }
    protected function runPhpFile($file, $document_root, $path_info)
    {
        $_SERVER['PATH_INFO'] = $path_info;
        $_SERVER['DOCUMENT_ROOT'] = $document_root;
        $_SERVER['SCRIPT_FILENAME'] = $file;
        $oldpath = getcwd();
        chdir(dirname($file));
        (function ($file) {
            include $file;
        })($file);
        chdir($oldpath);
        return true;
    }
    protected function send_file($full_file)
    {
        $response = static::Response();
        if (!$response) {
            return;
        }
        $mime = static::mime_content_type($full_file);
        $response->header('Content-Type', $mime);
        // sendfile() completes the response by itself: flag it so the request
        // teardown does not call end() a second time and lose the file.
        SwooleContext::G()->markResponseEnded();
        $response->sendfile($full_file);
    }
    ///////////////////
}