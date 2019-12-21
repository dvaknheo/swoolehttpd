<?php 
namespace tests\SwooleHttpd;
use SwooleHttpd\SwooleExtServerInterface;

class SwooleExtServerInterfaceTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        \MyCodeCoverage::G()->begin(SwooleExtServerInterface::class);
        
        //code here
        
        \MyCodeCoverage::G()->end(SwooleExtServerInterface::class);
        $this->assertTrue(true);
        /*
        SwooleExtServerInterface::G()->G($object = null);
        SwooleExtServerInterface::G()->SG($replacement_object = null);
        SwooleExtServerInterface::G()->ReplaceDefaultSingletonHandler();
        SwooleExtServerInterface::G()->system_wrapper_get_providers();
        SwooleExtServerInterface::G()->init(array $options, $server = null);
        SwooleExtServerInterface::G()->run();
        SwooleExtServerInterface::G()->is_with_http_handler_root();
        SwooleExtServerInterface::G()->set_http_exception_handler($callback);
        SwooleExtServerInterface::G()->set_http_404_handler($callback);
        SwooleExtServerInterface::G()->getDynamicComponentClasses();
        SwooleExtServerInterface::G()->forkMasterClassesToNewInstances();
        SwooleExtServerInterface::G()->forkMasterInstances($classes, $exclude_classes = []);
        //*/
    }
}
