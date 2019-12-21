<?php 
namespace tests\SwooleHttpd;
use SwooleHttpd\SwooleExt;

class SwooleExtTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        \MyCodeCoverage::G()->begin(SwooleExt::class);
        
        //code here
        
        \MyCodeCoverage::G()->end(SwooleExt::class);
        $this->assertTrue(true);
        /*
        SwooleExt::G()->init($options = [], $context = null);
        SwooleExt::G()->replaceInstances();
        SwooleExt::G()->OnRun();
        SwooleExt::G()->run();
        SwooleExt::G()->runSwoole();
        //*/
    }
}
