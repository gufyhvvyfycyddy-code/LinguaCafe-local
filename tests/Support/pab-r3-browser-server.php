<?php

$projectRoot = dirname(__DIR__, 2);
require $projectRoot.'/tests/bootstrap.php';

return require $projectRoot.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
