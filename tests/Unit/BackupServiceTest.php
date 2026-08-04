<?php

namespace Tests\Unit;

use App\Exceptions\BackupException;
use App\Services\BackupService;
use App\Services\DatabaseDumpProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BackupServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backup');
        config([
            'backup.disk' => 'backup',
            'backup.max_backups' => 14,
            'backup.lock_seconds' => 30,
            'backup.application_version' => 'test-version',
            'backup.restore_coordination_store' => 'array',
            'cache.default' => 'array',
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'testing_database',
        ]);
        Carbon::setTestNow('2026-07-28 08:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_successful_backup_publishes_verified_manifest_and_payload(): void
    {
        $service = $this->serviceWriting('CREATE TABLE example (id INT);');

        $backup = $service->createBackup();
        $backups = $service->listBackups();

        $this->assertCount(1, $backups);
        $this->assertSame($backup['backup_id'], $backups[0]['backup_id']);
        $this->assertSame('successful', $backup['status']);
        $this->assertSame(['database', 'articles', 'article_structure'], $backup['included_scopes']);
        $this->assertSame('test-version', $backup['application_version']);
        $this->assertSame(64, strlen($backup['sha256']));
        Storage::disk('backup')->assertExists($backup['payload_file']);
        Storage::disk('backup')->assertExists(
            str_replace('.sql.gz', '.json', $backup['payload_file']),
        );
        $this->assertSame(
            'CREATE TABLE example (id INT);',
            gzdecode(Storage::disk('backup')->get($backup['payload_file'])),
        );
        Storage::disk('backup')->assertMissing(".tmp/{$backup['backup_id']}/database.sql");
    }

    public function test_failed_dump_preserves_previous_successful_backup(): void
    {
        $service = $this->serviceWriting('SELECT 1;');
        $existing = $service->createBackup();
        $runner = Mockery::mock(DatabaseDumpProcess::class);
        $runner->shouldReceive('dump')
            ->once()
            ->andThrow(new BackupException(
                'BACKUP_DATABASE_DUMP_FAILED',
                'The database backup process failed.',
            ));
        $failingService = new BackupService($runner);

        try {
            $failingService->createBackup();
            $this->fail('Expected backup failure.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_DATABASE_DUMP_FAILED', $exception->errorCode);
        }

        $this->assertCount(1, $failingService->listBackups());
        Storage::disk('backup')->assertExists($existing['payload_file']);
        Storage::disk('backup')->assertExists(
            str_replace('.sql.gz', '.json', $existing['payload_file']),
        );
    }

    public function test_retention_runs_after_publish_and_keeps_newest_backups(): void
    {
        config(['backup.max_backups' => 2]);
        $service = $this->serviceWriting('SELECT 1;', 3);
        $first = $service->createBackup();
        Carbon::setTestNow('2026-07-28 08:01:00 UTC');
        $second = $service->createBackup();
        Carbon::setTestNow('2026-07-28 08:02:00 UTC');
        $third = $service->createBackup();

        $backups = $service->listBackups();

        $this->assertSame(
            [$third['backup_id'], $second['backup_id']],
            array_column($backups, 'backup_id'),
        );
        Storage::disk('backup')->assertMissing($first['payload_file']);
        Storage::disk('backup')->assertExists($second['payload_file']);
        Storage::disk('backup')->assertExists($third['payload_file']);
    }

    public function test_list_ignores_malformed_and_traversing_manifests(): void
    {
        $service = $this->serviceWriting('SELECT 1;');
        $valid = $service->createBackup();
        $malformedId = '11111111-1111-4111-8111-111111111111';
        Storage::disk('backup')->put(
            "linguacafe_20260728_080100_{$malformedId}.json",
            '{"not":"a manifest"}',
        );
        $traversingId = '22222222-2222-4222-8222-222222222222';
        $traversing = $valid;
        $traversing['backup_id'] = $traversingId;
        $traversing['payload_file'] = '../outside.sql.gz';
        Storage::disk('backup')->put(
            "linguacafe_20260728_080200_{$traversingId}.json",
            json_encode($traversing, JSON_THROW_ON_ERROR),
        );
        Storage::disk('backup')->put('.tmp/incomplete.sql.gz', 'partial');
        $aliasId = '33333333-3333-4333-8333-333333333333';
        $alias = $valid;
        $alias['backup_id'] = $aliasId;
        Storage::disk('backup')->put(
            "linguacafe_20260728_080300_{$aliasId}.json",
            json_encode($alias, JSON_THROW_ON_ERROR),
        );

        $backups = $service->listBackups();

        $this->assertCount(1, $backups);
        $this->assertSame($valid['backup_id'], $backups[0]['backup_id']);
    }

    public function test_invalid_retention_fails_before_dump_or_deletion(): void
    {
        config(['backup.max_backups' => 0]);
        Storage::disk('backup')->put('unrelated.txt', 'keep');
        $runner = Mockery::mock(DatabaseDumpProcess::class);
        $runner->shouldNotReceive('dump');

        try {
            (new BackupService($runner))->createBackup();
            $this->fail('Expected invalid configuration failure.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_CONFIGURATION_INVALID', $exception->errorCode);
            $this->assertSame(503, $exception->httpStatus);
        }

        Storage::disk('backup')->assertExists('unrelated.txt');
    }

    public function test_concurrent_backup_lock_rejects_second_creator_before_dump(): void
    {
        $lock = Cache::store('array')->lock('backup:create', 30);
        $this->assertTrue($lock->get());
        $runner = Mockery::mock(DatabaseDumpProcess::class);
        $runner->shouldNotReceive('dump');

        try {
            (new BackupService($runner))->createBackup();
            $this->fail('Expected concurrent backup conflict.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_ALREADY_RUNNING', $exception->errorCode);
            $this->assertSame(409, $exception->httpStatus);
        } finally {
            $lock->release();
        }
    }

    public function test_lock_backend_failure_is_sanitized_before_dump(): void
    {
        $runner = Mockery::mock(DatabaseDumpProcess::class);
        $runner->shouldNotReceive('dump');
        Cache::shouldReceive('store')
            ->once()
            ->with('array')
            ->andThrow(new RuntimeException('secret cache endpoint'));

        try {
            (new BackupService($runner))->createBackup();
            $this->fail('Expected unavailable backup service.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_UNAVAILABLE', $exception->errorCode);
            $this->assertSame(503, $exception->httpStatus);
            $this->assertSame('The backup service is unavailable.', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('backup')->files());
    }

    public function test_unexpected_runner_failure_is_sanitized_and_leaves_no_public_backup(): void
    {
        $runner = Mockery::mock(DatabaseDumpProcess::class);
        $runner->shouldReceive('dump')
            ->once()
            ->andThrow(new RuntimeException('secret internal process text'));

        try {
            (new BackupService($runner))->createBackup();
            $this->fail('Expected sanitized backup failure.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_FAILED', $exception->errorCode);
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }

        $this->assertSame([], (new BackupService($runner))->listBackups());
    }

    private function serviceWriting(string $contents, int $times = 1): BackupService
    {
        $runner = Mockery::mock(DatabaseDumpProcess::class);
        $runner->shouldReceive('dump')
            ->times($times)
            ->andReturnUsing(function (string $path) use ($contents) {
                file_put_contents($path, $contents);
            });

        return new BackupService($runner);
    }
}
