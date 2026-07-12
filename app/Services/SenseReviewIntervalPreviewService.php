<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * SenseReviewIntervalPreviewService
 *
 * ADR-0008: Read-only preview of all four rating projections for a sense
 * review card. Provides the data behind the "预计" interval hints shown on
 * the rating buttons after the user reveals the answer.
 *
 * Boundary:
 *  - Reads: ReviewCard, WordSense (access control only).
 *  - Writes: NOTHING. No ReviewLog, no FSRS mutation, no queue change.
 *  - Dependencies: FsrsSchedulingService (pure projection).
 *
 * Access control (all must pass, else 404):
 *  1. review_card_id exists.
 *  2. user_id matches current user.
 *  3. language_id matches current user's selected language.
 *  4. target_type === 'sense'.
 *  5. ADR-0010: lifecycle_state === 'active' AND not effectively buried
 *     (buried_until IS NULL OR buried_until <= now). Suspended/Archived
 *     cards return null (404). Expired buried cards are treated as Active.
 *  6. fsrs_enabled === true (compatibility mirror, redundant with #5).
 *  7. WordSense exists, belongs to current user + language, status=confirmed.
 *
 * This service does NOT require the card to be due. Access and lifecycle
 * eligibility are the hard boundary; due status is not a security boundary.
 */
class SenseReviewIntervalPreviewService
{
    public function __construct(
        private FsrsSchedulingService $fsrsSchedulingService,
    ) {
    }

    /**
     * Build the interval preview payload for a sense review card.
     *
     * Returns null when access control fails (caller should 404).
     *
     * @return array|null
     */
    public function preview(int $reviewCardId, int $userId, string $language): ?array
    {
        $now = Carbon::now();
        $card = ReviewCard::where('id', $reviewCardId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->where('lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE)
            ->where(function ($q) use ($now) {
                $q->whereNull('buried_until')
                    ->orWhere('buried_until', '<=', $now);
            })
            ->where('fsrs_enabled', true)
            ->first();

        if (!$card) {
            return null;
        }

        $sense = WordSense::where('id', $card->target_id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->first();

        if (!$sense) {
            return null;
        }

        $reviewedAt = Carbon::now();
        $projections = $this->fsrsSchedulingService->previewAllRatings($card, $reviewedAt);

        $ratings = [];
        foreach ($this->fsrsSchedulingService->ratings() as $rating) {
            $proj = $projections[$rating];
            $ratings[$rating] = [
                'due_at' => $proj['due_at']->toIso8601String(),
                'interval_seconds' => $proj['interval_seconds'],
                'next_state' => $proj['state'],
            ];
        }

        return [
            'review_card_id' => $card->id,
            'generated_at' => $reviewedAt->toIso8601String(),
            'timezone' => $reviewedAt->timezoneName,
            'engine' => $this->fsrsSchedulingService->activeEngine(),
            'ratings' => $ratings,
        ];
    }
}
