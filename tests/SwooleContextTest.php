<?php 
namespace tests\SwooleHttpd;
use SwooleHttpd\SwooleContext;

class SwooleContextTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        \MyCodeCoverage::G()->begin(SwooleContext::class);
        
        //code here
        
        \MyCodeCoverage::G()->end(SwooleContext::class);
        $this->assertTrue(true);
        /*
        SwooleContext::G()->initHttp($request, $response);
        SwooleContext::G()->initWebSocket($frame);
        SwooleContext::G()->cleanUp();
        SwooleContext::G()->onShutdown();
        SwooleContext::G()->regShutdown($call_data);
        SwooleContext::G()->isWebSocketClosing();
        SwooleContext::G()->header(string $string, bool $replace = true, int $http_status_code = 0);
        SwooleContext::G()->setcookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false);
        //*/
    }
}
