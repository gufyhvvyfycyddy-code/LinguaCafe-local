<?php

namespace Tests\Feature;

use App\Models\MobileClientAction;
use App\Models\MobileDevice;
use App\Models\Operation;
use App\Models\OperationChange;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\MobileOperationLedgerService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MobileOperationLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

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

        $this->user = $this->createUser('m2@example.com', 'english');
    }

    public function test_rating_registers_one_operation_and_recent_session_query(): void
    {
        [$token, $device] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'ledger');
        $sessionId = (string) Str::uuid();

        $rating = $this->rate($token, $card, $sessionId)
            ->assertOk()
            ->assertJsonPath('data.replayed', false);

        $operationId = $rating->json('data.operation_id');

        $this->assertDatabaseHas('operations', [
            'operation_id' => $operationId,
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'mobile_device_id' => $device->id,
            'operation_type' => Operation::TYPE_SENSE_REVIEW_RATING,
            'scope_type' => Operation::SCOPE_SESSION,
            'scope_id' => $sessionId,
            'status' => Operation::STATUS_APPLIED,
            'version' => 1,
            'review_card_id' => $card->id,
            'review_log_id' => $rating->json('data.review_log_id'),
        ]);
        $this->assertDatabaseHas('operation_changes', [
            'transition' => OperationChange::TRANSITION_APPLY,
            'from_status' => null,
            'to_status' => Operation::STATUS_APPLIED,
            'version' => 1,
        ]);

        $this->withToken($token)
            ->getJson("/api/v1/mobile/operations?review_session_id={$sessionId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.operations')
            ->assertJsonPath('data.operations.0.operation_id', $operationId)
            ->assertJsonPath('data.operations.0.source_device_uuid', $device->device_uuid)
            ->assertJsonPath('data.operations.0.can_undo', true)
            ->assertJsonPath('data.operations.0.can_redo', false);
    }

    public function test_rating_replay_does_not_duplicate_operation_or_change(): void
    {
        [$token] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'replay');
        $sessionId = (string) Str::uuid();
        $clientActionId = (string) Str::uuid();

        $first = $this->rate($token, $card, $sessionId, $clientActionId)
            ->assertJsonPath('data.replayed', false);
        $second = $this->rate($token, $card, $sessionId, $clientActionId)
            ->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.operation_id'), $second->json('data.operation_id'));
        $this->assertSame(1, Operation::count());
        $this->assertSame(1, OperationChange::count());
        $this->assertSame(1, ReviewLog::count());
        $this->assertSame(1, $card->fresh()->fsrs_reps);
    }

    public function test_recent_operations_use_a_stable_sequence_cursor(): void
    {
        [$token] = $this->issueToken($this->user);

        foreach (['cursor-one', 'cursor-two', 'cursor-three'] as $lemma) {
            $this->rate($token, $this->createSenseCard($this->user, $lemma))->assertOk();
        }

        $firstPage = $this->withToken($token)
            ->getJson('/api/v1/mobile/operations?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.operations');
        $cursor = $firstPage->json('data.next_before_sequence');
        $this->assertIsInt($cursor);

        $secondPage = $this->withToken($token)
            ->getJson("/api/v1/mobile/operations?limit=2&before_sequence={$cursor}")
            ->assertOk()
            ->assertJsonCount(1, 'data.operations')
            ->assertJsonPath('data.next_before_sequence', null);

        $firstIds = collect($firstPage->json('data.operations'))->pluck('operation_id');
        $secondIds = collect($secondPage->json('data.operations'))->pluck('operation_id');
        $this->assertCount(0, $firstIds->intersect($secondIds));
    }

    public function test_lifo_undo_and_redo_restore_snapshots_without_new_review_logs(): void
    {
        [$token] = $this->issueToken($this->user);
        $sessionId = (string) Str::uuid();
        $firstCard = $this->createSenseCard($this->user, 'first');
        $secondCard = $this->createSenseCard($this->user, 'second');

        $firstRating = $this->rate($token, $firstCard, $sessionId);
        $secondRating = $this->rate($token, $secondCard, $sessionId);
        $firstOperation = $firstRating->json('data.operation_id');
        $secondOperation = $secondRating->json('data.operation_id');

        $this->transition($token, $firstOperation, 'undo', 1)
            ->assertConflict()
            ->assertJsonPath('error.code', 'OPERATION_NOT_LATEST');

        $this->transition($token, $secondOperation, 'undo', 1)
            ->assertOk()
            ->assertJsonPath('data.operation.status', Operation::STATUS_UNDONE)
            ->assertJsonPath('data.operation.version', 2)
            ->assertJsonPath('data.replayed', false);
        $this->assertSame(0, $secondCard->fresh()->fsrs_reps);

        $this->transition($token, $firstOperation, 'undo', 1)
            ->assertOk()
            ->assertJsonPath('data.operation.can_redo', true);
        $this->assertSame(0, $firstCard->fresh()->fsrs_reps);

        $this->transition($token, $firstOperation, 'redo', 2)
            ->assertOk()
            ->assertJsonPath('data.operation.status', Operation::STATUS_APPLIED)
            ->assertJsonPath('data.operation.version', 3);
        $this->assertSame(1, $firstCard->fresh()->fsrs_reps);

        $this->transition($token, $secondOperation, 'redo', 2)
            ->assertOk()
            ->assertJsonPath('data.operation.status', Operation::STATUS_APPLIED)
            ->assertJsonPath('data.operation.version', 3);
        $this->assertSame(1, $secondCard->fresh()->fsrs_reps);

        $this->assertSame(2, ReviewLog::count());
        $this->assertSame(6, OperationChange::count());
        $this->assertSame(0, ReviewLog::whereNotNull('undone_at')->count());
    }

    public function test_new_rating_supersedes_the_redo_branch_in_the_same_session(): void
    {
        [$token] = $this->issueToken($this->user);
        $sessionId = (string) Str::uuid();
        $firstCard = $this->createSenseCard($this->user, 'branch-one');
        $secondCard = $this->createSenseCard($this->user, 'branch-two');

        $firstOperation = $this->rate($token, $firstCard, $sessionId)
            ->json('data.operation_id');

        $this->transition($token, $firstOperation, 'undo', 1)->assertOk();
        $this->rate($token, $secondCard, $sessionId)->assertOk();

        $first = Operation::where('operation_id', $firstOperation)->firstOrFail();
        $this->assertSame(Operation::STATUS_SUPERSEDED, $first->status);
        $this->assertSame(3, $first->version);
        $this->assertDatabaseHas('operation_changes', [
            'operation_record_id' => $first->id,
            'transition' => OperationChange::TRANSITION_SUPERSEDE,
            'version' => 3,
        ]);

        $this->transition($token, $firstOperation, 'redo', 3)
            ->assertConflict()
            ->assertJsonPath('error.code', 'OPERATION_NOT_REDOABLE');
        $this->assertNotNull(ReviewLog::findOrFail($first->review_log_id)->undone_at);
    }

    public function test_transition_replay_and_changed_payload_conflict_are_side_effect_free(): void
    {
        [$token] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'transition-replay');
        $operationId = $this->rate($token, $card)->json('data.operation_id');
        $clientActionId = (string) Str::uuid();

        $first = $this->transition($token, $operationId, 'undo', 1, $clientActionId)
            ->assertOk()
            ->assertJsonPath('data.replayed', false);
        $second = $this->transition($token, $operationId, 'undo', 1, $clientActionId)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.operation'), $second->json('data.operation'));
        $this->assertSame(2, OperationChange::count());
        $this->assertSame(1, ReviewLog::count());
        $this->assertSame(0, $card->fresh()->fsrs_reps);

        $this->transition($token, $operationId, 'undo', 2, $clientActionId)
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame(2, OperationChange::count());
    }

    public function test_version_and_card_state_conflicts_do_not_overwrite_current_state(): void
    {
        [$token] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'conflict');
        $operationId = $this->rate($token, $card)->json('data.operation_id');

        $this->transition($token, $operationId, 'undo', 2)
            ->assertConflict()
            ->assertJsonPath('error.code', 'OPERATION_VERSION_CONFLICT');

        $card->forceFill(['fsrs_stability' => 99.0])->save();

        $this->transition($token, $operationId, 'undo', 1)
            ->assertConflict()
            ->assertJsonPath('error.code', 'OPERATION_STATE_CHANGED');

        $this->assertSame(99.0, $card->fresh()->fsrs_stability);
        $this->assertSame(Operation::STATUS_APPLIED, Operation::firstOrFail()->status);
        $this->assertNull(ReviewLog::firstOrFail()->undone_at);
        $this->assertSame(1, OperationChange::count());
    }

    public function test_archived_target_rejects_undo_without_mutating_operation_history(): void
    {
        [$token] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'archived-target');
        $operationId = $this->rate($token, $card)->json('data.operation_id');

        $card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED,
            'fsrs_enabled' => false,
        ])->save();

        $this->transition($token, $operationId, 'undo', 1)
            ->assertConflict()
            ->assertJsonPath('error.code', 'OPERATION_TARGET_UNAVAILABLE');

        $this->assertSame(Operation::STATUS_APPLIED, Operation::firstOrFail()->status);
        $this->assertNull(ReviewLog::firstOrFail()->undone_at);
        $this->assertSame(1, OperationChange::count());
        $this->assertSame(ReviewCard::LIFECYCLE_ARCHIVED, $card->fresh()->lifecycle_state);
    }

    public function test_operation_history_survives_review_log_deletion(): void
    {
        [$token] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'deleted-log');
        $operationId = $this->rate($token, $card)->json('data.operation_id');

        ReviewLog::firstOrFail()->delete();

        $this->assertDatabaseHas('operations', [
            'operation_id' => $operationId,
            'review_log_id' => null,
            'status' => Operation::STATUS_APPLIED,
        ]);
        $this->withToken($token)
            ->getJson('/api/v1/mobile/operations')
            ->assertOk()
            ->assertJsonPath('data.operations.0.operation_id', $operationId);
        $this->transition($token, $operationId, 'undo', 1)
            ->assertConflict()
            ->assertJsonPath('error.code', 'OPERATION_TARGET_UNAVAILABLE');
        $this->assertSame(1, OperationChange::count());
    }

    public function test_operations_are_user_and_language_isolated_but_account_visible_across_devices(): void
    {
        [$firstToken, $firstDevice] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'isolation');
        $sessionId = (string) Str::uuid();
        $operationId = $this->rate($firstToken, $card, $sessionId)
            ->json('data.operation_id');

        [$secondToken, $secondDevice] = $this->issueToken($this->user);
        $this->withToken($secondToken)
            ->getJson("/api/v1/mobile/operations?review_session_id={$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.operations.0.operation_id', $operationId)
            ->assertJsonPath('data.operations.0.source_device_uuid', $firstDevice->device_uuid);

        $this->transition($secondToken, $operationId, 'undo', 1)
            ->assertOk();
        $this->assertDatabaseHas('operation_changes', [
            'transition' => OperationChange::TRANSITION_UNDO,
            'actor_mobile_device_id' => $secondDevice->id,
        ]);

        $other = $this->createUser('other-m2@example.com', 'english');
        [$otherToken] = $this->issueToken($other);
        $this->withToken($otherToken)
            ->getJson('/api/v1/mobile/operations')
            ->assertOk()
            ->assertJsonCount(0, 'data.operations');
        $this->transition($otherToken, $operationId, 'redo', 2)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'OPERATION_NOT_FOUND');

        $this->user->forceFill(['selected_language' => 'spanish'])->save();
        $this->withToken($secondToken)
            ->getJson('/api/v1/mobile/operations')
            ->assertOk()
            ->assertJsonCount(0, 'data.operations');
    }

    public function test_ledger_registration_failure_rolls_back_rating_claim_log_and_card(): void
    {
        [$token] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'rollback');

        $ledger = $this->mock(MobileOperationLedgerService::class);
        $ledger->shouldReceive('registerRating')
            ->once()
            ->andThrow(new RuntimeException('forced ledger failure'));

        $this->rate($token, $card)
            ->assertInternalServerError()
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertJsonMissing(['message' => 'forced ledger failure']);

        $this->assertSame(0, MobileClientAction::count());
        $this->assertSame(0, Operation::count());
        $this->assertSame(0, OperationChange::count());
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, $card->fresh()->fsrs_reps);
    }

    private function rate(
        string $token,
        ReviewCard $card,
        ?string $sessionId = null,
        ?string $clientActionId = null,
    ) {
        return $this->withToken($token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", [
                'rating' => 'good',
                'client_action_id' => $clientActionId ?? (string) Str::uuid(),
                'review_session_id' => $sessionId,
                'review_duration_ms' => 1000,
            ]);
    }

    private function transition(
        string $token,
        string $operationId,
        string $direction,
        int $expectedVersion,
        ?string $clientActionId = null,
    ) {
        return $this->withToken($token)
            ->postJson("/api/v1/mobile/operations/{$operationId}/{$direction}", [
                'client_action_id' => $clientActionId ?? (string) Str::uuid(),
                'expected_version' => $expectedVersion,
            ]);
    }

    private function issueToken(User $user): array
    {
        $deviceUuid = (string) Str::uuid();
        $response = $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => $deviceUuid,
            'platform' => 'android',
            'device_name' => 'M2 test device',
            'app_version' => '1.0.0',
        ])->assertCreated();

        $result = [
            $response->json('data.token'),
            MobileDevice::where('user_id', $user->id)
                ->where('device_uuid', $deviceUuid)
                ->firstOrFail(),
        ];

        // Multiple token requests share one PHPUnit application instance.
        // Real HTTP requests do not share the guard's cached token model.
        $this->app['auth']->forgetGuards();

        return $result;
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

    private function createSenseCard(User $user, string $lemma): ReviewCard
    {
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => $user->selected_language,
            'language_id' => $user->selected_language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "{$user->id}|{$user->selected_language}|{$lemma}"),
        ]);

        return app(ReviewCardService::class)->ensureSenseCard($sense);
    }
}
