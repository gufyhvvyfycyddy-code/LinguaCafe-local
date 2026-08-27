<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestingDatabaseLease;

class ValidationLaneRunnerTest extends TestCase
{
    private string $projectRoot;

    private string $runnerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = realpath(__DIR__.'/../..') ?: '';
        $this->runnerPath = __DIR__.'/../Support/run-validation-lane.php';
    }

    public function test_runtime_database_identifier_segments_lease_identity(): void
    {
        $override = 'LINGUACAFE_TEST_DB_LEASE_DATABASE_ID_OVERRIDE';
        $previous = getenv($override);

        try {
            putenv($override.'=linguacafe-fsrs-testing-mysql-lane01');
            $lane01 = TestingDatabaseLease::identityForProject($this->projectRoot);

            putenv($override.'=linguacafe-fsrs-testing-mysql-lane02');
            $lane02 = TestingDatabaseLease::identityForProject($this->projectRoot);
        } finally {
            if ($previous === false) {
                putenv($override);
            } else {
                putenv($override.'='.$previous);
            }
        }

        $this->assertNotSame($lane01, $lane02);
    }

    public function test_validation_lane_runner_reuses_existing_lease_and_isolation_primitives(): void
    {
        $this->assertFileExists($this->runnerPath);
        $contents = file_get_contents($this->runnerPath);

        foreach ([
            'TestingDatabaseLease::acquireForProject',
            "require __DIR__.'/run-with-testing-db-lease.php'",
            'TESTING_DB_LEASE_DATABASE_ID',
            'LINGUACAFE_TEST_DB_LEASE_DATABASE_ID_OVERRIDE',
            'LARAVEL_STORAGE_PATH',
            'DB_DATABASE',
            'CREATE DATABASE IF NOT EXISTS',
            "['01', '02', '03', '04']",
            '8870 + (int) $lane',
            'linguacafe-validation-',
        ] as $expected) {
            $this->assertStringContainsString($expected, $contents);
        }
    }

    public function test_validation_lane_runner_contains_no_destructive_database_command(): void
    {
        $contents = strtolower(file_get_contents($this->runnerPath));

        foreach ([
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'db:wipe',
            'drop table',
            'truncate table',
            'delete from',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $contents);
        }
    }

    public function test_default_phpunit_lease_identifier_remains_unchanged(): void
    {
        $identifier = TestingDatabaseLease::databaseIdentifierFromPhpunitXml($this->projectRoot.'/phpunit.xml');

        $this->assertSame('linguacafe-fsrs-testing-mysql', $identifier);
    }

    public function test_windows_extensionless_phpunit_proxy_runs_through_validation_lane(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows Composer proxy regression.');
        }

        $environment = getenv();
        $this->assertIsArray($environment);
        foreach ([
            'LINGUACAFE_TEST_DB_LEASE_TOKEN',
            'LINGUACAFE_TEST_DB_LEASE_OWNER_PID',
            'LINGUACAFE_TEST_DB_LEASE_IDENTITY',
        ] as $variable) {
            unset($environment[$variable]);
        }

        $result = $this->runProcess([
            PHP_BINARY,
            $this->runnerPath,
            '--lane=03',
            '--',
            'vendor/bin/phpunit',
            '--version',
        ], $environment);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertStringContainsString('PHPUnit', $result['stdout']);
    }

    /** @param list<string> $command
     *  @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, ?array $environment = null): array
    {
        $descriptors = [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->projectRoot,
            $environment,
            ['bypass_shell' => true],
        );
        $this->assertIsResource($process, 'Could not start validation lane process.');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
