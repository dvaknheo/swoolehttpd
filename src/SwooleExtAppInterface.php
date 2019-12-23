<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */
namespace SwooleHttpd;

interface SwooleExtAppInterface
{
    public static function G($object = null);
    public function run();
    
    public function onSwooleHttpdInit(SwooleHttpd $SwooleHttpd, ?callable $RunHandler = null);
    public function onSwooleHttpdStart(SwooleHttpd $SwooleHttpd);
    public function onSwooleHttpdRequest(SwooleHttpd $SwooleHttpd);
    public function getDynamicComponentClasses();
    public function getStaticComponentClasses();
}
