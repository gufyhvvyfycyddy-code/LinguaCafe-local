<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewCardSavedSearch;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\StudyOverviewQueryService;
use Tests\TestCase;

class StudyOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));
        $this->user = User::forceCreate([
            'name' => 'Overview User', 'email' => 'overview@example.test', 'password' => Hash::make('password'),
            'selected_language' => 'english', 'password_changed' => true, 'is_admin' => true, 'uuid' => (string) Str::uuid(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_overview_returns_scope_due_memory_rating_time_and_retention_metrics(): void
    {
        $review = $this->card('review-card', 'review', now()->subDay(), now()->subDays(30));
        $this->card('new-card', 'new', now()->addDay(), null);
        $this->log($review, now()->subDays(30), 'good', null, null, 'review');
        $this->log($review, now()->subDay(), 'again', now()->subDay(), 1500, 'review');

        $response = $this->actingAs($this->user)->getJson('/study-overview/data?period=30');

        $response->assertOk()
            ->assertJsonPath('meta.language', 'english')
            ->assertJsonPath('meta.period', 30)
            ->assertJsonPath('meta.scope_card_count', 2)
            ->assertJsonPath('today.due_count', 1)
            ->assertJsonPath('today.overdue_backlog', 1)
            ->assertJsonPath('cards.state_distribution.new', 1)
            ->assertJsonPath('cards.state_distribution.review', 1)
            ->assertJsonPath('ratings.counts.again', 1)
            ->assertJsonPath('review_time.timed_review_count', 1)
            ->assertJsonPath('review_time.untimed_review_count', 0)
            ->assertJsonPath('review_time.total_duration_ms', 1500)
            ->assertJsonPath('true_retention.mature_sample_size', 1)
            ->assertJsonPath('true_retention.mature_retention', 0);
        $this->assertCount(30, $response->json('future_due'));
        $this->assertSame(1, collect($response->json('future_due'))->sum('count'));
    }

    public function test_saved_search_scope_uses_canonical_filter_state_and_is_private(): void
    {
        $this->card('due-card', 'review', now()->subDay(), now()->subDays(4));
        $this->card('future-card', 'review', now()->addDays(4), now()->subDay());
        $saved = ReviewCardSavedSearch::forceCreate([
            'user_id' => $this->user->id, 'language_id' => 'english', 'name' => 'Due only', 'normalized_name' => 'due only',
            'filter_state_version' => 1, 'filter_state' => ['q' => '', 'filter' => 'due', 'sort_by' => 'id', 'sort_dir' => 'desc', 'fsrs_states' => [], 'due_range' => 'all', 'reps_min' => null, 'lapses_min' => null],
        ]);

        $this->actingAs($this->user)->getJson('/study-overview/data?saved_search_id=' . $saved->id)
            ->assertOk()->assertJsonPath('meta.scope_card_count', 1)
            ->assertJsonPath('meta.saved_search.name', 'Due only')
            ->assertJsonPath('deep_link', '/review-cards/manage?saved_search_id=' . $saved->id);

        $other = User::forceCreate([
            'name' => 'Other', 'email' => 'overview-other@example.test', 'password' => Hash::make('password'),
            'selected_language' => 'english', 'password_changed' => true, 'is_admin' => true, 'uuid' => (string) Str::uuid(),
        ]);
        $this->flushSession();
        $this->actingAs($other)->getJson('/study-overview/data?saved_search_id=' . $saved->id)->assertNotFound();
    }

    public function test_period_validation_and_authentication(): void
    {
        $this->getJson('/study-overview/data')->assertUnauthorized();
        $this->actingAs($this->user)->getJson('/study-overview/data?period=31')
            ->assertUnprocessable()->assertJsonValidationErrors(['period']);
    }

    public function test_lifecycle_distribution_only_treats_a_future_bury_as_buried(): void
    {
        $expired = $this->card('expired-bury', 'review', now()->addDay(), now()->subDay());
        $future = $this->card('future-bury', 'review', now()->addDay(), now()->subDay());
        $expired->forceFill(['buried_until' => now()->subMinute()])->save();
        $future->forceFill(['buried_until' => now()->addMinute()])->save();

        $this->actingAs($this->user)->getJson('/study-overview/data')
            ->assertOk()
            ->assertJsonPath('cards.lifecycle_distribution.active', 1)
            ->assertJsonPath('cards.lifecycle_distribution.buried', 1);
    }

    public function test_query_budget_is_constant_for_1_100_and_500_cards(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) { $queries[] = strtolower($query->sql); });
        $service = app(StudyOverviewQueryService::class);
        $budgets = [];
        $created = 0;
        foreach ([1, 100, 500] as $target) {
            while ($created < $target) {
                $created++;
                $this->card('budget-' . $created, 'review', now()->addDays($created % 20), now()->subDays(4));
            }
            $before = count($queries);
            $result = $service->build($this->user->id, 'english', 30, null, now());
            $slice = array_slice($queries, $before);
            $budgets[$target] = [
                'sql' => count($slice),
                'review_card_sql' => count(array_filter($slice, fn ($sql) => str_contains($sql, 'review_cards'))),
                'review_log_sql' => count(array_filter($slice, fn ($sql) => str_contains($sql, 'review_logs'))),
                'saved_search_sql' => count(array_filter($slice, fn ($sql) => str_contains($sql, 'review_card_saved_searches'))),
                'rows' => $result['meta']['scope_card_count'],
            ];
        }

        $this->assertSame([1, 100, 500], array_column($budgets, 'rows'));
        $this->assertLessThanOrEqual(1, max(array_column($budgets, 'sql')) - min(array_column($budgets, 'sql')));
        foreach ($budgets as $budget) {
            $this->assertLessThanOrEqual(12, $budget['sql']);
            $this->assertLessThanOrEqual(6, $budget['review_card_sql']);
            $this->assertLessThanOrEqual(3, $budget['review_log_sql']);
            $this->assertLessThanOrEqual(1, $budget['saved_search_sql']);
        }
    }

    private function card(string $lemma, string $state, Carbon $due, ?Carbon $lastReviewed): ReviewCard
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'language_id' => 'english', 'lemma' => $lemma,
            'surface_form' => $lemma, 'pos' => 'noun', 'sense_zh' => $lemma, 'sense_en' => $lemma,
            'aliases_zh' => [], 'collocations' => [], 'example_sentence_en' => 'Example.', 'example_sentence_zh' => 'Example.',
            'status' => WordSense::STATUS_CONFIRMED, 'is_context_specific' => true, 'sense_key' => hash('sha256', $lemma),
        ]);
        return ReviewCard::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE, 'target_id' => $sense->id, 'fsrs_state' => $state,
            'fsrs_due_at' => $due, 'fsrs_enabled' => true, 'fsrs_stability' => $state === 'new' ? null : 10,
            'fsrs_difficulty' => $state === 'new' ? null : 5, 'fsrs_last_reviewed_at' => $lastReviewed,
        ]);
    }

    private function log(ReviewCard $card, Carbon $reviewedAt, string $rating, ?Carbon $previousDue, ?int $duration, string $previousState): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'language_id' => 'english', 'review_card_id' => $card->id,
            'rating' => $rating, 'reviewed_at' => $reviewedAt, 'review_duration_ms' => $duration,
            'previous_state' => $previousState, 'new_state' => 'review', 'previous_due_at' => $previousDue, 'source' => 'sense_review',
        ]);
    }
}
