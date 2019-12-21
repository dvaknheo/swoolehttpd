<?php 
namespace tests\SwooleHttpd;
use SwooleHttpd\SwooleSessionHandler;

class SwooleSessionHandlerTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        \MyCodeCoverage::G()->begin(SwooleSessionHandler::class);
        
        //code here
        
        \MyCodeCoverage::G()->end(SwooleSessionHandler::class);
        $this->assertTrue(true);
        /*
        SwooleSessionHandler::G()->open($savePath, $sessionName);
        SwooleSessionHandler::G()->close();
        SwooleSessionHandler::G()->read($id);
        SwooleSessionHandler::G()->write($id, $data);
        SwooleSessionHandler::G()->destroy($id);
        SwooleSessionHandler::G()->gc($maxlifetime);
        //*/
    }
}
