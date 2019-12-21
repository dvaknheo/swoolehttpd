<?php 
namespace tests\SwooleHttpd;
use SwooleHttpd\SwooleExtAppInterface;

class SwooleExtAppInterfaceTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        \MyCodeCoverage::G()->begin(SwooleExtAppInterface::class);
        
        //code here
        
        \MyCodeCoverage::G()->end(SwooleExtAppInterface::class);
        $this->assertTrue(true);
        /*
        SwooleExtAppInterface::G()->G($object = null);
        SwooleExtAppInterface::G()->run();
        SwooleExtAppInterface::G()->onSwooleHttpdInit(SwooleHttpd $SwooleHttpd, bool $InCoroutine, ?$RunHandler = null);
        SwooleExtAppInterface::G()->getDynamicComponentClasses();
        SwooleExtAppInterface::G()->getStaticComponentClasses();
        //*/
    }
}
