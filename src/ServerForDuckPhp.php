<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */
namespace SwooleHttpd;

use SwooleHttpd\SwooleHttpd;
use DuckPhp\HttpServer\HttpServer;
use DuckPhp\Core\App;

class HttpServerForDuckPhp extends HttpServer
{
    use SwooleSingleton;
    protected $context_class;
    public function init($options = [], $context = null)
    {
        $options['http_handler'] = [static::class,'RunSwoole'];
        SwooleHttpd::G()->init($options, null);
        $this->replaceInstances();
        
        App::G()->options['skip_404_handler'] = true;
        App::SetExceptionHandle(WorkermanHttpd404Exception::class,function(){});
        App::system_wrapper_replace(SwooleHttpd::system_wrapper_get_providers());  //  替换系统函数
        
        return $this;
    }
    public function run()
    {
        SwooleHttpd::G()->run();
    }
    /////////////////////////////
    protected function replaceInstances()
    {
        $server = SwooleHttpd::G();
        $classes = ($this->context_class)::G()->getStaticComponentClasses();
        // Ext 那些也要追加。
        
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
    public static function RunSwoole()
    {
        return static::G()->_RunSwoole();
    }
    public function _RunSwoole()
    {
        $classes = App::G()->getDynamicComponentClasses();
        $exclude_classes = SwooleHttpd::G()->getDynamicComponentClasses();
        SwooleHttpd::G()->forkMasterInstances($classes, $exclude_classes);
        
        $ret = App::G()->run();
        
        if ($ret) {
            return true;
        }
        if (SwooleHttpd::G()->is_with_http_handler_root()) {
            SwooleHttpd::G()->forkMasterClassesToNewInstances();
            return false;
        }
        return true;
    }
}
