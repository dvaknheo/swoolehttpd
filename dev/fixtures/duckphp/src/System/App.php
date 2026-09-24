<?php declare(strict_types=1);
/**
 * DuckPhp fixture app used by dev/test-duckphp.sh
 */
namespace DpFixture\System;

use DuckPhp\DuckPhp;

class App extends DuckPhp
{
    //@override
    public $options = [
        'path' => __DIR__.'/../../',
        'is_debug' => true,
        // 404/500 fall back to DuckPhp's built-in plain-text output, so the fixture
        // needs no view directory.
    ];
}
