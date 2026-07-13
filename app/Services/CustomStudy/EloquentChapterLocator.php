<?php

namespace App\Services\CustomStudy;

use App\Models\Chapter;

/**
 * Production Eloquent implementation of ChapterLocatorInterface.
 *
 * Task 2000-18 (Phase 2B).
 *
 * Boundary:
 *   - Queries ONLY the `chapters` table.
 *   - Uses `exists()` — does NOT load a Chapter model.
 *   - Does NOT use Auth, Request, or Session.
 *   - Does NOT query ReviewCard / ReviewLog / WordSense / WordSenseOccurrence.
 *   - Does NOT write any data.
 *   - Returns a plain bool — chapter existence / ownership is NOT
 *     distinguishable to the caller (both return false).
 *
 * The locator is intentionally minimal: callers that need the full
 * Chapter model should query it separately. This service exists only
 * to answer "does this chapter belong to the given user + language?"
 * inside CustomStudyCriteriaValidator.
 */
final class EloquentChapterLocator implements ChapterLocatorInterface
{
    public function belongsToUserAndLanguage(int $chapterId, int $userId, string $language): bool
    {
        if ($chapterId <= 0 || $userId <= 0 || $language === '') {
            return false;
        }

        return Chapter::query()
            ->where('id', $chapterId)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->exists();
    }
}
