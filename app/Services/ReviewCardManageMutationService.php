<?php

namespace App\Services;

use App\Models\ReviewCard;
use Carbon\Carbon;

class ReviewCardManageMutationService
{
    /**
     * Toggle fsrs_enabled on a sense review card.
     * Does NOT write WordSense, ReviewLog, or EncounteredWord.
     */
    public function setEnabled(ReviewCard $card, bool $enabled): ReviewCard
    {
        $card->fsrs_enabled = $enabled;
        $card->save();

        return $card;
    }

    /**
     * Set fsrs_due_at = now() on a sense review card.
     * Does NOT auto-enable fsrs_enabled.
     * Does NOT write WordSense, ReviewLog, or EncounteredWord.
     */
    public function setDueNow(ReviewCard $card): ReviewCard
    {
        $card->fsrs_due_at = Carbon::now();
        $card->save();

        return $card;
    }
}
