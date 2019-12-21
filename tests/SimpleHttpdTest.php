<?php 
namespace tests\SwooleHttpd;
use SwooleHttpd\SimpleHttpd;

class SimpleHttpdTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        \MyCodeCoverage::G()->begin(SimpleHttpd::class);
        
        //code here
        
        \MyCodeCoverage::G()->end(SimpleHttpd::class);
        $this->assertTrue(true);
        /*
        SimpleHttpd::G()->onHttpRun($request, $response);
        SimpleHttpd::G()->onHttpException($ex);
        SimpleHttpd::G()->onHttpClean();
        SimpleHttpd::G()->initHttp($request, $response);
        SimpleHttpd::G()->onRequest($request, $response);
        //*/
    }
}
