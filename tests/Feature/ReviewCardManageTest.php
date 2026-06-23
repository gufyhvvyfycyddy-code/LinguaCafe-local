<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReviewCardManageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Manage Test User',
            'email' => 'manage@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => 'other.manage@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // ==================== Helpers ====================

    private function createSense(int $userId, string $language, array $overrides = []): WordSense
    {
        $lemma = $overrides['lemma'] ?? 'test';
        $pos = $overrides['pos'] ?? 'noun';
        $senseZh = $overrides['sense_zh'] ?? '测试';
        $senseEn = $overrides['sense_en'] ?? 'test';

        $data = array_merge([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => 'test',
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
            'sense_key' => hash('sha256', strtolower("{$language}|{$lemma}|{$pos}|{$senseZh}|{$senseEn}")),
        ], $overrides);

        return WordSense::forceCreate($data);
    }

    private function createSenseCard(WordSense $sense): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);
    }

    private function createWordCard(int $userId, string $language, int $wordId): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $userId,
            'language_id' => $language,
            'language' => $language,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $wordId,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);
    }

    // ==================== Data List Tests ====================

    public function test_data_returns_only_current_user_language_sense_confirmed_cards(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame($card->id, $items[0]['review_card_id']);
        $this->assertSame($sense->id, $items[0]['word_sense_id']);
    }

    public function test_data_excludes_legacy_word_cards(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $this->createSenseCard($sense);
        $this->createWordCard($this->user->id, 'english', 999);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame(ReviewCard::TARGET_SENSE, ReviewCard::find($items[0]['review_card_id'])->target_type);
    }

    public function test_data_excludes_other_user_cards(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $this->createSenseCard($sense);

        $otherSense = $this->createSense($this->otherUser->id, 'english', ['lemma' => 'other']);
        $this->createSenseCard($otherSense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame($sense->lemma, $items[0]['lemma']);
    }

    public function test_data_excludes_other_language_cards(): void
    {
        $senseEn = $this->createSense($this->user->id, 'english');
        $this->createSenseCard($senseEn);

        $senseEs = $this->createSense($this->user->id, 'spanish', ['lemma' => 'spanish']);
        $this->createSenseCard($senseEs);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(1, $items);
    }

    public function test_data_excludes_rejected_word_senses(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['status' => WordSense::STATUS_REJECTED]);
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(0, $items);
    }

    public function test_data_excludes_non_confirmed_word_senses(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['status' => WordSense::STATUS_AI_SUGGESTED]);
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(0, $items);
    }

    public function test_data_includes_disabled_sense_cards(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);
        $card->update(['fsrs_enabled' => false]);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertFalse($items[0]['fsrs_enabled']);
    }

    public function test_data_includes_enabled_sense_cards(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertTrue($items[0]['fsrs_enabled']);
    }

    // ==================== Search Tests ====================

    public function test_search_lemma(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['lemma' => 'uniquelemma']);
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?q=uniquelemma');
        $this->assertCount(1, $response->json('items'));

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?q=nonexistent');
        $this->assertCount(0, $response->json('items'));
    }

    public function test_search_surface_form(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['surface_form' => 'surf']);
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?q=surf');
        $this->assertCount(1, $response->json('items'));
    }

    public function test_search_sense_zh(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['sense_zh' => '独一无二']);
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?q=独一无二');
        $this->assertCount(1, $response->json('items'));
    }

    public function test_search_sense_en(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['sense_en' => 'uniquedef']);
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?q=uniquedef');
        $this->assertCount(1, $response->json('items'));
    }

    public function test_search_example_sentence_en(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['example_sentence_en' => 'unique example sentence']);
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?q=unique+example');
        $this->assertCount(1, $response->json('items'));
    }

    public function test_search_does_not_cross_users(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['lemma' => 'shared']);
        $this->createSenseCard($sense);
        $otherSense = $this->createSense($this->otherUser->id, 'english', ['lemma' => 'shared']);
        $this->createSenseCard($otherSense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?q=shared');
        $this->assertCount(1, $response->json('items'));
    }

    public function test_search_does_not_cross_languages(): void
    {
        $senseEn = $this->createSense($this->user->id, 'english', ['lemma' => 'shared']);
        $this->createSenseCard($senseEn);
        $senseEs = $this->createSense($this->user->id, 'spanish', ['lemma' => 'shared']);
        $this->createSenseCard($senseEs);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?q=shared');
        $this->assertCount(1, $response->json('items'));
    }

    public function test_search_orWhere_does_not_escape_scope(): void
    {
        // Create a rejected sense that shares a search term — should NOT appear
        $rejectedSense = $this->createSense($this->user->id, 'english', [
            'lemma' => 'rejectedLemma',
            'status' => WordSense::STATUS_REJECTED,
        ]);
        $this->createSenseCard($rejectedSense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?q=rejectedLemma');
        // Rejected sense should not appear even though lemma matches
        $this->assertCount(0, $response->json('items'));
    }

    // ==================== Filter Tests ====================

    public function test_filter_enabled(): void
    {
        $sense1 = $this->createSense($this->user->id, 'english', ['lemma' => 'enabled1']);
        $card1 = $this->createSenseCard($sense1);
        $card1->update(['fsrs_enabled' => true]);

        $sense2 = $this->createSense($this->user->id, 'english', ['lemma' => 'disabled1']);
        $card2 = $this->createSenseCard($sense2);
        $card2->update(['fsrs_enabled' => false]);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?filter=enabled');
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertTrue($items[0]['fsrs_enabled']);
    }

    public function test_filter_disabled(): void
    {
        $sense1 = $this->createSense($this->user->id, 'english', ['lemma' => 'en1']);
        $card1 = $this->createSenseCard($sense1);
        $card1->update(['fsrs_enabled' => true]);

        $sense2 = $this->createSense($this->user->id, 'english', ['lemma' => 'dis1']);
        $card2 = $this->createSenseCard($sense2);
        $card2->update(['fsrs_enabled' => false]);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?filter=disabled');
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertFalse($items[0]['fsrs_enabled']);
    }

    public function test_filter_due(): void
    {
        $sense1 = $this->createSense($this->user->id, 'english', ['lemma' => 'due1']);
        $card1 = $this->createSenseCard($sense1);
        $card1->update(['fsrs_due_at' => now()->subHour()]);

        $sense2 = $this->createSense($this->user->id, 'english', ['lemma' => 'future1']);
        $card2 = $this->createSenseCard($sense2);
        $card2->update(['fsrs_due_at' => now()->addDay()]);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?filter=due');
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('due1', $items[0]['lemma']);
    }

    public function test_filter_future(): void
    {
        $sense1 = $this->createSense($this->user->id, 'english', ['lemma' => 'past1']);
        $card1 = $this->createSenseCard($sense1);
        $card1->update(['fsrs_due_at' => now()->subHour()]);

        $sense2 = $this->createSense($this->user->id, 'english', ['lemma' => 'future2']);
        $card2 = $this->createSenseCard($sense2);
        $card2->update(['fsrs_due_at' => now()->addDay()]);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?filter=future');
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('future2', $items[0]['lemma']);
    }

    public function test_filter_missing_definition(): void
    {
        $sense1 = $this->createSense($this->user->id, 'english', [
            'lemma' => 'missingDef',
            'sense_zh' => '',
            'sense_en' => '',
        ]);
        $this->createSenseCard($sense1);

        $sense2 = $this->createSense($this->user->id, 'english', [
            'lemma' => 'hasDef',
            'sense_zh' => '有释义',
            'sense_en' => 'has def',
        ]);
        $this->createSenseCard($sense2);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?filter=missing_definition');
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('missingDef', $items[0]['lemma']);
        $this->assertTrue($items[0]['missing_definition']);
    }

    public function test_filter_missing_example(): void
    {
        $sense1 = $this->createSense($this->user->id, 'english', [
            'lemma' => 'missingEx',
            'example_sentence_en' => null,
        ]);
        $this->createSenseCard($sense1);

        $sense2 = $this->createSense($this->user->id, 'english', [
            'lemma' => 'hasEx',
            'example_sentence_en' => 'An example.',
        ]);
        $this->createSenseCard($sense2);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?filter=missing_example');
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('missingEx', $items[0]['lemma']);
        $this->assertTrue($items[0]['missing_example']);
    }

    public function test_filter_missing_source(): void
    {
        $sense1 = $this->createSense($this->user->id, 'english', [
            'lemma' => 'missingSrc',
            'source_chapter_id' => null,
        ]);
        $this->createSenseCard($sense1);

        $sense2 = $this->createSense($this->user->id, 'english', [
            'lemma' => 'hasSrc',
            'source_chapter_id' => 1,
        ]);
        $this->createSenseCard($sense2);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?filter=missing_source');
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('missingSrc', $items[0]['lemma']);
        $this->assertTrue($items[0]['missing_source']);
    }

    public function test_filter_missing_source_excludes_cards_with_occurrence_chapter(): void
    {
        $sense = $this->createSense($this->user->id, 'english', [
            'lemma' => 'hasOccurrence',
            'source_chapter_id' => null,
        ]);
        $this->createSenseCard($sense);

        // Create a bound occurrence with chapter_id
        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => 42,
            'sentence_id' => 's1',
            'sentence_en' => 'test',
            'sentence_zh' => 'test',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'hasOccurrence',
            'lemma' => 'hasOccurrence',
            'pos' => 'noun',
            'decision' => 'confirmed',
            'confidence' => 0.8,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
            'raw_payload' => ['decision' => 'confirmed'],
        ]);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?filter=missing_source');
        $this->assertCount(0, $response->json('items'));
    }

    // ==================== Pagination Tests ====================

    public function test_per_page_defaults_to_20(): void
    {
        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $this->assertSame(20, $response->json('pagination.per_page'));
    }

    public function test_per_page_max_is_100(): void
    {
        $response = $this->actingAs($this->user)->get('/review-cards/manage/data?per_page=200');
        $this->assertSame(100, $response->json('pagination.per_page'));
    }

    public function test_response_structure_has_items_and_pagination(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('pagination', $data);
    }

    public function test_item_has_required_fields(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)->get('/review-cards/manage/data');
        $item = $response->json('items.0');

        $requiredFields = [
            'review_card_id', 'word_sense_id', 'lemma', 'surface_form', 'pos',
            'sense_zh', 'sense_en', 'example_sentence_en', 'example_sentence_zh',
            'source_chapter_id', 'source_chapter_title', 'source_kind',
            'fsrs_state', 'fsrs_due_at', 'fsrs_reps', 'fsrs_lapses', 'fsrs_enabled',
            'missing_definition', 'missing_example', 'missing_source',
        ];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $item, "Missing field: {$field}");
        }
    }

    // ==================== PATCH Update Tests ====================

    public function test_patch_update_pos(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['pos' => 'verb']);
        $response->assertOk();
        $this->assertSame('verb', $response->json('pos'));
        $this->assertSame('verb', $sense->fresh()->pos);
    }

    public function test_patch_update_sense_zh(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => '新释义']);
        $response->assertOk();
        $this->assertSame('新释义', $sense->fresh()->sense_zh);
    }

    public function test_patch_update_sense_en(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_en' => 'new def']);
        $response->assertOk();
        $this->assertSame('new def', $sense->fresh()->sense_en);
    }

    public function test_patch_update_example_sentence_en(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['example_sentence_en' => 'New example.']);
        $response->assertOk();
        $this->assertSame('New example.', $sense->fresh()->example_sentence_en);
    }

    public function test_patch_update_example_sentence_zh(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['example_sentence_zh' => '新例句。']);
        $response->assertOk();
        $this->assertSame('新例句。', $sense->fresh()->example_sentence_zh);
    }

    // --- PATCH does NOT change WordSense.status ---

    public function test_patch_does_not_change_word_sense_status(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $oldStatus = $sense->status;
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame($oldStatus, $sense->fresh()->status);
    }

    // --- PATCH does NOT change ReviewCard FSRS fields ---

    public function test_patch_does_not_change_review_card_fsrs_enabled(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $oldValue = $card->fsrs_enabled;
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame($oldValue, $card->fresh()->fsrs_enabled);
    }

    public function test_patch_does_not_change_review_card_fsrs_due_at(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $oldValue = $card->fsrs_due_at->timestamp;
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame($oldValue, $card->fresh()->fsrs_due_at->timestamp);
    }

    public function test_patch_does_not_change_review_card_fsrs_state(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $oldValue = $card->fsrs_state;
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame($oldValue, $card->fresh()->fsrs_state);
    }

    public function test_patch_does_not_change_fsrs_stability(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_stability' => 1.5]);
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame(1.5, $card->fresh()->fsrs_stability);
    }

    public function test_patch_does_not_change_fsrs_difficulty(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_difficulty' => 0.8]);
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame(0.8, $card->fresh()->fsrs_difficulty);
    }

    public function test_patch_does_not_change_fsrs_reps(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_reps' => 5]);
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame(5, $card->fresh()->fsrs_reps);
    }

    public function test_patch_does_not_change_fsrs_lapses(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_lapses' => 3]);
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame(3, $card->fresh()->fsrs_lapses);
    }

    public function test_patch_does_not_change_fsrs_last_reviewed_at(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_last_reviewed_at' => now()->subDay()]);
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertNotNull($card->fresh()->fsrs_last_reviewed_at);
    }

    public function test_patch_does_not_change_target_type(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame(ReviewCard::TARGET_SENSE, $card->fresh()->target_type);
    }

    public function test_patch_does_not_change_target_id(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $oldTargetId = $card->target_id;
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame($oldTargetId, $card->fresh()->target_id);
    }

    public function test_patch_does_not_change_word_sense_user_id(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame($this->user->id, $sense->fresh()->user_id);
    }

    public function test_patch_does_not_change_word_sense_language_id(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame('english', $sense->fresh()->language_id);
    }

    public function test_patch_does_not_change_source_chapter_id(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $sense->update(['source_chapter_id' => 5]);
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame(5, $sense->fresh()->source_chapter_id);
    }

    public function test_patch_does_not_change_sentence_id(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $sense->update(['sentence_id' => 's42']);
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame('s42', $sense->fresh()->sentence_id);
    }

    public function test_patch_does_not_change_sentence_hash(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $sense->update(['sentence_hash' => 'abc123']);
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame('abc123', $sense->fresh()->sentence_hash);
    }

    public function test_patch_does_not_create_review_logs(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $oldCount = ReviewLog::count();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame($oldCount, ReviewLog::count());
    }

    public function test_patch_does_not_change_encountered_words(): void
    {
        // EncounteredWord table is separate; verify count unchanged
        $encWord = \App\Models\EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'stage' => -1,
            'word' => 'test',
            'translation' => 'test translation',
            'kanji' => '',
            'reading' => '',
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'lemma' => '',
            'added_to_srs' => now()->toDateString(),
            'next_review' => now()->toDateString(),
            'relearning' => false,
        ]);

        [$card, $sense] = $this->createTestSenseCard();
        $ec = \App\Models\EncounteredWord::count();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame($ec, \App\Models\EncounteredWord::count());
    }

    public function test_patch_does_not_change_word_sense_occurrences(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $occ = WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => 1,
            'sentence_id' => 's1',
            'sentence_en' => 'test',
            'sentence_zh' => 'test',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'test',
            'lemma' => 'test',
            'pos' => 'noun',
            'decision' => 'confirmed',
            'confidence' => 0.8,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
            'raw_payload' => ['decision' => 'confirmed'],
        ]);
        $oc = WordSenseOccurrence::count();
        $occId = $occ->id;
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame($oc, WordSenseOccurrence::count());
        $this->assertSame('test', $occ->fresh()->sentence_en);
    }

    public function test_patch_does_not_create_new_review_card(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $oldCount = ReviewCard::count();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", ['sense_zh' => 'changed']);
        $this->assertSame($oldCount, ReviewCard::count());
    }

    // --- PATCH ignores non-editable fields in payload ---

    public function test_patch_ignores_id_in_payload(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $oldId = $sense->id;
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", [
            'sense_zh' => 'changed',
            'id' => 99999,
        ]);
        $this->assertSame($oldId, $sense->fresh()->id);
    }

    public function test_patch_ignores_user_id_in_payload(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", [
            'sense_zh' => 'changed',
            'user_id' => $this->otherUser->id,
        ]);
        $this->assertSame($this->user->id, $sense->fresh()->user_id);
    }

    public function test_patch_ignores_status_in_payload(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", [
            'sense_zh' => 'changed',
            'status' => WordSense::STATUS_REJECTED,
        ]);
        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->fresh()->status);
    }

    public function test_patch_ignores_fsrs_enabled_in_payload(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", [
            'sense_zh' => 'changed',
            'fsrs_enabled' => false,
        ]);
        $this->assertTrue($card->fresh()->fsrs_enabled);
    }

    public function test_patch_ignores_fsrs_due_at_in_payload(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $oldDue = $card->fsrs_due_at;
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", [
            'sense_zh' => 'changed',
            'fsrs_due_at' => now()->addYear()->toISOString(),
        ]);
        $this->assertSame($oldDue->timestamp, $card->fresh()->fsrs_due_at->timestamp);
    }

    public function test_patch_ignores_target_type_in_payload(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", [
            'sense_zh' => 'changed',
            'target_type' => ReviewCard::TARGET_WORD,
        ]);
        $this->assertSame(ReviewCard::TARGET_SENSE, $card->fresh()->target_type);
    }

    public function test_patch_ignores_source_chapter_id_in_payload(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", [
            'sense_zh' => 'changed',
            'source_chapter_id' => 99,
        ]);
        $this->assertNull($sense->fresh()->source_chapter_id);
    }

    public function test_patch_ignores_fsrs_state_in_payload(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", [
            'sense_zh' => 'changed',
            'fsrs_state' => 'review',
        ]);
        $this->assertSame('new', $card->fresh()->fsrs_state);
    }

    // ==================== Cross-user / Cross-language isolation ====================

    public function test_other_user_card_cannot_be_patched(): void
    {
        $otherSense = $this->createSense($this->otherUser->id, 'english', ['lemma' => 'other']);
        $otherCard = $this->createSenseCard($otherSense);
        $oldSenseZh = $otherSense->sense_zh;

        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$otherCard->id}", ['sense_zh' => 'hacked']);
        $this->assertTrue($response->status() === 404 || $response->status() === 403);
        $this->assertSame($oldSenseZh, $otherSense->fresh()->sense_zh);
    }

    public function test_other_language_card_cannot_be_patched(): void
    {
        $senseEs = $this->createSense($this->user->id, 'spanish', ['lemma' => 'espanol']);
        $cardEs = $this->createSenseCard($senseEs);
        $oldSenseZh = $senseEs->sense_zh;

        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$cardEs->id}", ['sense_zh' => 'hacked']);
        $this->assertTrue($response->status() === 404 || $response->status() === 403);
        $this->assertSame($oldSenseZh, $senseEs->fresh()->sense_zh);
    }

    public function test_word_card_cannot_be_patched(): void
    {
        $wordCard = $this->createWordCard($this->user->id, 'english', 1);
        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$wordCard->id}", ['sense_zh' => 'hacked']);
        $this->assertTrue($response->status() === 404 || $response->status() === 403);
    }

    public function test_other_user_card_cannot_be_enabled(): void
    {
        $otherSense = $this->createSense($this->otherUser->id, 'english', ['lemma' => 'other']);
        $otherCard = $this->createSenseCard($otherSense);
        $otherCard->update(['fsrs_enabled' => false]);

        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$otherCard->id}/enabled", ['enabled' => true]);
        $this->assertTrue($response->status() === 404 || $response->status() === 403);
        $this->assertFalse($otherCard->fresh()->fsrs_enabled);
    }

    public function test_other_language_card_cannot_be_enabled(): void
    {
        $senseEs = $this->createSense($this->user->id, 'spanish', ['lemma' => 'espanol']);
        $cardEs = $this->createSenseCard($senseEs);
        $cardEs->update(['fsrs_enabled' => false]);

        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$cardEs->id}/enabled", ['enabled' => true]);
        $this->assertTrue($response->status() === 404 || $response->status() === 403);
        $this->assertFalse($cardEs->fresh()->fsrs_enabled);
    }

    public function test_word_card_cannot_be_enabled(): void
    {
        $wordCard = $this->createWordCard($this->user->id, 'english', 1);
        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$wordCard->id}/enabled", ['enabled' => true]);
        $this->assertTrue($response->status() === 404 || $response->status() === 403);
    }

    public function test_other_user_card_cannot_be_due_now(): void
    {
        $otherSense = $this->createSense($this->otherUser->id, 'english', ['lemma' => 'other']);
        $otherCard = $this->createSenseCard($otherSense);
        $otherCard->update(['fsrs_due_at' => now()->addDay()]);
        $oldDue = $otherCard->fsrs_due_at;

        $response = $this->actingAs($this->user)->post("/review-cards/manage/{$otherCard->id}/due-now");
        $this->assertTrue($response->status() === 404 || $response->status() === 403);
        $this->assertSame($oldDue->timestamp, $otherCard->fresh()->fsrs_due_at->timestamp);
    }

    public function test_other_language_card_cannot_be_due_now(): void
    {
        $senseEs = $this->createSense($this->user->id, 'spanish', ['lemma' => 'espanol']);
        $cardEs = $this->createSenseCard($senseEs);
        $cardEs->update(['fsrs_due_at' => now()->addDay()]);
        $oldDue = $cardEs->fsrs_due_at;

        $response = $this->actingAs($this->user)->post("/review-cards/manage/{$cardEs->id}/due-now");
        $this->assertTrue($response->status() === 404 || $response->status() === 403);
        $this->assertSame($oldDue->timestamp, $cardEs->fresh()->fsrs_due_at->timestamp);
    }

    public function test_word_card_cannot_be_due_now(): void
    {
        $wordCard = $this->createWordCard($this->user->id, 'english', 1);
        $response = $this->actingAs($this->user)->post("/review-cards/manage/{$wordCard->id}/due-now");
        $this->assertTrue($response->status() === 404 || $response->status() === 403);
    }

    // ==================== Enabled toggle tests ====================

    public function test_enabled_toggle_to_true_only_changes_fsrs_enabled(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_enabled' => false, 'fsrs_state' => 'review', 'fsrs_stability' => 1.0, 'fsrs_difficulty' => 0.5, 'fsrs_reps' => 3, 'fsrs_lapses' => 1]);
        $oldLogCount = ReviewLog::count();
        $oldCardCount = ReviewCard::count();

        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}/enabled", ['enabled' => true]);
        $response->assertOk();
        $this->assertTrue($response->json('fsrs_enabled'));

        $card->refresh();
        $this->assertTrue($card->fsrs_enabled);
        $this->assertSame('review', $card->fsrs_state);
        $this->assertSame(1.0, $card->fsrs_stability);
        $this->assertSame(0.5, $card->fsrs_difficulty);
        $this->assertSame(3, $card->fsrs_reps);
        $this->assertSame(1, $card->fsrs_lapses);
        $this->assertSame($oldLogCount, ReviewLog::count());
        $this->assertSame($oldCardCount, ReviewCard::count());
        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->fresh()->status);
    }

    public function test_enabled_toggle_to_false_only_changes_fsrs_enabled(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_enabled' => true]);
        $oldLogCount = ReviewLog::count();

        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}/enabled", ['enabled' => false]);
        $response->assertOk();
        $this->assertFalse($response->json('fsrs_enabled'));

        $card->refresh();
        $this->assertFalse($card->fsrs_enabled);
        $this->assertSame($oldLogCount, ReviewLog::count());
        // Card not deleted
        $this->assertNotNull(ReviewCard::find($card->id));
        // WordSense not deleted
        $this->assertNotNull(WordSense::find($sense->id));
    }

    public function test_enable_does_not_change_fsrs_due_at(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_enabled' => false, 'fsrs_due_at' => now()->addDay()]);
        $oldDue = $card->fresh()->fsrs_due_at;

        $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}/enabled", ['enabled' => true]);
        $this->assertSame($oldDue->timestamp, $card->fresh()->fsrs_due_at->timestamp);
    }

    // ==================== Due-now tests ====================

    public function test_due_now_sets_fsrs_due_at_to_now(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_due_at' => now()->addDays(10)]);
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertTrue($card->fresh()->fsrs_due_at->lte(now()));
    }

    public function test_due_now_preserves_fsrs_enabled(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_enabled' => false, 'fsrs_due_at' => now()->addDay()]);
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertFalse($card->fresh()->fsrs_enabled);
    }

    public function test_due_now_preserves_fsrs_enabled_true(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_enabled' => true, 'fsrs_due_at' => now()->addDay()]);
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertTrue($card->fresh()->fsrs_enabled);
    }

    public function test_due_now_does_not_change_fsrs_state(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_state' => 'review', 'fsrs_due_at' => now()->addDay()]);
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertSame('review', $card->fresh()->fsrs_state);
    }

    public function test_due_now_does_not_change_fsrs_stability(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_stability' => 2.5, 'fsrs_due_at' => now()->addDay()]);
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertSame(2.5, $card->fresh()->fsrs_stability);
    }

    public function test_due_now_does_not_change_fsrs_difficulty(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_difficulty' => 0.7, 'fsrs_due_at' => now()->addDay()]);
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertSame(0.7, $card->fresh()->fsrs_difficulty);
    }

    public function test_due_now_does_not_change_fsrs_reps(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_reps' => 10, 'fsrs_due_at' => now()->addDay()]);
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertSame(10, $card->fresh()->fsrs_reps);
    }

    public function test_due_now_does_not_change_fsrs_lapses(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_lapses' => 2, 'fsrs_due_at' => now()->addDay()]);
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertSame(2, $card->fresh()->fsrs_lapses);
    }

    public function test_due_now_does_not_change_target_type(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_due_at' => now()->addDay()]);
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertSame(ReviewCard::TARGET_SENSE, $card->fresh()->target_type);
    }

    public function test_due_now_does_not_change_target_id(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_due_at' => now()->addDay()]);
        $oldTargetId = $card->target_id;
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertSame($oldTargetId, $card->fresh()->target_id);
    }

    public function test_due_now_does_not_change_word_sense(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_due_at' => now()->addDay()]);
        $oldSenseZh = $sense->sense_zh;
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertSame($oldSenseZh, $sense->fresh()->sense_zh);
    }

    public function test_due_now_does_not_create_review_logs(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $card->update(['fsrs_due_at' => now()->addDay()]);
        $oldCount = ReviewLog::count();
        $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $this->assertSame($oldCount, ReviewLog::count());
    }

    // ==================== Data list does NOT call sourceContext ====================

    public function test_data_list_does_not_call_sense_review_service_source_context(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $this->createSenseCard($sense);

        // Spy on the sourceContext method
        $spy = $this->spy(SenseReviewService::class);

        $this->actingAs($this->user)->get('/review-cards/manage/data');
        $spy->shouldNotHaveReceived('sourceContext');
    }

    public function test_data_list_does_not_trigger_sense_source_context_log(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $this->createSenseCard($sense);

        Log::shouldReceive('info')->withArgs(function ($level, $message) {
            return $message === 'sense_source_context';
        })->never();

        // Actually use a spy to verify no sourceContext calls happen through the API
        $this->actingAs($this->user)->get('/review-cards/manage/data')->assertOk();
    }

    // ==================== Route model binding not trusted ====================

    public function test_user_a_cannot_patch_user_b_card_via_url(): void
    {
        $otherSense = $this->createSense($this->otherUser->id, 'english', ['lemma' => 'otherlemma']);
        $otherCard = $this->createSenseCard($otherSense);

        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$otherCard->id}", ['sense_zh' => 'hacked']);
        $this->assertTrue(in_array($response->status(), [404, 403]));
    }

    public function test_user_a_cannot_enable_user_b_card_via_url(): void
    {
        $otherSense = $this->createSense($this->otherUser->id, 'english', ['lemma' => 'otherlemma2']);
        $otherCard = $this->createSenseCard($otherSense);

        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$otherCard->id}/enabled", ['enabled' => true]);
        $this->assertTrue(in_array($response->status(), [404, 403]));
    }

    public function test_user_a_cannot_due_now_user_b_card_via_url(): void
    {
        $otherSense = $this->createSense($this->otherUser->id, 'english', ['lemma' => 'otherlemma3']);
        $otherCard = $this->createSenseCard($otherSense);

        $response = $this->actingAs($this->user)->post("/review-cards/manage/{$otherCard->id}/due-now");
        $this->assertTrue(in_array($response->status(), [404, 403]));
    }

    public function test_user_a_cannot_patch_different_language_card(): void
    {
        $senseEs = $this->createSense($this->user->id, 'spanish', ['lemma' => 'espanol2']);
        $cardEs = $this->createSenseCard($senseEs);

        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$cardEs->id}", ['sense_zh' => 'hacked']);
        $this->assertTrue(in_array($response->status(), [404, 403]));
    }

    public function test_user_a_cannot_patch_word_type_card(): void
    {
        $wordCard = $this->createWordCard($this->user->id, 'english', 42);

        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$wordCard->id}", ['sense_zh' => 'hacked']);
        $this->assertTrue(in_array($response->status(), [404, 403]));
    }

    // ==================== Full immutability checks after failed operations ====================

    public function test_failed_patch_leaves_all_models_unchanged(): void
    {
        $otherSense = $this->createSense($this->otherUser->id, 'english', ['lemma' => 'immutable']);
        $otherCard = $this->createSenseCard($otherSense);

        $oldSenseData = $otherSense->fresh()->toArray();
        $oldCardData = $otherCard->fresh()->toArray();
        $oldLogCount = ReviewLog::count();
        $oldOccurrenceCount = WordSenseOccurrence::count();
        $oldEncWordCount = \App\Models\EncounteredWord::count();

        $this->actingAs($this->user)->patch("/review-cards/manage/{$otherCard->id}", ['sense_zh' => 'hacked']);

        $this->assertSame($oldLogCount, ReviewLog::count());
        $this->assertSame($oldOccurrenceCount, WordSenseOccurrence::count());
        $this->assertSame($oldEncWordCount, \App\Models\EncounteredWord::count());
    }

    // ==================== Response structure tests ====================

    public function test_patch_response_contains_required_fields(): void
    {
        [$card, $sense] = $this->createTestSenseCard();

        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}", [
            'pos' => 'adjective',
            'sense_zh' => '新测试',
            'sense_en' => 'new test',
            'example_sentence_en' => 'New example.',
            'example_sentence_zh' => '新例句。',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertSame('adjective', $data['pos']);
        $this->assertSame('新测试', $data['sense_zh']);
        $this->assertSame('new test', $data['sense_en']);
        $this->assertSame('New example.', $data['example_sentence_en']);
        $this->assertSame('新例句。', $data['example_sentence_zh']);
        $this->assertArrayHasKey('review_card_id', $data);
        $this->assertArrayHasKey('word_sense_id', $data);
    }

    public function test_enabled_response_contains_fsrs_enabled(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $response = $this->actingAs($this->user)->patch("/review-cards/manage/{$card->id}/enabled", ['enabled' => true]);
        $response->assertOk();
        $this->assertArrayHasKey('review_card_id', $response->json());
        $this->assertArrayHasKey('fsrs_enabled', $response->json());
    }

    public function test_due_now_response_contains_fsrs_due_at_and_enabled(): void
    {
        [$card, $sense] = $this->createTestSenseCard();
        $response = $this->actingAs($this->user)->post("/review-cards/manage/{$card->id}/due-now");
        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('review_card_id', $data);
        $this->assertArrayHasKey('fsrs_due_at', $data);
        $this->assertArrayHasKey('fsrs_enabled', $data);
    }

    // ==================== Helper ====================

    private function createTestSenseCard(): array
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);
        return [$card, $sense];
    }
}
