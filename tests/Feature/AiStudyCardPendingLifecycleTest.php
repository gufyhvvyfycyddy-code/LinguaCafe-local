<?php

namespace Tests\Feature;

use App\Models\AiStudyCardPendingItem;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GM52-AIStudyCardPendingLifecycleClosure-1000-5
 *
 * Pending item lifecycle closure tests.
 *
 * After a user generates study cards from the V5 generate-cards flow,
 * the source user_selected pending items must be marked as `processed`
 * so they leave the default pending view and do not create noise.
 *
 * Lifecycle rules frozen in this round:
 *   1. user_selected + created  → pending item status = processed
 *   2. user_selected + duplicate → pending item status = processed
 *   3. user_selected + skipped  → pending item stays pending
 *   4. user_selected + failed   → pending item stays pending
 *   5. ai_recommended + created → no pending item modified
 *   6. ai_recommended + duplicate → no pending item modified
 *   7. dismissed pending item   → cannot be marked processed
 *   8. cross-user pending item  → cannot be marked processed
 *   9. cross-language pending item → cannot be marked processed
 *  10. processed does not write ReviewLog
 *  11. processed does not reschedule existing FSRS cards
 *  12. processed does not create legacy word ReviewCard
 *  13. processed items queryable via ?status=processed
 *  14. generate-cards response contains pending_item_processed / pending_item_status_after
 *  15. repeat generate-cards is idempotent (no duplicate cards, no error)
 *
 * Safety boundaries:
 *   - No AI called.
 *   - No ReviewLog written.
 *   - No FSRS rescheduled.
 *   - No legacy word ReviewCard created.
 *   - No WordSense / ReviewCard / ReviewLog deleted.
 */
class AiStudyCardPendingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('lifecycle-user@example.test', 'english');
        $this->otherUser = $this->createUser('lifecycle-other@example.test', 'english');
        $this->chapter = $this->createChapter($this->user, 'english');
    }

    // ===== 1. user_selected + created → processed =====

    public function test_user_selected_created_marks_pending_item_as_processed(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.summary.created_count', 1);

        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_PROCESSED,
        ]);

        $created = $response->json('results.created.0');
        $this->assertTrue($created['pending_item_processed']);
        $this->assertSame('pending', $created['pending_item_status_before']);
        $this->assertSame('processed', $created['pending_item_status_after']);
        $this->assertSame('created', $created['pending_item_process_reason']);
        $this->assertSame($itemId, $created['pending_item_id']);
    }

    // ===== 2. user_selected + duplicate → processed =====

    public function test_user_selected_duplicate_marks_pending_item_as_processed(): void
    {
        // Pre-create a confirmed WordSense + ReviewCard for the same word/lemma/sense_zh
        // so that generate-cards finds it as a duplicate.
        $wordSenseService = app(WordSenseService::class);
        $existingSense = $wordSenseService->createOrFindSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'landscape',
            'surface_form' => 'landscape',
            'pos' => null,
            'sense_zh' => '风景',
            'sense_en' => null,
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $wordSenseService->createReviewCardForSense($existingSense);

        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.summary.duplicate_count', 1);
        $response->assertJsonPath('results.summary.created_count', 0);

        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_PROCESSED,
        ]);

        $duplicate = $response->json('results.duplicate.0');
        $this->assertTrue($duplicate['pending_item_processed']);
        $this->assertSame('processed', $duplicate['pending_item_status_after']);
        $this->assertSame('duplicate', $duplicate['pending_item_process_reason']);
    }

    // ===== 3. user_selected + failed → stays pending =====

    public function test_user_selected_failed_keeps_pending_item_as_pending(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // Mock WordSenseService to throw, forcing the failed path.
        $this->mock(WordSenseService::class, function ($mock) {
            $mock->shouldReceive('createOrFindSense')
                ->andThrow(new \RuntimeException('forced failure for lifecycle test'));
        });

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.summary.failed_count', 1);
        $response->assertJsonPath('results.summary.created_count', 0);

        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);

        $failed = $response->json('results.failed.0');
        $this->assertFalse($failed['pending_item_processed']);
    }

    // ===== 4. user_selected + skipped → stays pending =====

    public function test_user_selected_skipped_keeps_pending_item_as_pending(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // Trigger skipped via invalid_chapter: item_id is valid and in the package,
        // word/lemma match, but chapter_id is invalid → skipped with 'invalid_chapter'.
        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => 999999, // invalid chapter → skipped
                    'sense_zh' => '风景',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.summary.skipped_count', 1);
        $response->assertJsonPath('results.summary.created_count', 0);

        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);

        $skipped = $response->json('results.skipped.0');
        $this->assertFalse($skipped['pending_item_processed']);
    }

    // ===== 5. ai_recommended + created → no pending item modified =====

    public function test_ai_recommended_created_does_not_modify_pending_item(): void
    {
        // Create a pending item to verify it is NOT modified by ai_recommended flow.
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $pkg = $this->finalCandidatesPackageAiOnly([
            ['word' => 'agency', 'lemma' => 'agency', 'surface' => 'agency', 'reason' => '推荐', 'sentence_text' => 'xxx'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $pkg,
            'confirmed_items' => [
                [
                    'source' => 'ai_recommended',
                    'word' => 'agency',
                    'lemma' => 'agency',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '代理；机构',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.summary.created_count', 1);

        // The pending item should still be pending (not processed)
        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);

        // ai_recommended item should have empty lifecycle info
        $created = $response->json('results.created.0');
        $this->assertFalse($created['pending_item_processed']);
        $this->assertNull($created['pending_item_id']);
        $this->assertNull($created['pending_item_status_after']);
    }

    // ===== 6. ai_recommended + duplicate → no pending item modified =====

    public function test_ai_recommended_duplicate_does_not_modify_pending_item(): void
    {
        // Pre-create a sense for 'agency' so the ai_recommended item is a duplicate.
        $wordSenseService = app(WordSenseService::class);
        $existingSense = $wordSenseService->createOrFindSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'agency',
            'surface_form' => 'agency',
            'pos' => null,
            'sense_zh' => '代理；机构',
            'sense_en' => null,
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $wordSenseService->createReviewCardForSense($existingSense);

        // Also create a pending item to verify it is NOT modified.
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $pkg = $this->finalCandidatesPackageAiOnly([
            ['word' => 'agency', 'lemma' => 'agency', 'surface' => 'agency', 'reason' => '推荐', 'sentence_text' => 'xxx'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $pkg,
            'confirmed_items' => [
                [
                    'source' => 'ai_recommended',
                    'word' => 'agency',
                    'lemma' => 'agency',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '代理；机构',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.summary.duplicate_count', 1);

        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);

        $duplicate = $response->json('results.duplicate.0');
        $this->assertFalse($duplicate['pending_item_processed']);
        $this->assertNull($duplicate['pending_item_id']);
    }

    // ===== 7. dismissed pending item cannot be marked processed =====

    public function test_dismissed_pending_item_not_marked_processed(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // Dismiss it first
        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/' . $itemId . '/dismiss')
            ->assertOk();

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ]);

        $response->assertOk();
        // The dismissed item is not in validPendingItems (which filters status=pending),
        // so it should be skipped with 'invalid_pending_item'.
        $response->assertJsonPath('results.summary.skipped_count', 1);
        $response->assertJsonPath('results.summary.created_count', 0);

        // The item should still be dismissed (NOT processed)
        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_DISMISSED,
        ]);
        $this->assertDatabaseMissing('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_PROCESSED,
        ]);
    }

    // ===== 8. cross-user pending item cannot be processed =====

    public function test_cross_user_pending_item_not_marked_processed(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $this->app['session']->flush();
        $response = $this->actingAs($this->otherUser)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id, // also not otherUser's
                    'sense_zh' => '风景',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.summary.skipped_count', 1);
        $response->assertJsonPath('results.summary.created_count', 0);

        // The original user's pending item should still be pending (not processed by otherUser)
        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'user_id' => $this->user->id,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);
    }

    // ===== 9. cross-language pending item cannot be processed =====

    public function test_cross_language_pending_item_not_marked_processed(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // Switch to spanish
        $this->user->selected_language = 'spanish';
        $this->user->save();

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.summary.skipped_count', 1);
        $response->assertJsonPath('results.summary.created_count', 0);

        // The English pending item should still be pending
        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'language_id' => 'english',
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);
    }

    // ===== 10. processed does not write ReviewLog =====

    public function test_processed_does_not_write_review_log(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $before = ReviewLog::count();

        $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        $this->assertSame($before, ReviewLog::count(), 'Processing pending item must not write ReviewLog.');
    }

    // ===== 11. processed does not reschedule existing FSRS cards =====

    public function test_processed_does_not_reschedule_existing_fsrs_cards(): void
    {
        $existingSense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'existing-fsrs',
            'surface_form' => 'existing-fsrs',
            'sense_key' => hash('sha256', 'english|existing-fsrs||存在||'),
            'sense_zh' => '存在',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
        ]);
        $existingCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $existingSense->id,
            'fsrs_state' => 'review',
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 0.3,
            'fsrs_due_at' => '2099-01-01 00:00:00',
            'fsrs_reps' => 7,
            'fsrs_lapses' => 1,
            'fsrs_enabled' => true,
        ]);

        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        $existingCard->refresh();
        $this->assertSame('review', $existingCard->fsrs_state);
        $this->assertSame(5.0, (float) $existingCard->fsrs_stability);
        $this->assertSame(0.3, (float) $existingCard->fsrs_difficulty);
        $this->assertSame(7, (int) $existingCard->fsrs_reps);
        $this->assertSame(1, (int) $existingCard->fsrs_lapses);
        $this->assertTrue((bool) $existingCard->fsrs_enabled);
    }

    // ===== 12. processed does not create legacy word ReviewCard =====

    public function test_processed_does_not_create_legacy_word_review_card(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $beforeWordCards = ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count();

        $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        $afterWordCards = ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count();
        $this->assertSame($beforeWordCards, $afterWordCards, 'Processing must not create legacy word ReviewCard.');
    }

    // ===== 13. processed items queryable via ?status=processed =====

    public function test_processed_items_queryable_via_status_processed(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // Before generate-cards: no processed items
        $this->actingAs($this->user)->getJson('/ai-study-card/pending-items?status=processed')
            ->assertOk()
            ->assertJsonCount(0, 'items');

        // Generate cards → pending item becomes processed
        $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        // After generate-cards: 1 processed item
        $response = $this->actingAs($this->user)->getJson('/ai-study-card/pending-items?status=processed')
            ->assertOk()
            ->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.id', $itemId);
        $response->assertJsonPath('items.0.status', AiStudyCardPendingItem::STATUS_PROCESSED);
        $response->assertJsonPath('items.0.word', 'landscape');

        // Default pending list should no longer contain it
        $this->actingAs($this->user)->getJson('/ai-study-card/pending-items')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    // ===== 14. generate-cards response contains lifecycle fields =====

    public function test_generate_cards_response_contains_lifecycle_fields(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'results' => [
                'created' => [
                    '*' => [
                        'pending_item_id',
                        'pending_item_status_before',
                        'pending_item_status_after',
                        'pending_item_processed',
                        'pending_item_process_reason',
                    ],
                ],
                'skipped' => [
                    '*' => [
                        'pending_item_id',
                        'pending_item_processed',
                    ],
                ],
                'duplicate' => [
                    '*' => [
                        'pending_item_id',
                        'pending_item_status_after',
                        'pending_item_processed',
                    ],
                ],
                'failed' => [
                    '*' => [
                        'pending_item_id',
                        'pending_item_processed',
                    ],
                ],
            ],
        ]);
    }

    // ===== 15. repeat generate-cards is idempotent =====

    public function test_repeat_generate_cards_is_idempotent(): void
    {
        $create = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();
        $itemId = $create->json('item.id');

        // First call: created, pending item → processed
        $first = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        $first->assertJsonPath('results.summary.created_count', 1);
        $first->assertJsonPath('results.summary.skipped_count', 0);

        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_PROCESSED,
        ]);

        $beforeSenseCount = WordSense::where('user_id', $this->user->id)->where('lemma', 'landscape')->count();
        $beforeCardCount = ReviewCard::where('user_id', $this->user->id)->where('target_type', ReviewCard::TARGET_SENSE)->count();

        // Second call: pending item is now processed, not in validPendingItems → skipped (idempotent)
        $second = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $this->finalCandidatesPackage($itemId, $this->chapter->id),
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '风景',
                ],
            ],
        ])->assertOk();

        $second->assertJsonPath('results.summary.created_count', 0);
        $second->assertJsonPath('results.summary.skipped_count', 1);
        $second->assertJsonPath('results.summary.duplicate_count', 0);

        // No duplicate cards created
        $this->assertSame($beforeSenseCount, WordSense::where('user_id', $this->user->id)->where('lemma', 'landscape')->count());
        $this->assertSame($beforeCardCount, ReviewCard::where('user_id', $this->user->id)->where('target_type', ReviewCard::TARGET_SENSE)->count());

        // Item is still processed (not re-processed, not reverted)
        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'id' => $itemId,
            'status' => AiStudyCardPendingItem::STATUS_PROCESSED,
        ]);
    }

    // ===== Helpers =====

    private function finalCandidatesPackage(int $itemId, ?int $chapterId, array $aiRecommendedSelected = []): array
    {
        return [
            'schema_version' => 'ai-study-card-final-candidates-v1',
            'user_selected_items' => [
                [
                    'item_id' => $itemId,
                    'chapter_id' => $chapterId,
                    'text_block_index' => 0,
                    'sentence_index' => 0,
                    'word' => 'landscape',
                    'normalized_word' => 'landscape',
                    'surface' => 'landscape',
                    'lemma' => 'landscape',
                    'sentence_text' => 'The intellectual landscape changed quickly.',
                    'status' => 'pending',
                    'source' => 'user_selected',
                ],
            ],
            'ai_recommended_selected_items' => $aiRecommendedSelected,
            'ai_recommended_unselected_items' => [],
            'dedupe_summary' => [],
            'generation_rules' => [],
            'safety_flags' => [],
        ];
    }

    private function finalCandidatesPackageAiOnly(array $aiRecommendedSelected): array
    {
        return [
            'schema_version' => 'ai-study-card-final-candidates-v1',
            'user_selected_items' => [],
            'ai_recommended_selected_items' => $aiRecommendedSelected,
            'ai_recommended_unselected_items' => [],
            'dedupe_summary' => [],
            'generation_rules' => [],
            'safety_flags' => [],
        ];
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
            'name' => 'Lifecycle Test User',
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
            'name' => "Lifecycle {$language} Book",
            'language' => $language,
        ]);

        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => "Lifecycle {$language} Chapter",
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
