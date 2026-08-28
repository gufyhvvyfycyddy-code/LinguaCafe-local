<?php

namespace Tests\Unit;

use H01LoadObservabilityFailure;
use H01LoadObservabilityHarness;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/Support/run-h01-load-observability.php';

final class H01LoadObservabilityHarnessTest extends TestCase
{
    public function test_argument_contract_has_bounded_defaults_and_accepts_overrides(): void
    {
        $this->assertSame([
            'port' => 8891,
            'vus' => 4,
            'duration' => '3s',
            'sample_ms' => 250,
        ], H01LoadObservabilityHarness::parseArguments(['runner.php']));

        $this->assertSame([
            'port' => 8899,
            'vus' => 12,
            'duration' => '7s',
            'sample_ms' => 500,
        ], H01LoadObservabilityHarness::parseArguments([
            'runner.php',
            '--port=8899',
            '--vus=12',
            '--duration=7s',
            '--sample-ms=500',
        ]));
    }

    public function test_argument_contract_fails_closed_for_invalid_values(): void
    {
        foreach ([
            ['runner.php', '--port=80'],
            ['runner.php', '--vus=0'],
            ['runner.php', '--sample-ms=10'],
            ['runner.php', '--duration=forever'],
            ['runner.php', '--duration=999ms'],
            ['runner.php', '--duration=31m'],
            ['runner.php', '--unknown=1'],
        ] as $arguments) {
            try {
                H01LoadObservabilityHarness::parseArguments($arguments);
                $this->fail('Invalid H-01 arguments were accepted: '.json_encode($arguments));
            } catch (H01LoadObservabilityFailure) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_runtime_boundary_requires_testing_and_existing_pab_sentinel_contract(): void
    {
        H01LoadObservabilityHarness::assertRuntimeBoundary(
            'testing',
            '__testing_acceptance_sentinel_'.str_repeat('a', 64),
        );
        $this->addToAssertionCount(1);

        foreach ([
            ['production', '__testing_acceptance_sentinel_'.str_repeat('a', 64)],
            ['testing', false],
            ['testing', 'not-a-pab-sentinel'],
        ] as [$environment, $sentinel]) {
            try {
                H01LoadObservabilityHarness::assertRuntimeBoundary($environment, $sentinel);
                $this->fail('Unsafe H-01 runtime boundary was accepted.');
            } catch (H01LoadObservabilityFailure) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_sentinel_readiness_budget_allows_a_cold_laravel_request_to_finish(): void
    {
        $this->assertGreaterThanOrEqual(5.0, H01LoadObservabilityHarness::SENTINEL_REQUEST_TIMEOUT_SECONDS);
        $this->assertGreaterThan(
            H01LoadObservabilityHarness::SENTINEL_REQUEST_TIMEOUT_SECONDS,
            H01LoadObservabilityHarness::SENTINEL_READY_DEADLINE_SECONDS,
        );
    }

    public function test_windows_executable_resolution_uses_real_exe_path_instead_of_extensionless_proxy(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h01-k6-resolution-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $executable = $directory.DIRECTORY_SEPARATOR.'k6.exe';
        file_put_contents($executable, 'fake');

        try {
            $this->assertSame(
                realpath($executable),
                H01LoadObservabilityHarness::resolveExecutable('k6', $directory, 'Windows'),
            );
            $this->assertNull(H01LoadObservabilityHarness::resolveExecutable('missing-k6', $directory, 'Windows'));
        } finally {
            @unlink($executable);
            @rmdir($directory);
        }
    }

    public function test_installed_k6_resolves_to_an_executable_on_windows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows k6 toolchain check.');
        }

        $resolved = H01LoadObservabilityHarness::resolveExecutable('k6');
        $this->assertNotNull($resolved, 'Install k6 with winget before H-01 acceptance.');
        $this->assertStringEndsWith('k6.exe', strtolower(str_replace('\\', '/', $resolved)));
    }

    public function test_sample_series_aggregation_keeps_min_max_and_last(): void
    {
        $samples = [
            ['threads_connected' => 4, 'threads_running' => 1, 'queue_backlog' => 3],
            ['threads_connected' => 9, 'threads_running' => 5, 'queue_backlog' => 7],
            ['threads_connected' => 6, 'threads_running' => 2, 'queue_backlog' => 2],
        ];

        $this->assertSame(['min' => 4, 'max' => 9, 'last' => 6], H01LoadObservabilityHarness::summarizeSeries($samples, 'threads_connected'));
        $this->assertSame(['min' => 1, 'max' => 5, 'last' => 2], H01LoadObservabilityHarness::summarizeSeries($samples, 'threads_running'));
        $this->assertSame(['min' => 2, 'max' => 7, 'last' => 2], H01LoadObservabilityHarness::summarizeSeries($samples, 'queue_backlog'));
    }

    public function test_final_summary_exposes_single_machine_readable_contract(): void
    {
        $k6 = [
            'metrics' => [
                'http_reqs' => ['values' => ['count' => 120, 'rate' => 40.0]],
                'http_req_failed' => ['values' => ['rate' => 0.0]],
                'checks' => ['values' => ['rate' => 1.0]],
                'http_req_duration' => ['values' => [
                    'avg' => 12.5,
                    'p(95)' => 20.0,
                    'p(99)' => 25.0,
                    'max' => 30.0,
                ]],
                'h03_login_page_duration' => ['values' => [
                    'count' => 10,
                    'avg' => 8.0,
                    'p(95)' => 12.0,
                    'p(99)' => 14.0,
                    'max' => 15.0,
                ]],
                'h03_reading_duration' => ['values' => [
                    'count' => 4,
                    'avg' => 5.0,
                    'p(95)' => 7.0,
                    'p(99)' => 8.0,
                    'max' => 9.0,
                ]],
            ],
        ];
        $samples = [
            ['threads_connected' => 3, 'threads_running' => 1, 'queue_backlog' => 0],
            ['threads_connected' => 5, 'threads_running' => 2, 'queue_backlog' => 1],
        ];
        $runtime = [
            'queue_connection' => 'database',
            'queue_driver' => 'database',
            'queue_name' => 'default',
        ];

        $summary = H01LoadObservabilityHarness::buildFinalSummary($k6, $samples, $runtime, 0, 3.25);

        $this->assertSame(1, $summary['schema_version']);
        $this->assertSame(120, $summary['http']['requests']);
        $this->assertSame(20.0, $summary['http']['duration_ms']['p95']);
        $this->assertSame(25.0, $summary['http']['duration_ms']['p99']);
        $this->assertSame([
            'count' => 10,
            'avg' => 8.0,
            'p95' => 12.0,
            'p99' => 14.0,
            'max' => 15.0,
        ], $summary['http']['flow_duration_ms']['login_page']);
        $this->assertSame([
            'count' => 4,
            'avg' => 5.0,
            'p95' => 7.0,
            'p99' => 8.0,
            'max' => 9.0,
        ], $summary['http']['flow_duration_ms']['reading']);
        $this->assertSame(5, $summary['mysql']['threads_connected']['max']);
        $this->assertSame(1, $summary['queue']['backlog']['max']);
        $this->assertSame(0, $summary['errors']['laravel_error_entries']);
        $this->assertSame('h01_sentinel_smoke', $summary['scenario']);

        $runtime['scenario'] = 'h02_representative_reading_lookup_sense_review';
        $overridden = H01LoadObservabilityHarness::buildFinalSummary($k6, $samples, $runtime, 0, 3.25);
        $this->assertSame('h02_representative_reading_lookup_sense_review', $overridden['scenario']);

        unset($k6['metrics']['h03_login_page_duration'], $k6['metrics']['h03_reading_duration']);
        $withoutFlowMetrics = H01LoadObservabilityHarness::buildFinalSummary($k6, $samples, $runtime, 0, 3.25);
        $this->assertSame([], $withoutFlowMetrics['http']['flow_duration_ms']);
    }

    public function test_final_summary_fails_closed_when_required_k6_metrics_are_missing(): void
    {
        $this->expectException(H01LoadObservabilityFailure::class);
        $this->expectExceptionMessage('H01_K6_REQUIRED_METRIC_MISSING');

        H01LoadObservabilityHarness::buildFinalSummary(
            ['metrics' => ['http_reqs' => ['values' => ['count' => 1]]]],
            [
                ['threads_connected' => 1, 'threads_running' => 1, 'queue_backlog' => 0],
                ['threads_connected' => 1, 'threads_running' => 1, 'queue_backlog' => 0],
            ],
            [
                'queue_connection' => 'sync',
                'queue_driver' => 'sync',
                'queue_name' => 'default',
            ],
            0,
            1.0,
        );
    }

    public function test_process_cleanup_terminates_a_running_child(): void
    {
        $tempDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h01-process-cleanup-'.bin2hex(random_bytes(6));
        mkdir($tempDirectory, 0700, true);
        $environment = getenv();
        $this->assertIsArray($environment);
        $child = h01StartProcess(
            [PHP_BINARY, '-r', 'sleep(30);'],
            dirname(__DIR__, 2),
            $environment,
            $tempDirectory,
            'cleanup-test',
        );

        try {
            $status = proc_get_status($child['process']);
            $this->assertIsArray($status);
            $this->assertTrue($status['running']);
            $this->assertTrue(h01StopProcess($child['process']));
        } finally {
            @unlink($child['stdout']);
            @unlink($child['stderr']);
            @rmdir($tempDirectory);
        }
    }

    public function test_temp_directory_cleanup_removes_task_owned_files_and_directory(): void
    {
        $tempDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h01-temp-cleanup-'.bin2hex(random_bytes(6));
        mkdir($tempDirectory, 0700, true);
        file_put_contents($tempDirectory.DIRECTORY_SEPARATOR.'stdout.log', 'done');
        file_put_contents($tempDirectory.DIRECTORY_SEPARATOR.'stderr.log', '');

        h01RemoveTempDirectory($tempDirectory);

        $this->assertDirectoryDoesNotExist($tempDirectory);
    }

    public function test_k6_sampling_owner_has_a_finally_cleanup_boundary(): void
    {
        $runner = (string) file_get_contents(dirname(__DIR__).'/Support/run-h01-load-observability.php');
        $start = strpos($runner, 'function h01RunK6AndSample(');
        $end = strpos($runner, 'function h01K6Version(', $start === false ? 0 : $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $slice = substr($runner, $start, $end - $start);
        $this->assertStringContainsString('finally', $slice);
        $this->assertStringContainsString("h01StopProcess(\$child['process'])", $slice);
    }

    public function test_log_error_counter_only_counts_new_high_severity_entries(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h01-log-counter-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $log = $directory.DIRECTORY_SEPARATOR.'laravel.log';
        file_put_contents($log, "[old] testing.ERROR: old\n[old] testing.INFO: old\n");
        $offsets = H01LoadObservabilityHarness::captureLogOffsets($directory);
        file_put_contents($log, "[new] testing.WARNING: warn\n[new] testing.ERROR: bad\n[new] testing.CRITICAL: worse\n", FILE_APPEND);

        try {
            $this->assertSame(2, H01LoadObservabilityHarness::countNewLaravelErrors($directory, $offsets));
        } finally {
            @unlink($log);
            @rmdir($directory);
        }
    }

    public function test_runner_is_testing_only_and_contains_no_destructive_database_command(): void
    {
        $runner = strtolower((string) file_get_contents(dirname(__DIR__).'/Support/run-h01-load-observability.php'));
        $scenario = (string) file_get_contents(dirname(__DIR__).'/load/h01-sentinel-smoke.js');

        $this->assertStringContainsString('h01_pab_sentinel_required', $runner);
        $this->assertStringContainsString('h01_database_not_testing', $runner);
        $this->assertStringContainsString('/__testing/acceptance-sentinel', $scenario);
        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe', 'truncate table', 'drop table'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $runner);
        }
    }
}
