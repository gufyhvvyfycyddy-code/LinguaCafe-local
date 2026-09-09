<?php

namespace Tests\Feature;

use App\Services\DisposableTestDatabaseGuard;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Testing database health and safety check.
 *
 * Run this BEFORE any other feature test to confirm the testing DB is
 * correctly configured and safe to use.
 *
 * IMPORTANT: This test deliberately does NOT use RefreshDatabase so that the
 * health check never triggers schema drops or migrations by itself.
 */
class TestingDatabaseHealthTest extends TestCase
{
    public function test_app_env_is_testing(): void
    {
        $this->assertSame('testing', $this->app->environment(),
            'APP_ENV must be "testing" for feature tests. ' .
            'Check phpunit.xml <env name="APP_ENV" value="testing"/>.'
        );
    }

    public function test_database_name_is_a_recognised_testing_database(): void
    {
        $dbName = Config::get('database.connections.mysql.database');

        $this->assertNotNull($dbName, 'No database name configured for mysql connection in testing env.');

        // Since #55/#67 the test run executes against a per-run, process-owned
        // disposable database named `linguacafe_disposable_<run>_<id>` (which does
        // not contain the literal "test"). Accept either that disposable shape or
        // a legacy name containing "test"; anything else (e.g. a real/shared DB)
        // still fails, preserving the original "protect real data" intent.
        $isDisposable = DisposableTestDatabaseGuard::matchesDisposablePattern($dbName);
        $looksLikeTest = str_contains(strtolower($dbName), 'test');

        $this->assertTrue($isDisposable || $looksLikeTest,
            "Database '{$dbName}' does not look like a testing database " .
            '(expected a disposable run database or a name containing "test"). ' .
            'Aborting to protect real data.'
        );
    }

    public function test_database_is_not_production_database(): void
    {
        $dbName = Config::get('database.connections.mysql.database');

        $this->assertNotSame('linguacafe_fsrs', $dbName,
            'Testing database must NOT be the default "linguacafe_fsrs" database. ' .
            'Set DB_DATABASE in .env.testing to "linguacafe_fsrs_test" or similar.'
        );
    }

    public function test_migrations_table_exists(): void
    {
        $dbName = Config::get('database.connections.mysql.database');

        // Skip this test if the DB doesn't exist (will be caught by DB connection test instead)
        if ($dbName === null) {
            $this->markTestSkipped('No database configured — skipping migrations table check.');
        }

        $this->assertTrue(
            Schema::hasTable('migrations'),
            "Migrations table does not exist in '{$dbName}'. " .
            "Run: php artisan migrate --env=testing\n" .
            "Do NOT run: php artisan migrate:fresh\n" .
            "Do NOT run: php artisan db:wipe"
        );
    }

    public function test_migrations_are_up_to_date(): void
    {
        if (! Schema::hasTable('migrations')) {
            $this->markTestSkipped('Migrations table missing — skipping migration count check.');
        }

        $count = DB::table('migrations')->count();

        $this->assertGreaterThan(0, $count,
            'Migrations table is empty, which means no migrations have been run. ' .
            'Run: php artisan migrate --env=testing'
        );
    }

    public function test_no_destructive_commands_in_test_bootstrap(): void
    {
        $bootstrapPath = __DIR__ . '/../bootstrap.php';

        if (! file_exists($bootstrapPath)) {
            $this->markTestSkipped('tests/bootstrap.php not found — skipping static check.');
        }

        $contents = file_get_contents($bootstrapPath);

        $dangerous = [
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'db:wipe',
            'DB::statement(',
            'drop table',
            'DROP TABLE',
        ];

        foreach ($dangerous as $pattern) {
            $this->assertStringNotContainsString($pattern, $contents,
                "tests/bootstrap.php must NOT contain '{$pattern}'."
            );
        }

        // ftruncate() is the safe PHP function and is NOT a SQL TRUNCATE.
        // Check executable lines only, skip comments.
        $lines = file($bootstrapPath);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || $trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (str_contains($trimmed, 'ftruncate(') || str_contains($trimmed, 'flock(')) {
                continue;
            }
            $this->assertStringNotContainsStringIgnoringCase('truncate', $trimmed,
                "tests/bootstrap.php must NOT call truncate (ftruncate is safe). Offending line: {$trimmed}"
            );
        }
    }
}
