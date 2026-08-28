<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class H02RepresentativeCapacityGateContractTest extends TestCase
{
    private const RUNNER_PATH = __DIR__.'/../Support/run-h02-representative-runtime.php';
    private const LADDER_PATH = __DIR__.'/../Support/run-h02-docker-ladder.php';

    public function test_host_runner_cannot_claim_representative_100_vu_capacity(): void
    {
        require_once self::RUNNER_PATH;

        $runtime = h02ResolveSmokeRuntime(99);
        self::assertFalse($runtime['capacity_representative']);
        self::assertSame('external_apache_testing_runtime', $runtime['server_profile']);

        try {
            h02ResolveSmokeRuntime(100);
            self::fail('100 VU must be reserved for the Docker representative ladder.');
        } catch (\H02RepresentativeRuntimeFailure $error) {
            self::assertSame('H02_CAPACITY_REQUIRES_DOCKER_LADDER', $error->machineCode);
        }

        $fixture = tempnam(sys_get_temp_dir(), 'h02-fixture-');
        self::assertIsString($fixture);
        try {
            $this->assertRuntimeFailure(static fn () => h02ParseMeasurementArguments([
                'runner.php',
                '--measure',
                '--fixture-file='.$fixture,
                '--capacity-proof=legacy.json',
                '--vus=50',
            ]));
        } finally {
            @unlink($fixture);
        }

        $runner = $this->readFile(self::RUNNER_PATH);
        self::assertStringNotContainsString('capacity_proof', $runner);
        self::assertStringNotContainsString('h02ReadCapacityProof', $runner);
        self::assertSame(
            0,
            preg_match('/\bdocker(?:\.exe)?\s+(?:compose|run|exec|build|inspect|ps|cp|version)\b/i', $runner),
        );
    }

    public function test_docker_ladder_is_the_single_representative_capacity_owner(): void
    {
        $ladder = $this->readFile(self::LADDER_PATH);
        $container = $this->readFile(__DIR__.'/../Support/run-h02-container-runtime.php');
        $h01 = $this->readFile(__DIR__.'/../Support/run-h01-load-observability.php');

        self::assertStringContainsString('foreach ([1, 10, 25, 50, 100] as $vus)', $ladder);
        self::assertStringContainsString("'build', '--quiet', 'web'", $ladder);
        self::assertStringContainsString("'up', '-d', '--no-build'", $ladder);
        self::assertStringContainsString("'php', 'artisan', 'migrate'", $ladder);
        self::assertStringContainsString('h02RunEnvironmentGate(', $ladder);
        self::assertStringContainsString("'--runtime'", $ladder);
        self::assertStringContainsString("'--provision'", $ladder);
        self::assertStringContainsString("'--sample'", $ladder);
        self::assertStringContainsString("'--verify'", $ladder);
        self::assertStringContainsString('H01LoadObservabilityHarness::buildFinalSummary(', $ladder);
        self::assertStringContainsString('($runtime[\'capacity_representative\'] ?? null) !== true', $ladder);
        self::assertStringContainsString('finally {', $ladder);
        self::assertStringContainsString("'down'", $ladder);

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe', 'truncate table'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($ladder));
        }

        self::assertStringContainsString('function h02ContainerRuntimeProof(): array', $container);
        self::assertStringContainsString("'server_profile' => 'docker_apache_testing'", $container);
        self::assertStringContainsString('$apacheProcesses >= 2', $container);
        self::assertStringContainsString("'scenario' => \$runtime['scenario'] ?? 'h01_sentinel_smoke'", $h01);
        self::assertStringContainsString('public const SCHEMA_VERSION = 1;', $h01);
    }

    private function readFile(string $path): string
    {
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function assertRuntimeFailure(callable $operation): void
    {
        try {
            $operation();
        } catch (\H02RepresentativeRuntimeFailure) {
            return;
        }

        self::fail('Expected H02RepresentativeRuntimeFailure.');
    }
}
