<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */
namespace SwooleHttpd;

use SwooleHttpd\SwooleSingleton;
use Swoole\Coroutine;

class SwooleCoroutineSingleton
{
    use SwooleSingleton;
    protected static $_instances = [];
    protected static $cid_map = [];
    
    public static function ReplaceDefaultSingletonHandler()
    {
        if (defined('__SINGLETONEX_REPALACER')) {
            return false;
        }
        define('__SINGLETONEX_REPALACER', self::class . '::'.'SingletonInstance');
        return true;
    }
    public static function getLegencyObject($class)
    {
        //深挖 宏之前的代码
        $ref = new \ReflectionClass($class);
        $prop = $ref->getProperty('_instances'); //OK Get It
        if (!$prop) {
            return null;
        }
        $prop->setAccessible(true);
        $array = $prop->getValue();
        if (empty($array[$class])) {
            return null;
        }
        return  $array[$class];
    }
    /**
     * Resolve the instance space ("coroutine id") that should serve the caller.
     *
     * Rules, in order:
     *   1. outside a coroutine            -> 0 (the master space)
     *   2. explicitly bound via cid_map   -> that space
     *   3. this coroutine owns a space    -> its own
     *   4. otherwise                      -> the nearest ancestor's space
     *
     * Rule 4 is what makes a child coroutine spawned inside a request
     * (Swoole\Coroutine::create()/go()) share the request's components instead of
     * building a second, un-initialised copy of each of them. Rule 3 keeps the
     * documented use of EnableCurrentCoSingleton() working: a child that asks for
     * its own space still gets one.
     */
    public static function GetOwnerCid(): int
    {
        $cid = Coroutine::getCid();
        if ($cid <= 0) {
            return 0;
        }
        if (isset(self::$cid_map[$cid])) {
            return self::$cid_map[$cid];
        }
        if (isset(self::$_instances[$cid])) {
            return $cid;
        }
        $pcid = Coroutine::getPcid($cid);
        while ($pcid > 0) {
            if (isset(self::$cid_map[$pcid])) {
                return self::$cid_map[$pcid];
            }
            if (isset(self::$_instances[$pcid])) {
                return $pcid;
            }
            $pcid = Coroutine::getPcid($pcid);
        }

        return 0;
    }
    public static function SingletonInstance($class, $object)
    {
        $cid = self::GetOwnerCid();
        
        if ($object === null) {
            $me = self::$_instances[$cid][$class] ?? null;
            if ($me !== null) {
                return $me;
            }
            if ($cid !== 0) {
                $me = self::$_instances[0][$class] ?? null;
                if ($me !== null) {
                    return $me;
                }
            }
            $me = self::getLegencyObject($class);
            if ($me !== null) {
                self::$_instances[0][$class]=$me;
                return $me;
            }
            $me = new $class();
            if (isset(self::$_instances[$cid])) {
                self::$_instances[$cid][$class] = $me;
            } else {
                self::$_instances[0][$class] = $me;
            }
            return $me;
        }
        self::$_instances[$cid][$class] = $object;
        return $object;
    }
    ///////////////
    public static function GetInstance($cid, $class)
    {
        return self::$_instances[$cid][$class] ?? null;
    }
    public static function SetInstance($cid, $class, $object)
    {
        self::$_instances[$cid][$class] = $object;
    }
    public static function DumpString()
    {
        return static::G()->_DumpString();
    }
    
    public static function EnableCurrentCoSingleton($cid = null)
    {
        if ($cid === 0) {
            return;
        }
        $current_cid = Coroutine::getCid();
        if ($current_cid <= 0) {
            return;
        }
        if ($cid !== null) {
            // Bind *this* coroutine to the instance space of another one.
            // (The original code wrote the mapping the other way round, so this
            // overload silently did nothing — cid_map was never hit.)
            self::$cid_map[$current_cid] = $cid;
            Coroutine::defer(
                function () use ($current_cid) {
                    unset(self::$cid_map[$current_cid]);
                }
            );
            return;
        }
        // Give this coroutine its own instance space, dropped when it ends.
        if (isset(self::$_instances[$current_cid])) {
            return;
        }
        self::$_instances[$current_cid] = [];
        Coroutine::defer(
            function () use ($current_cid) {
                unset(self::$_instances[$current_cid]);
            }
        );
    }
    public function forkMasterInstances($classes, $exclude_classes = [])
    {
        $cid = self::GetOwnerCid();
        if ($cid <= 0) {
            return;
        }
        
        foreach ($classes as $class) {
            if (!isset(self::$_instances[0][$class])) {
                $real_class = $class;
                if (in_array($real_class, $exclude_classes)) {
                    continue;
                }
                $oldobject =self::getLegencyObject($class);
                if($oldobject){
                    self::$_instances[$cid][$class] = clone $oldobject;
                }else{
                    self::$_instances[$cid][$class] = new $class();
                }
                continue;
            }
            $real_class = get_class(self::$_instances[0][$class]);
            if (in_array($real_class, $exclude_classes)) {
                self::$_instances[$cid][$class] = self::$_instances[$cid][$real_class];
                continue;
            }
            $object = self::$_instances[0][$real_class];
            self::$_instances[$cid][$real_class] = clone $object;
            if ($class !== $real_class) {
                self::$_instances[$cid][$class] = self::$_instances[$cid][$real_class];
            }
        }
    }
    
    public function forkAllMasterClasses()
    {
        $cid = self::GetOwnerCid();
        if ($cid <= 0) {
            return;
        }
        foreach (self::$_instances[0] as $class => $object) {
            if (!isset($object)) {
                continue;
            }
            self::$_instances[$cid][$class] = new $class();
        }
    }
    ///////////////////////
    public function _DumpString()
    {
        $my_cid = self::GetOwnerCid();
        $ret = "==== SwooleCoroutineSingleton List Current cid [{$my_cid}] ==== ;\n";
        foreach (self::$_instances as $cid => $v) {
            foreach ($v as $cid_class => $object) {
                $hash = $object?md5(spl_object_hash($object)):'';
                $class = $object?get_class($object):'';
                $class = $cid_class === $class?'':$class;
                $ret .= "[$hash]$cid $cid_class($class)\n";
            }
        }
        return "{{$ret}}\n";
    }
    public static function Dump()
    {
        fwrite(STDERR, static::DumpString());
    }
}
