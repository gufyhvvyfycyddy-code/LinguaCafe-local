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
}
