<?php

namespace Tests\Feature;

use App\Services\DisposableTestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\DisposableTestDatabase;
use Tests\TestCase;

/**
 * #55 stage C — per-run disposable database runner.
 *
 * Deliberately does NOT use RefreshDatabase: these tests verify the runner that
 * makes destructive resets safe, so they must not themselves reset a database.
 */
class DisposableTestDatabaseRunnerTest extends TestCase
{
    public function test_generate_name_is_unique_and_disposable_shaped(): void
    {
        $a = DisposableTestDatabase::generateName();
        $b = DisposableTestDatabase::generateName();

        $this->assertNotSame($a, $b);
        $this->assertTrue(DisposableTestDatabaseGuard::matchesDisposablePattern($a));
        $this->assertTrue(DisposableTestDatabaseGuard::matchesDisposablePattern($b));
    }

    public function test_drop_refuses_protected_and_shared_databases(): void
    {
        foreach ([
            'linguacafe_pc_test',
            'linguacafe_ep1_recovery_test',
            'linguacafe_fsrs_test',
            'linguacafe_testing',
        ] as $protected) {
            try {
                DisposableTestDatabase::drop($protected);
                $this->fail("drop() must refuse protected database '{$protected}'");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('[disposable-db]', $e->getMessage());
            }
        }
    }

    public function test_drop_refuses_disposable_name_not_created_by_this_run(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not created by this run/');
        DisposableTestDatabase::drop('linguacafe_disposable_1700000000_deadbeef');
    }

    public function test_create_then_drop_roundtrip_on_the_real_server(): void
    {
        $name = DisposableTestDatabase::create();

        $this->assertTrue(DisposableTestDatabaseGuard::matchesDisposablePattern($name));
        $this->assertTrue($this->databaseExists($name), 'created disposable database should exist');
        $this->assertArrayHasKey($name, DisposableTestDatabase::createdDatabases());

        DisposableTestDatabase::drop($name);

        $this->assertFalse($this->databaseExists($name), 'dropped disposable database should be gone');
        $this->assertArrayNotHasKey($name, DisposableTestDatabase::createdDatabases());
    }

    public function test_current_run_executes_against_an_owned_disposable_database(): void
    {
        // Proves the bootstrap runner is active: this suite is running against a
        // freshly provisioned, uniquely-named, ownership-marked disposable DB.
        $current = DB::getDatabaseName();

        $this->assertTrue(
            DisposableTestDatabaseGuard::matchesDisposablePattern($current),
            "current test database '{$current}' must be a disposable per-run database",
        );
        $this->assertSame(
            $current,
            getenv(DisposableTestDatabaseGuard::OWNERSHIP_MARKER_ENV),
            'the ownership marker must name the current disposable database',
        );

        // And the guard consequently authorises a destructive reset here.
        $this->app->make(DisposableTestDatabaseGuard::class)
            ->assertDestructiveResetAllowed('migrate:fresh');
        $this->addToAssertionCount(1);
    }

    private function databaseExists(string $name): bool
    {
        return DB::table('information_schema.schemata')
            ->where('SCHEMA_NAME', $name)
            ->exists();
    }
}
