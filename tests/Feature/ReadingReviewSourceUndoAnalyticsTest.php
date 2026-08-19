<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use App\Services\SenseReviewAnalyticsQueryService;
use App\Services\SenseReviewUndoPolicy;
use App\Services\SenseReviewUndoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PAB R2 Phase B source / undo / analytics acceptance tests.
 *
 * INTEGRATION_DB_ONLY: this class writes the shared testing DB and MUST be
 * run only by the Integration owner after Backend Core has registered
 * reading_passive and reading_explicit. This lane only lints/commits it.
 */
class ReadingReviewSourceUndoAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ReviewCardService $cardService;
    private ReviewCardFsrsSnapshotService $snapshotService;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Setting::where('name', 'reviewIntervals')->exists()) {
            Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3],
                    '-3' => [7], '-2' => [15], '-1' => [30],
                ]),
            ]);
        }

        $this->user = User::forceCreate([
            'name' => 'Reading Rating Contract',
            'email' => '__VG_EMAIL_reading_rating_contract__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->cardService = app(ReviewCardService::class);
        $this->snapshotService = app(ReviewCardFsrsSnapshotService::class);
    }

    public function test_reading_passive_is_formal_rating_source_and_undo_restores_before_snapshot(): void
    {
        $this->assertContains('reading_passive', ReviewLog::FORMAL_RATING_SOURCES);
        $this->assertContains('reading_passive', SenseReviewUndoPolicy::SUPPORTED_SOURCES);

        $card = $this->createSenseCard('passive-source');
        $this->anchorCardForReaderGood($card, Carbon::now()->subDays(2)->startOfSecond());
        $card->refresh();
        $before = $this->snapshotService->capture($card);
        $sessionId = (string) Str::uuid();

        $result = $this->cardService->recordReviewWithLog(
            $this->user->id,
            'english',
            $card->id,
            'good',
            'reading_passive',
            $sessionId,
        );

        $log = $result['review_log'];
        $this->assertSame('reading_passive', $log->source);
        $this->assertSame('good', $log->rating);
        $this->assertEquals($before, $log->before_card_snapshot);
        $rawCount = ReviewLog::count();

        $undo = app(SenseReviewUndoService::class)->undo(
            $log->id,
            $this->user->id,
            'english',
            $sessionId,
            (string) Str::uuid(),
            'reading_review_test',
        );

        $this->assertTrue($undo['success']);
        $card->refresh();
        $this->assertTrue($this->snapshotService->matches($card, $before));
        $log->refresh();
        $this->assertNotNull($log->undone_at);
        $this->assertSame($rawCount, ReviewLog::count(), 'Undo must retain the original audit log.');
    }

    public function test_reading_explicit_is_formal_rating_source_and_undoable(): void
    {
        $this->assertContains('reading_explicit', ReviewLog::FORMAL_RATING_SOURCES);
        $this->assertContains('reading_explicit', SenseReviewUndoPolicy::SUPPORTED_SOURCES);

        $card = $this->createSenseCard('explicit-source');
        $sessionId = (string) Str::uuid();
        $result = $this->cardService->recordReviewWithLog(
            $this->user->id,
            'english',
            $card->id,
            'hard',
            'reading_explicit',
            $sessionId,
        );

        $log = $result['review_log'];
        $this->assertSame('reading_explicit', $log->source);

        $undo = app(SenseReviewUndoService::class)->undo(
            $log->id,
            $this->user->id,
            'english',
            $sessionId,
            (string) Str::uuid(),
            'reading_review_test',
        );

        $this->assertTrue($undo['success']);
        $log->refresh();
        $this->assertNotNull($log->undone_at);
    }

    public function test_reading_sources_are_included_in_analytics_while_active_and_excluded_after_undo(): void
    {
        $passive = $this->createSenseCard('analytics-passive');
        $this->anchorCardForReaderGood($passive, Carbon::now()->subDays(2)->startOfSecond());
        $explicit = $this->createSenseCard('analytics-explicit');
        $passiveSession = (string) Str::uuid();
        $explicitSession = (string) Str::uuid();

        $passiveResult = $this->cardService->recordReviewWithLog(
            $this->user->id, 'english', $passive->id, 'good', 'reading_passive', $passiveSession,
        );
        $explicitResult = $this->cardService->recordReviewWithLog(
            $this->user->id, 'english', $explicit->id, 'again', 'reading_explicit', $explicitSession,
        );

        $analytics = app(SenseReviewAnalyticsQueryService::class);
        $start = Carbon::now()->subMinute();
        $end = Carbon::now()->addMinute();
        $active = $analytics->reviewsForPeriod($this->user->id, 'english', $start, $end);
        $this->assertCount(2, $active);

        $undoService = app(SenseReviewUndoService::class);
        $this->assertTrue($undoService->undo(
            $explicitResult['review_log']->id,
            $this->user->id,
            'english',
            $explicitSession,
            (string) Str::uuid(),
            'reading_review_test',
        )['success']);
        $this->assertTrue($undoService->undo(
            $passiveResult['review_log']->id,
            $this->user->id,
            'english',
            $passiveSession,
            (string) Str::uuid(),
            'reading_review_test',
        )['success']);

        $effective = $analytics->reviewsForPeriod($this->user->id, 'english', $start, $end);
        $this->assertCount(0, $effective, 'notUndone analytics must exclude undone reading ratings.');
        $this->assertSame(
            2,
            ReviewLog::whereIn('source', [ReviewLog::SOURCE_READING_PASSIVE, ReviewLog::SOURCE_READING_EXPLICIT])->count(),
            'Audit history must retain both reading ratings.',
        );
    }

    public function test_undo_never_creates_a_fake_redo_rating(): void
    {
        $card = $this->createSenseCard('no-redo');
        $sessionId = (string) Str::uuid();
        $result = $this->cardService->recordReviewWithLog(
            $this->user->id, 'english', $card->id, 'easy', 'reading_explicit', $sessionId,
        );
        $log = $result['review_log'];
        $rawBefore = ReviewLog::count();

        $undo = app(SenseReviewUndoService::class)->undo(
            $log->id,
            $this->user->id,
            'english',
            $sessionId,
            (string) Str::uuid(),
            'reading_review_test',
        );

        $this->assertTrue($undo['success']);
        $this->assertSame($rawBefore, ReviewLog::count());
        $this->assertSame(0, ReviewLog::notUndone()->whereKey($log->id)->count());
        $this->assertSame(
            1,
            ReviewLog::where('review_card_id', $card->id)->count(),
            'Undo marks the original log undone; it must not manufacture a compensating redo log.',
        );
    }

    private function anchorCardForReaderGood(ReviewCard $card, Carbon $anchor): void
    {
        $card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_step_index' => null,
            'fsrs_due_at' => $anchor->copy()->addDays(30),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 4,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => $anchor,
        ])->save();
        ReviewLog::forceCreate([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language_id,
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => $anchor,
            'previous_state' => 'review',
            'new_state' => 'review',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);
    }

    private function createSenseCard(string $lemma)
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => null,
            'example_sentence_zh' => null,
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("english|{$lemma}|noun|测试|test")),
        ]);

        return $this->cardService->ensureSenseCard($sense);
    }
}
