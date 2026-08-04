<?php

namespace Tests\Unit;

use App\Exceptions\BackupException;
use App\Services\DatabaseDumpProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DatabaseDumpProcessTest extends TestCase
{
    public function test_dump_uses_argument_boundaries_and_keeps_password_out_of_command(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'host' => 'database.example',
                'port' => 3307,
                'database' => 'linguacafe_testing',
                'username' => 'backup-user',
                'password' => 'secret with shell ; metacharacters',
            ],
            'backup.mysqldump_binary' => 'mysqldump',
            'backup.process_timeout_seconds' => 123,
        ]);
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result()]);
        $outputPath = tempnam(sys_get_temp_dir(), 'lc-dump-');

        try {
            app(DatabaseDumpProcess::class)->dump($outputPath);
        } finally {
            if (is_string($outputPath)) {
                @unlink($outputPath);
            }
        }

        Process::assertRan(function (PendingProcess $process, ProcessResult $result) {
            $encodedCommand = json_encode($process->command, JSON_THROW_ON_ERROR);
            $hasWindowsRuntimeEnvironment = PHP_OS_FAMILY !== 'Windows'
                || collect(['SystemRoot', 'WINDIR', 'ComSpec'])->every(
                    fn (string $key) => getenv($key) === false
                        || $process->environment[$key] === getenv($key),
                );

            return is_array($process->command)
                && $process->command[0] === 'mysqldump'
                && in_array('--host=database.example', $process->command, true)
                && in_array('--port=3307', $process->command, true)
                && in_array('--user=backup-user', $process->command, true)
                && in_array('--hex-blob', $process->command, true)
                && in_array('--skip-add-locks', $process->command, true)
                && in_array('--skip-disable-keys', $process->command, true)
                && in_array('--skip-triggers', $process->command, true)
                && in_array('--skip-comments', $process->command, true)
                && end($process->command) === 'linguacafe_testing'
                && ! str_contains($encodedCommand, 'secret with shell')
                && $process->environment['MYSQL_PWD'] === 'secret with shell ; metacharacters'
                && $hasWindowsRuntimeEnvironment
                && $process->timeout === 123;
        });
    }

    public function test_unsupported_database_driver_fails_before_process_execution(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.driver' => 'sqlite',
        ]);
        Process::preventStrayProcesses();
        Process::fake();

        try {
            app(DatabaseDumpProcess::class)->dump('unused.sql');
            $this->fail('Expected unsupported driver failure.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_DRIVER_UNSUPPORTED', $exception->errorCode);
            $this->assertSame(422, $exception->httpStatus);
        }

        Process::assertNothingRan();
    }

    public function test_process_failure_returns_sanitized_error(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'host' => 'database.example',
                'port' => 3306,
                'database' => 'linguacafe_testing',
                'username' => 'backup-user',
                'password' => 'top-secret',
            ],
        ]);
        Process::preventStrayProcesses();
        Process::fake([
            '*' => Process::result(
                errorOutput: 'mysqldump leaked top-secret internals',
                exitCode: 2,
            ),
        ]);
        $outputPath = tempnam(sys_get_temp_dir(), 'lc-dump-');

        try {
            app(DatabaseDumpProcess::class)->dump($outputPath);
            $this->fail('Expected process failure.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_DATABASE_DUMP_FAILED', $exception->errorCode);
            $this->assertStringNotContainsString('top-secret', $exception->getMessage());
        } finally {
            if (is_string($outputPath)) {
                @unlink($outputPath);
            }
        }
    }
}
