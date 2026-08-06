<?php

namespace Tests\Feature;

use App\Exceptions\ReviewCardManualOperationException;
use App\Models\Operation;
use App\Models\OperationChange;
use App\Models\ReviewCard;
use App\Models\ReviewCardStateEvent;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\MobileOperationLedgerService;
use App\Services\ReviewCardManualOperationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class M11ManualOperationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_testing_acceptance_sentinel_proves_environment_and_database(): void
    {
        $sentinel = '__testing_acceptance_sentinel_' . Str::uuid();
        putenv("LINGUACAFE_TEST_SENTINEL={$sentinel}");
        $_ENV['LINGUACAFE_TEST_SENTINEL'] = $sentinel;
        DB::table('migrations')->insert([
            'migration' => $sentinel,
            'batch' => 0,
        ]);

        try {
            $this->getJson('/__testing/acceptance-sentinel')
                ->assertOk()
                ->assertExactJson([
                    'environment' => 'testing',
                    'database_is_testing' => true,
                    'sentinel_present' => true,
                ]);
        } finally {
            DB::table('migrations')->where('migration', $sentinel)->delete();
            putenv('LINGUACAFE_TEST_SENTINEL');
            unset($_ENV['LINGUACAFE_TEST_SENTINEL']);
        }
    }

    private User $user;
    private ReviewCard $card;
    private ReviewCardManualOperationService $manualOperations;
    private MobileOperationLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'name' => 'm11-owner',
            'email' => 'm11-owner@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
        ]);
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'lemma' => 'manual',
            'surface_form' => 'manual',
            'pos' => 'adjective',
            'sense_zh' => '手动的',
            'sense_en' => 'done by a person',
            'status' => WordSense::STATUS_CONFIRMED,
            'sense_key' => hash('sha256', 'm11-manual'),
        ]);
        $this->card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->addDays(5),
            'fsrs_stability' => 8.5,
            'fsrs_difficulty' => 4.2,
            'fsrs_reps' => 7,
            'fsrs_lapses' => 3,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(2),
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'lifecycle_version' => 0,
        ]);
        $this->manualOperations = app(ReviewCardManualOperationService::class);
        $this->ledger = app(MobileOperationLedgerService::class);
    }

    public function test_due_now_is_previewed_registered_and_undoable_without_review_log(): void
    {
        $originalDue = $this->card->fsrs_due_at->toIso8601String();
        $preview = $this->preview(ReviewCardManualOperationService::ACTION_DUE_NOW);
        $operationId = (string) Str::uuid();

        $result = $this->apply($operationId, $preview);

        $this->assertFalse($result['already_applied']);
        $this->assertSame(Operation::TYPE_MANUAL_DUE_NOW, $result['operation']['operation_type']);
        $this->assertSame('web', $result['operation']['source_channel']);
        $this->assertTrue($result['operation']['can_undo']);
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(1, Operation::count());
        $this->assertSame(1, OperationChange::count());

        $undone = $this->ledger->undo(
            $operationId,
            $this->user->id,
            'english',
            null,
            1,
            (string) Str::uuid(),
            'web',
        );
        $this->assertSame($originalDue, $undone['card']['fsrs_due_at']);
        $this->assertSame(Operation::STATUS_UNDONE, $undone['operation']['status']);

        $redone = $this->ledger->redo(
            $operationId,
            $this->user->id,
            'english',
            null,
            2,
            (string) Str::uuid(),
            'web',
        );
        $this->assertSame(Operation::STATUS_APPLIED, $redone['operation']['status']);
        $this->assertNotSame($originalDue, $redone['card']['fsrs_due_at']);
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_suspend_and_undo_preserve_lifecycle_audit_and_fsrs_memory(): void
    {
        $beforeStability = $this->card->fsrs_stability;
        $preview = $this->preview(ReviewCardManualOperationService::ACTION_SUSPEND);
        $operationId = (string) Str::uuid();
        $this->apply($operationId, $preview);

        $this->card->refresh();
        $this->assertSame(ReviewCard::LIFECYCLE_SUSPENDED, $this->card->lifecycle_state);
        $this->assertFalse($this->card->fsrs_enabled);
        $this->assertSame($beforeStability, $this->card->fsrs_stability);
        $this->assertSame(1, ReviewCardStateEvent::where('action', 'suspend')->count());

        $this->ledger->undo(
            $operationId,
            $this->user->id,
            'english',
            null,
            1,
            (string) Str::uuid(),
            'web',
        );
        $this->card->refresh();
        $this->assertSame(ReviewCard::LIFECYCLE_ACTIVE, $this->card->lifecycle_state);
        $this->assertTrue($this->card->fsrs_enabled);
        $this->assertSame($beforeStability, $this->card->fsrs_stability);
        $this->assertSame(1, ReviewCardStateEvent::where('action', 'operation_undo')->count());
    }

    public function test_reset_without_count_reset_preserves_counts_and_history_and_can_be_undone(): void
    {
        $preview = $this->preview(
            ReviewCardManualOperationService::ACTION_RESET_NEW,
            ['reset_counts' => false],
        );
        $operationId = (string) Str::uuid();
        $this->apply($operationId, $preview);

        $this->card->refresh();
        $this->assertSame('new', $this->card->fsrs_state);
        $this->assertSame(7, $this->card->fsrs_reps);
        $this->assertSame(3, $this->card->fsrs_lapses);
        $this->assertNull($this->card->fsrs_stability);
        $this->assertSame(1, ReviewLog::where('rating', 'reset')->count());

        $this->ledger->undo(
            $operationId,
            $this->user->id,
            'english',
            null,
            1,
            (string) Str::uuid(),
            'web',
        );
        $this->card->refresh();
        $this->assertSame('review', $this->card->fsrs_state);
        $this->assertSame(7, $this->card->fsrs_reps);
        $this->assertSame(3, $this->card->fsrs_lapses);
        $this->assertSame(8.5, $this->card->fsrs_stability);
        $this->assertNotNull(ReviewLog::firstOrFail()->undone_at);
        $this->assertSame(1, ReviewLog::count(), 'Undo must preserve reset history.');
    }

    public function test_stale_preview_and_request_id_payload_change_fail_without_mutation(): void
    {
        $preview = $this->preview(ReviewCardManualOperationService::ACTION_DUE_NOW);
        $this->card->update(['fsrs_reps' => 8]);

        try {
            $this->apply((string) Str::uuid(), $preview);
            $this->fail('Expected stale preview conflict.');
        } catch (ReviewCardManualOperationException $exception) {
            $this->assertSame('MANUAL_OPERATION_STATE_CHANGED', $exception->errorCode);
        }
        $this->assertSame(0, Operation::count());

        $freshPreview = $this->preview(ReviewCardManualOperationService::ACTION_DUE_NOW);
        $operationId = (string) Str::uuid();
        $this->apply($operationId, $freshPreview);
        $resetPreview = $this->preview(
            ReviewCardManualOperationService::ACTION_RESET_NEW,
            ['reset_counts' => true],
        );

        try {
            $this->manualOperations->apply(
                $operationId,
                $this->user->id,
                'english',
                $this->card->id,
                ReviewCardManualOperationService::ACTION_RESET_NEW,
                $resetPreview['action_payload'],
                $resetPreview['expected_state_fingerprint'],
                'UTC',
                'web',
            );
            $this->fail('Expected request payload conflict.');
        } catch (ReviewCardManualOperationException $exception) {
            $this->assertSame('MANUAL_OPERATION_REQUEST_CONFLICT', $exception->errorCode);
        }
        $this->assertSame(1, Operation::count());
    }

    public function test_target_scope_is_user_and_language_isolated(): void
    {
        $other = User::forceCreate([
            'name' => 'm11-other',
            'email' => 'm11-other@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
        ]);

        $this->expectException(ReviewCardManualOperationException::class);
        try {
            $this->manualOperations->preview(
                $other->id,
                'english',
                $this->card->id,
                ReviewCardManualOperationService::ACTION_DUE_NOW,
                [],
                'UTC',
            );
        } catch (ReviewCardManualOperationException $exception) {
            $this->assertSame(404, $exception->status);
            $this->assertSame('MANUAL_OPERATION_TARGET_NOT_FOUND', $exception->errorCode);
            throw $exception;
        }
    }

    public function test_web_preview_apply_and_lifo_transition_contract(): void
    {
        $preview = $this->actingAs($this->user)
            ->postJson(
                "/review-cards/{$this->card->id}/manual-operations/preview",
                [
                    'action' => ReviewCardManualOperationService::ACTION_SET_DUE,
                    'options' => ['due_date' => Carbon::tomorrow()->format('Y-m-d')],
                ],
            )
            ->assertOk()
            ->assertJsonStructure([
                'review_card_id',
                'action',
                'action_payload' => ['due_date', 'due_at'],
                'expected_state_fingerprint',
                'before_state',
                'projected_after_state',
            ])
            ->json();
        $operationId = (string) Str::uuid();
        $applied = $this->actingAs($this->user)
            ->postJson(
                "/review-cards/{$this->card->id}/manual-operations/apply",
                [
                    'operation_id' => $operationId,
                    'action' => $preview['action'],
                    'options' => $preview['action_payload'],
                    'expected_state_fingerprint' => $preview['expected_state_fingerprint'],
                ],
            )
            ->assertOk()
            ->assertJsonPath('operation.operation_id', $operationId)
            ->assertJsonPath('operation.operation_type', Operation::TYPE_MANUAL_SET_DUE)
            ->assertJsonPath('operation.can_undo', true)
            ->json();

        $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$this->card->id}/detail")
            ->assertOk()
            ->assertJsonPath(
                'card_info.manual_operations.items.0.operation_id',
                $operationId,
            )
            ->assertJsonPath(
                'card_info.manual_operations.items.0.operation_type',
                Operation::TYPE_MANUAL_SET_DUE,
            )
            ->assertJsonPath('card_info.manual_operations.items.0.can_undo', true)
            ->assertJsonStructure([
                'card_info' => [
                    'manual_operations' => [
                        'items' => [[
                            'operation_id',
                            'source_channel',
                            'before_state',
                            'after_state',
                        ]],
                        'limit',
                    ],
                ],
            ]);

        $undoRequestId = (string) Str::uuid();
        $this->actingAs($this->user)
            ->postJson("/review-card-operations/{$operationId}/undo", [
                'client_action_id' => $undoRequestId,
                'expected_version' => $applied['operation']['version'],
            ])
            ->assertOk()
            ->assertJsonPath('operation.status', Operation::STATUS_UNDONE)
            ->assertJsonPath('operation.can_redo', true)
            ->assertJsonPath('replayed', false);
        $this->actingAs($this->user)
            ->postJson("/review-card-operations/{$operationId}/undo", [
                'client_action_id' => $undoRequestId,
                'expected_version' => $applied['operation']['version'],
            ])
            ->assertOk()
            ->assertJsonPath('operation.status', Operation::STATUS_UNDONE)
            ->assertJsonPath('replayed', true);
    }

    public function test_mobile_preview_apply_is_device_bound_and_replays(): void
    {
        $token = $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $this->user->email,
            'password' => 'password',
            'device_uuid' => (string) Str::uuid(),
            'platform' => 'android',
            'device_name' => 'M11 Test',
            'app_version' => '1.0.0',
        ])->assertCreated()->json('data.token');
        $headers = ['Authorization' => "Bearer {$token}"];
        $this->withHeaders($headers)
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.capabilities.review_control_manual_operations', true)
            ->assertJsonPath('data.capabilities.review_control_preview', true);
        $preview = $this->withHeaders($headers)
            ->postJson(
                "/api/v1/mobile/review-cards/{$this->card->id}/manual-operations/preview",
                ['action' => ReviewCardManualOperationService::ACTION_BURY_NEXT_DAY],
            )
            ->assertOk()
            ->assertJsonPath('data.action', ReviewCardManualOperationService::ACTION_BURY_NEXT_DAY)
            ->json('data');
        $clientActionId = (string) Str::uuid();
        $payload = [
            'client_action_id' => $clientActionId,
            'action' => $preview['action'],
            'options' => $preview['action_payload'],
            'expected_state_fingerprint' => $preview['expected_state_fingerprint'],
        ];

        $first = $this->withHeaders($headers)
            ->postJson(
                "/api/v1/mobile/review-cards/{$this->card->id}/manual-operations/apply",
                $payload,
            )
            ->assertOk()
            ->assertJsonPath('data.operation.source_channel', 'mobile')
            ->assertJsonPath('data.replayed', false)
            ->json('data');
        $this->withHeaders($headers)
            ->postJson(
                "/api/v1/mobile/review-cards/{$this->card->id}/manual-operations/apply",
                $payload,
            )
            ->assertOk()
            ->assertJsonPath('data.operation.operation_id', $first['operation']['operation_id'])
            ->assertJsonPath('data.replayed', true);

        $this->assertSame(1, Operation::count());
        $this->assertSame(1, OperationChange::count());
    }

    public function test_bury_resume_and_lifo_are_one_manual_stack(): void
    {
        $buryPreview = $this->preview(
            ReviewCardManualOperationService::ACTION_BURY_NEXT_DAY,
        );
        $this->assertSame(
            ReviewCard::LIFECYCLE_BURIED,
            $buryPreview['projected_after_state']['lifecycle']['lifecycle_state'],
        );
        $this->assertNotNull(
            $buryPreview['projected_after_state']['lifecycle']['buried_until'],
        );
        $buryId = (string) Str::uuid();
        $this->apply($buryId, $buryPreview);
        $undoBury = $this->ledger->undo(
            $buryId,
            $this->user->id,
            'english',
            null,
            1,
            (string) Str::uuid(),
            'web',
        );
        $this->assertSame(
            ReviewCard::LIFECYCLE_ACTIVE,
            $undoBury['card']['lifecycle_state'],
        );

        $suspendPreview = $this->preview(
            ReviewCardManualOperationService::ACTION_SUSPEND,
        );
        $suspendId = (string) Str::uuid();
        $this->apply($suspendId, $suspendPreview);
        $resumePreview = $this->preview(
            ReviewCardManualOperationService::ACTION_RESUME,
        );
        $resumeId = (string) Str::uuid();
        $resume = $this->apply($resumeId, $resumePreview);
        $this->assertTrue($resume['operation']['can_undo']);
        $this->assertFalse($this->ledger->present(
            Operation::where('operation_id', $suspendId)->firstOrFail(),
        )['can_undo']);

        $undoResume = $this->ledger->undo(
            $resumeId,
            $this->user->id,
            'english',
            null,
            1,
            (string) Str::uuid(),
            'web',
        );
        $this->assertSame(
            ReviewCard::LIFECYCLE_SUSPENDED,
            $undoResume['card']['lifecycle_state'],
        );
        $this->assertTrue($this->ledger->present(
            Operation::where('operation_id', $suspendId)->firstOrFail(),
        )['can_undo']);

        $duePreview = $this->preview(
            ReviewCardManualOperationService::ACTION_DUE_NOW,
        );
        $this->apply((string) Str::uuid(), $duePreview);
        $this->assertSame(
            Operation::STATUS_SUPERSEDED,
            Operation::where('operation_id', $resumeId)->value('status'),
        );
    }

    public function test_reset_with_count_reset_zeros_counts(): void
    {
        $preview = $this->preview(
            ReviewCardManualOperationService::ACTION_RESET_NEW,
            ['reset_counts' => true],
        );
        $this->apply((string) Str::uuid(), $preview);

        $this->card->refresh();
        $this->assertSame(0, $this->card->fsrs_reps);
        $this->assertSame(0, $this->card->fsrs_lapses);
        $this->assertSame(true, Operation::firstOrFail()->action_payload['reset_counts']);
    }

    private function preview(string $action, array $options = []): array
    {
        return $this->manualOperations->preview(
            $this->user->id,
            'english',
            $this->card->id,
            $action,
            $options,
            'UTC',
        );
    }

    private function apply(string $operationId, array $preview): array
    {
        return $this->manualOperations->apply(
            $operationId,
            $this->user->id,
            'english',
            $this->card->id,
            $preview['action'],
            $preview['action_payload'],
            $preview['expected_state_fingerprint'],
            'UTC',
            'web',
        );
    }
}
