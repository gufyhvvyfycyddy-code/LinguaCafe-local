<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PabR3BrowserAcceptanceFailure;
use PabR3BrowserAcceptanceHarness;

require_once dirname(__DIR__).'/Support/run-pab-r3-browser-acceptance.php';

final class PabR3BrowserAcceptanceEventLog
{
    /** @var list<string> */
    public array $events = [];
}

final class PabR3BrowserAcceptanceFakeLease
{
    public function __construct(
        private readonly PabR3BrowserAcceptanceEventLog $log,
        private readonly bool $inherited = false,
    ) {
    }

    public function createInheritanceProof(): array
    {
        $this->log->events[] = 'lease_proof';

        return [
            'LINGUACAFE_TEST_DB_LEASE_TOKEN' => 'fake-token',
            'LINGUACAFE_TEST_DB_LEASE_OWNER_PID' => '12345',
            'LINGUACAFE_TEST_DB_LEASE_IDENTITY' => str_repeat('a', 64),
        ];
    }

    public function release(): void
    {
        $this->log->events[] = 'lease_release';
    }

    public function metadata(): array
    {
        return [
            'mode' => 'exclusive',
            'label' => 'pab-r3-browser-acceptance',
        ];
    }

    public function isInherited(): bool
    {
        return $this->inherited;
    }
}

final class PabR3BrowserAcceptanceHarnessTest extends TestCase
{
    public function test_non_testing_environment_rejects_before_lease_mutation_or_child_launch(): void
    {
        $log = new PabR3BrowserAcceptanceEventLog();
        $evidence = [];
        $harness = $this->harness($log, evidence: $evidence);

        $exitCode = $harness->run('production', ['php', '-v']);

        $this->assertSame(PabR3BrowserAcceptanceHarness::EXIT_NOT_TESTING, $exitCode);
        $this->assertSame([], $log->events);
        $this->assertContains(
            '[pab-r3-browser-acceptance] PAB_R3_ENV_NOT_TESTING',
            array_column($evidence, 'line'),
        );
    }

    public function test_preexisting_cancellation_exits_before_lease_or_sentinel_action(): void
    {
        $log = new PabR3BrowserAcceptanceEventLog();
        $evidence = [];
        $harness = $this->harness(
            $log,
            cancellationRequested: static fn (): bool => true,
            evidence: $evidence,
        );

        $exitCode = $harness->run('testing', ['php', '-v']);

        $this->assertSame(PabR3BrowserAcceptanceHarness::EXIT_CANCELLED, $exitCode);
        $this->assertSame([], $log->events);
        $this->assertContains(
            '[pab-r3-browser-acceptance] PAB_R3_CANCELLED',
            array_column($evidence, 'line'),
        );
    }

    public function test_artisan_serve_requires_no_reload_before_any_lease_or_sentinel_action(): void
    {
        $log = new PabR3BrowserAcceptanceEventLog();
        $evidence = [];
        $harness = $this->harness($log, evidence: $evidence);

        $exitCode = $harness->run('testing', [
            'php',
            'artisan',
            '--env=testing',
            'serve',
            '--host=127.0.0.1',
        ]);

        $this->assertSame(PabR3BrowserAcceptanceHarness::EXIT_USAGE, $exitCode);
        $this->assertSame([], $log->events);
        $this->assertContains(
            '[pab-r3-browser-acceptance] PAB_R3_ARTISAN_SERVE_NO_RELOAD_REQUIRED',
            array_column($evidence, 'line'),
        );
    }

    public function test_unsupported_artisan_lookalikes_are_left_unchanged(): void
    {
        $root = dirname(__DIR__, 2);
        $commands = [
            [PHP_BINARY, '-r', 'echo "ok";', 'artisan', 'serve'],
            ['node', 'artisan', 'serve', '--no-reload'],
            [PHP_BINARY, 'artisan', 'help', 'serve'],
            [PHP_BINARY, 'artisan', 'list', 'serve'],
        ];

        foreach ($commands as $command) {
            $prepared = \preparePabR3BrowserAcceptanceChild($command, $root);
            $this->assertSame($command, $prepared['command']);
            $this->assertSame($root, $prepared['working_directory']);

            $log = new PabR3BrowserAcceptanceEventLog();
            $capturedCommand = null;
            $harness = $this->harness(
                $log,
                runChild: static function (array $actualCommand) use ($log, &$capturedCommand): int {
                    $capturedCommand = $actualCommand;
                    $log->events[] = 'child';

                    return 0;
                },
            );

            $this->assertSame(0, $harness->run('testing', $command));
            $this->assertSame($command, $capturedCommand);
        }
    }

    public function test_sentinel_has_required_prefix_and_uses_exactly_32_random_bytes(): void
    {
        $requestedLengths = [];
        $sentinel = PabR3BrowserAcceptanceHarness::generateSentinel(
            static function (int $length) use (&$requestedLengths): string {
                $requestedLengths[] = $length;

                return str_repeat("\xA5", $length);
            },
        );

        $this->assertSame([32], $requestedLengths);
        $this->assertMatchesRegularExpression(
            '/^__testing_acceptance_sentinel_[a-f0-9]{64}$/D',
            $sentinel,
        );
        $this->assertSame(
            '__testing_acceptance_sentinel_'.str_repeat('a5', 32),
            $sentinel,
        );
    }

    public function test_default_sentinel_generation_produces_distinct_valid_values(): void
    {
        $first = PabR3BrowserAcceptanceHarness::generateSentinel();
        $second = PabR3BrowserAcceptanceHarness::generateSentinel();

        $this->assertMatchesRegularExpression(
            '/^__testing_acceptance_sentinel_[a-f0-9]{64}$/D',
            $first,
        );
        $this->assertMatchesRegularExpression(
            '/^__testing_acceptance_sentinel_[a-f0-9]{64}$/D',
            $second,
        );
        $this->assertNotSame($first, $second);
    }

    public function test_lifecycle_order_child_environment_and_exact_command_are_preserved(): void
    {
        $log = new PabR3BrowserAcceptanceEventLog();
        $evidence = [];
        $capturedCommand = null;
        $capturedEnvironment = null;
        $createdSentinel = null;
        $cleanedSentinel = null;
        $command = [
            'php',
            'artisan',
            'serve',
            '--no-reload',
            '--host=127.0.0.1',
            '--value=hello world',
            '$HOME;echo',
            '*',
        ];

        $harness = $this->harness(
            $log,
            createSentinel: static function (string $sentinel) use ($log, &$createdSentinel): bool {
                $createdSentinel = $sentinel;
                $log->events[] = 'sentinel_create';

                return true;
            },
            cleanupSentinel: static function (string $sentinel) use ($log, &$cleanedSentinel): bool {
                $cleanedSentinel = $sentinel;
                $log->events[] = 'sentinel_cleanup';

                return true;
            },
            runChild: static function (array $actualCommand, array $environment) use (
                $log,
                &$capturedCommand,
                &$capturedEnvironment,
            ): int {
                $capturedCommand = $actualCommand;
                $capturedEnvironment = $environment;
                $log->events[] = 'child';

                return 0;
            },
            environmentProvider: static fn (): array => [
                'APP_ENV' => 'testing',
                'EXISTING_VALUE' => 'keep-me',
            ],
            evidence: $evidence,
        );

        $exitCode = $harness->run('testing', $command);

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            'lease_acquire',
            'sentinel_create',
            'lease_proof',
            'child',
            'sentinel_cleanup',
            'lease_release',
        ], $log->events);
        $this->assertSame($command, $capturedCommand);
        $this->assertIsArray($capturedEnvironment);
        $this->assertSame('keep-me', $capturedEnvironment['EXISTING_VALUE'] ?? null);
        $this->assertSame('fake-token', $capturedEnvironment['LINGUACAFE_TEST_DB_LEASE_TOKEN'] ?? null);
        $this->assertSame($createdSentinel, $capturedEnvironment['LINGUACAFE_TEST_SENTINEL'] ?? null);
        $this->assertSame($createdSentinel, $cleanedSentinel);
        $this->assertNotEmpty($createdSentinel);
        $serializedEvidence = json_encode($evidence, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $createdSentinel, $serializedEvidence);
        $this->assertStringContainsString(hash('sha256', (string) $createdSentinel), $serializedEvidence);
    }

    public function test_cancellation_after_sentinel_creation_skips_child_then_cleans_and_releases(): void
    {
        $log = new PabR3BrowserAcceptanceEventLog();
        $cancelled = false;
        $evidence = [];
        $harness = $this->harness(
            $log,
            createSentinel: static function (string $sentinel) use ($log, &$cancelled): bool {
                $log->events[] = 'sentinel_create';
                $cancelled = true;

                return true;
            },
            cancellationRequested: static function () use (&$cancelled): bool {
                return $cancelled;
            },
            evidence: $evidence,
        );

        $exitCode = $harness->run('testing', ['php', '-v']);

        $this->assertSame(PabR3BrowserAcceptanceHarness::EXIT_CANCELLED, $exitCode);
        $this->assertSame([
            'lease_acquire',
            'sentinel_create',
            'sentinel_cleanup',
            'lease_release',
        ], $log->events);
        $this->assertContains(
            '[pab-r3-browser-acceptance] PAB_R3_CANCELLED',
            array_column($evidence, 'line'),
        );
    }

    public function test_child_launch_failure_still_cleans_exact_sentinel_and_releases_lease(): void
    {
        $log = new PabR3BrowserAcceptanceEventLog();
        $created = null;
        $cleaned = null;
        $evidence = [];
        $harness = $this->harness(
            $log,
            createSentinel: static function (string $sentinel) use ($log, &$created): bool {
                $created = $sentinel;
                $log->events[] = 'sentinel_create';

                return true;
            },
            cleanupSentinel: static function (string $sentinel) use ($log, &$cleaned): bool {
                $cleaned = $sentinel;
                $log->events[] = 'sentinel_cleanup';

                return true;
            },
            runChild: static function () use ($log): int {
                $log->events[] = 'child';

                throw new PabR3BrowserAcceptanceFailure(
                    'PAB_R3_CHILD_START_FAILED',
                    PabR3BrowserAcceptanceHarness::EXIT_CHILD_START_FAILED,
                );
            },
            evidence: $evidence,
        );

        $exitCode = $harness->run('testing', ['missing-child']);

        $this->assertSame(PabR3BrowserAcceptanceHarness::EXIT_CHILD_START_FAILED, $exitCode);
        $this->assertSame($created, $cleaned);
        $this->assertSame([
            'lease_acquire',
            'sentinel_create',
            'lease_proof',
            'child',
            'sentinel_cleanup',
            'lease_release',
        ], $log->events);
        $this->assertContains(
            '[pab-r3-browser-acceptance] PAB_R3_CHILD_START_FAILED',
            array_column($evidence, 'line'),
        );
    }

    public function test_child_nonzero_exit_is_propagated_after_cleanup_and_release(): void
    {
        $log = new PabR3BrowserAcceptanceEventLog();
        $harness = $this->harness(
            $log,
            runChild: static function () use ($log): int {
                $log->events[] = 'child';

                return 7;
            },
        );

        $exitCode = $harness->run('testing', ['php', '-r', 'exit(7);']);

        $this->assertSame(7, $exitCode);
        $this->assertSame([
            'lease_acquire',
            'sentinel_create',
            'lease_proof',
            'child',
            'sentinel_cleanup',
            'lease_release',
        ], $log->events);
    }

    public function test_cleanup_failure_is_nonzero_and_machine_visible_even_after_green_child(): void
    {
        $log = new PabR3BrowserAcceptanceEventLog();
        $evidence = [];
        $harness = $this->harness(
            $log,
            cleanupSentinel: static function () use ($log): bool {
                $log->events[] = 'sentinel_cleanup';

                return false;
            },
            runChild: static function () use ($log): int {
                $log->events[] = 'child';

                return 0;
            },
            evidence: $evidence,
        );

        $exitCode = $harness->run('testing', ['php', '-v']);

        $this->assertSame(PabR3BrowserAcceptanceHarness::EXIT_CLEANUP_FAILED, $exitCode);
        $this->assertSame([
            'lease_acquire',
            'sentinel_create',
            'lease_proof',
            'child',
            'sentinel_cleanup',
            'lease_release',
        ], $log->events);
        $this->assertContains(
            '[pab-r3-browser-acceptance] PAB_R3_SENTINEL_CLEANUP_FAILED',
            array_column($evidence, 'line'),
        );
    }

    public function test_cleanup_exception_is_nonzero_and_still_releases_lease(): void
    {
        $log = new PabR3BrowserAcceptanceEventLog();
        $harness = $this->harness(
            $log,
            cleanupSentinel: static function () use ($log): bool {
                $log->events[] = 'sentinel_cleanup';
                throw new \RuntimeException('simulated cleanup failure');
            },
            runChild: static function () use ($log): int {
                $log->events[] = 'child';

                return 0;
            },
        );

        $exitCode = $harness->run('testing', ['php', '-v']);

        $this->assertSame(PabR3BrowserAcceptanceHarness::EXIT_CLEANUP_FAILED, $exitCode);
        $this->assertSame('lease_release', $log->events[array_key_last($log->events)]);
    }

    public function test_exact_cleanup_is_idempotent_and_preserves_other_sentinel_rows(): void
    {
        $owned = PabR3BrowserAcceptanceHarness::SENTINEL_PREFIX.str_repeat('a', 64);
        $other = PabR3BrowserAcceptanceHarness::SENTINEL_PREFIX.str_repeat('b', 64);
        $rows = [$owned => true, $other => true];
        $deletedValues = [];

        $delete = static function (string $value) use (&$rows, &$deletedValues): void {
            $deletedValues[] = $value;
            unset($rows[$value]);
        };
        $exists = static function (string $value) use (&$rows): bool {
            return isset($rows[$value]);
        };

        $this->assertTrue(PabR3BrowserAcceptanceHarness::cleanupExactSentinel($owned, $delete, $exists));
        $this->assertTrue(PabR3BrowserAcceptanceHarness::cleanupExactSentinel($owned, $delete, $exists));
        $this->assertSame([$owned, $owned], $deletedValues);
        $this->assertArrayNotHasKey($owned, $rows);
        $this->assertArrayHasKey($other, $rows);
    }

    public function test_create_rejects_collision_before_insert_and_proves_exact_created_value(): void
    {
        $sentinel = PabR3BrowserAcceptanceHarness::SENTINEL_PREFIX.str_repeat('c', 64);
        $inserted = [];

        try {
            PabR3BrowserAcceptanceHarness::createExactSentinel(
                $sentinel,
                static fn (): bool => true,
                static function (string $value) use (&$inserted): bool {
                    $inserted[] = $value;

                    return true;
                },
                static function (): void {
                    throw new \RuntimeException('Collision path must not clean an existing sentinel.');
                },
            );
            $this->fail('Existing exact sentinel must be rejected.');
        } catch (PabR3BrowserAcceptanceFailure $error) {
            $this->assertSame('PAB_R3_SENTINEL_COLLISION', $error->machineCode);
        }
        $this->assertSame([], $inserted);

        $rows = [];
        PabR3BrowserAcceptanceHarness::createExactSentinel(
            $sentinel,
            static function (string $value) use (&$rows): bool {
                return isset($rows[$value]);
            },
            static function (string $value) use (&$rows): bool {
                $rows[$value] = true;

                return true;
            },
            static function (string $value) use (&$rows): void {
                unset($rows[$value]);
            },
        );
        $this->assertSame([$sentinel => true], $rows);
    }

    public function test_failed_creation_proof_removes_only_the_new_exact_sentinel(): void
    {
        $owned = PabR3BrowserAcceptanceHarness::SENTINEL_PREFIX.str_repeat('d', 64);
        $other = PabR3BrowserAcceptanceHarness::SENTINEL_PREFIX.str_repeat('e', 64);
        $rows = [$other => true];
        $deleteValues = [];
        $existsChecks = 0;

        try {
            PabR3BrowserAcceptanceHarness::createExactSentinel(
                $owned,
                static function (string $value) use (&$rows, &$existsChecks, $owned): bool {
                    $existsChecks++;
                    if ($value === $owned && $existsChecks === 2) {
                        return false;
                    }

                    return isset($rows[$value]);
                },
                static function (string $value) use (&$rows): bool {
                    $rows[$value] = true;

                    return true;
                },
                static function (string $value) use (&$rows, &$deleteValues): void {
                    $deleteValues[] = $value;
                    unset($rows[$value]);
                },
            );
            $this->fail('Unproven creation must fail closed.');
        } catch (PabR3BrowserAcceptanceFailure $error) {
            $this->assertSame('PAB_R3_SENTINEL_CREATE_UNPROVEN', $error->machineCode);
        }

        $this->assertSame([$owned], $deleteValues);
        $this->assertArrayNotHasKey($owned, $rows);
        $this->assertArrayHasKey($other, $rows);
    }

    public function test_argument_parser_preserves_every_command_argument_after_separator(): void
    {
        $command = [
            'php',
            'artisan',
            'serve',
            '--host=127.0.0.1',
            '--value=hello world',
            '$HOME;echo',
            '*',
            '&&',
        ];
        $parsed = \parsePabR3BrowserAcceptanceArguments([
            'run-pab-r3-browser-acceptance.php',
            '--label=browser-final',
            '--wait-ms=1234',
            '--',
            ...$command,
        ]);

        $this->assertSame('browser-final', $parsed['label']);
        $this->assertSame(1234, $parsed['wait_ms']);
        $this->assertSame($command, $parsed['command']);
    }

    public function test_artisan_serve_is_prepared_as_one_direct_php_server_process(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pab-r3-direct-server-'.bin2hex(random_bytes(8));
        $public = $root.DIRECTORY_SEPARATOR.'public';
        $support = $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support';
        mkdir($public, 0700, true);
        mkdir($support, 0700, true);
        file_put_contents($support.DIRECTORY_SEPARATOR.'pab-r3-browser-server.php', "<?php\n");

        try {
            $prepared = \preparePabR3BrowserAcceptanceChild([
                PHP_BINARY,
                'artisan',
                '--env=testing',
                'serve',
                '--no-reload',
                '--host=127.0.0.1',
                '--port=8765',
            ], $root);

            $this->assertSame([
                PHP_BINARY,
                '-S',
                '127.0.0.1:8765',
                $root.'/tests/Support/pab-r3-browser-server.php',
            ], $prepared['command']);
            $this->assertSame($root.'/public', $prepared['working_directory']);
            $this->assertNotContains('artisan', $prepared['command']);
        } finally {
            @unlink($support.DIRECTORY_SEPARATOR.'pab-r3-browser-server.php');
            @rmdir($support);
            @rmdir($root.DIRECTORY_SEPARATOR.'tests');
            @rmdir($public);
            @rmdir($root);
        }
    }

    public function test_checked_in_router_serves_laravel_before_cancellation_closes_the_owned_child_and_port(): void
    {
        $root = dirname(__DIR__, 2);

        $reservation = null;
        $port = null;
        for ($attempt = 0; $attempt < 20 && ! is_resource($reservation); $attempt++) {
            $candidate = random_int(20000, 45000);
            $reservation = @stream_socket_server('tcp://127.0.0.1:'.$candidate, $errorCode, $errorMessage);
            if (is_resource($reservation)) {
                $port = $candidate;
            }
        }
        $this->assertIsResource($reservation, $errorMessage ?? 'Could not reserve a high acceptance port.');
        $this->assertIsInt($port);
        fclose($reservation);

        $serverObserved = false;
        $cancelDeadline = hrtime(true) + 5_000_000_000;
        $cancellationRequested = static function () use ($port, &$serverObserved, $cancelDeadline): bool {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.05);
            if (is_resource($connection)) {
                fwrite($connection, "GET /__testing/acceptance-sentinel HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
                stream_set_timeout($connection, 1);
                $response = stream_get_contents($connection);
                fclose($connection);
                $serverObserved = is_string($response)
                    && str_contains($response, '503 Service Unavailable')
                    && str_contains($response, '"environment":"testing"')
                    && str_contains($response, '"database_is_testing":true')
                    && str_contains($response, '"sentinel_present":false');

                return $serverObserved;
            }

            return hrtime(true) >= $cancelDeadline;
        };

        $environment = getenv();
        $this->assertIsArray($environment);
        $environment['APP_ENV'] = 'testing';
        $environment['PHP_CLI_SERVER_WORKERS'] = '4';
        try {
            \runPabR3BrowserAcceptanceChild(
                [
                    PHP_BINARY,
                    'artisan',
                    '--env=testing',
                    'serve',
                    '--no-reload',
                    '--host=127.0.0.1',
                    '--port='.$port,
                ],
                $environment,
                $root,
                $cancellationRequested,
            );
            $this->fail('Cancellation must stop the directly owned server process.');
        } catch (PabR3BrowserAcceptanceFailure $error) {
            $this->assertSame('PAB_R3_CANCELLED', $error->machineCode);
            $this->assertSame(PabR3BrowserAcceptanceHarness::EXIT_CANCELLED, $error->exitCode);
        }

        $this->assertTrue(
            $serverObserved,
            'The checked-in router must bootstrap the current testing application before cancellation.',
        );
        $portClosed = false;
        $closeDeadline = hrtime(true) + 2_000_000_000;
        do {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.05);
            if (! is_resource($connection)) {
                $portClosed = true;
                break;
            }
            fclose($connection);
            usleep(25_000);
        } while (hrtime(true) < $closeDeadline);
        $this->assertTrue($portClosed, 'Cancellation must leave no listening server on the acceptance port.');
    }

    public function test_source_has_no_env_file_write_migration_destructive_notification_or_shell_expansion_path(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/Support/run-pab-r3-browser-acceptance.php');
        $this->assertIsString($source);

        foreach ([
            '.env',
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'db:wipe',
            'DROP TABLE',
            'TRUNCATE TABLE',
            'notify.ps1',
            'shell_exec(',
            'passthru(',
            'system(',
            'putenv(',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }

        $this->assertStringContainsString('PAB_R3_ARTISAN_SERVE_NO_RELOAD_REQUIRED', $source);
        $this->assertStringContainsString("in_array('--no-reload', \$command, true)", $source);
        $this->assertStringContainsString('static function () use (&$cancelled): bool', $source);
        $this->assertStringContainsString('random_bytes($length)', $source);
        $this->assertStringNotContainsString('uniqid(', $source);
        $this->assertStringNotContainsString('mt_rand(', $source);
        $this->assertStringContainsString("['bypass_shell' => true]", $source);
        $this->assertStringContainsString('proc_open(', $source);
        $this->assertStringContainsString("PHP_BINARY, '-S'", $source);
        $this->assertStringContainsString("unset(\$environment['PHP_CLI_SERVER_WORKERS'])", $source);
        $this->assertStringContainsString("['LINGUACAFE_TEST_SENTINEL' => \$sentinel]", $source);
        $this->assertStringContainsString("table('migrations')->where('migration', \$value)->delete()", $source);
        $this->assertStringNotContainsString("table('migrations')->delete()", $source);

        $routerSource = file_get_contents(dirname(__DIR__).'/Support/pab-r3-browser-server.php');
        $this->assertIsString($routerSource);
        $this->assertStringContainsString("require \$projectRoot.'/tests/bootstrap.php'", $routerSource);
        $this->assertStringContainsString("require \$projectRoot.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'", $routerSource);
    }

    private function harness(
        PabR3BrowserAcceptanceEventLog $log,
        ?callable $createSentinel = null,
        ?callable $cleanupSentinel = null,
        ?callable $runChild = null,
        ?callable $environmentProvider = null,
        ?callable $cancellationRequested = null,
        array &$evidence = [],
    ): PabR3BrowserAcceptanceHarness {
        return new PabR3BrowserAcceptanceHarness(
            acquireLease: static function () use ($log): PabR3BrowserAcceptanceFakeLease {
                $log->events[] = 'lease_acquire';

                return new PabR3BrowserAcceptanceFakeLease($log);
            },
            createSentinel: $createSentinel ?? static function () use ($log): bool {
                $log->events[] = 'sentinel_create';

                return true;
            },
            cleanupSentinel: $cleanupSentinel ?? static function () use ($log): bool {
                $log->events[] = 'sentinel_cleanup';

                return true;
            },
            runChild: $runChild ?? static function () use ($log): int {
                $log->events[] = 'child';

                return 0;
            },
            randomBytes: static fn (int $length): string => str_repeat("\x5A", $length),
            environmentProvider: $environmentProvider ?? static fn (): array => ['APP_ENV' => 'testing'],
            evidenceWriter: static function (string $stream, string $line) use (&$evidence): void {
                $evidence[] = ['stream' => $stream, 'line' => $line];
            },
            cancellationRequested: $cancellationRequested,
        );
    }
}
