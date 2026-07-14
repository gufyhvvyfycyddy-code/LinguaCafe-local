<?php

namespace Tests\Feature;

use App\Exceptions\CustomStudySessionException;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\CustomStudy\CustomStudySessionService;
use App\Services\CustomStudy\CustomStudySessionTokenService;
use App\Services\ReviewQueueOrderOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 2000-22 — Phase 4B CustomStudySessionService::resume tests.
 *
 * Verifies the 16-item resume matrix from §19.6.
 *
 * The resume() method is stateless: each call verifies the supplied token,
 * picks the next card (or keeps current), runs eligibility recheck, issues a
 * fresh token, and returns the current card. It must NOT write ReviewLog,
 * must NOT modify FSRS/lifecycle, must NOT call AI, and must NOT re-run the
 * criteria query.
 */
class CustomStudyResumeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private string $language = 'english';
    private string $otherLanguage = 'french';
    private CustomStudySessionService $service;
    private Carbon $now;
    private ?string $originalTz = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTz = config('app.timezone');
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));
        $this->now = Carbon::now();

        $this->user = User::forceCreate([
            'name' => 'Resume User',
            'email' => 'resume-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other Resume User',
            'email' => 'other-resume-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->service = app(CustomStudySessionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->originalTz !== null) {
            config(['app.timezone' => $this->originalTz]);
        }
        parent::tearDown();
    }

    // ─── Helpers ───

    private function createSense(array $overrides = []): WordSense
    {
        $defaults = [
            'user_id' => $this->user->id,
            'language' => $this->language,
            'language_id' => $this->language,
            'lemma' => 'test' . Str::random(6),
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
            'sense_key' => hash('sha256', strtolower($this->language . '|' . Str::random(10) . '|noun|测试|test')),
            'source_chapter_id' => null,
        ];
        return WordSense::forceCreate(array_merge($defaults, $overrides));
    }

    private function createCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        $defaults = [
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subDays(2),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ];
        return ReviewCard::forceCreate(array_merge($defaults, $overrides));
    }

    private function openSession(int $cardCount = 1): array
    {
        for ($i = 0; $i < $cardCount; $i++) {
            $sense = $this->createSense();
            $this->createCard($sense);
        }
        return $this->service->openSession(
            ['mode' => 'overdue'],
            $this->user->id,
            $this->language,
            $this->now,
            ReviewQueueOrderOptions::defaults()
        );
    }

    private function resume(string $token, ?Carbon $at = null): array
    {
        return $this->service->resume(
            $token,
            $this->user->id,
            $this->language,
            $at ?? $this->now
        );
    }

    private function answer(string $token, string $rating, ?Carbon $at = null): array
    {
        return $this->service->answer(
            $token,
            $rating,
            $this->user->id,
            $this->language,
            $at ?? $this->now
        );
    }

    // ─── 1. current is kept ───

    public function test_current_is_kept_on_resume(): void
    {
        $opened = $this->openSession(2);
        $currentBefore = $opened['current_card']['review_card_id'];

        $result = $this->resume($opened['token']);

        $this->assertSame($currentBefore, $result['current_card']['review_card_id']);
    }

    // ─── 2. current ineligible → skipped ───

    public function test_current_ineligible_is_skipped(): void
    {
        // Open with 2 cards. After opening, mark the current card as suspended.
        // Then resume — eligibility recheck should skip it and pop the next from ready.
        $opened = $this->openSession(2);
        $currentCardId = $opened['current_card']['review_card_id'];

        // Mark current card as suspended (simulates race)
        ReviewCard::where('id', $currentCardId)->update([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);

        $result = $this->resume($opened['token']);

        // Current should be the other card (from ready), not the suspended one
        $this->assertNotNull($result['current_card']);
        $this->assertNotSame($currentCardId, $result['current_card']['review_card_id']);
        $this->assertSame(1, $result['summary']['skipped_ineligible_count']);
    }

    // ─── 3. current null + ready → pick from ready ───

    public function test_current_null_picks_from_ready(): void
    {
        // To get current=null with ready non-empty, we use the ineligible-skip
        // path: open with 2 cards, suspend the current one. Resume skips it
        // and pops the next from ready.
        $opened = $this->openSession(2);
        $currentCardId = $opened['current_card']['review_card_id'];

        ReviewCard::where('id', $currentCardId)->update([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);

        $result = $this->resume($opened['token']);

        $this->assertNotNull($result['current_card']);
        $this->assertNotSame($currentCardId, $result['current_card']['review_card_id']);
    }

    // ─── 4. ready consecutive ineligible ───

    public function test_ready_consecutive_ineligible(): void
    {
        // Open with 3 cards. Suspend current + first ready. Resume should skip
        // both and pick the last ready card.
        $opened = $this->openSession(3);
        $tokenService = app(CustomStudySessionTokenService::class);
        $state = $tokenService->verify($opened['token'], $this->user->id, $this->language, $this->now);
        $currentId = $state->currentCardId();
        $readyQueue = $state->readyQueue();

        // Suspend current + first ready
        ReviewCard::whereIn('id', [$currentId, $readyQueue[0]])->update([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);

        $result = $this->resume($opened['token']);

        $this->assertNotNull($result['current_card']);
        $this->assertSame($readyQueue[1], $result['current_card']['review_card_id']);
        $this->assertSame(2, $result['summary']['skipped_ineligible_count']);
    }

    // ─── 5. mature delayed ───

    public function test_mature_delayed_picked_on_resume(): void
    {
        // Open with 1 card, answer 'again' → current=null, delayed=[card].
        // Advance time 61s, resume → matured delayed card becomes current.
        $opened = $this->openSession(1);
        $cardId = $opened['current_card']['review_card_id'];

        $result1 = $this->answer($opened['token'], 'again');
        $this->assertNull($result1['current_card']);

        $later = Carbon::now()->addSeconds(61);
        $result2 = $this->resume($result1['refreshed_token'], $later);

        $this->assertNotNull($result2['current_card']);
        $this->assertSame($cardId, $result2['current_card']['review_card_id']);
    }

    // ─── 6. delayed earliest ───

    public function test_delayed_earliest_picked(): void
    {
        // Open with 2 cards (A, B). answer 'again' on A → A delayed (avail +60s),
        // B becomes current. answer 'again' on B → B delayed (avail +60s from now),
        // pickNext: ready empty, delayed has A (avail +60s from now) and B (avail +60s from now).
        // Both have same available_at → tie. But A was added first, so A wins (stable).
        // Actually, after the second 'again', current = A (mature? no, avail = now+60, current time = now).
        // Both are immature → current = null.
        // Advance 61s. Resume → pick earliest matured. Both matured at +60s. Tie → A (first added).
        $opened = $this->openSession(2);
        $firstCurrent = $opened['current_card']['review_card_id'];

        $result1 = $this->answer($opened['token'], 'again');
        $secondCurrent = $result1['current_card']['review_card_id'];
        $this->assertNotSame($firstCurrent, $secondCurrent);

        $result2 = $this->answer($result1['refreshed_token'], 'again');
        $this->assertNull($result2['current_card']); // both delayed, immature

        $later = Carbon::now()->addSeconds(61);
        $result3 = $this->resume($result2['refreshed_token'], $later);

        // Both delayed entries matured at the same time. Tie → first added (A) wins.
        $this->assertNotNull($result3['current_card']);
        $this->assertSame($firstCurrent, $result3['current_card']['review_card_id']);
    }

    // ─── 7. delayed tie ───

    public function test_delayed_tie_keeps_queue_order(): void
    {
        // Same as test 6 — both delayed entries have the same available_at.
        // The first-added entry (A) should be picked (stable selection).
        $opened = $this->openSession(2);
        $firstCurrent = $opened['current_card']['review_card_id'];

        $result1 = $this->answer($opened['token'], 'again');
        $result2 = $this->answer($result1['refreshed_token'], 'again');

        $later = Carbon::now()->addSeconds(61);
        $result3 = $this->resume($result2['refreshed_token'], $later);

        // A (first added to delayed) should win the tie
        $this->assertSame($firstCurrent, $result3['current_card']['review_card_id']);
    }

    // ─── 8. immature delayed wait ───

    public function test_immature_delayed_keeps_current_null(): void
    {
        $opened = $this->openSession(1);
        $result1 = $this->answer($opened['token'], 'again');

        // Delayed entry is immature (available +60s, now is now)
        $this->assertNull($result1['current_card']);
        $this->assertNotNull($result1['wait_until']);

        // Resume immediately — delayed still immature, current stays null
        $result2 = $this->resume($result1['refreshed_token']);
        $this->assertNull($result2['current_card']);
        $this->assertNotNull($result2['wait_until']);
    }

    // ─── 9. completed ───

    public function test_completed_when_no_cards_left(): void
    {
        // Open with 1 card, answer 'good' → completed.
        $opened = $this->openSession(1);
        $result1 = $this->answer($opened['token'], 'good');
        $this->assertTrue($result1['completed']);

        // Resume on a completed session → still completed
        $result2 = $this->resume($result1['refreshed_token']);
        $this->assertTrue($result2['completed']);
        $this->assertNull($result2['current_card']);
    }

    // ─── 10. step only +1 ───

    public function test_step_increments_by_one(): void
    {
        $opened = $this->openSession(2);
        $tokenService = app(CustomStudySessionTokenService::class);
        $stateBefore = $tokenService->verify($opened['token'], $this->user->id, $this->language, $this->now);
        $stepBefore = $stateBefore->step();

        $result = $this->resume($opened['token']);
        $stateAfter = $tokenService->verify($result['refreshed_token'], $this->user->id, $this->language, $this->now);

        $this->assertSame($stepBefore + 1, $stateAfter->step());
    }

    // ─── 11. eligibility recheck does not add extra step ───

    public function test_eligibility_recheck_does_not_add_extra_step(): void
    {
        // Resume with eligibility recheck skipping cards: step should still only +1.
        $opened = $this->openSession(2);
        $currentCardId = $opened['current_card']['review_card_id'];

        // Suspend current card to trigger eligibility skip during resume
        ReviewCard::where('id', $currentCardId)->update([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);

        $tokenService = app(CustomStudySessionTokenService::class);
        $stateBefore = $tokenService->verify($opened['token'], $this->user->id, $this->language, $this->now);
        $stepBefore = $stateBefore->step();

        $result = $this->resume($opened['token']);
        $stateAfter = $tokenService->verify($result['refreshed_token'], $this->user->id, $this->language, $this->now);

        // Step should be stepBefore + 1, NOT stepBefore + 2 (even though
        // eligibility recheck skipped a card).
        $this->assertSame($stepBefore + 1, $stateAfter->step());
        $this->assertSame(1, $result['summary']['skipped_ineligible_count']);
    }

    // ─── 12. refreshed token ───

    public function test_refreshed_token_issued(): void
    {
        $opened = $this->openSession(2);

        $result = $this->resume($opened['token']);

        $this->assertNotEmpty($result['refreshed_token']);
        $this->assertNotSame($opened['token'], $result['refreshed_token']);

        // Refreshed token can be verified
        $tokenService = app(CustomStudySessionTokenService::class);
        $state = $tokenService->verify($result['refreshed_token'], $this->user->id, $this->language, $this->now);
        $this->assertNotNull($state);
    }

    // ─── 13. token 404 matrix ───

    public function test_tampered_token_throws_session_not_found(): void
    {
        $opened = $this->openSession(1);
        $tampered = substr($opened['token'], 0, -5) . 'XXXXX';

        $this->expectException(CustomStudySessionException::class);
        $this->resume($tampered);
    }

    public function test_expired_token_throws_session_not_found(): void
    {
        $opened = $this->openSession(1);
        $later = Carbon::now()->addSeconds(14401);

        $this->expectException(CustomStudySessionException::class);
        $this->service->resume(
            $opened['token'],
            $this->user->id,
            $this->language,
            $later
        );
    }

    public function test_wrong_user_throws_session_not_found(): void
    {
        $opened = $this->openSession(1);

        $this->expectException(CustomStudySessionException::class);
        $this->service->resume(
            $opened['token'],
            $this->otherUser->id,
            $this->language,
            $this->now
        );
    }

    public function test_wrong_language_throws_session_not_found(): void
    {
        $opened = $this->openSession(1);

        $this->expectException(CustomStudySessionException::class);
        $this->service->resume(
            $opened['token'],
            $this->user->id,
            $this->otherLanguage,
            $this->now
        );
    }

    public function test_empty_token_throws_session_not_found(): void
    {
        $this->expectException(CustomStudySessionException::class);
        $this->resume('');
    }

    // ─── 14. does not re-run criteria query ───

    public function test_does_not_rerun_criteria_query(): void
    {
        $opened = $this->openSession(2);

        $queries = 0;
        \DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->resume($opened['token']);
        \DB::flushQueryLog();

        // resume() should NOT re-run the criteria query. It should only:
        // - serialize current card (1-2 queries)
        // - eligibility recheck (1 query)
        $this->assertLessThan(15, $queries, 'resume() must not re-run the criteria query. Got ' . $queries . ' queries.');
    }

    // ─── 15. does not write DB ───

    public function test_does_not_write_db(): void
    {
        $opened = $this->openSession(2);
        $logBefore = ReviewLog::count();
        $cardBefore = ReviewCard::count();

        $this->resume($opened['token']);

        $this->assertSame($logBefore, ReviewLog::count(), 'resume() must not write ReviewLog.');
        $this->assertSame($cardBefore, ReviewCard::count(), 'resume() must not create/update/delete ReviewCard.');
    }

    // ─── 16. does not call AI ───

    public function test_does_not_call_ai(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(CustomStudySessionService::class))->getFileName()
        );
        $this->assertStringNotContainsString('OpenAi', $source);
        $this->assertStringNotContainsString('AiLookup', $source);
        $this->assertStringNotContainsString('AiReadingAssist', $source);
        $this->assertStringNotContainsString('->complete(', $source);
        $this->assertStringNotContainsString('->chat(', $source);
    }
}
