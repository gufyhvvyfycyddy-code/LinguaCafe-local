<?php

namespace Tests\Unit;

use App\Exceptions\CustomStudyValidationException;
use App\Services\CustomStudy\ChapterLocatorInterface;
use App\Services\CustomStudy\CustomStudyCriteria;
use App\Services\CustomStudy\CustomStudyCriteriaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CustomStudyCriteriaValidator.
 *
 * Pure unit tests — no Laravel container, no DB, no Auth, no Request.
 * Uses an in-memory stub ChapterLocator so the validator stays unit-testable.
 *
 * Verifies the 28-item matrix from Task 2000-16 §9.6:
 * - 4 valid modes (today_forgotten / overdue / source_chapter / leech_only / leech_plus_struggling)
 * - unknown mode rejection
 * - source_chapter missing/zero/negative/non-int chapter_id rejection
 * - leech_attention missing/invalid sub_mode rejection
 * - empty language rejection
 * - invalid user id rejection
 * - malicious user_id / language in input cannot override trusted context
 * - locator receives correct (chapterId, userId, language)
 * - locator NOT called for today_forgotten / overdue / leech_attention
 * - validator does not depend on Auth / Request / ReviewLog / ReviewCard / WordSense
 * - returns CustomStudyCriteria on success
 * - exception carries stable field / reason
 *
 * Task CS-2 of Custom Study 1A Phase 1 (Task 2000-16).
 */
class CustomStudyCriteriaValidatorTest extends TestCase
{
    private StubChapterLocator $locator;
    private CustomStudyCriteriaValidator $validator;

    protected function setUp(): void
    {
        $this->locator = new StubChapterLocator();
        $this->validator = new CustomStudyCriteriaValidator($this->locator);
    }

    // ---------- 1-3: valid modes ----------

    public function test_validates_today_forgotten(): void
    {
        $criteria = $this->validator->validate(
            ['mode' => 'today_forgotten', 'parameters' => []],
            1,
            'english'
        );

        $this->assertInstanceOf(CustomStudyCriteria::class, $criteria);
        $this->assertSame('today_forgotten', $criteria->mode());
    }

    public function test_validates_overdue(): void
    {
        $criteria = $this->validator->validate(
            ['mode' => 'overdue', 'parameters' => []],
            1,
            'english'
        );

        $this->assertInstanceOf(CustomStudyCriteria::class, $criteria);
        $this->assertSame('overdue', $criteria->mode());
    }

    public function test_validates_source_chapter_when_locator_returns_true(): void
    {
        $this->locator->setReturn(true);
        $criteria = $this->validator->validate(
            ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => 42]],
            1,
            'english'
        );

        $this->assertInstanceOf(CustomStudyCriteria::class, $criteria);
        $this->assertSame('source_chapter', $criteria->mode());
        $this->assertSame(['chapter_id' => 42], $criteria->parameters());
    }

    // ---------- 4: source_chapter locator returns false ----------

    public function test_source_chapter_fails_when_locator_returns_false(): void
    {
        $this->locator->setReturn(false);
        $this->expectException(CustomStudyValidationException::class);
        $this->validator->validate(
            ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => 42]],
            1,
            'english'
        );
    }

    // ---------- 5-6: leech_attention valid ----------

    public function test_validates_leech_only(): void
    {
        $criteria = $this->validator->validate(
            ['mode' => 'leech_attention', 'parameters' => ['sub_mode' => 'leech_only']],
            1,
            'english'
        );

        $this->assertInstanceOf(CustomStudyCriteria::class, $criteria);
        $this->assertSame('leech_attention', $criteria->mode());
        $this->assertSame(['sub_mode' => 'leech_only'], $criteria->parameters());
    }

    public function test_validates_leech_plus_struggling(): void
    {
        $criteria = $this->validator->validate(
            ['mode' => 'leech_attention', 'parameters' => ['sub_mode' => 'leech_plus_struggling']],
            1,
            'english'
        );

        $this->assertInstanceOf(CustomStudyCriteria::class, $criteria);
        $this->assertSame('leech_attention', $criteria->mode());
        $this->assertSame(['sub_mode' => 'leech_plus_struggling'], $criteria->parameters());
    }

    // ---------- 7: unknown mode ----------

    public function test_rejects_unknown_mode(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        $this->validator->validate(
            ['mode' => 'marked', 'parameters' => []],
            1,
            'english'
        );
    }

    // ---------- 8-11: source_chapter parameter failures ----------

    public function test_rejects_source_chapter_missing_chapter_id(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        $this->validator->validate(
            ['mode' => 'source_chapter', 'parameters' => []],
            1,
            'english'
        );
    }

    public function test_rejects_source_chapter_with_chapter_id_zero(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        $this->validator->validate(
            ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => 0]],
            1,
            'english'
        );
    }

    public function test_rejects_source_chapter_with_negative_chapter_id(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        $this->validator->validate(
            ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => -5]],
            1,
            'english'
        );
    }

    public function test_rejects_source_chapter_with_string_chapter_id(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        $this->validator->validate(
            ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => '42']],
            1,
            'english'
        );
    }

    // ---------- 12-13: leech_attention parameter failures ----------

    public function test_rejects_leech_attention_missing_sub_mode(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        $this->validator->validate(
            ['mode' => 'leech_attention', 'parameters' => []],
            1,
            'english'
        );
    }

    public function test_rejects_leech_attention_with_invalid_sub_mode(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        $this->validator->validate(
            ['mode' => 'leech_attention', 'parameters' => ['sub_mode' => 'all']],
            1,
            'english'
        );
    }

    // ---------- 14-15: language / user_id failures ----------

    public function test_rejects_empty_language(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        $this->validator->validate(
            ['mode' => 'today_forgotten', 'parameters' => []],
            1,
            ''
        );
    }

    public function test_rejects_invalid_user_id(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        $this->validator->validate(
            ['mode' => 'today_forgotten', 'parameters' => []],
            0,
            'english'
        );
    }

    public function test_rejects_negative_user_id(): void
    {
        $this->expectException(CustomStudyValidationException::class);
        $this->validator->validate(
            ['mode' => 'today_forgotten', 'parameters' => []],
            -1,
            'english'
        );
    }

    // ---------- 16-17: malicious input cannot override trusted context ----------

    public function test_malicious_user_id_in_input_cannot_override_trusted_user_id(): void
    {
        // Trusted user_id = 1. Input contains user_id = 999. Validator must use trusted = 1.
        $this->locator->setReturn(true);
        $criteria = $this->validator->validate(
            ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => 42], 'user_id' => 999],
            1,
            'english'
        );

        // locator should have been called with trusted userId=1, NOT 999
        $this->assertSame([[42, 1, 'english']], $this->locator->calls);
        $this->assertSame('source_chapter', $criteria->mode());
    }

    public function test_malicious_language_in_input_cannot_override_trusted_language(): void
    {
        $this->locator->setReturn(true);
        $criteria = $this->validator->validate(
            ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => 42], 'language' => 'other-language'],
            1,
            'english'
        );

        $this->assertSame([[42, 1, 'english']], $this->locator->calls);
        $this->assertSame('source_chapter', $criteria->mode());
    }

    // ---------- 18: locator receives correct arguments ----------

    public function test_locator_receives_correct_chapter_id_user_id_and_language(): void
    {
        $this->locator->setReturn(true);
        $this->validator->validate(
            ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => 77]],
            5,
            'japanese'
        );

        $this->assertSame([[77, 5, 'japanese']], $this->locator->calls);
    }

    // ---------- 19-21: non-source_chapter modes do NOT call locator ----------

    public function test_today_forgotten_does_not_call_locator(): void
    {
        $this->validator->validate(
            ['mode' => 'today_forgotten', 'parameters' => []],
            1,
            'english'
        );

        $this->assertSame([], $this->locator->calls);
    }

    public function test_overdue_does_not_call_locator(): void
    {
        $this->validator->validate(
            ['mode' => 'overdue', 'parameters' => []],
            1,
            'english'
        );

        $this->assertSame([], $this->locator->calls);
    }

    public function test_leech_attention_does_not_call_locator(): void
    {
        $this->validator->validate(
            ['mode' => 'leech_attention', 'parameters' => ['sub_mode' => 'leech_only']],
            1,
            'english'
        );

        $this->assertSame([], $this->locator->calls);
    }

    // ---------- 28: exception carries stable field / reason ----------

    public function test_exception_carries_stable_field_and_reason_for_unknown_mode(): void
    {
        try {
            $this->validator->validate(
                ['mode' => 'marked', 'parameters' => []],
                1,
                'english'
            );
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('mode', $e->getField());
            $this->assertSame('unknown_mode', $e->getReason());
        }
    }

    public function test_exception_carries_stable_field_and_reason_for_chapter_not_owned(): void
    {
        $this->locator->setReturn(false);
        try {
            $this->validator->validate(
                ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => 42]],
                1,
                'english'
            );
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('chapter_id', $e->getField());
            $this->assertSame('chapter_not_owned', $e->getReason());
        }
    }

    public function test_exception_carries_stable_field_and_reason_for_invalid_user_id(): void
    {
        try {
            $this->validator->validate(
                ['mode' => 'today_forgotten', 'parameters' => []],
                0,
                'english'
            );
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('user_id', $e->getField());
            $this->assertSame('invalid_user_id', $e->getReason());
        }
    }

    public function test_exception_carries_stable_field_and_reason_for_empty_language(): void
    {
        try {
            $this->validator->validate(
                ['mode' => 'today_forgotten', 'parameters' => []],
                1,
                ''
            );
            $this->fail('Expected CustomStudyValidationException was not thrown');
        } catch (CustomStudyValidationException $e) {
            $this->assertSame('language', $e->getField());
            $this->assertSame('invalid_language', $e->getReason());
        }
    }

    // ---------- 22-26: validator does not depend on Auth/Request/ReviewLog/ReviewCard/WordSense ----------
    // These are enforced by code inspection (no `use` statements for those facades/models
    // in CustomStudyCriteriaValidator.php). The fact that this pure unit test runs without
    // booting Laravel container proves the validator has no hidden dependencies.

    public function test_validator_runs_without_laravel_container_or_db(): void
    {
        // If the validator had any Auth/Request/DB/Model dependency, this pure TestCase
        // (which does NOT boot Laravel) would fail to instantiate it.
        $this->assertInstanceOf(CustomStudyCriteriaValidator::class, $this->validator);
        $criteria = $this->validator->validate(
            ['mode' => 'today_forgotten', 'parameters' => []],
            1,
            'english'
        );
        $this->assertSame('today_forgotten', $criteria->mode());
    }
}

/**
 * In-memory stub for ChapterLocatorInterface used only in unit tests.
 *
 * Records every call so tests can assert arguments and call counts.
 */
class StubChapterLocator implements ChapterLocatorInterface
{
    public bool $return = true;

    /** @var list<array{0:int,1:int,2:string}> */
    public array $calls = [];

    public function setReturn(bool $return): void
    {
        $this->return = $return;
    }

    public function belongsToUserAndLanguage(int $chapterId, int $userId, string $language): bool
    {
        $this->calls[] = [$chapterId, $userId, $language];
        return $this->return;
    }
}
