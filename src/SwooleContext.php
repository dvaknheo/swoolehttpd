<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */
namespace SwooleHttpd;

use SwooleHttpd\SwooleSingleton;
use SwooleHttpd\SwooleSessionHandler;
use Swoole\Coroutine;

class SwooleContext
{
    use SwooleSingleton;
    public $request = null;
    public $response = null;
    public $fd = -1;
    public $frame = null;

    /**
     * Whether the response for this request has already been completed.
     *
     * Swoole\Http\Response::end() may only be called once per request; a second
     * call logs "Http response is already sent" and the payload is lost. Everything
     * that finishes a response (our own flush, sendfile(), or user code calling
     * end() through sendResponse()) must therefore go through this flag.
     */
    protected $is_response_ended = false;

    protected $session_handler = null;
    protected $session_id = null;
    protected $session_name = '';
    protected $sessionOptions = [];
    
    protected $is_session_started = false;
    /** What we loaded at session_start(), used to detect legacy $_SESSION writes. */
    protected $session_snapshot = null;
    
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
        $this->is_response_ended = false;
    }
    /**
     * Complete the response exactly once. An empty body still has to be sent,
     * otherwise the client waits for the connection to time out.
     */
    public function sendResponse(string $content = ''): bool
    {
        if ($this->is_response_ended) {
            return false;
        }
        $this->is_response_ended = true;
        if (!$this->response) {
            return false;
        }
        $this->response->end($content);
        return true;
    }
    /**
     * Mark the response as complete without sending a body — for paths that end it
     * themselves, such as sendfile().
     */
    public function markResponseEnded(): void
    {
        $this->is_response_ended = true;
    }
    public function isResponseEnded(): bool
    {
        return $this->is_response_ended;
    }
    /**
     * Run the request's shutdown callbacks, most recently registered first.
     *
     * Entries are stored as [callable, args]. The old code did
     * `$func = array_shift($v); $func($v);` on a raw func_get_args() array, which
     * for the framework's own [ClassName, 'method'] callbacks tried to call
     * "ClassName" as a plain function — a fatal error that took the whole worker
     * down with it.
     */
    public function onShutdown()
    {
        $funcs = array_reverse($this->shutdown_function_array);
        $this->shutdown_function_array = [];
        foreach ($funcs as $entry) {
            list($callback, $args) = $entry;
            if (is_callable($callback)) {
                ($callback)(...$args);
            }
        }
    }
    /**
     * Register a callback to run when the request finishes.
     *
     * Entries are stored internally as [callable, args]. The single parameter is
     * deliberately loose so the historical raw form — the func_get_args() array
     * [callback, ...args] that the old implementation passed around — keeps
     * working. (PHP method names are case-insensitive, so this also answers the
     * regShutDown() spelling used by callers.)
     *
     * @param mixed $callback callable, or the legacy [callback, ...args] array
     * @param array<int, mixed> $args
     */
    public function regShutdown($callback, array $args = [])
    {
        if (!is_callable($callback) && is_array($callback)) {
            $legacy = array_values($callback);
            $callback = array_shift($legacy);
            $args = $legacy;
        }
        if (!is_callable($callback)) {
            return false;
        }
        $this->shutdown_function_array[] = [$callback, $args];

        return true;
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
        list($key, $value) = explode(':', $string, 2);
        // "X-Probe: yes" splits into " yes"; a leading space is not part of the value.
        $this->response->header(trim($key), ltrim($value));
    }
    public function setcookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false)
    {
        if (!$this->response) {
            return false;
        }
        return $this->response->cookie($key, $value, $expire, $path, $domain, $secure, $httponly);
    }
    /**
     * Content type for static file serving.
     *
     * Prefers the real mime_content_type() when the fileinfo extension is present,
     * and falls back to an extension table so that file serving does not hard-depend
     * on that extension. DuckPhp expects this on the system wrapper, so it must not
     * call back into SystemWrapper (that would recurse).
     */
    public function mime_content_type($file)
    {
        if (function_exists('mime_content_type') && is_file($file)) {
            $mime = @mime_content_type($file);
            if ($mime) {
                return $mime;
            }
        }
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        $map = [
            'html' => 'text/html', 'htm' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript', 'mjs' => 'application/javascript',
            'json' => 'application/json', 'xml' => 'text/xml',
            'txt' => 'text/plain', 'csv' => 'text/csv',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
            'pdf' => 'application/pdf', 'zip' => 'application/zip',
            'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        ];

        return $map[$ext] ?? 'application/octet-stream';
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
        
        // ini_get() returns strings; the setcookie() signature is strictly typed.
        $lifetime = (int)$this->getSessionOption('cookie_lifetime');
        $this->setcookie(
            $session_name,
            $session_id,
            $lifetime ? time() + $lifetime : 0,
            (string)$this->getSessionOption('cookie_path'),
            (string)$this->getSessionOption('cookie_domain'),
            (bool)$this->getSessionOption('cookie_secure'),
            (bool)$this->getSessionOption('cookie_httponly')
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
        // Must be an *instance* callable: writeClose() is not static, and the
        // historical [ClassName, 'method'] form made PHP raise
        // "Non-static method ... cannot be called statically".
        $this->regShutdown([$this, 'writeClose']);
    }
    public function session_start(array $options = [])
    {
        if (!$this->session_handler) {
            $this->session_handler = SwooleSessionHandler::G();
        }
        if ($this->is_session_started) {
            return true;
        }
        $this->is_session_started = true;
        $this->sessionOptions = $options;
        $this->registWriteClose();
        $session_name = $this->getSessionOption('name');
        $session_save_path = session_save_path();
        $this->session_id = $this->session_id ?? $this->getSessionId();
        
        if ((int)$this->getSessionOption('gc_probability') > mt_rand(0, (int)$this->getSessionOption('gc_divisor'))) {
            $this->session_handler->gc((int)$this->getSessionOption('gc_maxlifetime'));
        }
        $this->session_handler->open($session_save_path, $session_name);
        $raw = $this->session_handler->read($this->session_id);
        
        $data = $raw === '' ? [] : @unserialize($raw);
        if (!is_array($data)) {
            $data = [];
        }
        $this->session_snapshot = $data;
        // The object store is the coroutine-safe source of truth (DuckPhp reads it
        // through __SUPERGLOBAL_CONTEXT). The real $_SESSION gets a copy so that
        // code reading it right after session_start() still works — see the warning
        // in writeClose() about the two diverging.
        (__SUPERGLOBAL_CONTEXT)()->_SESSION = $data;
        $_SESSION = $data;

        return true;
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
        if ($this->session_handler) {
            $this->session_handler->destroy((string)$this->session_id);
        }
        (__SUPERGLOBAL_CONTEXT)()->_SESSION = null;
        $_SESSION = [];
        $this->session_snapshot = null;
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
        $this->is_session_started = false;

        $session = (__SUPERGLOBAL_CONTEXT)()->_SESSION;

        // Legacy compatibility: code that writes straight into $_SESSION never
        // touches our store. If the store was left exactly as we loaded it while the
        // real $_SESSION moved on, the superglobal is the one carrying the user's
        // edits — so persist that. (Under real concurrency $_SESSION is shared
        // between coroutines and cannot be trusted; use SwooleHttpd::SG()->_SESSION.)
        $real = $_SESSION ?? null;
        if (is_array($real) && $session === $this->session_snapshot && $real !== $this->session_snapshot) {
            $session = $real;
        }

        if ($session !== null && $session !== []) {
            $this->session_handler->write((string)$this->session_id, serialize($session));
        }
        (__SUPERGLOBAL_CONTEXT)()->_SESSION = null;
        $_SESSION = [];
        $this->session_snapshot = null;
    }
    public function create_sid()
    {
        $cid = Coroutine::getCid();
        return md5(microtime().' '.$cid.' '.mt_rand());
    }

}
