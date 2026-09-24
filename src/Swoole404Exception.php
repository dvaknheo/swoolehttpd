<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */
namespace SwooleHttpd;

use Exception;

/**
 * Thrown to abort the current request as a 404.
 *
 * Restored in the 1.1.5 revival: the class was deleted in 2021 (commit 04c5447)
 * while README, the graphviz doc and tests/Swoole404ExceptionTest.php kept
 * referring to it.
 */
class Swoole404Exception extends Exception
{
}
