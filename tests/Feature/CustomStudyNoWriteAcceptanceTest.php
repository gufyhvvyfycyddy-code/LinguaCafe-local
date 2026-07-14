<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Models\EncounteredWord;
use App\Services\CustomStudy\CustomStudySessionService;
use App\Services\ReviewQueueOrderOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 2000-22 — Phase 4B No-Write acceptance tests (§21).
 *
 * Custom Study 1A is preview-only: openSession / answer / resume must NOT
 * write to the database. This test suite verifies that every preview path
 * leaves ALL of the following completely unchanged:
 *
 *   - review_logs row count
 *   - review_cards core fields (fsrs_due_at, fsrs_state, fsrs_stability,
 *     fsrs_difficulty, fsrs_reps, fsrs_lapses, lifecycle_state,
 *     fsrs_enabled, buried_until, lifecycle_version, lifecycle_changed_at)
 *     — Note: the spec listed suspended_at / archived_at, but the actual
 *     schema (migration 2026_07_12_000001) represents suspended/archived
 *     as values of lifecycle_state, not as separate timestamp columns.
 *     The four lifecycle columns together fully cover the state machine.
 *   - word_senses row count and status
 *   - word_sense_occurrences row count
 *   - EncounteredWord rows
 *   - settings (queue order + global)
 *
 * The test snapshots all relevant rows BEFORE the call, runs the call,
 * then snapshots AFTER and asserts the two snapshots are identical.
 */
class CustomStudyNoWriteAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $language = 'english';
    private CustomStudySessionService $service;
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));
        $this->now = Carbon::now();

        $this->user = User::forceCreate([
            'name' => 'NoWrite User',
            'email' => 'nowrite-' . Str::uuid() . '@example.com',
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
        parent::tearDown();
    }

    // ─── Helpers ───

    private function createSense(): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => $this->language,
            'language_id' => $this->language,
            'lemma' => 'nw' . Str::random(6),
            'surface_form' => 'nw',
            'pos' => 'noun',
            'sense_zh' => '词',
            'sense_en' => 'word',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'A word.',
            'example_sentence_zh' => '一个词。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower($this->language . '|' . Str::random(10) . '|noun|词|word')),
            'source_chapter_id' => null,
        ]);
    }

    private function createCard(WordSense $sense): ReviewCard
    {
        return ReviewCard::forceCreate([
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
        ]);
    }

    private function eligibleCard(): ReviewCard
    {
        return $this->createCard($this->createSense());
    }

    /**
     * Snapshot all DB state that must remain unchanged across a preview
     * session operation. Returns an array of comparable values.
     */
    private function snapshotDbState(): array
    {
        return [
            'review_logs_count' => ReviewLog::count(),
            'review_cards' => ReviewCard::where('user_id', $this->user->id)
                ->where('language', $this->language)
                ->get([
                    'id', 'fsrs_due_at', 'fsrs_state', 'fsrs_stability',
                    'fsrs_difficulty', 'fsrs_reps', 'fsrs_lapses',
                    'lifecycle_state', 'fsrs_enabled', 'buried_until',
                    'lifecycle_version', 'lifecycle_changed_at',
                ])
                ->map(fn ($c) => $c->getAttributes())
                ->all(),
            'word_senses_count' => WordSense::where('user_id', $this->user->id)
                ->where('language', $this->language)->count(),
            'word_senses_status' => WordSense::where('user_id', $this->user->id)
                ->where('language', $this->language)->pluck('status', 'id')->all(),
            'word_sense_occurrences_count' => WordSenseOccurrence::count(),
            'encountered_words_count' => EncounteredWord::count(),
            'settings_count' => Setting::count(),
            'settings_user_minus_one' => Setting::where('user_id', -1)
                ->pluck('value', 'name')->all(),
        ];
    }

    private function openSession(): array
    {
        return $this->service->openSession(
            ['mode' => 'overdue'],
            $this->user->id,
            $this->language,
            $this->now,
            ReviewQueueOrderOptions::defaults()
        );
    }

    private function assertDbUnchanged(array $before, string $context): void
    {
        $after = $this->snapshotDbState();

        $this->assertSame(
            $before['review_logs_count'],
            $after['review_logs_count'],
            "review_logs row count changed during {$context}."
        );
        $this->assertSame(
            $before['review_cards'],
            $after['review_cards'],
            "review_cards core fields changed during {$context}."
        );
        $this->assertSame(
            $before['word_senses_count'],
            $after['word_senses_count'],
            "word_senses row count changed during {$context}."
        );
        $this->assertSame(
            $before['word_senses_status'],
            $after['word_senses_status'],
            "word_senses status changed during {$context}."
        );
        $this->assertSame(
            $before['word_sense_occurrences_count'],
            $after['word_sense_occurrences_count'],
            "word_sense_occurrences row count changed during {$context}."
        );
        $this->assertSame(
            $before['encountered_words_count'],
            $after['encountered_words_count'],
            "EncounteredWord row count changed during {$context}."
        );
        $this->assertSame(
            $before['settings_count'],
            $after['settings_count'],
            "settings row count changed during {$context}."
        );
        $this->assertSame(
            $before['settings_user_minus_one'],
            $after['settings_user_minus_one'],
            "settings (user_id=-1) values changed during {$context}."
        );
    }

    // ─── openSession ───

    public function test_open_session_does_not_write(): void
    {
        $this->eligibleCard();
        $before = $this->snapshotDbState();

        $this->openSession();

        $this->assertDbUnchanged($before, 'openSession');
    }

    public function test_open_session_with_268_candidates_does_not_write(): void
    {
        for ($i = 0; $i < 268; $i++) {
            $this->eligibleCard();
        }
        $before = $this->snapshotDbState();

        $this->service->openSession(
            ['mode' => 'overdue', 'card_limit' => 100],
            $this->user->id,
            $this->language,
            $this->now,
            ReviewQueueOrderOptions::defaults()
        );

        $this->assertDbUnchanged($before, 'openSession(268 candidates, limit 100)');
    }

    // ─── answer ───

    public function test_answer_does_not_write(): void
    {
        $this->eligibleCard();
        $opened = $this->openSession();

        $before = $this->snapshotDbState();

        $this->service->answer(
            $opened['token'],
            'good',
            $this->user->id,
            $this->language,
            $this->now
        );

        $this->assertDbUnchanged($before, 'answer(good)');
    }

    public function test_answer_all_four_ratings_do_not_write(): void
    {
        // 4 cards so we can answer all four ratings without depleting the queue.
        for ($i = 0; $i < 4; $i++) {
            $this->eligibleCard();
        }

        $opened = $this->openSession();
        $token = $opened['token'];

        foreach (['again', 'hard', 'good', 'easy'] as $rating) {
            $before = $this->snapshotDbState();
            $result = $this->service->answer(
                $token,
                $rating,
                $this->user->id,
                $this->language,
                $this->now
            );
            $this->assertDbUnchanged($before, "answer({$rating})");
            $token = $result['refreshed_token'];
            if ($result['completed']) {
                break;
            }
        }
    }

    // ─── resume ───

    public function test_resume_does_not_write(): void
    {
        $this->eligibleCard();
        $opened = $this->openSession();

        $before = $this->snapshotDbState();

        $this->service->resume(
            $opened['token'],
            $this->user->id,
            $this->language,
            $this->now
        );

        $this->assertDbUnchanged($before, 'resume');
    }

    public function test_resume_after_answer_does_not_write(): void
    {
        // 2 cards so we can answer then resume.
        $this->eligibleCard();
        $this->eligibleCard();
        $opened = $this->openSession();
        $answered = $this->service->answer(
            $opened['token'],
            'good',
            $this->user->id,
            $this->language,
            $this->now
        );

        $before = $this->snapshotDbState();

        $this->service->resume(
            $answered['refreshed_token'],
            $this->user->id,
            $this->language,
            $this->now
        );

        $this->assertDbUnchanged($before, 'resume(after answer)');
    }

    // ─── Comprehensive: open → answer → resume ───

    public function test_full_open_answer_resume_cycle_does_not_write(): void
    {
        // 3 cards to allow a full cycle without completing.
        $this->eligibleCard();
        $this->eligibleCard();
        $this->eligibleCard();

        $before = $this->snapshotDbState();

        $opened = $this->openSession();
        $this->assertDbUnchanged($before, 'openSession (in cycle)');

        $answered = $this->service->answer(
            $opened['token'],
            'hard',
            $this->user->id,
            $this->language,
            $this->now
        );
        $this->assertDbUnchanged($before, 'answer (in cycle)');

        $resumed = $this->service->resume(
            $answered['refreshed_token'],
            $this->user->id,
            $this->language,
            $this->now
        );
        $this->assertDbUnchanged($before, 'resume (in cycle)');

        // One more answer to verify multi-step cycles are safe.
        $this->service->answer(
            $resumed['refreshed_token'],
            'good',
            $this->user->id,
            $this->language,
            $this->now
        );
        $this->assertDbUnchanged($before, 'answer 2 (in cycle)');
    }

    // ─── Eligibility race: skipping ineligible cards does not write ───

    public function test_skipping_ineligible_cards_does_not_write(): void
    {
        $card1 = $this->eligibleCard();
        $card2 = $this->eligibleCard();

        $opened = $this->openSession();

        // Suspend the current card — service must skip without writing.
        ReviewCard::where('id', $opened['current_card']['review_card_id'])->update([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);

        $before = $this->snapshotDbState();

        $this->service->resume(
            $opened['token'],
            $this->user->id,
            $this->language,
            $this->now
        );

        $this->assertDbUnchanged($before, 'resume(skipping ineligible)');
    }
}
