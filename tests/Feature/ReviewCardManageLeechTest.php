<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ReviewCardManageLeechTest
 *
 * ADR-0011: Feature tests for leech integration in the management page.
 *
 * Verifies:
 *  - leech/struggling filters work
 *  - leech fields are included in items when include_leech=true
 *  - leech-summary endpoint returns counts
 *  - bulk-leech-rewrite-packages endpoint works
 *  - HTTP routes are accessible
 */
class ReviewCardManageLeechTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'Manage Leech Test',
            'email' => 'manage-leech-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_leech_filter_returns_leech_cards(): void
    {
        $leechCard = $this->makeCardWithLeechHistory();
        $stableCard = $this->makeCard();

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1');

        $response->assertStatus(200);
        $items = $response->json('items');
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($leechCard->id, $cardIds);
        $this->assertNotContains($stableCard->id, $cardIds);
    }

    public function test_struggling_filter_returns_struggling_cards(): void
    {
        $strugglingCard = $this->makeCardWithStrugglingHistory();

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=struggling&include_leech=1');

        $response->assertStatus(200);
        $items = $response->json('items');
        // Should include the struggling card (fsrs_lapses >= 2)
        $cardIds = array_column($items, 'review_card_id');
        $this->assertContains($strugglingCard->id, $cardIds);
    }

    public function test_include_leech_adds_leech_fields(): void
    {
        $card = $this->makeCardWithLeechHistory();

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=leech&include_leech=1');

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertNotEmpty($items);

        $item = $items[0];
        $this->assertArrayHasKey('leech_status', $item);
        $this->assertArrayHasKey('leech_severity', $item);
        $this->assertArrayHasKey('leech_reasons', $item);
        $this->assertArrayHasKey('leech_suggestions', $item);
    }

    public function test_without_include_leech_no_leech_fields(): void
    {
        $this->makeCard();

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=learning');

        $response->assertStatus(200);
        $items = $response->json('items');
        if (!empty($items)) {
            $this->assertArrayNotHasKey('leech_status', $items[0]);
        }
    }

    public function test_leech_summary_endpoint(): void
    {
        $this->makeCardWithLeechHistory();
        $this->makeCard();

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/leech-summary');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'counts' => ['stable', 'struggling', 'leech'],
            'leech_card_ids',
            'struggling_card_ids',
        ]);
    }

    public function test_bulk_rewrite_packages_endpoint(): void
    {
        $card1 = $this->makeCardWithLeechHistory();
        $card2 = $this->makeCard();

        $response = $this->actingAs($this->user)
            ->postJson('/review-cards/manage/bulk-leech-rewrite-packages', [
                'ids' => [$card1->id, $card2->id],
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'packages',
            'failed',
            'provider_called',
            'card_created',
            'review_log_created',
        ]);
        $this->assertFalse($response->json('provider_called'));
        $this->assertFalse($response->json('card_created'));
        $this->assertFalse($response->json('review_log_created'));
    }

    public function test_single_card_leech_endpoint(): void
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

    public function test_single_card_rewrite_package_endpoint(): void
    {
        $card = $this->makeCardWithLeechHistory();

        $response = $this->actingAs($this->user)
            ->postJson("/reviews/senses/{$card->id}/leech/rewrite-package");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'schema_version',
            'package',
            'markdown',
            'json',
            'provider_called',
            'card_created',
            'review_log_created',
        ]);
        $this->assertFalse($response->json('provider_called'));
    }

    public function test_leech_endpoint_404_for_other_user_card(): void
    {
        $otherUser = User::forceCreate([
            'name' => 'Other',
            'email' => 'other-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $otherSense = WordSense::forceCreate([
            'user_id' => $otherUser->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'other' . Str::random(4),
            'surface_form' => 'other',
            'pos' => 'noun',
            'sense_zh' => '其他',
            'sense_en' => 'other',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Other.',
            'example_sentence_zh' => '其他。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower('english|other|noun|其他|other')),
        ]);
        $otherCard = ReviewCard::forceCreate([
            'user_id' => $otherUser->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherSense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'lifecycle_state' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$otherCard->id}/leech");

        $response->assertStatus(404);
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

    private function makeCardWithLeechHistory(): ReviewCard
    {
        $card = $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);
        return $card;
    }

    private function makeCardWithStrugglingHistory(): ReviewCard
    {
        $card = $this->makeCard(['fsrs_lapses' => 2]);
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 5],
            ['rating' => 'hard', 'daysAgo' => 4],
            ['rating' => 'again', 'daysAgo' => 3],
            ['rating' => 'good', 'daysAgo' => 2],
            ['rating' => 'easy', 'daysAgo' => 1],
        ]);
        return $card;
    }

    private function makeLogs(ReviewCard $card, array $ratings): void
    {
        foreach ($ratings as $r) {
            ReviewLog::create([
                'user_id' => $card->user_id,
                'language_id' => $card->language_id,
                'language' => $card->language,
                'review_card_id' => $card->id,
                'rating' => $r['rating'],
                'reviewed_at' => now()->subDays($r['daysAgo']),
                'previous_state' => 'review',
                'new_state' => 'review',
                'previous_due_at' => now()->subDays($r['daysAgo'] + 1),
                'new_due_at' => now()->subDays($r['daysAgo'] - 1),
                'previous_stability' => 1.0,
                'new_stability' => 1.5,
                'previous_difficulty' => 5.0,
                'new_difficulty' => 5.0,
                'source' => $r['rating'] === 'reset' ? 'reset' : 'sense_review',
            ]);
        }
    }
}
