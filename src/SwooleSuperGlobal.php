<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */
namespace SwooleHttpd;

use SwooleHttpd\SwooleHttpd;
use SwooleHttpd\SwooleSingleton;
use Swoole\Coroutine;

class SwooleSuperGlobal
{
    use SwooleSingleton;
    
    public $_GET = [];
    public $_POST = [];
    public $_REQUEST = [];
    public $_SERVER = [];
    public $_COOKIE = [];
    public $_SESSION = null;
    public $_FILES = [];
    public $is_inited = false;
    
    public function __construct()
    {
        $this->init();
    }
    public function init()
    {
        // Define the macro as early as possible: DuckPhp reads it to decide whether
        // to take superglobals from us instead of the real $_GET/$_SERVER.
        static::DefineSuperGlobalContext();

        $cid = Coroutine::getCid();
        if ($cid <= 0) {
            // Not inside a coroutine (e.g. the master process): nothing request-scoped to fill.
            return $this;
        }

        if ($this->is_inited) {
            return $this;
        }

        $request = SwooleHttpd::Request();

        if (!$request) {
            // No request bound yet. Deliberately do NOT set is_inited, so a later
            // call (once the request is available) can still populate us.
            return $this;
        }
        $this->is_inited = true;
        
        $this->_GET = $request->get ?? [];
        $this->_POST = $request->post ?? [];
        $this->_COOKIE = $request->cookie ?? [];
        $this->_REQUEST = array_merge($request->get ?? [], $request->post ?? []);
        
        $this->_SERVER = $_SERVER;
        if (isset($this->_SERVER['argv'])) {
            $this->_SERVER['cli_argv'] = $this->_SERVER['argv'];
            unset($this->_SERVER['argv']);
        }
        if (isset($this->_SERVER['argc'])) {
            $this->_SERVER['cli_argc'] = $this->_SERVER['argc'];
            unset($this->_SERVER['argc']);
        }
        foreach ($request->header as $k => $v) {
            $k = 'HTTP_'.str_replace('-', '_', strtoupper($k));
            $this->_SERVER[$k] = $v;
        }
        foreach ($request->server as $k => $v) {
            $this->_SERVER[strtoupper($k)] = $v;
        }
        $this->_SERVER['cli_script_filename'] = $this->_SERVER['SCRIPT_FILENAME'] ?? '';
        
        $this->_FILES = $request->files;
        
        // Swoole leaves REQUEST_URI without the query string, while php-fpm puts it
        // there. Re-append it — but only when it is really missing, otherwise a
        // request already carrying "?a=1" would become "/path?a=1?a=1"
        // (DuckPhp's Pager builds pagination links straight from REQUEST_URI).
        if (!empty($this->_GET) && strpos((string)$this->_SERVER['REQUEST_URI'], '?') === false) {
            $this->_SERVER['REQUEST_URI'] .= '?'.http_build_query($this->_GET);
        }
        
        return $this;
    }
    public static function DefineSuperGlobalContext()
    {
        if (!defined('__SUPERGLOBAL_CONTEXT')) {
            // DuckPhp's own convention is 'DuckPhp\Core\SuperGlobal::_'; mirror it so
            // the framework can consume this object with zero adapter code.
            define('__SUPERGLOBAL_CONTEXT', static::class .'::_');
            return true;
        }
        return false;
    }
    public static function LoadSuperGlobalAll()
    {
        return static::G()->_LoadSuperGlobalAll();
    }
    public static function SaveSuperGlobalAll()
    {
        return static::G()->_SaveSuperGlobalAll();
    }
    public function _LoadSuperGlobalAll()
    {
        $this->_GET = $_GET;
        $this->_POST = $_POST;
        $this->_REQUEST = $_REQUEST;
        $this->_SERVER = $_SERVER;
        //$this->_ENV = $_ENV;
        $this->_COOKIE = $_COOKIE;
        $this->_SESSION = $_SESSION ?? null;
        $this->_FILES = $_FILES;
    }
    public function _SaveSuperGlobalAll()
    {
        $_GET = $this->_GET;
        $_POST = $this->_POST;
        $_REQUEST = $this->_REQUEST;
        $_SERVER = $this->_SERVER;
        //$_ENV = $this->_ENV;
        $_COOKIE = $this->_COOKIE;
        $_SESSION = $this->_SESSION;
        $_FILES = $this->_FILES;
    }
}
