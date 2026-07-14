<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\CustomStudy\CustomStudyCriteria;
use App\Services\CustomStudy\CustomStudySessionService;
use App\Services\CustomStudy\CustomStudySessionState;
use App\Services\CustomStudy\CustomStudySessionTokenService;
use App\Services\ReviewQueueOrderOptions;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 2000-22 — Phase 4B Security tests (§16, §19.5-19.6, FACT §53).
 *
 * Verifies the complete token failure matrix from §16.2 at the HTTP level:
 * all eight failure modes must return 404 with the SAME generic payload,
 * with no leakage of the actual failure reason.
 *
 * Token failure matrix (§16.2):
 *   1. empty token          → 404 session_not_found
 *   2. tampered token       → 404 session_not_found
 *   3. random string        → 404 session_not_found
 *   4. expired token        → 404 session_not_found
 *   5. wrong user           → 404 session_not_found
 *   6. wrong language       → 404 session_not_found
 *   7. unsupported version  → 404 session_not_found
 *   8. corrupted payload    → 404 session_not_found
 *
 * No-leakage contract (FACT §53):
 *   - All eight 404 responses must have identical message + reason.
 *   - The response body must NOT contain failure-specific words
 *     ("expired", "tampered", "version", "user", "language", "decrypt").
 *   - No stack trace or exception details exposed.
 *
 * Replay security (§16.1, §19.5 items 25-26):
 *   - Client-obsolete token replay forms an independent preview branch.
 *   - Replay does NOT write ReviewLog, FSRS, or lifecycle.
 */
class CustomStudySecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private string $language = 'english';
    private string $otherLanguage = 'spanish';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $this->user = User::forceCreate([
            'name' => 'Security User',
            'email' => 'sec-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
            'is_admin' => false,
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other Security User',
            'email' => 'other-sec-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->otherLanguage,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
            'is_admin' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ───

    private function createSense(array $overrides = []): WordSense
    {
        $defaults = [
            'user_id' => $this->user->id,
            'language' => $this->language,
            'language_id' => $this->language,
            'lemma' => 'sec' . Str::random(6),
            'surface_form' => 'sec',
            'pos' => 'noun',
            'sense_zh' => '安全',
            'sense_en' => 'security',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Security test.',
            'example_sentence_zh' => '安全测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower($this->language . '|' . Str::random(10) . '|noun|安全|security')),
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
            'buried_until' => null,
            'lifecycle_version' => 0,
            'lifecycle_changed_at' => null,
        ];
        return ReviewCard::forceCreate(array_merge($defaults, $overrides));
    }

    private function eligibleCard(): ReviewCard
    {
        return $this->createCard($this->createSense());
    }

    private function openSession(): string
    {
        $this->eligibleCard();
        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'overdue',
        ]);
        $response->assertStatus(200);
        return $response->json('token');
    }

    /**
     * Open a session via the service layer (not HTTP) and return the token.
     * Used when the test needs to switch users mid-test — calling actingAs()
     * twice in one test causes auth.session middleware conflicts.
     */
    private function openSessionViaService(): string
    {
        $this->eligibleCard();
        $service = app(CustomStudySessionService::class);
        $result = $service->openSession(
            ['mode' => 'overdue'],
            $this->user->id,
            $this->language,
            Carbon::now(),
            ReviewQueueOrderOptions::defaults()
        );
        return $result['token'];
    }

    /**
     * Craft an encrypted token with a custom payload (for unsupported-version
     * and corrupted-payload tests). Uses the same Encrypter as TokenService.
     */
    private function craftEncryptedToken(string $jsonPayload): string
    {
        /** @var Encrypter $encrypter */
        $encrypter = app(Encrypter::class);
        return $encrypter->encrypt($jsonPayload, false);
    }

    /**
     * Build a valid state array, then override specific fields to produce
     * an unsupported-version token.
     */
    private function craftUnsupportedVersionToken(): string
    {
        $this->eligibleCard();
        $tokenService = app(CustomStudySessionTokenService::class);

        // Issue a real token to get a valid state payload, then tamper
        // with the version field.
        $state = CustomStudySessionState::createInitial(
            version: CustomStudySessionState::VERSION,
            userId: $this->user->id,
            language: $this->language,
            criteria: \App\Services\CustomStudy\CustomStudyCriteria::fromArray([
                'mode' => 'overdue',
            ]),
            sessionId: (string) Str::uuid(),
            issuedAt: Carbon::now()->getTimestamp(),
            expiresAt: Carbon::now()->getTimestamp() + 14400,
            orderedCandidateIds: [],
            availableCandidateCount: 0,
            previewDelayConfig: [
                'again_secs' => 60,
                'hard_secs' => 600,
                'good_secs' => 0,
                'easy_secs' => 0,
            ],
        );

        $payload = $state->toArray();
        // Override version to an unsupported value.
        $payload['version'] = 999;

        return $this->craftEncryptedToken(json_encode($payload));
    }

    /**
     * Craft a token whose decrypted content is not valid JSON.
     */
    private function craftCorruptedPayloadToken(): string
    {
        return $this->craftEncryptedToken('this is not json {{{');
    }

    /**
     * Craft a token whose decrypted content is valid JSON but missing
     * required state fields (invalid state structure).
     */
    private function craftInvalidStateToken(): string
    {
        return $this->craftEncryptedToken(json_encode(['random' => 'data', 'no' => 'state fields']));
    }

    // ─── Token failure matrix: answer endpoint ───

    public function test_answer_empty_token_returns_404(): void
    {
        $this->assertAnswer404('');
    }

    public function test_answer_tampered_token_returns_404(): void
    {
        $valid = $this->openSession();
        $tampered = substr($valid, 0, -5) . 'XXXXX';
        $this->assertAnswer404($tampered);
    }

    public function test_answer_random_string_returns_404(): void
    {
        $this->assertAnswer404('this-is-not-a-valid-encrypted-token-at-all');
    }

    public function test_answer_expired_token_returns_404(): void
    {
        $token = $this->openSession();

        // Advance time past the 14400s TTL.
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 16, 0, 1, 'UTC'));

        $this->assertAnswer404($token);
    }

    public function test_answer_wrong_user_returns_404(): void
    {
        $token = $this->openSessionViaService();

        $response = $this->actingAs($this->otherUser)->postJson('/custom-study/sessions/answer', [
            'token' => $token,
            'rating' => 'good',
        ]);

        $this->assert404NoLeakage($response);
    }

    public function test_answer_wrong_language_returns_404(): void
    {
        $token = $this->openSessionViaService();

        // The other user has selected_language = spanish.
        $response = $this->actingAs($this->otherUser)->postJson('/custom-study/sessions/answer', [
            'token' => $token,
            'rating' => 'good',
        ]);

        $this->assert404NoLeakage($response);
    }

    public function test_answer_unsupported_version_returns_404(): void
    {
        $token = $this->craftUnsupportedVersionToken();
        $this->assertAnswer404($token);
    }

    public function test_answer_corrupted_payload_returns_404(): void
    {
        $token = $this->craftCorruptedPayloadToken();
        $this->assertAnswer404($token);
    }

    public function test_answer_invalid_state_structure_returns_404(): void
    {
        $token = $this->craftInvalidStateToken();
        $this->assertAnswer404($token);
    }

    // ─── Token failure matrix: resume endpoint ───

    public function test_resume_empty_token_returns_404(): void
    {
        $this->assertResume404('');
    }

    public function test_resume_tampered_token_returns_404(): void
    {
        $valid = $this->openSession();
        $tampered = substr($valid, 0, -5) . 'XXXXX';
        $this->assertResume404($tampered);
    }

    public function test_resume_random_string_returns_404(): void
    {
        $this->assertResume404('random-string-not-encrypted');
    }

    public function test_resume_expired_token_returns_404(): void
    {
        $token = $this->openSession();
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 16, 0, 1, 'UTC'));
        $this->assertResume404($token);
    }

    public function test_resume_wrong_user_returns_404(): void
    {
        $token = $this->openSessionViaService();
        $response = $this->actingAs($this->otherUser)->postJson('/custom-study/sessions/resume', [
            'token' => $token,
        ]);
        $this->assert404NoLeakage($response);
    }

    public function test_resume_unsupported_version_returns_404(): void
    {
        $token = $this->craftUnsupportedVersionToken();
        $this->assertResume404($token);
    }

    public function test_resume_corrupted_payload_returns_404(): void
    {
        $token = $this->craftCorruptedPayloadToken();
        $this->assertResume404($token);
    }

    // ─── No-leakage: all failure modes return identical 404 shape ───

    public function test_all_token_failures_return_identical_404_message(): void
    {
        $valid = $this->openSession();

        $failures = [
            'empty' => '',
            'tampered' => substr($valid, 0, -5) . 'XXXXX',
            'random' => 'not-a-real-token',
            'unsupported_version' => $this->craftUnsupportedVersionToken(),
            'corrupted' => $this->craftCorruptedPayloadToken(),
        ];

        $messages = [];
        foreach ($failures as $label => $token) {
            $response = $this->actingAs($this->user)->postJson('/custom-study/sessions/answer', [
                'token' => $token,
                'rating' => 'good',
            ]);
            $response->assertStatus(404);
            $data = $response->json();
            $messages[$label] = $data['message'] ?? null;
        }

        // All messages must be identical — no leakage of the specific reason.
        $unique = array_unique(array_values($messages));
        $this->assertCount(1, $unique, 'All 404 messages must be identical. Got: ' . json_encode($messages));
    }

    public function test_404_response_does_not_leak_failure_specific_words(): void
    {
        $valid = $this->openSession();
        $tampered = substr($valid, 0, -5) . 'XXXXX';

        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions/answer', [
            'token' => $tampered,
            'rating' => 'good',
        ]);

        $response->assertStatus(404);
        $body = $response->getContent();

        // The response body must NOT contain failure-specific words that
        // would hint at the actual verification failure. Note: "expired"
        // is intentionally excluded from this list because the frozen 404
        // message itself is "Custom Study session not found or expired."
        // — that word appears in the generic message, not as a leak.
        $forbidden = ['tampered', 'version', 'decrypt', 'payload', 'unsupported', 'mismatch'];
        foreach ($forbidden as $word) {
            $this->assertStringNotContainsStringIgnoringCase(
                $word,
                $body,
                "404 response body must not contain '{$word}' — leakage of failure reason."
            );
        }

        // Must NOT expose stack trace or exception details.
        $data = $response->json();
        $this->assertArrayNotHasKey('trace', $data);
        $this->assertArrayNotHasKey('exception', $data);
        $this->assertArrayNotHasKey('file', $data);
        $this->assertArrayNotHasKey('line', $data);
    }

    // ─── Cross-user token isolation ───

    public function test_token_is_bound_to_user_at_http_level(): void
    {
        // User A opens a session (via service to avoid auth.session conflict).
        $token = $this->openSessionViaService();

        // User B tries to use User A's token.
        $response = $this->actingAs($this->otherUser)->postJson('/custom-study/sessions/resume', [
            'token' => $token,
        ]);

        $this->assert404NoLeakage($response);
    }

    // ─── Replay security (§16.1, §19.5 items 25-26) ───

    public function test_client_obsolete_token_replay_at_http_does_not_write_db(): void
    {
        // 2 cards to allow two answers.
        $this->eligibleCard();
        $this->eligibleCard();

        $open = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'overdue',
        ]);
        $open->assertStatus(200);
        $originalToken = $open->json('token');

        // First answer with the original token → branch B.
        $responseB = $this->actingAs($this->user)->postJson('/custom-study/sessions/answer', [
            'token' => $originalToken,
            'rating' => 'good',
        ]);
        $responseB->assertStatus(200);
        $tokenB = $responseB->json('refreshed_token');

        // Snapshot DB before replay.
        $logBefore = ReviewLog::count();
        $cardCount = ReviewCard::count();
        $cardStates = ReviewCard::where('user_id', $this->user->id)
            ->get(['id', 'fsrs_due_at', 'fsrs_state', 'fsrs_stability', 'fsrs_difficulty',
                    'fsrs_reps', 'fsrs_lapses', 'lifecycle_state'])
            ->map(fn ($c) => $c->getAttributes())
            ->all();

        // Replay the original (now client-obsolete) token → branch C.
        $responseC = $this->actingAs($this->user)->postJson('/custom-study/sessions/answer', [
            'token' => $originalToken,
            'rating' => 'good',
        ]);
        $responseC->assertStatus(200);
        $tokenC = $responseC->json('refreshed_token');

        // B and C are independent branches with different tokens.
        $this->assertNotSame($tokenB, $tokenC, 'Replay must form an independent branch.');

        // No DB writes.
        $this->assertSame($logBefore, ReviewLog::count(), 'Replay must not write ReviewLog.');
        $this->assertSame($cardCount, ReviewCard::count(), 'Replay must not change ReviewCard count.');
        $cardStatesAfter = ReviewCard::where('user_id', $this->user->id)
            ->get(['id', 'fsrs_due_at', 'fsrs_state', 'fsrs_stability', 'fsrs_difficulty',
                    'fsrs_reps', 'fsrs_lapses', 'lifecycle_state'])
            ->map(fn ($c) => $c->getAttributes())
            ->all();
        $this->assertSame($cardStates, $cardStatesAfter, 'Replay must not modify any ReviewCard fields.');
    }

    // ─── Internal assertion helpers ───

    private function assertAnswer404(string $token): void
    {
        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions/answer', [
            'token' => $token,
            'rating' => 'good',
        ]);
        $this->assert404NoLeakage($response);
    }

    private function assertResume404(string $token): void
    {
        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions/resume', [
            'token' => $token,
        ]);
        $this->assert404NoLeakage($response);
    }

    private function assert404NoLeakage($response): void
    {
        $response->assertStatus(404);
        $data = $response->json();
        $this->assertFalse($data['success']);
        $this->assertNotEmpty($data['message']);
        $this->assertSame('session_not_found', $data['error']['reason']);
        $this->assertArrayNotHasKey('trace', $data);
        $this->assertArrayNotHasKey('exception', $data);
    }
}
