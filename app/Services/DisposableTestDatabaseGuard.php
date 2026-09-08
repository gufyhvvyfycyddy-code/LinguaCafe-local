<?php

namespace App\Services;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * DisposableTestDatabaseGuard — fail-closed containment for Source #55 (stage A).
 *
 * Prevents destructive schema-reset console commands (migrate:fresh /
 * migrate:refresh / migrate:reset / db:wipe) — such as those triggered by the
 * RefreshDatabase test trait — from running against any long-lived or shared
 * database while APP_ENV=testing.
 *
 * A destructive reset is permitted ONLY when the target database is proven to
 * be a uniquely-named, per-run, process-owned disposable database. All of the
 * following must hold (fail closed — any missing condition rejects):
 *   1. an explicit disposable-run marker (env LINGUACAFE_DISPOSABLE_DB) is set;
 *   2. the target connection's database name equals that marker, proving the
 *      current process explicitly owns exactly this database;
 *   3. the database name matches the unique per-run disposable pattern.
 *
 * Being APP_ENV=testing, or a database name that merely "contains test", is
 * deliberately NOT sufficient.
 *
 * This guard never connects to a database and never reads .env; it decides
 * purely from resolved config plus the ownership marker, before the command
 * runs, so the reset is refused before any schema is touched.
 */
class DisposableTestDatabaseGuard
{
    /** Destructive schema-reset console commands this guard fences. */
    public const GUARDED_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    /** Ownership marker env var naming the one disposable DB this run owns. */
    public const OWNERSHIP_MARKER_ENV = 'LINGUACAFE_DISPOSABLE_DB';

    /** Unique per-run disposable database name pattern. */
    private const DISPOSABLE_PATTERN = '/^linguacafe_disposable_[0-9]+_[A-Za-z0-9]+$/';

    /**
     * Known long-lived / shared databases that must never be schema-reset by a
     * test run, regardless of any other signal. Defense in depth.
     */
    private const PROTECTED_DATABASES = [
        'linguacafe_pc_test',
        'linguacafe_ep1_recovery_test',
        'linguacafe_fsrs_test',
        'linguacafe_testing',
    ];

    public function __construct(private readonly Application $app)
    {
    }

    public static function isGuardedCommand(?string $command): bool
    {
        return $command !== null && in_array($command, self::GUARDED_COMMANDS, true);
    }

    /**
     * Destructive schema statements that erase or reset structure. Matched at
     * the connection layer so a reset is refused before the first drop runs,
     * independent of how the reset was invoked (CLI, RefreshDatabase, etc.).
     */
    public function isDestructiveSchemaQuery(string $query): bool
    {
        return preg_match(
            '/^\s*(drop\s+(table|database|schema|view)|truncate(\s+table)?)\b/i',
            $query,
        ) === 1;
    }

    /**
     * Connection-layer fail-closed check (registered via beforeExecuting while
     * APP_ENV=testing). Non-destructive statements always pass; a destructive
     * schema statement is refused unless the connection's database is a
     * sanctioned disposable run database.
     */
    public function assertQueryAllowed(string $query, string $databaseName): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        if (! $this->isDestructiveSchemaQuery($query)) {
            return;
        }

        if ($this->isDisposableRunDatabase($databaseName)) {
            return;
        }

        throw new RuntimeException(sprintf(
            "[test-db-guard] Refusing destructive schema statement against non-disposable "
            . "database '%s'. Destructive schema resets are only allowed on a unique per-run "
            . "disposable database explicitly owned via the %s marker; shared/long-lived "
            . 'testing databases are protected.',
            $databaseName,
            self::OWNERSHIP_MARKER_ENV,
        ));
    }

    /**
     * Throw unless the destructive command is allowed to run against the
     * resolved target database. No-op outside the testing environment.
     */
    public function assertDestructiveResetAllowed(string $command, ?string $connectionName = null): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        $connection = ($connectionName !== null && $connectionName !== '')
            ? $connectionName
            : config('database.default');

        $database = config("database.connections.{$connection}.database");

        if (! is_string($database) || $database === '') {
            throw new RuntimeException(
                "[test-db-guard] Refusing '{$command}': target database for connection "
                . "'{$connection}' is undefined."
            );
        }

        if ($this->isDisposableRunDatabase($database)) {
            return;
        }

        throw new RuntimeException(sprintf(
            "[test-db-guard] Refusing destructive '%s' against non-disposable database '%s'. "
            . 'A destructive schema reset is only allowed on a unique per-run disposable database '
            . "(name matching 'linguacafe_disposable_<run>_<id>') explicitly owned via the "
            . '%s marker. Shared/long-lived testing databases are protected.',
            $command,
            $database,
            self::OWNERSHIP_MARKER_ENV,
        ));
    }

    /**
     * A database is a sanctioned disposable run database only when it is not a
     * known protected database, matches the unique per-run pattern, and is the
     * exact database named by this run's ownership marker.
     */
    /**
     * A name is disposable-shaped when it is not a known protected database and
     * matches the unique per-run pattern. This is the necessary structural
     * condition (no ownership proof) — reused by the per-run runner to decide
     * which databases it is ever allowed to drop.
     */
    public static function matchesDisposablePattern(string $database): bool
    {
        if (in_array($database, self::PROTECTED_DATABASES, true)) {
            return false;
        }

        return preg_match(self::DISPOSABLE_PATTERN, $database) === 1;
    }

    public function isDisposableRunDatabase(string $database): bool
    {
        if (! self::matchesDisposablePattern($database)) {
            return false;
        }

        $marker = getenv(self::OWNERSHIP_MARKER_ENV);
        if (! is_string($marker) || $marker === '') {
            return false;
        }

        return hash_equals($marker, $database);
    }
}
