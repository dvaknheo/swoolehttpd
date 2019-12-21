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
    
    public function onSwooleHttpdInit(SwooleHttpd $SwooleHttpd, bool $InCoroutine, ?callable $RunHandler = null);
    public function getDynamicComponentClasses();
    public function getStaticComponentClasses();
}
