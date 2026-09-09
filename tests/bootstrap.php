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
 *  - Touches a database only to provision + forward-migrate the process-owned
 *    disposable database (#55/#67); never a shared / long-lived / real DB, and
 *    never a destructive schema reset.
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

    // #55 stage C: provision a unique, process-owned disposable database for
    // this run and repoint the connection at it, so destructive schema resets
    // triggered by RefreshDatabase can only ever touch a throwaway DB and
    // never a shared / long-lived / recovery testing database. If no server
    // connection is configured this is a no-op and the guard fails closed.
    try {
        $disposableDb = \Tests\Support\DisposableTestDatabase::activate();
        if ($disposableDb !== null) {
            register_shutdown_function(static function (): void {
                \Tests\Support\DisposableTestDatabase::dropAllCreated();
            });

            // #67: the disposable database provisioned above is created empty.
            // Tests using RefreshDatabase migrate it themselves, but tests that
            // rely on an already-migrated schema (no RefreshDatabase trait — e.g.
            // the dictionary import tests that create/drop their own helper tables
            // and assert transaction behavior) previously depended on the shared
            // testing database being pre-migrated out of band. Migrate the
            // disposable database once here so it faithfully replaces that
            // pre-migrated shared database for every test regardless of trait.
            //
            // The migration runs in a SEPARATE process so booting a Laravel
            // application (which installs error/exception handlers, container and
            // facade bindings via its bootstrappers) never pollutes the PHPUnit
            // process — doing it in-process would leave every subsequent test
            // flagged risky for "removed error handlers". The disposable database
            // name and ownership marker were exported with putenv() in activate(),
            // so the child artisan process inherits them and targets exactly the
            // same throwaway database. The plain forward-only migrate command is
            // used (never a destructive reset): it only applies pending migrations
            // and never wipes a populated database.
            $migrateCommand = escapeshellarg(PHP_BINARY)
                . ' ' . escapeshellarg(__DIR__ . '/../artisan')
                . ' migrate --no-interaction 2>&1';
            $migrateOutput = [];
            $migrateStatus = 1;
            exec($migrateCommand, $migrateOutput, $migrateStatus);
            if ($migrateStatus !== 0) {
                fwrite(STDERR, "[bootstrap] WARNING: disposable database migration exited with status "
                    . "{$migrateStatus}:\n" . implode("\n", $migrateOutput) . "\n");
            }
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, '[bootstrap] Could not provision a disposable test database: ' . $e->getMessage() . "\n");
    }
}
