<?php

namespace Tests\Feature;

use App\Support\GoalIdentity;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class GoalIdentityMigrationTest extends TestCase
{
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->table = 'r11z_goal_identity_'.bin2hex(random_bytes(6));
        DB::statement("CREATE TEMPORARY TABLE `{$this->table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `user_id` INT NOT NULL,
            `language` VARCHAR(255) NOT NULL,
            `type` VARCHAR(255) NOT NULL,
            `target_id` VARCHAR(255) NULL,
            `current_chapter` INT NULL,
            `quantity` INT NOT NULL,
            `created_at` TIMESTAMP NULL,
            `updated_at` TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        parent::tearDown();
    }

    public function test_up_down_up_preserves_rows_and_restores_constraint(): void
    {
        $this->insertGoal(1001, 'english', 'review', null, 37);
        $this->insertGoal(1001, 'english', 'read_book_chapter', 'book-one', 2);

        GoalIdentity::addConstraint(DB::connection(), $this->table, null);
        $this->assertTrue(GoalIdentity::constraintPresent(DB::connection(), $this->table));
        $this->assertSame(2, DB::table($this->table)->count());

        GoalIdentity::removeConstraint(DB::connection(), $this->table);
        $this->assertFalse(GoalIdentity::constraintPresent(DB::connection(), $this->table));
        $this->assertSame(2, DB::table($this->table)->count());

        GoalIdentity::addConstraint(DB::connection(), $this->table, null);
        $this->assertTrue(GoalIdentity::constraintPresent(DB::connection(), $this->table));
        $this->assertSame(37, (int) DB::table($this->table)->where('type', 'review')->value('quantity'));
        $this->assertSame(2, DB::table($this->table)->count());
    }

    public function test_duplicate_preflight_fails_closed_without_partial_schema_or_row_changes(): void
    {
        $this->insertGoal(1002, 'english', 'review', null, 7);
        $this->insertGoal(1002, 'english', 'review', null, 99);

        try {
            GoalIdentity::addConstraint(DB::connection(), $this->table, null);
            $this->fail('Expected duplicate Goal identities to block the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('duplicate_groups=1', $exception->getMessage());
        }

        $this->assertFalse(GoalIdentity::constraintPresent(DB::connection(), $this->table));
        $this->assertSame(2, DB::table($this->table)->count());
        $this->assertSame([7, 99], DB::table($this->table)->orderBy('quantity')->pluck('quantity')->all());
    }

    public function test_drift_preflight_fails_closed_without_normalizing_rows(): void
    {
        $this->insertGoal(1003, ' English ', 'review', null, 7);

        try {
            GoalIdentity::addConstraint(DB::connection(), $this->table, null);
            $this->fail('Expected identity drift to block the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('drift_rows=1', $exception->getMessage());
        }

        $this->assertFalse(GoalIdentity::constraintPresent(DB::connection(), $this->table));
        $this->assertSame(' English ', DB::table($this->table)->value('language'));
    }

    public function test_overlapping_drift_flags_count_each_row_only_once(): void
    {
        $this->insertGoal(1004, 'english', 'read_book_chapter', '   ', 1);

        $audit = GoalIdentity::audit(DB::connection(), $this->table, null);

        $this->assertSame(1, $audit['identity_drift_rows']);
        $this->assertSame(1, $audit['target_empty_rows']);
        $this->assertSame(1, $audit['target_trim_drift_rows']);
        $this->assertSame(1, $audit['targeted_type_without_target_rows']);
    }

    public function test_column_detection_does_not_treat_underscores_as_wildcards(): void
    {
        DB::statement("ALTER TABLE `{$this->table}` ADD COLUMN `goalXidentityXfingerprint` BINARY(32) NULL");

        $this->assertFalse(GoalIdentity::constraintPresent(DB::connection(), $this->table));

        GoalIdentity::addConstraint(DB::connection(), $this->table, null);

        $this->assertTrue(GoalIdentity::constraintPresent(DB::connection(), $this->table));
    }

    private function insertGoal(
        int $userId,
        string $language,
        string $type,
        ?string $targetId,
        int $quantity,
    ): void {
        DB::table($this->table)->insert([
            'name' => 'R11Z synthetic goal',
            'user_id' => $userId,
            'language' => $language,
            'type' => $type,
            'target_id' => $targetId,
            'current_chapter' => null,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
