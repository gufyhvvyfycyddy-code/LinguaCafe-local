<?php

namespace App\Services\CustomStudy;

/**
 * Abstraction for "does this chapter belong to the given user and language?".
 *
 * Used by CustomStudyCriteriaValidator when validating source_chapter criteria.
 * The interface is intentionally minimal: it returns a boolean, NOT a Chapter
 * model. Callers that need the full Chapter model should query it separately.
 *
 * Production binding (Task 2000-18 / Phase 2B):
 * - `EloquentChapterLocator` is the production implementation, bound in
 *   `AppServiceProvider`. It queries `chapters.user_id` + `chapters.language`
 *   via `exists()` and returns a plain bool.
 * - `app(ChapterLocatorInterface::class)` resolves to `EloquentChapterLocator`.
 * - Phase 1 unit tests still use an in-memory stub for isolation.
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
     *              False if the chapter does not exist OR exists but belongs to
     *              another user / language. The two cases are intentionally
     *              indistinguishable to the caller.
     */
    public function belongsToUserAndLanguage(int $chapterId, int $userId, string $language): bool;
}
