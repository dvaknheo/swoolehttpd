<?php 
namespace tests\SwooleHttpd;
use SwooleHttpd\SwooleSuperGlobal;

class SwooleSuperGlobalTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        \MyCodeCoverage::G()->begin(SwooleSuperGlobal::class);
        
        //code here
        
        \MyCodeCoverage::G()->end(SwooleSuperGlobal::class);
        $this->assertTrue(true);
        /*
        SwooleSuperGlobal::G()->__construct();
        SwooleSuperGlobal::G()->init();
        SwooleSuperGlobal::G()->mapToGlobal();
        SwooleSuperGlobal::G()->_GLOBALS($k, $v = null);
        SwooleSuperGlobal::G()->_STATICS($name, $value = null, $parent = 0);
        SwooleSuperGlobal::G()->_CLASS_STATICS($class_name, $var_name);
        SwooleSuperGlobal::G()->session_set_save_handler($handler);
        SwooleSuperGlobal::G()->getSessionOption($key);
        SwooleSuperGlobal::G()->getSessionId();
        SwooleSuperGlobal::G()->deleteSessionId();
        SwooleSuperGlobal::G()->registWriteClose();
        SwooleSuperGlobal::G()->session_start(array $options = []);
        SwooleSuperGlobal::G()->session_id($session_id = null);
        SwooleSuperGlobal::G()->session_destroy();
        SwooleSuperGlobal::G()->writeClose();
        SwooleSuperGlobal::G()->create_sid();
        //*/
    }
}
