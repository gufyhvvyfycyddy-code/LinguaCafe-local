<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewLearningFeedbackService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewLearningFeedbackServiceTest
 *
 * SenseReview-FeedbackService-1000-1
 *
 * Verifies the dedicated SenseReviewLearningFeedbackService that was
 * extracted from SenseReviewCardSerializerService. The service is the
 * single source of truth for ReviewLog aggregation, rating labels, and
 * forgetting-pattern trend computation.
 *
 * Contract:
 *  - buildForCard(int $reviewCardId) returns a stable structure even
 *    when the card has zero ReviewLog rows.
 *  - Counts aggregate non-reset ReviewLog rows for THIS card only.
 *  - recent_reviews: latest 5 non-reset logs, newest first, each with
 *    rating + rating_label + date (Y-m-d).
 *  - forgetting_pattern: total_forget, forget_rate, last_forget_date,
 *    trend (improving/declining/stable/insufficient).
 *  - reset-type logs (rating='reset' OR source='reset') are EXCLUDED.
 *  - READ-ONLY: never writes ReviewLog, never mutates any FSRS field.
 *  - Multi-user isolation via review_card_id scoping.
 */
class SenseReviewLearningFeedbackServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private SenseReviewLearningFeedbackService $feedbackService;

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

        $this->user = $this->createUser('feedback-service@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->feedbackService = app(SenseReviewLearningFeedbackService::class);
    }

    /**
     * 1. Empty ReviewLog → stable structure with zeros and insufficient trend.
     */
    public function test_empty_review_log_returns_stable_structure(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $fb = $this->feedbackService->buildForCard($card->id);

        $this->assertSame(0, $fb['total_reviews']);
        $this->assertSame(0, $fb['forget_count']);
        $this->assertSame(0, $fb['hard_count']);
        $this->assertSame(0, $fb['good_count']);
        $this->assertSame(0, $fb['easy_count']);
        $this->assertSame([], $fb['recent_reviews']);
        $this->assertSame(0, $fb['recent_forget_count']);
        $this->assertSame(0.0, $fb['forgetting_pattern']['forget_rate']);
        $this->assertNull($fb['forgetting_pattern']['last_forget_date']);
        $this->assertSame('insufficient', $fb['forgetting_pattern']['trend']);
    }

    /**
     * 2. Reset exclusion: rating='reset' OR source='reset' are excluded.
     */
    public function test_reset_logs_are_excluded(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(3), 'sense_review');
        $this->createReviewLog($card, 'again', Carbon::now()->subDays(2), 'sense_review');
        $this->createReviewLog($card, 'reset', Carbon::now()->subDays(1), 'reset');

        $fb = $this->feedbackService->buildForCard($card->id);

        $this->assertSame(2, $fb['total_reviews']);
        $this->assertSame(1, $fb['forget_count']);
        $this->assertSame(1, $fb['good_count']);
    }

    /**
     * 3. User isolation: another user's logs never leak.
     */
    public function test_other_users_logs_do_not_leak(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::now()->subDay());

        $otherUser = $this->createUser('other-feedback@example.com', 'english');
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

        $fb = $this->feedbackService->buildForCard($card->id);

        $this->assertSame(1, $fb['total_reviews']);
        $this->assertSame(0, $fb['forget_count']);
        $this->assertSame(1, $fb['good_count']);
    }

    /**
     * 4. Rating counts are accurate.
     */
    public function test_rating_counts_are_accurate(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $this->createReviewLog($card, 'again', Carbon::now()->subDays(5));
        $this->createReviewLog($card, 'hard',  Carbon::now()->subDays(4));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(3));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(2));
        $this->createReviewLog($card, 'easy',  Carbon::now()->subDays(1));

        $fb = $this->feedbackService->buildForCard($card->id);

        $this->assertSame(5, $fb['total_reviews']);
        $this->assertSame(1, $fb['forget_count']);
        $this->assertSame(1, $fb['hard_count']);
        $this->assertSame(2, $fb['good_count']);
        $this->assertSame(1, $fb['easy_count']);
    }

    /**
     * 5. recent_reviews: latest 5, newest first, with labels.
     */
    public function test_recent_reviews_newest_first_with_labels(): void
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

        $fb = $this->feedbackService->buildForCard($card->id);

        $this->assertCount(5, $fb['recent_reviews']);
        $this->assertSame('good',  $fb['recent_reviews'][0]['rating']);
        $this->assertSame('记得',   $fb['recent_reviews'][0]['rating_label']);
        $this->assertSame('2026-07-07', $fb['recent_reviews'][0]['date']);
        $this->assertSame('again', $fb['recent_reviews'][1]['rating']);
        $this->assertSame('忘了',   $fb['recent_reviews'][1]['rating_label']);
    }

    /**
     * 6. forget_rate = again_count / total_reviews.
     */
    public function test_forget_rate_is_again_over_total(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        for ($i = 0; $i < 8; $i++) {
            $this->createReviewLog($card, 'good', Carbon::now()->subDays(10 - $i));
        }
        for ($i = 0; $i < 2; $i++) {
            $this->createReviewLog($card, 'again', Carbon::now()->subDays(2 - $i));
        }

        $fb = $this->feedbackService->buildForCard($card->id);

        $this->assertSame(10, $fb['total_reviews']);
        $this->assertSame(2, $fb['forget_count']);
        $this->assertSame(0.2, $fb['forgetting_pattern']['forget_rate']);
    }

    /**
     * 7a. Trend improving: late half has fewer 'again' than early half.
     */
    public function test_trend_improving(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        // old→new: again, again, good, good, good, good → early=2, late=0 → improving
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 3, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 4, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 6, 10));

        $fb = $this->feedbackService->buildForCard($card->id);

        $this->assertSame('improving', $fb['forgetting_pattern']['trend']);
    }

    /**
     * 7b. Trend declining: late half has more 'again' than early half.
     */
    public function test_trend_declining(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        // old→new: good, good, good, again, again, again → early=0, late=3 → declining
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 3, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 4, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 6, 10));

        $fb = $this->feedbackService->buildForCard($card->id);

        $this->assertSame('declining', $fb['forgetting_pattern']['trend']);
    }

    /**
     * 7c. Trend stable: both halves have equal 'again' counts.
     */
    public function test_trend_stable(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        // old→new: again, good, good, again, good, good → early=1, late=1 → stable
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 3, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 4, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 6, 10));

        $fb = $this->feedbackService->buildForCard($card->id);

        $this->assertSame('stable', $fb['forgetting_pattern']['trend']);
    }

    /**
     * 7d. Trend insufficient: fewer than 4 logs.
     */
    public function test_trend_insufficient_when_fewer_than_four(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $this->createReviewLog($card, 'again', Carbon::now()->subDays(3));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(2));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(1));

        $fb = $this->feedbackService->buildForCard($card->id);

        $this->assertSame('insufficient', $fb['forgetting_pattern']['trend']);
    }

    /**
     * 8. last_forget_date is the most recent 'again' log date.
     */
    public function test_last_forget_date_is_most_recent_again(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 1, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 2, 10));
        $this->createReviewLog($card, 'again', Carbon::create(2026, 7, 5, 10));
        $this->createReviewLog($card, 'good',  Carbon::create(2026, 7, 6, 10));

        $fb = $this->feedbackService->buildForCard($card->id);

        $this->assertSame('2026-07-05', $fb['forgetting_pattern']['last_forget_date']);
    }

    /**
     * 9. Service is read-only: does NOT modify any FSRS field on ReviewCard.
     */
    public function test_service_does_not_change_fsrs_fields(): void
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
            $this->feedbackService->buildForCard($card->id);
        }

        $after = $card->fresh();
        $this->assertSame($beforeDue, $after->fsrs_due_at->toIso8601String());
        $this->assertSame($beforeStability, $after->fsrs_stability);
        $this->assertSame($beforeDifficulty, $after->fsrs_difficulty);
        $this->assertSame($beforeReps, $after->fsrs_reps);
        $this->assertSame($beforeLapses, $after->fsrs_lapses);
    }

    /**
     * 10. Service is read-only: does NOT create ReviewLog.
     */
    public function test_service_does_not_create_review_log(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'good', Carbon::now()->subDay());

        $before = ReviewLog::where('review_card_id', $card->id)->count();

        for ($i = 0; $i < 5; $i++) {
            $this->feedbackService->buildForCard($card->id);
        }

        $after = ReviewLog::where('review_card_id', $card->id)->count();
        $this->assertSame($before, $after, 'buildForCard must not write ReviewLog');
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
