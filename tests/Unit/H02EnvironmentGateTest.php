<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class H02EnvironmentGateTest extends TestCase
{
    private const RUNNER_PATH = __DIR__.'/../Support/run-h02-environment-gate.php';

    protected function setUp(): void
    {
        parent::setUp();
        require_once self::RUNNER_PATH;
    }

    public function test_all_environment_checks_pass_without_running_real_tools(): void
    {
        $commands = [];
        $result = $this->runGate($this->greenResponses(), 'Windows', $commands);

        self::assertTrue($result['ready']);
        self::assertSame(1, $result['schema_version']);
        self::assertSame('Windows', $result['platform']);
        self::assertSame('H02_ENV_READY', $result['details']['machine_code']);
        self::assertCount(7, $commands);
        self::assertFalse(in_array(false, $result['checks'], true));
        self::assertSame('WSL version: 2.7.8.0', $result['details']['wsl_version']['stdout']);
        self::assertSame('linux', $result['details']['docker_info']['ostype']);
    }

    public function test_non_windows_platform_is_blocked_before_external_probes(): void
    {
        $commands = [];
        $result = $this->runGate([], 'Linux', $commands);

        self::assertFalse($result['ready']);
        self::assertSame('H02_ENV_PLATFORM_UNSUPPORTED', $result['details']['machine_code']);
        self::assertSame('platform_windows', $result['details']['failed_check']);
        self::assertSame([], $commands);
    }

    public function test_wsl_failure_is_blocked_with_stable_code(): void
    {
        $commands = [];
        $responses = $this->greenResponses();
        $responses[$this->key(['wsl.exe', '--status'])] = $this->response('', 'WSL unavailable', 1);

        $result = $this->runGate($responses, 'Windows', $commands);

        self::assertFalse($result['ready']);
        self::assertSame('H02_ENV_WSL_UNAVAILABLE', $result['details']['machine_code']);
        self::assertFalse($result['checks']['wsl_status']);
        self::assertSame(1, count($commands));
    }

    public function test_docker_server_failure_is_distinguished_from_cli_failure(): void
    {
        $commands = [];
        $responses = $this->greenResponses();
        $responses[$this->key(['docker.exe', 'version', '--format', '{{json .}}'])] = $this->response('', 'daemon unavailable', 1);

        $result = $this->runGate($responses, 'Windows', $commands);

        self::assertFalse($result['ready']);
        self::assertSame('H02_ENV_DOCKER_SERVER_UNAVAILABLE', $result['details']['machine_code']);
        self::assertFalse($result['checks']['docker_server']);
        self::assertSame(3, count($commands));
    }

    public function test_non_linux_docker_server_is_blocked(): void
    {
        $commands = [];
        $responses = $this->greenResponses();
        $responses[$this->key(['docker.exe', 'info', '--format', '{{json .}}'])] = $this->response(
            json_encode(['OSType' => 'windows'], JSON_THROW_ON_ERROR),
        );

        $result = $this->runGate($responses, 'Windows', $commands);

        self::assertFalse($result['ready']);
        self::assertSame('H02_ENV_DOCKER_NOT_LINUX', $result['details']['machine_code']);
        self::assertFalse($result['checks']['docker_linux']);
        self::assertSame('windows', $result['details']['docker_info']['ostype']);
    }

    public function test_invalid_h02_compose_file_is_blocked_without_building_or_starting(): void
    {
        $commands = [];
        $responses = $this->greenResponses();
        $responses[$this->key([
            'docker.exe',
            'compose',
            '-f',
            'docker-compose.h02-testing.yml',
            'config',
            '--quiet',
        ])] = $this->response('', 'invalid compose', 1);

        $result = $this->runGate($responses, 'Windows', $commands);

        self::assertFalse($result['ready']);
        self::assertSame('H02_ENV_COMPOSE_INVALID', $result['details']['machine_code']);
        self::assertFalse($result['checks']['compose_config']);
    }

    public function test_busy_port_8892_is_blocked(): void
    {
        $commands = [];
        $responses = $this->greenResponses();
        $responses[$this->key($this->portProbeCommand())] = $this->response("1\n");

        $result = $this->runGate($responses, 'Windows', $commands);

        self::assertFalse($result['ready']);
        self::assertSame('H02_ENV_PORT_8892_BUSY', $result['details']['machine_code']);
        self::assertTrue($result['details']['port_8892']['listening']);
        self::assertFalse($result['checks']['port_8892_free']);
    }

    public function test_probe_timeout_is_fail_closed(): void
    {
        $commands = [];
        $responses = $this->greenResponses();
        $responses[$this->key(['wsl.exe', '--status'])] = $this->response('', '', 1, true, true);

        $result = $this->runGate($responses, 'Windows', $commands);

        self::assertFalse($result['ready']);
        self::assertSame('H02_ENV_PROBE_TIMEOUT', $result['details']['machine_code']);
        self::assertTrue($result['details']['wsl_status']['timed_out']);
        self::assertSame(H02_ENVIRONMENT_GATE_TIMEOUT_MS, $commands[0]['timeout_ms']);
    }

    public function test_malformed_probe_response_is_fail_closed(): void
    {
        $commands = [];
        $responses = $this->greenResponses();
        unset($responses[$this->key(['wsl.exe', '--status'])]['timed_out']);

        $result = $this->runGate($responses, 'Windows', $commands);

        self::assertFalse($result['ready']);
        self::assertSame('H02_ENV_PROBE_FAILED', $result['details']['machine_code']);
        self::assertSame('probe', $result['details']['failed_check']);
        self::assertCount(1, $commands);
    }

    public function test_empty_required_probe_output_is_fail_closed(): void
    {
        $commands = [];
        $responses = $this->greenResponses();
        $responses[$this->key(['wsl.exe', '--version'])] = $this->response();

        $result = $this->runGate($responses, 'Windows', $commands);

        self::assertFalse($result['ready']);
        self::assertSame('H02_ENV_WSL_UNAVAILABLE', $result['details']['machine_code']);
        self::assertSame('wsl_version', $result['details']['failed_check']);
        self::assertCount(2, $commands);
    }

    public function test_result_shape_is_bounded_sanitized_and_contains_no_environment_values(): void
    {
        $commands = [];
        $responses = $this->greenResponses();
        $longSecretValue = str_repeat('x', 2500);
        $responses[$this->key(['wsl.exe', '--status'])] = $this->response(
            str_repeat('status ', 500)."request path: /health\nPATH=C:\\private\\runtime-path\nTOKEN=secret-value\nTOKEN={$longSecretValue}\n路径",
            "request path: /stderr\nTOKEN=stderr-secret",
        );

        $result = $this->runGate($responses, 'Windows', $commands);
        $details = $result['details']['wsl_status'];

        self::assertIsArray($result);
        self::assertSame(['schema_version', 'ready', 'platform', 'checks', 'details'], array_keys($result));
        self::assertIsArray($result['checks']);
        self::assertIsArray($result['details']);
        self::assertLessThanOrEqual(H02_ENVIRONMENT_GATE_OUTPUT_TAIL_BYTES, strlen($details['stdout']));
        self::assertStringNotContainsString("\0", $details['stdout']);
        self::assertStringNotContainsString('secret-value', $details['stdout']);
        self::assertStringNotContainsString(str_repeat('x', 32), $details['stdout']);
        self::assertStringContainsString('request path: /health', $details['stdout']);
        self::assertStringContainsString('PATH=[REDACTED]', $details['stdout']);
        self::assertStringContainsString('[REDACTED]', $details['stdout']);
        self::assertStringNotContainsString('stderr-secret', $details['stderr']);
        self::assertStringContainsString('request path: /stderr', $details['stderr']);
        self::assertSame(H02_ENVIRONMENT_GATE_TIMEOUT_MS, $commands[0]['timeout_ms']);
        self::assertSame(dirname(__DIR__, 2), $commands[0]['cwd']);
    }

    public function test_json_and_case_insensitive_environment_values_are_redacted(): void
    {
        $commands = [];
        $responses = $this->greenResponses();
        $responses[$this->key(['wsl.exe', '--status'])] = $this->response(
            "{\"Password\":\"json-secret\",\"Path\":\"C:\\\\private\\\\runtime\"}\n"
                ."path=C:\\\\private\\\\lowercase\n"
                ."path: C:\\\\private\\\\colon\n",
        );

        $result = $this->runGate($responses, 'Windows', $commands);
        $stdout = $result['details']['wsl_status']['stdout'];

        self::assertStringNotContainsString('json-secret', $stdout);
        self::assertStringNotContainsString('C:\\private', $stdout);
        self::assertStringContainsString('Password":"[REDACTED]"', $stdout);
        self::assertStringContainsString('path=[REDACTED]', $stdout);
        self::assertStringContainsString('path: [REDACTED]', $stdout);
    }

    public function test_no_mutating_docker_verbs_are_issued(): void
    {
        $commands = [];
        $this->runGate($this->greenResponses(), 'Windows', $commands);
        $mutatingVerbs = ['build', 'up', 'down', 'pull', 'run', 'start', 'stop', 'rm'];

        foreach ($commands as $entry) {
            if (($entry['command'][0] ?? null) !== 'docker.exe') {
                continue;
            }
            self::assertEmpty(array_intersect($mutatingVerbs, array_map('strtolower', $entry['command'])));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $responses
     * @param list<array{command:list<string>,cwd:string,timeout_ms:int}> $commands
     * @return array<string, mixed>
     */
    private function runGate(array $responses, string $platform, array &$commands): array
    {
        $commands = [];
        $runner = function (array $command, string $cwd, int $timeoutMs) use (&$responses, &$commands): array {
            $commands[] = [
                'command' => $command,
                'cwd' => $cwd,
                'timeout_ms' => $timeoutMs,
            ];
            $key = $this->key($command);
            if (! array_key_exists($key, $responses)) {
                throw new \RuntimeException('Unexpected command in fake runner.');
            }

            return $responses[$key];
        };

        return \h02RunEnvironmentGate($runner, $platform, dirname(__DIR__, 2));
    }

    /** @return array<string, array<string, mixed>> */
    private function greenResponses(): array
    {
        return [
            $this->key(['wsl.exe', '--status']) => $this->response("Default Distribution: Ubuntu\n"),
            $this->key(['wsl.exe', '--version']) => $this->response("WSL version: 2.7.8.0\n"),
            $this->key(['docker.exe', 'version', '--format', '{{json .}}']) => $this->response(
                json_encode([
                    'Client' => ['Version' => '27.0.0'],
                    'Server' => ['Version' => '27.0.0'],
                ], JSON_THROW_ON_ERROR),
            ),
            $this->key(['docker.exe', 'compose', 'version']) => $this->response("Docker Compose version v2.29.0\n"),
            $this->key(['docker.exe', 'info', '--format', '{{json .}}']) => $this->response(
                json_encode(['OSType' => 'linux'], JSON_THROW_ON_ERROR),
            ),
            $this->key([
                'docker.exe',
                'compose',
                '-f',
                'docker-compose.h02-testing.yml',
                'config',
                '--quiet',
            ]) => $this->response(),
            $this->key($this->portProbeCommand()) => $this->response("0\n"),
        ];
    }

    /** @return list<string> */
    private function portProbeCommand(): array
    {
        return [
            'powershell.exe',
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            '$ErrorActionPreference = "Stop"; $listeners = @(Get-NetTCPConnection -LocalPort 8892 -State Listen -ErrorAction Stop); [Console]::Out.WriteLine($listeners.Count)',
        ];
    }

    /** @return array{started:bool,timed_out:bool,exit_code:int,stdout:string,stderr:string} */
    private function response(
        string $stdout = '',
        string $stderr = '',
        int $exitCode = 0,
        bool $started = true,
        bool $timedOut = false,
    ): array {
        return [
            'started' => $started,
            'timed_out' => $timedOut,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /** @param list<string> $command */
    private function key(array $command): string
    {
        return json_encode($command, JSON_THROW_ON_ERROR);
    }
}
