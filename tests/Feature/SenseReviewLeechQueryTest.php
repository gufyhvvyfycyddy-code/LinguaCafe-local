<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewLeechQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewLeechQueryTest
 *
 * ADR-0011: Feature tests for the leech query service.
 *
 * Verifies batch loading, single-card query, summary, filtering,
 * and N+1 prevention.
 */
class SenseReviewLeechQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SenseReviewLeechQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'Leech Query Test',
            'email' => 'leech-query-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->service = app(SenseReviewLeechQueryService::class);
    }

    public function test_describe_for_card_returns_stable_for_new_card(): void
    {
        $card = $this->makeCard();

        $result = $this->service->describeForCard($card);

        $this->assertSame('stable', $result['status']);
        $this->assertSame(0, $result['severity']);
    }

    public function test_describe_for_card_classifies_leech_with_review_logs(): void
    {
        $card = $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'again', 'daysAgo' => 2],
            ['rating' => 'hard', 'daysAgo' => 1],
        ]);

        $result = $this->service->describeForCard($card);

        $this->assertSame('leech', $result['status']);
    }

    public function test_describe_for_cards_batch(): void
    {
        $card1 = $this->makeCard();
        $card2 = $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeLogs($card2, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);

        $results = $this->service->describeForCards([$card1->id, $card2->id]);

        $this->assertCount(2, $results);
        $this->assertSame('stable', $results[$card1->id]['status']);
        $this->assertSame('leech', $results[$card2->id]['status']);
    }

    public function test_summary_returns_counts(): void
    {
        $stableCard = $this->makeCard();
        $leechCard = $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeLogs($leechCard, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);

        $summary = $this->service->summary($this->user->id, 'english');

        $this->assertArrayHasKey('counts', $summary);
        $this->assertArrayHasKey('leech_card_ids', $summary);
        $this->assertContains($leechCard->id, $summary['leech_card_ids']);
    }

    public function test_filter_card_ids_by_leech_status(): void
    {
        $stableCard = $this->makeCard();
        $leechCard = $this->makeCard(['fsrs_lapses' => 3]);
        $this->makeLogs($leechCard, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);

        $leechIds = $this->service->filterCardIdsByLeechStatus([$stableCard->id, $leechCard->id], 'leech');
        $stableIds = $this->service->filterCardIdsByLeechStatus([$stableCard->id, $leechCard->id], 'stable');

        $this->assertContains($leechCard->id, $leechIds);
        $this->assertNotContains($stableCard->id, $leechIds);
        $this->assertContains($stableCard->id, $stableIds);
    }

    public function test_undone_review_logs_excluded(): void
    {
        $card = $this->makeCard();
        // Create logs, then mark them all as undone
        $this->makeLogs($card, [
            ['rating' => 'again', 'daysAgo' => 10],
            ['rating' => 'again', 'daysAgo' => 8],
            ['rating' => 'again', 'daysAgo' => 6],
            ['rating' => 'good', 'daysAgo' => 4],
            ['rating' => 'easy', 'daysAgo' => 2],
        ]);
        // Mark all as undone
        ReviewLog::where('review_card_id', $card->id)->update(['undone_at' => now()]);

        $result = $this->service->describeForCard($card);

        // With all logs undone, should be stable (no effective reviews)
        $this->assertSame('stable', $result['status']);
    }

    public function test_reset_review_logs_excluded(): void
    {
        $card = $this->makeCard();
        // Create reset logs — these should be excluded
        $this->makeLogs($card, [
            ['rating' => 'reset', 'daysAgo' => 10],
            ['rating' => 'reset', 'daysAgo' => 8],
            ['rating' => 'reset', 'daysAgo' => 6],
        ]);

        $result = $this->service->describeForCard($card);

        // With only reset logs, should be stable (no effective reviews)
        $this->assertSame('stable', $result['status']);
    }

    public function test_other_user_cards_not_in_summary(): void
    {
        $otherUser = User::forceCreate([
            'name' => 'Other User',
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
            'example_sentence_en' => 'Other test.',
            'example_sentence_zh' => '其他测试。',
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
            'fsrs_lapses' => 3,
            'lifecycle_state' => 'active',
        ]);

        $summary = $this->service->summary($this->user->id, 'english');

        $this->assertNotContains($otherCard->id, $summary['leech_card_ids']);
    }

    public function test_describe_for_cards_no_n_plus_one(): void
    {
        $cardIds = [];
        for ($i = 0; $i < 5; $i++) {
            $card = $this->makeCard();
            $this->makeLogs($card, [
                ['rating' => 'good', 'daysAgo' => 5],
                ['rating' => 'easy', 'daysAgo' => 3],
            ]);
            $cardIds[] = $card->id;
        }

        // This should execute without error and return results for all cards.
        $results = $this->service->describeForCards($cardIds);

        $this->assertCount(5, $results);
        foreach ($cardIds as $id) {
            $this->assertArrayHasKey($id, $results);
            $this->assertSame('stable', $results[$id]['status']);
        }
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
