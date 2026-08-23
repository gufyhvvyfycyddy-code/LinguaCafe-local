<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class M14StatisticsCardInfoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'M14 User',
            'email' => 'm14@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_statistics_are_scoped_and_use_non_undone_formal_history(): void
    {
        $card = $this->card('signal', now()->addDay());
        $this->log($card, 'again', now()->subDay()->startOfDay()->addHour(), 70000);
        $this->log($card, 'good', now()->subDay()->startOfDay()->addHours(2), 20000);
        $this->log($card, 'easy', now()->startOfDay()->addHour(), 10000);
        $this->log($card, 'hard', now()->startOfDay()->addHours(2), 1000, ['undone_at' => now()]);

        $other = User::forceCreate([
            'name' => 'Other', 'email' => 'm14-other@example.test',
            'password' => Hash::make('password'), 'selected_language' => 'english',
            'password_changed' => true, 'uuid' => (string) Str::uuid(),
        ]);
        $this->card('other', now()->addDay(), $other);

        $before = [ReviewCard::count(), ReviewLog::count(), WordSense::count()];
        $response = $this->actingAs($this->user)->postJson('/statistics/get', [
            'period_days' => 30,
            'q' => 'signal',
        ])->assertOk();

        $response->assertJsonPath('schema_version', 3)
            ->assertJsonPath('scope.card_count', 1)
            ->assertJsonPath('future_due.horizons.7', 1)
            ->assertJsonPath('review_time.total_seconds', 90)
            ->assertJsonPath('review_time.average_seconds', 30)
            ->assertJsonPath('true_retention.total', 2)
            ->assertJsonPath('true_retention.passed', 1)
            ->assertJsonPath('true_retention.rate_percent', 50)
            ->assertJsonPath('ratings.0.count', 1)
            ->assertJsonPath('ratings.1.count', 0)
            ->assertJsonPath('ratings.2.count', 1)
            ->assertJsonPath('ratings.3.count', 1)
            ->assertJsonPath('memory_durability.states.1.key', 'consolidating')
            ->assertJsonPath('memory_durability.states.1.count', 1)
            ->assertJsonPath('future_pressure.assumptions.candidate_cards', 1)
            ->assertJsonPath('future_pressure.assumptions.projection_days', 91);
        $this->assertSame(['tomorrow', 7, 30, 90], array_keys($response->json('future_pressure.horizons')));
        $this->assertCount(90, $response->json('future_pressure.curve'));
        $this->assertSame($before, [ReviewCard::count(), ReviewLog::count(), WordSense::count()]);
    }

    public function test_memory_durability_keeps_evidence_poor_cards_out_of_stable(): void
    {
        $this->card('poor', now()->addDay(), null, [
            'fsrs_reps' => 1, 'fsrs_stability' => null, 'fsrs_last_reviewed_at' => null,
        ]);
        $this->card('fragile', now()->addDay(), null, [
            'fsrs_reps' => 5, 'fsrs_lapses' => 3, 'fsrs_stability' => 40,
        ]);
        $this->card('consolidating', now()->addDay(), null, [
            'fsrs_reps' => 4, 'fsrs_lapses' => 0, 'fsrs_stability' => 10,
        ]);
        $this->card('stable', now()->addDay(), null, [
            'fsrs_reps' => 5, 'fsrs_lapses' => 0, 'fsrs_stability' => 60,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->user)->postJson('/statistics/get')->assertOk();

        $this->assertSame([
            'fragile' => 1,
            'consolidating' => 1,
            'stable' => 1,
            'evidence_poor' => 1,
        ], collect($response->json('memory_durability.states'))->pluck('count', 'key')->all());
        $this->assertSame(
            ['容易遗忘', '正在巩固', '掌握稳定', '证据不足'],
            array_column($response->json('memory_durability.states'), 'label'),
        );
        $response->assertJsonPath('memory_durability.coverage.sufficient', 3)
            ->assertJsonPath('memory_durability.coverage.total', 4);
        $this->assertStringContainsString('不会被标为掌握稳定', $response->json('memory_durability.criteria.evidence_poor'));
    }

    public function test_learning_entry_date_range_is_the_common_card_scope_for_durability_and_pressure(): void
    {
        config()->set('app.timezone', 'Asia/Shanghai');
        $this->card('before-range', now()->addDay(), null, [], [
            'learning_started_at' => Carbon::create(2026, 7, 13, 12, 0, 0, 'Asia/Shanghai'),
        ]);
        $this->card('inside-range', now()->addDay(), null, [], [
            'learning_started_at' => Carbon::create(2026, 7, 14, 12, 0, 0, 'Asia/Shanghai'),
        ]);
        $this->card('after-range', now()->addDay(), null, [], [
            'learning_started_at' => Carbon::create(2026, 7, 15, 12, 0, 0, 'Asia/Shanghai'),
        ]);
        $this->card('missing-learning-entry', now()->addDay());

        $response = $this->actingAs($this->user)->postJson('/statistics/get', [
            'date_from' => '2026-07-14',
            'date_to' => '2026-07-14',
        ])->assertOk();

        $response->assertJsonPath('scope.card_count', 1)
            ->assertJsonPath('scope.learning_date_range.date_from', '2026-07-14')
            ->assertJsonPath('scope.learning_date_range.date_to', '2026-07-14')
            ->assertJsonPath('scope.learning_date_range.timezone', 'Asia/Shanghai')
            ->assertJsonPath('memory_durability.coverage.total', 1)
            ->assertJsonPath('future_pressure.assumptions.candidate_cards', 1);
    }

    public function test_learning_entry_date_range_requires_a_valid_pair(): void
    {
        $this->actingAs($this->user)->postJson('/statistics/get', [
            'date_from' => '2026-07-14',
        ])->assertUnprocessable()->assertJsonValidationErrors('date_to');

        $this->actingAs($this->user)->postJson('/statistics/get', [
            'date_from' => '2026-07-15',
            'date_to' => '2026-07-14',
        ])->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }

    public function test_unified_query_can_produce_a_truthful_empty_report(): void
    {
        $this->card('present', now()->addDay());
        $this->actingAs($this->user)->postJson('/statistics/get', [
            'period_days' => 7,
            'q' => 'missing-term',
        ])->assertOk()
            ->assertJsonPath('scope.card_count', 0)
            ->assertJsonPath('true_retention.rate_percent', null)
            ->assertJsonPath('future_due.horizons.365', 0);
    }

    public function test_csv_and_pdf_exports_share_the_report_and_have_safe_headers(): void
    {
        $this->card('export', now()->addDay());

        $csv = $this->actingAs($this->user)->post('/statistics/export/csv', ['period_days' => 30]);
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('future_due', $csv->getContent());

        $pdf = $this->actingAs($this->user)->post('/statistics/export/pdf', ['period_days' => 30]);
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $pdf->getContent());
        $this->assertStringContainsString('LinguaCafe Statistics V3', $pdf->getContent());
    }

    public function test_card_info_v3_adds_current_and_aggregate_statistics(): void
    {
        $card = $this->card('detail', now()->addDay());
        $this->log($card, 'again', now()->subHour(), 70000);
        $this->log($card, 'good', now(), 10000);

        $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$card->id}/detail")
            ->assertOk()
            ->assertJsonPath('card_info.statistics.current.state', 'review')
            ->assertJsonPath('card_info.statistics.ratings.again', 1)
            ->assertJsonPath('card_info.statistics.ratings.good', 1)
            ->assertJsonPath('card_info.statistics.review_time.total_seconds', 70);
    }

    private function card(
        string $lemma,
        $dueAt,
        ?User $user = null,
        array $overrides = [],
        array $senseOverrides = [],
    ): ReviewCard
    {
        $user ??= $this->user;
        $sense = WordSense::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '含义',
            'sense_en' => 'meaning',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Example.',
            'example_sentence_zh' => '例句。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => false,
            'sense_key' => hash('sha256', "{$user->id}|{$lemma}"),
        ], $senseOverrides));
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => $dueAt,
            'fsrs_last_reviewed_at' => now()->subDays(3),
            'fsrs_stability' => 5,
            'fsrs_difficulty' => 6,
            'fsrs_reps' => 4,
            'fsrs_lapses' => 1,
            'fsrs_enabled' => true,
            'lifecycle_state' => 'active',
        ], $overrides));
    }

    private function log(ReviewCard $card, string $rating, $reviewedAt, int $duration, array $overrides = []): ReviewLog
    {
        return ReviewLog::forceCreate(array_merge([
            'user_id' => $card->user_id,
            'language' => $card->language,
            'language_id' => $card->language_id,
            'review_card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => $reviewedAt,
            'review_duration_ms' => $duration,
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => now()->subDay(),
            'new_due_at' => now()->addDay(),
            'previous_stability' => 4,
            'new_stability' => 5,
            'previous_difficulty' => 6,
            'new_difficulty' => 6,
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ], $overrides));
    }
}
