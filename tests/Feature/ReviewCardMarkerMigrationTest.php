<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReviewCardMarkerMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_marker_column_defaults_to_zero_and_accepts_boundary_values(): void
    {
        $this->assertTrue(Schema::hasColumn('review_cards', 'marker'));

        $id = $this->insertCard();
        $this->assertSame(0, (int) DB::table('review_cards')->where('id', $id)->value('marker'));

        DB::table('review_cards')->where('id', $id)->update(['marker' => 7]);
        $this->assertSame(7, (int) DB::table('review_cards')->where('id', $id)->value('marker'));
    }

    public function test_marker_has_named_range_constraint_and_rejects_out_of_range_values(): void
    {
        $createTable = DB::selectOne('SHOW CREATE TABLE review_cards');
        $sql = (string) array_values((array) $createTable)[1];
        $this->assertStringContainsString('review_cards_marker_range_check', $sql);

        $id = $this->insertCard();

        foreach ([-1, 8] as $marker) {
            try {
                DB::table('review_cards')->where('id', $id)->update(['marker' => $marker]);
                $this->fail("Marker {$marker} must be rejected.");
            } catch (QueryException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_marker_lookup_index_has_the_frozen_column_order(): void
    {
        $rows = DB::select(
            "SHOW INDEXES FROM review_cards WHERE Key_name = 'review_cards_user_language_id_target_marker_index'"
        );
        usort($rows, fn ($left, $right) => $left->Seq_in_index <=> $right->Seq_in_index);

        $this->assertSame(
            ['user_id', 'language_id', 'target_type', 'marker'],
            array_map(fn ($row) => $row->Column_name, $rows)
        );
    }

    public function test_down_then_up_is_reversible_and_backfills_existing_rows(): void
    {
        $migration = require database_path('migrations/2026_07_18_000001_add_marker_to_review_cards.php');

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('review_cards', 'marker'));

            $id = $this->insertCard();
            $migration->up();

            $this->assertTrue(Schema::hasColumn('review_cards', 'marker'));
            $this->assertSame(0, (int) DB::table('review_cards')->where('id', $id)->value('marker'));
        } finally {
            if (!Schema::hasColumn('review_cards', 'marker')) {
                $migration->up();
            }
        }
    }

    private function insertCard(): int
    {
        return (int) DB::table('review_cards')->insertGetId([
            'user_id' => 991001,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => 'sense',
            'target_id' => random_int(100000, 999999),
            'fsrs_state' => 'new',
            'fsrs_enabled' => true,
            'lifecycle_state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
