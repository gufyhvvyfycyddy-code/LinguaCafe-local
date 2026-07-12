<?php

namespace App\Services;

use App\Models\ReviewCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SenseReviewLeechQueryService
 *
 * ADR-0011
 *
 * Read-only query service that batch-loads ReviewLog data for sense review
 * cards and builds leech descriptors using SenseReviewLeechPolicy.
 *
 * Responsibilities:
 *  - Batch-load non-reset, non-undone ReviewLog rows for a set of cards.
 *  - Build learning_feedback descriptors (via SenseReviewLearningFeedbackService).
 *  - Build lifecycle descriptors (via ReviewCardLifecyclePolicy).
 *  - Classify each card via SenseReviewLeechPolicy.
 *  - Support single-card and paginated batch queries.
 *  - Support leech status filtering (stable / struggling / leech / all).
 *
 * Hard rules:
 *  - READ-ONLY: never writes any table.
 *  - Reuses SenseReviewAnalyticsQueryService::reviewsForCards() for batch
 *    loading (1 ReviewLog query regardless of card count — no N+1).
 *  - Excludes reset and undone ReviewLog rows (delegated to QueryService).
 *  - Does NOT call AI.
 *  - Does NOT call lifecycle mutation.
 *
 * Layering:
 *   This service sits ABOVE:
 *     - SenseReviewLearningFeedbackService (feedback aggregation)
 *     - ReviewCardLifecyclePolicy (lifecycle descriptor)
 *     - SenseReviewLeechPolicy (classification)
 *
 *   And BESIDE:
 *     - SenseReviewAnalyticsQueryService (shared ReviewLog query layer)
 */
class SenseReviewLeechQueryService
{
    public function __construct(
        private SenseReviewLearningFeedbackService $feedbackService,
        private ReviewCardLifecyclePolicy $lifecyclePolicy,
        private SenseReviewLeechPolicy $leechPolicy,
    ) {
    }

    /**
     * Build the leech descriptor for a single card.
     *
     * @param  ReviewCard  $card
     * @param  Carbon|null $now
     * @param  string      $timezone
     * @return array  Leech descriptor {status, severity, reasons, suggestions, blocked_actions}
     */
    public function describeForCard(ReviewCard $card, ?Carbon $now = null, string $timezone = 'UTC'): array
    {
        $now = $now ?? Carbon::now();
        $feedback = $this->feedbackService->buildForCard($card->id);
        $lifecycleDescriptor = $this->lifecyclePolicy->describe($card, $now, $timezone);

        return $this->leechPolicy->classify($card, $feedback, $lifecycleDescriptor, $now);
    }

    /**
     * Build the leech descriptor for a single card using PRE-BUILT feedback.
     *
     * This method does NOT query the database — it reuses a feedback
     * descriptor that was already built by the caller (typically via
     * SenseReviewLearningFeedbackService::buildForCard() or
     * buildForCards()). This is the correct entry point when the caller
     * has already loaded feedback, to avoid duplicate ReviewLog queries.
     *
     * @param  ReviewCard  $card
     * @param  array       $feedback  Pre-built learning feedback descriptor.
     * @param  Carbon|null $now
     * @param  string      $timezone
     * @return array  Leech descriptor {status, severity, reasons, suggestions, blocked_actions}
     */
    public function describeForCardWithFeedback(
        ReviewCard $card,
        array $feedback,
        ?Carbon $now = null,
        string $timezone = 'UTC'
    ): array {
        $now = $now ?? Carbon::now();
        $lifecycleDescriptor = $this->lifecyclePolicy->describe($card, $now, $timezone);

        return $this->leechPolicy->classify($card, $feedback, $lifecycleDescriptor, $now);
    }

    /**
     * Build leech descriptors for many cards using a PRE-BUILT feedback map.
     *
     * This method does NOT query the database — it reuses a feedback map
     * that was already built by the caller (typically via
     * SenseReviewLearningFeedbackService::buildForCards()). This is the
     * correct entry point for batch operations that have already loaded
     * feedback, to avoid N+1 ReviewLog queries.
     *
     * Query count: 0 ReviewLog queries (feedback must be pre-built).
     *
     * @param  array<int>    $cardIds
     * @param  Collection    $cards    Pre-loaded card models keyed by id.
     * @param  array<int, array> $feedbackMap  Pre-built feedback map (card_id => feedback).
     * @param  Carbon|null   $now
     * @param  string        $timezone
     * @return array<int, array>  Map of card_id => leech descriptor.
     */
    public function describeForCardsWithFeedbackMap(
        array $cardIds,
        Collection $cards,
        array $feedbackMap,
        ?Carbon $now = null,
        string $timezone = 'UTC'
    ): array {
        $now = $now ?? Carbon::now();
        $cardIds = array_values(array_filter(array_map('intval', $cardIds), fn($id) => $id > 0));

        if (empty($cardIds)) {
            return [];
        }

        $cards = $cards->keyBy('id');

        $result = [];
        foreach ($cardIds as $cardId) {
            $card = $cards->get($cardId);
            if (!$card) {
                continue;
            }
            $feedback = $feedbackMap[$cardId] ?? [];
            $lifecycleDescriptor = $this->lifecyclePolicy->describe($card, $now, $timezone);
            $result[$cardId] = $this->leechPolicy->classify($card, $feedback, $lifecycleDescriptor, $now);
        }

        return $result;
    }

    /**
     * Build leech descriptors for many cards in a single batch.
     *
     * Uses SenseReviewLearningFeedbackService::buildForCards() which issues
     * exactly 1 ReviewLog query for all cards — no N+1.
     *
     * @param  array<int>    $cardIds
     * @param  Collection|null $cards  Pre-loaded card models (avoids re-querying).
     * @param  Carbon|null   $now
     * @param  string        $timezone
     * @return array<int, array>  Map of card_id => leech descriptor.
     */
    public function describeForCards(array $cardIds, ?Collection $cards = null, ?Carbon $now = null, string $timezone = 'UTC'): array
    {
        $now = $now ?? Carbon::now();
        $cardIds = array_values(array_filter(array_map('intval', $cardIds), fn($id) => $id > 0));

        if (empty($cardIds)) {
            return [];
        }

        // Batch build feedback for all cards (1 ReviewLog query).
        $feedbackMap = $this->feedbackService->buildForCards($cardIds);

        // Load cards if not pre-loaded.
        if ($cards === null) {
            $cards = ReviewCard::whereIn('id', $cardIds)->get()->keyBy('id');
        } else {
            $cards = $cards->keyBy('id');
        }

        $result = [];
        foreach ($cardIds as $cardId) {
            $card = $cards->get($cardId);
            if (!$card) {
                continue;
            }
            $feedback = $feedbackMap[$cardId] ?? [];
            $lifecycleDescriptor = $this->lifecyclePolicy->describe($card, $now, $timezone);
            $result[$cardId] = $this->leechPolicy->classify($card, $feedback, $lifecycleDescriptor, $now);
        }

        return $result;
    }

    /**
     * Get a leech summary for the management page.
     *
     * Returns counts by status and a list of card IDs that are leech/struggling.
     *
     * @param  int       $userId
     * @param  string    $language
     * @param  Carbon|null $now
     * @return array{
     *     counts: array{stable: int, struggling: int, leech: int},
     *     leech_card_ids: list<int>,
     *     struggling_card_ids: list<int>,
     * }
     */
    public function summary(int $userId, string $language, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();

        // Get all sense cards for this user/language (regardless of lifecycle).
        $cardIds = ReviewCard::where('user_id', $userId)
            ->where('language', $language)
            ->where('target_type', 'sense')
            ->pluck('id')
            ->all();

        if (empty($cardIds)) {
            return [
                'counts' => ['stable' => 0, 'struggling' => 0, 'leech' => 0],
                'leech_card_ids' => [],
                'struggling_card_ids' => [],
            ];
        }

        $descriptors = $this->describeForCards($cardIds, null, $now);

        $counts = ['stable' => 0, 'struggling' => 0, 'leech' => 0];
        $leechIds = [];
        $strugglingIds = [];

        foreach ($descriptors as $cardId => $desc) {
            $status = $desc['status'] ?? 'stable';
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            if ($status === 'leech') {
                $leechIds[] = $cardId;
            } elseif ($status === 'struggling') {
                $strugglingIds[] = $cardId;
            }
        }

        return [
            'counts' => $counts,
            'leech_card_ids' => $leechIds,
            'struggling_card_ids' => $strugglingIds,
        ];
    }

    /**
     * Filter card IDs by leech status.
     *
     * Used by ReviewCardManageQueryService to apply leech/struggling filters.
     *
     * @param  array<int> $cardIds
     * @param  string     $filter  'leech' | 'struggling' | 'stable' | 'all'
     * @param  Carbon|null $now
     * @return list<int>  Card IDs matching the filter.
     */
    public function filterCardIdsByLeechStatus(array $cardIds, string $filter, ?Carbon $now = null): array
    {
        if ($filter === 'all' || empty($cardIds)) {
            return array_values($cardIds);
        }

        $descriptors = $this->describeForCards($cardIds, null, $now);
        $result = [];

        foreach ($descriptors as $cardId => $desc) {
            if (($desc['status'] ?? 'stable') === $filter) {
                $result[] = $cardId;
            }
        }

        return $result;
    }
}
