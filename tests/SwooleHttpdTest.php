<?php 
namespace tests\SwooleHttpd;
use SwooleHttpd\SwooleHttpd as App;

class SwooleHttpdTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        \MyCodeCoverage::G()->begin(App::class);
        
        //code here
function hello()
{
    echo "<h1> hello ,have a good start.</h1><pre>\n";
    var_export(App::SG());
    echo "</pre>";
    return true;
}

$options=[
    'port'=>9528,
    'http_handler'=>'hello',
];
App::RunQuickly($options);

        \MyCodeCoverage::G()->end(SwooleHttpd::class);
        $this->assertTrue(true);
        /*
        SwooleHttpd::G()->RunQuickly(array $options = [], $after_init = null);
        SwooleHttpd::G()->set_http_exception_handler($exception_handler);
        SwooleHttpd::G()->set_http_404_handler($http_404_handler);
        SwooleHttpd::G()->is_with_http_handler_root();
        SwooleHttpd::G()->exit_request($code = 0);
        SwooleHttpd::G()->Throw404();
        SwooleHttpd::G()->ThrowOn($flag, $message, $code = 0);
        SwooleHttpd::G()->fixIndex();
        SwooleHttpd::G()->onHttpRun($request, $response);
        SwooleHttpd::G()->prepareRootMode();
        SwooleHttpd::G()->runHttpFile($path, $document_root);
        SwooleHttpd::G()->includeHttpFullFile($full_file, $document_root, $path_info = '');
        SwooleHttpd::G()->includeHttpPhpFile($file, $document_root, $path_info);
        SwooleHttpd::G()->onHttpException($ex);
        SwooleHttpd::G()->onHttpClean();
        SwooleHttpd::G()->check_swoole();
        SwooleHttpd::G()->checkOverride($options);
        SwooleHttpd::G()->init(array $options, $server = null);
        SwooleHttpd::G()->run();
        SwooleHttpd::G()->initHttp($request, $response);
        SwooleHttpd::G()->deferGC();
        SwooleHttpd::G()->checkShutdown();
        SwooleHttpd::G()->onRequest($request, $response);
        SwooleHttpd::G()->OnShow404();
        SwooleHttpd::G()->OnException($ex);
        SwooleHttpd::G()->_OnShow404();
        SwooleHttpd::G()->_OnException($ex);
        SwooleHttpd::G()->Server();
        SwooleHttpd::G()->Request();
        SwooleHttpd::G()->Response();
        SwooleHttpd::G()->Frame();
        SwooleHttpd::G()->FD();
        SwooleHttpd::G()->IsClosing();
        SwooleHttpd::G()->SG($replacement_object = null);
        SwooleHttpd::G()->GLOBALS($k, $v = null);
        SwooleHttpd::G()->STATICS($k, $v = null);
        SwooleHttpd::G()->CLASS_STATICS($class_name, $var_name);
        SwooleHttpd::G()->header(string $string, bool $replace = true, int $http_status_code = 0);
        SwooleHttpd::G()->setcookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false);
        SwooleHttpd::G()->exit_system($code = 0);
        SwooleHttpd::G()->set_exception_handler($exception_handler);
        SwooleHttpd::G()->register_shutdown_function($callback, ...$args);
        SwooleHttpd::G()->session_start(array $options = []);
        SwooleHttpd::G()->session_destroy();
        SwooleHttpd::G()->session_set_save_handler(\SessionHandlerInterface $handler);
        SwooleHttpd::G()->system_wrapper_get_providers();
        SwooleHttpd::G()->ReplaceDefaultSingletonHandler();
        SwooleHttpd::G()->EnableCurrentCoSingleton();
        SwooleHttpd::G()->getStaticComponentClasses();
        SwooleHttpd::G()->getDynamicComponentClasses();
        SwooleHttpd::G()->forkMasterInstances($classes, $exclude_classes = []);
        SwooleHttpd::G()->forkMasterClassesToNewInstances();
        //*/
    }
}
