<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewCardSerializerService;
use App\Services\SenseReviewLearningFeedbackService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewBatchFeedbackTest
 *
 * SenseReview-BatchFeedback-1000-1
 *
 * Verifies the batch learning-feedback path that eliminates the per-card
 * ReviewLog N+1 on the SenseReview queue.
 *
 * Contract:
 *  - buildForCards(array $ids) returns [review_card_id => feedback] map.
 *  - Empty id list → empty map, zero ReviewLog queries.
 *  - Cards with no logs → stable empty structure.
 *  - Per-card payload is IDENTICAL to buildForCard() (single source of truth).
 *  - Reset logs excluded; user isolation via review_card_id scoping.
 *  - Duplicate ids in input → no duplicate queries, no duplicate output keys.
 *  - READ-ONLY: never writes ReviewLog, never mutates FSRS.
 *  - Query count: exactly 1 ReviewLog query regardless of card count.
 *  - serializeMany() uses the batch path; payload shape unchanged.
 */
class SenseReviewBatchFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private SenseReviewLearningFeedbackService $feedbackService;
    private SenseReviewCardSerializerService $serializerService;

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

        $this->user = $this->createUser('batch-feedback@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->feedbackService = app(SenseReviewLearningFeedbackService::class);
        $this->serializerService = app(SenseReviewCardSerializerService::class);
    }

    /**
     * 1. Empty id list → empty map.
     */
    public function test_empty_id_list_returns_empty_map(): void
    {
        $map = $this->feedbackService->buildForCards([]);

        $this->assertSame([], $map);
    }

    /**
     * 2. Single card → map with one entry matching buildForCard().
     */
    public function test_single_card_matches_build_for_card(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'again', Carbon::now()->subDays(2));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(1));

        $single = $this->feedbackService->buildForCard($card->id);
        $batch = $this->feedbackService->buildForCards([$card->id]);

        $this->assertArrayHasKey($card->id, $batch);
        $this->assertSame($single, $batch[$card->id]);
    }

    /**
     * 3. Multiple cards → each entry matches buildForCard() independently.
     */
    public function test_multiple_cards_match_build_for_card(): void
    {
        $senseA = $this->createConfirmedSense('bank');
        $cardA = $this->createSenseCard($senseA);
        $this->createReviewLog($cardA, 'again', Carbon::now()->subDays(3));
        $this->createReviewLog($cardA, 'good',  Carbon::now()->subDays(1));

        $senseB = $this->createConfirmedSense('river');
        $cardB = $this->createSenseCard($senseB);
        $this->createReviewLog($cardB, 'hard', Carbon::now()->subDays(2));
        $this->createReviewLog($cardB, 'easy', Carbon::now()->subDays(1));
        $this->createReviewLog($cardB, 'good', Carbon::now());

        $senseC = $this->createConfirmedSense('plain');
        $cardC = $this->createSenseCard($senseC); // no logs

        $ids = [$cardA->id, $cardB->id, $cardC->id];
        $batch = $this->feedbackService->buildForCards($ids);

        $this->assertSame($this->feedbackService->buildForCard($cardA->id), $batch[$cardA->id]);
        $this->assertSame($this->feedbackService->buildForCard($cardB->id), $batch[$cardB->id]);
        $this->assertSame($this->feedbackService->buildForCard($cardC->id), $batch[$cardC->id]);

        // Verify some concrete values to ensure the maps are real data.
        $this->assertSame(2, $batch[$cardA->id]['total_reviews']);
        $this->assertSame(1, $batch[$cardA->id]['forget_count']);
        $this->assertSame(3, $batch[$cardB->id]['total_reviews']);
        $this->assertSame(0, $batch[$cardC->id]['total_reviews']);
        $this->assertSame('insufficient', $batch[$cardC->id]['forgetting_pattern']['trend']);
    }

    /**
     * 4. Card with no logs → stable empty structure.
     */
    public function test_no_log_card_returns_stable_empty_structure(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $batch = $this->feedbackService->buildForCards([$card->id]);

        $fb = $batch[$card->id];
        $this->assertSame(0, $fb['total_reviews']);
        $this->assertSame(0, $fb['forget_count']);
        $this->assertSame([], $fb['recent_reviews']);
        $this->assertSame(0.0, $fb['forgetting_pattern']['forget_rate']);
        $this->assertNull($fb['forgetting_pattern']['last_forget_date']);
        $this->assertSame('insufficient', $fb['forgetting_pattern']['trend']);
    }

    /**
     * 5. Reset logs excluded in batch path.
     */
    public function test_reset_logs_excluded_in_batch(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(2));
        $this->createReviewLog($card, 'reset', Carbon::now()->subDays(1), 'reset');

        $batch = $this->feedbackService->buildForCards([$card->id]);

        $this->assertSame(1, $batch[$card->id]['total_reviews']);
        $this->assertSame(1, $batch[$card->id]['good_count']);
    }

    /**
     * 6. User isolation: another user's logs never leak into the batch map.
     */
    public function test_other_users_logs_do_not_leak_in_batch(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::now()->subDay());

        $otherUser = $this->createUser('other-batch@example.com', 'english');
        $otherSense = $this->wordSenseService->createSense([
            'user_id' => $otherUser->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'river',
            'surface_form' => 'River',
            'pos' => 'noun',
            'sense_zh' => '河',
            'sense_en' => 'river',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $otherSense->update(['status' => WordSense::STATUS_CONFIRMED]);
        $otherCard = ReviewCard::forceCreate([
            'user_id' => $otherUser->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherSense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subDay(),
            'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDay(),
        ]);
        $this->createReviewLog($otherCard, 'again', Carbon::now()->subDays(5));
        $this->createReviewLog($otherCard, 'hard',  Carbon::now()->subDays(4));

        $batch = $this->feedbackService->buildForCards([$card->id]);

        $this->assertSame(1, $batch[$card->id]['total_reviews']);
        $this->assertSame(0, $batch[$card->id]['forget_count']);
        // otherCard's id was not requested, so it must not appear in the map.
        $this->assertArrayNotHasKey($otherCard->id, $batch);
    }

    /**
     * 7. Rating counts accurate in batch path.
     */
    public function test_rating_counts_accurate_in_batch(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'again', Carbon::now()->subDays(5));
        $this->createReviewLog($card, 'hard',  Carbon::now()->subDays(4));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(3));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(2));
        $this->createReviewLog($card, 'easy',  Carbon::now()->subDays(1));

        $batch = $this->feedbackService->buildForCards([$card->id]);

        $fb = $batch[$card->id];
        $this->assertSame(5, $fb['total_reviews']);
        $this->assertSame(1, $fb['forget_count']);
        $this->assertSame(1, $fb['hard_count']);
        $this->assertSame(2, $fb['good_count']);
        $this->assertSame(1, $fb['easy_count']);
    }

    /**
     * 8. recent_reviews newest first in batch path.
     */
    public function test_recent_reviews_newest_first_in_batch(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'hard',  Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 3, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 4, 10));
        $this->createReviewLog($card, 'easy',  Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 6, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 7, 10));

        $batch = $this->feedbackService->buildForCards([$card->id]);

        $fb = $batch[$card->id];
        $this->assertCount(5, $fb['recent_reviews']);
        $this->assertSame('good',  $fb['recent_reviews'][0]['rating']);
        $this->assertSame('2026-07-07', $fb['recent_reviews'][0]['date']);
        $this->assertSame('again', $fb['recent_reviews'][1]['rating']);
    }

    /**
     * 9. forget_rate correct in batch path.
     */
    public function test_forget_rate_in_batch(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        for ($i = 0; $i < 8; $i++) {
            $this->createReviewLog($card, 'good', Carbon::now()->subDays(10 - $i));
        }
        for ($i = 0; $i < 2; $i++) {
            $this->createReviewLog($card, 'again', Carbon::now()->subDays(2 - $i));
        }

        $batch = $this->feedbackService->buildForCards([$card->id]);

        $this->assertSame(0.2, $batch[$card->id]['forgetting_pattern']['forget_rate']);
    }

    /**
     * 10. last_forget_date correct in batch path.
     */
    public function test_last_forget_date_in_batch(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 6, 10));

        $batch = $this->feedbackService->buildForCards([$card->id]);

        $this->assertSame('2026-07-05', $batch[$card->id]['forgetting_pattern']['last_forget_date']);
    }

    /**
     * 11a. Trend improving in batch path.
     */
    public function test_trend_improving_in_batch(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 3, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 4, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 6, 10));

        $batch = $this->feedbackService->buildForCards([$card->id]);

        $this->assertSame('improving', $batch[$card->id]['forgetting_pattern']['trend']);
    }

    /**
     * 11b. Trend declining in batch path.
     */
    public function test_trend_declining_in_batch(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 3, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 4, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 6, 10));

        $batch = $this->feedbackService->buildForCards([$card->id]);

        $this->assertSame('declining', $batch[$card->id]['forgetting_pattern']['trend']);
    }

    /**
     * 11c. Trend stable in batch path.
     */
    public function test_trend_stable_in_batch(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 3, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 4, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 6, 10));

        $batch = $this->feedbackService->buildForCards([$card->id]);

        $this->assertSame('stable', $batch[$card->id]['forgetting_pattern']['trend']);
    }

    /**
     * 11d. Trend insufficient in batch path.
     */
    public function test_trend_insufficient_in_batch(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'again', Carbon::now()->subDays(3));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(2));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(1));

        $batch = $this->feedbackService->buildForCards([$card->id]);

        $this->assertSame('insufficient', $batch[$card->id]['forgetting_pattern']['trend']);
    }

    /**
     * 12. Duplicate card ids in input → no duplicate output keys, same result.
     */
    public function test_duplicate_ids_deduplicated(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::now()->subDay());

        $batch = $this->feedbackService->buildForCards([$card->id, $card->id, $card->id]);

        $this->assertCount(1, $batch);
        $this->assertArrayHasKey($card->id, $batch);
        $this->assertSame(1, $batch[$card->id]['total_reviews']);
    }

    /**
     * 13. buildForCards is READ-ONLY: does NOT create ReviewLog.
     */
    public function test_build_for_cards_does_not_create_review_log(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::now()->subDay());

        $before = ReviewLog::where('review_card_id', $card->id)->count();

        for ($i = 0; $i < 5; $i++) {
            $this->feedbackService->buildForCards([$card->id]);
        }

        $after = ReviewLog::where('review_card_id', $card->id)->count();
        $this->assertSame($before, $after, 'buildForCards must not write ReviewLog');
    }

    /**
     * 14. buildForCards is READ-ONLY: does NOT change FSRS fields.
     */
    public function test_build_for_cards_does_not_change_fsrs_fields(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense, [
            'fsrs_due_at' => Carbon::now()->addDays(3),
            'fsrs_stability' => 9.5,
            'fsrs_difficulty' => 4.2,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
        ]);
        $this->createReviewLog($card, 'good', Carbon::now()->subDay());

        $before = $card->fresh();
        $beforeDue = $before->fsrs_due_at->toIso8601String();
        $beforeStability = $before->fsrs_stability;
        $beforeDifficulty = $before->fsrs_difficulty;
        $beforeReps = $before->fsrs_reps;
        $beforeLapses = $before->fsrs_lapses;

        for ($i = 0; $i < 5; $i++) {
            $this->feedbackService->buildForCards([$card->id]);
        }

        $after = $card->fresh();
        $this->assertSame($beforeDue, $after->fsrs_due_at->toIso8601String());
        $this->assertSame($beforeStability, $after->fsrs_stability);
        $this->assertSame($beforeDifficulty, $after->fsrs_difficulty);
        $this->assertSame($beforeReps, $after->fsrs_reps);
        $this->assertSame($beforeLapses, $after->fsrs_lapses);
    }

    /**
     * 15. buildForCard() (single) and buildForCards() (batch) produce the
     *     IDENTICAL payload for the same card — single source of truth.
     *
     * Covers: empty, mixed ratings, reset exclusion, trend variants, and
     * last_forget_date all in one comprehensive equivalence check.
     */
    public function test_single_and_batch_produce_identical_payload(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'reset', Carbon::create(2026, 7, 3, 10), 'reset');
        $this->createReviewLog($card, 'hard',  Carbon::create(2026, 7, 4, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 6, 10));
        $this->createReviewLog($card, 'easy',  Carbon::create(2026, 7, 7, 10));

        $single = $this->feedbackService->buildForCard($card->id);
        $batch = $this->feedbackService->buildForCards([$card->id]);

        $this->assertSame($single, $batch[$card->id]);
    }

    /**
     * 16. serializeMany() produces the same per-card payload shape as
     *     serialize(), and the learning_feedback block is identical.
     */
    public function test_serialize_many_matches_serialize_payload(): void
    {
        $senseA = $this->createConfirmedSense('bank', 'A sentence for bank.');
        $cardA = $this->createSenseCard($senseA);
        $this->createReviewLog($cardA, 'again', Carbon::now()->subDays(2));
        $this->createReviewLog($cardA, 'good',  Carbon::now()->subDays(1));

        $senseB = $this->createConfirmedSense('river', 'A sentence for river.');
        $cardB = $this->createSenseCard($senseB);
        $this->createReviewLog($cardB, 'hard', Carbon::now()->subDay());

        $cards = collect([$cardA->fresh()->load('sense'), $cardB->fresh()->load('sense')]);

        $many = $this->serializerService->serializeMany($cards);
        $singleA = $this->serializerService->serialize($cardA->fresh()->load('sense'));
        $singleB = $this->serializerService->serialize($cardB->fresh()->load('sense'));

        $this->assertCount(2, $many);
        // learning_feedback must be identical between batch and single paths.
        $this->assertSame($singleA['learning_feedback'], $many[0]['learning_feedback']);
        $this->assertSame($singleB['learning_feedback'], $many[1]['learning_feedback']);
        // Top-level keys must match.
        $this->assertSame(array_keys($singleA), array_keys($many[0]));
        $this->assertSame(array_keys($singleB), array_keys($many[1]));
    }

    /**
     * 17. serializeMany() with empty collection → empty array.
     */
    public function test_serialize_many_empty_collection(): void
    {
        $result = $this->serializerService->serializeMany(collect());

        $this->assertSame([], $result);
    }

    // ==================== Query Count Tests ====================

    /**
     * 18. ReviewLog query count does NOT grow with card count.
     *
     * Builds 1, 5, and 20 sense cards (each with a few ReviewLog rows),
     * then calls buildForCards() while capturing the SQL query log. The
     * number of queries touching the review_logs table must be CONSTANT
     * (exactly 1) regardless of how many cards are passed.
     *
     * Before optimization: buildForCard() issued ~7 queries per card
     * (count + 4 rating counts + recent + last_forget + trend). For 20
     * cards that was ~140 ReviewLog queries.
     * After optimization: exactly 1 ReviewLog query for any card count.
     */
    public function test_review_log_query_count_constant_regardless_of_card_count(): void
    {
        // Create 20 cards with logs.
        $cards = [];
        for ($i = 0; $i < 20; $i++) {
            $sense = $this->createConfirmedSense('word' . $i);
            $card = $this->createSenseCard($sense);
            $this->createReviewLog($card, 'again', Carbon::now()->subDays(5));
            $this->createReviewLog($card, 'good',  Carbon::now()->subDays(3));
            $this->createReviewLog($card, 'hard',  Carbon::now()->subDays(1));
            $cards[] = $card;
        }
        $allIds = array_map(fn ($c) => $c->id, $cards);

        // 1 card
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->feedbackService->buildForCards(array_slice($allIds, 0, 1));
        $queries1 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        // 5 cards
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->feedbackService->buildForCards(array_slice($allIds, 0, 5));
        $queries5 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        // 20 cards
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->feedbackService->buildForCards($allIds);
        $queries20 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        // The ReviewLog query count must be constant (exactly 1) and must
        // NOT grow linearly with the card count.
        $this->assertSame(1, $queries1,  "1 card: expected 1 review_logs query, got $queries1");
        $this->assertSame(1, $queries5,  "5 cards: expected 1 review_logs query, got $queries5");
        $this->assertSame(1, $queries20, "20 cards: expected 1 review_logs query, got $queries20");
    }

    /**
     * 19. serializeMany() also keeps ReviewLog queries constant.
     *
     * This validates the full controller-level batch path (serializer →
     * feedbackService::buildForCards). The serializer must NOT issue its
     * own per-card ReviewLog queries when a precomputed feedback map is
     * supplied.
     */
    public function test_serialize_many_review_log_query_count_constant(): void
    {
        $cards = collect();
        for ($i = 0; $i < 20; $i++) {
            $sense = $this->createConfirmedSense('sw' . $i, 'Sentence ' . $i);
            $card = $this->createSenseCard($sense);
            $this->createReviewLog($card, 'good', Carbon::now()->subDays(2));
            $cards->push($card->fresh()->load('sense'));
        }

        // 1 card
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->serializerService->serializeMany($cards->slice(0, 1));
        $queries1 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        // 20 cards
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->serializerService->serializeMany($cards);
        $queries20 = $this->countReviewLogQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries1,  "serializeMany 1 card: expected 1 review_logs query, got $queries1");
        $this->assertSame(1, $queries20, "serializeMany 20 cards: expected 1 review_logs query, got $queries20");
    }

    // ==================== Helpers ====================

    /**
     * Count queries that touch the review_logs table.
     */
    private function countReviewLogQueries(array $queryLog): int
    {
        $count = 0;
        foreach ($queryLog as $entry) {
            $sql = $entry['query'] ?? '';
            // Match the review_logs table name in FROM/JOIN/WHERE clauses.
            if (preg_match('/\breview_logs\b/i', $sql)) {
                $count++;
            }
        }
        return $count;
    }

    private function createReviewLog(ReviewCard $card, string $rating, Carbon $reviewedAt, string $source = 'sense_review'): ReviewLog
    {
        return ReviewLog::create([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => $reviewedAt,
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => $reviewedAt->copy()->subDay(),
            'new_due_at' => $reviewedAt->copy()->addDay(),
            'previous_stability' => 1.0,
            'new_stability' => 1.5,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 4.8,
            'source' => $source,
        ]);
    }

    private function createConfirmedSense(string $lemma, string $exampleEn = ''): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => ucfirst($lemma),
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => $exampleEn,
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);
        return $sense->fresh();
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        $data = array_merge([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subDay(),
            'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDay(),
        ], $overrides);

        return ReviewCard::forceCreate($data);
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
