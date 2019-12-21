<?php 
namespace tests\SwooleHttpd;
use SwooleHttpd\SimpleWebSocketd;

class SimpleWebSocketdTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        \MyCodeCoverage::G()->begin(SimpleWebSocketd::class);
        
        //code here
        
        \MyCodeCoverage::G()->end(SimpleWebSocketd::class);
        $this->assertTrue(true);
        /*
        SimpleWebSocketd::G()->onOpen(Websocket_Server $server, Request $request);
        SimpleWebSocketd::G()->onMessage($server, $frame);
        //*/
    }
}
