<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #57 regression guard. FSRS schedules due dates up to maximum_interval_days
 * (clamp 36500 ≈ 100 years), so review_cards.fsrs_due_at must use DATETIME, not
 * TIMESTAMP (which tops out at 2038-01-19). These assertions lock the column
 * type and prove a far-future due date round-trips through the normal model.
 */
class ReviewCardDueDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_fsrs_datetime_columns_are_datetime_not_timestamp(): void
    {
        foreach (['fsrs_due_at', 'fsrs_last_reviewed_at'] as $col) {
            $type = DB::table('information_schema.columns')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', 'review_cards')
                ->where('column_name', $col)
                ->value('DATA_TYPE');

            $this->assertSame(
                'datetime',
                strtolower((string) $type),
                "review_cards.{$col} must be DATETIME to store far-future FSRS due dates",
            );
        }
    }

    public function test_review_card_persists_a_due_date_beyond_2038(): void
    {
        $user = User::factory()->create();

        // Within the app's real scheduling horizon (maximum_interval_days clamp
        // is 36500 days ≈ year 2126); unstorable in a TIMESTAMP column.
        $due = Carbon::create(2125, 1, 1, 0, 0, 0, 'UTC');

        $card = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => 1,
            'fsrs_state' => 'review',
            'fsrs_stability' => 9999.0,
            'fsrs_difficulty' => 0.3,
            'fsrs_due_at' => $due,
            'fsrs_reps' => 20,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);

        $reloaded = ReviewCard::findOrFail($card->id);

        $this->assertInstanceOf(Carbon::class, $reloaded->fsrs_due_at);
        $this->assertTrue(
            $reloaded->fsrs_due_at->equalTo($due),
            'a post-2038 FSRS due date must round-trip intact',
        );
    }
}
