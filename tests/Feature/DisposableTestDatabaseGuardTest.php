<?php

namespace Tests\Feature;

use App\Services\DisposableTestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Source #55 stage A — fail-closed containment.
 *
 * These tests intentionally do NOT use RefreshDatabase: the guard under test is
 * precisely what prevents destructive schema resets, and exercising it must not
 * reset any database. They assert the guard decision directly and prove the
 * CommandStarting wiring rejects a destructive reset before it executes.
 */
class DisposableTestDatabaseGuardTest extends TestCase
{
    private string|false $originalMarker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMarker = getenv(DisposableTestDatabaseGuard::OWNERSHIP_MARKER_ENV);
        config(['database.default' => 'mysql']);
    }

    protected function tearDown(): void
    {
        if ($this->originalMarker === false) {
            putenv(DisposableTestDatabaseGuard::OWNERSHIP_MARKER_ENV);
        } else {
            putenv(DisposableTestDatabaseGuard::OWNERSHIP_MARKER_ENV . '=' . $this->originalMarker);
        }
        parent::tearDown();
    }

    private function guard(): DisposableTestDatabaseGuard
    {
        return $this->app->make(DisposableTestDatabaseGuard::class);
    }

    private function setMarker(?string $value): void
    {
        if ($value === null) {
            putenv(DisposableTestDatabaseGuard::OWNERSHIP_MARKER_ENV);
        } else {
            putenv(DisposableTestDatabaseGuard::OWNERSHIP_MARKER_ENV . '=' . $value);
        }
    }

    public function test_rejects_when_no_disposable_marker_is_set(): void
    {
        $this->setMarker(null);
        config(['database.connections.mysql.database' => 'linguacafe_disposable_123_abc']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\[test-db-guard\]/');
        $this->guard()->assertDestructiveResetAllowed('migrate:fresh');
    }

    public function test_rejects_shared_pc_test_database(): void
    {
        $this->setMarker('linguacafe_pc_test');
        config(['database.connections.mysql.database' => 'linguacafe_pc_test']);

        $this->expectException(RuntimeException::class);
        $this->guard()->assertDestructiveResetAllowed('migrate:fresh');
    }

    public function test_rejects_recovery_database(): void
    {
        // Even if an ownership marker is (wrongly) set to the recovery DB, the
        // protected-database list and the disposable pattern both reject it.
        $this->setMarker('linguacafe_ep1_recovery_test');
        config(['database.connections.mysql.database' => 'linguacafe_ep1_recovery_test']);

        $this->expectException(RuntimeException::class);
        $this->guard()->assertDestructiveResetAllowed('migrate:refresh');
    }

    public function test_rejects_name_that_merely_contains_test(): void
    {
        $this->setMarker('linguacafe_fsrs_test');
        config(['database.connections.mysql.database' => 'linguacafe_fsrs_test']);

        $this->expectException(RuntimeException::class);
        $this->guard()->assertDestructiveResetAllowed('migrate:reset');

        $this->assertFalse($this->guard()->isDisposableRunDatabase('some_random_test'));
    }

    public function test_rejects_disposable_pattern_name_without_matching_marker(): void
    {
        // Unique disposable-looking name, but the marker names a different DB:
        // this run does not own it, so it must still be refused.
        $this->setMarker('linguacafe_disposable_999_zzz');
        config(['database.connections.mysql.database' => 'linguacafe_disposable_123_abc']);

        $this->expectException(RuntimeException::class);
        $this->guard()->assertDestructiveResetAllowed('db:wipe');
    }

    public function test_allows_unique_disposable_database_owned_by_this_run(): void
    {
        $db = 'linguacafe_disposable_1700000000_a1b2c3';
        $this->setMarker($db);
        config(['database.connections.mysql.database' => $db]);

        $this->assertTrue($this->guard()->isDisposableRunDatabase($db));

        // Must not throw for any guarded command.
        foreach (DisposableTestDatabaseGuard::GUARDED_COMMANDS as $command) {
            $this->guard()->assertDestructiveResetAllowed($command);
        }
        $this->addToAssertionCount(1);
    }

    public function test_destructive_schema_queries_are_classified(): void
    {
        $g = $this->guard();
        foreach (['drop table `x`', 'DROP DATABASE y', 'drop schema z', 'truncate table t', 'TRUNCATE t'] as $q) {
            $this->assertTrue($g->isDestructiveSchemaQuery($q), "should be destructive: {$q}");
        }
        foreach (['select 1', 'insert into t values (1)', 'update t set a=1', 'set foreign_key_checks=0', 'show tables'] as $q) {
            $this->assertFalse($g->isDestructiveSchemaQuery($q), "should NOT be destructive: {$q}");
        }
    }

    public function test_query_guard_rejects_destructive_statement_on_protected_database(): void
    {
        $this->setMarker(null);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\[test-db-guard\]/');
        $this->guard()->assertQueryAllowed('drop table `words`', 'linguacafe_pc_test');
    }

    public function test_query_guard_allows_destructive_statement_on_owned_disposable_database(): void
    {
        $db = 'linguacafe_disposable_1700000000_owned';
        $this->setMarker($db);
        $this->guard()->assertQueryAllowed('drop table `words`', $db);
        $this->addToAssertionCount(1);
    }

    public function test_query_guard_allows_non_destructive_statement_even_when_unowned(): void
    {
        $this->setMarker(null);
        $this->guard()->assertQueryAllowed('select * from words', 'linguacafe_pc_test');
        $this->addToAssertionCount(1);
    }

    public function test_connection_layer_guard_is_wired_and_fires_before_any_drop(): void
    {
        // Uses the real (disposable) test connection. Removing the ownership
        // marker makes even this run's own database count as unowned, so a
        // destructive DDL must be refused at execution time — before the drop
        // actually runs. This proves the beforeExecuting guard is installed.
        $this->setMarker(null);

        try {
            DB::connection()->statement('DROP TABLE IF EXISTS lc_guard_probe_should_never_run');
            $this->fail('Expected the connection-layer guard to refuse the destructive DDL.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('[test-db-guard]', $e->getMessage());
        }

        // A non-destructive statement on the same connection still works, even
        // with the marker unset (the guard only fences destructive schema DDL).
        $this->assertSame(1, (int) DB::connection()->select('select 1 as n')[0]->n);
    }

    public function test_non_destructive_commands_are_not_guarded(): void
    {
        $this->assertFalse(DisposableTestDatabaseGuard::isGuardedCommand('migrate'));
        $this->assertFalse(DisposableTestDatabaseGuard::isGuardedCommand('migrate:status'));
        $this->assertFalse(DisposableTestDatabaseGuard::isGuardedCommand(null));
        $this->assertTrue(DisposableTestDatabaseGuard::isGuardedCommand('migrate:fresh'));
    }
}
