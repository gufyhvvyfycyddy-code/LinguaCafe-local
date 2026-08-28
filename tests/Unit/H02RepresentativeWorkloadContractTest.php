<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class H02RepresentativeWorkloadContractTest extends TestCase
{
    private const WORKLOAD_PATH = __DIR__.'/../load/h02-representative-workloads.js';

    public function test_scenario_allocations_split_total_users(): void
    {
        $source = $this->workloadSource();
        $functionSource = $this->splitterFunctionSource($source);
        $inputs = range(1, 100);
        $evaluation = $this->runSplitter($functionSource, $inputs);

        $splits = $evaluation['splits'] ?? null;
        self::assertIsArray($splits);
        self::assertCount(count($inputs), $splits);

        $expectedSplits = [
            1 => [1, 0, 0],
            2 => [1, 1, 0],
            3 => [1, 1, 1],
            4 => [2, 1, 1],
            5 => [2, 2, 1],
            100 => [34, 33, 33],
        ];

        foreach ($inputs as $index => $input) {
            $split = $splits[$index] ?? null;
            self::assertIsArray($split);
            self::assertCount(3, $split, "H-02 must return three allocations for {$input} users.");
            self::assertSame($input, array_sum($split), "H-02 allocations must sum to {$input} users.");
            self::assertLessThanOrEqual(1, max($split) - min($split), "H-02 allocations must stay even for {$input} users.");

            if (isset($expectedSplits[$input])) {
                self::assertSame($expectedSplits[$input], $split, "Unexpected H-02 allocation for {$input} users.");
            }
        }

        $invalidError = $evaluation['invalid_error'] ?? null;
        self::assertIsString($invalidError);
        self::assertStringContainsString('H02_INVALID_VUS', $invalidError);
    }

    public function test_representative_workload_matches_the_frozen_contract(): void
    {
        $source = $this->workloadSource();

        $exportedWorkloads = $this->exportedCallableNames($source);
        sort($exportedWorkloads);
        $this->assertSame(
            ['handleSummary', 'lookup', 'reading', 'senseReview', 'splitScenarioVus'],
            $exportedWorkloads,
            'H-02 must export the three workloads, pure VU splitter, and the single H-01-compatible raw summary handoff.'
        );

        $scenarioExecs = [];
        $this->assertSame(
            3,
            preg_match_all(
                '/\bexec\s*:\s*[\'\"]([A-Za-z_$][A-Za-z0-9_$]*)[\'\"]/',
                $source,
                $scenarioExecs
            ),
            'H-02 must define exactly three named scenario execs.'
        );
        $scenarioExecNames = $scenarioExecs[1];
        sort($scenarioExecNames);
        $this->assertSame(
            ['lookup', 'reading', 'senseReview'],
            $scenarioExecNames,
            'H-02 scenarios must point only to the three representative workload execs.'
        );

        $this->assertSame(
            3,
            preg_match_all('/\bexecutor\s*:\s*[\'\"]per-vu-iterations[\'\"]/', $source),
            'Each representative scenario must use the per-vu-iterations executor.'
        );
        $this->assertStringContainsString(
            'const [readingVus, lookupVus, senseReviewVus] = splitScenarioVus(vus);',
            $source,
            'Scenario VUs must be derived from the total H02_VUS splitter output.'
        );
        foreach ([
            'reading' => 'readingVus',
            'lookup' => 'lookupVus',
            'senseReview' => 'senseReviewVus',
        ] as $scenario => $scenarioVus) {
            $this->assertSame(
                1,
                substr_count($source, "if ({$scenarioVus} > 0) {"),
                "A zero {$scenarioVus} allocation must omit the scenario because k6 rejects zero-VU scenarios."
            );
            $this->assertSame(
                1,
                substr_count($source, "scenarios.{$scenario} = {"),
                "The {$scenario} scenario must be added through the zero-safe scenario map."
            );
            $this->assertSame(
                1,
                substr_count($source, "        vus: {$scenarioVus},"),
                "Each scenario must use its deterministic {$scenarioVus} allocation."
            );
        }
        $this->assertStringContainsString(
            "export const options = {\n    scenarios,",
            $source,
            'k6 options must expose the zero-safe scenario map alongside fail-closed measurement settings.'
        );

        $sharedArray = [];
        $this->assertSame(
            1,
            preg_match(
                '/\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*new\s+SharedArray\s*\(/',
                $source,
                $sharedArray
            ),
            'H-02 fixtures must be backed by a SharedArray.'
        );
        $this->assertStringContainsString(
            'const fixture = fixtures[vu.idInTest - 1];',
            $source,
            'H-02 must map each VU to its fixture through the global vu.idInTest index.'
        );
        $this->assertStringContainsString("const fixturesJson = __ENV.H02_FIXTURES_JSON || '';", $source);
        $this->assertStringContainsString("const fixturesPath = __ENV.H02_FIXTURES_PATH || '';", $source);
        $this->assertStringContainsString("if ((fixturesJson === '') === (fixturesPath === ''))", $source);
        $this->assertStringContainsString("open(fixturesPath)", $source);
        $this->assertStringContainsString("const summaryPath = __ENV.H02_K6_SUMMARY_PATH", $source);

        foreach (['email', 'password', 'chapter_id', 'lemma', 'language', 'review_card_id'] as $key) {
            $escapedKey = preg_quote($key, '/');
            $this->assertSame(
                1,
                preg_match(
                    '/(?:\.\s*'.$escapedKey.'\b|\[\s*[\'\"]'.$escapedKey.'[\'\"]\s*\]|\b'.$escapedKey.'\s*[,}:])/',
                    $source
                ),
                "H-02 fixture mapping must expose the {$key} key."
            );
        }

        $this->assertStringContainsString(
            '/chapters/get/reader',
            $source,
            'Reading must use the exact /chapters/get/reader route.'
        );
        $this->assertSame(
            1,
            preg_match('/\bchapterId\b/', $source),
            'Reading must send the chapterId parameter.'
        );

        $this->assertStringContainsString(
            '/senses/known-sense-lookup',
            $source,
            'Lookup must use the exact /senses/known-sense-lookup route.'
        );
        foreach (['lemma', 'language'] as $parameter) {
            $this->assertSame(
                1,
                preg_match('/\b'.preg_quote($parameter, '/').'\b/', $source),
                "Lookup must send the {$parameter} parameter."
            );
        }

        $this->assertSame(
            1,
            preg_match('~\bhttp\.get\s*\([^;]*?/login~s', $source),
            'Authentication must begin with a real GET /login flow.'
        );
        $this->assertSame(
            1,
            preg_match('~\bhttp\.post\s*\([^;]*?/login~s', $source),
            'Authentication must submit credentials through the real POST /login flow.'
        );
        $this->assertStringContainsString(
            "loginPage.cookies['XSRF-TOKEN']",
            $source,
            'The real login flow must use Laravel\'s XSRF-TOKEN cookie like the Axios browser path.'
        );
        $this->assertStringContainsString(
            "JSON.stringify({\n        email: fixture.email,\n        password: fixture.password,\n        remember: true,\n    })",
            $source,
            'Login must send the Vue login payload as JSON, including remember.'
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, "'X-XSRF-TOKEN': csrfToken"),
            'Cookie-derived CSRF tokens must use Laravel\'s X-XSRF-TOKEN header for login and authenticated mutations.'
        );
        $this->assertStringNotContainsString("'X-CSRF-TOKEN'", $source);
        $this->assertStringContainsString(
            "loginResponse.cookies['XSRF-TOKEN']",
            $source,
            'The post-login CSRF token must follow the session-regeneration cookie refresh.'
        );
        $this->assertStringContainsString(
            'decodeURIComponent(refreshedXsrfCookies[0].value)',
            $source,
            'The refreshed XSRF-TOKEN cookie must be decoded before reuse as a CSRF header.'
        );
        $this->assertSame(
            0,
            preg_match('/\b_token\b/', $source),
            'The Vue-driven login flow must not depend on a native _token input or form field.'
        );

        $this->assertSame(
            1,
            preg_match_all('~/reviews/senses/~', $source),
            'Sense Review must contain exactly one formal sense-rating route.'
        );
        $this->assertSame(
            1,
            preg_match(
                '~\bhttp\.post\s*\([^;]*?/reviews/senses/[^;]*?/rate~s',
                $source
            ),
            'The ordinary Sense Review workload must make one formal rate call per VU iteration/card.'
        );
        $this->assertStringContainsString(
            "JSON.stringify({ rating: 'good' })",
            $source,
            'Sense Review must send one controller-accepted string rating.'
        );

        $this->assertSame(
            0,
            preg_match('~__testing\s*/\s*(?:auth|login|authenticate)\b~i', $source),
            'H-02 must not use a __testing authentication endpoint.'
        );
        $this->assertStringContainsString(
            "http_req_failed: ['rate==0']",
            $source,
            'H-02 must fail closed when any HTTP request fails.'
        );
        $this->assertStringContainsString(
            "checks: ['rate==1']",
            $source,
            'H-02 must fail closed when any workload check fails.'
        );
        $this->assertStringContainsString("'p(99)'", $source);
        $this->assertSame(
            1,
            substr_count($source, 'export function handleSummary(data)'),
            'H-02 must reuse the H-01 raw k6 data handoff exactly once.'
        );
        $this->assertStringContainsString(
            '[summaryPath]: JSON.stringify(data, null, 2)',
            $source,
            'H-02 handleSummary must only persist raw k6 data for the H-01 summary builder.'
        );
        $this->assertSame(
            0,
            preg_match('/\bschema_version\b/', $source),
            'H-02 must not introduce a new schema_version metric truth.'
        );
    }

    private function splitterFunctionSource(string $source): string
    {
        $functionStart = strpos($source, 'export function splitScenarioVus(totalVus)');
        self::assertNotFalse($functionStart, 'H-02 must expose the pure splitter function.');
        if ($functionStart === false) {
            return '';
        }

        $functionEnd = strpos($source, "\n}\n\nconst baseUrl", $functionStart);
        self::assertNotFalse($functionEnd, 'H-02 splitter must end before the workload configuration.');
        if ($functionEnd === false) {
            return '';
        }

        $functionSource = substr($source, $functionStart, $functionEnd - $functionStart + 2);

        return str_replace('export function', 'function', $functionSource);
    }

    /** @param list<int> $inputs
     *  @return array{splits: list<list<int>>, invalid_error: string}
     */
    private function runSplitter(string $functionSource, array $inputs): array
    {
        $encodedInputs = json_encode($inputs, JSON_THROW_ON_ERROR);
        $script = $functionSource."\n"
            ."const inputs = {$encodedInputs};\n"
            ."const splits = inputs.map((value) => splitScenarioVus(value));\n"
            ."let invalidError = '';\n"
            ."try { splitScenarioVus(0); } catch (error) { invalidError = String(error.message || error); }\n"
            ."process.stdout.write(JSON.stringify({ splits, invalid_error: invalidError }));\n";

        $scriptPath = tempnam(sys_get_temp_dir(), 'h02-splitter-');
        self::assertIsString($scriptPath);
        self::assertSame(strlen($script), file_put_contents($scriptPath, $script));

        try {
            $process = proc_open(
                ['node', $scriptPath],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
                null,
                ['bypass_shell' => true],
            );
            self::assertIsResource($process, 'Could not start Node to execute the H-02 splitter.');

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertIsString($stdout);
            self::assertIsString($stderr);

            $exitCode = proc_close($process);
            self::assertSame(0, $exitCode, "Node splitter execution failed: {$stderr}");

            try {
                $decoded = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                self::fail("Node splitter output was not valid JSON: {$stdout} {$error->getMessage()}");
            }
            self::assertIsArray($decoded);

            return $decoded;
        } finally {
            @unlink($scriptPath);
        }
    }

    private function workloadSource(): string
    {
        $this->assertFileExists(
            self::WORKLOAD_PATH,
            'H-02 RED contract: tests/load/h02-representative-workloads.js is not present yet.'
        );

        $source = file_get_contents(self::WORKLOAD_PATH);
        $this->assertIsString($source, 'H-02 representative workload must be readable.');

        return $source;
    }

    /** @return list<string> */
    private function exportedCallableNames(string $source): array
    {
        $names = [];
        foreach ([
            '/\bexport\s+(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/',
            '/\bexport\s+(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(?:async\s+)?(?:function\b|(?:\([^)]*\)|[A-Za-z_$][A-Za-z0-9_$]*)\s*=>)/',
        ] as $pattern) {
            preg_match_all($pattern, $source, $matches);
            $names = array_merge($names, $matches[1]);
        }

        return array_values(array_unique($names));
    }
}
