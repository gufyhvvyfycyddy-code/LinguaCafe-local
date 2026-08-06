<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\ReviewCard;
use App\Models\SpecialStudySession;
use App\Models\User;
use App\Models\WordSense;
use App\Services\MobileOperationLedgerService;
use App\Services\EffectiveReviewLimitsService;
use App\Services\SpecialStudy\SpecialStudyAnswerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class M12SpecialStudyExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_ratings_advance_without_learning_writes(): void
    {
        $user = $this->createUser('m12-preview@example.test');

        foreach (['again', 'hard', 'good', 'easy'] as $index => $rating) {
            $card = $this->createSenseCard($user, "preview-{$index}");
            $before = $this->cardSnapshot($card);
            $created = $this->actingAs($user)->postJson(
                '/special-study/sessions',
                [
                    'scenario' => 'filtered',
                    'execution_mode' => 'preview',
                    'filters' => ['markers' => [$index + 1]],
                ],
            );
            // Give each card an exact marker after creation, then rebuild so
            // the session definition selects only that card.
            $card->forceFill(['marker' => $index + 1])->save();
            $session = SpecialStudySession::findOrFail($created->json('id'));
            $rebuilt = $this->actingAs($user)->postJson(
                "/special-study/sessions/{$session->id}/rebuild",
                ['expected_revision' => $session->revision],
            )->assertOk();

            $this->actingAs($user)->postJson(
                "/special-study/sessions/{$session->id}/answer",
                [
                    'rating' => $rating,
                    'client_action_id' => (string) Str::uuid(),
                    'expected_revision' => $rebuilt->json('revision'),
                ],
            )->assertOk()
                ->assertJsonPath('status', 'completed')
                ->assertJsonPath('completed_count', 1)
                ->assertJsonPath('operation_id', null)
                ->assertJsonPath('replayed', false);

            $this->assertSame($before, $this->cardSnapshot($card->fresh()));
        }

        $this->assertDatabaseCount('review_logs', 0);
        $this->assertDatabaseCount('operations', 0);
        $this->assertDatabaseCount('special_study_session_actions', 4);
    }

    public function test_formal_rating_is_atomic_logged_and_exactly_replayable(): void
    {
        $user = $this->createUser('m12-formal@example.test');
        $card = $this->createSenseCard($user, 'formal', [
            'marker' => ReviewCard::MARKER_RED,
        ]);
        $created = $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'execution_mode' => 'formal',
            'filters' => ['markers' => [ReviewCard::MARKER_RED]],
        ])->assertCreated();
        $actionId = (string) Str::uuid();
        $payload = [
            'rating' => 'good',
            'client_action_id' => $actionId,
            'expected_revision' => $created->json('revision'),
            'review_duration_ms' => 1200,
        ];

        $first = $this->actingAs($user)->postJson(
            "/special-study/sessions/{$created->json('id')}/answer",
            $payload,
        )->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('completed_count', 1)
            ->assertJsonPath('replayed', false);

        $operationId = $first->json('operation_id');
        $this->assertNotNull($operationId);
        $this->assertDatabaseHas('review_logs', [
            'review_card_id' => $card->id,
            'rating' => 'good',
            'source' => 'special_study',
            'review_session_id' => $created->json('id'),
            'review_duration_ms' => 1200,
        ]);
        $this->assertDatabaseHas('operations', [
            'operation_id' => $operationId,
            'source_channel' => 'web',
            'operation_type' => Operation::TYPE_SENSE_REVIEW_RATING,
            'scope_type' => Operation::SCOPE_SESSION,
            'scope_id' => $created->json('id'),
            'review_card_id' => $card->id,
        ]);
        $this->assertSame(4, $card->fresh()->fsrs_reps);
        $this->assertSame(
            1,
            app(EffectiveReviewLimitsService::class)
                ->resolve($user->id, 'english')['reviewed_today_count'],
        );

        $this->actingAs($user)->postJson(
            "/special-study/sessions/{$created->json('id')}/answer",
            $payload,
        )->assertOk()
            ->assertJsonPath('operation_id', $operationId)
            ->assertJsonPath('replayed', true);

        $this->assertDatabaseCount('review_logs', 1);
        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('operation_changes', 1);
        $this->assertSame(4, $card->fresh()->fsrs_reps);
    }

    public function test_action_conflict_and_stale_revision_fail_before_rating(): void
    {
        $user = $this->createUser('m12-conflict@example.test');
        $this->createSenseCard($user, 'conflict');
        $created = $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'execution_mode' => 'formal',
        ])->assertCreated();
        $actionId = (string) Str::uuid();

        $this->actingAs($user)->postJson(
            "/special-study/sessions/{$created->json('id')}/answer",
            [
                'rating' => 'good',
                'client_action_id' => $actionId,
                'expected_revision' => 1,
            ],
        )->assertOk();

        $this->actingAs($user)->postJson(
            "/special-study/sessions/{$created->json('id')}/answer",
            [
                'rating' => 'easy',
                'client_action_id' => $actionId,
                'expected_revision' => 1,
            ],
        )->assertStatus(409)
            ->assertJsonPath('error.reason', 'action_request_conflict');

        $this->actingAs($user)->postJson(
            "/special-study/sessions/{$created->json('id')}/answer",
            [
                'rating' => 'easy',
                'client_action_id' => (string) Str::uuid(),
                'expected_revision' => 1,
            ],
        )->assertStatus(409)
            ->assertJsonPath('error.reason', 'revision_conflict');

        $this->assertDatabaseCount('review_logs', 1);
        $this->assertDatabaseCount('operations', 1);
    }

    public function test_ineligible_preview_card_is_skipped_without_writes(): void
    {
        $user = $this->createUser('m12-skip@example.test');
        $card = $this->createSenseCard($user, 'skip', [
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
            'fsrs_enabled' => false,
        ]);
        $created = $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'execution_mode' => 'preview',
            'filters' => ['lifecycle_states' => ['suspended']],
        ])->assertCreated();
        $card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED,
        ])->save();

        $this->actingAs($user)->postJson(
            "/special-study/sessions/{$created->json('id')}/answer",
            [
                'rating' => 'again',
                'client_action_id' => (string) Str::uuid(),
                'expected_revision' => 1,
            ],
        )->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('completed_count', 0)
            ->assertJsonPath('skipped_count', 1);

        $this->assertDatabaseCount('review_logs', 0);
        $this->assertDatabaseCount('operations', 0);
    }

    public function test_early_review_rechecks_the_future_window_before_writing(): void
    {
        $now = Carbon::parse('2026-07-29 09:00:00', 'UTC');
        Carbon::setTestNow($now);

        try {
            $user = $this->createUser('m12-early-recheck@example.test');
            $card = $this->createSenseCard($user, 'early-recheck', [
                'fsrs_due_at' => $now->copy()->addDays(3),
            ]);
            $created = $this->actingAs($user)->postJson(
                '/special-study/sessions',
                [
                    'scenario' => 'review_ahead',
                    'execution_mode' => 'early_review',
                    'days' => 7,
                ],
            )->assertCreated()
                ->assertJsonPath('remaining_count', 1);

            // Another formal review or manual operation can make the snapshotted
            // card due now before this session answers it.
            $card->forceFill([
                'fsrs_due_at' => $now->copy()->subMinute(),
            ])->save();

            $this->actingAs($user)->postJson(
                "/special-study/sessions/{$created->json('id')}/answer",
                [
                    'rating' => 'good',
                    'client_action_id' => (string) Str::uuid(),
                    'expected_revision' => $created->json('revision'),
                ],
            )->assertOk()
                ->assertJsonPath('status', 'completed')
                ->assertJsonPath('completed_count', 0)
                ->assertJsonPath('skipped_count', 1)
                ->assertJsonPath('operation_id', null);

            $this->assertDatabaseCount('review_logs', 0);
            $this->assertDatabaseCount('operations', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_ledger_failure_rolls_back_rating_and_progress(): void
    {
        $user = $this->createUser('m12-rollback@example.test');
        $card = $this->createSenseCard($user, 'rollback');
        $created = $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'execution_mode' => 'formal',
        ])->assertCreated();
        $before = $this->cardSnapshot($card);
        $ledger = \Mockery::mock(MobileOperationLedgerService::class);
        $ledger->shouldReceive('registerWebRating')
            ->once()
            ->andThrow(new RuntimeException('ledger unavailable'));
        $service = new SpecialStudyAnswerService(
            app(\App\Services\ReviewCardService::class),
            $ledger,
            app(\App\Services\SpecialStudy\SpecialStudySessionService::class),
            app(\App\Services\ReviewStudyTimezoneService::class),
        );

        try {
            $service->answer(
                $created->json('id'),
                $user->id,
                'english',
                'good',
                (string) Str::uuid(),
                1,
            );
            $this->fail('Expected the ledger failure to escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ledger unavailable', $exception->getMessage());
        }

        $this->assertSame($before, $this->cardSnapshot($card->fresh()));
        $this->assertDatabaseCount('review_logs', 0);
        $this->assertDatabaseCount('operations', 0);
        $this->assertDatabaseCount('special_study_session_actions', 0);
        $this->assertSame(1, SpecialStudySession::findOrFail(
            $created->json('id'),
        )->revision);
    }

    private function createUser(string $email): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function createSenseCard(
        User $user,
        string $lemma,
        array $overrides = [],
    ): ReviewCard {
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => $lemma,
            'sense_en' => $lemma,
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "{$user->id}|english|{$lemma}"),
        ]);

        return ReviewCard::forceCreate(array_merge([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 5,
            'fsrs_difficulty' => 5,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ], $overrides));
    }

    private function cardSnapshot(ReviewCard $card): array
    {
        return [
            $card->fsrs_state,
            $card->fsrs_due_at?->toIso8601String(),
            (float) $card->fsrs_stability,
            (float) $card->fsrs_difficulty,
            (int) $card->fsrs_reps,
            (int) $card->fsrs_lapses,
            $card->fsrs_last_reviewed_at?->toIso8601String(),
            $card->lifecycle_state,
            (bool) $card->fsrs_enabled,
        ];
    }
}
