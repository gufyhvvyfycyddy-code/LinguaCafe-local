<?php

namespace App\Services\CustomStudy;

use App\Exceptions\CustomStudyValidationException;

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
 * 3. Builds a CustomStudyCriteria value object (which validates mode + parameters
 *    and throws structured CustomStudyValidationException directly).
 * 4. For source_chapter mode, calls ChapterLocatorInterface to confirm chapter ownership.
 * 5. Returns the validated CustomStudyCriteria on success.
 * 6. Throws CustomStudyValidationException on any failure (with stable field + reason).
 *
 * Task 2000-17 error contract fix:
 * The validator NO LONGER catches the old plain SPL exception and NO LONGER
 * parses message text to derive field/reason. CustomStudyCriteria::fromArray()
 * now throws CustomStudyValidationException directly with field/reason set at
 * the throw site. The validator lets that exception propagate unchanged.
 *
 * Client-supplied user_id / language in the input array are NEVER used —
 * they are silently dropped by CustomStudyCriteria::fromArray (unknown keys
 * ignored) and the validator always uses the trusted caller-supplied values.
 *
 * Task CS-2 of Custom Study 1A Phase 1 (Task 2000-16).
 * Error contract fix: Task 2000-17.
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
        //    CustomStudyCriteria::fromArray() throws CustomStudyValidationException
        //    directly with stable field/reason — the validator lets it propagate
        //    unchanged (no message parsing, no re-translation).
        $criteria = CustomStudyCriteria::fromArray($input);

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
}
