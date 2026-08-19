<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use App\Services\SenseReviewUndoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReadingEarlyReviewMinimumSpacingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ReviewCardService $service;
    private ReviewCardFsrsSnapshotService $snapshots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'G06B 24h Reader',
            'email' => 'g06b-24h-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->service = app(ReviewCardService::class);
        $this->snapshots = app(ReviewCardFsrsSnapshotService::class);
    }

    public function test_86399_seconds_blocks_reader_good_with_zero_mutation(): void
    {
        [$card, $anchor] = $this->anchoredReviewCard(Carbon::parse('2026-08-17T10:00:00Z'));
        $before = $this->snapshots->capture($card->fresh());
        $beforeLogs = ReviewLog::count();

        $this->assertReaderGoodBlocked($card, $anchor->copy()->addSeconds(86399));

        $this->assertSame($beforeLogs, ReviewLog::count());
        $this->assertTrue($this->snapshots->matches($card->fresh(), $before));
    }

    public function test_exactly_86400_seconds_allows_one_reader_good(): void
    {
        [$card, $anchor] = $this->anchoredReviewCard(Carbon::parse('2026-08-17T10:00:00Z'));

        $result = $this->readerGood($card, $anchor->copy()->addSeconds(86400));

        $this->assertSame(ReviewLog::SOURCE_READING_EXPLICIT, $result['review_log']->source);
        $this->assertSame('good', $result['review_log']->rating);
        $this->assertSame(
            $anchor->copy()->addSeconds(86400)->toIso8601String(),
            $result['review_log']->reviewed_at->utc()->toIso8601String(),
        );
        $this->assertSame(1, ReviewLog::where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count());
    }

    public function test_90000_seconds_allows_one_reader_good(): void
    {
        [$card, $anchor] = $this->anchoredReviewCard(Carbon::parse('2026-08-17T10:00:00Z'));

        $this->readerGood($card, $anchor->copy()->addSeconds(90000));

        $this->assertSame(1, ReviewLog::where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count());
    }

    public function test_crossing_midnight_without_24_elapsed_hours_stays_blocked(): void
    {
        [$card, $anchor] = $this->anchoredReviewCard(Carbon::parse('2026-08-17T23:30:00Z'));

        $this->assertReaderGoodBlocked($card, Carbon::parse('2026-08-18T23:29:59Z'));

        $this->assertSame(0, ReviewLog::where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count());
    }

    public function test_external_good_plus_30_seconds_blocks_reader_good(): void
    {
        [$card, $anchor] = $this->anchoredReviewCard(Carbon::parse('2026-08-17T10:00:00Z'));

        $this->assertReaderGoodBlocked($card, $anchor->copy()->addSeconds(30));

        $this->assertSame(0, ReviewLog::where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count());
    }

    public function test_day_seven_early_good_is_allowed_even_when_due_is_day_thirty(): void
    {
        [$card, $anchor] = $this->anchoredReviewCard(Carbon::parse('2026-08-01T10:00:00Z'), 30);
        $eventAt = $anchor->copy()->addDays(7);
        $this->assertTrue($card->fresh()->fsrs_due_at->greaterThan($eventAt));

        $this->readerGood($card, $eventAt);

        $this->assertSame(1, ReviewLog::where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count());
    }

    public function test_undo_restores_previous_effective_anchor_for_positive_spacing(): void
    {
        [$card, $anchor] = $this->anchoredReviewCard(Carbon::parse('2026-08-15T10:00:00Z'));
        $sessionId = (string) Str::uuid();
        $firstAt = $anchor->copy()->addDays(2);
        $first = $this->service->recordReviewWithLog(
            $this->user->id,
            'english',
            $card->id,
            'good',
            ReviewLog::SOURCE_READING_EXPLICIT,
            $sessionId,
            null,
            $firstAt,
        );
        app(SenseReviewUndoService::class)->undo(
            $first['review_log']->id,
            $this->user->id,
            'english',
            $sessionId,
            (string) Str::uuid(),
            'g06b_spacing_test',
        );

        $second = $this->readerGood($card->fresh(), $firstAt->copy()->addSeconds(30));

        $this->assertSame('good', $second['review_log']->rating);
        $this->assertNotNull($first['review_log']->fresh()->undone_at);
        $this->assertSame(1, ReviewLog::where('review_card_id', $card->id)
            ->where('source', ReviewLog::SOURCE_READING_EXPLICIT)
            ->whereNull('undone_at')
            ->count());
    }

    public function test_learning_and_relearning_states_fail_closed_for_positive_reader_good(): void
    {
        foreach (['new', 'learning', 'relearning'] as $state) {
            [$card, $anchor] = $this->anchoredReviewCard(Carbon::parse('2026-08-01T10:00:00Z'));
            $card->forceFill(['fsrs_state' => $state])->save();

            $this->assertReaderGoodBlocked($card, $anchor->copy()->addDays(7));
        }
    }

    public function test_again_inside_24_hours_is_a_real_formal_failure(): void
    {
        [$card, $anchor] = $this->anchoredReviewCard(Carbon::parse('2026-08-17T10:00:00Z'));

        $result = $this->service->recordReviewWithLog(
            $this->user->id,
            'english',
            $card->id,
            'again',
            ReviewLog::SOURCE_READING_EXPLICIT,
            (string) Str::uuid(),
            null,
            $anchor->copy()->addSeconds(30),
        );

        $this->assertSame('again', $result['review_log']->rating);
        $this->assertSame(1, ReviewLog::where('source', ReviewLog::SOURCE_READING_EXPLICIT)->where('rating', 'again')->count());
    }

    public function test_mismatched_review_card_anchor_fails_closed(): void
    {
        [$card, $anchor] = $this->anchoredReviewCard(Carbon::parse('2026-08-15T10:00:00Z'));
        $card->forceFill(['fsrs_last_reviewed_at' => $anchor->copy()->addSecond()])->save();

        $this->assertReaderGoodBlocked($card, $anchor->copy()->addDays(7));

        $this->assertSame(0, ReviewLog::where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count());
    }

    #[DataProvider('externalRatings')]
    public function test_external_sense_review_four_ratings_remain_available_inside_reader_floor(string $rating): void
    {
        [$card, $anchor] = $this->anchoredReviewCard(Carbon::parse('2026-08-17T10:00:00Z'));

        $result = $this->service->recordReviewWithLog(
            $this->user->id,
            'english',
            $card->id,
            $rating,
            ReviewLog::SOURCE_SENSE_REVIEW,
            (string) Str::uuid(),
            null,
            $anchor->copy()->addSeconds(30),
        );

        $this->assertSame($rating, $result['review_log']->rating);
        $this->assertSame(ReviewLog::SOURCE_SENSE_REVIEW, $result['review_log']->source);
    }

    public static function externalRatings(): array
    {
        return [['again'], ['hard'], ['good'], ['easy']];
    }

    private function readerGood(ReviewCard $card, Carbon $eventAt): array
    {
        return $this->service->recordReviewWithLog(
            $this->user->id,
            'english',
            $card->id,
            'good',
            ReviewLog::SOURCE_READING_EXPLICIT,
            (string) Str::uuid(),
            null,
            $eventAt,
        );
    }

    private function assertReaderGoodBlocked(ReviewCard $card, Carbon $eventAt): void
    {
        try {
            $this->readerGood($card, $eventAt);
            $this->fail('Reader Good should have been blocked by the 24h minimum spacing floor.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(ReviewCardService::ERROR_READING_POSITIVE_SPACING_BLOCKED, $e->getMessage());
        }
    }

    /** @return array{ReviewCard, Carbon} */
    private function anchoredReviewCard(Carbon $anchor, int $dueDays = 30): array
    {
        $suffix = Str::lower(Str::random(8));
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'anchor-'.$suffix,
            'surface_form' => 'anchor-'.$suffix,
            'pos' => 'noun',
            'sense_zh' => '锚点',
            'sense_en' => 'anchor',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', 'g06b-anchor-'.$suffix),
        ]);
        $card = $this->service->ensureSenseCard($sense);
        $card->forceFill([
            'fsrs_state' => 'review',
            'fsrs_step_index' => null,
            'fsrs_due_at' => $anchor->copy()->addDays($dueDays),
            'fsrs_stability' => 12.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 4,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => $anchor,
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ])->save();

        ReviewLog::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => $anchor,
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => $anchor->copy()->subDay(),
            'new_due_at' => $card->fsrs_due_at,
            'previous_stability' => 10.0,
            'new_stability' => 12.0,
            'previous_difficulty' => 5.2,
            'new_difficulty' => 5.0,
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);

        return [$card->fresh(), $anchor->copy()];
    }
}
