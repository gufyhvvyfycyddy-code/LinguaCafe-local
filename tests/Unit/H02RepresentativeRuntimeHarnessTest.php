<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class H02RepresentativeRuntimeHarnessTest extends TestCase
{
    private const RUNNER_PATH = __DIR__.'/../Support/run-h02-representative-runtime.php';

    private const WORKLOAD_PATH = __DIR__.'/../load/h02-representative-workloads.js';

    private const PAB_RUNNER_PATH = __DIR__.'/../Support/run-pab-r3-browser-acceptance.php';

    private const H01_RUNNER_PATH = __DIR__.'/../Support/run-h01-load-observability.php';

    public function test_h02_outer_runner_owns_the_prepared_fixture_and_runtime_seam(): void
    {
        $this->assertFileExists(
            self::RUNNER_PATH,
            'H02 RED contract owner missing: expected tests/Support/run-h02-representative-runtime.php.',
        );

        $runner = file_get_contents(self::RUNNER_PATH);
        $workload = file_get_contents(self::WORKLOAD_PATH);
        $pabRunner = file_get_contents(self::PAB_RUNNER_PATH);
        $h01Runner = file_get_contents(self::H01_RUNNER_PATH);
        $this->assertIsString($runner);
        $this->assertIsString($workload);
        $this->assertIsString($pabRunner);
        $this->assertIsString($h01Runner);

        $h02Runners = glob(dirname(self::RUNNER_PATH).DIRECTORY_SEPARATOR.'run-h02-*.php') ?: [];
        $this->assertCount(1, $h02Runners, 'There must be exactly one H-02 outer runner.');
        $this->assertSame(realpath(self::RUNNER_PATH), realpath($h02Runners[0]));

        $this->assertStringContainsString(
            "require_once __DIR__.'/run-pab-r3-browser-acceptance.php';",
            $runner,
        );
        $this->assertStringContainsString('runPabR3BrowserAcceptanceCli(', $runner);
        $this->assertStringContainsString('TestingDatabaseLease::acquireOrInheritForProject', $pabRunner);
        $this->assertStringContainsString(
            'H01LoadObservabilityHarness::assertRuntimeBoundary(',
            $runner,
        );
        $this->assertStringContainsString("getenv('APP_ENV')", $runner);
        $this->assertStringContainsString("getenv('LINGUACAFE_TEST_SENTINEL')", $runner);

        $this->assertStringContainsString(
            "require_once __DIR__.'/run-h01-load-observability.php';",
            $runner,
        );
        $this->assertStringContainsString('H01LoadObservabilityHarness::buildFinalSummary(', $runner);
        $this->assertStringContainsString(
            '/tests/load/h02-representative-workloads.js',
            $runner,
        );
        $this->assertFileExists(self::WORKLOAD_PATH);

        $this->assertStringContainsString("new SharedArray('h02-representative-fixtures'", $workload);
        $this->assertStringContainsString('__ENV.H02_FIXTURES_JSON', $workload);
        $this->assertStringContainsString('__ENV.H02_VUS', $workload);
        $this->assertStringContainsString('return JSON.parse(__ENV.H02_FIXTURES_JSON);', $workload);
        $this->assertSame(0, preg_match('/function\s+(?:setup|teardown)\s*\(/', $workload));

        $this->assertMatchesRegularExpression(
            '/function\s+h02PrepareFixtureRows\s*\(\s*int\s+\$vus\s*\)\s*:\s*array/',
            $runner,
        );
        $this->assertMatchesRegularExpression('/function\s+h02CleanupFixtures\s*\(/', $runner);
        $prepareStart = strpos($runner, 'function h02PrepareFixtureRows');
        $cleanupDeclaration = strpos($runner, 'function h02CleanupFixtures');
        $this->assertNotFalse($prepareStart);
        $this->assertNotFalse($cleanupDeclaration);
        $this->assertGreaterThan($prepareStart, $cleanupDeclaration);
        $fixtureBuilder = substr($runner, $prepareStart, $cleanupDeclaration - $prepareStart);
        foreach (['email', 'password', 'chapter_id', 'lemma', 'language', 'review_card_id'] as $key) {
            $this->assertMatchesRegularExpression(
                '/[\'\"]'.$key.'[\'\"]\s*=>/',
                $fixtureBuilder,
                "Prepared fixture rows must contain the {$key} key.",
            );
        }
        $this->assertMatchesRegularExpression('/sprintf\s*\([^;]*\$index/s', $fixtureBuilder);
        $this->assertStringContainsString('$index', $fixtureBuilder);
        $this->assertMatchesRegularExpression('/(?:range\s*\(\s*1\s*,\s*\$vus|\$index\s*<=\s*\$vus)/', $fixtureBuilder);
        foreach (['random_bytes(', 'uniqid(', 'mt_rand(', 'microtime('] as $nonDeterministicSource) {
            $this->assertStringNotContainsString($nonDeterministicSource, $fixtureBuilder, $nonDeterministicSource);
        }
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $runner);
        $this->assertMatchesRegularExpression(
            '/h02PrepareFixtureRows\s*\(\s*(?:\$options\s*\[\s*[\'\"]vus[\'\"]\s*\]|\$vus)\s*\)/',
            $runner,
        );

        $this->assertStringContainsString('H02_FIXTURE_ROWS_INSUFFICIENT', $runner);
        $this->assertMatchesRegularExpression(
            '/(?:count\s*\(\s*\$[A-Za-z_]\w*\s*\)\s*<\s*(?:\$vus|\$[A-Za-z_]\w*\s*(?:\[\s*[\'\"]vus[\'\"]\s*\]|->vus))|\$[A-Za-z_]\w*\s*(?:>|>=)\s*count\s*\(\s*\$[A-Za-z_]\w*\s*\))/',
            $runner,
        );

        $this->assertStringContainsString('fixtures[vu.idInTest - 1]', $workload);
        $this->assertSame(3, substr_count($workload, "executor: 'per-vu-iterations'"));
        $this->assertSame(3, substr_count($workload, 'iterations: 1'));
        $this->assertStringContainsString('${fixture.review_card_id}', $workload);

        $this->assertStringContainsString('try {', $runner);
        $this->assertStringContainsString('finally', $runner);
        $this->assertMatchesRegularExpression('/finally\s*\{.*h02CleanupFixtures\s*\(/s', $runner);
        $this->assertGreaterThanOrEqual(2, substr_count($runner, 'h02CleanupFixtures('));
        $cleanupCall = strrpos($runner, 'h02CleanupFixtures(');
        $finally = strrpos($runner, 'finally');
        $outerRunnerCall = strpos($runner, 'runPabR3BrowserAcceptanceCli(');
        $this->assertNotFalse($cleanupCall);
        $this->assertNotFalse($finally);
        $this->assertNotFalse($outerRunnerCall);
        $this->assertGreaterThan($finally, $cleanupCall);
        $this->assertLessThan($outerRunnerCall, $cleanupCall);
        $this->assertStringContainsString('EXIT_CANCELLED', $pabRunner);
        $sentinelCleanup = strpos($pabRunner, "'event' => 'sentinel_cleanup'");
        $leaseRelease = strpos($pabRunner, '$lease->release();');
        $this->assertNotFalse($sentinelCleanup);
        $this->assertNotFalse($leaseRelease);
        $this->assertLessThan($leaseRelease, $sentinelCleanup);

        foreach ([
            '.env',
            'putenv(',
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'db:wipe',
            'DROP TABLE',
            'drop table',
            'TRUNCATE TABLE',
            'truncate table',
            'H02_FIXTURES_JSON=',
            'H02_VUS=',
            'shell_exec(',
            'passthru(',
            'system(',
            'bash -c',
            'cmd /c',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $runner, $forbidden);
        }

        $this->assertStringContainsString("'H02_FIXTURES_JSON'", $runner);
        $this->assertStringContainsString("'H02_VUS'", $runner);
        $this->assertStringContainsString('$k6Environment', $runner);
        $this->assertStringContainsString('h01StartProcess(', $runner);
        $this->assertMatchesRegularExpression('/h01StartProcess\s*\(.*?\$k6Environment.*?\)/s', $runner);
        $this->assertStringContainsString("['bypass_shell' => true]", $h01Runner);
        $this->assertStringContainsString('proc_open(', $h01Runner);
    }
}
