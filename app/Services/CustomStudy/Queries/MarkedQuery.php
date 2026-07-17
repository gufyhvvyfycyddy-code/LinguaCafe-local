<?php

namespace App\Services\CustomStudy\Queries;

use App\Models\ReviewCard;
use App\Services\SenseReviewQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class MarkedQuery
{
    public function __construct(
        private readonly SenseReviewQueryService $senseReviewQueryService,
    ) {
    }

    /** @return Builder<ReviewCard> */
    public function build(int $userId, string $language, Carbon $now): Builder
    {
        return $this->senseReviewQueryService
            ->confirmedSenseCardQuery($userId, $language)
            ->senseReviewEligible($userId, $language, $now)
            ->where('review_cards.marker', '>', ReviewCard::MARKER_UNMARKED);
    }
}
