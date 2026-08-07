<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestingDatabaseLease;

require_once dirname(__DIR__).'/Support/TestingDatabaseLease.php';

class TestingDatabaseLeaseProcessTest extends TestCase
{
    private string $root;

    private string $worker;

    private string $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->worker = $this->root.'/tests/Support/testing-db-lease-worker.php';
        $this->runner = $this->root.'/tests/Support/run-with-testing-db-lease.php';
    }

    public function test_first_process_blocks_second_then_release_allows_next_owner(): void
    {
        $base = $this->temporaryDirectory('lease-process-');
        $identity = hash('sha256', 'process-exclusive');
        $holder = $this->startProcess([PHP_BINARY, $this->worker, 'hold', $identity, $base, 'holder']);

        try {
            $this->assertSame('READY', $this->readLine($holder['pipes'][1]));

            $blocked = $this->runProcess([PHP_BINARY, $this->worker, 'try', $identity, $base, 'blocked']);
            $this->assertSame(TestingDatabaseLease::EXIT_BUSY, $blocked['exit_code']);
            $this->assertStringContainsString('LEASE_BUSY', $blocked['stderr']);

            fwrite($holder['pipes'][0], "RELEASE\n");
            fflush($holder['pipes'][0]);
            $this->assertSame('RELEASED', $this->readLine($holder['pipes'][1]));
            $this->assertSame(0, $this->finishProcess($holder)['exit_code']);
            $holder = null;

            $after = $this->runProcess([PHP_BINARY, $this->worker, 'try', $identity, $base, 'after']);
            $this->assertSame(0, $after['exit_code']);
            $this->assertStringContainsString('ACQUIRED', $after['stdout']);
        } finally {
            $this->terminateProcess($holder);
            $this->removeDirectory($base);
        }
    }

    public function test_two_real_git_worktrees_contend_on_the_same_default_machine_lock(): void
    {
        $fixture = $this->createGitWorktreeFixture();
        $databaseIdentifier = 'fixture-db-'.bin2hex(random_bytes(6));
        $identity = TestingDatabaseLease::identityForProject(
            $fixture['repository'],
            $databaseIdentifier,
        );
        $lockPath = TestingDatabaseLease::lockPathForIdentity($identity);
        $holder = $this->startProcess([
            PHP_BINARY,
            $this->worker,
            'project-hold',
            $fixture['repository'],
            $databaseIdentifier,
            'fixture-holder',
        ]);

        try {
            $this->assertSame('READY', $this->readLine($holder['pipes'][1]));
            $blocked = $this->runProcess([
                PHP_BINARY,
                $this->worker,
                'project-try',
                $fixture['worktree'],
                $databaseIdentifier,
                'fixture-contender',
            ]);
            $this->assertSame(TestingDatabaseLease::EXIT_BUSY, $blocked['exit_code']);
            $this->assertStringContainsString('LEASE_BUSY', $blocked['stderr']);

            fwrite($holder['pipes'][0], "RELEASE\n");
            fflush($holder['pipes'][0]);
            $this->assertSame('RELEASED', $this->readLine($holder['pipes'][1]));
            $this->assertSame(0, $this->finishProcess($holder)['exit_code']);
            $holder = null;

            $after = $this->runProcess([
                PHP_BINARY,
                $this->worker,
                'project-try',
                $fixture['worktree'],
                $databaseIdentifier,
                'fixture-after',
            ]);
            $this->assertSame(0, $after['exit_code']);
            $this->assertStringContainsString('ACQUIRED', $after['stdout']);
        } finally {
            $this->terminateProcess($holder);
            @unlink($lockPath);
            @unlink(substr($lockPath, 0, -5).'.json');
            $this->removeGitWorktreeFixture($fixture);
        }
    }

    public function test_abnormal_process_exit_releases_os_lock_and_stale_metadata_does_not_block(): void
    {
        $base = $this->temporaryDirectory('lease-crash-');
        $identity = hash('sha256', 'process-crash');
        $holder = $this->startProcess([PHP_BINARY, $this->worker, 'hold', $identity, $base, 'crash-holder']);

        try {
            $this->assertSame('READY', $this->readLine($holder['pipes'][1]));
            proc_terminate($holder['process']);
            $finished = $this->finishProcess($holder);
            $holder = null;
            $this->assertNotSame(0, $finished['exit_code']);

            $status = TestingDatabaseLease::statusIdentity($identity, $base);
            $this->assertFalse($status['active']);
            $this->assertTrue($status['stale_metadata']);

            $after = $this->runProcess([PHP_BINARY, $this->worker, 'try', $identity, $base, 'recovery']);
            $this->assertSame(0, $after['exit_code']);
        } finally {
            $this->terminateProcess($holder);
            $this->removeDirectory($base);
        }
    }

    public function test_three_barrier_coordinated_competitors_produce_exactly_one_owner(): void
    {
        $base = $this->temporaryDirectory('lease-race-');
        $identity = hash('sha256', 'three-way-race');
        $processes = [];

        try {
            for ($index = 0; $index < 3; $index++) {
                $processes[$index] = $this->startProcess([
                    PHP_BINARY,
                    $this->worker,
                    'compete',
                    $identity,
                    $base,
                    'competitor-'.$index,
                ]);
            }

            foreach ($processes as $entry) {
                $this->assertSame('WAITING', $this->readLine($entry['pipes'][1]));
            }
            foreach ($processes as $entry) {
                fwrite($entry['pipes'][0], "GO\n");
                fflush($entry['pipes'][0]);
            }

            $outcomes = [];
            foreach ($processes as $index => $entry) {
                $outcomes[$index] = $this->readLine($entry['pipes'][1]);
            }

            $this->assertCount(1, array_keys($outcomes, 'ACQUIRED', true));
            $this->assertCount(2, array_keys($outcomes, 'BUSY', true));

            $ownerIndex = array_search('ACQUIRED', $outcomes, true);
            $this->assertIsInt($ownerIndex);
            fwrite($processes[$ownerIndex]['pipes'][0], "RELEASE\n");
            fflush($processes[$ownerIndex]['pipes'][0]);
            $this->assertSame('RELEASED', $this->readLine($processes[$ownerIndex]['pipes'][1]));

            foreach ($processes as $index => $entry) {
                $result = $this->finishProcess($entry);
                $processes[$index] = null;
                $this->assertSame(
                    $index === $ownerIndex ? 0 : TestingDatabaseLease::EXIT_BUSY,
                    $result['exit_code'],
                );
            }
        } finally {
            foreach ($processes as $entry) {
                $this->terminateProcess($entry);
            }
            $this->removeDirectory($base);
        }
    }

    public function test_explicit_finite_wait_acquires_after_current_owner_releases(): void
    {
        $base = $this->temporaryDirectory('lease-wait-');
        $identity = hash('sha256', 'finite-wait');
        $holder = $this->startProcess([PHP_BINARY, $this->worker, 'hold', $identity, $base, 'wait-holder']);
        $waiter = null;

        try {
            $this->assertSame('READY', $this->readLine($holder['pipes'][1]));
            $waiter = $this->startProcess([
                PHP_BINARY,
                $this->worker,
                'wait-acquire',
                $identity,
                $base,
                'bounded-waiter',
                '2000',
            ]);
            $this->assertSame('WAITING', $this->readLine($waiter['pipes'][1]));
            fwrite($waiter['pipes'][0], "GO\n");
            fflush($waiter['pipes'][0]);
            $this->assertSame('BLOCKED', $this->readLine($waiter['pipes'][1]));

            fwrite($holder['pipes'][0], "RELEASE\n");
            fflush($holder['pipes'][0]);
            $this->assertSame('RELEASED', $this->readLine($holder['pipes'][1]));
            $this->assertSame(0, $this->finishProcess($holder)['exit_code']);
            $holder = null;

            $this->assertSame('ACQUIRED', $this->readLine($waiter['pipes'][1]));
            fwrite($waiter['pipes'][0], "RELEASE\n");
            fflush($waiter['pipes'][0]);
            $this->assertSame('RELEASED', $this->readLine($waiter['pipes'][1]));
            $this->assertSame(0, $this->finishProcess($waiter)['exit_code']);
            $waiter = null;
        } finally {
            $this->terminateProcess($holder);
            $this->terminateProcess($waiter);
            $this->removeDirectory($base);
        }
    }

    public function test_status_reports_active_owner_without_releasing_or_overwriting_it(): void
    {
        $base = $this->temporaryDirectory('lease-status-process-');
        $identity = hash('sha256', 'active-status');
        $holder = $this->startProcess([PHP_BINARY, $this->worker, 'hold', $identity, $base, 'status-holder']);

        try {
            $this->assertSame('READY', $this->readLine($holder['pipes'][1]));
            $status = TestingDatabaseLease::statusIdentity($identity, $base);
            $this->assertTrue($status['active']);
            $this->assertFalse($status['stale_metadata']);
            $this->assertTrue($status['metadata_valid']);
            $this->assertSame('status-holder', $status['label']);

            $secondStatus = $this->runProcess([PHP_BINARY, $this->worker, 'status', $identity, $base]);
            $this->assertSame(0, $secondStatus['exit_code']);
            $this->assertTrue(json_decode($secondStatus['stdout'], true, flags: JSON_THROW_ON_ERROR)['active']);

            $blocked = $this->runProcess([PHP_BINARY, $this->worker, 'try', $identity, $base, 'still-blocked']);
            $this->assertSame(TestingDatabaseLease::EXIT_BUSY, $blocked['exit_code']);
        } finally {
            if (is_array($holder)) {
                @fwrite($holder['pipes'][0], "RELEASE\n");
                $this->finishProcess($holder);
            }
            $this->removeDirectory($base);
        }
    }

    public function test_metadata_is_atomic_valid_and_contains_only_allowed_fields_while_held(): void
    {
        $base = $this->temporaryDirectory('lease-metadata-process-');
        $identity = hash('sha256', 'metadata-process');
        $holder = $this->startProcess([PHP_BINARY, $this->worker, 'hold', $identity, $base, 'meta-holder']);

        try {
            $this->assertSame('READY', $this->readLine($holder['pipes'][1]));
            $metadataPath = dirname(TestingDatabaseLease::lockPathForIdentity($identity, $base))
                .DIRECTORY_SEPARATOR.$identity.'.json';
            $metadata = json_decode(file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(
                ['protocol_version', 'pid', 'mode', 'started_at', 'label'],
                array_keys($metadata),
            );
            $this->assertSame('meta-holder', $metadata['label']);
            $this->assertSame([], glob(dirname($metadataPath).'/*.tmp') ?: []);
        } finally {
            if (is_array($holder)) {
                @fwrite($holder['pipes'][0], "RELEASE\n");
                $this->finishProcess($holder);
            }
            $this->removeDirectory($base);
        }
    }

    public function test_non_testing_bootstrap_does_not_create_or_acquire_a_lease(): void
    {
        $fixture = $this->createBootstrapFixture();
        $identity = TestingDatabaseLease::identityForProject(
            $fixture['repository'],
            $fixture['database_identifier'],
        );
        $lockPath = TestingDatabaseLease::lockPathForIdentity($identity);
        @unlink($lockPath);
        @unlink(substr($lockPath, 0, -5).'.json');

        try {
            $code = <<<'PHP'
putenv('APP_ENV=production');
require $argv[1];
echo isset($GLOBALS['linguacafeTestingDatabaseLease']) ? "LOCKED\n" : "UNLOCKED\n";
PHP;
            $result = $this->runProcess([
                PHP_BINARY,
                '-r',
                $code,
                $fixture['bootstrap'],
            ], $this->environmentWithoutInheritance('production'));

            $this->assertSame(0, $result['exit_code'], $result['stderr']);
            $this->assertSame('UNLOCKED', trim($result['stdout']));
            $this->assertFileDoesNotExist($lockPath);
        } finally {
            $this->removeGitWorktreeFixture($fixture);
        }
    }

    public function test_testing_bootstrap_holds_lock_exports_proof_and_releases_on_exit(): void
    {
        $fixture = $this->createBootstrapFixture();
        $identity = TestingDatabaseLease::identityForProject(
            $fixture['repository'],
            $fixture['database_identifier'],
        );
        $lockPath = TestingDatabaseLease::lockPathForIdentity($identity);
        @unlink($lockPath);
        @unlink(substr($lockPath, 0, -5).'.json');
        $entry = null;

        try {
            $code = <<<'PHP'
putenv('APP_ENV=testing');
require $argv[1];
$lease = $GLOBALS['linguacafeTestingDatabaseLease'] ?? null;
$hasProof = is_string(getenv('LINGUACAFE_TEST_DB_LEASE_TOKEN'))
    && getenv('LINGUACAFE_TEST_DB_LEASE_TOKEN') !== '';
echo ($lease instanceof Tests\Support\TestingDatabaseLease && $lease->ownsOsLock() && $hasProof)
    ? "READY\n"
    : "FAILED\n";
flush();
fgets(STDIN);
PHP;
            $entry = $this->startProcess([
                PHP_BINARY,
                '-r',
                $code,
                $fixture['bootstrap'],
            ], $this->environmentWithoutInheritance('testing'));
            $this->assertSame('READY', $this->readLine($entry['pipes'][1]));

            $status = TestingDatabaseLease::statusIdentity($identity);
            $this->assertTrue($status['active']);
            $this->assertTrue($status['metadata_valid']);

            fwrite($entry['pipes'][0], "RELEASE\n");
            fflush($entry['pipes'][0]);
            $this->assertSame(0, $this->finishProcess($entry)['exit_code']);
            $entry = null;

            $after = TestingDatabaseLease::statusIdentity($identity);
            $this->assertFalse($after['active']);
            $this->assertSame([], glob(dirname($lockPath).DIRECTORY_SEPARATOR.$identity.'.proof-*.json') ?: []);
        } finally {
            $this->terminateProcess($entry);
            @unlink($lockPath);
            @unlink(substr($lockPath, 0, -5).'.json');
            $this->removeGitWorktreeFixture($fixture);
        }
    }

    public function test_runner_transfers_child_exit_code_and_preserves_outer_lease_state(): void
    {
        $before = TestingDatabaseLease::statusForProject($this->root);
        $result = $this->runProcess([
            PHP_BINARY,
            $this->runner,
            '--label=exit-code',
            '--',
            PHP_BINARY,
            '-r',
            'exit(7);',
        ], $this->runnerEnvironment());

        $this->assertSame(7, $result['exit_code']);
        $this->assertSame('', trim($result['stderr']));

        $after = TestingDatabaseLease::statusForProject($this->root);
        if (($GLOBALS['linguacafeTestingDatabaseLease'] ?? null) instanceof TestingDatabaseLease) {
            $this->assertTrue($after['active']);
            $this->assertSame($before['pid'], $after['pid']);
            $this->assertSame($before['started_at'], $after['started_at']);
        } else {
            $this->assertFalse($after['active']);
        }
    }

    public function test_runner_fails_fast_when_another_owner_holds_the_project_lease(): void
    {
        $lease = $GLOBALS['linguacafeTestingDatabaseLease'] ?? null;
        $ownedByThisTest = false;
        if (! $lease instanceof TestingDatabaseLease) {
            $lease = TestingDatabaseLease::acquireForProject(
                $this->root,
                label: 'outer-owner',
                databaseIdentifier: 'linguacafe-fsrs-testing-mysql',
            );
            $ownedByThisTest = true;
        }

        try {
            $environment = $this->runnerEnvironment();
            foreach ([
                'LINGUACAFE_TEST_DB_LEASE_TOKEN',
                'LINGUACAFE_TEST_DB_LEASE_OWNER_PID',
                'LINGUACAFE_TEST_DB_LEASE_IDENTITY',
            ] as $name) {
                unset($environment[$name]);
            }
            $result = $this->runProcess([
                PHP_BINARY,
                $this->runner,
                '--label=blocked-runner',
                '--',
                PHP_BINARY,
                '-r',
                'exit(0);',
            ], $environment);
            $this->assertSame(TestingDatabaseLease::EXIT_BUSY, $result['exit_code']);
            $this->assertStringContainsString('LEASE_BUSY', $result['stderr']);
        } finally {
            if ($ownedByThisTest) {
                $lease->release();
            }
        }
    }

    public function test_runner_status_is_stable_json_and_does_not_change_owner_state(): void
    {
        $before = TestingDatabaseLease::statusForProject($this->root);
        $result = $this->runProcess([
            PHP_BINARY,
            $this->runner,
            '--status',
        ], $this->runnerEnvironment());

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'active',
            'stale_metadata',
            'metadata_valid',
            'protocol_version',
            'pid',
            'mode',
            'started_at',
            'label',
        ], array_keys($payload));
        $after = TestingDatabaseLease::statusForProject($this->root);
        $this->assertSame($before['active'], $after['active']);
        $this->assertSame($before['pid'], $after['pid']);
        $this->assertSame($before['started_at'], $after['started_at']);
    }

    public function test_runner_rejects_out_of_range_wait_as_usage_error(): void
    {
        foreach (['3600001', '999999999999999999999999'] as $invalidWait) {
            $result = $this->runProcess([
                PHP_BINARY,
                $this->runner,
                '--wait-ms='.$invalidWait,
                '--',
                PHP_BINARY,
                '-r',
                'exit(0);',
            ], $this->runnerEnvironment());
            $this->assertSame(TestingDatabaseLease::EXIT_USAGE, $result['exit_code']);
            $this->assertStringContainsString('LEASE_RUNNER_WAIT_INVALID', $result['stderr']);
        }
    }

    public function test_runner_child_proves_inheritance_without_self_deadlock(): void
    {
        $result = $this->runProcess([
            PHP_BINARY,
            $this->runner,
            '--label=inherit-parent',
            '--',
            PHP_BINARY,
            $this->worker,
            'inherit',
            $this->root,
            '',
            'inherit-child',
        ], $this->runnerEnvironment());

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('INHERITED', trim($result['stdout']));
    }

    public function test_windows_ctrl_c_stops_runner_and_ctrl_c_ignoring_child(): void
    {
        if (PHP_OS_FAMILY !== 'Windows'
            || ! function_exists('sapi_windows_generate_ctrl_event')
            || ! defined('PHP_WINDOWS_EVENT_CTRL_C')
        ) {
            $this->markTestSkipped('Windows console control-event API is unavailable.');
        }

        $before = TestingDatabaseLease::statusForProject($this->root);
        $childCode = <<<'PHP'
if (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler(static fn (int $event): bool => true);
}
echo "CHILD_READY\n";
flush();
while (true) {
    usleep(100000);
}
PHP;
        $entry = $this->startProcess([
            PHP_BINARY,
            $this->runner,
            '--label=ctrl-c-test',
            '--',
            PHP_BINARY,
            '-r',
            $childCode,
        ], $this->runnerEnvironment(), ['create_process_group' => true]);

        try {
            $this->assertSame('CHILD_READY', $this->readLine($entry['pipes'][1], 10));
            $status = proc_get_status($entry['process']);
            $this->assertIsArray($status);
            $this->assertTrue($status['running']);
            $this->assertTrue(sapi_windows_generate_ctrl_event(PHP_WINDOWS_EVENT_CTRL_C, $status['pid']));
            $this->assertTrue($this->waitForProcessExit($entry['process'], 10));
            $this->finishProcess($entry);
            $entry = null;

            $after = TestingDatabaseLease::statusForProject($this->root);
            $this->assertSame($before['active'], $after['active']);
            $this->assertSame($before['pid'], $after['pid']);
            $this->assertSame($before['started_at'], $after['started_at']);
        } finally {
            $this->terminateProcess($entry);
        }
    }

    public function test_inheritance_rejects_protocol_mismatch_even_with_a_valid_random_token(): void
    {
        $base = $this->temporaryDirectory('lease-protocol-');
        $lease = TestingDatabaseLease::acquireForProject(
            $this->root,
            label: 'protocol-parent',
            databaseIdentifier: 'linguacafe-fsrs-testing-mysql',
            leaseBaseDirectory: $base,
        );

        try {
            $proofEnvironment = $lease->createInheritanceProof();
            $metadataPath = dirname(TestingDatabaseLease::lockPathForIdentity($lease->identity(), $base))
                .DIRECTORY_SEPARATOR.$lease->identity().'.json';
            $metadata = json_decode(file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
            $metadata['protocol_version'] = 'older-protocol';
            file_put_contents($metadataPath, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $environment = getenv();
            if (! is_array($environment)) {
                $environment = [];
            }
            $environment = array_merge($environment, $proofEnvironment);
            $result = $this->runProcess([
                PHP_BINARY,
                $this->worker,
                'inherit',
                $this->root,
                $base,
                'protocol-child',
            ], $environment);

            $this->assertSame(TestingDatabaseLease::EXIT_UNAVAILABLE, $result['exit_code']);
            $this->assertStringContainsString('LEASE_INHERIT_PROOF_INVALID', $result['stderr']);
        } finally {
            $lease->release();
            $this->removeDirectory($base);
        }
    }

    public function test_inheritance_rejects_identity_token_pid_and_missing_proof_tampering(): void
    {
        $cases = [
            'identity' => 'LEASE_INHERIT_IDENTITY_MISMATCH',
            'token' => 'LEASE_INHERIT_PROOF_INVALID',
            'pid' => 'LEASE_INHERIT_PROOF_INVALID',
            'missing-proof' => 'LEASE_INHERIT_PROOF_INVALID',
        ];

        foreach ($cases as $case => $expectedCode) {
            $base = $this->temporaryDirectory('lease-inherit-negative-');
            $lease = TestingDatabaseLease::acquireForProject(
                $this->root,
                label: 'negative-parent-'.$case,
                databaseIdentifier: 'linguacafe-fsrs-testing-mysql',
                leaseBaseDirectory: $base,
            );

            try {
                $proofEnvironment = $lease->createInheritanceProof();
                $token = $proofEnvironment['LINGUACAFE_TEST_DB_LEASE_TOKEN'];
                $proofPath = dirname(TestingDatabaseLease::lockPathForIdentity($lease->identity(), $base))
                    .DIRECTORY_SEPARATOR.$lease->identity().'.proof-'.hash('sha256', $token).'.json';

                if ($case === 'identity') {
                    $proofEnvironment['LINGUACAFE_TEST_DB_LEASE_IDENTITY'] = hash('sha256', 'wrong-identity');
                } elseif ($case === 'token') {
                    $proofEnvironment['LINGUACAFE_TEST_DB_LEASE_TOKEN'] = 'tampered-token';
                } elseif ($case === 'pid') {
                    $proofEnvironment['LINGUACAFE_TEST_DB_LEASE_OWNER_PID'] = (string) (getmypid() + 100_000);
                } else {
                    unlink($proofPath);
                }

                $environment = getenv();
                if (! is_array($environment)) {
                    $environment = [];
                }
                $result = $this->runProcess([
                    PHP_BINARY,
                    $this->worker,
                    'inherit',
                    $this->root,
                    $base,
                    'negative-child-'.$case,
                ], array_merge($environment, $proofEnvironment));

                $this->assertSame(TestingDatabaseLease::EXIT_UNAVAILABLE, $result['exit_code'], $case);
                $this->assertStringContainsString($expectedCode, $result['stderr'], $case);
            } finally {
                $lease->release();
                $this->removeDirectory($base);
            }
        }
    }

    public function test_non_testing_runner_fails_without_changing_current_lease_state(): void
    {
        $before = TestingDatabaseLease::statusForProject($this->root);
        $environment = $this->runnerEnvironment();
        $environment['APP_ENV'] = 'production';

        $result = $this->runProcess([
            PHP_BINARY,
            $this->runner,
            '--',
            PHP_BINARY,
            '-r',
            'exit(0);',
        ], $environment);
        $this->assertSame(TestingDatabaseLease::EXIT_NOT_TESTING, $result['exit_code']);
        $this->assertStringContainsString('LEASE_ENV_NOT_TESTING', $result['stderr']);

        $after = TestingDatabaseLease::statusForProject($this->root);
        $this->assertSame($before['active'], $after['active']);
        $this->assertSame($before['pid'], $after['pid']);
        $this->assertSame($before['started_at'], $after['started_at']);
    }

    /** @return array{root: string, repository: string, worktree: string} */
    private function createGitWorktreeFixture(): array
    {
        $root = $this->temporaryDirectory('lease-process-git-');
        $repository = $root.DIRECTORY_SEPARATOR.'repository';
        $worktree = $root.DIRECTORY_SEPARATOR.'worktree';
        mkdir($repository, 0700, true);

        foreach ([
            ['git', 'init', '--quiet', $repository],
            [
                'git', '-C', $repository,
                '-c', 'user.name=Lease Process Test',
                '-c', 'user.email=lease-process@example.test',
                'commit', '--quiet', '--allow-empty', '-m', 'fixture',
            ],
            [
                'git', '-C', $repository,
                'remote', 'add', 'origin', 'https://example.test/linguacafe.git',
            ],
            [
                'git', '-C', $repository,
                'worktree', 'add', '--quiet', '--detach', $worktree, 'HEAD',
            ],
        ] as $command) {
            $result = $this->runProcess($command);
            $this->assertSame(0, $result['exit_code'], $result['stderr']);
        }

        return compact('root', 'repository', 'worktree');
    }

    /** @return array{root: string, repository: string, worktree: string, bootstrap: string, database_identifier: string} */
    private function createBootstrapFixture(): array
    {
        $fixture = $this->createGitWorktreeFixture();
        $databaseIdentifier = 'bootstrap-fixture-'.bin2hex(random_bytes(6));
        $supportDirectory = $fixture['repository'].DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support';
        $vendorDirectory = $fixture['repository'].DIRECTORY_SEPARATOR.'vendor';
        mkdir($supportDirectory, 0700, true);
        mkdir($vendorDirectory, 0700, true);
        copy(
            $this->root.'/tests/Support/TestingDatabaseLease.php',
            $supportDirectory.DIRECTORY_SEPARATOR.'TestingDatabaseLease.php',
        );
        $bootstrap = $fixture['repository'].DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'bootstrap.php';
        copy($this->root.'/tests/bootstrap.php', $bootstrap);
        file_put_contents($vendorDirectory.DIRECTORY_SEPARATOR.'autoload.php', "<?php\n");
        file_put_contents(
            $fixture['repository'].DIRECTORY_SEPARATOR.'phpunit.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            .'<phpunit><php>'
            .'<env name="APP_ENV" value="testing"/>'
            ."<env name=\"TESTING_DB_LEASE_DATABASE_ID\" value=\"{$databaseIdentifier}\"/>"
            .'<env name="TESTING_DB_LEASE_WAIT_MS" value="0"/>'
            ."</php></phpunit>\n",
        );

        return [
            ...$fixture,
            'bootstrap' => $bootstrap,
            'database_identifier' => $databaseIdentifier,
        ];
    }

    /** @param array{root: string, repository: string, worktree: string} $fixture */
    private function removeGitWorktreeFixture(array $fixture): void
    {
        if (is_dir($fixture['repository']) && is_dir($fixture['worktree'])) {
            $this->runProcess([
                'git', '-C', $fixture['repository'],
                'worktree', 'remove', $fixture['worktree'],
            ]);
        }
        $this->removeDirectory($fixture['root']);
    }

    /** @return array<string, string> */
    private function environmentWithoutInheritance(string $appEnvironment): array
    {
        $environment = getenv();
        if (! is_array($environment)) {
            $environment = [];
        }
        foreach ([
            'LINGUACAFE_TEST_DB_LEASE_TOKEN',
            'LINGUACAFE_TEST_DB_LEASE_OWNER_PID',
            'LINGUACAFE_TEST_DB_LEASE_IDENTITY',
        ] as $name) {
            unset($environment[$name]);
        }
        $environment['APP_ENV'] = $appEnvironment;

        return $environment;
    }

    /** @return array<string, string> */
    private function runnerEnvironment(): array
    {
        $environment = getenv();
        if (! is_array($environment)) {
            $environment = [];
        }
        $environment['APP_ENV'] = 'testing';

        return $environment;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>|null  $environment
     * @return array{process: resource, pipes: array<int, resource>}
     */
    private function startProcess(
        array $command,
        ?array $environment = null,
        array $options = [],
    ): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->root,
            $environment,
            ['bypass_shell' => true, ...$options],
        );
        $this->assertIsResource($process, 'Could not start lease process.');

        return compact('process', 'pipes');
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>|null  $environment
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, ?array $environment = null): array
    {
        $entry = $this->startProcess($command, $environment);
        fclose($entry['pipes'][0]);

        return $this->finishProcess($entry);
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource>}  $entry
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function finishProcess(array $entry): array
    {
        foreach ([1, 2] as $index) {
            stream_set_blocking($entry['pipes'][$index], true);
        }
        $stdout = stream_get_contents($entry['pipes'][1]);
        $stderr = stream_get_contents($entry['pipes'][2]);
        foreach ($entry['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exitCode = proc_close($entry['process']);

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param array{process: resource, pipes: array<int, resource>}|null $entry */
    private function terminateProcess(?array $entry): void
    {
        if (! is_array($entry) || ! is_resource($entry['process'])) {
            return;
        }
        @proc_terminate($entry['process']);
        $this->finishProcess($entry);
    }

    /** @param resource $process */
    private function waitForProcessExit($process, int $timeoutSeconds): bool
    {
        $deadline = hrtime(true) + ($timeoutSeconds * 1_000_000_000);
        do {
            $status = proc_get_status($process);
            if (! is_array($status) || ! ($status['running'] ?? false)) {
                return true;
            }
            usleep(50_000);
        } while (hrtime(true) < $deadline);

        return false;
    }

    /** @param resource $stream */
    private function readLine($stream, int $timeoutSeconds = 5): string
    {
        $read = [$stream];
        $write = [];
        $except = [];
        $ready = stream_select($read, $write, $except, $timeoutSeconds);
        $this->assertSame(1, $ready, 'Timed out waiting for deterministic lease process signal.');
        $line = fgets($stream);
        $this->assertIsString($line, 'Lease process closed without a signal.');

        return trim($line);
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(8));
        mkdir($path, 0700, true);

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }
        $items = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $itemPath = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($itemPath) && ! is_link($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                @unlink($itemPath);
            }
        }
        @rmdir($path);
    }
}
