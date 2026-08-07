<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalIdentityDatabaseConstraintTest extends TestCase
{
    use DatabaseTransactions;

    public function test_default_scope_treats_null_and_empty_target_as_one_identity(): void
    {
        $userId = $this->nextSyntheticUserId();

        $this->insertGoal($userId, 'english', 'review', null, 7);

        $this->expectException(QueryException::class);
        $this->insertGoal($userId, 'english', 'review', '   ', 99);
    }

    public function test_language_and_type_whitespace_and_case_cannot_bypass_identity(): void
    {
        $userId = $this->nextSyntheticUserId();

        $this->insertGoal($userId, ' English ', ' REVIEW ', null, 7);

        $this->expectException(QueryException::class);
        $this->insertGoal($userId, 'english', 'review', null, 99);
    }

    public function test_numeric_target_scope_strips_leading_zeroes(): void
    {
        $userId = $this->nextSyntheticUserId();

        $this->insertGoal($userId, 'english', 'read_book_chapter', '001', 1);

        $this->expectException(QueryException::class);
        $this->insertGoal($userId, 'english', 'read_book_chapter', '1', 2);
    }

    public function test_distinct_target_scopes_can_coexist(): void
    {
        $userId = $this->nextSyntheticUserId();

        $this->insertGoal($userId, 'english', 'read_book_chapter', '1', 1);
        $this->insertGoal($userId, 'english', 'read_book_chapter', '2', 2);

        $this->assertSame(
            2,
            DB::table('goals')->where('user_id', $userId)->count(),
        );
    }

    public function test_non_numeric_target_scope_preserves_case(): void
    {
        $userId = $this->nextSyntheticUserId();

        $this->insertGoal($userId, 'english', 'read_book_chapter', 'Book-A', 1);
        $this->insertGoal($userId, 'english', 'read_book_chapter', 'book-a', 2);

        $this->assertSame(
            2,
            DB::table('goals')->where('user_id', $userId)->count(),
        );
    }

    private function insertGoal(
        int $userId,
        string $language,
        string $type,
        ?string $targetId,
        int $quantity,
    ): void {
        DB::table('goals')->insert([
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

    private function nextSyntheticUserId(): int
    {
        return 2_000_000_000 - random_int(1, 10_000_000);
    }
}
