<?php
namespace SwooleHttpd;

trait SwooleHttpd_SimpleHttpd
{
    protected function onHttpRun($request, $response)
    {
        throw new SwooleException("Impelement Me");
    }
    protected function onHttpException($ex)
    {
        throw new SwooleException("Impelement Me");
    }
    protected function onHttpClean()
    {
        throw new SwooleException("Impelement Me");
    }
    
    // en...
    public function initHttp($request, $response)
    {
        SwooleContext::G(new SwooleContext())->initHttp($request, $response);
    }
    public function onRequest($request, $response)
    {
        \defer(function () {
            gc_collect_cycles();
        });
        SwooleCoroutineSingleton::EnableCurrentCoSingleton();
        
        $InitObLevel=ob_get_level();
        ob_start(function ($str) use ($response) {
            if (''===$str) {
                return;
            } // stop warnning;
            $response->write($str);
        });
        
        \defer(function () use ($response,$InitObLevel) {
            SwooleContext::G()->onShutdown();
            $this->onHttpClean();
            for ($i=ob_get_level();$i>$InitObLevel;$i--) {
                ob_end_flush();
            }
            SwooleContext::G()->cleanUp();
            $response->end();
        });
        
        $this->initHttp($request, $response);
        SwooleSuperGlobal::G(new SwooleSuperGlobal());
        try {
            $this->onHttpRun($request, $response);
        } catch (\Throwable $ex) {
            $this->onHttpException($ex);
        }
    }
}
