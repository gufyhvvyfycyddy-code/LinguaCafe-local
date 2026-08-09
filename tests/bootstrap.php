<?php

/**
 * PHPUnit bootstrap for the machine-global testing database lease.
 *
 * All LinguaCafe worktrees that target the same logical testing database use
 * one OS file lock under the system temporary directory. The bootstrap never
 * reads environment files, opens a database connection, or runs migrations.
 */

$composer = require __DIR__.'/../vendor/autoload.php';
$projectBasePath = dirname(__DIR__);
if ($composer instanceof Composer\Autoload\ClassLoader) {
    $sharedProjectBasePath = dirname((new ReflectionClass(Composer\Autoload\ClassLoader::class))->getFileName(), 3);
    $sharedVendorPath = $sharedProjectBasePath.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR;
    $projectClassMap = [];
    foreach ($composer->getClassMap() as $class => $path) {
        $resolvedPath = realpath($path) ?: $path;
        $normalizedPath = strtolower($resolvedPath);
        if (str_starts_with($normalizedPath, strtolower($sharedProjectBasePath.DIRECTORY_SEPARATOR))
            && ! str_starts_with($normalizedPath, strtolower($sharedVendorPath))) {
            $projectClassMap[$class] = $projectBasePath.substr($resolvedPath, strlen($sharedProjectBasePath));
        }
    }
    $composer->addClassMap($projectClassMap);
    $composer->setPsr4('App\\', __DIR__.'/../app/');
    $composer->setPsr4('Database\\Factories\\', __DIR__.'/../database/factories/');
    $composer->setPsr4('Database\\Seeders\\', __DIR__.'/../database/seeders/');
    $composer->setPsr4('Tests\\', __DIR__.'/');
}
putenv("APP_BASE_PATH={$projectBasePath}");
$_ENV['APP_BASE_PATH'] = $projectBasePath;
$_SERVER['APP_BASE_PATH'] = $projectBasePath;
require_once __DIR__.'/Support/TestingDatabaseLease.php';

use Tests\Support\TestingDatabaseLease;
use Tests\Support\TestingDatabaseLeaseException;

$appEnv = strtolower((string) (getenv('APP_ENV') ?: ''));

if ($appEnv === 'testing') {
    $waitValue = (string) (getenv('TESTING_DB_LEASE_WAIT_MS') ?: '0');
    $waitMs = ctype_digit($waitValue) ? (int) $waitValue : -1;
    $label = (string) (getenv('TESTING_DB_LEASE_LABEL') ?: 'phpunit');

    try {
        $testingDatabaseLease = TestingDatabaseLease::acquireOrInheritForProject(
            dirname(__DIR__),
            label: $label,
            waitMs: $waitMs,
        );
    } catch (TestingDatabaseLeaseException $error) {
        fwrite(STDERR, "[testing-db-lease] LEASE_ACQUIRE_FAILED code={$error->machineCode}\n");
        throw new RuntimeException('Testing database lease acquisition failed.', 0, $error);
    }

    foreach ($testingDatabaseLease->createInheritanceProof() as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $GLOBALS['linguacafeTestingDatabaseLease'] = $testingDatabaseLease;
}
