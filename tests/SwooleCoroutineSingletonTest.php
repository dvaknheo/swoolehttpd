<?php 
namespace tests\SwooleHttpd;
use SwooleHttpd\SwooleCoroutineSingleton;

class SwooleCoroutineSingletonTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        \MyCodeCoverage::G()->begin(SwooleCoroutineSingleton::class);
        
        //code here
        
        \MyCodeCoverage::G()->end(SwooleCoroutineSingleton::class);
        $this->assertTrue(true);
        /*
        SwooleCoroutineSingleton::G()->ReplaceDefaultSingletonHandler();
        SwooleCoroutineSingleton::G()->SingletonInstance($class, $object);
        SwooleCoroutineSingleton::G()->GetInstance($cid, $class);
        SwooleCoroutineSingleton::G()->SetInstance($cid, $class, $object);
        SwooleCoroutineSingleton::G()->DumpString();
        SwooleCoroutineSingleton::G()->EnableCurrentCoSingleton($cid = null);
        SwooleCoroutineSingleton::G()->forkMasterInstances($classes, $exclude_classes = []);
        SwooleCoroutineSingleton::G()->forkAllMasterClasses();
        SwooleCoroutineSingleton::G()->_DumpString();
        SwooleCoroutineSingleton::G()->Dump();
        //*/
    }
}
