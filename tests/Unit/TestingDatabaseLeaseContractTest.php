<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestingDatabaseLease;
use Tests\Support\TestingDatabaseLeaseException;

require_once dirname(__DIR__).'/Support/TestingDatabaseLease.php';

class TestingDatabaseLeaseContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_bootstrap_uses_machine_global_lease_instead_of_worktree_local_lock(): void
    {
        $bootstrap = file_get_contents($this->root.'/tests/bootstrap.php');

        $this->assertStringContainsString('TestingDatabaseLease', $bootstrap);
        $this->assertStringNotContainsString('storage/framework/testing/phpunit-db.lock', $bootstrap);
        $this->assertStringContainsString('LEASE_ACQUIRE_FAILED', $bootstrap);
        $this->assertStringNotContainsString('WARNING:', $bootstrap);
    }

    public function test_phpunit_config_declares_non_secret_database_identity_and_bounded_wait(): void
    {
        $xml = simplexml_load_file($this->root.'/phpunit.xml');
        $values = [];
        foreach ($xml->php->env as $env) {
            $values[(string) $env['name']] = (string) $env['value'];
        }

        $this->assertSame('linguacafe-fsrs-testing-mysql', $values['TESTING_DB_LEASE_DATABASE_ID'] ?? null);
        $this->assertSame('0', $values['TESTING_DB_LEASE_WAIT_MS'] ?? null);
        $this->assertStringNotContainsString('password', strtolower(json_encode($values)));
    }

    public function test_same_git_common_repository_worktrees_resolve_same_identity_and_lock_path(): void
    {
        $fixture = $this->createGitWorktreeFixture();
        $base = $this->temporaryDirectory('lease-path-');

        try {
            $databaseId = 'linguacafe-fsrs-testing-mysql';
            $firstIdentity = TestingDatabaseLease::identityForProject($fixture['repository'], $databaseId);
            $secondIdentity = TestingDatabaseLease::identityForProject($fixture['worktree'], $databaseId);

            $this->assertSame($firstIdentity, $secondIdentity);
            $this->assertSame(
                TestingDatabaseLease::lockPathForIdentity($firstIdentity, $base),
                TestingDatabaseLease::lockPathForIdentity($secondIdentity, $base),
            );
        } finally {
            $this->removeGitWorktreeFixture($fixture);
            $this->removeDirectory($base);
        }
    }

    public function test_different_repository_or_database_identifier_produces_different_identity(): void
    {
        $first = TestingDatabaseLease::identityFromInputs(
            'C:/repo/common/.git',
            'git@github.com:example/linguacafe.git',
            'testing-a',
        );
        $differentRepo = TestingDatabaseLease::identityFromInputs(
            'C:/repo/other/.git',
            'git@github.com:example/linguacafe.git',
            'testing-a',
        );
        $differentDatabase = TestingDatabaseLease::identityFromInputs(
            'C:/repo/common/.git',
            'git@github.com:example/linguacafe.git',
            'testing-b',
        );

        $this->assertNotSame($first, $differentRepo);
        $this->assertNotSame($first, $differentDatabase);
    }

    public function test_windows_path_case_and_slashes_normalize_stably(): void
    {
        $first = TestingDatabaseLease::identityFromInputs(
            'C:\\Users\\Example\\Repo\\.git',
            'HTTPS://GitHub.com/Example/LinguaCafe.git',
            'TESTING-A',
        );
        $second = TestingDatabaseLease::identityFromInputs(
            'c:/users/example/repo/.git/',
            'https://github.com/Example/LinguaCafe',
            'testing-a',
        );

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertSame($first, $second);
        } else {
            $this->assertNotSame($first, $second, 'Linux path case remains significant.');
        }
    }

    public function test_lock_path_contains_only_fixed_prefix_protocol_directory_and_hash(): void
    {
        $identity = TestingDatabaseLease::identityFromInputs(
            'C:/Example/Repo/.git',
            'https://token@example.test/private/repo.git',
            'private-testing-database',
        );
        $base = $this->temporaryDirectory('lease-redaction-');

        try {
            $path = TestingDatabaseLease::lockPathForIdentity($identity, $base);
            $relative = str_replace('\\', '/', substr($path, strlen(realpath($base))));
            $this->assertMatchesRegularExpression(
                '#^/linguacafe-testing-db-leases/v1/[a-f0-9]{64}\.lock$#',
                $relative,
            );
            $this->assertStringNotContainsStringIgnoringCase('secretuser', $path);
            $this->assertStringNotContainsStringIgnoringCase('private-testing-database', $path);
            $this->assertStringNotContainsString('token', $path);
        } finally {
            $this->removeDirectory($base);
        }
    }

    public function test_testing_server_runner_exists_and_uses_safe_argument_array_process_start(): void
    {
        $runner = $this->root.'/tests/Support/run-with-testing-db-lease.php';
        $this->assertFileExists($runner);
        $contents = file_get_contents($runner);

        $this->assertStringContainsString('TestingDatabaseLease', $contents);
        $this->assertStringContainsString("['bypass_shell' => true]", $contents);
        $this->assertStringContainsString('proc_open(', $contents);
        $this->assertStringNotContainsString('implode($options', $contents);
        $this->assertStringNotContainsString('shell_exec(', $contents);
    }

    public function test_metadata_contract_is_minimal_and_sanitized(): void
    {
        $base = $this->temporaryDirectory('lease-metadata-');
        $identity = hash('sha256', 'metadata-contract');
        $lease = TestingDatabaseLease::acquireIdentity(
            $identity,
            label: 'Browser acceptance C:\\Users\\Secret token=abc',
            leaseBaseDirectory: $base,
        );

        try {
            $metadata = $lease->metadata();
            $this->assertSame(
                ['protocol_version', 'pid', 'mode', 'started_at', 'label'],
                array_keys($metadata),
            );
            $this->assertSame(TestingDatabaseLease::PROTOCOL_VERSION, $metadata['protocol_version']);
            $this->assertSame('exclusive', $metadata['mode']);
            $this->assertMatchesRegularExpression('/^[a-z0-9._-]{1,48}$/', $metadata['label']);
            $serialized = json_encode($metadata);
            $this->assertStringNotContainsStringIgnoringCase('secret', $serialized);
            $this->assertStringNotContainsString('C:', $serialized);
            $this->assertStringNotContainsString('token=', $serialized);
        } finally {
            $lease->release();
            $this->removeDirectory($base);
        }
    }

    public function test_status_is_read_only_and_pid_text_alone_does_not_claim_active_owner(): void
    {
        $base = $this->temporaryDirectory('lease-status-');
        $identity = hash('sha256', 'status-contract');

        try {
            $status = TestingDatabaseLease::statusIdentity($identity, $base);
            $this->assertFalse($status['active']);
            $this->assertFalse($status['stale_metadata']);
            $this->assertFileDoesNotExist(TestingDatabaseLease::lockPathForIdentity($identity, $base));

            $directory = dirname(TestingDatabaseLease::lockPathForIdentity($identity, $base));
            $this->assertDirectoryDoesNotExist($directory, 'Read-only status must not create lease directories.');
            mkdir($directory, 0700, true);
            file_put_contents($directory.'/'.$identity.'.json', json_encode([
                'protocol_version' => TestingDatabaseLease::PROTOCOL_VERSION,
                'pid' => getmypid(),
                'mode' => 'exclusive',
                'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'label' => 'stale',
            ]));
            $status = TestingDatabaseLease::statusIdentity($identity, $base);
            $this->assertFalse($status['active']);
            $this->assertTrue($status['stale_metadata']);
            $this->assertTrue($status['metadata_valid']);
        } finally {
            $this->removeDirectory($base);
        }
    }

    public function test_malformed_stale_metadata_is_reported_without_claiming_an_active_owner(): void
    {
        $base = $this->temporaryDirectory('lease-malformed-status-');
        $identity = hash('sha256', 'malformed-status-contract');

        try {
            $seed = TestingDatabaseLease::acquireIdentity($identity, leaseBaseDirectory: $base);
            $seed->release();
            $metadataPath = dirname(TestingDatabaseLease::lockPathForIdentity($identity, $base))
                .DIRECTORY_SEPARATOR.$identity.'.json';
            file_put_contents($metadataPath, '{malformed');
            @chmod($metadataPath, 0600);

            $status = TestingDatabaseLease::statusIdentity($identity, $base);
            $this->assertFalse($status['active']);
            $this->assertTrue($status['stale_metadata']);
            $this->assertFalse($status['metadata_valid']);
            $this->assertNull($status['pid']);
        } finally {
            $this->removeDirectory($base);
        }
    }

    public function test_unsafe_base_directory_fails_closed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lease-base-file-');
        try {
            $this->expectException(TestingDatabaseLeaseException::class);
            $this->expectExceptionMessage('Testing database lease operation failed.');
            TestingDatabaseLease::acquireIdentity(
                hash('sha256', 'unsafe-base'),
                leaseBaseDirectory: $file,
            );
        } finally {
            @unlink($file);
        }
    }

    public function test_symlink_or_reparse_lease_directory_is_rejected_when_supported(): void
    {
        $base = $this->temporaryDirectory('lease-link-base-');
        $target = $this->temporaryDirectory('lease-link-target-');
        $link = $base.'/linguacafe-testing-db-leases';

        try {
            if (! @symlink($target, $link)) {
                $this->markTestSkipped('Symlink creation is not permitted on this host.');
            }

            try {
                TestingDatabaseLease::acquireIdentity(
                    hash('sha256', 'symlink-base'),
                    leaseBaseDirectory: $base,
                );
                $this->fail('A symlinked lease directory must be rejected.');
            } catch (TestingDatabaseLeaseException $error) {
                $this->assertContains(
                    $error->machineCode,
                    ['LEASE_DIRECTORY_UNSAFE', 'LEASE_DIRECTORY_REPARSE_REJECTED'],
                );
            }
        } finally {
            @unlink($link);
            $this->removeDirectory($base);
            $this->removeDirectory($target);
        }
    }

    public function test_new_owner_prunes_only_strictly_owned_stale_proof_files(): void
    {
        $base = $this->temporaryDirectory('lease-proof-prune-');
        $identity = hash('sha256', 'proof-prune');
        $initial = TestingDatabaseLease::acquireIdentity($identity, leaseBaseDirectory: $base);
        $initial->release();
        $directory = dirname(TestingDatabaseLease::lockPathForIdentity($identity, $base));
        $staleProof = $directory.DIRECTORY_SEPARATOR.$identity.'.proof-'.str_repeat('a', 64).'.json';
        $unknown = $directory.DIRECTORY_SEPARATOR.'keep-unknown.json';
        file_put_contents($staleProof, '{}');
        file_put_contents($unknown, '{}');
        @chmod($staleProof, 0600);
        @chmod($unknown, 0600);

        try {
            $lease = TestingDatabaseLease::acquireIdentity($identity, leaseBaseDirectory: $base);
            try {
                $this->assertFileDoesNotExist($staleProof);
                $this->assertFileExists($unknown);
            } finally {
                $lease->release();
            }
        } finally {
            $this->removeDirectory($base);
        }
    }

    public function test_hard_link_lock_file_is_rejected_when_supported(): void
    {
        $base = $this->temporaryDirectory('lease-hard-link-');
        $directoryLease = TestingDatabaseLease::acquireIdentity(
            hash('sha256', 'hard-link-directory'),
            leaseBaseDirectory: $base,
        );
        $directoryLease->release();
        $identity = hash('sha256', 'hard-link-target');
        $lockPath = TestingDatabaseLease::lockPathForIdentity($identity, $base);
        $target = dirname($lockPath).DIRECTORY_SEPARATOR.'hard-link-source.txt';
        file_put_contents($target, 'safe target');
        @chmod($target, 0600);

        try {
            if (! @link($target, $lockPath)) {
                $this->markTestSkipped('Hard-link creation is not available on this host.');
            }
            try {
                TestingDatabaseLease::acquireIdentity($identity, leaseBaseDirectory: $base);
                $this->fail('A hard-linked lock file must be rejected.');
            } catch (TestingDatabaseLeaseException $error) {
                $this->assertContains($error->machineCode, [
                    'LEASE_FILE_UNSAFE',
                    'LEASE_FILE_HANDLE_UNSAFE',
                ]);
            }
            $this->assertSame('safe target', file_get_contents($target));
        } finally {
            @unlink($lockPath);
            @unlink($target);
            $this->removeDirectory($base);
        }
    }

    public function test_runner_and_git_identity_probe_have_bounded_termination_paths(): void
    {
        $runner = file_get_contents($this->root.'/tests/Support/run-with-testing-db-lease.php');
        $lease = file_get_contents($this->root.'/tests/Support/TestingDatabaseLease.php');

        $this->assertStringContainsString("defined('SIGHUP')", $runner);
        $this->assertStringContainsString("defined('SIGQUIT')", $runner);
        $this->assertStringContainsString('proc_terminate($child, 9)', $runner);
        $this->assertStringContainsString('LEASE_GIT_TIMEOUT', $lease);
        $this->assertStringContainsString('stream_select(', $lease);
        $this->assertStringNotContainsString('TESTING_DB_LEASE_BASE_DIR', $runner);
        $this->assertStringNotContainsString('TESTING_DB_LEASE_BASE_DIR', file_get_contents($this->root.'/tests/bootstrap.php'));
    }

    public function test_bootstrap_and_runner_do_not_read_environment_files_or_contain_destructive_commands(): void
    {
        $contents = file_get_contents($this->root.'/tests/bootstrap.php')
            .file_get_contents($this->root.'/tests/Support/TestingDatabaseLease.php')
            .file_get_contents($this->root.'/tests/Support/run-with-testing-db-lease.php');

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe', 'DROP TABLE', 'TRUNCATE TABLE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $contents);
        }
        $this->assertDoesNotMatchRegularExpression('/file_get_contents\([^\n]*\.env/', $contents);
        $this->assertDoesNotMatchRegularExpression('/getenv\(["\']DB_/', $contents);
    }

    /** @return array{root: string, repository: string, worktree: string} */
    private function createGitWorktreeFixture(): array
    {
        $root = $this->temporaryDirectory('lease-git-');
        $repository = $root.DIRECTORY_SEPARATOR.'repository';
        $worktree = $root.DIRECTORY_SEPARATOR.'worktree';
        mkdir($repository, 0700, true);

        $this->runProcess(['git', 'init', '--quiet', $repository], $root);
        $this->runProcess([
            'git', '-C', $repository,
            '-c', 'user.name=Lease Test',
            '-c', 'user.email=lease-test@example.test',
            'commit', '--quiet', '--allow-empty', '-m', 'fixture',
        ], $root);
        $this->runProcess([
            'git', '-C', $repository,
            'remote', 'add', 'origin', 'https://example.test/linguacafe.git',
        ], $root);
        $this->runProcess([
            'git', '-C', $repository,
            'worktree', 'add', '--quiet', '--detach', $worktree, 'HEAD',
        ], $root);

        return compact('root', 'repository', 'worktree');
    }

    /** @param array{root: string, repository: string, worktree: string} $fixture */
    private function removeGitWorktreeFixture(array $fixture): void
    {
        if (is_dir($fixture['repository']) && is_dir($fixture['worktree'])) {
            $this->runProcess([
                'git', '-C', $fixture['repository'],
                'worktree', 'remove', $fixture['worktree'],
            ], $fixture['root'], false);
        }
        $this->removeDirectory($fixture['root']);
    }

    /** @param list<string> $command */
    private function runProcess(array $command, string $workingDirectory, bool $mustSucceed = true): array
    {
        $descriptors = [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
        $this->assertIsResource($process, 'Could not start fixture process.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($mustSucceed) {
            $this->assertSame(0, $exitCode, "Fixture process failed. STDERR: {$stderr}");
        }

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
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
