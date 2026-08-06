<?php

$projectRoot = dirname(__DIR__, 2);
$testingEnvironment = [
    'APP_ENV' => 'testing',
    'BACKUP_ENABLED' => 'false',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'file',
    'SESSION_SECURE_COOKIE' => 'false',
    'SESSION_DOMAIN' => '127.0.0.1',
    'APP_URL' => 'http://127.0.0.1:8092',
    'PYTHON_CONTAINER_NAME' => 'http://127.0.0.1:8679',
];

foreach ($testingEnvironment as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

chdir($projectRoot . DIRECTORY_SEPARATOR . 'public');

return require $projectRoot
    . DIRECTORY_SEPARATOR . 'vendor'
    . DIRECTORY_SEPARATOR . 'laravel'
    . DIRECTORY_SEPARATOR . 'framework'
    . DIRECTORY_SEPARATOR . 'src'
    . DIRECTORY_SEPARATOR . 'Illuminate'
    . DIRECTORY_SEPARATOR . 'Foundation'
    . DIRECTORY_SEPARATOR . 'resources'
    . DIRECTORY_SEPARATOR . 'server.php';
