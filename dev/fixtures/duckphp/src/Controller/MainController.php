<?php declare(strict_types=1);
/**
 * DuckPhp fixture app used by dev/test-duckphp.sh
 */
namespace DpFixture\Controller;

class MainController extends Base
{
    public function index()
    {
        echo "duckphp-index";
    }
}
