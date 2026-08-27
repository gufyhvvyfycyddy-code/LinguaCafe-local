<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class H02RepresentativeCapacityGateContractTest extends TestCase
{
    private const RUNNER_PATH = __DIR__.'/../Support/run-h02-representative-runtime.php';

    public function test_capacity_proof_seams_exist_before_capacity_execution(): void
    {
        $this->loadRunner();
        $readerExists = function_exists('h02ReadCapacityProof');
        $resolverExists = function_exists('h02ResolveCapacityRuntime');
        self::assertTrue($readerExists, 'H-02 must expose h02ReadCapacityProof before capacity execution.');
        self::assertTrue($resolverExists, 'H-02 must expose h02ResolveCapacityRuntime before capacity execution.');
        if (! $readerExists || ! $resolverExists) {
            return;
        }

        $projectRoot = dirname(__DIR__, 2);
        $baseUrl = 'http://127.0.0.1:8892';
        $head = trim((string) shell_exec('git -C '.escapeshellarg($projectRoot).' rev-parse HEAD'));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/i', $head);
        $validProof = [
            'schema_version' => 1,
            'git_head' => $head,
            'base_url' => $baseUrl,
            'server_profile' => 'docker_apache_testing',
            'capacity_representative' => true,
        ];
        $paths = [];

        try {
            $validPath = $this->writeProof($validProof);
            $paths[] = $validPath;
            $accepted = h02ReadCapacityProof($validPath, $projectRoot, $baseUrl);
            self::assertSame(1, $accepted['schema_version'] ?? null);
            self::assertSame('docker_apache_testing', $accepted['server_profile'] ?? null);
            self::assertTrue($accepted['capacity_representative'] ?? false);
            self::assertFileExists($validPath, 'Proof validation must not remove the external proof.');

            foreach ([
                ['schema_version' => 2],
                ['git_head' => str_repeat('0', 40)],
                ['base_url' => 'http://127.0.0.1:8893'],
                ['server_profile' => 'php_server_smoke'],
                ['capacity_representative' => false],
            ] as $changes) {
                $path = $this->writeProof(array_merge($validProof, $changes));
                $paths[] = $path;
                $this->assertCapacityFailure(static fn () => h02ReadCapacityProof($path, $projectRoot, $baseUrl));
            }

            $malformedPath = $this->writeFile('{malformed');
            $paths[] = $malformedPath;
            $this->assertCapacityFailure(static fn () => h02ReadCapacityProof($malformedPath, $projectRoot, $baseUrl));

            $missingPath = $this->writeFile('{}');
            unlink($missingPath);
            $paths[] = $missingPath;
            $this->assertCapacityFailure(static fn () => h02ReadCapacityProof($missingPath, $projectRoot, $baseUrl));

            $smokeRuntime = h02ResolveCapacityRuntime(null, 1, $projectRoot, $baseUrl);
            self::assertFalse($smokeRuntime['capacity_representative'] ?? true);
            self::assertNotSame('docker_apache_testing', $smokeRuntime['server_profile'] ?? null);
            $this->assertCapacityFailure(static fn () => h02ResolveCapacityRuntime(null, 100, $projectRoot, $baseUrl));

            $capacityRuntime = h02ResolveCapacityRuntime($validPath, 100, $projectRoot, $baseUrl);
            self::assertTrue($capacityRuntime['capacity_representative'] ?? false);
            self::assertSame('docker_apache_testing', $capacityRuntime['server_profile'] ?? null);
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    public function test_capacity_proof_option_is_measurement_only(): void
    {
        $this->loadRunner();
        $readerExists = function_exists('h02ReadCapacityProof');
        $resolverExists = function_exists('h02ResolveCapacityRuntime');
        self::assertTrue($readerExists, 'Capacity CLI coverage waits for h02ReadCapacityProof.');
        self::assertTrue($resolverExists, 'Capacity CLI coverage waits for h02ResolveCapacityRuntime.');
        if (! $readerExists || ! $resolverExists) {
            return;
        }

        $proofPath = $this->writeFile('{}');
        $fixturePath = $this->writeFile('[]');
        try {
            $options = h02ParseMeasurementArguments([
                'runner.php',
                '--measure',
                '--fixture-file='.$fixturePath,
                '--capacity-proof='.$proofPath,
                '--vus=100',
            ]);
            self::assertSame($proofPath, $options['capacity_proof'] ?? null);
            $this->assertCapacityFailure(static fn () => h02ParseRuntimeArguments([
                'runner.php',
                '--capacity-proof='.$proofPath,
            ]));
        } finally {
            @unlink($proofPath);
            @unlink($fixturePath);
        }
    }

    public function test_h02_keeps_h01_summary_contract_before_docker_reboot(): void
    {
        $this->loadRunner();
        $runner = $this->readFile(self::RUNNER_PATH);
        $h01 = $this->readFile(__DIR__.'/../Support/run-h01-load-observability.php');
        self::assertSame(1, substr_count($runner, 'H01LoadObservabilityHarness::buildFinalSummary('));
        self::assertStringContainsString('public const SCHEMA_VERSION = 1;', $h01);
        self::assertStringContainsString("'schema_version' => self::SCHEMA_VERSION", $h01);
        self::assertSame(
            0,
            preg_match('/\bdocker(?:-compose)?\s+(?:compose|run|exec|build|inspect|ps|cp|version)\b/i', $runner),
        );
    }

    private function loadRunner(): void
    {
        require_once self::RUNNER_PATH;
    }

    private function readFile(string $path): string
    {
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    /** @param array<string, mixed> $proof */
    private function writeProof(array $proof): string
    {
        $json = json_encode($proof, JSON_THROW_ON_ERROR);
        self::assertIsString($json);

        return $this->writeFile($json);
    }

    private function writeFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'h02-capacity-');
        self::assertIsString($path);
        self::assertSame(strlen($contents), file_put_contents($path, $contents));

        return $path;
    }

    private function assertCapacityFailure(callable $operation): void
    {
        try {
            $operation();
        } catch (\H02RepresentativeRuntimeFailure) {
            return;
        }

        self::fail('Expected H02RepresentativeRuntimeFailure.');
    }
}
