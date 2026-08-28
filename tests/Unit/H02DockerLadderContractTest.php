<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class H02DockerLadderContractTest extends TestCase
{
    public function test_docker_ladder_is_fail_closed_and_owns_only_testing_runtime_orchestration(): void
    {
        $ladder = $this->source('tests/Support/run-h02-docker-ladder.php');
        $container = $this->source('tests/Support/run-h02-container-runtime.php');
        $reviewCardService = $this->source('app/Services/ReviewCardService.php');
        $reviewSettingsResolver = $this->source('app/Services/Settings/Presets/ReviewSettingsResolver.php');

        self::assertStringContainsString("require_once __DIR__.'/run-h02-environment-gate.php';", $ladder);
        self::assertStringContainsString("require_once __DIR__.'/run-h01-load-observability.php';", $ladder);
        self::assertStringContainsString('foreach ([1, 10, 25, 50, 100] as $vus)', $ladder);
        self::assertStringContainsString('h02LadderRunThreshold($vus, $k6Executable, $k6Version, $gitHead, $tempDirectory)', $ladder);
        self::assertStringContainsString("'git_head' => \$gitHead", $ladder);

        // Generic Docker/migration commands must use file-backed stdout/stderr,
        // not the environment-gate pipe probe that previously deadlocked on
        // larger Windows child output.
        $runner = $this->sectionBetween(
            $ladder,
            'function h02LadderRunCommand(',
            'function h02LadderRunJsonCommand(',
        );
        self::assertStringContainsString('h01StartProcess(', $runner);
        self::assertStringContainsString('h02LadderWaitChild(', $runner);
        self::assertStringContainsString("@unlink(\$child['stdout'])", $runner);
        self::assertStringContainsString("@unlink(\$child['stderr'])", $runner);
        self::assertStringNotContainsString('h02EnvironmentGateRunProcess(', $runner);

        // Every threshold gets a fresh disposable Compose runtime, ordinary
        // migration, real localhost web flow, same-DB fixture/metrics/FSRS
        // verification, and then the outer finally owns residue cleanup.
        self::assertStringContainsString("'down'", $ladder);
        self::assertStringContainsString("'up', '-d', '--no-build'", $ladder);
        self::assertStringContainsString("'php', 'artisan', 'migrate'", $ladder);
        self::assertStringContainsString("'--runtime'", $ladder);
        self::assertStringContainsString("'--provision', '--vus='.\$vus", $ladder);
        self::assertStringContainsString("'--sample', '--sample-count=60', '--sample-ms=100'", $ladder);
        self::assertStringContainsString("'--verify', '--vus='.\$vus, '--expected-rated='.\$expectedRated", $ladder);
        self::assertStringContainsString("H02_K6_SUMMARY_PATH", $ladder);
        self::assertStringContainsString("H02_FIXTURES_PATH", $ladder);
        self::assertStringContainsString('H01LoadObservabilityHarness::buildFinalSummary(', $ladder);
        self::assertStringContainsString("(\$measurement['http']['failed_rate'] ?? null) !== 0", $ladder);
        self::assertStringContainsString("(\$measurement['http']['checks_rate'] ?? null) !== 1", $ladder);
        self::assertStringContainsString("H02_LADDER_APACHE_CONCURRENCY_UNPROVEN", $ladder);
        self::assertStringContainsString('function h02LadderEmitWebDiagnostic(', $ladder);
        self::assertGreaterThanOrEqual(2, substr_count($ladder, 'h02LadderEmitWebDiagnostic($compose, $vus)'));
        self::assertStringContainsString('function h02LadderEnsureTempDirectory(', $ladder);
        self::assertGreaterThanOrEqual(2, substr_count($ladder, 'h02LadderEnsureTempDirectory($tempDirectory)'));
        self::assertStringContainsString('function h02LadderAcquireLock()', $ladder);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $ladder);
        self::assertStringContainsString('H02_LADDER_ALREADY_RUNNING', $ladder);
        self::assertStringContainsString('if (is_resource($lock)) {', $ladder);
        self::assertStringContainsString('SHOW ENGINE INNODB STATUS', $ladder);
        self::assertStringContainsString("H02_LADDER_FORMAL_RATING_INVALID", $ladder);
        self::assertStringContainsString("H02_LADDER_LARAVEL_ERRORS", $ladder);
        self::assertStringContainsString('return intdiv($vus, 3);', $ladder);

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe', '--force', 'docker system prune'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($ladder));
        }

        // The production-like container helper must bootstrap Laravel directly;
        // it cannot inherit the host PAB/Git testing lease just to collect DB
        // metrics or fixtures.
        self::assertStringContainsString("require_once __DIR__.'/H01ObservabilitySampleSupport.php';", $container);
        self::assertStringContainsString("require_once __DIR__.'/H02RepresentativeFixtureSupport.php';", $container);
        self::assertStringNotContainsString('run-h01-load-observability.php', $container);
        self::assertStringNotContainsString('run-pab-r3-browser-acceptance.php', $container);
        self::assertStringNotContainsString('TestingDatabaseLease', $container);
        self::assertStringContainsString('H01ObservabilitySampleSupport::collect(', $container);
        self::assertStringContainsString("'server_profile' => 'docker_apache_testing'", $container);
        self::assertStringContainsString("'capacity_representative' => PHP_OS_FAMILY === 'Linux' && \$apacheProcesses >= 2", $container);

        $formalRating = $this->sectionBetween(
            $reviewCardService,
            'public function recordReviewWithLog(',
            'private function formalQuestionExampleKey(',
        );
        self::assertStringContainsString('}, 3);', $formalRating);

        $presetInitialization = $this->sectionBetween(
            $reviewSettingsResolver,
            'private function resolvePreset(',
            'private function validatedBoundPreset(',
        );
        self::assertStringNotContainsString('lockForUpdate()', $presetInitialization);
        self::assertStringNotContainsString('DB::transaction(', $presetInitialization);
        self::assertStringContainsString('$this->bindings->bind(', $presetInitialization);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function sectionBetween(string $source, string $startMarker, string $endMarker): string
    {
        $start = strpos($source, $startMarker);
        $end = $start === false ? false : strpos($source, $endMarker, $start + strlen($startMarker));
        if ($start === false || $end === false) {
            self::fail("H02 ladder contract section is missing: {$startMarker}");
        }

        return substr($source, $start, $end - $start);
    }
}
