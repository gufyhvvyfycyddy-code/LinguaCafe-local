<?php

namespace Tests\Feature;

use App\Models\AiStudyCardPendingItem;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiStudyCardV6RequestPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('ai-v6-user@example.test', 'english');
        $this->otherUser = $this->createUser('ai-v6-other@example.test', 'english');
        $this->chapter = $this->createChapter($this->user, 'english');
    }

    public function test_user_can_build_provider_disabled_v6_request_package(): void
    {
        $item = $this->createPendingItem($this->user, $this->chapter, [
            'word' => 'agency',
            'lemma' => 'agency',
            'surface' => 'agency',
            'sentence_text' => 'Agency is the capacity to act in a situation.',
        ]);

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/v6/recommendations/request-package', [
            'item_ids' => [$item->id],
            'context_policy' => 'selected_items_with_sentence',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('package.schema_version', 'ai-study-card-v6-request-package-v1');
        $response->assertJsonPath('package.provider_request_state', 'provider_disabled');
        $response->assertJsonPath('package.provider_preflight.ready', false);
        $response->assertJsonPath('package.provider_preflight.provider', 'disabled');
        $response->assertJsonPath('package.provider_preflight.model', 'deepseek-chat');
        $response->assertJsonPath('package.provider_preflight.item_count', 1);
        $response->assertJsonPath('package.provider_preflight.timeout_seconds', 0);
        $response->assertJsonPath('package.provider_preflight.max_cost_usd', null);
        $response->assertJsonPath('package.provider_preflight.estimated_cost_usd', null);
        $response->assertJsonPath('package.provider_preflight.secret_source', 'env');
        $response->assertJsonPath('package.provider_preflight.failure_policy', 'fail_closed');
        $response->assertJsonPath('package.provider_preflight.external_data_fields.0', 'word');
        $response->assertJsonFragment(['cost_ceiling_not_configured']);
        $response->assertJsonPath('package.language', 'english');
        $response->assertJsonPath('package.context_policy.mode', 'selected_items_with_sentence');
        $response->assertJsonPath('package.context_policy.include_full_chapter_text', false);
        $response->assertJsonPath('package.context_policy.raw_source_payload_excluded', true);
        $response->assertJsonPath('package.selected_pending_item_ids.0', $item->id);
        $response->assertJsonPath('package.selected_items.0.item_id', $item->id);
        $response->assertJsonPath('package.selected_items.0.word', 'agency');
        $response->assertJsonPath('package.selected_items.0.source', 'user_selected_pending_item');
        $response->assertJsonPath('package.provider_instructions.return_schema_version', 'ai-study-card-v6-recommendation-package-v1');
        $response->assertJsonPath('package.generation_rules.provider_disabled_in_this_round', true);
        $response->assertJsonPath('package.generation_rules.ai_recommendations_default_unchecked', true);
        $response->assertJsonPath('package.generation_rules.ai_reason_not_final_sense_zh', true);
        $response->assertJsonPath('package.generation_rules.final_card_creation_must_use_v5_generate_cards', true);
        $response->assertJsonPath('package.safety_flags.user_triggered_request', true);
        $response->assertJsonPath('package.safety_flags.provider_disabled', true);
        $response->assertJsonPath('package.safety_flags.no_provider_called', true);
        $response->assertJsonPath('package.safety_flags.no_card_creation', true);
        $response->assertJsonPath('package.safety_flags.no_review_log_created', true);
        $response->assertJsonPath('package.safety_flags.no_fsrs_changed', true);
        $response->assertJsonPath('package.safety_flags.no_word_sense_created', true);
        $response->assertJsonPath('package.safety_flags.no_review_card_created', true);
        $response->assertJsonPath('package.safety_flags.no_legacy_word_card_created', true);
        $response->assertJsonPath('package.safety_flags.user_confirmation_required', true);
        $this->assertStringNotContainsString('secret_reference', $response->getContent());
        $this->assertStringNotContainsString('AI_STUDY_CARD_V6_API_KEY', $response->getContent());
    }

    public function test_request_package_does_not_write_cards_senses_logs_or_pending_state(): void
    {
        $item = $this->createPendingItem($this->user, $this->chapter);

        $this->actingAs($this->user)->postJson('/ai-study-card/v6/recommendations/request-package', [
            'item_ids' => [$item->id],
        ])->assertOk();

        $this->assertSame(0, WordSense::count());
        $this->assertSame(0, ReviewCard::count());
        $this->assertSame(0, ReviewLog::count());
        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $item->id,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);
    }

    public function test_request_package_requires_authentication(): void
    {
        $item = $this->createPendingItem($this->user, $this->chapter);

        $this->postJson('/ai-study-card/v6/recommendations/request-package', [
            'item_ids' => [$item->id],
        ])->assertUnauthorized();
    }

    public function test_request_package_requires_at_least_one_item_id(): void
    {
        $this->actingAs($this->user)->postJson('/ai-study-card/v6/recommendations/request-package', [
            'item_ids' => [],
        ])->assertStatus(422);
    }

    public function test_request_package_filters_other_user_items(): void
    {
        $ownItem = $this->createPendingItem($this->user, $this->chapter, ['word' => 'agency']);
        $otherChapter = $this->createChapter($this->otherUser, 'english');
        $otherItem = $this->createPendingItem($this->otherUser, $otherChapter, ['word' => 'foreign']);

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/v6/recommendations/request-package', [
            'item_ids' => [$ownItem->id, $otherItem->id],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'package.selected_items');
        $response->assertJsonPath('package.selected_items.0.item_id', $ownItem->id);
        $response->assertJsonMissing(['word' => 'foreign']);
    }

    public function test_request_package_filters_cross_language_items(): void
    {
        $englishItem = $this->createPendingItem($this->user, $this->chapter, ['word' => 'agency']);
        $spanishChapter = $this->createChapter($this->user, 'spanish');
        $spanishItem = $this->createPendingItem($this->user, $spanishChapter, [
            'word' => 'agencia',
            'language' => 'spanish',
            'language_id' => 'spanish',
        ]);

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/v6/recommendations/request-package', [
            'item_ids' => [$englishItem->id, $spanishItem->id],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'package.selected_items');
        $response->assertJsonPath('package.selected_items.0.item_id', $englishItem->id);
        $response->assertJsonMissing(['word' => 'agencia']);
    }

    public function test_request_package_rejects_dismissed_or_processed_only_items(): void
    {
        $dismissed = $this->createPendingItem($this->user, $this->chapter, [
            'word' => 'dismissed',
            'status' => AiStudyCardPendingItem::STATUS_DISMISSED,
        ]);
        $processed = $this->createPendingItem($this->user, $this->chapter, [
            'word' => 'processed',
            'status' => AiStudyCardPendingItem::STATUS_PROCESSED,
        ]);

        $this->actingAs($this->user)->postJson('/ai-study-card/v6/recommendations/request-package', [
            'item_ids' => [$dismissed->id, $processed->id],
        ])->assertStatus(404);
    }

    public function test_request_package_does_not_return_raw_source_payload(): void
    {
        $item = $this->createPendingItem($this->user, $this->chapter, [
            'source_payload' => ['raw' => 'do not expose this raw payload'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/v6/recommendations/request-package', [
            'item_ids' => [$item->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('package.context_policy.raw_source_payload_excluded', true);
        $this->assertStringNotContainsString('do not expose this raw payload', $response->getContent());
        $this->assertStringNotContainsString('"raw"', $response->getContent());
    }

    public function test_request_package_limits_number_of_items(): void
    {
        $ids = range(1, 51);

        $this->actingAs($this->user)->postJson('/ai-study-card/v6/recommendations/request-package', [
            'item_ids' => $ids,
        ])->assertStatus(422);
    }

    public function test_route_is_dedicated_and_does_not_change_existing_v5_routes(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("Route::post('/ai-study-card/v6/recommendations/request-package', [App\\Http\\Controllers\\AiStudyCardV6RecommendationController::class, 'requestPackage'])", $routes);
        $this->assertStringContainsString("Route::post('/ai-study-card/pending-items/preview-package', [App\\Http\\Controllers\\AiStudyCardPendingItemController::class, 'previewPackage'])", $routes);
        $this->assertStringContainsString("Route::post('/ai-study-card/pending-items/final-candidates-package', [App\\Http\\Controllers\\AiStudyCardPendingItemController::class, 'finalCandidatesPackage'])", $routes);
        $this->assertStringContainsString("Route::post('/ai-study-card/generate-cards', [App\\Http\\Controllers\\AiStudyCardPendingItemController::class, 'generateCards'])", $routes);
    }

    private function createPendingItem(User $user, Chapter $chapter, array $overrides = []): AiStudyCardPendingItem
    {
        $language = $overrides['language_id'] ?? $user->selected_language;

        return AiStudyCardPendingItem::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'chapter_id' => $chapter->id,
            'text_block_index' => 0,
            'sentence_index' => 0,
            'sentence_id' => 'ai-v6-test-sentence-0',
            'word' => 'landscape',
            'normalized_word' => 'landscape',
            'surface' => 'landscape',
            'lemma' => 'landscape',
            'sentence_text' => 'The landscape is clear.',
            'source_payload' => ['source' => 'test'],
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ], $overrides));
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
        ]);
    }

    private function createChapter(User $user, string $language): Chapter
    {
        $book = Book::forceCreate([
            'name' => "V6 {$language} Book",
            'user_id' => $user->id,
            'language' => $language,
        ]);

        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => "V6 {$language} Chapter",
            'language' => $language,
            'raw_text' => 'The landscape is clear.',
            'word_count' => 4,
            'read_count' => 0,
            'unique_words' => '["the","landscape","is","clear"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }
}
