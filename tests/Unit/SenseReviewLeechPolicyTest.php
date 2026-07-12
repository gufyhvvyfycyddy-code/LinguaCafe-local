<?php

namespace Tests\Unit;

use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SenseReviewLeechPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewLeechPolicyTest
 *
 * ADR-0011: Unit tests for the pure leech classification policy.
 *
 * The policy is a pure function — no DB queries, no side effects.
 * Tests verify classification thresholds, severity, reasons, suggestions,
 * and blocked_actions based on various input combinations.
 */
class SenseReviewLeechPolicyTest extends TestCase
{
    use RefreshDatabase;

    private SenseReviewLeechPolicy $policy;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(SenseReviewLeechPolicy::class);
        $this->user = User::forceCreate([
            'name' => 'Leech Policy Test',
            'email' => 'leech-policy-' . Str::uuid() . '@example.com',
            'password' => \Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    // ─── Stable classification ───

    public function test_new_card_with_no_reviews_is_stable(): void
    {
        $card = $this->makeCard();
        $feedback = $this->emptyFeedback();
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertSame('stable', $result['status']);
        $this->assertSame(0, $result['severity']);
        $this->assertEmpty($result['reasons']);
    }

    public function test_card_with_few_reviews_and_no_again_is_stable(): void
    {
        $card = $this->makeCard();
        $feedback = $this->feedback(3, 0, 0, 2, 1);
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertSame('stable', $result['status']);
    }

    // ─── Leech classification ───

    public function test_leech_by_again_count_threshold(): void
    {
        // again_count >= 3 AND total_reviews >= 5
        $card = $this->makeCard(['fsrs_lapses' => 3]);
        $feedback = $this->feedback(6, 4, 0, 1, 1);
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertSame('leech', $result['status']);
        $this->assertGreaterThan(0, $result['severity']);
        $this->assertContains('recent_again_count_high', $result['reasons']);
    }

    public function test_leech_by_recent_7_again_hard_threshold(): void
    {
        // last 7: (again+hard) >= 4
        $card = $this->makeCard();
        $feedback = $this->feedbackWithRecentReviews([
            ['rating' => 'again'], ['rating' => 'hard'],
            ['rating' => 'again'], ['rating' => 'hard'],
            ['rating' => 'good'], ['rating' => 'easy'],
        ], total: 6, again: 2, hard: 2, good: 1, easy: 1);
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertSame('leech', $result['status']);
    }

    public function test_leech_not_triggered_when_total_reviews_below_5(): void
    {
        // again_count=3 but total_reviews=4 (< 5)
        $card = $this->makeCard();
        $feedback = $this->feedback(4, 3, 0, 1, 0);
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        // Should NOT be leech by again_count (total < 5), but COULD be struggling
        $this->assertNotSame('leech', $result['status']);
    }

    // ─── Struggling classification ───

    public function test_struggling_by_recent_5_again_hard(): void
    {
        // last 5: (again+hard) >= 3, but not leech
        $card = $this->makeCard();
        $feedback = $this->feedbackWithRecentReviews([
            ['rating' => 'again'], ['rating' => 'hard'],
            ['rating' => 'again'], ['rating' => 'good'],
            ['rating' => 'easy'],
        ], total: 5, again: 2, hard: 1, good: 1, easy: 1);
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertSame('struggling', $result['status']);
    }

    public function test_struggling_by_lapses_and_declining_trend(): void
    {
        $card = $this->makeCard(['fsrs_lapses' => 2]);
        $feedback = $this->feedbackWithTrend('declining', total: 4, again: 1, hard: 0, good: 2, easy: 1);
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertSame('struggling', $result['status']);
        $this->assertContains('lapses_high', $result['reasons']);
        $this->assertContains('stability_declining', $result['reasons']);
    }

    // ─── Severity ───

    public function test_stable_severity_is_zero(): void
    {
        $card = $this->makeCard();
        $feedback = $this->emptyFeedback();
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertSame(0, $result['severity']);
    }

    public function test_leech_severity_higher_than_struggling(): void
    {
        $leechCard = $this->makeCard(['fsrs_lapses' => 4]);
        $leechFeedback = $this->feedback(7, 5, 1, 1, 0);
        $lifecycle = $this->activeLifecycle();
        $leechResult = $this->policy->classify($leechCard, $leechFeedback, $lifecycle);

        $strugglingCard = $this->makeCard(['fsrs_lapses' => 2]);
        $strugglingFeedback = $this->feedbackWithRecentReviews([
            ['rating' => 'again'], ['rating' => 'hard'],
            ['rating' => 'again'], ['rating' => 'good'],
            ['rating' => 'easy'],
        ], total: 5, again: 2, hard: 1, good: 1, easy: 1);
        $strugglingResult = $this->policy->classify($strugglingCard, $strugglingFeedback, $lifecycle);

        $this->assertSame('leech', $leechResult['status']);
        $this->assertSame('struggling', $strugglingResult['status']);
        $this->assertGreaterThan($strugglingResult['severity'], $leechResult['severity']);
    }

    // ─── Suggestions ───

    public function test_stable_suggests_continue_review(): void
    {
        $card = $this->makeCard();
        $feedback = $this->emptyFeedback();
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertContains('continue_review', $result['suggestions']);
    }

    public function test_leech_suggests_rewrite_and_suspend(): void
    {
        $card = $this->makeCard(['fsrs_lapses' => 3]);
        $feedback = $this->feedback(6, 4, 0, 1, 1);
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertContains('rewrite_example', $result['suggestions']);
        $this->assertContains('suspend_temporarily', $result['suggestions']);
        $this->assertContains('edit_sense', $result['suggestions']);
        $this->assertContains('view_history', $result['suggestions']);
    }

    public function test_struggling_does_not_suggest_suspend(): void
    {
        $card = $this->makeCard(['fsrs_lapses' => 2]);
        $feedback = $this->feedbackWithTrend('declining', total: 4, again: 1, hard: 0, good: 2, easy: 1);
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertNotContains('suspend_temporarily', $result['suggestions']);
    }

    // ─── Blocked actions ───

    public function test_suspended_card_blocks_suspend_suggestion(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'suspended', 'fsrs_lapses' => 3]);
        $feedback = $this->feedback(6, 4, 0, 1, 1);
        $lifecycle = $this->lifecycleWithState('suspended');

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertSame('leech', $result['status']);
        $this->assertContains('suspend_temporarily', $result['blocked_actions']);
    }

    public function test_archived_card_blocks_suspend_suggestion(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'archived', 'fsrs_lapses' => 3]);
        $feedback = $this->feedback(6, 4, 0, 1, 1);
        $lifecycle = $this->lifecycleWithState('archived');

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertContains('suspend_temporarily', $result['blocked_actions']);
    }

    public function test_active_card_does_not_block_suspend(): void
    {
        $card = $this->makeCard(['fsrs_lapses' => 3]);
        $feedback = $this->feedback(6, 4, 0, 1, 1);
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertNotContains('suspend_temporarily', $result['blocked_actions']);
    }

    // ─── Reasons ───

    public function test_reasons_include_lapses_high_when_lapses_ge_2(): void
    {
        $card = $this->makeCard(['fsrs_lapses' => 3]);
        $feedback = $this->feedback(6, 4, 0, 1, 1);
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertContains('lapses_high', $result['reasons']);
    }

    public function test_reasons_include_low_success_after_multiple_reviews(): void
    {
        // total=5, good=1, easy=0, success rate = 1/5 = 20% < 40%
        $card = $this->makeCard();
        $feedback = $this->feedbackWithRecentReviews([
            ['rating' => 'again'], ['rating' => 'again'],
            ['rating' => 'again'], ['rating' => 'hard'],
            ['rating' => 'good'],
        ], total: 5, again: 3, hard: 1, good: 1, easy: 0);
        $lifecycle = $this->activeLifecycle();

        $result = $this->policy->classify($card, $feedback, $lifecycle);

        $this->assertContains('low_success_after_multiple_reviews', $result['reasons']);
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

    private function emptyFeedback(): array
    {
        return [
            'total_reviews' => 0,
            'forget_count' => 0,
            'hard_count' => 0,
            'good_count' => 0,
            'easy_count' => 0,
            'recent_reviews' => [],
            'recent_forget_count' => 0,
            'forgetting_pattern' => [
                'total_forget' => 0,
                'recent_forget_count' => 0,
                'forget_rate' => 0.0,
                'last_forget_date' => null,
                'trend' => 'insufficient',
            ],
        ];
    }

    private function feedback(int $total, int $again, int $hard, int $good, int $easy): array
    {
        $recent = [];
        for ($i = 0; $i < min(5, $total); $i++) {
            $rating = $i < $again ? 'again' : ($i < $again + $hard ? 'hard' : ($i < $again + $hard + $good ? 'good' : 'easy'));
            $recent[] = ['rating' => $rating, 'rating_label' => $rating, 'date' => now()->subDays($i)->format('Y-m-d')];
        }

        return [
            'total_reviews' => $total,
            'forget_count' => $again,
            'hard_count' => $hard,
            'good_count' => $good,
            'easy_count' => $easy,
            'recent_reviews' => $recent,
            'recent_forget_count' => min(5, $again),
            'forgetting_pattern' => [
                'total_forget' => $again,
                'recent_forget_count' => min(5, $again),
                'forget_rate' => $total > 0 ? round($again / $total, 4) : 0.0,
                'last_forget_date' => $again > 0 ? now()->format('Y-m-d') : null,
                'trend' => 'stable',
            ],
        ];
    }

    private function feedbackWithRecentReviews(array $reviews, int $total, int $again, int $hard, int $good, int $easy): array
    {
        return [
            'total_reviews' => $total,
            'forget_count' => $again,
            'hard_count' => $hard,
            'good_count' => $good,
            'easy_count' => $easy,
            'recent_reviews' => $reviews,
            'recent_forget_count' => count(array_filter($reviews, fn($r) => $r['rating'] === 'again')),
            'forgetting_pattern' => [
                'total_forget' => $again,
                'recent_forget_count' => count(array_filter($reviews, fn($r) => $r['rating'] === 'again')),
                'forget_rate' => $total > 0 ? round($again / $total, 4) : 0.0,
                'last_forget_date' => $again > 0 ? now()->format('Y-m-d') : null,
                'trend' => 'stable',
            ],
        ];
    }

    private function feedbackWithTrend(string $trend, int $total, int $again, int $hard, int $good, int $easy): array
    {
        $f = $this->feedback($total, $again, $hard, $good, $easy);
        $f['forgetting_pattern']['trend'] = $trend;
        return $f;
    }

    private function activeLifecycle(): array
    {
        return [
            'effective_state' => 'active',
            'lifecycle_state' => 'active',
            'available_actions' => ['bury', 'suspend', 'archive'],
        ];
    }

    private function lifecycleWithState(string $state): array
    {
        return [
            'effective_state' => $state,
            'lifecycle_state' => $state,
            'available_actions' => $state === 'suspended' ? ['resume', 'archive'] : ['restore'],
        ];
    }
}
