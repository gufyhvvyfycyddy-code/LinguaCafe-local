<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static (no Laravel app) unit test for the PHPUnit bootstrap and config.
 *
 * These tests check the phpunit.xml and tests/bootstrap.php configuration
 * without booting a Laravel application or connecting to any database.
 */
class TestingDatabaseHealthConfigTest extends TestCase
{
    private string $phpunitXmlPath;

    private string $bootstrapPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phpunitXmlPath = __DIR__.'/../../phpunit.xml';
        $this->bootstrapPath = __DIR__.'/../bootstrap.php';
    }

    public function test_phpunit_xml_bootstrap_points_to_tests_bootstrap(): void
    {
        $this->assertFileExists($this->phpunitXmlPath, 'phpunit.xml not found');

        $xml = simplexml_load_file($this->phpunitXmlPath);
        $this->assertNotFalse($xml, 'phpunit.xml is not valid XML');

        $bootstrapAttr = (string) $xml['bootstrap'];
        $this->assertSame(
            'tests/bootstrap.php',
            $bootstrapAttr,
            'phpunit.xml bootstrap attribute must be "tests/bootstrap.php", got "'.$bootstrapAttr.'". '.
            'This is required so the process lock is acquired before any test runs.',
        );
    }

    public function test_bootstrap_file_exists(): void
    {
        $this->assertFileExists(
            $this->bootstrapPath,
            'tests/bootstrap.php must exist. '.
            'It loads vendor/autoload.php and acquires the testing DB process lock.',
        );
    }

    public function test_bootstrap_delegates_to_a_lease_implementation_that_contains_flock(): void
    {
        $this->assertFileExists($this->bootstrapPath);
        $leasePath = __DIR__.'/../Support/TestingDatabaseLease.php';
        $this->assertFileExists($leasePath);

        $bootstrapContents = file_get_contents($this->bootstrapPath);
        $leaseContents = file_get_contents($leasePath);

        $this->assertStringContainsString(
            'TestingDatabaseLease::acquireOrInheritForProject',
            $bootstrapContents,
            'tests/bootstrap.php must acquire the shared testing database lease before tests run.',
        );
        $this->assertStringContainsString(
            'flock(',
            $leaseContents,
            'The testing database lease must use an OS file lock to prevent concurrent writers.',
        );
    }

    public function test_bootstrap_uses_machine_global_testing_database_lease(): void
    {
        $this->assertFileExists($this->bootstrapPath);

        $contents = file_get_contents($this->bootstrapPath);

        $this->assertStringContainsString(
            'TestingDatabaseLease::acquireOrInheritForProject',
            $contents,
            'tests/bootstrap.php must reuse the machine-global lease implementation.',
        );
        $this->assertStringNotContainsString(
            'storage/framework/testing/phpunit-db.lock',
            $contents,
            'A worktree-local lock cannot coordinate one shared testing database.',
        );
        $this->assertStringContainsString(
            'LEASE_ACQUIRE_FAILED',
            $contents,
            'Lease setup failures must fail closed with a stable machine code.',
        );
        $this->assertStringNotContainsString(
            'WARNING:',
            $contents,
            'Lease failures must not warn and continue without synchronization.',
        );
    }

    public function test_phpunit_xml_declares_non_secret_lease_identity_and_bounded_wait(): void
    {
        $xml = simplexml_load_file($this->phpunitXmlPath);
        $values = [];
        foreach ($xml->php->env as $env) {
            $values[(string) $env['name']] = (string) $env['value'];
        }

        $this->assertSame('linguacafe-fsrs-testing-mysql', $values['TESTING_DB_LEASE_DATABASE_ID'] ?? null);
        $this->assertSame('0', $values['TESTING_DB_LEASE_WAIT_MS'] ?? null);
    }

    public function test_testing_server_runner_exists_and_uses_the_same_lease(): void
    {
        $runnerPath = __DIR__.'/../Support/run-with-testing-db-lease.php';
        $this->assertFileExists($runnerPath);
        $contents = file_get_contents($runnerPath);

        $this->assertStringContainsString('TestingDatabaseLease::acquireOrInheritForProject', $contents);
        $this->assertStringContainsString("['bypass_shell' => true]", $contents);
        $this->assertStringContainsString(
            'LEASE_BUSY',
            file_get_contents(__DIR__.'/../Support/TestingDatabaseLease.php'),
        );
    }

    public function test_bootstrap_does_not_contain_destructive_commands(): void
    {
        $this->assertFileExists($this->bootstrapPath);

        $contents = file_get_contents($this->bootstrapPath)
            .file_get_contents(__DIR__.'/../Support/TestingDatabaseLease.php')
            .file_get_contents(__DIR__.'/../Support/run-with-testing-db-lease.php');

        $dangerous = [
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'db:wipe',
            'drop table',
            'DROP TABLE',
            // ftruncate() is the safe PHP function and is NOT a SQL TRUNCATE
        ];

        foreach ($dangerous as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $contents,
                "tests/bootstrap.php must NOT contain '{$pattern}'.",
            );
        }

        // Verify bootstrap does not CALL truncate (ftruncate is safe)
        $lines = file($this->bootstrapPath);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Skip comments and empty lines
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || $trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            // ftruncate() and flock() are safe PHP functions — allow them
            if (str_contains($trimmed, 'ftruncate(') || str_contains($trimmed, 'flock(')) {
                continue;
            }
            $this->assertStringNotContainsStringIgnoringCase(
                'truncate',
                $trimmed,
                "tests/bootstrap.php must NOT call truncate (ftruncate is safe). Offending line: {$trimmed}",
            );
        }
    }

    public function test_bootstrap_does_not_read_env(): void
    {
        $this->assertFileExists($this->bootstrapPath);

        // Check that file_get_contents/.env is NOT used to read env files.
        // Check lines are NOT active reads (docstring mentions are fine).
        $lines = file($this->bootstrapPath);

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            // Skip docstring / comments.
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            // Skip empty lines, closing docstring, and <?php.
            if ($trimmed === '' || $trimmed === '*/' || $trimmed === '<?php') {
                continue;
            }
            // file_get_contents called on a .env file is a read.
            if (str_contains($trimmed, 'file_get_contents(') && (str_contains($trimmed, "'.env'") || str_contains($trimmed, '".env"'))) {
                $this->fail('tests/bootstrap.php line '.($i + 1)." reads a .env file: {$trimmed}");
            }
            // No getenv calls for DB credentials.
            if (preg_match('/getenv\(\s*["\']DB_/', $trimmed)) {
                $this->fail('tests/bootstrap.php line '.($i + 1)." reads DB env var: {$trimmed}");
            }
        }
    }
}
