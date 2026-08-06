<?php

namespace App\Services\SpecialStudy;

use App\Models\Chapter;
use App\Models\Book;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSenseOccurrence;
use App\Services\ReviewCardManageFilterState;
use App\Services\ReviewCardManageQueryService;
use App\Services\ReviewQueueOrderService;
use App\Services\ReviewStudyTimezoneService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SpecialStudyCandidateQueryService
{
    public function __construct(
        private readonly ReviewCardManageQueryService $manageQueryService,
        private readonly ReviewQueueOrderService $queueOrderService,
        private readonly ReviewStudyTimezoneService $timezoneService,
    ) {
    }

    /**
     * @return array{ordered_ids: list<int>, total_candidates: int}
     */
    public function build(
        SpecialStudyCriteria $criteria,
        int $userId,
        string $language,
        Carbon $now,
    ): array {
        $filters = $criteria->filters();
        $filterState = ReviewCardManageFilterState::fromArray([
            'filter' => 'all',
            'tag_ids' => $filters['tag_ids'],
            'fsrs_states' => $filters['fsrs_states'],
        ]);
        $searchCriteria = $this->manageQueryService
            ->parseCriteriaForState($filterState);
        $query = $this->manageQueryService->buildFromFilterState(
            $filterState,
            $searchCriteria,
            $userId,
            $language,
        );

        $query->whereIn(
            'review_cards.lifecycle_state',
            $filters['lifecycle_states'],
        );

        if ($criteria->get('execution_mode') !== SpecialStudyCriteria::MODE_PREVIEW) {
            $query->where('review_cards.fsrs_enabled', true)
                ->where('review_cards.lifecycle_state', ReviewCard::LIFECYCLE_ACTIVE)
                ->where(function (Builder $builder) use ($now) {
                    $builder->whereNull('review_cards.buried_until')
                        ->orWhere('review_cards.buried_until', '<=', $now);
                });
        }

        if ($filters['markers'] !== []) {
            $query->whereIn('review_cards.marker', $filters['markers']);
        }

        $this->applySourceFilters(
            $query,
            $filters,
            $userId,
            $language,
        );
        $this->applyScenario($query, $criteria, $userId, $language, $now);

        $cards = $query->get()->unique('id')->values();
        $orderedIds = $this->order(
            $cards,
            $criteria->get('sort'),
            $userId,
            $language,
            $now,
        );
        $totalCandidates = count($orderedIds);

        return [
            'ordered_ids' => array_slice(
                $orderedIds,
                0,
                $criteria->get('card_limit'),
            ),
            'total_candidates' => $totalCandidates,
        ];
    }

    private function applyScenario(
        Builder $query,
        SpecialStudyCriteria $criteria,
        int $userId,
        string $language,
        Carbon $now,
    ): void {
        switch ($criteria->get('scenario')) {
            case SpecialStudyCriteria::SCENARIO_TODAY_FORGOTTEN:
                $bounds = $this->timezoneService->dayBounds($now);
                $query->whereExists(function ($logQuery) use (
                    $userId,
                    $language,
                    $bounds,
                ) {
                    $logQuery->select(DB::raw(1))
                        ->from('review_logs')
                        ->whereColumn(
                            'review_logs.review_card_id',
                            'review_cards.id',
                        )
                        ->where('review_logs.user_id', $userId)
                        ->where('review_logs.language_id', $language)
                        ->where('review_logs.rating', 'again')
                        ->whereNull('review_logs.undone_at')
                        ->where(
                            'review_logs.reviewed_at',
                            '>=',
                            $bounds['day_start'],
                        )
                        ->where(
                            'review_logs.reviewed_at',
                            '<',
                            $bounds['next_day_start'],
                        );
                });
                break;

            case SpecialStudyCriteria::SCENARIO_BACKLOG:
                $query->whereNotNull('review_cards.fsrs_due_at')
                    ->where('review_cards.fsrs_due_at', '<', $now);
                break;

            case SpecialStudyCriteria::SCENARIO_REVIEW_AHEAD:
                $end = $this->timezoneService
                    ->dayStart($now)
                    ->addDays($criteria->get('days') + 1);
                $query->whereIn('review_cards.fsrs_state', ['review', 'relearning'])
                    ->where('review_cards.fsrs_due_at', '>', $now)
                    ->where('review_cards.fsrs_due_at', '<', $end);
                break;

            case SpecialStudyCriteria::SCENARIO_RECENT_NEW:
                $query->where('review_cards.fsrs_state', 'new')
                    ->where(
                        'review_cards.created_at',
                        '>=',
                        $now->copy()->subDays($criteria->get('days')),
                    );
                break;

            case SpecialStudyCriteria::SCENARIO_FILTERED:
                break;
        }
    }

    private function applySourceFilters(
        Builder $query,
        array $filters,
        int $userId,
        string $language,
    ): void {
        if (! $this->sourceIdsAreOwned(
            $filters,
            $userId,
            $language,
        )) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($filters['chapter_ids'] !== []) {
            $chapterIds = $filters['chapter_ids'];
            $query->where(function (Builder $sourceQuery) use (
                $chapterIds,
                $userId,
                $language,
            ) {
                $sourceQuery->whereHas(
                    'sense',
                    fn (Builder $senseQuery) =>
                        $senseQuery->whereIn('source_chapter_id', $chapterIds),
                )->orWhereExists(function ($occurrenceQuery) use (
                    $chapterIds,
                    $userId,
                    $language,
                ) {
                    $occurrenceQuery->select(DB::raw(1))
                        ->from('word_sense_occurrences')
                        ->whereColumn(
                            'word_sense_occurrences.word_sense_id',
                            'review_cards.target_id',
                        )
                        ->where('word_sense_occurrences.user_id', $userId)
                        ->where('word_sense_occurrences.language_id', $language)
                        ->where(
                            'word_sense_occurrences.status',
                            WordSenseOccurrence::STATUS_BOUND,
                        )
                        ->whereIn(
                            'word_sense_occurrences.chapter_id',
                            $chapterIds,
                        );
                });
            });
        }

        if ($filters['article_ids'] !== []) {
            $articleIds = $filters['article_ids'];
            $query->where(function (Builder $sourceQuery) use (
                $articleIds,
                $userId,
                $language,
            ) {
                $sourceQuery->whereHas('sense', function (Builder $senseQuery) use (
                    $articleIds,
                    $userId,
                    $language,
                ) {
                    $senseQuery->whereIn(
                        'source_chapter_id',
                        Chapter::query()
                            ->select('id')
                            ->where('user_id', $userId)
                            ->where('language', $language)
                            ->whereIn('book_id', $articleIds),
                    );
                })->orWhereExists(function ($occurrenceQuery) use (
                    $articleIds,
                    $userId,
                    $language,
                ) {
                    $occurrenceQuery->select(DB::raw(1))
                        ->from('word_sense_occurrences')
                        ->join(
                            'chapters',
                            'chapters.id',
                            '=',
                            'word_sense_occurrences.chapter_id',
                        )
                        ->whereColumn(
                            'word_sense_occurrences.word_sense_id',
                            'review_cards.target_id',
                        )
                        ->where('word_sense_occurrences.user_id', $userId)
                        ->where('word_sense_occurrences.language_id', $language)
                        ->where(
                            'word_sense_occurrences.status',
                            WordSenseOccurrence::STATUS_BOUND,
                        )
                        ->where('chapters.user_id', $userId)
                        ->where('chapters.language', $language)
                        ->whereIn('chapters.book_id', $articleIds);
                });
            });
        }
    }

    private function sourceIdsAreOwned(
        array $filters,
        int $userId,
        string $language,
    ): bool {
        if ($filters['article_ids'] !== []
            && Book::query()
                ->whereIn('id', $filters['article_ids'])
                ->where('user_id', $userId)
                ->where('language', $language)
                ->count() !== count($filters['article_ids'])) {
            return false;
        }

        return $filters['chapter_ids'] === []
            || Chapter::query()
                ->whereIn('id', $filters['chapter_ids'])
                ->where('user_id', $userId)
                ->where('language', $language)
                ->count() === count($filters['chapter_ids']);
    }

    /**
     * @param Collection<int, ReviewCard> $cards
     * @return list<int>
     */
    private function order(
        Collection $cards,
        string $sort,
        int $userId,
        string $language,
        Carbon $now,
    ): array {
        if ($cards->isEmpty()) {
            return [];
        }

        if ($sort === SpecialStudyCriteria::SORT_RANDOM) {
            $ids = $cards->pluck('id')->map(fn ($id) => (int) $id)->all();
            shuffle($ids);

            return $ids;
        }

        if ($sort === SpecialStudyCriteria::SORT_SOURCE) {
            return $this->orderBySource($cards, $userId, $language);
        }

        $items = $cards->map(function (ReviewCard $card) use ($sort, $now) {
            $value = match ($sort) {
                SpecialStudyCriteria::SORT_MOST_OVERDUE =>
                    $card->fsrs_due_at?->getTimestamp() ?? PHP_INT_MAX,
                SpecialStudyCriteria::SORT_MOST_LAPSES =>
                    -1 * (int) $card->fsrs_lapses,
                default =>
                    $this->queueOrderService->computeRetrievability($card, $now),
            };

            return ['id' => (int) $card->id, 'value' => $value];
        })->all();

        usort(
            $items,
            fn (array $left, array $right) =>
                ($left['value'] <=> $right['value'])
                ?: ($left['id'] <=> $right['id']),
        );

        return array_column($items, 'id');
    }

    /**
     * @param Collection<int, ReviewCard> $cards
     * @return list<int>
     */
    private function orderBySource(
        Collection $cards,
        int $userId,
        string $language,
    ): array {
        $cardIds = $cards->pluck('id')->all();
        $senseToCard = $cards->mapWithKeys(
            fn (ReviewCard $card) => [(int) $card->target_id => (int) $card->id],
        );
        $sourceChapterByCard = [];

        foreach ($cards as $card) {
            if ($card->sense?->source_chapter_id) {
                $sourceChapterByCard[(int) $card->id] =
                    (int) $card->sense->source_chapter_id;
            }
        }

        $occurrences = DB::table('word_sense_occurrences')
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('chapter_id')
            ->where(function ($query) use ($cardIds, $senseToCard) {
                $query->whereIn('review_card_id', $cardIds)
                    ->orWhereIn('word_sense_id', $senseToCard->keys()->all());
            })
            ->get(['review_card_id', 'word_sense_id', 'chapter_id']);

        foreach ($occurrences as $occurrence) {
            $cardId = $occurrence->review_card_id
                ? (int) $occurrence->review_card_id
                : $senseToCard->get((int) $occurrence->word_sense_id);
            if (! $cardId) {
                continue;
            }
            $chapterId = (int) $occurrence->chapter_id;
            $sourceChapterByCard[$cardId] = min(
                $sourceChapterByCard[$cardId] ?? PHP_INT_MAX,
                $chapterId,
            );
        }

        $chapters = Chapter::query()
            ->where('user_id', $userId)
            ->where('language', $language)
            ->whereIn('id', array_values($sourceChapterByCard))
            ->get(['id', 'book_id'])
            ->keyBy('id');
        $items = $cards->map(function (ReviewCard $card) use (
            $sourceChapterByCard,
            $chapters,
        ) {
            $chapterId = $sourceChapterByCard[(int) $card->id] ?? PHP_INT_MAX;
            $bookId = $chapters->get($chapterId)?->book_id ?? PHP_INT_MAX;

            return [
                'id' => (int) $card->id,
                'book_id' => (int) $bookId,
                'chapter_id' => (int) $chapterId,
            ];
        })->all();

        usort(
            $items,
            fn (array $left, array $right) =>
                ($left['book_id'] <=> $right['book_id'])
                ?: ($left['chapter_id'] <=> $right['chapter_id'])
                ?: ($left['id'] <=> $right['id']),
        );

        return array_column($items, 'id');
    }
}
