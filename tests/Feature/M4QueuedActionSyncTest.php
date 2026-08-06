<?php

namespace Tests\Feature;

use App\Models\MobileClientAction;
use App\Models\MobileDevice;
use App\Models\Operation;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\MobileSenseReviewMutationService;
use App\Services\ReviewCardService;
use App\Services\WordSenseContentVersionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class M4QueuedActionSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private MobileDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::forceCreate([
            'name' => 'reviewIntervals',
            'value' => json_encode([
                '-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3],
                '-3' => [7], '-2' => [15], '-1' => [30],
            ]),
        ]);
        $this->user = $this->createUser('m4-sync@example.test', 'english');
        [$this->token, $this->device] = $this->issueToken($this->user);
    }

    public function test_batch_sorts_same_card_ratings_by_occurrence_time_and_merges_other_cards(): void
    {
        [$firstSense, $firstCard] = $this->createSenseCard($this->user, 'alpha');
        [, $secondCard] = $this->createSenseCard($this->user, 'beta');
        $early = Carbon::now('UTC')->subMinutes(10);
        $middle = Carbon::now('UTC')->subMinutes(8);
        $late = Carbon::now('UTC')->subMinutes(5);

        $response = $this->sync([
            $this->ratingAction($firstCard, 'good', $late, 3),
            $this->ratingAction($secondCard, 'easy', $middle, 2),
            $this->ratingAction($firstCard, 'hard', $early, 1),
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.counts.succeeded', 3)
            ->assertJsonPath('data.results.0.processed_order', 2)
            ->assertJsonPath('data.results.1.processed_order', 1)
            ->assertJsonPath('data.results.2.processed_order', 0);

        $this->assertSame(2, $firstCard->fresh()->fsrs_reps);
        $this->assertSame(1, $secondCard->fresh()->fsrs_reps);
        $logs = ReviewLog::query()
            ->where('review_card_id', $firstCard->id)
            ->orderBy('reviewed_at')
            ->get();
        $this->assertCount(2, $logs);
        $this->assertSame($early->toIso8601String(), $logs[0]->reviewed_at->utc()->toIso8601String());
        $this->assertSame($late->toIso8601String(), $logs[1]->reviewed_at->utc()->toIso8601String());
        $this->assertSame(3, Operation::query()->count());
        $this->assertSame(3, MobileClientAction::query()->count());
        $this->assertSame($firstSense->id, $firstCard->target_id);
        $this->assertNotNull($response->json('data.results.0.operation_id'));
    }

    public function test_same_timestamp_actions_use_device_sequence_as_the_tie_breaker(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'same-time');
        $occurredAt = Carbon::now('UTC')->subMinutes(3);

        $this->sync([
            $this->ratingAction($card, 'good', $occurredAt, 2),
            $this->ratingAction($card, 'hard', $occurredAt, 1),
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results.0.processed_order', 1)
            ->assertJsonPath('data.results.1.processed_order', 0);

        $this->assertSame(2, $card->fresh()->fsrs_reps);
        $this->assertSame(
            [1, 2],
            Operation::query()->orderBy('created_at')->orderBy('id')->pluck('client_sequence')->all(),
        );
    }

    public function test_exact_batch_retry_replays_each_action_and_changed_payload_conflicts(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'replay');
        $action = $this->ratingAction($card, 'good', Carbon::now('UTC')->subMinute(), 1);
        $batchId = (string) Str::uuid();

        $first = $this->sync([$action], $batchId)
            ->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'applied');
        $operationId = $first->json('data.results.0.operation_id');

        $this->sync([$action], $batchId)
            ->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'replayed')
            ->assertJsonPath('data.results.0.operation_id', $operationId)
            ->assertJsonPath('data.counts.replayed', 1);

        $changed = $action;
        $changed['payload']['rating'] = 'easy';
        $this->sync([$changed], (string) Str::uuid())
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.results.0.error.code', 'IDEMPOTENCY_KEY_REUSED')
            ->assertJsonPath('data.results.0.replayed', false);

        $this->assertSame(1, ReviewLog::query()->count());
        $this->assertSame(1, Operation::query()->count());
        $this->assertSame(1, $card->fresh()->fsrs_reps);
    }

    public function test_online_rating_can_be_replayed_through_sync_without_second_side_effect(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'online-to-sync');
        $clientActionId = (string) Str::uuid();

        $online = $this->withToken($this->token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", [
                'rating' => 'good',
                'client_action_id' => $clientActionId,
                'review_duration_ms' => 1250,
            ])
            ->assertOk()
            ->assertJsonPath('data.replayed', false);

        $action = $this->ratingAction(
            $card,
            'good',
            Carbon::now('UTC')->subMinute(),
            1,
            $clientActionId,
        );
        $sync = $this->sync([$action])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results.0.outcome', 'replayed')
            ->assertJsonPath('data.results.0.replayed', true);

        $this->assertSame(
            $online->json('data.operation_id'),
            $sync->json('data.results.0.operation_id'),
        );
        $this->assertSame(1, ReviewLog::query()->where('review_card_id', $card->id)->count());
        $this->assertSame(1, Operation::query()->count());
        $this->assertSame(1, $card->fresh()->fsrs_reps);
    }

    public function test_synced_rating_can_be_replayed_through_online_endpoint_without_second_side_effect(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'sync-to-online');
        $clientActionId = (string) Str::uuid();
        $action = $this->ratingAction(
            $card,
            'good',
            Carbon::now('UTC')->subMinute(),
            1,
            $clientActionId,
        );

        $sync = $this->sync([$action])
            ->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'applied');

        $online = $this->withToken($this->token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", [
                'rating' => 'good',
                'client_action_id' => $clientActionId,
                'review_duration_ms' => 1250,
            ])
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame(
            $sync->json('data.results.0.operation_id'),
            $online->json('data.operation_id'),
        );
        $this->assertSame(1, ReviewLog::query()->where('review_card_id', $card->id)->count());
        $this->assertSame(1, Operation::query()->count());
        $this->assertSame(1, $card->fresh()->fsrs_reps);
    }

    public function test_cross_transport_retry_normalizes_integer_strings_before_hashing(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'normalized-cross-transport');
        $clientActionId = (string) Str::uuid();

        $online = $this->withToken($this->token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", [
                'rating' => 'good',
                'client_action_id' => $clientActionId,
                'review_duration_ms' => 1250,
            ])
            ->assertOk()
            ->assertJsonPath('data.replayed', false);

        $action = $this->ratingAction(
            $card,
            'good',
            Carbon::now('UTC')->subMinute(),
            1,
            $clientActionId,
        );
        $action['sequence'] = '1';
        $action['payload']['review_card_id'] = (string) $card->id;
        $action['payload']['review_duration_ms'] = '1250';

        $sync = $this->sync([$action])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results.0.outcome', 'replayed');

        $this->assertSame(
            $online->json('data.operation_id'),
            $sync->json('data.results.0.operation_id'),
        );
        $this->assertSame(1, ReviewLog::query()->where('review_card_id', $card->id)->count());
        $this->assertSame(1, $card->fresh()->fsrs_reps);
    }

    public function test_cross_transport_changed_rating_conflicts_without_duplicate_review(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'changed-cross-transport');
        $clientActionId = (string) Str::uuid();

        $this->withToken($this->token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", [
                'rating' => 'good',
                'client_action_id' => $clientActionId,
                'review_duration_ms' => 1250,
            ])
            ->assertOk();

        $this->sync([
            $this->ratingAction(
                $card,
                'easy',
                Carbon::now('UTC')->subMinute(),
                1,
                $clientActionId,
            ),
        ])->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.results.0.error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, ReviewLog::query()->where('review_card_id', $card->id)->count());
        $this->assertSame(1, Operation::query()->count());
        $this->assertSame(1, $card->fresh()->fsrs_reps);
    }

    public function test_late_rating_is_rejected_after_newer_rating_across_devices(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'ordered');
        $newer = Carbon::now('UTC')->subMinute();
        $older = Carbon::now('UTC')->subMinutes(2);
        $this->sync([$this->ratingAction($card, 'good', $newer, 2)])
            ->assertJsonPath('data.status', 'completed');

        [$otherToken] = $this->issueToken($this->user);
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)
            ->postJson('/api/v1/mobile/sync/actions', [
                'batch_id' => (string) Str::uuid(),
                'actions' => [$this->ratingAction($card, 'hard', $older, 1)],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.results.0.error.code', 'OUT_OF_ORDER_ACTION')
            ->assertJsonPath('data.results.0.error.retryable', false);

        $this->assertSame(1, ReviewLog::query()->where('review_card_id', $card->id)->count());
        $this->assertSame(1, $card->fresh()->fsrs_reps);
    }

    public function test_queued_rating_is_rejected_after_newer_web_rating(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'web-ordered');
        app(ReviewCardService::class)->recordReview(
            $this->user->id,
            'english',
            $card->id,
            'good',
            'sense_review',
        );

        $this->sync([
            $this->ratingAction($card, 'hard', Carbon::now('UTC')->subMinutes(2), 1),
        ])->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.results.0.error.code', 'OUT_OF_ORDER_ACTION');

        $this->assertSame(1, ReviewLog::query()->where('review_card_id', $card->id)->count());
        $this->assertSame(1, $card->fresh()->fsrs_reps);
        $this->assertSame(0, Operation::query()->count());
    }

    public function test_word_sense_update_uses_content_version_and_stale_edit_is_partial(): void
    {
        [$sense, $card] = $this->createSenseCard($this->user, 'versioned');
        [, $otherCard] = $this->createSenseCard($this->user, 'independent');
        $version = app(WordSenseContentVersionService::class)->version($sense);
        $stale = str_repeat('a', 64);

        $response = $this->sync([
            $this->senseUpdateAction($sense, $stale, ['sense_zh' => '不会覆盖'], 1),
            $this->ratingAction($otherCard, 'good', Carbon::now('UTC')->subMinute(), 2),
            $this->senseUpdateAction($sense, $version, [
                'sense_zh' => '新释义',
                'aliases_zh' => ['甲', '乙'],
            ], 3),
        ])->assertOk()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.counts.succeeded', 2)
            ->assertJsonPath('data.counts.failed', 1)
            ->assertJsonPath('data.results.0.error.code', 'STALE_WORD_SENSE')
            ->assertJsonPath('data.results.1.outcome', 'applied')
            ->assertJsonPath('data.results.2.outcome', 'applied');

        $sense->refresh();
        $this->assertSame('新释义', $sense->sense_zh);
        $this->assertSame(['甲', '乙'], $sense->aliases_zh);
        $this->assertSame(0, $card->fresh()->fsrs_reps);
        $this->assertSame(1, $otherCard->fresh()->fsrs_reps);
        $this->assertNotSame($version, $response->json('data.results.2.data.word_sense_version'));
    }

    public function test_delete_then_edit_conflict_preserves_occurrences_and_review_history(): void
    {
        [$sense, $card] = $this->createSenseCard($this->user, 'delete-me');
        app(ReviewCardService::class)->recordReview(
            $this->user->id,
            'english',
            $card->id,
            'good',
            'sense_review',
        );
        $version = app(WordSenseContentVersionService::class)->version($sense);
        $delete = $this->senseDeleteAction($sense, $version, 1);

        $this->sync([$delete])
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results.0.data.deleted', true);
        $this->assertSame(WordSense::STATUS_REJECTED, $sense->fresh()->status);
        $this->assertDatabaseMissing('review_cards', ['id' => $card->id]);
        $this->assertSame(1, ReviewLog::query()->where('review_card_id', $card->id)->count());

        $this->sync([
            $this->senseUpdateAction($sense, $version, ['sense_zh' => 'resurrect'], 2),
        ])->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.results.0.error.code', 'WORD_SENSE_DELETED');

        $this->sync([$delete])
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results.0.outcome', 'replayed');
    }

    public function test_foreign_sense_invalid_action_and_valid_rating_return_clear_partial_results(): void
    {
        $other = $this->createUser('m4-other@example.test', 'english');
        [$foreignSense] = $this->createSenseCard($other, 'foreign');
        [, $ownCard] = $this->createSenseCard($this->user, 'own');
        $foreignVersion = app(WordSenseContentVersionService::class)->version($foreignSense);

        $invalid = [
            'client_action_id' => (string) Str::uuid(),
            'type' => 'unknown.action',
            'occurred_at' => Carbon::now('UTC')->toIso8601String(),
            'sequence' => 1,
            'payload' => [],
        ];
        $this->sync([
            $invalid,
            $this->senseDeleteAction($foreignSense, $foreignVersion, 2),
            $this->ratingAction($ownCard, 'good', Carbon::now('UTC')->subMinute(), 3),
        ])->assertOk()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.results.0.error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('data.results.1.error.code', 'WORD_SENSE_NOT_FOUND')
            ->assertJsonPath('data.results.2.outcome', 'applied');

        $this->assertSame(WordSense::STATUS_CONFIRMED, $foreignSense->fresh()->status);
        $this->assertSame(1, $ownCard->fresh()->fsrs_reps);
    }

    public function test_time_window_conflict_is_stored_and_replayed(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'old');
        $action = $this->ratingAction($card, 'good', Carbon::now('UTC')->subDays(31), 1);

        $this->sync([$action])
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.results.0.error.code', 'ACTION_TIME_OUT_OF_RANGE')
            ->assertJsonPath('data.results.0.replayed', false);
        $this->sync([$action])
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.results.0.error.code', 'ACTION_TIME_OUT_OF_RANGE')
            ->assertJsonPath('data.results.0.replayed', true);

        $this->assertSame(1, MobileClientAction::query()->count());
        $this->assertSame(0, ReviewLog::query()->count());
    }

    public function test_non_iso_and_invalid_calendar_timestamps_are_rejected_without_claims(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'strict-time');
        $first = $this->ratingAction($card, 'good', Carbon::now('UTC'), 1);
        $first['occurred_at'] = 'tomorrow';
        $second = $this->ratingAction($card, 'hard', Carbon::now('UTC'), 2);
        $second['occurred_at'] = '2026-02-30T12:00:00Z';

        $this->sync([$first, $second])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.counts.failed', 2)
            ->assertJsonPath('data.results.0.error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('data.results.1.error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, MobileClientAction::query()->count());
        $this->assertSame(0, ReviewLog::query()->count());
        $this->assertSame(0, Operation::query()->count());
    }

    public function test_unexpected_action_failure_is_retryable_and_rolls_back_claim(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'retryable');
        $mutation = $this->mock(MobileSenseReviewMutationService::class);
        $mutation->shouldReceive('apply')
            ->once()
            ->andThrow(new RuntimeException('forced failure'));

        $this->sync([
            $this->ratingAction($card, 'good', Carbon::now('UTC')->subMinute(), 1),
        ])->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.results.0.outcome', 'retryable')
            ->assertJsonPath('data.results.0.error.code', 'INTERNAL_ERROR')
            ->assertJsonPath('data.results.0.error.retry_after_ms', 1000)
            ->assertJsonMissing(['message' => 'forced failure']);

        $this->assertSame(0, MobileClientAction::query()->count());
        $this->assertSame(0, ReviewLog::query()->count());
        $this->assertSame(0, Operation::query()->count());
    }

    public function test_revoked_device_and_invalid_outer_batch_are_rejected_before_actions(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'revoked');
        $action = $this->ratingAction($card, 'good', Carbon::now('UTC')->subMinute(), 1);

        $this->withToken($this->token)
            ->postJson('/api/v1/mobile/sync/actions', [
                'batch_id' => 'not-a-uuid',
                'actions' => [$action],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->device->forceFill(['revoked_at' => now()])->save();
        $this->withToken($this->token)
            ->postJson('/api/v1/mobile/sync/actions', [
                'batch_id' => (string) Str::uuid(),
                'actions' => [$action],
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'DEVICE_REVOKED');

        $this->assertSame(0, ReviewLog::query()->count());
        $this->assertSame(0, MobileClientAction::query()->count());
    }

    public function test_outer_batch_enforces_json_list_count_and_byte_limits_without_claims(): void
    {
        [, $card] = $this->createSenseCard($this->user, 'outer-limits');
        $action = $this->ratingAction($card, 'good', Carbon::now('UTC')->subMinute(), 1);

        $this->withToken($this->token)
            ->postJson('/api/v1/mobile/sync/actions', [
                'batch_id' => (string) Str::uuid(),
                'actions' => ['named' => $action],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $actions = [];
        for ($index = 0; $index < 101; $index++) {
            $actions[] = $this->ratingAction(
                $card,
                'good',
                Carbon::now('UTC')->subMinute(),
                $index + 1,
            );
        }
        $this->withToken($this->token)
            ->postJson('/api/v1/mobile/sync/actions', [
                'batch_id' => (string) Str::uuid(),
                'actions' => $actions,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $oversized = $this->ratingAction($card, 'good', Carbon::now('UTC')->subMinute(), 1);
        $oversized['padding'] = str_repeat('x', 1048576);
        $this->withToken($this->token)
            ->postJson('/api/v1/mobile/sync/actions', [
                'batch_id' => (string) Str::uuid(),
                'actions' => [$oversized],
            ])
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');

        $this->assertSame(0, MobileClientAction::query()->count());
        $this->assertSame(0, ReviewLog::query()->count());
        $this->assertSame(0, Operation::query()->count());
    }

    private function sync(array $actions, ?string $batchId = null)
    {
        return $this->withToken($this->token)
            ->postJson('/api/v1/mobile/sync/actions', [
                'batch_id' => $batchId ?? (string) Str::uuid(),
                'actions' => $actions,
            ]);
    }

    private function ratingAction(
        ReviewCard $card,
        string $rating,
        Carbon $occurredAt,
        int $sequence,
        ?string $clientActionId = null,
    ): array {
        return [
            'client_action_id' => $clientActionId ?? (string) Str::uuid(),
            'type' => 'sense_review.rating',
            'occurred_at' => $occurredAt->toIso8601String(),
            'sequence' => $sequence,
            'payload' => [
                'review_card_id' => $card->id,
                'rating' => $rating,
                'review_duration_ms' => 1250,
            ],
        ];
    }

    private function senseUpdateAction(
        WordSense $sense,
        string $version,
        array $changes,
        int $sequence,
    ): array {
        return [
            'client_action_id' => (string) Str::uuid(),
            'type' => 'word_sense.update',
            'occurred_at' => Carbon::now('UTC')->subMinute()->toIso8601String(),
            'sequence' => $sequence,
            'payload' => [
                'word_sense_id' => $sense->id,
                'expected_word_sense_version' => str_starts_with($version, 'sha256:')
                    ? $version
                    : 'sha256:' . $version,
                'changes' => $changes,
            ],
        ];
    }

    private function senseDeleteAction(
        WordSense $sense,
        string $version,
        int $sequence,
    ): array {
        return [
            'client_action_id' => (string) Str::uuid(),
            'type' => 'word_sense.delete',
            'occurred_at' => Carbon::now('UTC')->subMinute()->toIso8601String(),
            'sequence' => $sequence,
            'payload' => [
                'word_sense_id' => $sense->id,
                'expected_word_sense_version' => $version,
            ],
        ];
    }

    private function createSenseCard(User $user, string $lemma): array
    {
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => $user->selected_language,
            'language_id' => $user->selected_language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '释义-' . $lemma,
            'sense_en' => 'meaning-' . $lemma,
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => "Example for {$lemma}.",
            'example_sentence_zh' => "{$lemma} 的例句。",
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "{$user->id}|{$user->selected_language}|{$lemma}"),
        ]);

        return [$sense, app(ReviewCardService::class)->ensureSenseCard($sense)];
    }

    private function issueToken(User $user): array
    {
        $deviceUuid = (string) Str::uuid();
        $response = $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => $deviceUuid,
            'platform' => 'web',
            'device_name' => 'M4 simulator test',
            'app_version' => '1.0.0',
        ])->assertCreated();

        return [
            $response->json('data.token'),
            MobileDevice::query()
                ->where('user_id', $user->id)
                ->where('device_uuid', $deviceUuid)
                ->firstOrFail(),
        ];
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
