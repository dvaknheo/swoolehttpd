<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */
namespace SwooleHttpd;

use SwooleHttpd\SwooleSingleton;
use SwooleHttpd\SwooleSessionHandler;
use Swool\Coroutine;

class SwooleContext
{
    use SwooleSingleton;
    public $request = null;
    public $response = null;
    public $fd = -1;
    public $frame = null;

    protected $session_handler = null;
    protected $session_id = null;
    protected $session_name = '';
    protected $sessionOptions = [];
    
    protected $is_session_started = false;
    
    public $shutdown_function_array = [];
    public function initHttp($request, $response)
    {
        $this->request = $request;
        $this->response = $response;
    }
    public function initWebSocket($frame)
    {
        $this->frame = $frame;
        $this->fd = $frame->fd;
    }
    public function cleanUp()
    {
        $this->request = null;
        $this->response = null;
        $this->fd = -1;
        $this->frame = null;
    }
    public function onShutdown()
    {
        $funcs = array_reverse($this->shutdown_function_array);
        foreach ($funcs as $v) {
            $func = array_shift($v);
            $func($v);
        }
        $this->shutdown_function_array = [];
    }
    public function regShutdown($call_data)
    {
        $this->shutdown_function_array[] = $call_data;
    }
    public function isWebSocketClosing()
    {
        return $this->frame->opcode == 0x08?true:false;
    }
    public function header(string $string, bool $replace = true, int $http_status_code = 0)
    {
        if (!$this->response) {
            return;
        }
        if ($http_status_code) {
            $this->response->status($http_status_code);
        }
        if (strpos($string, ':') === false) {
            return;
        } // 404,500 so on
        list($key, $value) = explode(':', $string);
        $this->response->header($key, $value);
    }
    public function setcookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false)
    {
        return $this->response->cookie($key, $value, $expire, $path, $domain, $secure, $httponly);
    }
    /////////////////////////////////////////////////
    
    
    public function session_set_save_handler($handler)
    {
        return $this->session_handler = $handler;
    }
    protected function getSessionOption($key)
    {
        return $this->sessionOptions[$key] ?? ini_get('session.'.$key);
    }
    protected function getSessionId()
    {
        $session_name = $this->getSessionOption('name');
        
        $cookies = $this->request->cookie ?? [];
        $session_id = $cookies[$session_name] ?? null;
        if ($session_id === null || ! preg_match('/[a-zA-Z0-9,-]+/', $session_id)) {
            $session_id = $this->create_sid();
        }
        
        $this->setcookie(
            $session_name,
            $session_id,
            $this->getSessionOption('cookie_lifetime')?time() + $this->getSessionOption('cookie_lifetime'):0,
            $this->getSessionOption('cookie_path'),
            $this->getSessionOption('cookie_domain'),
            $this->getSessionOption('cookie_secure'),
            $this->getSessionOption('cookie_httponly')
        );
        return $session_id;
    }
    protected function deleteSessionId()
    {
        $session_name = $this->getSessionOption('name');
        $this->setcookie($session_name, '');
        $this->session_id = null;
    }
    protected function registWriteClose()
    {
        $this->regShutdown([static::class,'DoWriteClose']);
    }
    public function session_start(array $options = [])
    {
        if (!$this->session_handler) {
            $this->session_handler = SwooleSessionHandler::G();
        }
        $this->is_session_started = true;
        $this->sessionOptions = $options;
        $this->registWriteClose();
        $session_name = $this->getSessionOption('name');
        $session_save_path = session_save_path();
        $this->session_id = $this->session_id ?? $this->getSessionId();
        
        if ($this->getSessionOption('gc_probability') > mt_rand(0, $this->getSessionOption('gc_divisor'))) {
            $this->session_handler->gc($this->getSessionOption('gc_maxlifetime'));
        }
        $this->session_handler->open($session_save_path, $session_name);
        $raw = $this->session_handler->read($this->session_id);
        
        $data = unserialize($raw);
        (__SUPERGLOBAL_CONTEXT)()->_SESSION = $data;
    }
    public function session_id($session_id = null)
    {
        if (isset($session_id)) {
            $this->session_id = $session_id;
        }
        return $this->session_id;
    }
    public function session_destroy()
    {
        $this->session_handler->destroy($this->session_id);
        (__SUPERGLOBAL_CONTEXT)()->_SESSION = null;
        $this->deleteSessionId();
        $this->is_session_started = false;
    }
    public function DoWriteClose()
    {
        return static::G()->writeClose();
    }
    public function writeClose()
    {
        if (!$this->is_session_started) {
            return;
        }
        $this->session_handler->write($this->session_id, serialize($this->_SESSION));
        (__SUPERGLOBAL_CONTEXT)()->_SESSION = null;
    }
    public function create_sid()
    {
        $cid = Coroutine::getuid();
        return md5(microtime().' '.$cid.' '.mt_rand());
    }

}
