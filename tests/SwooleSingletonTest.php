<?php 
namespace tests\SwooleHttpd;
use SwooleHttpd\SwooleSingleton;

class SwooleSingletonTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        \MyCodeCoverage::G()->begin(SwooleSingleton::class);
        
        //code here
        
        \MyCodeCoverage::G()->end(SwooleSingleton::class);
        $this->assertTrue(true);
        /*
        SwooleSingleton::G()->G($object = null);
        //*/
    }
}
