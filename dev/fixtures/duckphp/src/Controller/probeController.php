<?php declare(strict_types=1);
/**
 * Probe controller: exercises the parts of the DuckPhp integration that this
 * library is responsible for (superglobals, container isolation, session,
 * routing) so dev/test-duckphp.sh can assert on them.
 */
namespace DpFixture\Controller;

use DuckPhp\Core\PhaseContainer;
use DuckPhp\Foundation\Helper;
use SwooleHttpd\SwooleHttpd;

class probeController extends Base
{
    /** Routing works at all => Route was seeded with its options and route hooks. */
    public function route()
    {
        echo json_encode([
            'ok' => true,
            'controller' => static::class,
            'method' => 'route',
        ]);
    }

    /** URL generation depends on DOCUMENT_ROOT / REQUEST_SCHEME / HTTP_HOST. */
    public function url()
    {
        $route = \DuckPhp\Core\Route::_();
        echo json_encode([
            'url' => $route->_Url('/probe/route'),
            'domain' => $route->_Domain(),
            'full' => $route->_Domain(true),
        ]);
    }

    /**
     * The concurrency probe. Yield to the event loop, then read request data back
     * through DuckPhp's superglobal path — which must still belong to THIS request.
     */
    public function slow()
    {
        $tag = Helper::GET('tag');
        $uri = Helper::SERVER('REQUEST_URI');
        \Swoole\Coroutine::sleep(0.3);
        echo json_encode([
            'tag_before' => $tag,
            'tag_after' => Helper::GET('tag'),
            'uri_before' => $uri,
            'uri_after' => Helper::SERVER('REQUEST_URI'),
            'raw_get_after' => $_GET['tag'] ?? null,
        ]);
    }

    /**
     * Session through DuckPhp's system wrapper, i.e. our SessionHandler.
     * Starting it explicitly keeps the probe independent of SessionTrait.
     */
    public function session()
    {
        \DuckPhp\Core\SystemWrapper::_()->_session_start();
        $sg = \DuckPhp\Core\SuperGlobal::_();
        $sg->_SessionSet('n', (int)$sg->_SessionGet('n', 0) + 1);
        echo 'n='.$sg->_SessionGet('n');
    }

    /** Shows that every request got its own components, while App stays shared. */
    public function container()
    {
        $container = PhaseContainer::_();
        $is_ours = $container instanceof \SwooleHttpd\CoroutinePhaseContainer;
        $app = \DpFixture\System\App::_();
        $route = \DuckPhp\Core\Route::_();
        $runtime = \DuckPhp\Core\Runtime::_();

        echo json_encode([
            'container_is_ours' => $is_ours,
            'coroutine_containers' => $is_ours ? $container->countCoroutineContainers() : -1,
            'app_is_shared' => ($GLOBALS['__dp_fixture_app'] ?? null) === $app,
            'route_object' => spl_object_id($route),
            'runtime_running' => $runtime->isRunning(),
            'route_has_hooks' => $this->countRouteHooks($route) > 0,
        ]);
    }

    /** STATICS replacement must be per request (StaticReplacer is rebuilt). */
    public function statics()
    {
        $n =& SwooleHttpd::STATICS('probe_n');
        $n = ($n ?? 0) + 1;
        echo 'statics='.$n;
    }

    public function fail()
    {
        throw new \RuntimeException('fixture boom');
    }

    public function bye()
    {
        echo 'before-bye';
        exit(3);
    }

    protected function countRouteHooks($route): int
    {
        $n = 0;
        foreach (['pre_run_hook_list', 'post_run_hook_list', 'finally_run_hook_list'] as $prop) {
            $ref = new \ReflectionProperty($route, $prop);
            $ref->setAccessible(true);
            $n += count((array)$ref->getValue($route));
        }

        return $n;
    }
}
