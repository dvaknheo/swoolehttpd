<?php
spl_autoload_register(function ($class) {
    $path=__DIR__.'/src/';
    $namespace='DNMVCS\\';
    if (strncmp($namespace, $class, strlen($namespace)) !== 0) {
        var_dump("faile1!");

        return false;
    }
    $file = $path . str_replace('\\', '/', substr($class, strlen($namespace))) . '.php';
    if (!file_exists($file)) {
        var_dump("fai $file le2!");

        return false;
    }
    require $file;
    return true;
});
