<?php

namespace Tests\Feature;

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
 * SenseReviewSerializerContractTest
 *
 * SenseReview-SerializerContract-1000-1
 *
 * Verifies that the SenseReviewCardSerializerService payload shape is
 * preserved after the extraction of SenseReviewLearningFeedbackService.
 * The refactoring must be transparent to any consumer of the payload.
 *
 * Contract:
 *  - learning_feedback block has the same keys and shape as before.
 *  - understanding_aid is still normalized and present.
 *  - occurrence rotation fields are still present.
 *  - FSRS fields are still present.
 *  - The overall payload has the same top-level keys.
 */
class SenseReviewSerializerContractTest extends TestCase
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

        $this->user = $this->createUser('serializer-contract@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->serializerService = app(SenseReviewCardSerializerService::class);
    }

    /**
     * 1. learning_feedback payload is compatible with pre-refactor shape.
     */
    public function test_learning_feedback_payload_shape_unchanged(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);
        $this->createReviewLog($card, 'again', Carbon::now()->subDays(2));
        $this->createReviewLog($card, 'good',  Carbon::now()->subDays(1));

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertArrayHasKey('learning_feedback', $payload);
        $fb = $payload['learning_feedback'];

        // Top-level keys
        $this->assertArrayHasKey('total_reviews', $fb);
        $this->assertArrayHasKey('forget_count', $fb);
        $this->assertArrayHasKey('hard_count', $fb);
        $this->assertArrayHasKey('good_count', $fb);
        $this->assertArrayHasKey('easy_count', $fb);
        $this->assertArrayHasKey('recent_reviews', $fb);
        $this->assertArrayHasKey('recent_forget_count', $fb);
        $this->assertArrayHasKey('forgetting_pattern', $fb);

        // forgetting_pattern keys
        $fp = $fb['forgetting_pattern'];
        $this->assertArrayHasKey('total_forget', $fp);
        $this->assertArrayHasKey('recent_forget_count', $fp);
        $this->assertArrayHasKey('forget_rate', $fp);
        $this->assertArrayHasKey('last_forget_date', $fp);
        $this->assertArrayHasKey('trend', $fp);

        // recent_reviews entry shape
        $this->assertNotEmpty($fb['recent_reviews']);
        $entry = $fb['recent_reviews'][0];
        $this->assertArrayHasKey('rating', $entry);
        $this->assertArrayHasKey('rating_label', $entry);
        $this->assertArrayHasKey('date', $entry);
    }

    /**
     * 2. understanding_aid is still present and normalized.
     */
    public function test_understanding_aid_not_lost(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $sense->update([
            'understanding_aid' => [
                'explanation' => 'test explanation',
                'meaning_boundary' => 'boundary',
                'context_hint' => 'hint',
                'usage_keywords' => ['kw1'],
                'related_collocations' => ['col1'],
            ],
        ]);
        $card = $this->createSenseCard($sense->fresh());

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertArrayHasKey('understanding_aid', $payload);
        $aid = $payload['understanding_aid'];
        $this->assertSame('test explanation', $aid['explanation']);
        $this->assertSame('boundary', $aid['meaning_boundary']);
        $this->assertSame('hint', $aid['context_hint']);
        $this->assertSame(['kw1'], $aid['usage_keywords']);
        $this->assertSame(['col1'], $aid['related_collocations']);
    }

    /**
     * 3. Occurrence rotation fields are still present.
     */
    public function test_occurrence_rotation_fields_not_lost(): void
    {
        $sense = $this->createConfirmedSense('bank', 'A test sentence for bank.');
        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertArrayHasKey('displayed_occurrence_id', $payload);
        $this->assertArrayHasKey('occurrence_count', $payload);
        $this->assertArrayHasKey('example_source_status', $payload);
        $this->assertArrayHasKey('example_candidates', $payload);
        $this->assertArrayHasKey('example_candidates_count', $payload);
        $this->assertArrayHasKey('supplementary_example', $payload);
    }

    /**
     * 4. FSRS fields are still present.
     */
    public function test_fsrs_fields_not_lost(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense, [
            'fsrs_stability' => 5.5,
            'fsrs_difficulty' => 4.1,
            'fsrs_reps' => 7,
            'fsrs_lapses' => 2,
        ]);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertArrayHasKey('fsrs_state', $payload);
        $this->assertArrayHasKey('fsrs_due_at', $payload);
        $this->assertArrayHasKey('fsrs_stability', $payload);
        $this->assertArrayHasKey('fsrs_difficulty', $payload);
        $this->assertArrayHasKey('fsrs_reps', $payload);
        $this->assertArrayHasKey('fsrs_lapses', $payload);
        $this->assertSame(5.5, $payload['fsrs_stability']);
        $this->assertSame(4.1, $payload['fsrs_difficulty']);
        $this->assertSame(7, $payload['fsrs_reps']);
        $this->assertSame(2, $payload['fsrs_lapses']);
    }

    /**
     * 5. Single card response structure: top-level keys unchanged.
     */
    public function test_top_level_payload_keys_unchanged(): void
    {
        $sense = $this->createConfirmedSense('bank', 'A test sentence.');
        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $expectedKeys = [
            'review_card_id', 'word_sense_id', 'lemma', 'surface_form', 'pos',
            'sense_zh', 'sense_en', 'aliases_zh', 'collocations',
            'understanding_aid', 'example_sentence_en', 'example_sentence_zh',
            'example_sentence_tokens', 'example_sentence_token_source',
            'example_candidates', 'example_candidates_count',
            'supplementary_example', 'displayed_occurrence_id',
            'occurrence_count', 'example_source_status',
            'fsrs_state', 'fsrs_due_at', 'fsrs_stability', 'fsrs_difficulty',
            'fsrs_reps', 'fsrs_lapses', 'learning_feedback',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $payload, "Missing top-level key: $key");
        }
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
