<?php

$projectRoot = dirname(__DIR__, 2);
$publicRoot = $projectRoot.'/public';
if (! is_dir($publicRoot) || ! @chdir($publicRoot)) {
    http_response_code(500);
    exit('PAB_R3_PUBLIC_ROOT_UNAVAILABLE');
}

require $projectRoot.'/tests/bootstrap.php';

return require $projectRoot.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
