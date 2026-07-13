<?php

namespace App\Services\CustomStudy;

use App\Exceptions\CustomStudyValidationException;
use InvalidArgumentException;

/**
 * Validates a Custom Study criteria input against the four frozen modes,
 * using a trusted caller-supplied user_id + language (NOT client input).
 *
 * Pure validator — no DB queries of its own (chapter ownership is delegated
 * to the injected ChapterLocatorInterface), no Auth facade, no Request,
 * no ReviewLog / ReviewCard / WordSense lookups.
 *
 * The validator:
 * 1. Receives the raw criteria input array from the client.
 * 2. Receives the trusted user_id + language from the caller (Controller / service).
 * 3. Builds a CustomStudyCriteria value object (which validates mode + parameters).
 * 4. For source_chapter mode, calls ChapterLocatorInterface to confirm chapter ownership.
 * 5. Returns the validated CustomStudyCriteria on success.
 * 6. Throws CustomStudyValidationException on any failure (with stable field + reason).
 *
 * Client-supplied user_id / language in the input array are NEVER used —
 * they are silently dropped by CustomStudyCriteria::fromArray (unknown keys
 * ignored) and the validator always uses the trusted caller-supplied values.
 *
 * Task CS-2 of Custom Study 1A Phase 1 (Task 2000-16).
 */
class CustomStudyCriteriaValidator
{
    public function __construct(
        private readonly ChapterLocatorInterface $chapterLocator
    ) {
    }

    /**
     * @param array<string, mixed> $input Raw client input (mode + parameters).
     * @param int $userId Trusted current user id (from caller, NOT from input).
     * @param string $language Trusted current language (from caller, NOT from input).
     * @return CustomStudyCriteria
     * @throws CustomStudyValidationException
     */
    public function validate(array $input, int $userId, string $language): CustomStudyCriteria
    {
        // 1. Validate trusted context first — these come from the server, not the client.
        if ($userId <= 0) {
            throw new CustomStudyValidationException(
                'user_id',
                'invalid_user_id',
                'Custom Study criteria validation failed: user_id must be a positive integer.'
            );
        }
        if ($language === '') {
            throw new CustomStudyValidationException(
                'language',
                'invalid_language',
                'Custom Study criteria validation failed: language must be a non-empty string.'
            );
        }

        // 2. Build the value object (this validates mode + parameters).
        //    Malicious user_id / language in $input are silently dropped here
        //    because CustomStudyCriteria::fromArray ignores unknown keys.
        try {
            $criteria = CustomStudyCriteria::fromArray($input);
        } catch (InvalidArgumentException $e) {
            throw $this->translateCriteriaException($e, $input);
        }

        // 3. For source_chapter, confirm chapter ownership via the locator.
        //    Other modes do NOT call the locator.
        if ($criteria->mode() === CustomStudyCriteria::MODE_SOURCE_CHAPTER) {
            $chapterId = $criteria->parameters()['chapter_id'];
            $owned = $this->chapterLocator->belongsToUserAndLanguage($chapterId, $userId, $language);
            if (!$owned) {
                throw new CustomStudyValidationException(
                    'chapter_id',
                    'chapter_not_owned',
                    "Custom Study criteria validation failed: chapter_id {$chapterId} does not belong to user {$userId} + language {$language}."
                );
            }
        }

        return $criteria;
    }

    /**
     * Translate an InvalidArgumentException from CustomStudyCriteria::fromArray
     * into a structured CustomStudyValidationException with a stable field + reason.
     */
    private function translateCriteriaException(InvalidArgumentException $e, array $input): CustomStudyValidationException
    {
        $message = $e->getMessage();

        // Detect mode-related failures.
        if (str_contains($message, 'missing required key: mode')) {
            return new CustomStudyValidationException('mode', 'missing_mode', $message);
        }
        if (str_contains($message, 'Unknown Custom Study criteria mode')) {
            return new CustomStudyValidationException('mode', 'unknown_mode', $message);
        }

        // Detect source_chapter parameter failures.
        if (str_contains($message, 'source_chapter criteria requires parameter: chapter_id')) {
            return new CustomStudyValidationException('chapter_id', 'missing_chapter_id', $message);
        }
        if (str_contains($message, 'chapter_id must be an integer')) {
            return new CustomStudyValidationException('chapter_id', 'invalid_chapter_id', $message);
        }
        if (str_contains($message, 'chapter_id must be greater than 0')) {
            return new CustomStudyValidationException('chapter_id', 'invalid_chapter_id', $message);
        }

        // Detect leech_attention parameter failures.
        if (str_contains($message, 'leech_attention criteria requires parameter: sub_mode')) {
            return new CustomStudyValidationException('sub_mode', 'missing_sub_mode', $message);
        }
        if (str_contains($message, 'Invalid leech_attention sub_mode')) {
            return new CustomStudyValidationException('sub_mode', 'invalid_sub_mode', $message);
        }

        // Fallback — should not normally happen, but keep a stable reason.
        return new CustomStudyValidationException('criteria', 'invalid_criteria', $message);
    }
}
