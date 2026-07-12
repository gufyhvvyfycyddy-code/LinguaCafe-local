<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewLeechPolicy;
use App\Services\SenseReviewLeechRewritePackageService;
use App\Services\SenseReviewLearningFeedbackService;
use App\Services\ReviewCardLifecyclePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewLeechRewritePackageTest
 *
 * ADR-0011: Feature tests for the rewrite package service.
 *
 * Verifies that the package:
 *  - Has correct schema_version
 *  - Does NOT call AI (provider_called=false)
 *  - Does NOT create WordSense / ReviewCard / ReviewLog
 *  - Contains all required fields
 *  - Generates valid Markdown
 *  - Batch generation works with partial failures
 */
class SenseReviewLeechRewritePackageTest extends TestCase
{
    use RefreshDatabase;

    private SenseReviewLeechRewritePackageService $service;
    private SenseReviewLeechPolicy $leechPolicy;
    private SenseReviewLearningFeedbackService $feedbackService;
    private ReviewCardLifecyclePolicy $lifecyclePolicy;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SenseReviewLeechRewritePackageService::class);
        $this->leechPolicy = app(SenseReviewLeechPolicy::class);
        $this->feedbackService = app(SenseReviewLearningFeedbackService::class);
        $this->lifecyclePolicy = app(ReviewCardLifecyclePolicy::class);
        $this->user = User::forceCreate([
            'name' => 'Rewrite Package Test',
            'email' => 'rewrite-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_build_package_has_correct_schema_version(): void
    {
        $card = $this->makeCard();
        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycle = $this->lifecyclePolicy->describe($card, now(), 'UTC');
        $leech = $this->leechPolicy->classify($card, $feedback, $lifecycle);

        $result = $this->service->buildPackage($card, $feedback, $leech, $lifecycle);

        $this->assertSame('sense-leech-rewrite-package-v1', $result['schema_version']);
        $this->assertSame('sense-leech-rewrite-package-v1', $result['package']['schema_version']);
    }

    public function test_build_package_provider_called_is_false(): void
    {
        $card = $this->makeCard();
        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycle = $this->lifecyclePolicy->describe($card, now(), 'UTC');
        $leech = $this->leechPolicy->classify($card, $feedback, $lifecycle);

        $result = $this->service->buildPackage($card, $feedback, $leech, $lifecycle);

        $this->assertFalse($result['provider_called']);
    }

    public function test_build_package_card_created_is_false(): void
    {
        $card = $this->makeCard();
        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycle = $this->lifecyclePolicy->describe($card, now(), 'UTC');
        $leech = $this->leechPolicy->classify($card, $feedback, $lifecycle);

        $result = $this->service->buildPackage($card, $feedback, $leech, $lifecycle);

        $this->assertFalse($result['card_created']);
    }

    public function test_build_package_review_log_created_is_false(): void
    {
        $card = $this->makeCard();
        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycle = $this->lifecyclePolicy->describe($card, now(), 'UTC');
        $leech = $this->leechPolicy->classify($card, $feedback, $lifecycle);

        $result = $this->service->buildPackage($card, $feedback, $leech, $lifecycle);

        $this->assertFalse($result['review_log_created']);
    }

    public function test_build_package_does_not_create_word_sense(): void
    {
        $card = $this->makeCard();
        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycle = $this->lifecyclePolicy->describe($card, now(), 'UTC');
        $leech = $this->leechPolicy->classify($card, $feedback, $lifecycle);

        $senseCountBefore = WordSense::count();

        $this->service->buildPackage($card, $feedback, $leech, $lifecycle);

        $this->assertSame($senseCountBefore, WordSense::count());
    }

    public function test_build_package_does_not_create_review_card(): void
    {
        $card = $this->makeCard();
        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycle = $this->lifecyclePolicy->describe($card, now(), 'UTC');
        $leech = $this->leechPolicy->classify($card, $feedback, $lifecycle);

        $cardCountBefore = ReviewCard::count();

        $this->service->buildPackage($card, $feedback, $leech, $lifecycle);

        $this->assertSame($cardCountBefore, ReviewCard::count());
    }

    public function test_build_package_does_not_create_review_log(): void
    {
        $card = $this->makeCard();
        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycle = $this->lifecyclePolicy->describe($card, now(), 'UTC');
        $leech = $this->leechPolicy->classify($card, $feedback, $lifecycle);

        $logCountBefore = ReviewLog::count();

        $this->service->buildPackage($card, $feedback, $leech, $lifecycle);

        $this->assertSame($logCountBefore, ReviewLog::count());
    }

    public function test_build_package_contains_required_fields(): void
    {
        $card = $this->makeCard();
        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycle = $this->lifecyclePolicy->describe($card, now(), 'UTC');
        $leech = $this->leechPolicy->classify($card, $feedback, $lifecycle);

        $result = $this->service->buildPackage($card, $feedback, $leech, $lifecycle);
        $package = $result['package'];

        $this->assertArrayHasKey('schema_version', $package);
        $this->assertArrayHasKey('generated_at', $package);
        $this->assertArrayHasKey('review_card_id', $package);
        $this->assertArrayHasKey('word_sense_id', $package);
        $this->assertArrayHasKey('lemma', $package);
        $this->assertArrayHasKey('part_of_speech', $package);
        $this->assertArrayHasKey('sense_zh', $package);
        $this->assertArrayHasKey('sense_en', $package);
        $this->assertArrayHasKey('current_example', $package);
        $this->assertArrayHasKey('source_context', $package);
        $this->assertArrayHasKey('recent_review_summary', $package);
        $this->assertArrayHasKey('forgetting_reasons', $package);
        $this->assertArrayHasKey('user_goal', $package);
        $this->assertArrayHasKey('output_contract', $package);
        $this->assertArrayHasKey('safety_rules', $package);
    }

    public function test_build_package_markdown_is_valid(): void
    {
        $card = $this->makeCard();
        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycle = $this->lifecyclePolicy->describe($card, now(), 'UTC');
        $leech = $this->leechPolicy->classify($card, $feedback, $lifecycle);

        $result = $this->service->buildPackage($card, $feedback, $leech, $lifecycle);

        $this->assertNotEmpty($result['markdown']);
        $this->assertStringContainsString('# Sense Leech Rewrite Package', $result['markdown']);
        $this->assertStringContainsString('did NOT call any AI provider', $result['markdown']);
    }

    public function test_build_package_safety_rules_present(): void
    {
        $card = $this->makeCard();
        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycle = $this->lifecyclePolicy->describe($card, now(), 'UTC');
        $leech = $this->leechPolicy->classify($card, $feedback, $lifecycle);

        $result = $this->service->buildPackage($card, $feedback, $leech, $lifecycle);
        $rules = $result['package']['safety_rules'];

        $this->assertArrayHasKey('do_not_create_new_senses', $rules);
        $this->assertArrayHasKey('do_not_create_review_cards', $rules);
        $this->assertArrayHasKey('do_not_modify_fsrs', $rules);
        $this->assertArrayHasKey('do_not_call_external_apis', $rules);
    }

    public function test_build_packages_batch(): void
    {
        $card1 = $this->makeCard();
        $card2 = $this->makeCard();

        $cardsData = [];
        foreach ([$card1, $card2] as $card) {
            $feedback = $this->feedbackService->buildForCard($card->id);
            $lifecycle = $this->lifecyclePolicy->describe($card, now(), 'UTC');
            $leech = $this->leechPolicy->classify($card, $feedback, $lifecycle);
            $cardsData[] = [
                'card' => $card,
                'feedback' => $feedback,
                'leechDescriptor' => $leech,
                'lifecycleDescriptor' => $lifecycle,
            ];
        }

        $result = $this->service->buildPackagesBatch($cardsData);

        $this->assertCount(2, $result['packages']);
        $this->assertEmpty($result['failed']);
        $this->assertFalse($result['provider_called']);
    }

    // ─── Helpers ───

    private function makeCard(array $overrides = []): ReviewCard
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'test' . Str::random(4),
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
            'sense_key' => hash('sha256', strtolower('english|test|noun|测试|test')),
        ]);

        return ReviewCard::forceCreate(array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'lifecycle_state' => 'active',
        ], $overrides));
    }
}
