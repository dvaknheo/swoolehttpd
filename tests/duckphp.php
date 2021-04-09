<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */
require __DIR__.'/../autoload.php';
require __DIR__.'/../../DNMVCS/autoload.php';
chdir(realpath(__DIR__.'/../../DNMVCS/template/'));
//var_dump(realpath(__DIR__.'/../../DNMVCS/template/'));
require 'duckphp-project';

//php duckphp.php run --override-class=SwooleHttpd/SwooleHttpd

return;
require __DIR__.'/../../DNMVCS/template/.php';

return;
class Mainx
{
    public function index()
    {
        include __DIR__.'/index.php';
    }
}
$options = [
    'namespace_controller' => "\\",   // 本例特殊，设置控制器的命名空间为根，而不是默认的 Controller
    // 还有百来个选项以上可用，详细请查看参考文档
    'is_debug'=>true,
];
\DuckPhp\DuckPhp::RunQuickly($options);

//php duckphp.php  run --override-class = WorkermanHttpd/HttpServerForDuckphp --command stop --gracefull
