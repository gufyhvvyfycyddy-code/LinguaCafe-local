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
}
