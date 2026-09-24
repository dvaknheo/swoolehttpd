<?php declare(strict_types=1);
/**
 * DuckPhp fixture app used by dev/test-duckphp.sh
 */
namespace DpFixture\Controller;

use DuckPhp\Foundation\SingletonTrait;

abstract class Base
{
    use SingletonTrait;
    public function __construct()
    {
    }
}
