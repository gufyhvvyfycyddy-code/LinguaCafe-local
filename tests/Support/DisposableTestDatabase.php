<?php

namespace Tests\Support;

use App\Services\DisposableTestDatabaseGuard;
use PDO;
use RuntimeException;

/**
 * #55 stage C — per-run disposable test-database runner.
 *
 * Provisions a unique, process-owned MySQL database for a test run and points
 * the connection env at it (plus the ownership marker the guard checks), so any
 * destructive schema reset (RefreshDatabase / migrate:fresh) can only ever touch
 * a throwaway database — never a shared / long-lived / recovery / dev / prod DB.
 *
 * It only ever drops databases it created itself, and only if they still match
 * the disposable-name contract. Together with DisposableTestDatabaseGuard this
 * makes the shared-testing-DB incident (#55) structurally impossible to repeat.
 */
class DisposableTestDatabase
{
    /** @var array<string,bool> database names this process created */
    private static array $created = [];

    public static function generateName(?int $timestamp = null, ?string $token = null): string
    {
        $ts = $timestamp ?? time();
        $rand = $token ?? bin2hex(random_bytes(6));

        return sprintf('linguacafe_disposable_%d_%s', $ts, $rand);
    }

    /**
     * Create a fresh disposable database and record ownership. Returns its name.
     */
    public static function create(?int $timestamp = null, ?string $token = null): string
    {
        $params = self::serverParams();
        if ($params === null) {
            throw new RuntimeException('[disposable-db] No MySQL server connection available to create a disposable database.');
        }

        $name = self::generateName($timestamp, $token);
        self::serverConnection($params)->exec(
            "CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        self::$created[$name] = true;

        return $name;
    }

    /**
     * Create a disposable database and repoint this process's connection env at
     * it (DB_DATABASE + ownership marker). Returns the name, or null when no
     * server connection is configured — in which case the environment is left
     * untouched and the guard fails closed against any destructive reset.
     */
    public static function activate(): ?string
    {
        if (self::serverParams() === null) {
            return null;
        }

        $name = self::create();
        self::putEnv('DB_DATABASE', $name);
        self::putEnv(DisposableTestDatabaseGuard::OWNERSHIP_MARKER_ENV, $name);

        return $name;
    }

    /**
     * Drop a database, but only if this run created it AND it still matches the
     * disposable-name contract. Any other name (shared / recovery / dev / prod /
     * arbitrary) is refused.
     */
    public static function drop(string $name): void
    {
        if (! isset(self::$created[$name])) {
            throw new RuntimeException(
                "[disposable-db] Refusing to drop '{$name}': not created by this run."
            );
        }

        if (! DisposableTestDatabaseGuard::matchesDisposablePattern($name)) {
            throw new RuntimeException(
                "[disposable-db] Refusing to drop '{$name}': not a disposable database name."
            );
        }

        $params = self::serverParams();
        if ($params !== null) {
            self::serverConnection($params)->exec("DROP DATABASE IF EXISTS `{$name}`");
        }

        unset(self::$created[$name]);
    }

    /** Best-effort cleanup of every database this run created (shutdown hook). */
    public static function dropAllCreated(): void
    {
        foreach (array_keys(self::$created) as $name) {
            try {
                self::drop($name);
            } catch (\Throwable) {
                // best effort — never abort teardown
            }
        }
    }

    /** @return array<string,bool> */
    public static function createdDatabases(): array
    {
        return self::$created;
    }

    private static function serverParams(): ?array
    {
        $conn = getenv('DB_CONNECTION') ?: 'mysql';
        if ($conn !== 'mysql') {
            return null;
        }

        $host = getenv('DB_HOST');
        $user = getenv('DB_USERNAME');
        if (! is_string($host) || $host === '' || ! is_string($user) || $user === '') {
            return null;
        }

        return [
            'host' => $host,
            'port' => getenv('DB_PORT') ?: '3306',
            'user' => $user,
            'pass' => getenv('DB_PASSWORD') ?: '',
        ];
    }

    private static function serverConnection(array $p): PDO
    {
        return new PDO(
            "mysql:host={$p['host']};port={$p['port']}",
            $p['user'],
            $p['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private static function putEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
