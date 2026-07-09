<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseReviewCardSerializerService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewLearningFeedbackTest
 *
 * SenseReview-LearningFeedback-1000-1
 *
 * Verifies the read-only learning feedback aggregate exposed by the
 * SenseReviewCardSerializerService. The serializer augments each review
 * card payload with a `learning_feedback` block that summarizes the
 * card's ReviewLog history so the user can see their recent review
 * performance at a glance.
 *
 * Contract:
 *  - Payload always contains a `learning_feedback` key with a stable
 *    structure, even when the card has zero ReviewLog rows.
 *  - total_reviews / forget_count / hard_count / good_count / easy_count
 *    count non-reset ReviewLog rows for THIS card only.
 *  - recent_reviews returns the latest 5 non-reset logs (newest first),
 *    each with rating + rating_label + date (Y-m-d).
 *  - recent_forget_count counts 'again' ratings among the recent 5.
 *  - reset-type ReviewLog rows (rating='reset' or source='reset') are
 *    EXCLUDED from every aggregate, matching nonResetSenseReviewLogQuery.
 *  - Serializing is READ-ONLY: it does NOT write ReviewLog and does NOT
 *    modify any FSRS field on the ReviewCard.
 *  - Multi-user isolation: another user's ReviewLog rows never leak into
 *    this card's learning_feedback (guaranteed by review_card_id scoping).
 */
class SenseReviewLearningFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
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

        $this->user = $this->createUser('learning-feedback@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->serializerService = app(SenseReviewCardSerializerService::class);
    }

    /**
     * Payload always contains learning_feedback with a stable structure,
     * even when the card has no ReviewLog rows.
     */
    public function test_payload_includes_learning_feedback_key_with_stable_shape_when_empty(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertArrayHasKey('learning_feedback', $payload);
        $fb = $payload['learning_feedback'];
        $this->assertSame(0, $fb['total_reviews']);
        $this->assertSame(0, $fb['forget_count']);
        $this->assertSame(0, $fb['hard_count']);
        $this->assertSame(0, $fb['good_count']);
        $this->assertSame(0, $fb['easy_count']);
        $this->assertSame(0, $fb['recent_forget_count']);
        $this->assertSame([], $fb['recent_reviews']);
    }

    /**
     * Counts aggregate non-reset ReviewLog rows for this card only.
     */
    public function test_counts_aggregate_non_reset_review_logs_for_this_card(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $this->createReviewLog($card, 'again', Carbon::now()->subDays(10));
        $this->createReviewLog($card, 'hard',  Carbon::now()->subDays(9));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(8));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(7));
        $this->createReviewLog($card, 'easy',  Carbon::now()->subDays(6));

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $fb = $payload['learning_feedback'];
        $this->assertSame(5, $fb['total_reviews']);
        $this->assertSame(1, $fb['forget_count']);
        $this->assertSame(1, $fb['hard_count']);
        $this->assertSame(2, $fb['good_count']);
        $this->assertSame(1, $fb['easy_count']);
    }

    /**
     * recent_reviews returns the latest 5 non-reset logs, newest first,
     * each with rating + rating_label + date (Y-m-d).
     */
    public function test_recent_reviews_returns_latest_five_newest_first_with_labels(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        // 7 logs at distinct times — only the latest 5 should be returned.
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'hard',  Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 3, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 4, 10));
        $this->createReviewLog($card, 'easy',  Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 6, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 7, 10));

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $fb = $payload['learning_feedback'];
        $this->assertSame(7, $fb['total_reviews']);
        $this->assertCount(5, $fb['recent_reviews']);

        // Newest first
        $this->assertSame('good',  $fb['recent_reviews'][0]['rating']);
        $this->assertSame('记得',   $fb['recent_reviews'][0]['rating_label']);
        $this->assertSame('2026-07-07', $fb['recent_reviews'][0]['date']);

        $this->assertSame('again', $fb['recent_reviews'][1]['rating']);
        $this->assertSame('忘了',   $fb['recent_reviews'][1]['rating_label']);
        $this->assertSame('2026-07-06', $fb['recent_reviews'][1]['date']);

        $this->assertSame('easy',  $fb['recent_reviews'][2]['rating']);
        $this->assertSame('很熟',   $fb['recent_reviews'][2]['rating_label']);

        $this->assertSame('good',  $fb['recent_reviews'][3]['rating']);
        $this->assertSame('记得',   $fb['recent_reviews'][3]['rating_label']);

        $this->assertSame('good',  $fb['recent_reviews'][4]['rating']);
        $this->assertSame('记得',   $fb['recent_reviews'][4]['rating_label']);

        // recent_forget_count counts 'again' among the recent 5 (only 1 here)
        $this->assertSame(1, $fb['recent_forget_count']);
    }

    /**
     * reset-type ReviewLog rows (rating='reset' or source='reset') are
     * EXCLUDED from every aggregate, matching nonResetSenseReviewLogQuery.
     */
    public function test_reset_review_logs_are_excluded_from_aggregates(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        // 3 real reviews
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(3), 'sense_review');
        $this->createReviewLog($card, 'again', Carbon::now()->subDays(2), 'sense_review');
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(1), 'sense_review');
        // 1 reset log — must be excluded
        $this->createReviewLog($card, 'reset', Carbon::now()->subHours(12), 'reset');

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $fb = $payload['learning_feedback'];
        $this->assertSame(3, $fb['total_reviews']);
        $this->assertSame(1, $fb['forget_count']);
        $this->assertSame(0, $fb['hard_count']);
        $this->assertSame(2, $fb['good_count']);
        $this->assertSame(0, $fb['easy_count']);
        // recent_reviews must not contain the reset entry
        foreach ($fb['recent_reviews'] as $entry) {
            $this->assertNotSame('reset', $entry['rating']);
        }
        // recent_forget_count counts 'again' among the recent (1 here)
        $this->assertSame(1, $fb['recent_forget_count']);
    }

    /**
     * Serializing is READ-ONLY: it does NOT write ReviewLog.
     */
    public function test_serialize_does_not_write_review_log(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::now()->subDay());

        $before = ReviewLog::where('review_card_id', $card->id)->count();

        for ($i = 0; $i < 5; $i++) {
            $this->serializerService->serialize($card->fresh()->load('sense'));
        }

        $after = ReviewLog::where('review_card_id', $card->id)->count();
        $this->assertSame($before, $after, 'serialize must not write ReviewLog');
    }

    /**
     * Serializing is READ-ONLY: it does NOT modify any FSRS field on the
     * ReviewCard.
     */
    public function test_serialize_does_not_change_fsrs_fields(): void
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

        $beforeDue = $card->fsrs_due_at->toIso8601String();
        $beforeStability = $card->fsrs_stability;
        $beforeDifficulty = $card->fsrs_difficulty;
        $beforeReps = $card->fsrs_reps;
        $beforeLapses = $card->fsrs_lapses;

        for ($i = 0; $i < 5; $i++) {
            $this->serializerService->serialize($card->fresh()->load('sense'));
        }

        $after = $card->fresh();
        $this->assertSame($beforeDue, $after->fsrs_due_at->toIso8601String());
        $this->assertSame($beforeStability, $after->fsrs_stability);
        $this->assertSame($beforeDifficulty, $after->fsrs_difficulty);
        $this->assertSame($beforeReps, $after->fsrs_reps);
        $this->assertSame($beforeLapses, $after->fsrs_lapses);
    }

    /**
     * Multi-user isolation: another user's ReviewLog rows never leak into
     * this card's learning_feedback. The aggregate is scoped by
     * review_card_id, and a card belongs to exactly one user.
     */
    public function test_other_users_review_logs_do_not_leak_into_learning_feedback(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::now()->subDay());

        // Another user with their own card + logs
        $otherUser = $this->createUser('other-user@example.com', 'english');
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
        // Other user's logs — must NOT appear in $card's learning_feedback
        $this->createReviewLog($otherCard, 'again', Carbon::now()->subDays(5), 'sense_review');
        $this->createReviewLog($otherCard, 'hard',  Carbon::now()->subDays(4), 'sense_review');
        $this->createReviewLog($otherCard, 'easy',  Carbon::now()->subDays(3), 'sense_review');

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $fb = $payload['learning_feedback'];
        // Only this card's single 'good' log should be counted
        $this->assertSame(1, $fb['total_reviews']);
        $this->assertSame(0, $fb['forget_count']);
        $this->assertSame(0, $fb['hard_count']);
        $this->assertSame(1, $fb['good_count']);
        $this->assertSame(0, $fb['easy_count']);
        $this->assertCount(1, $fb['recent_reviews']);
        $this->assertSame('good', $fb['recent_reviews'][0]['rating']);
    }

    /**
     * recent_forget_count is used for the "容易忘记" hint: when 2+ of the
     * recent 5 are 'again', the hint should be triggerable. Verify the
     * count is accurate.
     */
    public function test_recent_forget_count_accurate_when_many_forgets_in_recent_five(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        // 5 recent logs, 3 'again' → recent_forget_count = 3
        $this->createReviewLog($card, 'again', Carbon::now()->subDays(5));
        $this->createReviewLog($card, 'again', Carbon::now()->subDays(4));
        $this->createReviewLog($card, 'hard',  Carbon::now()->subDays(3));
        $this->createReviewLog($card, 'again', Carbon::now()->subDays(2));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(1));

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $fb = $payload['learning_feedback'];
        $this->assertSame(3, $fb['recent_forget_count']);
        $this->assertSame(5, $fb['total_reviews']);
        $this->assertSame(3, $fb['forget_count']);
    }

    // ==================== forgetting_pattern ====================

    /**
     * forgetting_pattern key is always present with a stable shape.
     * A card with no ReviewLog returns trend='insufficient', forget_rate=0.0,
     * last_forget_date=null, and zero counts — never a missing key.
     */
    public function test_forgetting_pattern_present_with_insufficient_shape_when_no_reviews(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertArrayHasKey('forgetting_pattern', $payload['learning_feedback']);
        $fp = $payload['learning_feedback']['forgetting_pattern'];
        $this->assertSame(0, $fp['total_forget']);
        $this->assertSame(0, $fp['recent_forget_count']);
        $this->assertSame(0.0, $fp['forget_rate']);
        $this->assertNull($fp['last_forget_date']);
        $this->assertSame('insufficient', $fp['trend']);
    }

    /**
     * forget_rate = again_count / total_reviews.
     * 10 reviews: 2 again + 8 good → forget_rate = 0.2.
     */
    public function test_forgetting_pattern_forget_rate_is_again_count_over_total_reviews(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        // 8 good
        for ($i = 0; $i < 8; $i++) {
            $this->createReviewLog($card, 'good', Carbon::create(2026, 7, 1, 10)->addDays($i));
        }
        // 2 again
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 9, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 10, 10));

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $fp = $payload['learning_feedback']['forgetting_pattern'];
        $this->assertSame(2, $fp['total_forget']);
        $this->assertSame(10, $payload['learning_feedback']['total_reviews']);
        $this->assertSame(0.2, $fp['forget_rate']);
    }

    /**
     * trend=improving when the recent half has fewer 'again' than the early half.
     * 6 logs (old→new): again, again, good, good, good, good
     *   early(3) = 2 again, late(3) = 0 again → improving.
     */
    public function test_forgetting_pattern_trend_improving_when_recent_half_has_fewer_forgets(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $ratings = ['again', 'again', 'good', 'good', 'good', 'good'];
        foreach ($ratings as $i => $rating) {
            $this->createReviewLog($card, $rating, Carbon::create(2026, 7, 1, 10)->addDays($i));
        }

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertSame('improving', $payload['learning_feedback']['forgetting_pattern']['trend']);
    }

    /**
     * trend=declining when the recent half has more 'again' than the early half.
     * 6 logs (old→new): good, good, good, again, again, again
     *   early(3) = 0 again, late(3) = 3 again → declining.
     */
    public function test_forgetting_pattern_trend_declining_when_recent_half_has_more_forgets(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $ratings = ['good', 'good', 'good', 'again', 'again', 'again'];
        foreach ($ratings as $i => $rating) {
            $this->createReviewLog($card, $rating, Carbon::create(2026, 7, 1, 10)->addDays($i));
        }

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertSame('declining', $payload['learning_feedback']['forgetting_pattern']['trend']);
    }

    /**
     * trend=stable when both halves have the same 'again' count.
     * 6 logs (old→new): again, good, good, again, good, good
     *   early(3) = 1 again, late(3) = 1 again → stable.
     */
    public function test_forgetting_pattern_trend_stable_when_both_halves_have_equal_forgets(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $ratings = ['again', 'good', 'good', 'again', 'good', 'good'];
        foreach ($ratings as $i => $rating) {
            $this->createReviewLog($card, $rating, Carbon::create(2026, 7, 1, 10)->addDays($i));
        }

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertSame('stable', $payload['learning_feedback']['forgetting_pattern']['trend']);
    }

    /**
     * trend=insufficient when there are fewer than 4 reviews (not enough
     * data to split into two comparable halves).
     */
    public function test_forgetting_pattern_trend_insufficient_when_fewer_than_four_reviews(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 3, 10));

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertSame('insufficient', $payload['learning_feedback']['forgetting_pattern']['trend']);
    }

    /**
     * last_forget_date is the Y-m-d of the most recent 'again' log.
     */
    public function test_forgetting_pattern_last_forget_date_is_most_recent_again_date(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 3, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 6, 10));

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertSame('2026-07-05', $payload['learning_feedback']['forgetting_pattern']['last_forget_date']);
    }

    /**
     * forgetting_pattern excludes reset logs and never leaks other users' data.
     * - reset logs (rating='reset' / source='reset') do not count toward
     *   total_forget, forget_rate, or trend.
     * - another user's 'again' logs on their own card do not appear here.
     */
    public function test_forgetting_pattern_excludes_reset_logs_and_isolates_users(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        // 4 real reviews (2 again) — enough for a non-insufficient trend.
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 3, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 4, 10));
        // 1 reset log — must be excluded.
        $this->createReviewLog($card, 'reset', Carbon::create(2026, 7, 5, 10), 'reset');

        // Another user's card with 'again' logs — must not leak.
        $otherUser = $this->createUser('forget-other@example.com', 'english');
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
        $this->createReviewLog($otherCard, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($otherCard, 'again', Carbon::create(2026, 7, 2, 10));

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $fp = $payload['learning_feedback']['forgetting_pattern'];
        $this->assertSame(2, $fp['total_forget'], 'only this card\'s 2 again logs');
        $this->assertSame(4, $payload['learning_feedback']['total_reviews'], 'reset excluded from total');
        $this->assertSame(0.5, $fp['forget_rate'], '2/4 = 0.5');
        $this->assertNotSame('insufficient', $fp['trend'], '4 reviews → real trend, not insufficient');
        $this->assertSame('2026-07-04', $fp['last_forget_date'], 'most recent again on this card');
    }

    // ==================== Helpers ====================

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
