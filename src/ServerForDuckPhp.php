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
    public function init($options = [], $context = null)
    {
        $ret = parent::init($options, $context);
        $options['http_handler'] = [static::class,'OnServerRequest'];
        SwooleHttpd::G()->init($options, null);
        
        App::G()->options['skip_404_handler'] = true;
        App::assignExceptionHandler(WorkermanHttpd404Exception::class,function(){});
        App::system_wrapper_replace(SwooleHttpd::system_wrapper_get_providers());
        
        return $this;
    }
    public function run()
    {
        SwooleHttpd::G()->run();
    }
    /////////////////////////////
    public static function OnServerRequest()
    {
        return static::G()->_OnServerRequest();
    }
    public function _OnServerRequest()
    {
        $classes = App::G()->getDynamicComponentClasses();
        $exclude_classes = SwooleHttpd::G()->getDynamicComponentClasses();
        SwooleHttpd::G()->forkMasterInstances($classes, $exclude_classes);
        
        if (!$flag) {
            App::On404();
        }
        return true;
    }
}
