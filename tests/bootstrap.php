<?php

/**
 * PHPUnit bootstrap for the machine-global testing database lease.
 *
 * All LinguaCafe worktrees that target the same logical testing database use
 * one OS file lock under the system temporary directory. The bootstrap never
 * reads environment files, opens a database connection, or runs migrations.
 */

require __DIR__.'/../vendor/autoload.php';
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
