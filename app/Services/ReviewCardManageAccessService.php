<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use Illuminate\Support\Facades\Auth;

/**
 * ReviewCardManageAccessService
 *
 * ADR-0007 — SenseReview Report Card Deep Link
 *
 * Single source of truth for sense-review-card access control. Every
 * Controller action that operates on a specific sense ReviewCard (update,
 * enabled, dueNow, reset, destroy, logs, detail) delegates to this service
 * so that user / language / target_type / WordSense-status checks live in
 * exactly one place.
 *
 * Validation rules (all must pass, else 404):
 *  - review_card_id exists in review_cards.
 *  - card.user_id === current user.
 *  - card.language_id === current user's selected_language.
 *  - card.target_type === 'sense'.
 *  - WordSense (card.target_id) exists.
 *  - sense.user_id === current user.
 *  - sense.language_id === current user's selected_language.
 *  - sense.status === 'confirmed'.
 *
 * Archived cards (fsrs_enabled = false) ARE manageable — archiving only
 * removes the card from the daily review queue, it does not revoke
 * management access.
 *
 * Legacy word cards (target_type = 'word') are NOT manageable here — this
 * service is sense-only. A 404 is returned for any non-sense card.
 *
 * Invariants:
 *  - READ-ONLY access checks: never writes ReviewCard / WordSense / FSRS.
 *  - Never creates cards or senses.
 *  - Returns [$card, $sense] on success; aborts 404 on any failure.
 *  - No product copy / serialization — callers shape the response.
 */
class ReviewCardManageAccessService
{
    /**
     * Find a manageable sense ReviewCard or abort 404.
     *
     * @param  int     $reviewCardId
     * @param  int     $userId
     * @param  string  $language  The user's selected_language (language_id).
     * @return array{0: ReviewCard, 1: WordSense}
     */
    public function findManageableSenseCardOrFail(
        int $reviewCardId,
        int $userId,
        string $language
    ): array {
        $card = ReviewCard::query()
            ->where('id', $reviewCardId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->first();

        if (!$card) {
            abort(404);
        }

        $sense = WordSense::query()
            ->where('id', $card->target_id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->first();

        if (!$sense) {
            abort(404);
        }

        return [$card, $sense];
    }
}
