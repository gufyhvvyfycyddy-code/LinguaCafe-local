<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class H02RepresentativeWorkloadContractTest extends TestCase
{
    private const WORKLOAD_PATH = __DIR__.'/../load/h02-representative-workloads.js';

    public function test_representative_workload_matches_the_frozen_contract(): void
    {
        $source = $this->workloadSource();

        $exportedWorkloads = $this->exportedCallableNames($source);
        sort($exportedWorkloads);
        $this->assertSame(
            ['lookup', 'reading', 'senseReview'],
            $exportedWorkloads,
            'H-02 must export exactly the reading, lookup, and Sense Review workload functions.'
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
        $fixtureName = $sharedArray[1] ?? '';
        $this->assertSame(
            1,
            preg_match(
                '/\b'.preg_quote($fixtureName, '/').
                '\s*(?:\[\s*vu\s*\.\s*idInTest(?:\s*[-+]\s*\d+)?\s*\]|\.\s*at\s*\(\s*vu\s*\.\s*idInTest(?:\s*[-+]\s*\d+)?\s*\))/',
                $source
            ),
            'H-02 must map each VU to its fixture through vu.idInTest.'
        );

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
            '/<meta[^>]*name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\']/i',
            $source,
            'The real login flow must extract the CSRF token from the login page meta tag.'
        );
        $this->assertStringContainsString(
            "JSON.stringify({\n        email: fixture.email,\n        password: fixture.password,\n        remember: true,\n    })",
            $source,
            'Login must send the Vue login payload as JSON, including remember.'
        );
        $this->assertStringContainsString(
            "'X-CSRF-TOKEN': csrfToken",
            $source,
            'The extracted login-page token must be sent through the real CSRF header.'
        );
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
        $this->assertSame(
            0,
            preg_match('/\bhandleSummary\b/i', $source),
            'H-02 must not introduce a new handleSummary metric truth.'
        );
        $this->assertSame(
            0,
            preg_match('/\bschema_version\b/', $source),
            'H-02 must not introduce a new schema_version metric truth.'
        );
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
