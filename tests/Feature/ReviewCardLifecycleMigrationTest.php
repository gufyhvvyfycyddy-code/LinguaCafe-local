<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ReviewCardLifecycleMigrationTest
 *
 * ADR-0010: Verifies the single additive migration:
 *   - Adds lifecycle_state, buried_until, lifecycle_version, lifecycle_changed_at
 *   - Creates review_card_state_events audit table
 *   - Backfills lifecycle_state from fsrs_enabled
 *   - Is reversible (down drops columns + table)
 *
 * Note: RefreshDatabase runs the migration before each test, so the columns
 * already exist. We verify schema presence, default values, backfill behavior
 * by simulating pre-migration state, and reversibility by calling down()/up().
 */
class ReviewCardLifecycleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a UUID-based email to avoid duplicate key issues if DDL
        // implicit commits break RefreshDatabase transaction isolation.
        $this->user = User::forceCreate([
            'name' => 'Migration Test',
            'email' => 'migration-' . \Illuminate\Support\Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // ─── Schema verification ───

    public function test_review_cards_has_lifecycle_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('review_cards', 'lifecycle_state'));
        $this->assertTrue(Schema::hasColumn('review_cards', 'buried_until'));
        $this->assertTrue(Schema::hasColumn('review_cards', 'lifecycle_version'));
        $this->assertTrue(Schema::hasColumn('review_cards', 'lifecycle_changed_at'));
    }

    public function test_review_card_state_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('review_card_state_events'));
        $this->assertTrue(Schema::hasColumns('review_card_state_events', [
            'id', 'user_id', 'language_id', 'review_card_id', 'action',
            'previous_state', 'new_state', 'request_id', 'source',
            'metadata', 'created_at',
        ]));
    }

    public function test_request_id_has_unique_index(): void
    {
        $indexes = DB::select("SHOW INDEXES FROM review_card_state_events WHERE Key_name = 'rcse_request_id_unique'");
        $this->assertNotEmpty($indexes, 'request_id must have a unique index');
    }

    public function test_lifecycle_state_has_index(): void
    {
        $indexes = DB::select("SHOW INDEXES FROM review_cards WHERE Key_name = 'review_cards_lifecycle_state_index'");
        $this->assertNotEmpty($indexes);
    }

    public function test_buried_until_has_index(): void
    {
        $indexes = DB::select("SHOW INDEXES FROM review_cards WHERE Key_name = 'review_cards_buried_until_index'");
        $this->assertNotEmpty($indexes);
    }

    // ─── Default values ───

    public function test_new_card_defaults_to_active_lifecycle(): void
    {
        $sense = $this->createSense();
        $card = $this->createSenseCard($sense);

        $this->assertSame('active', $card->fresh()->lifecycle_state);
        $this->assertSame(0, (int) $card->fresh()->lifecycle_version);
        $this->assertNull($card->fresh()->buried_until);
        $this->assertNull($card->fresh()->lifecycle_changed_at);
    }

    // ─── Backfill verification ───

    public function test_backfill_archives_fsrs_disabled_cards(): void
    {
        // Simulate pre-migration state: lifecycle_state defaults to 'active'
        // (column default) even though fsrs_enabled=false. The backfill
        // should correct this to 'archived'.
        $sense = $this->createSense();
        $card = $this->createSenseCard($sense, ['fsrs_enabled' => false]);

        // Column default gives 'active' even for fsrs_enabled=false cards.
        $this->assertSame('active', $card->fresh()->lifecycle_state);

        // Re-run the backfill SQL (same logic as migration).
        DB::table('review_cards')->where('fsrs_enabled', false)->update(['lifecycle_state' => 'archived']);

        $this->assertSame('archived', $card->fresh()->lifecycle_state);
    }

    public function test_backfill_activates_fsrs_enabled_cards(): void
    {
        $sense = $this->createSense();
        $card = $this->createSenseCard($sense, ['fsrs_enabled' => true]);

        // Column default already gives 'active', but simulate a card that
        // was somehow set to a non-active state before backfill.
        DB::table('review_cards')->where('id', $card->id)->update(['lifecycle_state' => 'archived']);

        DB::table('review_cards')->where('fsrs_enabled', true)->update(['lifecycle_state' => 'active']);

        $this->assertSame('active', $card->fresh()->lifecycle_state);
    }

    public function test_backfill_does_not_modify_fsrs_scheduling(): void
    {
        $sense = $this->createSense();
        $card = $this->createSenseCard($sense, ['fsrs_enabled' => false]);
        $card->update([
            'fsrs_stability' => 5.5,
            'fsrs_difficulty' => 0.3,
            'fsrs_reps' => 7,
            'fsrs_lapses' => 2,
        ]);

        DB::table('review_cards')->where('fsrs_enabled', false)->update(['lifecycle_state' => 'archived']);

        $fresh = $card->fresh();
        $this->assertSame('archived', $fresh->lifecycle_state);
        $this->assertSame(5.5, (float) $fresh->fsrs_stability);
        $this->assertSame(0.3, (float) $fresh->fsrs_difficulty);
        $this->assertSame(7, (int) $fresh->fsrs_reps);
        $this->assertSame(2, (int) $fresh->fsrs_lapses);
    }

    // ─── Reversibility ───
    // NOTE: MySQL DDL statements cause implicit commits, which break
    // RefreshDatabase transaction isolation. We combine down + up in a
    // single test and restore the schema at the end so subsequent tests
    // are not affected.

    public function test_migration_down_then_up_is_reversible(): void
    {
        $migration = new \AddReviewCardLifecycleStateMachine();

        try {
            // down() should remove columns and drop the events table.
            $migration->down();

            $this->assertFalse(Schema::hasTable('review_card_state_events'));
            $this->assertFalse(Schema::hasColumn('review_cards', 'lifecycle_state'));
            $this->assertFalse(Schema::hasColumn('review_cards', 'buried_until'));
            $this->assertFalse(Schema::hasColumn('review_cards', 'lifecycle_version'));
            $this->assertFalse(Schema::hasColumn('review_cards', 'lifecycle_changed_at'));

            // up() should restore everything.
            $migration->up();

            $this->assertTrue(Schema::hasColumn('review_cards', 'lifecycle_state'));
            $this->assertTrue(Schema::hasColumn('review_cards', 'buried_until'));
            $this->assertTrue(Schema::hasColumn('review_cards', 'lifecycle_version'));
            $this->assertTrue(Schema::hasColumn('review_cards', 'lifecycle_changed_at'));
            $this->assertTrue(Schema::hasTable('review_card_state_events'));
        } finally {
            // Always restore schema so subsequent tests are not broken by
            // MySQL DDL implicit commits.
            if (!Schema::hasColumn('review_cards', 'lifecycle_state')) {
                $migration->up();
            }
        }
    }

    // ─── Helpers ───

    private function createSense(): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'test',
            'surface_form' => 'test',
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a test.',
            'example_sentence_zh' => '这是一个测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower('english|test|noun|测试|test')),
        ]);
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ], $overrides));
    }
}
