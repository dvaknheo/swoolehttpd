<?php

use DNMVCS\SwooleHttpd;
require ('../src/tickall.php');
require ('../src/SwooleHttpd.php');

function hello()
{
	echo "<h1> hello ,have a good start.</h1><pre>\n";
	var_export(SwooleHttpd::SG());
	echo "</pre>";
    return true;
}

$options=[
    'port'=>9528,
    'http_handler'=>'hello',
];
SwooleHttpd::RunQuickly($options);