<?php

namespace Tests\Feature;

use App\Console\Commands\AuditGoalIdentity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalIdentityAuditCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(Kernel::class)->registerCommand(
            $this->app->make(AuditGoalIdentity::class),
        );
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        parent::tearDown();
    }

    public function test_json_contract_is_stable_and_clean_audit_is_read_only(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $exitCode = Artisan::call('goals:audit-identity', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('goal_identity_audit_v1', $payload['schema_version']);
        $this->assertSame('clean', $payload['status']);
        $this->assertFalse($payload['counts']['has_issues']);
        $this->assertArrayHasKey('duplicate_identity_groups', $payload['counts']);
        $this->assertArrayHasKey('dangling_goal_achievement_rows', $payload['counts']);
        $this->assertSame(
            ['review', 'read_words', 'learn_words', 'read_book_chapter', 'other'],
            array_keys($payload['counts']['type_counts']),
        );

        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression(
                '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',
                $query,
                'The audit command must execute read-only SQL only: '.$query,
            );
        }
    }

    public function test_duplicate_and_drift_are_reported_with_exit_two_without_row_mutation(): void
    {
        $this->createShadowTables();
        DB::table('goals')->insert([
            [
                'name' => 'first',
                'user_id' => 2001,
                'language' => 'english',
                'type' => 'review',
                'target_id' => null,
                'current_chapter' => null,
                'quantity' => 7,
            ],
            [
                'name' => 'second',
                'user_id' => 2001,
                'language' => ' English ',
                'type' => 'review',
                'target_id' => null,
                'current_chapter' => null,
                'quantity' => 99,
            ],
        ]);
        $before = DB::table('goals')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $exitCode = Artisan::call('goals:audit-identity', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(2, $exitCode);
        $this->assertSame('issues_found', $payload['status']);
        $this->assertSame(1, $payload['counts']['duplicate_identity_groups']);
        $this->assertSame(2, $payload['counts']['duplicate_identity_rows']);
        $this->assertSame(1, $payload['counts']['conflict_identity_groups']);
        $this->assertSame(1, $payload['counts']['identity_drift_rows']);
        $this->assertSame(1, $payload['counts']['language_drift_rows']);
        $this->assertSame($before, DB::table('goals')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    private function createShadowTables(): void
    {
        DB::statement(<<<SQL
CREATE TEMPORARY TABLE goals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    user_id INT NOT NULL,
    language VARCHAR(255) NOT NULL,
    type VARCHAR(255) NOT NULL,
    target_id VARCHAR(255) NULL,
    current_chapter INT NULL,
    quantity INT NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        DB::statement(<<<SQL
CREATE TEMPORARY TABLE goal_achievements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    language VARCHAR(255) NOT NULL,
    goal_id INT NOT NULL,
    achieved_quantity INT NOT NULL,
    goal_quantity INT NOT NULL,
    day DATE NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
}
