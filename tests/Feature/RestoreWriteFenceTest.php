<?php

namespace Tests\Feature;

use App\Exceptions\BackupException;
use App\Services\RestoreWriteFence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestoreWriteFenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'backup.restore_coordination_store' => 'array',
            'cache.default' => 'array',
        ]);
    }

    public function test_global_fence_rejects_mutating_http_before_route_logic(): void
    {
        $operationId = (string) Str::uuid();
        app(RestoreWriteFence::class)->activate($operationId);

        try {
            $this->postJson('/login', [
                'email' => 'nobody@example.test',
                'password' => 'not-used',
            ])
                ->assertServiceUnavailable()
                ->assertJsonPath('error.code', 'RESTORE_WRITE_FENCE_ACTIVE');
        } finally {
            app(RestoreWriteFence::class)->deactivate($operationId);
        }
    }

    public function test_only_owning_operation_can_release_fence(): void
    {
        $owner = (string) Str::uuid();
        app(RestoreWriteFence::class)->activate($owner);
        app(RestoreWriteFence::class)->deactivate((string) Str::uuid());
        $this->assertTrue(app(RestoreWriteFence::class)->active());

        app(RestoreWriteFence::class)->deactivate($owner);
        $this->assertFalse(app(RestoreWriteFence::class)->active());
    }

    public function test_connection_resolved_before_fence_rechecks_immediately_before_write(): void
    {
        $connection = DB::connection();
        $connection->select('SELECT 1');
        $operationId = (string) Str::uuid();
        app(RestoreWriteFence::class)->activate($operationId);

        try {
            $connection->update(
                'UPDATE `settings` SET `value` = ? WHERE 1 = 0',
                ['must-not-write'],
            );
            $this->fail('Expected the database-level write guard to reject the late write.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_RESTORE_WRITE_FENCE_ACTIVE', $exception->errorCode);
            $this->assertSame(503, $exception->httpStatus);
        } finally {
            app(RestoreWriteFence::class)->deactivate($operationId);
        }
    }
}
