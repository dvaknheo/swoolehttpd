<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */
namespace SwooleHttpd;

use SwooleHttpd\SwooleSingleton;
use SwooleHttpd\SwooleHttpd;
use Exception;
use Swoole\Coroutine;

class SwooleExt
{
    use SwooleSingleton;
    
    protected $context_class;
    protected $is_inited = false;
    protected $is_error = false;
    protected $in_fake = false;
    
    public function init($options = [], $context = null)
    {
        if (PHP_SAPI !== 'cli') {
            return $this;
        }
        if ($this->is_inited) {
            return $this;
        }
        $this->is_inited = true;
        
        if (!class_exists(Coroutine::class)) {
            return $this;
        }
        
        $this->context_class = get_class($context);
        $cid = Coroutine::getuid();
        if ($cid > 0) {
            ($this->context_class)::G()->onSwooleHttpdRequest(SwooleHttpd::G());
            return;
        }
        
        $this->replaceInstances();
        
        $options['http_handler'] = [static::class,'RunSwoole'];
        SwooleHttpd::G()->init($options, null);
        ($this->context_class)::G()->onSwooleHttpdInit(SwooleHttpd::G(), [static::class,'OnAppRun']);
        
        return $this;
    }
    protected function replaceInstances()
    {
        $server = SwooleHttpd::G();
        $classes = ($this->context_class)::G()->getStaticComponentClasses();
        $classes[] = $this->context_class;
        $instances = [];
        foreach ($classes as $class) {
            $instances[$class] = $class::G();
        }
        $flag = SwooleHttpd::ReplaceDefaultSingletonHandler();
        if (!$flag) {
            return;
        }
        
        // replace G method again;
        static::G($this);
        SwooleHttpd::G($server);
        foreach ($instances as $class => $object) {
            $class = (string)$class;
            $class::G($object);
        }
    }
    public static function OnAppRun()
    {
        return static::G()->_OnAppRun();
    }
    public function _OnAppRun()
    {
        if (!$this->is_inited) {
            return;
        }
        $cid = Coroutine::getuid();
        if ($cid > 0) {
            return;
        }
        ($this->context_class)::G()->onSwooleHttpdStart(SwooleHttpd::G());
        SwooleHttpd::G()->run();

        // OK ,we need not return .
        $this->is_error = true;
        throw new Exception('run break;', 500);
    }
    public static function RunSwoole()
    {
        return static::G()->_RunSwoole();
    }
    public function _RunSwoole()
    {
        $classes = ($this->context_class)::G()->getDynamicComponentClasses();
        $exclude_classes = SwooleHttpd::G()->getDynamicComponentClasses();
        SwooleHttpd::G()->forkMasterInstances($classes, $exclude_classes);
        
        $ret = ($this->context_class)::G()->run();
        if ($ret) {
            return true;
        }
        if (SwooleHttpd::G()->is_with_http_handler_root()) {
            //SwooleHttpd::G()->forkMasterInstances([get_class(($this->context_class)::G())]);
            SwooleHttpd::G()->forkMasterClassesToNewInstances();
            return false;
        }
        return true;
    }
}
