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

use SwooleHttpd\SwooleHttpd_Static;
use SwooleHttpd\SwooleHttpd_SystemWrapper;
use SwooleHttpd\SwooleHttpd_SuperGlobal;
use SwooleHttpd\SwooleHttpd_Singleton;
use SwooleHttpd\SwooleHttpd_Handler;
//use SwooleHttpd\SwooleExtServerInterface;

use Swoole\ExitException;
use Swoole\Http\Server as Http_Server;
use Swoole\WebSocket\Server as Websocket_Server;
use Swoole\Runtime;
use Swoole\Coroutine;

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
            'port' => 0,
            'swoole_server_options' => [],   
            
            'http_app_class' => null,
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
        $this->options['http_handler_basepath'] = rtrim((string)realpath($this->options['http_handler_basepath']), '/').'/';        
        
        $this->createServer();
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
            $this->server->on('mesage', [$this,'onMessage']);
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
    protected function initApp()
    {
        $app=$this->options['http_app_class'];
        $app::G()->options['skip_404_handler'] = true;
        $app::assignExceptionHandler(ExitException::class,function(){});
        $app::system_wrapper_replace(static::system_wrapper_get_providers());
     
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
        
        Coroutine::defer(
            function () {
                $InitObLevel = 0;
                for ($i = ob_get_level();$i > $InitObLevel;$i--) {
                    ob_end_flush();
                }
                SwooleContext::G()->cleanUp();
            }
        );
        Coroutine::defer(
            function () {
                SwooleContext::G()->onShutdown();
            }
        );
        ob_start(
            function ($str) {
                if ('' === $str) {
                    return;
                }
                SwooleContext::G()->response->end($str);
            }
        );
        ///////////////////
        SwooleContext::G(new SwooleContext())->initHttp($request, $response);
        SwooleSuperGlobal::G(new SwooleSuperGlobal())->SaveSuperGlobalAll();
        
        $flag = true;
        try {
            $flag = $this->onHttpRun($request, $response);
        } catch (\Throwable $ex) {
            if (!($ex instanceof ExitException)) {
                $this->_OnException($ex);
            }
        }
        if(!$flag){
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
        return SwooleContext::G()->regShutDown(func_get_args());
    }
    
    public static function session_start(array $options = [])
    {
        return SwooleSuperGlobal::G()->session_start($options);
    }
    public static function session_destroy()
    {
        return SwooleSuperGlobal::G()->session_destroy();
    }
    public static function session_set_save_handler(\SessionHandlerInterface $handler)
    {
        return SwooleSuperGlobal::G()->session_set_save_handler($handler);
    }
    
    public static function system_wrapper_get_providers():array
    {
        $ret = [
            'header' => [static::class,'header'],
            'setcookie' => [static::class,'setcookie'],
            'exit' => [static::class,'exit'],
            'set_exception_handler' => [static::class,'set_exception_handler'],
            'session_start' => [static::class,'session_start'],
            'session_destroy' => [static::class,'session_destroy'],
            'session_set_save_handler' => [static::class,'session_set_save_handler'],
            'register_shutdown_function' => [static::class,'register_shutdown_function'],
        ];
        return $ret;
    }
}
trait SwooleHttpd_SingletonHandle
{

    //@inteface;
    public function getDynamicComponentClasses()
    {
        $classes = [
            SwooleSuperGlobal::class,
            SwooleContext::class,
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
        $app = $this->options['http_app_class'];
        $classes = $app::G()->getDynamicComponentClasses();
        $exclude_classes = $this->getDynamicComponentClasses();
        $this->forkMasterInstances($classes, $exclude_classes);
        
        $flag= $app::G()->run();
        if (!$flag) {
            $app::On404();
        }
        return true;
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
            return;
        }
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
        $mime = mime_content_type($full_file);
        static::Response()->header('Content-Type', $mime);
        static::Response()->sendfile($full_file);
    }
    ///////////////////
}