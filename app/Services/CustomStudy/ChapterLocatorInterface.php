<?php

namespace App\Services\CustomStudy;

/**
 * Abstraction for "does this chapter belong to the given user and language?".
 *
 * Used by CustomStudyCriteriaValidator when validating source_chapter criteria.
 * The interface is intentionally minimal: it returns a boolean, NOT a Chapter
 * model. Callers that need the full Chapter model should query it separately.
 *
 * Phase 1 boundary (Task 2000-16):
 * - This interface has NO production Eloquent implementation yet.
 * - This interface has NO container binding yet.
 * - Phase 1 unit tests use an in-memory stub.
 * - A future Phase 2 / API integration round MUST create the Eloquent
 *   implementation (querying `chapters.user_id` + `chapters.language`) and
 *   bind it in a Service Provider.
 *
 * The interface MUST NOT:
 * - Return a full Chapter Model (return bool only);
 * - Query ReviewCard / ReviewLog / WordSense / WordSenseOccurrence;
 * - Depend on Auth / Request / Session.
 */
interface ChapterLocatorInterface
{
    /**
     * @param int $chapterId
     * @param int $userId
     * @param string $language
     * @return bool True if the chapter exists and belongs to the given user + language.
     */
    public function belongsToUserAndLanguage(int $chapterId, int $userId, string $language): bool;
}
