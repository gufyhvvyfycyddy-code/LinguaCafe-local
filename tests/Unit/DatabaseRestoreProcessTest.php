<?php

namespace Tests\Unit;

use App\Exceptions\BackupException;
use App\Services\DatabaseRestoreProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DatabaseRestoreProcessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'host' => 'database.example',
                'port' => 3307,
                'database' => 'linguacafe_testing',
                'username' => 'restore-user',
                'password' => 'secret ; value',
            ],
            'backup.mysql_binary' => 'mysql',
            'backup.process_timeout_seconds' => 123,
            'backup.restore_temporary_database_prefix' => 'lc_restore_',
            'backup.restore_quiesce_timeout_seconds' => 2,
            'backup.restore_quiesce_stable_seconds' => 1,
            'backup.restore_validation_host' => 'validation.example',
            'backup.restore_validation_port' => 3308,
            'backup.restore_validation_username' => 'validation-user',
            'backup.restore_validation_password' => 'validation-secret',
        ]);
        Process::preventStrayProcesses();
    }

    public function test_active_restore_streams_input_with_argument_boundaries_and_no_password(): void
    {
        Process::fake(['*' => Process::result()]);
        $path = $this->gzip('CREATE TABLE `users` (`id` bigint);');

        try {
            app(DatabaseRestoreProcess::class)->replaceActive($path);
        } finally {
            @unlink($path);
        }

        Process::assertRan(function (PendingProcess $process, ProcessResult $result) {
            return is_array($process->command)
                && $process->command[0] === 'mysql'
                && in_array('--host=database.example', $process->command, true)
                && in_array('--port=3307', $process->command, true)
                && in_array('--user=restore-user', $process->command, true)
                && end($process->command) === 'linguacafe_testing'
                && $process->input !== null
                && $process->environment['MYSQL_PWD'] === 'secret ; value'
                && ! str_contains(json_encode($process->command), 'secret ; value')
                && $process->timeout === 123;
        });
    }

    public function test_isolated_validation_uses_safe_random_database_and_drops_it(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', $process->command);

            return str_contains($command, 'SHOW TABLES')
                ? Process::result(output: "migrations\nusers\n")
                : Process::result();
        });
        $path = $this->gzip(
            "CREATE TABLE `migrations` (`id` bigint);\nCREATE TABLE `users` (`id` bigint);",
        );

        try {
            app(DatabaseRestoreProcess::class)->validateIsolated(
                $path,
                ['migrations', 'users'],
            );
        } finally {
            @unlink($path);
        }

        Process::assertRan(fn (PendingProcess $process) => preg_match(
            '/--execute=CREATE DATABASE `lc_restore_[a-z0-9]{24}`/',
            implode(' ', $process->command),
        ) === 1
            && in_array('--host=validation.example', $process->command, true)
            && in_array('--user=validation-user', $process->command, true));
        Process::assertRan(fn (PendingProcess $process) => preg_match(
            '/--execute=DROP DATABASE IF EXISTS `lc_restore_[a-z0-9]{24}`/',
            implode(' ', $process->command),
        ) === 1);
    }

    public function test_process_failure_is_sanitized(): void
    {
        Process::fake([
            '*' => Process::result(
                errorOutput: 'secret ; value leaked',
                exitCode: 2,
            ),
        ]);
        $path = $this->gzip('CREATE TABLE `users` (`id` bigint);');

        try {
            app(DatabaseRestoreProcess::class)->replaceActive($path);
            $this->fail('Expected restore process failure.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_RESTORE_PROCESS_FAILED', $exception->errorCode);
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_active_replace_drops_all_existing_schema_objects_before_import(): void
    {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = implode(' ', $process->command);
            $commands[] = $command;

            return match (true) {
                str_contains($command, 'information_schema.TABLES') => Process::result(
                    output: "old_table\tBASE TABLE\nold_view\tVIEW\n",
                ),
                str_contains($command, 'information_schema.ROUTINES') => Process::result(
                    output: "old_proc\tPROCEDURE\n",
                ),
                str_contains($command, 'information_schema.EVENTS') => Process::result(
                    output: "old_event\tEVENT\n",
                ),
                default => Process::result(),
            };
        });
        $path = $this->gzip('CREATE TABLE `users` (`id` bigint);');

        try {
            app(DatabaseRestoreProcess::class)->replaceActive($path);
        } finally {
            @unlink($path);
        }

        $resetIndex = array_key_first(array_filter(
            $commands,
            fn ($command) => str_contains($command, 'DROP VIEW IF EXISTS `old_view`')
                && str_contains($command, 'DROP TABLE IF EXISTS `old_table`')
                && str_contains($command, 'DROP PROCEDURE IF EXISTS `old_proc`')
                && str_contains($command, 'DROP EVENT IF EXISTS `old_event`'),
        ));
        $importIndex = array_key_last($commands);

        $this->assertIsInt($resetIndex);
        $this->assertLessThan($importIndex, $resetIndex);
    }

    public function test_isolated_cleanup_failure_is_not_reported_as_success(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', $process->command);
            if (str_contains($command, 'DROP DATABASE')) {
                return Process::result(exitCode: 2);
            }

            return str_contains($command, 'SHOW TABLES')
                ? Process::result(output: "users\n")
                : Process::result();
        });
        $path = $this->gzip('CREATE TABLE `users` (`id` bigint);');

        try {
            app(DatabaseRestoreProcess::class)->validateIsolated($path, ['users']);
            $this->fail('Expected temporary database cleanup failure.');
        } catch (BackupException $exception) {
            $this->assertSame(
                'BACKUP_RESTORE_TEMPORARY_CLEANUP_FAILED',
                $exception->errorCode,
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_quiescence_check_uses_dedicated_monitor_and_performance_schema(): void
    {
        Process::fake(['*' => Process::result(output: "0\n")]);

        app(DatabaseRestoreProcess::class)->waitForQuiescence();

        Process::assertRan(function (PendingProcess $process) {
            $command = implode(' ', $process->command);

            return in_array('--host=validation.example', $process->command, true)
                && in_array('--port=3308', $process->command, true)
                && in_array('--user=validation-user', $process->command, true)
                && ($process->environment['MYSQL_PWD'] ?? null) === 'validation-secret'
                && str_contains($command, 'performance_schema.setup_consumers')
                && str_contains($command, 'performance_schema.setup_instruments')
                && str_contains($command, 'performance_schema.threads')
                && str_contains($command, 'performance_schema.events_transactions_current')
                && str_contains($command, bin2hex('linguacafe_testing'))
                && ! str_contains($command, 'information_schema.INNODB_TRX')
                && ! str_contains($command, 'information_schema.PROCESSLIST')
                && ! str_contains($command, "'linguacafe_testing'")
                && ! str_contains($command, 'secret ; value');
        });
    }

    public function test_quiescence_fails_closed_when_transaction_monitoring_is_disabled(): void
    {
        Process::fake(['*' => Process::result(output: "-1\n")]);

        try {
            app(DatabaseRestoreProcess::class)->waitForQuiescence();
            $this->fail('Expected unavailable quiescence monitoring failure.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_CONFIGURATION_INVALID', $exception->errorCode);
            $this->assertSame(503, $exception->httpStatus);
        }
    }

    private function gzip(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lc-restore-') . '.sql.gz';
        file_put_contents($path, gzencode($contents, 9));

        return $path;
    }
}
