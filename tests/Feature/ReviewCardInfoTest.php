<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewCardStateEvent;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ReviewCardInfoTest
 *
 * ADR-0014 — Review Card Info Read Model.
 *
 * Tests the converged canonical detail endpoint
 * (GET /review-cards/manage/{reviewCard}/detail) which returns the existing
 * top-level serializeCard() fields PLUS an additive "card_info" object
 * aggregating recent review logs, lifecycle events, and the leech descriptor.
 *
 * Contract invariants:
 *  - Old top-level fields preserved unchanged (additive only).
 *  - card_info.review_logs.items shape byte-identical to /logs endpoint.
 *  - card_info.lifecycle_events.items shape byte-identical to /lifecycle-events endpoint.
 *  - card_info.leech shape byte-identical to /leech endpoint's "leech" field.
 *  - Access control reuses ReviewCardManageAccessService (user/language/sense-only).
 *  - No ReviewLog write, no FSRS mutation, no lifecycle mutation.
 *  - Query budget: O(1) per request, no N+1 with log/event count.
 */
class ReviewCardInfoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Card Info User',
            'email' => 'cardinfo@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Card Info Other',
            'email' => 'cardinfo-other@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    // ─── Helpers ───

    private function createSense(int $userId, string $language, array $overrides = []): WordSense
    {
        $lemma = $overrides['lemma'] ?? 'info-' . Str::random(4);
        $senseZh = $overrides['sense_zh'] ?? '信息';
        $senseEn = $overrides['sense_en'] ?? 'info';
        $data = array_merge([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => $senseZh,
            'sense_en' => $senseEn,
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is an info test.',
            'example_sentence_zh' => '这是一个信息测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("{$language}|{$lemma}|noun|{$senseZh}|{$senseEn}")),
        ], $overrides);

        return WordSense::forceCreate($data);
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now(),
            'fsrs_stability' => 1.5,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
            'lifecycle_state' => 'active',
        ], $overrides));
    }

    private function createLog(ReviewCard $card, array $overrides = []): ReviewLog
    {
        return ReviewLog::forceCreate(array_merge([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => 'good',
            'source' => 'sense_review',
            'reviewed_at' => now(),
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => now()->subDay(),
            'new_due_at' => now()->addDay(),
            'previous_stability' => 1.0,
            'new_stability' => 1.5,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 5.0,
        ], $overrides));
    }

    private function createEvent(ReviewCard $card, array $overrides = []): ReviewCardStateEvent
    {
        return ReviewCardStateEvent::forceCreate(array_merge([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'review_card_id' => $card->id,
            'action' => ReviewCardStateEvent::ACTION_BURY,
            'previous_state' => ['state' => 'active'],
            'new_state' => ['state' => 'buried'],
            'source' => 'manual',
            'request_id' => Str::uuid()->toString(),
            'created_at' => now(),
        ], $overrides));
    }

    private function makeCardWithLeechHistory(): ReviewCard
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense, ['fsrs_lapses' => 3]);
        $this->createLog($card, ['rating' => 'again', 'reviewed_at' => now()->subDays(10), 'source' => 'sense_review']);
        $this->createLog($card, ['rating' => 'again', 'reviewed_at' => now()->subDays(8), 'source' => 'sense_review']);
        $this->createLog($card, ['rating' => 'again', 'reviewed_at' => now()->subDays(6), 'source' => 'sense_review']);
        $this->createLog($card, ['rating' => 'good', 'reviewed_at' => now()->subDays(4), 'source' => 'sense_review']);
        $this->createLog($card, ['rating' => 'easy', 'reviewed_at' => now()->subDays(2), 'source' => 'sense_review']);
        return $card;
    }

    // ─── 1. detail continues to return existing top-level fields ───

    public function test_detail_returns_existing_top_level_fields(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'review_card_id',
            'word_sense_id',
            'lemma',
            'surface_form',
            'pos',
            'sense_zh',
            'sense_en',
            'example_sentence_en',
            'example_sentence_zh',
            'aliases_zh',
            'collocations',
            'source_chapter_id',
            'source_chapter_title',
            'source_kind',
            'source_display_status',
            'source_display_label',
            'fsrs_state',
            'fsrs_due_at',
            'fsrs_stability',
            'fsrs_difficulty',
            'fsrs_reps',
            'fsrs_lapses',
            'fsrs_last_reviewed_at',
            'fsrs_enabled',
            'lifecycle_state',
            'buried_until',
            'lifecycle_changed_at',
            'missing_definition',
            'missing_example',
            'missing_source',
        ]);
        $this->assertSame($card->id, $response->json('review_card_id'));
        $this->assertSame($sense->id, $response->json('word_sense_id'));
    }

    // ─── 2. detail additive returns card_info ───

    public function test_detail_additive_returns_card_info(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'card_info' => [
                'review_logs' => ['items', 'limit'],
                'lifecycle_events' => ['items', 'limit'],
                'leech',
            ],
        ]);
        $this->assertSame(20, $response->json('card_info.review_logs.limit'));
        $this->assertSame(20, $response->json('card_info.lifecycle_events.limit'));
    }

    // ─── 3. user isolation ───

    public function test_detail_user_isolation_returns_404_for_other_user_card(): void
    {
        $sense = $this->createSense($this->otherUser->id, 'english');
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $response->assertStatus(404);
    }

    // ─── 4. language isolation ───

    public function test_detail_language_isolation_returns_404_for_other_language(): void
    {
        $sense = $this->createSense($this->user->id, 'german');
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $response->assertStatus(404);
    }

    // ─── 5. sense-only ───

    public function test_detail_returns_404_for_legacy_word_card(): void
    {
        $wordCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 9999,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'lifecycle_state' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$wordCard->id}/detail");

        $response->assertStatus(404);
    }

    // ─── 6. archived card can be viewed ───

    public function test_detail_allows_archived_card(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense, ['fsrs_enabled' => false]);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $response->assertStatus(200);
        $this->assertFalse($response->json('fsrs_enabled'));
        $response->assertJsonStructure(['card_info']);
    }

    // ─── 7. rejected sense returns 404 ───

    public function test_detail_returns_404_for_rejected_sense(): void
    {
        $sense = $this->createSense($this->user->id, 'english', ['status' => WordSense::STATUS_REJECTED]);
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $response->assertStatus(404);
    }

    // ─── 8. deleted sense returns 404 ───

    public function test_detail_returns_404_for_deleted_sense(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);
        $sense->delete();

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $response->assertStatus(404);
    }

    // ─── 9. legacy word card returns 404 (covered above, explicit name) ───

    public function test_detail_returns_404_for_legacy_word_card_explicit(): void
    {
        $wordCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 8888,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'lifecycle_state' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$wordCard->id}/detail");

        $response->assertStatus(404);
    }

    // ─── 10. ReviewLog only returns current user/language/card ───

    public function test_review_logs_only_return_current_user_language_card(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        // Current user's log for this card
        $this->createLog($card, ['rating' => 'good', 'reviewed_at' => now()->subHour()]);

        // Other user's log for the SAME card id (should never happen in prod, but defensive)
        $this->createLog($card, [
            'user_id' => $this->otherUser->id,
            'rating' => 'again',
            'reviewed_at' => now(),
        ]);

        // Current user's log for a DIFFERENT card
        $otherSense = $this->createSense($this->user->id, 'english');
        $otherCard = $this->createSenseCard($otherSense);
        $this->createLog($otherCard, ['rating' => 'hard', 'reviewed_at' => now()]);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $response->assertStatus(200);
        $items = $response->json('card_info.review_logs.items');
        $this->assertCount(1, $items);
        $this->assertSame('good', $items[0]['rating']);
    }

    // ─── 11. ReviewLog sorted by reviewed_at DESC ───

    public function test_review_logs_sorted_by_reviewed_at_desc(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $old = $this->createLog($card, ['rating' => 'good', 'reviewed_at' => now()->subDays(3)]);
        $mid = $this->createLog($card, ['rating' => 'hard', 'reviewed_at' => now()->subDay()]);
        $new = $this->createLog($card, ['rating' => 'again', 'reviewed_at' => now()]);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $items = $response->json('card_info.review_logs.items');
        $this->assertCount(3, $items);
        $this->assertSame($new->id, $items[0]['id']);
        $this->assertSame($mid->id, $items[1]['id']);
        $this->assertSame($old->id, $items[2]['id']);
    }

    // ─── 12. ReviewLog limited to 20 ───

    public function test_review_logs_limited_to_20(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        for ($i = 0; $i < 25; $i++) {
            $this->createLog($card, [
                'rating' => 'good',
                'reviewed_at' => now()->subMinutes(100 - $i),
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $items = $response->json('card_info.review_logs.items');
        $this->assertCount(20, $items);
        $this->assertSame(20, $response->json('card_info.review_logs.limit'));
    }

    // ─── 13. undone/undone_at/undo_source fields complete ───

    public function test_review_log_undone_fields_complete(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $undoneLog = $this->createLog($card, [
            'rating' => 'again',
            'reviewed_at' => now()->subHour(),
            'undone_at' => now(),
            'undo_source' => 'user-manual',
        ]);
        $normalLog = $this->createLog($card, [
            'rating' => 'good',
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $items = $response->json('card_info.review_logs.items');
        $this->assertCount(2, $items);

        // Most recent first (normal log is newer)
        $this->assertSame($normalLog->id, $items[0]['id']);
        $this->assertFalse($items[0]['undone']);
        $this->assertNull($items[0]['undone_at']);
        $this->assertNull($items[0]['undo_source']);

        $this->assertSame($undoneLog->id, $items[1]['id']);
        $this->assertTrue($items[1]['undone']);
        $this->assertNotNull($items[1]['undone_at']);
        $this->assertSame('user-manual', $items[1]['undo_source']);
    }

    // ─── 14. lifecycle events scope correct ───

    public function test_lifecycle_events_scope_correct(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $this->createEvent($card, ['action' => ReviewCardStateEvent::ACTION_BURY, 'created_at' => now()->subHour()]);

        // Other user's event for same card id (defensive)
        ReviewCardStateEvent::forceCreate([
            'user_id' => $this->otherUser->id,
            'language_id' => 'english',
            'review_card_id' => $card->id,
            'action' => ReviewCardStateEvent::ACTION_SUSPEND,
            'previous_state' => ['state' => 'active'],
            'new_state' => ['state' => 'suspended'],
            'source' => 'manual',
            'request_id' => Str::uuid()->toString(),
            'created_at' => now(),
        ]);

        // Current user's event for a DIFFERENT card
        $otherSense = $this->createSense($this->user->id, 'english');
        $otherCard = $this->createSenseCard($otherSense);
        $this->createEvent($otherCard, ['action' => ReviewCardStateEvent::ACTION_ARCHIVE, 'created_at' => now()]);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $items = $response->json('card_info.lifecycle_events.items');
        $this->assertCount(1, $items);
        $this->assertSame(ReviewCardStateEvent::ACTION_BURY, $items[0]['action']);
    }

    // ─── 15. lifecycle events sorted by created_at DESC ───

    public function test_lifecycle_events_sorted_by_created_at_desc(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $old = $this->createEvent($card, ['action' => ReviewCardStateEvent::ACTION_BURY, 'created_at' => now()->subDays(3)]);
        $mid = $this->createEvent($card, ['action' => ReviewCardStateEvent::ACTION_UNBURY, 'created_at' => now()->subDay()]);
        $new = $this->createEvent($card, ['action' => ReviewCardStateEvent::ACTION_SUSPEND, 'created_at' => now()]);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $items = $response->json('card_info.lifecycle_events.items');
        $this->assertCount(3, $items);
        $this->assertSame($new->id, $items[0]['id']);
        $this->assertSame($mid->id, $items[1]['id']);
        $this->assertSame($old->id, $items[2]['id']);
    }

    // ─── 16. leech descriptor matches existing endpoint / Policy ───

    public function test_leech_descriptor_matches_existing_endpoint_shape(): void
    {
        $card = $this->makeCardWithLeechHistory();

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $response->assertStatus(200);
        $leech = $response->json('card_info.leech');
        $this->assertIsArray($leech);
        $this->assertArrayHasKey('status', $leech);
        $this->assertArrayHasKey('severity', $leech);
        $this->assertArrayHasKey('reasons', $leech);
        $this->assertArrayHasKey('suggestions', $leech);
        $this->assertArrayHasKey('blocked_actions', $leech);

        // Cross-check against the existing /leech endpoint
        $leechEndpointResponse = $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$card->id}/leech");
        $leechEndpointResponse->assertStatus(200);
        $this->assertSame(
            $leechEndpointResponse->json('leech'),
            $leech
        );
    }

    // ─── 17. query count does not grow with ReviewLog count ───

    public function test_query_count_does_not_grow_with_review_log_count(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        // Add 5 logs
        for ($i = 0; $i < 5; $i++) {
            $this->createLog($card, ['reviewed_at' => now()->subMinutes(100 - $i)]);
        }

        $queries5 = 0;
        \DB::listen(function () use (&$queries5) { $queries5++; });
        $this->actingAs($this->user)->getJson("/review-cards/manage/{$card->id}/detail");

        // Add 20 more logs (total 25, capped to 20 in response)
        for ($i = 0; $i < 20; $i++) {
            $this->createLog($card, ['reviewed_at' => now()->subMinutes(200 - $i)]);
        }

        $queries25 = 0;
        \DB::listen(function () use (&$queries25) { $queries25++; });
        $this->actingAs($this->user)->getJson("/review-cards/manage/{$card->id}/detail");

        // Query count should be roughly equal (O(1)), not 5x growth
        $this->assertLessThan(
            $queries5 * 2,
            $queries25,
            "Query count grew from {$queries5} to {$queries25} when log count went 5→25"
        );
    }

    // ─── 18. query count does not grow with lifecycle event count ───

    public function test_query_count_does_not_grow_with_lifecycle_event_count(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        for ($i = 0; $i < 3; $i++) {
            $this->createEvent($card, ['created_at' => now()->subMinutes(100 - $i)]);
        }

        $queries3 = 0;
        \DB::listen(function () use (&$queries3) { $queries3++; });
        $this->actingAs($this->user)->getJson("/review-cards/manage/{$card->id}/detail");

        for ($i = 0; $i < 20; $i++) {
            $this->createEvent($card, ['created_at' => now()->subMinutes(200 - $i)]);
        }

        $queries23 = 0;
        \DB::listen(function () use (&$queries23) { $queries23++; });
        $this->actingAs($this->user)->getJson("/review-cards/manage/{$card->id}/detail");

        $this->assertLessThan(
            $queries3 * 2,
            $queries23,
            "Query count grew from {$queries3} to {$queries23} when event count went 3→23"
        );
    }

    // ─── 19. detail request does not write ReviewLog ───

    public function test_detail_request_does_not_write_review_log(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);
        $this->createLog($card);

        $before = ReviewLog::count();

        $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $after = ReviewLog::count();
        $this->assertSame($before, $after, 'detail request must not write ReviewLog');
    }

    // ─── 20. detail request does not modify FSRS ───

    public function test_detail_request_does_not_modify_fsrs(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense, [
            'fsrs_stability' => 7.7,
            'fsrs_difficulty' => 4.4,
            'fsrs_reps' => 9,
            'fsrs_lapses' => 2,
        ]);

        $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $card->refresh();
        $this->assertSame(7.7, $card->fsrs_stability);
        $this->assertSame(4.4, $card->fsrs_difficulty);
        $this->assertSame(9, $card->fsrs_reps);
        $this->assertSame(2, $card->fsrs_lapses);
    }

    // ─── 21. detail request does not modify lifecycle ───

    public function test_detail_request_does_not_modify_lifecycle(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense, ['lifecycle_state' => 'suspended']);

        $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $card->refresh();
        $this->assertSame('suspended', $card->lifecycle_state);
    }

    // ─── 22. old /logs contract does not regress ───

    public function test_old_logs_endpoint_contract_unchanged(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);
        $this->createLog($card, ['rating' => 'easy', 'reviewed_at' => now()]);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/logs");

        $response->assertStatus(200);
        $item = $response->json('items.0');
        $this->assertSame([
            'id',
            'rating',
            'source',
            'reviewed_at',
            'previous_state',
            'new_state',
            'previous_due_at',
            'new_due_at',
            'previous_stability',
            'new_stability',
            'previous_difficulty',
            'new_difficulty',
            'undone',
            'undone_at',
            'undo_source',
        ], array_keys($item));
    }

    // ─── 23. old /lifecycle-events contract does not regress ───

    public function test_old_lifecycle_events_endpoint_contract_unchanged(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);
        $this->createEvent($card);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/{$card->id}/lifecycle-events");

        $response->assertStatus(200);
        $item = $response->json('items.0');
        $this->assertSame([
            'id',
            'action',
            'previous_state',
            'new_state',
            'source',
            'created_at',
            'request_id_prefix',
        ], array_keys($item));
    }

    // ─── 24. old /leech contract does not regress ───

    public function test_old_leech_endpoint_contract_unchanged(): void
    {
        $card = $this->makeCardWithLeechHistory();

        $response = $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$card->id}/leech");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'review_card_id',
            'leech' => ['status', 'severity', 'reasons', 'suggestions', 'blocked_actions'],
        ]);
    }

    // ─── Additional: card_info.review_logs.items shape byte-identical to /logs ───

    public function test_card_info_review_logs_items_shape_matches_old_logs_endpoint(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);
        $this->createLog($card, ['rating' => 'hard', 'reviewed_at' => now()]);

        $detail = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");
        $logs = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/logs");

        $detailItem = $detail->json('card_info.review_logs.items.0');
        $logsItem = $logs->json('items.0');

        $this->assertSame(array_keys($logsItem), array_keys($detailItem));
        $this->assertSame($logsItem, $detailItem);
    }

    // ─── Additional: card_info.lifecycle_events.items shape byte-identical to /lifecycle-events ───

    public function test_card_info_lifecycle_events_items_shape_matches_old_endpoint(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);
        $this->createEvent($card, ['created_at' => now()]);

        $detail = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");
        $events = $this->actingAs($this->user)
            ->getJson("/review-cards/{$card->id}/lifecycle-events");

        $detailItem = $detail->json('card_info.lifecycle_events.items.0');
        $eventsItem = $events->json('items.0');

        $this->assertSame(array_keys($eventsItem), array_keys($detailItem));
        $this->assertSame($eventsItem, $detailItem);
    }

    // ─── Additional: card_info.leech byte-identical to /leech endpoint ───

    public function test_card_info_leech_matches_old_leech_endpoint(): void
    {
        $card = $this->makeCardWithLeechHistory();

        $detail = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");
        $leech = $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$card->id}/leech");

        $this->assertSame($leech->json('leech'), $detail->json('card_info.leech'));
    }

    // ─── Additional: empty states ───

    public function test_card_info_empty_states_when_no_logs_no_events(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('card_info.review_logs.items'));
        $this->assertSame([], $response->json('card_info.lifecycle_events.items'));
        // leech should still be a descriptor (stable for a card with no review history)
        $this->assertIsArray($response->json('card_info.leech'));
        $this->assertSame('stable', $response->json('card_info.leech.status'));
    }
}
