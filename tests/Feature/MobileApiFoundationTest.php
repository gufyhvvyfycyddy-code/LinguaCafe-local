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
use App\Services\FsrsSchedulingService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MobileApiFoundationTest extends TestCase
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

        $this->user = $this->createUser('mobile@example.com', 'english');
    }

    public function test_token_creation_converges_legacy_language_and_returns_stable_device_envelope(): void
    {
        $this->user->forceFill(['selected_language' => 'japanese'])->save();
        $deviceUuid = (string) Str::uuid();

        $response = $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $this->user->email,
            'password' => 'password',
            'device_uuid' => $deviceUuid,
            'platform' => 'android',
            'device_name' => 'Pixel Test',
            'app_version' => '1.0.0',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.device.device_uuid', $deviceUuid)
            ->assertJsonPath('data.device.platform', 'android')
            ->assertJsonPath('meta.schema_version', 1)
            ->assertJsonPath('meta.minimum_client_version', '1.0.0')
            ->assertJsonStructure([
                'data' => ['token', 'token_type', 'device'],
                'meta' => ['server_time', 'schema_version', 'minimum_client_version'],
            ]);

        $this->assertDatabaseHas('mobile_devices', [
            'user_id' => $this->user->id,
            'device_uuid' => $deviceUuid,
            'revoked_at' => null,
        ]);
        $this->assertNotNull(MobileDevice::first()->personal_access_token_id);
        $this->assertSame(1, $this->user->tokens()->count());
        $this->assertSame('english', $this->user->refresh()->selected_language);
    }

    public function test_invalid_credentials_use_safe_mobile_error_envelope(): void
    {
        $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $this->user->email,
            'password' => 'wrong-password',
            'device_uuid' => (string) Str::uuid(),
            'platform' => 'android',
            'app_version' => '1.0.0',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS')
            ->assertJsonMissing(['password' => 'wrong-password'])
            ->assertJsonStructure(['error' => ['code', 'message'], 'meta' => ['server_time']]);
    }

    public function test_mobile_authentication_and_validation_failures_use_the_same_envelope(): void
    {
        $this->getJson('/api/v1/mobile/bootstrap')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('meta.schema_version', 1);

        $this->postJson('/api/v1/mobile/auth/tokens', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details']]);
    }

    public function test_bootstrap_catches_up_stale_language_and_keeps_current_language_contract(): void
    {
        [$token, $device] = $this->issueToken($this->user);
        $this->user->forceFill(['selected_language' => 'japanese'])->save();

        $this->withToken($token)
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonPath('data.current_language', 'english')
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonPath('data.schema_version', 1)
            ->assertJsonPath('data.device.device_uuid', $device->device_uuid)
            ->assertJsonPath('data.capabilities.formal_sense_review', true)
            ->assertJsonPath('data.capabilities.operation_ledger', true)
            ->assertJsonPath('data.capabilities.operation_undo_redo', true)
            ->assertJsonPath('data.capabilities.unified_read_only_search', true)
            ->assertJsonPath('data.capabilities.offline_queue', true)
            ->assertJsonPath('data.readiness.database', true)
            ->assertJsonPath('data.readiness.selected_language', true)
            ->assertJsonMissingPath('data.user.password');

        $this->assertSame('english', $this->user->refresh()->selected_language);
    }

    public function test_reissuing_a_device_token_revokes_the_prior_token_binding(): void
    {
        [$firstToken, $device] = $this->issueToken($this->user);
        [$secondToken] = $this->issueToken($this->user, $device->device_uuid);

        $this->withToken($firstToken)
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertUnauthorized();

        $this->withToken($secondToken)
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertOk();

        $this->assertSame(1, $this->user->tokens()->count());
    }

    public function test_device_revocation_is_user_scoped_and_invalidates_its_token(): void
    {
        [$token, $device] = $this->issueToken($this->user);

        $this->withToken($token)
            ->deleteJson("/api/v1/mobile/devices/{$device->device_uuid}")
            ->assertOk()
            ->assertJsonPath('data.device.revoked', true);

        $device->refresh();
        $this->assertNotNull($device->revoked_at);
        $this->assertNull($device->personal_access_token_id);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertUnauthorized();
    }

    public function test_revoked_device_can_re_register_with_credentials_while_old_token_stays_invalid(): void
    {
        [$oldToken, $device] = $this->issueToken($this->user);

        $this->withToken($oldToken)
            ->deleteJson("/api/v1/mobile/devices/{$device->device_uuid}")
            ->assertOk();

        [$newToken, $reRegisteredDevice] = $this->issueToken(
            $this->user,
            $device->device_uuid,
        );

        $this->assertSame($device->id, $reRegisteredDevice->id);
        $this->assertNull($reRegisteredDevice->revoked_at);
        $this->withToken($oldToken)
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($newToken)
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertOk();
        $this->assertSame(1, $this->user->tokens()->count());
    }

    public function test_device_revocation_cannot_target_another_users_device(): void
    {
        [$token] = $this->issueToken($this->user);
        $otherUser = $this->createUser('device-owner@example.com', 'english');
        [$otherToken, $otherDevice] = $this->issueToken($otherUser);

        $this->withToken($token)
            ->deleteJson("/api/v1/mobile/devices/{$otherDevice->device_uuid}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'DEVICE_NOT_FOUND');

        $this->withToken($otherToken)
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertOk();

        $this->assertNull($otherDevice->fresh()->revoked_at);
        $this->assertNotNull($otherDevice->fresh()->personal_access_token_id);
    }

    public function test_non_numeric_rating_card_id_returns_not_found(): void
    {
        [$token] = $this->issueToken($this->user);

        $this->withToken($token)
            ->postJson('/api/v1/mobile/review-cards/not-a-number/ratings', [
                'rating' => 'good',
                'client_action_id' => (string) Str::uuid(),
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, MobileClientAction::count());
    }

    public function test_rating_replay_returns_original_operation_without_second_side_effect(): void
    {
        [$token] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'apple');
        $actionId = (string) Str::uuid();
        $payload = [
            'rating' => 'good',
            'client_action_id' => $actionId,
            'review_session_id' => (string) Str::uuid(),
            'review_duration_ms' => 1250,
        ];

        $first = $this->withToken($token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_action_id', $actionId)
            ->assertJsonPath('data.replayed', false);

        $second = $this->withToken($token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.operation_id'), $second->json('data.operation_id'));
        $this->assertSame($first->json('data.review_log_id'), $second->json('data.review_log_id'));
        $this->assertSame(1, ReviewLog::where('review_card_id', $card->id)->count());
        $this->assertSame(1, MobileClientAction::count());
        $this->assertSame(1, Operation::count());
        $this->assertSame(1, OperationChange::count());
        $this->assertSame(1, $card->fresh()->fsrs_reps);
    }

    public function test_rating_replay_returns_original_result_after_card_becomes_unavailable(): void
    {
        [$token] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'apricot');
        $payload = [
            'rating' => 'good',
            'client_action_id' => (string) Str::uuid(),
        ];

        $first = $this->withToken($token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', false);

        $card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED,
            'fsrs_enabled' => false,
        ])->save();

        $replay = $this->withToken($token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.operation_id'), $replay->json('data.operation_id'));
        $this->assertSame($first->json('data.review_log_id'), $replay->json('data.review_log_id'));
        $this->assertSame(1, ReviewLog::where('review_card_id', $card->id)->count());
        $this->assertSame(1, $card->fresh()->fsrs_reps);
    }

    public function test_same_action_id_with_different_payload_returns_conflict_without_new_review(): void
    {
        [$token] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'banana');
        $actionId = (string) Str::uuid();

        $this->withToken($token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", [
                'rating' => 'good',
                'client_action_id' => $actionId,
            ])
            ->assertOk();

        $this->withToken($token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", [
                'rating' => 'easy',
                'client_action_id' => $actionId,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, ReviewLog::where('review_card_id', $card->id)->count());
        $this->assertSame(1, $card->fresh()->fsrs_reps);
    }

    public function test_rating_isolated_by_user_language_and_device(): void
    {
        [$token] = $this->issueToken($this->user);
        $otherUser = $this->createUser('other@example.com', 'english');
        $otherCard = $this->createSenseCard($otherUser, 'cherry');

        $this->withToken($token)
            ->postJson("/api/v1/mobile/review-cards/{$otherCard->id}/ratings", [
                'rating' => 'good',
                'client_action_id' => (string) Str::uuid(),
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'REVIEW_CARD_NOT_FOUND');

        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, MobileClientAction::count());
    }

    public function test_rating_rejects_a_card_from_the_users_non_selected_language(): void
    {
        $this->user->forceFill(['selected_language' => 'spanish'])->save();
        $card = $this->createSenseCard($this->user, 'fresa');
        $this->user->forceFill(['selected_language' => 'english'])->save();
        [$token] = $this->issueToken($this->user);

        $this->withToken($token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", [
                'rating' => 'good',
                'client_action_id' => (string) Str::uuid(),
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'REVIEW_CARD_NOT_FOUND');

        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, MobileClientAction::count());
    }

    public function test_database_claim_key_allows_only_one_row_for_retry_identity(): void
    {
        [, $device] = $this->issueToken($this->user);
        $clientActionId = (string) Str::uuid();
        $attributes = [
            'operation_id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'mobile_device_id' => $device->id,
            'action_type' => 'sense_review.rating',
            'client_action_id' => $clientActionId,
            'request_hash' => str_repeat('a', 64),
            'status' => MobileClientAction::STATUS_PROCESSING,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->assertSame(1, MobileClientAction::query()->insertOrIgnore($attributes));

        $attributes['operation_id'] = (string) Str::uuid();
        $this->assertSame(0, MobileClientAction::query()->insertOrIgnore($attributes));
        $this->assertSame(1, MobileClientAction::count());
    }

    public function test_unexpected_rating_failure_rolls_back_action_log_and_card(): void
    {
        Log::spy();
        [$token] = $this->issueToken($this->user);
        $card = $this->createSenseCard($this->user, 'dragonfruit');

        $scheduler = $this->mock(FsrsSchedulingService::class);
        $scheduler->shouldReceive('schedule')->once()->andThrow(new RuntimeException('forced failure'));

        $this->withToken($token)
            ->postJson("/api/v1/mobile/review-cards/{$card->id}/ratings", [
                'rating' => 'good',
                'client_action_id' => (string) Str::uuid(),
            ])
            ->assertInternalServerError()
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertJsonMissing(['message' => 'forced failure']);

        $this->assertSame(0, MobileClientAction::count());
        $this->assertSame(0, Operation::count());
        $this->assertSame(0, OperationChange::count());
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, $card->fresh()->fsrs_reps);
        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) use ($card): bool {
                return $message === 'Unhandled mobile API exception.'
                    && $context['exception'] === RuntimeException::class
                    && $context['method'] === 'POST'
                    && $context['path'] === "api/v1/mobile/review-cards/{$card->id}/ratings"
                    && $context['user_id'] === $this->user->id
                    && ! array_key_exists('request_body', $context)
                    && ! array_key_exists('authorization', $context);
            })
            ->atLeast()
            ->once();
    }

    public function test_existing_web_rating_contract_remains_compatible(): void
    {
        $card = $this->createSenseCard($this->user, 'elderberry');

        $this->actingAs($this->user)
            ->postJson("/reviews/senses/{$card->id}/rate", ['rating' => 'good'])
            ->assertOk()
            ->assertJsonStructure([
                'reviewed_card',
                'next_card',
                'summary',
                'action' => ['review_log_id', 'rating', 'rating_label', 'undoable'],
            ])
            ->assertJsonMissing(['success' => true])
            ->assertJsonMissingPath('meta.schema_version');

        $this->assertSame(1, ReviewLog::count());
        $this->assertSame(0, MobileClientAction::count());
    }

    private function issueToken(User $user, ?string $deviceUuid = null): array
    {
        $deviceUuid ??= (string) Str::uuid();

        $response = $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => $deviceUuid,
            'platform' => 'android',
            'device_name' => 'Test device',
            'app_version' => '1.0.0',
        ])->assertCreated();

        return [
            $response->json('data.token'),
            MobileDevice::where('user_id', $user->id)
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
