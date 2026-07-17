<?php

namespace App\Services\CustomStudy;

use App\Services\CustomStudy\Queries\LeechAttentionQuery;
use App\Services\CustomStudy\Queries\MarkedQuery;
use App\Services\CustomStudy\Queries\OverdueQuery;
use App\Services\CustomStudy\Queries\SourceChapterQuery;
use App\Services\CustomStudy\Queries\TodayForgottenQuery;
use Illuminate\Support\Carbon;

/**
 * Custom Study Phase 2B — unified candidate ID orchestration boundary.
 *
 * Frozen by Task 2000-18 §6.2 / §10:
 *   - Single entry point that turns a validated CustomStudyCriteria into a
 *     list of unique positive ReviewCard IDs.
 *   - Dispatches to one of the four Query classes based on the criteria
 *     mode — does NOT run multiple modes in parallel.
 *   - For SQL-native modes (today_forgotten / overdue / source_chapter)
 *     it terminates the Builder via `pluck('review_cards.id')`.
 *   - For the Policy-derived mode (leech_attention) it delegates to
 *     LeechAttentionQuery::candidateIds() — which already returns
 *     list<int>.
 *   - Output is de-duplicated + filtered to positive integers; order is
 *     NOT guaranteed (session ordering is a Phase 3+ concern).
 *
 * Hard rules (Task 2000-18 §10.2):
 *   - Does NOT apply card_limit.
 *   - Does NOT load serializer payload.
 *   - Does NOT create session / token / SessionState.
 *   - Does NOT write ReviewLog / ReviewCard / WordSense / lifecycle.
 *   - Does NOT modify FSRS.
 *   - Does NOT call AI.
 *   - Does NOT sort.
 *
 * Forbidden by Task 2000-18 §6.2:
 *   - No new QueryInterface / DTO / Repository / Adapter. This service is
 *     the ONLY orchestration layer above the four Query classes.
 */
class CustomStudyQueryService
{
    public function __construct(
        private readonly TodayForgottenQuery $todayForgottenQuery,
        private readonly OverdueQuery $overdueQuery,
        private readonly SourceChapterQuery $sourceChapterQuery,
        private readonly LeechAttentionQuery $leechAttentionQuery,
        private readonly MarkedQuery $markedQuery,
    ) {
    }

    /**
     * Resolve the candidate ReviewCard IDs for the given criteria.
     *
     * @param CustomStudyCriteria $criteria  Validated criteria value object.
     * @param int                 $userId    Trusted current user id.
     * @param string              $language  Trusted current language.
     * @param Carbon              $now       Current instant.
     * @return list<int>  Unique positive ReviewCard IDs. Empty when no
     *                    candidate matches. Order is NOT guaranteed.
     */
    public function candidateIds(
        CustomStudyCriteria $criteria,
        int $userId,
        string $language,
        Carbon $now
    ): array {
        $mode = $criteria->mode();

        switch ($mode) {
            case CustomStudyCriteria::MODE_TODAY_FORGOTTEN:
                $ids = $this->todayForgottenQuery
                    ->build($userId, $language, $now)
                    ->pluck('review_cards.id')
                    ->all();
                break;

            case CustomStudyCriteria::MODE_OVERDUE:
                $ids = $this->overdueQuery
                    ->build($userId, $language, $now)
                    ->pluck('review_cards.id')
                    ->all();
                break;

            case CustomStudyCriteria::MODE_SOURCE_CHAPTER:
                $chapterId = (int) ($criteria->parameters()['chapter_id'] ?? 0);
                $ids = $this->sourceChapterQuery
                    ->build($userId, $language, $chapterId, $now)
                    ->pluck('review_cards.id')
                    ->all();
                break;

            case CustomStudyCriteria::MODE_LEECH_ATTENTION:
                $subMode = (string) ($criteria->parameters()['sub_mode'] ?? '');
                $ids = $this->leechAttentionQuery
                    ->candidateIds($userId, $language, $subMode, $now);
                break;

            case CustomStudyCriteria::MODE_MARKED:
                $ids = $this->markedQuery
                    ->build($userId, $language, $now)
                    ->pluck('review_cards.id')
                    ->all();
                break;

            default:
                // Should never reach here — criteria is validated upstream.
                $ids = [];
                break;
        }

        // Normalize: keep only positive integers, de-duplicate, preserve
        // no particular order (sorting / card_limit belong to Phase 3+).
        $ids = array_values(array_filter(
            array_map('intval', $ids),
            fn ($id) => $id > 0
        ));
        $ids = array_values(array_unique($ids));

        return $ids;
    }
}
