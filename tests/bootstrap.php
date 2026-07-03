<?php

/**
 * tests/bootstrap.php — PHPUnit bootstrap with testing DB process lock.
 *
 * Prevents concurrent PHPUnit processes from corrupting the shared MySQL
 * testing database (linguacafe_fsrs_test) when both use RefreshDatabase.
 *
 * The lock uses flock(LOCK_EX) on a file inside storage/framework/testing/ so
 * it works on both Windows and Linux without OS-level IPC.
 *
 * Design constraints:
 *  - Does NOT read .env or .env.testing.
 *  - Does NOT connect to any database.
 *  - Does NOT run migrations.
 *  - Does NOT delete any data.
 *  - Safe to run during normal application bootstrap in testing environment.
 */

require __DIR__ . '/../vendor/autoload.php';

$appEnv = getenv('APP_ENV') ?: '';

if (strtolower($appEnv) === 'testing') {
    $lockDir = __DIR__ . '/../storage/framework/testing';

    if (! is_dir($lockDir)) {
        @mkdir($lockDir, 0775, true);
    }

    if (! is_dir($lockDir)) {
        fwrite(STDERR, "[bootstrap] WARNING: Cannot create lock directory: {$lockDir}\n");
        return;
    }

    $lockFile = $lockDir . '/phpunit-db.lock';
    $lockFp = @fopen($lockFile, 'c');

    if (! $lockFp) {
        fwrite(STDERR, "[bootstrap] WARNING: Cannot open lock file: {$lockFile}\n");
        return;
    }

    // Non-blocking attempt first so we can print a message if another process
    // is already running tests.
    $locked = flock($lockFp, LOCK_EX | LOCK_NB, $wouldBlock);

    if (! $locked && $wouldBlock) {
        fwrite(STDERR, "[bootstrap] Another PHPUnit process holds the testing DB lock. Waiting...\n");
        $locked = flock($lockFp, LOCK_EX);
    }

    if (! $locked) {
        fwrite(STDERR, "[bootstrap] WARNING: Could not acquire testing DB lock. Tests may race.\n");
        fclose($lockFp);
        return;
    }

    // Truncate lock file so the first line always carries the current PID
    ftruncate($lockFp, 0);
    fwrite($lockFp, getmypid() . "\n");

    register_shutdown_function(function () use ($lockFp): void {
        if (is_resource($lockFp)) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }
    });
}
