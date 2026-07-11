<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use App\Services\SenseReviewDailyReportService;
use App\Services\SenseReviewSevenDayTrendService;
use App\Services\SenseReviewThirtyDayCalendarService;
use App\Services\SenseReviewUndoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewUndoneAnalyticsTest
 *
 * ADR-0009: verifies that undone ratings are excluded from product
 * analytics (daily report, 7-day trend, 30-day calendar) but
 * retained in audit views (management page logs, session timeline).
 *
 * Contract:
 *  - Product analytics: exclude undone (whereNull('undone_at'))
 *  - Audit views: include undone (no exclusion)
 *  - Raw ReviewLog count never decreases
 */
class SenseReviewUndoneAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ReviewCardService $cardService;
    private ReviewCardFsrsSnapshotService $snapshotService;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Setting::where('name', 'reviewIntervals')->exists()) {
            Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3],
                    '-3' => [7], '-2' => [15], '-1' => [30],
                ]),
            ]);
        }

        $this->user = User::forceCreate([
            'name' => 'Analytics User',
            'email' => 'analytics@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->cardService = app(ReviewCardService::class);
        $this->snapshotService = app(ReviewCardFsrsSnapshotService::class);
    }

    // ==================== scopeNotUndone ====================

    public function test_scope_not_undone_excludes_undone_logs(): void
    {
        $sense = $this->createConfirmedSense('scope');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // Before undo: 1 active log
        $this->assertSame(1, ReviewLog::notUndone()->count());
        $this->assertSame(1, ReviewLog::count());

        // Undo it
        $this->undoLog($log->id, $sessionId);

        // After undo: 0 active, 1 total
        $this->assertSame(0, ReviewLog::notUndone()->count(), 'notUndone should exclude undone logs');
        $this->assertSame(1, ReviewLog::count(), 'Raw count should not decrease');
    }

    // ==================== Daily report ====================

    public function test_daily_report_excludes_undone_ratings(): void
    {
        $sense = $this->createConfirmedSense('daily');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        // Before undo: report shows 1 review
        $service = app(SenseReviewDailyReportService::class);
        $reportBefore = $service->build($this->user->id, 'english');
        $this->assertSame(1, $reportBefore['overview']['total_reviews']);

        // Undo
        $this->undoLog($log->id, $sessionId);

        // After undo: report shows 0 reviews
        $reportAfter = $service->build($this->user->id, 'english');
        $this->assertSame(0, $reportAfter['overview']['total_reviews'], 'Daily report should exclude undone ratings');
        $this->assertSame(0, $reportAfter['quality']['distribution']['good'], 'Distribution should exclude undone');
    }

    public function test_daily_report_includes_non_undone_ratings(): void
    {
        $senseA = $this->createConfirmedSense('incA');
        $cardA = $this->cardService->ensureSenseCard($senseA);
        $senseB = $this->createConfirmedSense('incB');
        $cardB = $this->cardService->ensureSenseCard($senseB);
        $sessionId = (string) Str::uuid();

        // Rate A and B (B is the latest active action)
        $this->cardService->recordReview($this->user->id, 'english', $cardA->id, 'good', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $cardB->id, 'hard', 'sense_review', $sessionId);

        // Undo B (the latest active action — stack undo semantics)
        $logB = ReviewLog::where('review_card_id', $cardB->id)->first();
        $this->undoLog($logB->id, $sessionId);

        $service = app(SenseReviewDailyReportService::class);
        $report = $service->build($this->user->id, 'english');

        // Only A should be counted (B is undone)
        $this->assertSame(1, $report['overview']['total_reviews']);
        $this->assertSame(1, $report['quality']['distribution']['good']);
        $this->assertSame(0, $report['quality']['distribution']['hard']);
    }

    // ==================== 7-day trend ====================

    public function test_seven_day_trend_excludes_undone_ratings(): void
    {
        $sense = $this->createConfirmedSense('trend');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        $service = app(SenseReviewSevenDayTrendService::class);
        $trendBefore = $service->build($this->user->id, 'english');
        $beforeTotal = array_sum(array_column($trendBefore['days'], 'total_reviews'));
        $this->assertSame(1, $beforeTotal);

        $this->undoLog($log->id, $sessionId);

        $trendAfter = $service->build($this->user->id, 'english');
        $afterTotal = array_sum(array_column($trendAfter['days'], 'total_reviews'));
        $this->assertSame(0, $afterTotal, '7-day trend should exclude undone ratings');
    }

    // ==================== 30-day calendar ====================

    public function test_thirty_day_calendar_excludes_undone_ratings(): void
    {
        $sense = $this->createConfirmedSense('calendar');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        $service = app(SenseReviewThirtyDayCalendarService::class);
        $calendarBefore = $service->build($this->user->id, 'english');
        $beforeTotal = array_sum(array_column($calendarBefore['days'], 'total_reviews'));
        $this->assertSame(1, $beforeTotal);

        $this->undoLog($log->id, $sessionId);

        $calendarAfter = $service->build($this->user->id, 'english');
        $afterTotal = array_sum(array_column($calendarAfter['days'], 'total_reviews'));
        $this->assertSame(0, $afterTotal, '30-day calendar should exclude undone ratings');
    }

    // ==================== Management page retains undone ====================

    public function test_management_page_logs_include_undone(): void
    {
        $sense = $this->createConfirmedSense('mgmt');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        $this->undoLog($log->id, $sessionId);

        // Management page logs endpoint
        $response = $this->actingAs($this->user)->getJson("/review-cards/manage/{$card->id}/logs");
        $response->assertOk();

        $items = $response->json('items');
        $this->assertCount(1, $items, 'Management page should retain undone logs for audit');

        $logEntry = $items[0];
        $this->assertTrue($logEntry['undone'], 'Log should be marked undone');
        $this->assertNotNull($logEntry['undone_at'], 'undone_at should be set');
        $this->assertSame('sense_review_snackbar', $logEntry['undo_source'], 'undo_source should be set');
        $this->assertSame('good', $logEntry['rating'], 'Original rating should be preserved');
    }

    // ==================== Session timeline retains undone ====================

    public function test_session_timeline_includes_undone(): void
    {
        $sense = $this->createConfirmedSense('sesstl');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        $this->undoLog($log->id, $sessionId);

        $response = $this->actingAs($this->user)->getJson(
            '/reviews/senses/session-actions?review_session_id=' . $sessionId,
        );
        $response->assertOk();

        $actions = $response->json('actions');
        $this->assertCount(1, $actions, 'Session timeline should include undone actions');
        $this->assertTrue($actions[0]['undone']);
    }

    // ==================== Raw ReviewLog count never decreases ====================

    public function test_raw_review_log_count_never_decreases_through_undo(): void
    {
        $sense = $this->createConfirmedSense('rawcount');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $log = ReviewLog::where('review_card_id', $card->id)->first();

        $countBefore = ReviewLog::count();
        $this->undoLog($log->id, $sessionId);
        $countAfter = ReviewLog::count();

        $this->assertSame($countBefore, $countAfter, 'Raw ReviewLog count must never decrease');
    }

    // ==================== Effective vs raw count ====================

    public function test_effective_count_differs_from_raw_after_undo(): void
    {
        $sense = $this->createConfirmedSense('effvsraw');
        $card = $this->cardService->ensureSenseCard($sense);
        $sessionId = (string) Str::uuid();

        // Rate 3 times
        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'good', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'hard', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $card->id, 'easy', 'sense_review', $sessionId);

        $rawCount = ReviewLog::count();
        $effectiveCount = ReviewLog::notUndone()->count();
        $this->assertSame(3, $rawCount);
        $this->assertSame(3, $effectiveCount);

        // Undo the latest (easy)
        $log = ReviewLog::where('review_card_id', $card->id)->orderBy('id', 'desc')->first();
        $this->undoLog($log->id, $sessionId);

        $rawCountAfter = ReviewLog::count();
        $effectiveCountAfter = ReviewLog::notUndone()->count();
        $this->assertSame(3, $rawCountAfter, 'Raw count unchanged');
        $this->assertSame(2, $effectiveCountAfter, 'Effective count should be 2 after undo');
    }

    // ==================== Multiple undone, analytics consistency ====================

    public function test_multiple_undone_ratings_all_excluded_from_daily_report(): void
    {
        $senseA = $this->createConfirmedSense('multiA');
        $cardA = $this->cardService->ensureSenseCard($senseA);
        $senseB = $this->createConfirmedSense('multiB');
        $cardB = $this->cardService->ensureSenseCard($senseB);
        $senseC = $this->createConfirmedSense('multiC');
        $cardC = $this->cardService->ensureSenseCard($senseC);
        $sessionId = (string) Str::uuid();

        // Rate A, B, C
        $this->cardService->recordReview($this->user->id, 'english', $cardA->id, 'good', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $cardB->id, 'hard', 'sense_review', $sessionId);
        $this->cardService->recordReview($this->user->id, 'english', $cardC->id, 'easy', 'sense_review', $sessionId);

        // Undo all three (stack order: C, then B, then A)
        $logC = ReviewLog::where('review_card_id', $cardC->id)->first();
        $this->undoLog($logC->id, $sessionId);

        $logB = ReviewLog::where('review_card_id', $cardB->id)->first();
        $this->undoLog($logB->id, $sessionId);

        $logA = ReviewLog::where('review_card_id', $cardA->id)->first();
        $this->undoLog($logA->id, $sessionId);

        // Daily report should show 0 reviews
        $service = app(SenseReviewDailyReportService::class);
        $report = $service->build($this->user->id, 'english');
        $this->assertSame(0, $report['overview']['total_reviews']);

        // Raw count should still be 3
        $this->assertSame(3, ReviewLog::count());

        // Management page should show all 3 for each card
        $this->assertSame(1, $this->managementLogsCount($cardA));
        $this->assertSame(1, $this->managementLogsCount($cardB));
        $this->assertSame(1, $this->managementLogsCount($cardC));
    }

    // ==================== Helpers ====================

    private function undoLog(int $logId, string $sessionId): array
    {
        $undoService = app(SenseReviewUndoService::class);
        return $undoService->undo(
            $logId,
            $this->user->id,
            'english',
            $sessionId,
            (string) Str::uuid(),
            'sense_review_snackbar',
        );
    }

    private function managementLogsCount(ReviewCard $card): int
    {
        $response = $this->actingAs($this->user)->getJson("/review-cards/manage/{$card->id}/logs");
        return count($response->json('items'));
    }

    private function createConfirmedSense(string $lemma): WordSense
    {
        return WordSense::forceCreate([
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
    }
}
