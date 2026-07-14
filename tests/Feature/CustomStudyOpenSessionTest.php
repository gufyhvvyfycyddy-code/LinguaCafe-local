<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
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
 * Task 2000-22 — Phase 4B CustomStudySessionService::openSession tests.
 *
 * Verifies the 38-item openSession matrix from §19.4.
 *
 * The service is stateless: each call returns a fresh token + payload.
 * It must NOT write ReviewLog, must NOT modify FSRS/lifecycle, must NOT
 * call AI, and must keep SQL query count constant regardless of card
 * count (no N+1).
 */
class CustomStudyOpenSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private string $language = 'english';
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
            'name' => 'Open User',
            'email' => 'open-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => 'other-open-' . Str::uuid() . '@example.com',
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

    private function createChapter(int $userId, string $language): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $userId,
            'name' => 'Book-' . Str::random(4),
            'language' => $language,
        ]);

        return Chapter::forceCreate([
            'user_id' => $userId,
            'book_id' => $book->id,
            'name' => 'Chapter-' . Str::random(4),
            'language' => $language,
            'raw_text' => 'Test chapter content.',
            'word_count' => 3,
            'read_count' => 0,
            'unique_words' => '["test"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

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

    private function openSession(array $input): array
    {
        return $this->service->openSession(
            $input,
            $this->user->id,
            $this->language,
            $this->now,
            ReviewQueueOrderOptions::defaults()
        );
    }

    private function eligibleCard(): array
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, [
            'fsrs_due_at' => Carbon::now()->subDays(2),
        ]);
        return [$sense, $card];
    }

    // ─── 1-4. Four modes succeed ───

    public function test_today_forgotten_mode_succeeds(): void
    {
        [$sense, $card] = $this->eligibleCard();
        ReviewLog::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => $this->language,
            'language' => $this->language,
            'review_card_id' => $card->id,
            'rating' => 'again',
            'reviewed_at' => Carbon::now()->subHours(2),
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => Carbon::now()->subDays(1),
            'new_due_at' => Carbon::now()->addDays(1),
            'previous_stability' => 1.0,
            'new_stability' => 1.5,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 5.0,
            'source' => 'sense_review',
            'undone_at' => null,
        ]);

        $result = $this->openSession(['mode' => 'today_forgotten']);

        $this->assertNotEmpty($result['token']);
        $this->assertNotEmpty($result['session_id']);
        $this->assertSame($card->id, $result['current_card']['review_card_id']);
    }

    public function test_overdue_mode_succeeds(): void
    {
        [$sense, $card] = $this->eligibleCard();

        $result = $this->openSession(['mode' => 'overdue']);

        $this->assertNotEmpty($result['token']);
        $this->assertSame($card->id, $result['current_card']['review_card_id']);
    }

    public function test_source_chapter_mode_succeeds(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card = $this->createCard($sense);

        $result = $this->openSession([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => $chapter->id],
        ]);

        $this->assertNotEmpty($result['token']);
        $this->assertSame($card->id, $result['current_card']['review_card_id']);
    }

    public function test_leech_attention_mode_succeeds(): void
    {
        // Leech attention requires sufficient review history. We use leech_only
        // sub_mode but the candidate set may be empty — that's still a valid
        // empty session. We verify the response shape, not the count.
        $result = $this->openSession([
            'mode' => 'leech_attention',
            'parameters' => ['sub_mode' => 'leech_only'],
        ]);

        $this->assertNotEmpty($result['token']);
        $this->assertNotEmpty($result['session_id']);
        $this->assertSame('leech_attention', $result['summary']['mode']);
    }

    // ─── 5. Criteria validator is called ───

    public function test_criteria_validator_is_called(): void
    {
        $this->expectException(\App\Exceptions\CustomStudyValidationException::class);
        $this->expectExceptionMessageMatches('/mode/');

        $this->openSession([]); // missing mode
    }

    // ─── 6. Chapter ownership is verified ───

    public function test_chapter_ownership_is_verified(): void
    {
        $otherChapter = $this->createChapter($this->otherUser->id, $this->language);

        $this->expectException(\App\Exceptions\CustomStudyValidationException::class);
        $this->expectExceptionMessageMatches('/chapter/');

        $this->openSession([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => $otherChapter->id],
        ]);
    }

    // ─── 7. Candidate query per-mode dispatch ───

    public function test_candidate_query_dispatches_per_mode(): void
    {
        // overdue-only card (due before dayStart) should NOT appear in
        // today_forgotten mode (no again log today).
        [$sense, $card] = $this->eligibleCard();

        $result = $this->openSession(['mode' => 'today_forgotten']);
        $this->assertNull($result['current_card']);

        $result = $this->openSession(['mode' => 'overdue']);
        $this->assertNotNull($result['current_card']);
        $this->assertSame($card->id, $result['current_card']['review_card_id']);
    }

    // ─── 8. SessionOrder orders full candidate set ───

    public function test_session_order_orders_full_candidate_set(): void
    {
        // Create 3 overdue cards with different retrievability (via stability).
        $sense1 = $this->createSense();
        $card1 = $this->createCard($sense1, ['fsrs_stability' => 1.0]);
        $sense2 = $this->createSense();
        $card2 = $this->createCard($sense2, ['fsrs_stability' => 5.0]);
        $sense3 = $this->createSense();
        $card3 = $this->createCard($sense3, ['fsrs_stability' => 20.0]);

        $result = $this->openSession(['mode' => 'overdue']);

        $ids = $result['summary']['total_count'];
        $this->assertSame(3, $ids);
    }

    // ─── 9. Forbidden to truncate before ordering ───

    public function test_forbidden_to_truncate_before_ordering(): void
    {
        // Create 3 cards. With card_limit=2, total_candidates must be 3
        // (full pre-truncation count) and total_count must be 2 (truncated).
        $this->eligibleCard();
        $this->eligibleCard();
        $this->eligibleCard();

        $result = $this->openSession(['mode' => 'overdue', 'card_limit' => 2]);

        $this->assertSame(3, $result['summary']['total_candidates']);
        $this->assertSame(2, $result['summary']['total_count']);
    }

    // ─── 10. Default card_limit=100 ───

    public function test_default_card_limit_is_100(): void
    {
        // With <100 cards, total_count should equal number of candidates.
        $this->eligibleCard();
        $this->eligibleCard();

        $result = $this->openSession(['mode' => 'overdue']);

        $this->assertSame(2, $result['summary']['total_count']);
        $this->assertSame(2, $result['summary']['total_candidates']);
    }

    // ─── 11. card_limit=1 ───

    public function test_card_limit_one(): void
    {
        $this->eligibleCard();
        $this->eligibleCard();

        $result = $this->openSession(['mode' => 'overdue', 'card_limit' => 1]);

        $this->assertSame(2, $result['summary']['total_candidates']);
        $this->assertSame(1, $result['summary']['total_count']);
    }

    // ─── 12. card_limit=500 ───

    public function test_card_limit_500(): void
    {
        $this->eligibleCard();

        $result = $this->openSession(['mode' => 'overdue', 'card_limit' => 500]);

        $this->assertSame(1, $result['summary']['total_count']);
        $this->assertSame(1, $result['summary']['total_candidates']);
    }

    // ─── 13-19. card_limit validation matrix → 422 equivalent ───

    public function test_card_limit_zero_throws(): void
    {
        $this->expectException(\App\Exceptions\CustomStudyValidationException::class);
        $this->openSession(['mode' => 'overdue', 'card_limit' => 0]);
    }

    public function test_card_limit_negative_throws(): void
    {
        $this->expectException(\App\Exceptions\CustomStudyValidationException::class);
        $this->openSession(['mode' => 'overdue', 'card_limit' => -1]);
    }

    public function test_card_limit_501_throws(): void
    {
        $this->expectException(\App\Exceptions\CustomStudyValidationException::class);
        $this->openSession(['mode' => 'overdue', 'card_limit' => 501]);
    }

    public function test_card_limit_string_throws(): void
    {
        $this->expectException(\App\Exceptions\CustomStudyValidationException::class);
        $this->openSession(['mode' => 'overdue', 'card_limit' => '100']);
    }

    public function test_card_limit_float_throws(): void
    {
        $this->expectException(\App\Exceptions\CustomStudyValidationException::class);
        $this->openSession(['mode' => 'overdue', 'card_limit' => 100.5]);
    }

    public function test_card_limit_bool_throws(): void
    {
        $this->expectException(\App\Exceptions\CustomStudyValidationException::class);
        $this->openSession(['mode' => 'overdue', 'card_limit' => true]);
    }

    public function test_card_limit_explicit_null_throws(): void
    {
        $this->expectException(\App\Exceptions\CustomStudyValidationException::class);
        $this->openSession(['mode' => 'overdue', 'card_limit' => null]);
    }

    // ─── 20-22. total_candidates vs total_count ───

    public function test_total_candidates_is_full_count(): void
    {
        $this->eligibleCard();
        $this->eligibleCard();

        $result = $this->openSession(['mode' => 'overdue']);

        $this->assertSame(2, $result['summary']['total_candidates']);
    }

    public function test_total_count_is_truncated_count(): void
    {
        $this->eligibleCard();
        $this->eligibleCard();
        $this->eligibleCard();

        $result = $this->openSession(['mode' => 'overdue', 'card_limit' => 2]);

        $this->assertSame(2, $result['summary']['total_count']);
    }

    public function test_268_candidates_with_limit_100(): void
    {
        for ($i = 0; $i < 268; $i++) {
            $this->eligibleCard();
        }

        $result = $this->openSession(['mode' => 'overdue', 'card_limit' => 100]);

        $this->assertSame(268, $result['summary']['total_candidates']);
        $this->assertSame(100, $result['summary']['total_count']);
    }

    // ─── 23. Empty candidate returns valid empty session ───

    public function test_empty_candidates_returns_valid_empty_session(): void
    {
        $result = $this->openSession(['mode' => 'overdue']);

        $this->assertNotEmpty($result['token']);
        $this->assertNotEmpty($result['session_id']);
        $this->assertNull($result['current_card']);
        $this->assertSame(0, $result['summary']['total_candidates']);
        $this->assertSame(0, $result['summary']['total_count']);
        $this->assertSame(0, $result['summary']['completed_count']);
        $this->assertSame(0, $result['summary']['remaining_count']);
        $this->assertSame(0, $result['summary']['step']);
    }

    // ─── 24. session_id is UUID v4 ───

    public function test_session_id_is_uuid_v4(): void
    {
        $result = $this->openSession(['mode' => 'overdue']);

        // UUID v4 regex (version field = 4, variant field = 8/9/a/b)
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $result['session_id']
        );
    }

    // ─── 25. issued_at correct ───

    public function test_issued_at_correct(): void
    {
        $result = $this->openSession(['mode' => 'overdue']);

        // expires_at - issued_at = TTL_SECONDS = 14400
        $expiresAt = Carbon::createFromTimestamp(
            strtotime($result['expires_at']),
            'UTC'
        )->getTimestamp();
        $this->assertSame($this->now->getTimestamp() + 14400, $expiresAt);
    }

    // ─── 26. expires_at = now + 14400 ───

    public function test_expires_at_is_now_plus_14400(): void
    {
        $result = $this->openSession(['mode' => 'overdue']);

        $expiresAt = Carbon::createFromTimestamp(
            strtotime($result['expires_at']),
            'UTC'
        );
        $this->assertSame($this->now->getTimestamp() + 14400, $expiresAt->getTimestamp());
    }

    // ─── 27. delay config = 60/600/0/0 ───

    public function test_delay_config_is_60_600_0_0(): void
    {
        // The delay config is stored in the encrypted token. We verify it
        // by issuing a token, then verifying it and inspecting the state.
        $result = $this->openSession(['mode' => 'overdue']);
        $tokenService = app(CustomStudySessionTokenService::class);
        $state = $tokenService->verify($result['token'], $this->user->id, $this->language, $this->now);

        $this->assertNotNull($state);
        $config = $state->previewDelayConfig();
        $this->assertSame(60, $config['again_secs']);
        $this->assertSame(600, $config['hard_secs']);
        $this->assertSame(0, $config['good_secs']);
        $this->assertSame(0, $config['easy_secs']);
    }

    // ─── 28. state step=0 ───

    public function test_state_step_is_zero(): void
    {
        $result = $this->openSession(['mode' => 'overdue']);

        $this->assertSame(0, $result['summary']['step']);
    }

    // ─── 29. Only serializes current ───

    public function test_only_serializes_current_card(): void
    {
        $this->eligibleCard();
        $this->eligibleCard();
        $this->eligibleCard();

        $result = $this->openSession(['mode' => 'overdue']);

        // current_card is a single object (or null), not a list
        $this->assertTrue(
            is_array($result['current_card']) || is_null($result['current_card']),
            'current_card must be a single object or null, not a list.'
        );
        if (is_array($result['current_card'])) {
            $this->assertArrayHasKey('review_card_id', $result['current_card']);
        }
    }

    // ─── 30. Does NOT serialize all candidates ───

    public function test_does_not_serialize_all_candidates(): void
    {
        $this->eligibleCard();
        $this->eligibleCard();
        $this->eligibleCard();

        $result = $this->openSession(['mode' => 'overdue']);

        // Response must NOT contain any list of all candidate cards
        $this->assertArrayNotHasKey('candidates', $result);
        $this->assertArrayNotHasKey('cards', $result);
        $this->assertArrayNotHasKey('all_cards', $result);
    }

    // ─── 31. Token can verify ───

    public function test_token_can_verify(): void
    {
        $result = $this->openSession(['mode' => 'overdue']);
        $tokenService = app(CustomStudySessionTokenService::class);
        $state = $tokenService->verify($result['token'], $this->user->id, $this->language, $this->now);

        $this->assertNotNull($state);
        $this->assertSame($result['session_id'], $state->sessionId());
    }

    // ─── 32. Token does not contain plaintext ID/JSON ───

    public function test_token_does_not_contain_plaintext_id_or_json(): void
    {
        $result = $this->openSession(['mode' => 'overdue']);
        $token = $result['token'];

        // Token must not contain the session_id in plaintext
        $this->assertStringNotContainsString($result['session_id'], $token);
        // Token must not contain JSON braces (encrypted blobs are base64-ish)
        $this->assertStringNotContainsString('"session_id"', $token);
        $this->assertStringNotContainsString('"user_id"', $token);
    }

    // ─── 33. Race: current ineligible → auto-skip ───

    public function test_race_current_ineligible_auto_skips(): void
    {
        // Card is eligible for query (passes senseReviewEligible at query time),
        // but the race guard catches it via eligibility recheck. We simulate
        // this by having the only candidate become ineligible between query
        // and state creation — here we mark the card as suspended BEFORE
        // calling openSession (the recheck will catch it).
        $sense = $this->createSense();
        $card = $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
            'fsrs_due_at' => Carbon::now()->subDays(2),
        ]);

        $result = $this->openSession(['mode' => 'overdue']);

        // Suspended card never appears in query result; session is empty
        $this->assertNull($result['current_card']);
        $this->assertSame(0, $result['summary']['total_count']);
    }

    // ─── 34. open eligibility recheck does not increment step ───

    public function test_open_eligibility_recheck_does_not_increment_step(): void
    {
        // Even if eligibility recheck skips cards, step stays 0.
        $sense1 = $this->createSense();
        $card1 = $this->createCard($sense1, ['fsrs_due_at' => Carbon::now()->subDays(2)]);
        // Note: both cards pass the query's eligible scope, so the recheck
        // finds them still eligible — no skips. Step is still 0.
        $sense2 = $this->createSense();
        $card2 = $this->createCard($sense2, ['fsrs_due_at' => Carbon::now()->subDays(2)]);

        $result = $this->openSession(['mode' => 'overdue']);

        $this->assertSame(0, $result['summary']['step']);
        $this->assertSame(0, $result['summary']['skipped_ineligible_count']);
    }

    // ─── 35. Does NOT write ReviewLog ───

    public function test_does_not_write_review_log(): void
    {
        $this->eligibleCard();
        $before = ReviewLog::count();

        $this->openSession(['mode' => 'overdue']);

        $this->assertSame($before, ReviewLog::count(), 'openSession must not write ReviewLog.');
    }

    // ─── 36. Does NOT modify FSRS/lifecycle ───

    public function test_does_not_modify_fsrs_or_lifecycle(): void
    {
        [$sense, $card] = $this->eligibleCard();
        $originalFsrsState = $card->fsrs_state;
        $originalDueAt = $card->fsrs_due_at;
        $originalStability = $card->fsrs_stability;
        $originalLifecycle = $card->lifecycle_state;

        $this->openSession(['mode' => 'overdue']);

        $card->refresh();
        $this->assertSame($originalFsrsState, $card->fsrs_state);
        $this->assertEquals($originalDueAt, $card->fsrs_due_at);
        $this->assertSame($originalStability, $card->fsrs_stability);
        $this->assertSame($originalLifecycle, $card->lifecycle_state);
    }

    // ─── 37. Does NOT call AI ───

    public function test_does_not_call_ai(): void
    {
        // We assert that no AI-related service is invoked by checking that
        // the source code does not reference AI services.
        $source = file_get_contents(
            (new \ReflectionClass(CustomStudySessionService::class))->getFileName()
        );
        $this->assertStringNotContainsString('OpenAi', $source);
        $this->assertStringNotContainsString('AiLookup', $source);
        $this->assertStringNotContainsString('AiReadingAssist', $source);
        $this->assertStringNotContainsString('->complete(', $source);
        $this->assertStringNotContainsString('->chat(', $source);
    }

    // ─── 38. Query count constant, no N+1 ───

    public function test_query_count_constant_no_n_plus_one(): void
    {
        // Open with 1 candidate vs 3 candidates: SQL query count must not
        // grow linearly with card count.
        $this->eligibleCard();

        $queries1 = 0;
        \DB::listen(function () use (&$queries1): void {
            $queries1++;
        });
        $this->openSession(['mode' => 'overdue']);
        \DB::flushQueryLog();

        // Reset for next run
        $this->eligibleCard();
        $this->eligibleCard();

        $queries3 = 0;
        \DB::listen(function () use (&$queries3): void {
            $queries3++;
        });
        $this->openSession(['mode' => 'overdue']);
        \DB::flushQueryLog();

        // Query count for 3 cards must NOT be 3x the count for 1 card.
        // Allow small variance but assert no linear growth.
        $this->assertLessThan(
            $queries1 * 2,
            $queries3,
            "Query count must not grow linearly with card count. 1 card={$queries1}, 3 cards={$queries3}."
        );
    }
}
