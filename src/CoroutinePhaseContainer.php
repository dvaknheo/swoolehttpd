<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */
namespace SwooleHttpd;

use DuckPhp\Core\PhaseContainer;
use Swoole\Coroutine;

/**
 * A PhaseContainer that keeps ONE independent component container per coroutine.
 *
 * Why this exists
 * ---------------
 * DuckPhp resolves every component through a single process-wide static
 * (`PhaseContainer::$instance`), so under Swoole a long-lived worker would share
 * one set of components — and one `Route`/`Runtime` state — between all concurrent
 * requests. DuckPhp leaves that to the server implementation on purpose: its
 * `KernelTrait::prepareServe()` ships an empty renew list "for other
 * implementations to look at".
 *
 * How it works
 * ------------
 * Exactly one instance of this class is installed as `PhaseContainer::$instance`,
 * and it dispatches internally by coroutine id:
 *
 *   - **cid 0** (the master, i.e. the process before/outside any request) uses the
 *     parent's own `$containers/$current/$default` properties. DuckPhp initialises
 *     the application there, so the master ends up as a fully built template.
 *   - **cid > 0** (a request coroutine) gets its own copy of every bucket. The copy
 *     is seeded from the master by **cloning each object**, which preserves the
 *     initialized state (options, registered route hooks, ...) that a bare
 *     `new Route()` would lose — see the class list notes below.
 *
 * Because the parent's `_GetObject()` is reached only through
 * `static::_()->_GetObject(...)`, overriding it here is enough to redirect every
 * `Something::_()` in DuckPhp.
 *
 * Known limitations (deliberate, documented in AI_MEMO.md §12.3)
 * -------------------------------------------------------------
 *  - `KernelTrait::SwitchRootPhase()` assigns `PhaseContainer::_()->current` and
 *    `->default` directly. Public properties inherited from the parent cannot be
 *    intercepted, so those two assignments always hit the MASTER values. That is
 *    accepted: phase-root switching is a structural, process-wide operation.
 *  - Objects registered as "shared" are handed out by reference to every
 *    coroutine (application instances), and ones listed as "renew" are rebuilt
 *    from scratch per coroutine (connections).
 */
class CoroutinePhaseContainer extends PhaseContainer
{
    /**
     * Classes handed out by reference to every coroutine.
     *
     * Application instances belong here: they carry the startup state
     * (`options`, `setting`, child-app registration) and are looked up by class
     * name, so cloning them would desynchronise `App::_()` from the instance that
     * actually ran `init()`.
     *
     * @var array<int, string>
     */
    public static $shared_classes = [];

    /**
     * Like $shared_classes, but matched with `is_a()` so a whole application class
     * hierarchy can be declared shared at once. DuckPhp registers the app instance
     * under several keys (the concrete class, `DuckPhp\Core\App`, and
     * `override_from`), all pointing at the SAME object — cloning one of them would
     * desynchronise `App::_()`.
     *
     * @var array<int, string>
     */
    public static $shared_instance_of = [];

    /**
     * Classes rebuilt from scratch (new instance + init) per coroutine rather than
     * cloned — for anything holding a connection, where sharing one handle across
     * coroutines is unsafe and a clone would still point at the same handle.
     *
     * @var array<int, string>
     */
    public static $renew_classes = [];

    /** @var array<int, array<string, array<string, object>>> cid => phase => class => object */
    protected $cid_containers = [];
    /** @var array<int, string> cid => phase */
    protected $cid_current = [];
    /** @var array<int, string> cid => shared bucket name */
    protected $cid_default = [];

    /**
     * Install this container (and remember which classes are shared / renewed).
     *
     * MUST be called before the DuckPhp application is initialised, otherwise the
     * master template is built in the wrong container.
     *
     * @param array<int, string> $shared_classes
     * @param array<int, string> $renew_classes
     */
    public static function install(array $shared_classes = [], array $renew_classes = [], array $shared_instance_of = []): self
    {
        foreach ($shared_classes as $class) {
            if (!in_array($class, self::$shared_classes, true)) {
                self::$shared_classes[] = $class;
            }
        }
        foreach ($renew_classes as $class) {
            if (!in_array($class, self::$renew_classes, true)) {
                self::$renew_classes[] = $class;
            }
        }
        foreach ($shared_instance_of as $class) {
            if ($class && !in_array($class, self::$shared_instance_of, true)) {
                self::$shared_instance_of[] = $class;
            }
        }
        // Always put back our own type, even if PhaseContainer::RestAllContainerForTesting()
        // (or anything else) replaced the instance with a plain PhaseContainer.
        $current = PhaseContainer::_();
        if ($current instanceof self) {
            return $current;
        }
        $container = new self();
        PhaseContainer::_($container);

        return $container;
    }

    /** Reinstall if something swapped the container out from under us. */
    public static function assertInstalled(): bool
    {
        if (PhaseContainer::_() instanceof self) {
            return true;
        }
        self::install();

        return false;
    }

    /**
     * Reset per-request state only.
     *
     * The parent implementation swaps in a brand new container, which would throw
     * away the initialized master template and leave `App::_()` creating a fresh,
     * un-initialised application ("unexplained Internal Error"). So we keep the
     * master and drop the coroutine-local copies.
     */
    public static function RestAllContainerForTesting()
    {
        $me = PhaseContainer::_();
        if ($me instanceof self) {
            $me->resetCoroutineContainers();

            return;
        }
        PhaseContainer::_(new self());
    }

    public function resetCoroutineContainers(): void
    {
        $this->cid_containers = [];
        $this->cid_current = [];
        $this->cid_default = [];
    }

    ////////////////////////////////////////////////////////////////

    /**
     * The instance space this call belongs to. Reuses the singleton registry's
     * resolver so that a child coroutine spawned inside a request (go()/create())
     * shares that request's container instead of building an empty one.
     */
    protected static function ownerCid(): int
    {
        if (!class_exists(Coroutine::class)) {
            return 0;
        }

        return SwooleCoroutineSingleton::GetOwnerCid();
    }

    /** @return array<string, array<string, object>> */
    protected function &containersOf(int $cid): array
    {
        if ($cid <= 0) {
            return $this->containers;
        }
        if (!isset($this->cid_containers[$cid])) {
            $this->cid_containers[$cid] = $this->seedFromMaster();
            // Drop this coroutine's copy when the coroutine ends, otherwise a
            // long-lived worker leaks one container per request.
            if (class_exists(Coroutine::class)) {
                $self = $this;
                Coroutine::defer(function () use ($self, $cid) {
                    $self->forgetCid($cid);
                });
            }
        }

        return $this->cid_containers[$cid];
    }

    public function forgetCid(int $cid): void
    {
        unset($this->cid_containers[$cid], $this->cid_current[$cid], $this->cid_default[$cid]);
    }

    /** @return array<string, array<string, object>> */
    protected function seedFromMaster(): array
    {
        $out = [];
        foreach ($this->containers as $phase => $bucket) {
            foreach ($bucket as $class => $object) {
                $out[$phase][$class] = $this->seedObject((string)$class, $object);
            }
        }

        return $out;
    }

    /**
     * Build the per-coroutine counterpart of one master object.
     *
     * We clone rather than rebuild because DuckPhp components gain their state in
     * init(): `Route` gets the application options and has every RouteHook
     * registered onto it there, and `Route::clear()` deliberately does not undo
     * that. A bare `new Route()` would therefore route nothing at all.
     *
     * Cloning is safe for the framework's own hooks because they are registered as
     * `[ClassName::class, 'Method']` — static callables that capture no `$this`.
     * A user registering a closure that captured its own object would defeat this;
     * that caveat is documented in the README.
     */
    protected function seedObject(string $class, $object)
    {
        if (!is_object($object)) {
            return $object;
        }
        if (in_array($class, self::$shared_classes, true)) {
            return $object;
        }
        foreach (self::$shared_instance_of as $parent) {
            if (is_a($object, $parent)) {
                return $object;
            }
        }
        if (in_array($class, self::$renew_classes, true)) {
            return $this->renewObject($object);
        }
        try {
            return clone $object;
        } catch (\Throwable $e) {
            // Not cloneable (holds a resource, uncloneable property, ...): sharing is
            // the safer fallback, and beats handing out an uninitialised object.
            return $object;
        }
    }

    /** Rebuild one component as a fresh, re-initialised instance. */
    protected function renewObject($master)
    {
        $class = get_class($master);
        $options = (array)($master->options ?? []);
        try {
            $new = new $class();
        } catch (\Throwable $e) {
            return $master;
        }
        if (method_exists($new, 'init')) {
            try {
                // Same shape as KernelTrait::initExtensionsByOptions()'s EXT_RENEW.
                $new->init($options, $options);
            } catch (\Throwable $e) {
                return $master;
            }
        }

        return $new;
    }

    /////////////////// per-cid accessors ///////////////////

    protected function currentOf(int $cid): string
    {
        if ($cid <= 0) {
            return (string)$this->current;
        }

        return (string)($this->cid_current[$cid] ?? $this->current);
    }

    protected function defaultOf(int $cid): string
    {
        if ($cid <= 0) {
            return (string)$this->default;
        }

        return (string)($this->cid_default[$cid] ?? $this->default);
    }

    public function getCurrentContainer()
    {
        return $this->currentOf(static::ownerCid());
    }

    public function setCurrentContainer($container)
    {
        $cid = static::ownerCid();
        if ($cid <= 0) {
            $this->current = $container;

            return;
        }
        $this->cid_current[$cid] = $container;
    }

    public function setDefaultContainer($class)
    {
        // The shared-bucket name is structural: every coroutine must agree on it.
        $this->default = $class;
        foreach (array_keys($this->cid_default) as $cid) {
            $this->cid_default[$cid] = $class;
        }
    }

    /////////////////// the resolution core ///////////////////

    public function _GetObject(string $class, ?object $object = null): object
    {
        $cid = static::ownerCid();
        $containers = &$this->containersOf($cid);
        $current = $this->currentOf($cid);
        $default = $this->defaultOf($cid);

        // 1. current phase bucket
        if (isset($containers[$current][$class])) {
            if ($object) {
                $containers[$current][$class] = $object;
            }

            return $containers[$current][$class];
        }
        // 2. public classes live in the shared bucket
        if (isset($this->publics[$class])) {
            if (isset($containers[$default][$class])) {
                if ($object) {
                    $containers[$default][$class] = $object;
                }

                return $containers[$default][$class];
            }
            $made = $object ?? $this->createObject($class);
            $containers[$default][$class] = $made;

            return $made;
        }
        // 3. not seen before
        $made = $object ?? $this->createObject($class);
        $containers[$current][$class] = $made;

        return $made;
    }

    /////////////////// remaining accessors, per cid ///////////////////

    protected function getObjectInContainer($container_name, $class, $object)
    {
        $containers = &$this->containersOf(static::ownerCid());
        if (isset($containers[$container_name][$class])) {
            if ($object) {
                $containers[$container_name][$class] = $object;
            }

            return $containers[$container_name][$class];
        }

        return null;
    }

    protected function createObjectToContainer($container_name, $class, $object)
    {
        $containers = &$this->containersOf(static::ownerCid());
        $result = $object ?? $this->createObject($class);
        $containers[$container_name][$class] = $result;

        return $result;
    }

    public function issetContainer($phase)
    {
        $containers = &$this->containersOf(static::ownerCid());

        return isset($containers[$phase]);
    }

    public function createLocalObject($class, $object = null)
    {
        $containers = &$this->containersOf(static::ownerCid());
        $result = $object ?? $this->createObject($class);
        $containers[$this->currentOf(static::ownerCid())][$class] = $result;

        return $result;
    }

    public function removeLocalObject($class)
    {
        $containers = &$this->containersOf(static::ownerCid());
        unset($containers[$this->currentOf(static::ownerCid())][$class]);
    }

    public function getClassOfContainer($class, $phase = '')
    {
        $containers = &$this->containersOf(static::ownerCid());

        return $containers[$phase][$class] ?? null;
    }

    /** Diagnostics: how many coroutine-local containers are alive right now. */
    public function countCoroutineContainers(): int
    {
        return count($this->cid_containers);
    }
}
