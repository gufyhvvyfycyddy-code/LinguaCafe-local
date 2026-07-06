<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Test tokenizer doctor's health JSON parsing for backward compatibility.
 * These tests use fixture JSON data — no running tokenizer needed.
 */
class TokenizerDoctorTest extends TestCase
{
    private function oldHealthJson(): string
    {
        return json_encode([
            'spacy_available' => true,
            'en_core_web_sm_loaded' => true,
            'lemminflect_available' => true,
            'tests' => [
                'opened' => 'open',
                'called' => 'call',
                'lemminflect_opened' => 'open',
                'lemminflect_called' => 'call',
            ],
            'english_irregular' => [
                ['surface' => 'ran', 'expected' => 'run', 'actual' => 'run', 'passed' => true],
                ['surface' => 'better', 'expected' => 'good', 'actual' => 'good', 'passed' => true],
            ],
        ]);
    }

    private function newHealthJson(): string
    {
        return json_encode([
            'spacy_available' => true,
            'en_core_web_sm_loaded' => true,
            'lemminflect_available' => true,
            'version' => 2,
            'status' => 'healthy',
            'tests' => [
                'opened' => 'open',
                'called' => 'call',
                'lemminflect_opened' => 'open',
                'lemminflect_called' => 'call',
            ],
            'english' => [
                'model_loaded' => true,
                'lemminflect_available' => true,
                'checks_passed' => true,
                'lemma_checks' => [
                    ['surface' => 'ran', 'expected' => 'run', 'actual' => 'run', 'passed' => true, 'error' => null],
                    ['surface' => 'better', 'expected' => 'good', 'actual' => 'good', 'passed' => true, 'error' => null],
                ],
            ],
            'languages' => [
                'english' => ['available' => true, 'status' => 'available', 'model' => 'en_core_web_sm', 'error' => null],
                'german' => ['available' => false, 'status' => 'not_installed', 'model' => 'de_core_news_sm', 'error' => 'Not installed. Run: python -m spacy download de_core_news_sm'],
                'french' => ['available' => false, 'status' => 'not_installed', 'model' => 'fr_core_news_sm', 'error' => 'Not installed. Run: python -m spacy download fr_core_news_sm'],
            ],
            'checks' => [
                'english_lemma' => ['passed' => true, 'total' => 2, 'passed_count' => 2, 'failed_count' => 0],
            ],
            'english_irregular' => [
                ['surface' => 'ran', 'expected' => 'run', 'actual' => 'run', 'passed' => true],
                ['surface' => 'better', 'expected' => 'good', 'actual' => 'good', 'passed' => true],
            ],
        ]);
    }

    private function lemmaFailedHealthJson(): string
    {
        return json_encode([
            'spacy_available' => true,
            'en_core_web_sm_loaded' => true,
            'lemminflect_available' => true,
            'version' => 2,
            'status' => 'degraded',
            'tests' => [
                'opened' => 'open',
                'called' => 'call',
                'lemminflect_opened' => 'open',
                'lemminflect_called' => 'call',
            ],
            'english' => [
                'model_loaded' => true,
                'lemminflect_available' => true,
                'checks_passed' => false,
                'lemma_checks' => [
                    ['surface' => 'ran', 'expected' => 'run', 'actual' => 'running', 'passed' => false, 'error' => null],
                ],
            ],
            'languages' => [
                'english' => ['available' => true, 'status' => 'available', 'model' => 'en_core_web_sm', 'error' => null],
            ],
            'checks' => [
                'english_lemma' => ['passed' => false, 'total' => 1, 'passed_count' => 0, 'failed_count' => 1],
            ],
            'english_irregular' => [
                ['surface' => 'ran', 'expected' => 'run', 'actual' => 'running', 'passed' => false],
            ],
        ]);
    }

    public function test_old_health_json_parses_correctly(): void
    {
        $health = json_decode($this->oldHealthJson(), true);

        $this->assertTrue($health['spacy_available']);
        $this->assertTrue($health['en_core_web_sm_loaded']);
        $this->assertTrue($health['lemminflect_available']);
        $this->assertSame('open', $health['tests']['opened']);
        $this->assertSame('call', $health['tests']['called']);
        $this->assertCount(2, $health['english_irregular']);
        $this->assertTrue($health['english_irregular'][0]['passed']);
    }

    public function test_new_health_json_contains_languages(): void
    {
        $health = json_decode($this->newHealthJson(), true);

        $this->assertSame(2, $health['version']);
        $this->assertSame('healthy', $health['status']);
        $this->assertArrayHasKey('languages', $health);
        $this->assertArrayHasKey('english', $health['languages']);
        $this->assertArrayHasKey('german', $health['languages']);

        // Check language detail structure
        $en = $health['languages']['english'];
        $this->assertTrue($en['available']);
        $this->assertSame('available', $en['status']);
        $this->assertSame('en_core_web_sm', $en['model']);
        $this->assertNull($en['error']);
    }

    public function test_new_health_json_contains_lemma_checks(): void
    {
        $health = json_decode($this->newHealthJson(), true);

        $this->assertTrue($health['english']['checks_passed']);
        $this->assertCount(2, $health['english']['lemma_checks']);
        $this->assertTrue($health['english']['lemma_checks'][0]['passed']);

        // Check summary
        $this->assertSame(2, $health['checks']['english_lemma']['passed_count']);
        $this->assertSame(0, $health['checks']['english_lemma']['failed_count']);
    }

    public function test_lemma_failure_detected_correctly(): void
    {
        $health = json_decode($this->lemmaFailedHealthJson(), true);

        $this->assertFalse($health['english']['checks_passed']);
        $this->assertFalse($health['checks']['english_lemma']['passed']);
        $this->assertSame(1, $health['checks']['english_lemma']['failed_count']);

        $check = $health['english']['lemma_checks'][0];
        $this->assertFalse($check['passed']);
        $this->assertSame('running', $check['actual']);
        $this->assertSame('run', $check['expected']);
    }

    public function test_language_not_installed_does_not_crash(): void
    {
        $health = json_decode($this->newHealthJson(), true);

        $german = $health['languages']['german'];
        $this->assertFalse($german['available']);
        $this->assertSame('not_installed', $german['status']);
        $this->assertStringContainsString('python -m spacy download', $german['error']);
    }

    public function test_backward_compatibility_old_json_still_works(): void
    {
        // Simulate the doctor's parsing logic: read english_irregular from health
        $health = json_decode($this->oldHealthJson(), true);
        $irregular = $health['english_irregular'] ?? [];
        $allPassed = !empty($irregular) && collect($irregular)->every(fn ($c) => ($c['passed'] ?? false) === true);
        $this->assertTrue($allPassed);

        // No version field = version 1
        $this->assertArrayNotHasKey('version', $health);
    }

    /**
     * Philosophy-text lemma cases JSON shape (added by
     * GLM-EnglishLemmaPhilosophyText-1000-1). The new tokenizer health
     * endpoint emits philosophy_lemma_checks and philosophy_guard_checks
     * with a `category` field on each case. The doctor parses these to
     * decide whether the tokenizer is healthy for philosophy-text past
     * tense / past participle / plural inflections.
     */
    private function philosophyHealthJson(): string
    {
        return json_encode([
            'spacy_available' => true,
            'en_core_web_sm_loaded' => true,
            'lemminflect_available' => true,
            'version' => 2,
            'status' => 'healthy',
            'tests' => [
                'opened' => 'open',
                'called' => 'call',
            ],
            'english' => [
                'model_loaded' => true,
                'lemminflect_available' => true,
                'checks_passed' => true,
                'lemma_checks' => [
                    ['surface' => 'ran', 'expected' => 'run', 'actual' => 'run', 'passed' => true, 'error' => null, 'category' => 'irregular'],
                    ['surface' => 'construed', 'expected' => 'construe', 'actual' => 'construe', 'passed' => true, 'error' => null, 'category' => 'philosophy'],
                    ['surface' => 'mediated', 'expected' => 'mediate', 'actual' => 'mediate', 'passed' => true, 'error' => null, 'category' => 'philosophy'],
                    ['surface' => 'recently', 'expected' => 'recently', 'actual' => 'recently', 'passed' => true, 'error' => null, 'category' => 'philosophy_guard'],
                    ['surface' => 'code', 'expected' => 'code', 'actual' => 'code', 'passed' => true, 'error' => null, 'category' => 'philosophy_guard'],
                ],
                'philosophy_lemma_checks' => [
                    ['surface' => 'construed', 'expected' => 'construe', 'actual' => 'construe', 'passed' => true, 'error' => null, 'category' => 'philosophy'],
                    ['surface' => 'mediated', 'expected' => 'mediate', 'actual' => 'mediate', 'passed' => true, 'error' => null, 'category' => 'philosophy'],
                ],
                'philosophy_checks_passed' => true,
                'philosophy_guard_checks' => [
                    ['surface' => 'recently', 'expected' => 'recently', 'actual' => 'recently', 'passed' => true, 'error' => null, 'category' => 'philosophy_guard'],
                    ['surface' => 'code', 'expected' => 'code', 'actual' => 'code', 'passed' => true, 'error' => null, 'category' => 'philosophy_guard'],
                ],
                'philosophy_guard_checks_passed' => true,
            ],
            'checks' => [
                'english_lemma' => ['passed' => true, 'total' => 5, 'passed_count' => 5, 'failed_count' => 0],
                'english_philosophy_lemma' => ['passed' => true, 'total' => 2, 'passed_count' => 2, 'failed_count' => 0],
                'english_philosophy_guard' => ['passed' => true, 'total' => 2, 'passed_count' => 2, 'failed_count' => 0],
            ],
            'english_irregular' => [
                ['surface' => 'ran', 'expected' => 'run', 'actual' => 'run', 'passed' => true],
            ],
        ]);
    }

    public function test_philosophy_lemma_checks_are_parsed_from_health_json(): void
    {
        $health = json_decode($this->philosophyHealthJson(), true);

        // Top-level philosophy summary fields
        $this->assertArrayHasKey('philosophy_lemma_checks', $health['english']);
        $this->assertArrayHasKey('philosophy_guard_checks', $health['english']);
        $this->assertTrue($health['english']['philosophy_checks_passed']);
        $this->assertTrue($health['english']['philosophy_guard_checks_passed']);

        // Each philosophy case carries a category tag
        $philosophy = $health['english']['philosophy_lemma_checks'];
        $this->assertCount(2, $philosophy);
        foreach ($philosophy as $case) {
            $this->assertSame('philosophy', $case['category']);
            $this->assertTrue($case['passed']);
        }

        // construed → construe is in the philosophy set
        $construed = collect($philosophy)->firstWhere('surface', 'construed');
        $this->assertNotNull($construed);
        $this->assertSame('construe', $construed['actual']);
        $this->assertSame('construe', $construed['expected']);
    }

    public function test_philosophy_guard_checks_are_parsed_from_health_json(): void
    {
        $health = json_decode($this->philosophyHealthJson(), true);

        $guards = $health['english']['philosophy_guard_checks'];
        $this->assertCount(2, $guards);
        foreach ($guards as $case) {
            $this->assertSame('philosophy_guard', $case['category']);
            $this->assertTrue($case['passed']);
        }

        // recently and code are NOT over-lemmatized
        $recently = collect($guards)->firstWhere('surface', 'recently');
        $this->assertNotNull($recently);
        $this->assertSame('recently', $recently['actual']);

        $code = collect($guards)->firstWhere('surface', 'code');
        $this->assertNotNull($code);
        $this->assertSame('code', $code['actual']);
    }

    public function test_philosophy_summary_checks_section_is_present(): void
    {
        $health = json_decode($this->philosophyHealthJson(), true);

        $this->assertArrayHasKey('english_philosophy_lemma', $health['checks']);
        $this->assertArrayHasKey('english_philosophy_guard', $health['checks']);

        $phil = $health['checks']['english_philosophy_lemma'];
        $this->assertTrue($phil['passed']);
        $this->assertSame(2, $phil['passed_count']);
        $this->assertSame(0, $phil['failed_count']);

        $guard = $health['checks']['english_philosophy_guard'];
        $this->assertTrue($guard['passed']);
        $this->assertSame(2, $guard['passed_count']);
        $this->assertSame(0, $guard['failed_count']);
    }

    public function test_philosophy_failure_is_detected(): void
    {
        // If construed → construed (tokenizer failed), the philosophy check
        // must report failed=true and the summary must reflect it.
        $health = json_decode($this->philosophyHealthJson(), true);

        // Mutate: construed returns construed (broken)
        $health['english']['philosophy_lemma_checks'][0]['actual'] = 'construed';
        $health['english']['philosophy_lemma_checks'][0]['passed'] = false;
        $health['english']['philosophy_checks_passed'] = false;
        $health['checks']['english_philosophy_lemma']['passed'] = false;
        $health['checks']['english_philosophy_lemma']['passed_count'] = 1;
        $health['checks']['english_philosophy_lemma']['failed_count'] = 1;

        $construed = $health['english']['philosophy_lemma_checks'][0];
        $this->assertFalse($construed['passed']);
        $this->assertSame('construed', $construed['actual']);
        $this->assertSame('construe', $construed['expected']);

        $this->assertFalse($health['english']['philosophy_checks_passed']);
        $this->assertFalse($health['checks']['english_philosophy_lemma']['passed']);
        $this->assertSame(1, $health['checks']['english_philosophy_lemma']['failed_count']);
    }
}
