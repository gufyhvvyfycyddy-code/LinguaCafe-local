<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\CustomStudy\Queries\LeechAttentionQuery;
use App\Services\SenseReviewLeechPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CustomStudyLeechAttentionQueryTest — Task 2000-18 / Phase 2B (CS-6)
 *
 * Verifies the LeechAttentionQuery Policy-derived boundary:
 *  - leech_only returns only real leech cards (per SenseReviewLeechPolicy).
 *  - leech_plus_struggling returns leech + struggling cards.
 *  - stable cards never leak.
 *  - Undone / reset ReviewLogs excluded (via real feedback aggregation).
 *  - Cross-user / cross-language ReviewLogs do not affect classification.
 *  - Suspended / archived / future-buried leech cards excluded (via
 *    scopeSenseReviewEligible) — even though they remain diagnosable in
 *    the management page, they cannot enter a Custom Study session.
 *  - Expired buried leech cards CAN be included.
 *  - fsrs_enabled=false excluded.
 *  - AI-suggested / rejected WordSense excluded.
 *  - Legacy word card excluded.
 *  - Empty candidate set returns empty array.
 *  - IDs unique.
 *  - Query budget: 1 eligible-card query + 1 batch feedback query,
 *    no re-query of ReviewCard, no N+1.
 *  - Does NOT write ReviewLog / ReviewCard / WordSense.
 *  - Does NOT modify SenseReviewLeechPolicy / QueryService / Feedback.
 */
class CustomStudyLeechAttentionQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private string $language = 'english';
    private LeechAttentionQuery $query;
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
            'name' => 'Leech User',
            'email' => 'leech-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Leech Other',
            'email' => 'leech-other-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->query = app(LeechAttentionQuery::class);
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
            'fsrs_due_at' => Carbon::now()->subMinutes(5),
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

    /**
     * Create a ReviewLog row.
     *
     * @param array{rating:string,daysAgo:int,source?:string,undone?:bool} $ratings
     */
    private function createLog(ReviewCard $card, string $rating, int $daysAgo, array $overrides = []): ReviewLog
    {
        $defaults = [
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => Carbon::now()->subDays($daysAgo),
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => Carbon::now()->subDays($daysAgo + 1),
            'new_due_at' => Carbon::now()->subDays(max($daysAgo - 1, 0)),
            'previous_stability' => 1.0,
            'new_stability' => 1.5,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 5.0,
            'source' => $rating === 'reset' ? 'reset' : 'sense_review',
            'undone_at' => null,
        ];
        return ReviewLog::forceCreate(array_merge($defaults, $overrides));
    }

    /**
     * Build a real LEECH card per SenseReviewLeechPolicy.
     *
     * Leech rule: again_count >= 3 AND total_reviews >= 5
     * Fixture: 3 again + 2 good = 5 total → leech.
     */
    private function createLeechCard(): ReviewCard
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $this->createLog($card, 'again', 10);
        $this->createLog($card, 'again', 8);
        $this->createLog($card, 'again', 6);
        $this->createLog($card, 'good', 4);
        $this->createLog($card, 'good', 2);
        return $card;
    }

    /**
     * Build a real STRUGGLING card (not leech) per SenseReviewLeechPolicy.
     *
     * Struggling rule: last 5 reviews (again+hard) >= 3
     * Leech rule fails: again_count=2, total=5 → not leech.
     * Fixture: again,again,hard,good,easy → last5=3, last7=3 → struggling.
     */
    private function createStrugglingCard(): ReviewCard
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $this->createLog($card, 'again', 10);
        $this->createLog($card, 'again', 8);
        $this->createLog($card, 'hard', 6);
        $this->createLog($card, 'good', 4);
        $this->createLog($card, 'easy', 2);
        return $card;
    }

    /**
     * Build a STABLE card (no leech / struggling triggers).
     */
    private function createStableCard(): ReviewCard
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $this->createLog($card, 'good', 5);
        $this->createLog($card, 'easy', 3);
        return $card;
    }

    private function callLeechOnly(): array
    {
        return $this->query->candidateIds(
            $this->user->id,
            $this->language,
            'leech_only',
            $this->now
        );
    }

    private function callPlus(): array
    {
        return $this->query->candidateIds(
            $this->user->id,
            $this->language,
            'leech_plus_struggling',
            $this->now
        );
    }

    // ─── 1-3. leech_only ───

    public function test_leech_only_includes_real_leech(): void
    {
        $leech = $this->createLeechCard();
        $ids = $this->callLeechOnly();
        $this->assertContains($leech->id, $ids);
    }

    public function test_leech_only_excludes_struggling(): void
    {
        $struggling = $this->createStrugglingCard();
        $ids = $this->callLeechOnly();
        $this->assertNotContains($struggling->id, $ids);
    }

    public function test_leech_only_excludes_stable(): void
    {
        $stable = $this->createStableCard();
        $ids = $this->callLeechOnly();
        $this->assertNotContains($stable->id, $ids);
    }

    // ─── 4-6. leech_plus_struggling ───

    public function test_leech_plus_struggling_includes_leech(): void
    {
        $leech = $this->createLeechCard();
        $ids = $this->callPlus();
        $this->assertContains($leech->id, $ids);
    }

    public function test_leech_plus_struggling_includes_struggling(): void
    {
        $struggling = $this->createStrugglingCard();
        $ids = $this->callPlus();
        $this->assertContains($struggling->id, $ids);
    }

    public function test_leech_plus_struggling_excludes_stable(): void
    {
        $stable = $this->createStableCard();
        $ids = $this->callPlus();
        $this->assertNotContains($stable->id, $ids);
    }

    // ─── 7-8. Undone / reset ReviewLogs ───

    public function test_undone_review_logs_not_counted(): void
    {
        $leech = $this->createLeechCard();
        // Mark ALL logs undone → card becomes stable → must NOT appear.
        ReviewLog::where('review_card_id', $leech->id)->update([
            'undone_at' => Carbon::now(),
        ]);

        $ids = $this->callLeechOnly();
        $this->assertNotContains($leech->id, $ids, 'Undone logs must not classify card as leech.');
    }

    public function test_reset_review_logs_not_counted(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        // Reset logs only — these are excluded by the analytics layer.
        $this->createLog($card, 'reset', 10, ['source' => 'reset']);
        $this->createLog($card, 'reset', 8, ['source' => 'reset']);
        $this->createLog($card, 'reset', 6, ['source' => 'reset']);
        $this->createLog($card, 'reset', 4, ['source' => 'reset']);
        $this->createLog($card, 'reset', 2, ['source' => 'reset']);

        $ids = $this->callLeechOnly();
        $this->assertNotContains($card->id, $ids, 'Reset logs must not classify card as leech.');
    }

    // ─── 9-10. Cross-user / cross-language ReviewLogs ───

    public function test_other_users_review_logs_do_not_affect_classification(): void
    {
        $mySense = $this->createSense();
        $myCard = $this->createCard($mySense);
        // My card has NO logs → stable.
        // Other user has their own card with leech-triggering logs.
        $otherSense = $this->createSense(['user_id' => $this->otherUser->id]);
        $otherCard = $this->createCard($otherSense, ['user_id' => $this->otherUser->id]);
        $this->createLog($otherCard, 'again', 10, ['user_id' => $this->otherUser->id]);
        $this->createLog($otherCard, 'again', 8, ['user_id' => $this->otherUser->id]);
        $this->createLog($otherCard, 'again', 6, ['user_id' => $this->otherUser->id]);
        $this->createLog($otherCard, 'good', 4, ['user_id' => $this->otherUser->id]);
        $this->createLog($otherCard, 'good', 2, ['user_id' => $this->otherUser->id]);

        $ids = $this->callLeechOnly();
        $this->assertNotContains($myCard->id, $ids, 'My stable card must not inherit other user\'s logs.');
        $this->assertNotContains($otherCard->id, $ids, 'Other user\'s leech card must not appear in my query.');
    }

    public function test_other_languages_review_logs_do_not_affect_classification(): void
    {
        $mySense = $this->createSense();
        $myCard = $this->createCard($mySense);
        // Same user, different language — must not leak.
        $jpSense = $this->createSense(['language' => 'japanese', 'language_id' => 'japanese']);
        $jpCard = $this->createCard($jpSense, [
            'language' => 'japanese',
            'language_id' => 'japanese',
        ]);
        $this->createLog($jpCard, 'again', 10, ['language' => 'japanese', 'language_id' => 'japanese']);
        $this->createLog($jpCard, 'again', 8, ['language' => 'japanese', 'language_id' => 'japanese']);
        $this->createLog($jpCard, 'again', 6, ['language' => 'japanese', 'language_id' => 'japanese']);
        $this->createLog($jpCard, 'good', 4, ['language' => 'japanese', 'language_id' => 'japanese']);
        $this->createLog($jpCard, 'good', 2, ['language' => 'japanese', 'language_id' => 'japanese']);

        $ids = $this->callLeechOnly();
        $this->assertNotContains($myCard->id, $ids);
        $this->assertNotContains($jpCard->id, $ids, 'Other-language leech card must not appear in english query.');
    }

    // ─── 11-14. Lifecycle exclusion ───

    public function test_suspended_leech_not_included(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED]);
        $this->createLog($card, 'again', 10);
        $this->createLog($card, 'again', 8);
        $this->createLog($card, 'again', 6);
        $this->createLog($card, 'good', 4);
        $this->createLog($card, 'good', 2);

        $ids = $this->callLeechOnly();
        $this->assertNotContains($card->id, $ids, 'Suspended leech must not enter Custom Study session.');
    }

    public function test_archived_leech_not_included(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED]);
        $this->createLog($card, 'again', 10);
        $this->createLog($card, 'again', 8);
        $this->createLog($card, 'again', 6);
        $this->createLog($card, 'good', 4);
        $this->createLog($card, 'good', 2);

        $ids = $this->callLeechOnly();
        $this->assertNotContains($card->id, $ids, 'Archived leech must not enter Custom Study session.');
    }

    public function test_future_buried_leech_not_included(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_BURIED,
            'buried_until' => Carbon::now()->addDays(2),
        ]);
        $this->createLog($card, 'again', 10);
        $this->createLog($card, 'again', 8);
        $this->createLog($card, 'again', 6);
        $this->createLog($card, 'good', 4);
        $this->createLog($card, 'good', 2);

        $ids = $this->callLeechOnly();
        $this->assertNotContains($card->id, $ids, 'Future-buried leech must not enter Custom Study session.');
    }

    public function test_expired_buried_leech_can_be_included(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_BURIED,
            'buried_until' => Carbon::now()->subMinutes(10),
        ]);
        $this->createLog($card, 'again', 10);
        $this->createLog($card, 'again', 8);
        $this->createLog($card, 'again', 6);
        $this->createLog($card, 'good', 4);
        $this->createLog($card, 'good', 2);

        $ids = $this->callLeechOnly();
        $this->assertContains($card->id, $ids, 'Expired-buried leech must be eligible for Custom Study session.');
    }

    // ─── 15-17. WordSense / card type / fsrs_enabled ───

    public function test_fsrs_disabled_leech_not_included(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['fsrs_enabled' => false]);
        $this->createLog($card, 'again', 10);
        $this->createLog($card, 'again', 8);
        $this->createLog($card, 'again', 6);
        $this->createLog($card, 'good', 4);
        $this->createLog($card, 'good', 2);

        $ids = $this->callLeechOnly();
        $this->assertNotContains($card->id, $ids, 'fsrs_enabled=false card must not be eligible.');
    }

    public function test_ai_suggested_and_rejected_senses_not_included(): void
    {
        $aiSense = $this->createSense(['status' => WordSense::STATUS_AI_SUGGESTED]);
        $aiCard = $this->createCard($aiSense);
        $this->createLog($aiCard, 'again', 10);
        $this->createLog($aiCard, 'again', 8);
        $this->createLog($aiCard, 'again', 6);
        $this->createLog($aiCard, 'good', 4);
        $this->createLog($aiCard, 'good', 2);

        $rejectedSense = $this->createSense(['status' => WordSense::STATUS_REJECTED]);
        $rejectedCard = $this->createCard($rejectedSense);
        $this->createLog($rejectedCard, 'again', 10);
        $this->createLog($rejectedCard, 'again', 8);
        $this->createLog($rejectedCard, 'again', 6);
        $this->createLog($rejectedCard, 'good', 4);
        $this->createLog($rejectedCard, 'good', 2);

        $ids = $this->callLeechOnly();
        $this->assertNotContains($aiCard->id, $ids, 'AI-suggested sense card must not appear.');
        $this->assertNotContains($rejectedCard->id, $ids, 'Rejected sense card must not appear.');
    }

    public function test_legacy_word_card_not_included(): void
    {
        // Create a WordSense (so WordSense table is populated) but bind the
        // card to an EncounteredWord target_type=word — not sense.
        $sense = $this->createSense();
        $wordCard = ReviewCard::forceCreate([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $sense->id, // any positive int; target_type=word is what matters
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subMinutes(5),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);
        $this->createLog($wordCard, 'again', 10);
        $this->createLog($wordCard, 'again', 8);
        $this->createLog($wordCard, 'again', 6);
        $this->createLog($wordCard, 'good', 4);
        $this->createLog($wordCard, 'good', 2);

        $ids = $this->callLeechOnly();
        $this->assertNotContains($wordCard->id, $ids, 'Legacy word card must not appear in sense-only query.');
    }

    // ─── 18-19. Empty result / unique IDs ───

    public function test_empty_candidate_returns_empty_array(): void
    {
        // No cards at all.
        $this->assertSame([], $this->callLeechOnly());
        $this->assertSame([], $this->callPlus());
    }

    public function test_ids_are_unique(): void
    {
        $leech1 = $this->createLeechCard();
        $leech2 = $this->createLeechCard();
        $struggling = $this->createStrugglingCard();

        $ids = $this->callPlus();
        $this->assertContains($leech1->id, $ids);
        $this->assertContains($leech2->id, $ids);
        $this->assertContains($struggling->id, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)), 'IDs must be unique.');
    }

    // ─── 20-23. Query budget ───

    public function test_single_eligible_card_query_no_n_plus_1(): void
    {
        // Build several eligible cards (mix of leech / stable) to ensure
        // the eligible-card query terminates in exactly 1 SQL.
        $this->createLeechCard();
        $this->createStableCard();
        $this->createStrugglingCard();

        DB::enableQueryLog();
        $this->callLeechOnly();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // We expect:
        //  - 1 eligible-card SELECT (the main confirmedSenseCardQuery + eligible scope + get())
        //  - 1 batch ReviewLog query (inside buildForCards)
        //  - Possibly 1 timezone-config query is NOT expected (timezone is config-driven).
        // We assert at most 2 SELECTs hit review_cards / review_logs.
        $cardQueries = array_filter($queries, fn ($q) => stripos($q['query'], 'select') !== false
            && (stripos($q['query'], '`review_cards`') !== false || stripos($q['query'], 'review_cards') !== false));
        $logQueries = array_filter($queries, fn ($q) => stripos($q['query'], 'select') !== false
            && stripos($q['query'], 'review_logs') !== false);

        $this->assertLessThanOrEqual(1, count($cardQueries), 'Must issue at most 1 SELECT on review_cards.');
        $this->assertGreaterThanOrEqual(1, count($cardQueries), 'Must issue the eligible-card SELECT.');
        $this->assertLessThanOrEqual(1, count($logQueries), 'Must issue at most 1 batch ReviewLog SELECT.');
    }

    public function test_does_not_re_query_review_card(): void
    {
        // With preloaded cards, describeForCards must NOT issue a second
        // review_cards SELECT.
        $this->createLeechCard();
        $this->createStableCard();

        DB::enableQueryLog();
        $this->callLeechOnly();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $cardSelects = 0;
        foreach ($queries as $q) {
            if (stripos($q['query'], 'select') !== false
                && stripos($q['query'], 'review_cards') !== false
                && stripos($q['query'], 'review_logs') === false) {
                $cardSelects++;
            }
        }
        $this->assertSame(1, $cardSelects, 'Must issue exactly 1 review_cards SELECT (no re-query).');
    }

    public function test_no_n_plus_1_with_many_cards(): void
    {
        // 5 leech + 5 stable cards. Whatever the count, the eligible-card
        // SELECT is 1 and the ReviewLog batch is 1 — no per-card queries.
        for ($i = 0; $i < 5; $i++) {
            $this->createLeechCard();
            $this->createStableCard();
        }

        DB::enableQueryLog();
        $this->callLeechOnly();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $totalSelects = count(array_filter($queries, fn ($q) => stripos($q['query'], 'select') === 0));
        // 1 review_cards + 1 review_logs = 2 selects max (config() reads
        // do not hit DB). We allow a couple extra in case Laravel issues
        // internal introspection, but no per-card N+1.
        $this->assertLessThanOrEqual(4, $totalSelects, 'No N+1: total SELECTs must stay bounded.');
    }

    public function test_leech_only_does_not_call_summary_or_describe_for_card_single_path(): void
    {
        // Source-level guard: candidateIds() must NOT call summary() (which
        // would bypass eligibility) or the single-card describeForCard().
        $source = file_get_contents(app_path('Services/CustomStudy/Queries/LeechAttentionQuery.php'));
        $this->assertStringNotContainsString('->summary(', $source, 'Must NOT call summary() — would bypass eligibility.');
        $this->assertStringNotContainsString('->describeForCard(', $source, 'Must NOT call single-card describeForCard() — would cause N+1.');
        $this->assertStringNotContainsString('describeForCardWithFeedback', $source);
        $this->assertStringContainsString('describeForCards', $source, 'Must reuse describeForCards() with preloaded cards.');
    }

    // ─── 24-26. No writes / no modification ───

    public function test_does_not_write_review_log(): void
    {
        $this->createLeechCard();
        $before = ReviewLog::count();

        $this->callLeechOnly();

        $this->assertSame($before, ReviewLog::count(), 'Must not write ReviewLog.');
    }

    public function test_does_not_modify_review_card(): void
    {
        $leech = $this->createLeechCard();
        $stable = $this->createStableCard();
        $leechBefore = $leech->fresh()->toArray();
        $stableBefore = $stable->fresh()->toArray();

        $this->callLeechOnly();

        $leechAfter = $leech->fresh()->toArray();
        $stableAfter = $stable->fresh()->toArray();
        // Compare the relevant immutable fields (timestamps may update on
        // fresh() in some Laravel versions; compare only business fields).
        $fields = ['id', 'user_id', 'language_id', 'target_type', 'target_id',
                   'fsrs_state', 'fsrs_stability', 'fsrs_difficulty', 'fsrs_reps',
                   'fsrs_lapses', 'fsrs_enabled', 'lifecycle_state'];
        foreach ($fields as $f) {
            $this->assertSame($leechBefore[$f] ?? null, $leechAfter[$f] ?? null, "leech.{$f} must not change.");
            $this->assertSame($stableBefore[$f] ?? null, $stableAfter[$f] ?? null, "stable.{$f} must not change.");
        }
    }

    public function test_does_not_modify_word_sense(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $this->createLog($card, 'again', 10);
        $this->createLog($card, 'again', 8);
        $this->createLog($card, 'again', 6);
        $this->createLog($card, 'good', 4);
        $this->createLog($card, 'good', 2);

        $senseId = $sense->id;
        $senseBefore = WordSense::find($senseId)->toArray();

        $this->callLeechOnly();

        $senseAfter = WordSense::find($senseId)->toArray();
        $fields = ['id', 'user_id', 'language_id', 'status', 'source_chapter_id', 'lemma'];
        foreach ($fields as $f) {
            $this->assertSame($senseBefore[$f] ?? null, $senseAfter[$f] ?? null, "wordsense.{$f} must not change.");
        }
    }

    // ─── 27. Source guard: no Policy modification / threshold duplication ───

    public function test_does_not_modify_leech_policy(): void
    {
        // Source-level guard: the Query file must NOT redefine leech
        // thresholds or duplicate the Policy algorithm.
        $source = file_get_contents(app_path('Services/CustomStudy/Queries/LeechAttentionQuery.php'));
        // Forbidden: hard-coded thresholds from the Policy.
        $this->assertStringNotContainsString('>= 3 && $totalReviews >= 5', $source, 'Must not duplicate leech again_count threshold.');
        $this->assertStringNotContainsString('last7AgainHard >= 4', $source, 'Must not duplicate leech recent-window threshold.');
        $this->assertStringNotContainsString('last5AgainHard >= 3', $source, 'Must not duplicate struggling recent-window threshold.');
        $this->assertStringNotContainsString('fsrs_lapses >= 2', $source, 'Must not duplicate struggling lapses threshold.');
        // Forbidden: new classifier (re-implementing classify()).
        $this->assertStringNotContainsString('function classify(', $source, 'Must not define a second classifier.');
        // Required: must reuse the real Policy via describeForCards().
        $this->assertStringContainsString('describeForCards', $source);
        $this->assertStringContainsString(SenseReviewLeechPolicy::class, $source);
    }
}
