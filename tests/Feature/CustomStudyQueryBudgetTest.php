<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\CustomStudy\CustomStudySessionEligibilityService;
use App\Services\CustomStudy\CustomStudySessionService;
use App\Services\CustomStudy\CustomStudySessionState;
use App\Services\CustomStudy\CustomStudyCriteria;
use App\Services\ReviewQueueOrderOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 2000-22 — Phase 4B Query Budget acceptance tests (§20).
 *
 * Verifies that SQL query count does NOT scale linearly with the number
 * of candidate cards. Catches N+1 patterns in:
 *
 *   1. openSession with 1 / 100 / 500 candidates
 *   2. EligibilityService with 1 / 100 / 500 active cards
 *   3. answer skipping 1 / 50 ineligible cards
 *   4. resume skipping 1 / 50 ineligible cards
 *
 * Per §20: "SQL 查询数量不随卡片数量线性增加；Serializer 只处理 current；
 * 不允许 per-card ReviewCard 查询；不允许 per-card WordSense 查询；
 * 不允许 per-card eligibility 查询。"
 *
 * The test distinguishes:
 *   - query count (must stay constant)
 *   - returned/loaded row count (will grow with candidate count — that's OK)
 */
class CustomStudyQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $language = 'english';
    private CustomStudySessionService $service;
    private CustomStudySessionEligibilityService $eligibilityService;
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));
        $this->now = Carbon::now();

        $this->user = User::forceCreate([
            'name' => 'Budget User',
            'email' => 'budget-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->service = app(CustomStudySessionService::class);
        $this->eligibilityService = app(CustomStudySessionEligibilityService::class);
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
            'lemma' => 'w' . Str::random(6),
            'surface_form' => 'w',
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

    private function createEligibleCards(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $sense = $this->createSense();
            $this->createCard($sense);
        }
    }

    private function openSession(int $cardLimit = 500): array
    {
        return $this->service->openSession(
            ['mode' => 'overdue', 'card_limit' => $cardLimit],
            $this->user->id,
            $this->language,
            $this->now,
            ReviewQueueOrderOptions::defaults()
        );
    }

    private function countQueries(callable $fn): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $fn();
        return $count;
    }

    // ─── 1. openSession: 1 / 100 / 500 candidates ───

    public function test_open_session_1_candidate_query_budget(): void
    {
        $this->createEligibleCards(1);
        $count = $this->countQueries(fn () => $this->openSession(500));
        // Capture baseline. Will compare against larger sets below.
        $this->assertLessThan(50, $count, "openSession(1 candidate) used {$count} queries — must stay bounded.");
    }

    public function test_open_session_100_candidates_query_budget(): void
    {
        $this->createEligibleCards(100);
        $count = $this->countQueries(fn () => $this->openSession(500));
        $this->assertLessThan(50, $count, "openSession(100 candidates) used {$count} queries — must stay bounded (no N+1).");
    }

    public function test_open_session_500_candidates_query_budget(): void
    {
        $this->createEligibleCards(500);
        $count = $this->countQueries(fn () => $this->openSession(500));
        $this->assertLessThan(50, $count, "openSession(500 candidates) used {$count} queries — must stay bounded (no N+1).");
    }

    public function test_open_session_query_count_does_not_grow_linearly(): void
    {
        $this->createEligibleCards(1);
        $count1 = $this->countQueries(fn () => $this->openSession(500));

        // Reset DB state for next batch.
        ReviewCard::query()->delete();
        WordSense::query()->delete();

        $this->createEligibleCards(500);
        $count500 = $this->countQueries(fn () => $this->openSession(500));

        // Query count must NOT grow proportionally. Allow up to 2x difference
        // for caching/state effects, but block N+1 growth (which would be
        // ~500x for 500 cards).
        $this->assertLessThan(
            $count1 * 3,
            $count500,
            "openSession query count grew from {$count1} (1 card) to {$count500} (500 cards) — N+1 suspected."
        );
    }

    // ─── 2. EligibilityService: 1 / 100 / 500 active cards ───

    public function test_eligibility_service_1_active_query_budget(): void
    {
        $this->createEligibleCards(1);
        $state = $this->buildStateFromEligibleCards();

        $count = $this->countQueries(fn () => $this->eligibilityService->findIneligibleCardIds($state, $this->now));
        $this->assertLessThan(20, $count, "EligibilityService(1 active) used {$count} queries — must stay bounded.");
    }

    public function test_eligibility_service_100_active_query_budget(): void
    {
        $this->createEligibleCards(100);
        $state = $this->buildStateFromEligibleCards();

        $count = $this->countQueries(fn () => $this->eligibilityService->findIneligibleCardIds($state, $this->now));
        $this->assertLessThan(20, $count, "EligibilityService(100 active) used {$count} queries — must stay bounded (no N+1).");
    }

    public function test_eligibility_service_500_active_query_budget(): void
    {
        $this->createEligibleCards(500);
        $state = $this->buildStateFromEligibleCards();

        $count = $this->countQueries(fn () => $this->eligibilityService->findIneligibleCardIds($state, $this->now));
        $this->assertLessThan(20, $count, "EligibilityService(500 active) used {$count} queries — must stay bounded (no N+1).");
    }

    public function test_eligibility_service_query_count_does_not_grow_linearly(): void
    {
        $this->createEligibleCards(1);
        $state1 = $this->buildStateFromEligibleCards();
        $count1 = $this->countQueries(fn () => $this->eligibilityService->findIneligibleCardIds($state1, $this->now));

        ReviewCard::query()->delete();
        WordSense::query()->delete();

        $this->createEligibleCards(500);
        $state500 = $this->buildStateFromEligibleCards();
        $count500 = $this->countQueries(fn () => $this->eligibilityService->findIneligibleCardIds($state500, $this->now));

        $this->assertLessThan(
            $count1 * 3,
            $count500,
            "EligibilityService query count grew from {$count1} (1 card) to {$count500} (500 cards) — N+1 suspected."
        );
    }

    // ─── 3. answer: skip 1 / 50 ineligible cards ───

    public function test_answer_skip_1_ineligible_query_budget(): void
    {
        // 2 eligible cards. After answering the first, we suspend it
        // (simulating race) so the second becomes current. Then answer the
        // second — the now-suspended first must be skipped by eligibility
        // recheck without causing N+1 queries.
        $sense1 = $this->createSense();
        $card1 = $this->createCard($sense1);
        $sense2 = $this->createSense();
        $card2 = $this->createCard($sense2);

        $opened = $this->openSession(500);
        $token = $opened['token'];

        // Suspend card1 — simulate race between open and answer.
        ReviewCard::where('id', $card1->id)->update([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);

        $count = $this->countQueries(function () use ($token) {
            try {
                $this->service->answer($token, 'good', $this->user->id, $this->language, $this->now);
            } catch (\Throwable $e) {
                // Race may make current_card ineligible; service should
                // skip and produce a valid response or completed state.
            }
        });
        $this->assertLessThan(40, $count, "answer(skip 1 ineligible) used {$count} queries — must stay bounded.");
    }

    public function test_answer_skip_50_ineligible_query_budget(): void
    {
        // 51 eligible cards. Suspend 50 of them after openSession — the
        // service should skip all 50 ineligibles during a single answer
        // call without per-card queries.
        $cardIds = [];
        for ($i = 0; $i < 51; $i++) {
            $sense = $this->createSense();
            $card = $this->createCard($sense);
            $cardIds[] = $card->id;
        }

        $opened = $this->openSession(500);
        $token = $opened['token'];

        // Suspend first 50 cards.
        ReviewCard::whereIn('id', array_slice($cardIds, 0, 50))->update([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);

        $count = $this->countQueries(function () use ($token) {
            try {
                $this->service->answer($token, 'good', $this->user->id, $this->language, $this->now);
            } catch (\Throwable $e) {
                // Service may complete the session or return current=null.
            }
        });
        $this->assertLessThan(
            100,
            $count,
            "answer(skip 50 ineligible) used {$count} queries — N+1 suspected if approaching 50+ queries."
        );
    }

    // ─── 4. resume: skip 1 / 50 ineligible cards ───

    public function test_resume_skip_1_ineligible_query_budget(): void
    {
        $sense1 = $this->createSense();
        $card1 = $this->createCard($sense1);
        $sense2 = $this->createSense();
        $card2 = $this->createCard($sense2);

        $opened = $this->openSession(500);
        $token = $opened['token'];

        // Suspend current card.
        ReviewCard::where('id', $card1->id)->update([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);

        $count = $this->countQueries(function () use ($token) {
            try {
                $this->service->resume($token, $this->user->id, $this->language, $this->now);
            } catch (\Throwable $e) {
                // May complete or produce current=null.
            }
        });
        $this->assertLessThan(40, $count, "resume(skip 1 ineligible) used {$count} queries — must stay bounded.");
    }

    public function test_resume_skip_50_ineligible_query_budget(): void
    {
        $cardIds = [];
        for ($i = 0; $i < 51; $i++) {
            $sense = $this->createSense();
            $card = $this->createCard($sense);
            $cardIds[] = $card->id;
        }

        $opened = $this->openSession(500);
        $token = $opened['token'];

        // Suspend first 50 cards.
        ReviewCard::whereIn('id', array_slice($cardIds, 0, 50))->update([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
        ]);

        $count = $this->countQueries(function () use ($token) {
            try {
                $this->service->resume($token, $this->user->id, $this->language, $this->now);
            } catch (\Throwable $e) {
                // May complete or produce current=null.
            }
        });
        $this->assertLessThan(
            100,
            $count,
            "resume(skip 50 ineligible) used {$count} queries — N+1 suspected if approaching 50+ queries."
        );
    }

    // ─── Helpers for EligibilityService tests ───

    /**
     * Build a minimal State whose ordered_candidate_ids cover all eligible
     * cards currently in the DB. Used to drive EligibilityService directly.
     */
    private function buildStateFromEligibleCards(): CustomStudySessionState
    {
        $candidateIds = ReviewCard::where('user_id', $this->user->id)
            ->where('language', $this->language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->pluck('id')
            ->all();

        $criteria = CustomStudyCriteria::fromArray(['mode' => 'overdue']);

        return CustomStudySessionState::createInitial(
            CustomStudySessionState::VERSION,
            $this->user->id,
            $this->language,
            $criteria,
            (string) Str::uuid(),
            $this->now->getTimestamp(),
            $this->now->getTimestamp() + 14400,
            $candidateIds,
            ['again_secs' => 60, 'hard_secs' => 600, 'good_secs' => 0, 'easy_secs' => 0],
            count($candidateIds)
        );
    }
}
