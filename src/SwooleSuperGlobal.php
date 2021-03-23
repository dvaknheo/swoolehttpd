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
    
    public $_GET;
    public $_POST;
    public $_REQUEST;
    public $_SERVER = [];
    public $_ENV;
    public $_COOKIE = [];
    public $_SESSION;
    public $_FILES = [];
    public $is_inited = false;
    
    public function __construct()
    {
        $this->init();
    }
    public function init()
    {
        // 这里
        $cid = Coroutine::getuid();
        if ($cid <= 0) {
            return;
        }
        static::DefineSuperGlobalContext();
        
        if ($this->is_inited) {
            return $this;
        }
        $this->is_inited = true;
        
        $request = SwooleHttpd::Request();
        
        if (!$request) {
            return;
        }
        
        $this->_GET = $request->get ?? [];
        $this->_POST = $request->post ?? [];
        $this->_COOKIE = $request->cookie ?? [];
        $this->_REQUEST = array_merge($request->get ?? [], $request->post ?? []);
        $this->_ENV = &$_ENV;
        
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
        
        // fixed swoole system bug
        if (!empty($this->_GET)) {
            $this->_SERVER['REQUEST_URI'] .= '?'.http_build_query($this->_GET);
        }
        
        return $this;
    }
    public function mapToGlobal()
    {
        $_GET = $this->_GET;
        $_POST = $this->_POST;
        $_REQUEST = $this->_REQUEST;
        $_SERVER = $this->_SERVER;
        // $_ENV       =&$this->_ENV; no need
        $_COOKIE = $this->_COOKIE;
        $_SESSION = $this->_SESSION;
        $_FILES = $this->_FILES;
    }
    ////////////////////////////
    public static function DefineSuperGlobalContext()
    {
        if(!defined('__SUPERGLOBAL_CONTEXT')){
            define('__SUPERGLOBAL_CONTEXT',static::class .'::G');
            return true;
        }
        return false;
    }
}
