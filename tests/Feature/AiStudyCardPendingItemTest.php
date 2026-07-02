<?php

namespace Tests\Feature;

use App\Models\AiStudyCardPendingItem;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiStudyCardPendingItemTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('ai-pending-user@example.test', 'english');
        $this->otherUser = $this->createUser('ai-pending-other@example.test', 'english');
        $this->chapter = $this->createChapter($this->user, 'english');
    }

    public function test_logged_in_user_can_create_pending_item(): void
    {
        $response = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload());

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('created', true);
        $response->assertJsonPath('item.word', 'landscape');

        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
            'text_block_index' => 0,
            'sentence_index' => 0,
            'word' => 'landscape',
            'normalized_word' => 'landscape',
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);
    }

    public function test_unauthenticated_user_cannot_create_pending_item(): void
    {
        $this->postJson('/ai-study-card/pending-items', $this->payload())
            ->assertUnauthorized();

        $this->assertSame(0, AiStudyCardPendingItem::count());
    }

    public function test_user_isolation_rejects_other_users_chapter(): void
    {
        $response = $this->actingAs($this->otherUser)->postJson('/ai-study-card/pending-items', $this->payload());

        $response->assertStatus(404);
        $this->assertSame(0, AiStudyCardPendingItem::count());
    }

    public function test_language_isolation_uses_current_selected_language(): void
    {
        $spanishChapter = $this->createChapter($this->user, 'spanish');

        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items', $this->payload(['chapter_id' => $spanishChapter->id]))
            ->assertStatus(404);

        $this->user->selected_language = 'spanish';
        $this->user->save();

        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items', $this->payload(['chapter_id' => $spanishChapter->id]))
            ->assertOk();

        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'user_id' => $this->user->id,
            'language_id' => 'spanish',
            'chapter_id' => $spanishChapter->id,
        ]);
        $this->assertDatabaseMissing('ai_study_card_pending_items', [
            'language_id' => 'english',
            'chapter_id' => $spanishChapter->id,
        ]);
    }

    public function test_duplicate_click_does_not_create_unlimited_rows(): void
    {
        $first = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload());
        $second = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload());

        $first->assertOk()->assertJsonPath('created', true);
        $second->assertOk()->assertJsonPath('created', false);

        $this->assertSame(1, AiStudyCardPendingItem::where([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
            'text_block_index' => 0,
            'normalized_word' => 'landscape',
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ])->count());
    }

    public function test_pending_item_creation_does_not_create_learning_or_review_data(): void
    {
        $before = [
            'word_senses' => WordSense::count(),
            'review_cards' => ReviewCard::count(),
            'review_logs' => ReviewLog::count(),
            'encountered_words' => EncounteredWord::count(),
            'word_sense_occurrences' => WordSenseOccurrence::count(),
        ];

        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();

        $this->assertSame($before['word_senses'], WordSense::count());
        $this->assertSame($before['review_cards'], ReviewCard::count());
        $this->assertSame($before['review_logs'], ReviewLog::count());
        $this->assertSame($before['encountered_words'], EncounteredWord::count());
        $this->assertSame($before['word_sense_occurrences'], WordSenseOccurrence::count());
    }

    public function test_existing_sense_and_review_card_state_is_unchanged(): void
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'existing',
            'surface_form' => 'existing',
            'pos' => 'noun',
            'sense_key' => 'existing-key',
            'sense_zh' => '已有释义',
            'sense_en' => 'existing sense',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
        ]);

        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_stability' => 4.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 2,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDay(),
            'fsrs_enabled' => true,
        ]);

        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();

        $sense->refresh();
        $card->refresh();

        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->status);
        $this->assertSame('review', $card->fsrs_state);
        $this->assertSame(2, $card->fsrs_reps);
        $this->assertTrue((bool) $card->fsrs_enabled);
    }

    // ===== V2 tests: list / dismiss / restore / re-mark =====

    public function test_logged_in_user_can_list_own_pending_items(): void
    {
        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();

        $response = $this->actingAs($this->user)->getJson('/ai-study-card/pending-items');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.word', 'landscape');
        $response->assertJsonPath('items.0.status', AiStudyCardPendingItem::STATUS_PENDING);
    }

    public function test_unauthenticated_user_cannot_list_pending_items(): void
    {
        $this->getJson('/ai-study-card/pending-items')->assertUnauthorized();
    }

    public function test_user_isolation_on_list(): void
    {
        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();

        // other user should not see this user's pending items
        // 切换用户前清空 session，避免 AuthenticateSession middleware 因 password_hash 不匹配而 401
        $this->app['session']->flush();
        $response = $this->actingAs($this->otherUser)->getJson('/ai-study-card/pending-items');

        $response->assertOk();
        $response->assertJsonCount(0, 'items');
    }

    public function test_list_filters_by_chapter_id(): void
    {
        $otherChapter = $this->createChapter($this->user, 'english');

        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload([
            'chapter_id' => $otherChapter->id,
            'word' => 'mountain',
        ]))->assertOk();

        // list all
        $this->actingAs($this->user)->getJson('/ai-study-card/pending-items')
            ->assertOk()
            ->assertJsonCount(2, 'items');

        // list filtered by original chapter
        $this->actingAs($this->user)->getJson('/ai-study-card/pending-items?chapter_id=' . $this->chapter->id)
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.word', 'landscape');
    }

    public function test_list_returns_404_for_other_users_chapter(): void
    {
        $otherUserChapter = $this->createChapter($this->otherUser, 'english');

        $this->actingAs($this->user)
            ->getJson('/ai-study-card/pending-items?chapter_id=' . $otherUserChapter->id)
            ->assertStatus(404);
    }

    public function test_list_only_returns_pending_not_dismissed(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // dismiss it
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertOk();

        // list should be empty (dismissed items not returned)
        $this->actingAs($this->user)->getJson('/ai-study-card/pending-items')
            ->assertOk()
            ->assertJsonCount(0, 'items');

        // still exists in DB as dismissed
        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_DISMISSED,
        ]);
    }

    public function test_language_isolation_on_list(): void
    {
        // create english pending item
        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();

        // switch to spanish — should not see english items
        $this->user->selected_language = 'spanish';
        $this->user->save();

        $this->actingAs($this->user)->getJson('/ai-study-card/pending-items')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_user_can_dismiss_own_pending_item(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $response = $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('item.status', AiStudyCardPendingItem::STATUS_DISMISSED);

        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_DISMISSED,
        ]);
    }

    public function test_user_cannot_dismiss_other_users_pending_item(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $this->app['session']->flush();
        $this->actingAs($this->otherUser)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertStatus(404);

        // still pending, not dismissed
        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);
    }

    public function test_dismiss_does_not_create_word_sense_or_review_card_or_review_log(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $before = [
            'word_senses' => WordSense::count(),
            'review_cards' => ReviewCard::count(),
            'review_logs' => ReviewLog::count(),
            'encountered_words' => EncounteredWord::count(),
            'word_sense_occurrences' => WordSenseOccurrence::count(),
        ];

        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertOk();

        $this->assertSame($before['word_senses'], WordSense::count());
        $this->assertSame($before['review_cards'], ReviewCard::count());
        $this->assertSame($before['review_logs'], ReviewLog::count());
        $this->assertSame($before['encountered_words'], EncounteredWord::count());
        $this->assertSame($before['word_sense_occurrences'], WordSenseOccurrence::count());
    }

    public function test_dismiss_is_idempotent(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // dismiss twice — second should still return success
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertOk();

        $this->assertSame(1, AiStudyCardPendingItem::where('id', $itemId)->count());
    }

    public function test_dismissed_item_can_be_re_marked_via_restore(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // dismiss
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertOk();

        // restore
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/restore')
            ->assertOk()
            ->assertJsonPath('item.status', AiStudyCardPendingItem::STATUS_PENDING);

        // should appear in list again
        $this->actingAs($this->user)->getJson('/ai-study-card/pending-items')
            ->assertOk()
            ->assertJsonCount(1, 'items');

        // still only 1 row, not 2
        $this->assertSame(1, AiStudyCardPendingItem::where('normalized_word', 'landscape')->count());
    }

    public function test_dismissed_item_is_re_activated_when_re_marked_via_store(): void
    {
        // V2: re-clicking "待 AI 解释" on a dismissed word should restore the dismissed row
        // rather than creating a new pending row, avoiding duplicate history rows.
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $originalId = $create->json('item.id');

        // dismiss
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $originalId . '/dismiss')
            ->assertOk();

        // re-mark same word via store
        $reMark = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload());
        $reMark->assertOk();
        $reMark->assertJsonPath('created', false);
        $reMark->assertJsonPath('item.id', $originalId);
        $reMark->assertJsonPath('item.status', AiStudyCardPendingItem::STATUS_PENDING);

        // only 1 row in DB (restored, not duplicated)
        $this->assertSame(1, AiStudyCardPendingItem::where('normalized_word', 'landscape')->count());
    }

    public function test_restore_does_not_create_learning_data(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertOk();

        $before = [
            'word_senses' => WordSense::count(),
            'review_cards' => ReviewCard::count(),
            'review_logs' => ReviewLog::count(),
            'encountered_words' => EncounteredWord::count(),
        ];

        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/restore')
            ->assertOk();

        $this->assertSame($before['word_senses'], WordSense::count());
        $this->assertSame($before['review_cards'], ReviewCard::count());
        $this->assertSame($before['review_logs'], ReviewLog::count());
        $this->assertSame($before['encountered_words'], EncounteredWord::count());
    }

    public function test_user_cannot_restore_other_users_item(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertOk();

        $this->app['session']->flush();
        $this->actingAs($this->otherUser)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/restore')
            ->assertStatus(404);
    }

    public function test_dismiss_or_restore_does_not_touch_existing_sense_and_card_state(): void
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'existing',
            'surface_form' => 'existing',
            'pos' => 'noun',
            'sense_key' => 'existing-key',
            'sense_zh' => '已有释义',
            'sense_en' => 'existing sense',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
        ]);

        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_stability' => 4.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 2,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDay(),
            'fsrs_enabled' => true,
        ]);

        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // dismiss then restore
        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')->assertOk();
        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items/' . $itemId . '/restore')->assertOk();

        $sense->refresh();
        $card->refresh();

        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->status);
        $this->assertSame('review', $card->fsrs_state);
        $this->assertSame(2, $card->fsrs_reps);
        $this->assertTrue((bool) $card->fsrs_enabled);
    }

    // ===== V3 tests: dismissed list / preview-package / reverse contracts =====

    public function test_list_with_status_dismissed_returns_only_dismissed_items(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // dismiss it
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertOk();

        // status=dismissed should return the dismissed item
        $response = $this->actingAs($this->user)->getJson('/ai-study-card/pending-items?status=dismissed');
        $response->assertOk();
        $response->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.status', AiStudyCardPendingItem::STATUS_DISMISSED);
        $response->assertJsonPath('items.0.word', 'landscape');
    }

    public function test_list_with_status_all_returns_both_pending_and_dismissed(): void
    {
        $create1 = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $create2 = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload([
            'word' => 'mountain',
            'text_block_index' => 1,
        ]))->assertOk();

        // dismiss the first one
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $create1->json('item.id') . '/dismiss')
            ->assertOk();

        // status=all should return both
        $response = $this->actingAs($this->user)->getJson('/ai-study-card/pending-items?status=all');
        $response->assertOk();
        $response->assertJsonCount(2, 'items');
    }

    public function test_list_with_status_dismissed_respects_user_isolation(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $create->json('item.id') . '/dismiss')
            ->assertOk();

        // other user should not see dismissed items
        $this->app['session']->flush();
        $response = $this->actingAs($this->otherUser)->getJson('/ai-study-card/pending-items?status=dismissed');
        $response->assertOk();
        $response->assertJsonCount(0, 'items');
    }

    public function test_list_with_status_dismissed_respects_language_isolation(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $create->json('item.id') . '/dismiss')
            ->assertOk();

        // switch to spanish — should not see english dismissed items
        $this->user->selected_language = 'spanish';
        $this->user->save();

        $this->actingAs($this->user)->getJson('/ai-study-card/pending-items?status=dismissed')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_restore_via_list_returns_to_pending(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // dismiss
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertOk();

        // verify dismissed list has it
        $this->actingAs($this->user)->getJson('/ai-study-card/pending-items?status=dismissed')
            ->assertOk()
            ->assertJsonCount(1, 'items');

        // restore
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/restore')
            ->assertOk()
            ->assertJsonPath('item.status', AiStudyCardPendingItem::STATUS_PENDING);

        // verify pending list has it again
        $this->actingAs($this->user)->getJson('/ai-study-card/pending-items?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'items');

        // verify dismissed list no longer has it
        $this->actingAs($this->user)->getJson('/ai-study-card/pending-items?status=dismissed')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_preview_package_generates_safe_package_for_own_pending_items(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items/preview-package', [
            'item_ids' => [$itemId],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'message',
            'package' => [
                'schema_version',
                'created_at',
                'selected_items',
                'generation_rules',
                'safety_flags',
            ],
        ]);

        $package = $response->json('package');
        $this->assertSame('ai-study-card-preview-package-v1', $package['schema_version']);
        $this->assertCount(1, $package['selected_items']);
        $this->assertSame($itemId, $package['selected_items'][0]['item_id']);
        $this->assertSame('landscape', $package['selected_items'][0]['word']);

        // safety flags
        $this->assertTrue($package['safety_flags']['no_ai_called']);
        $this->assertTrue($package['safety_flags']['no_review_card_created']);
        $this->assertTrue($package['safety_flags']['no_word_sense_created']);
        $this->assertTrue($package['safety_flags']['no_fsrs_changed']);

        // generation rules
        $this->assertTrue($package['generation_rules']['no_auto_review_card']);
        $this->assertTrue($package['generation_rules']['ai_recommended_default_unchecked']);
        $this->assertTrue($package['generation_rules']['ai_recommended_exclude_user_selected']);
        $this->assertTrue($package['generation_rules']['user_confirmation_required_before_generation']);
    }

    public function test_preview_package_unauthenticated_user_cannot_generate(): void
    {
        $this->postJson('/ai-study-card/pending-items/preview-package', [
            'item_ids' => [1],
        ])->assertUnauthorized();
    }

    public function test_preview_package_user_isolation_rejects_other_users_items(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // other user tries to package this user's item
        $this->app['session']->flush();
        $response = $this->actingAs($this->otherUser)->postJson('/ai-study-card/pending-items/preview-package', [
            'item_ids' => [$itemId],
        ]);

        // should fail (no valid items found)
        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_preview_package_excludes_dismissed_items(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // dismiss it
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertOk();

        // try to package a dismissed item — should fail (no valid pending items)
        $response = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items/preview-package', [
            'item_ids' => [$itemId],
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_preview_package_language_isolation(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // switch to spanish — should not be able to package english items
        $this->user->selected_language = 'spanish';
        $this->user->save();

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items/preview-package', [
            'item_ids' => [$itemId],
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_preview_package_empty_item_ids_returns_validation_error(): void
    {
        $response = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items/preview-package', [
            'item_ids' => [],
        ]);

        $response->assertStatus(422);
    }

    public function test_preview_package_does_not_create_learning_data(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $before = [
            'word_senses' => WordSense::count(),
            'review_cards' => ReviewCard::count(),
            'review_logs' => ReviewLog::count(),
            'encountered_words' => EncounteredWord::count(),
            'word_sense_occurrences' => WordSenseOccurrence::count(),
        ];

        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items/preview-package', [
            'item_ids' => [$itemId],
        ])->assertOk();

        $this->assertSame($before['word_senses'], WordSense::count());
        $this->assertSame($before['review_cards'], ReviewCard::count());
        $this->assertSame($before['review_logs'], ReviewLog::count());
        $this->assertSame($before['encountered_words'], EncounteredWord::count());
        $this->assertSame($before['word_sense_occurrences'], WordSenseOccurrence::count());
    }

    public function test_preview_package_does_not_change_pending_item_status(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items/preview-package', [
            'item_ids' => [$itemId],
        ])->assertOk();

        // item should still be pending
        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);
    }

    public function test_preview_package_with_multiple_items(): void
    {
        $create1 = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $create2 = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload([
            'word' => 'mountain',
            'text_block_index' => 1,
        ]))->assertOk();

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items/preview-package', [
            'item_ids' => [$create1->json('item.id'), $create2->json('item.id')],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertCount(2, $response->json('package.selected_items'));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'chapter_id' => $this->chapter->id,
            'text_block_index' => 0,
            'sentence_index' => 0,
            'sentence_id' => '0',
            'word' => 'landscape',
            'surface' => 'landscape',
            'lemma' => 'landscape',
            'sentence_text' => 'The intellectual landscape changed quickly.',
            'source_payload' => [
                'source' => 'test',
            ],
        ], $overrides);
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => 'AI Study Card Pending User',
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    private function createChapter(User $user, string $language): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => "Pending {$language} Book",
            'language' => $language,
        ]);

        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => "Pending {$language} Chapter",
            'language' => $language,
            'raw_text' => 'The intellectual landscape changed quickly.',
            'word_count' => 5,
            'read_count' => 0,
            'unique_words' => '["the","intellectual","landscape","changed","quickly"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }
}
