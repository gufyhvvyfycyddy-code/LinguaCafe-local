<?php

namespace Tests\Unit;

use App\Console\Commands\StudyBaseDoctor;
use Tests\TestCase;

/**
 * Unit tests for StudyBaseDoctor::computeLemma().
 *
 * This test class was added by GLM-EnglishLemmaPhilosophyText-1000-1 to lock in
 * the P1 bug fix that prevented the doctor from suggesting bogus lemmas for
 * short words and philosophy-text sanity guards.
 *
 * The unit-test environment has no ECDICT (dict_en_ecdict_full is empty), so
 * computeLemma's ECDICT-gated branches are conservatively inactive. What we
 * verify here is:
 *   1. PROTECTED_BASE_WORDS guards (this, indeed, breed, recently, code, red,
 *      bed) return the surface unchanged — these are the P1 bug regressions.
 *   2. Without ECDICT, inflected forms (construed, derived, mediated, etc.)
 *      also return the surface unchanged — the doctor is conservative when it
 *      cannot validate candidates against a dictionary.
 *   3. Short words (< 3 chars) and structural markers are returned unchanged.
 *
 * The live `php artisan study-base:doctor` run against the real dev DB (with
 * ECDICT loaded) is the integration verification that construed→construe
 * actually fires. That path is covered by the substage-5 doctor run recorded
 * in the handoff, not by this unit test.
 */
class StudyBaseDoctorComputeLemmaTest extends TestCase
{
    /**
     * Invoke the private computeLemma() method via reflection.
     */
    private function computeLemma(string $surface): string
    {
        $doctor = $this->app->make(StudyBaseDoctor::class);
        $ref = new \ReflectionMethod(StudyBaseDoctor::class, 'computeLemma');
        $ref->setAccessible(true);
        return $ref->invoke($doctor, $surface);
    }

    // ---------------------------------------------------------------------
    // P1 bug regression: PROTECTED_BASE_WORDS must NOT be over-lemmatized.
    // ---------------------------------------------------------------------

    public function test_protected_pronouns_return_unchanged(): void
    {
        $this->assertSame('this', $this->computeLemma('this'));
        $this->assertSame('that', $this->computeLemma('that'));
        $this->assertSame('those', $this->computeLemma('those'));
        $this->assertSame('these', $this->computeLemma('these'));
    }

    public function test_protected_adverbs_return_unchanged(): void
    {
        $this->assertSame('indeed', $this->computeLemma('indeed'));
        $this->assertSame('instead', $this->computeLemma('instead'));
    }

    public function test_protected_eed_words_return_unchanged(): void
    {
        $this->assertSame('breed', $this->computeLemma('breed'));
        $this->assertSame('bleed', $this->computeLemma('bleed'));
        $this->assertSame('greed', $this->computeLemma('greed'));
        $this->assertSame('tweed', $this->computeLemma('tweed'));
        $this->assertSame('knead', $this->computeLemma('knead'));
    }

    // ---------------------------------------------------------------------
    // Philosophy-text sanity guards: must NOT be over-lemmatized.
    // These are the words the task spec §5.3 explicitly requires us NOT to
    // break: recently→recently, code→code, red→red, bed→bed.
    // ---------------------------------------------------------------------

    public function test_recently_returns_unchanged(): void
    {
        $this->assertSame('recently', $this->computeLemma('recently'));
    }

    public function test_code_returns_unchanged(): void
    {
        $this->assertSame('code', $this->computeLemma('code'));
    }

    public function test_red_returns_unchanged(): void
    {
        $this->assertSame('red', $this->computeLemma('red'));
    }

    public function test_bed_returns_unchanged(): void
    {
        $this->assertSame('bed', $this->computeLemma('bed'));
    }

    // ---------------------------------------------------------------------
    // Without ECDICT, inflected forms return the surface unchanged
    // (conservative fallback — the doctor must not suggest a lemma it
    // cannot validate against a dictionary).
    // ---------------------------------------------------------------------

    public function test_philosophy_inflected_forms_conservative_without_ecdict(): void
    {
        // These are the 10 philosophy-text words from the task spec.
        // Without ECDICT, computeLemma returns the surface unchanged.
        $cases = [
            'construed', 'mediated', 'constituted', 'presupposed',
            'determined', 'derived', 'grounded', 'posited',
            'interpreted', 'conditions',
        ];
        foreach ($cases as $surface) {
            $this->assertSame(
                $surface,
                $this->computeLemma($surface),
                "Without ECDICT, computeLemma('{$surface}') must return the surface unchanged."
            );
        }
    }

    public function test_bad_stems_do_not_leak_without_ecdict(): void
    {
        // Words whose stems are in BAD_STEMS. Without ECDICT these return
        // the surface unchanged; with ECDICT the BAD_STEMS guard prevents
        // ecdictExists() from returning the bogus stem.
        // This test only verifies the no-ECDICT path; the BAD_STEMS guard
        // itself is integration-verified via the live doctor run.
        $cases = [
            'derived'    => 'deriv',
            'explored'   => 'explor',
            'noted'      => 'not',
            'stated'     => 'stat',
        ];
        foreach ($cases as $surface => $badStem) {
            $this->assertNotSame(
                $badStem,
                $this->computeLemma($surface),
                "computeLemma('{$surface}') must never return the bogus stem '{$badStem}'."
            );
        }
    }

    // ---------------------------------------------------------------------
    // Short words and structural markers
    // ---------------------------------------------------------------------

    public function test_short_words_return_unchanged(): void
    {
        // computeLemma lowercases input first, so 'I' returns 'i'.
        $this->assertSame('a', $this->computeLemma('a'));
        $this->assertSame('i', $this->computeLemma('I'));
        $this->assertSame('be', $this->computeLemma('be'));
    }

    public function test_structural_markers_return_unchanged(): void
    {
        $this->assertSame('paragraph_break', $this->computeLemma('paragraph_break'));
        $this->assertSame('newline', $this->computeLemma('newline'));
        $this->assertSame('[a]', $this->computeLemma('[a]'));
    }

    // ---------------------------------------------------------------------
    // Irregular forms without ECDICT return the surface unchanged
    // (irregular lemma is computed but ecdictSafe falls back to surface
    // because ecdictExists() returns false).
    // ---------------------------------------------------------------------

    public function test_irregular_forms_conservative_without_ecdict(): void
    {
        // Without ECDICT, even irregular forms return the surface unchanged
        // because ecdictSafe() falls back to the surface.
        $this->assertSame('was', $this->computeLemma('was'));
        $this->assertSame('went', $this->computeLemma('went'));
        $this->assertSame('mice', $this->computeLemma('mice'));
        $this->assertSame('geese', $this->computeLemma('geese'));
        $this->assertSame('better', $this->computeLemma('better'));
        $this->assertSame('children', $this->computeLemma('children'));
    }

    // ---------------------------------------------------------------------
    // Case insensitivity
    // ---------------------------------------------------------------------

    public function test_computelemma_is_case_insensitive(): void
    {
        $this->assertSame('this', $this->computeLemma('THIS'));
        $this->assertSame('indeed', $this->computeLemma('Indeed'));
        $this->assertSame('recently', $this->computeLemma('RECENTLY'));
        $this->assertSame('construed', $this->computeLemma('Construed'));
    }
}
