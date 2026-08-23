<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ReadingSession;
use App\Models\ReadingSessionCardSettlement;
use App\Models\ReadingSessionInteraction;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LearningHistoryQueryService
{
    public const FILTER_ALL = 'all';
    public const FILTER_NEW_LEARNING = 'new_learning';
    public const FILTER_READING_REVIEW = 'reading_review';
    public const FILTER_FORMAL_REVIEW = 'formal_review';
    public const FILTERS = [
        self::FILTER_ALL,
        self::FILTER_NEW_LEARNING,
        self::FILTER_READING_REVIEW,
        self::FILTER_FORMAL_REVIEW,
    ];

    public function __construct(private ReviewStudyTimezoneService $timezoneService)
    {
    }

    public function countReadingSensesStartedToday(
        int $userId,
        string $language,
        ?Carbon $now = null,
    ): int {
        $bounds = $this->timezoneService->dayBounds($now ?: Carbon::now());

        return $this->readingLearningQuery($userId, $language, $bounds['day_start'], $bounds['next_day_start'])
            ->count();
    }

    /** @return array{data:array<int,array>,pagination:array,meta:array} */
    public function paginate(
        int $userId,
        string $language,
        string $dateFrom,
        string $dateTo,
        string $filter = self::FILTER_ALL,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $bounds = $this->timezoneService->inclusiveDateRangeBounds($dateFrom, $dateTo);
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);
        $events = $this->eventIdentifiersQuery($userId, $language, $bounds, $filter);
        $total = (clone $events)->count();
        $identifiers = $this->orderEventIdentifiers($events)
            ->forPage($page, $perPage)
            ->get();
        $currentStateAsOf = Carbon::now();

        return [
            'data' => $this->hydrateRows($identifiers, $userId, $language, $currentStateAsOf),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'meta' => $this->historyMeta($bounds, $filter, $currentStateAsOf) + [
                'daily_reading_counts' => $this->dailyReadingCounts(
                    $userId,
                    $language,
                    $bounds['range_start'],
                    $bounds['range_end'],
                ),
            ],
        ];
    }

    /** @return array{data:array<int,array>,meta:array} */
    public function all(
        int $userId,
        string $language,
        string $dateFrom,
        string $dateTo,
        string $filter = self::FILTER_ALL,
    ): array {
        $bounds = $this->timezoneService->inclusiveDateRangeBounds($dateFrom, $dateTo);
        $identifiers = $this->orderEventIdentifiers(
            $this->eventIdentifiersQuery($userId, $language, $bounds, $filter)
        )->get();
        $currentStateAsOf = Carbon::now();

        return [
            'data' => $this->hydrateRows($identifiers, $userId, $language, $currentStateAsOf),
            'meta' => $this->historyMeta($bounds, $filter, $currentStateAsOf),
        ];
    }

    /** @return array<string,int> */
    public function dailyReadingCounts(
        int $userId,
        string $language,
        Carbon $rangeStart,
        Carbon $rangeEnd,
    ): array {
        $counts = [];
        $this->readingLearningQuery($userId, $language, $rangeStart, $rangeEnd)
            ->pluck('learning_started_at')
            ->each(function ($occurredAt) use (&$counts): void {
                $studyDate = $this->timezoneService->localDate(Carbon::parse($occurredAt));
                $counts[$studyDate] = ($counts[$studyDate] ?? 0) + 1;
            });
        ksort($counts);

        return $counts;
    }

    private function readingLearningQuery(int $userId, string $language, Carbon $rangeStart, Carbon $rangeEnd)
    {
        return WordSense::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('learning_started_origin', WordSense::LEARNING_ORIGIN_READING)
            ->where('learning_started_at', '>=', $rangeStart)
            ->where('learning_started_at', '<', $rangeEnd);
    }

    private function eventIdentifiersQuery(int $userId, string $language, array $bounds, string $filter)
    {
        if (!in_array($filter, self::FILTERS, true)) {
            throw new \InvalidArgumentException('Unsupported learning history filter.');
        }

        $learning = DB::table('word_senses')
            ->selectRaw("'learning_entry' as event_type, 'learning_entry' as event_source, word_senses.id as source_id, word_senses.learning_started_at as occurred_at, 0 as event_rank")
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->whereNotNull('word_senses.learning_started_at')
            ->where('word_senses.learning_started_at', '>=', $bounds['range_start'])
            ->where('word_senses.learning_started_at', '<', $bounds['range_end']);
        if (!in_array($filter, [self::FILTER_ALL, self::FILTER_NEW_LEARNING], true)) {
            $learning->whereRaw('1 = 0');
        }

        $reviewSources = match ($filter) {
            self::FILTER_NEW_LEARNING => [],
            self::FILTER_READING_REVIEW => [
                ReviewLog::SOURCE_READING_PASSIVE,
                ReviewLog::SOURCE_READING_EXPLICIT,
            ],
            self::FILTER_FORMAL_REVIEW => [
                ReviewLog::SOURCE_SENSE_REVIEW,
                ReviewLog::SOURCE_SPECIAL_STUDY,
            ],
            default => ReviewLog::FORMAL_RATING_SOURCES,
        };
        $reviews = DB::table('review_logs')
            ->join('review_cards', 'review_cards.id', '=', 'review_logs.review_card_id')
            ->join('word_senses', 'word_senses.id', '=', 'review_cards.target_id')
            ->selectRaw("'review' as event_type, review_logs.source as event_source, review_logs.id as source_id, review_logs.reviewed_at as occurred_at, 1 as event_rank")
            ->where('review_logs.user_id', $userId)
            ->where('review_logs.language_id', $language)
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('review_cards.target_type', ReviewCard::TARGET_SENSE)
            ->where('word_senses.user_id', $userId)
            ->where('word_senses.language_id', $language)
            ->whereNull('review_logs.undone_at')
            ->whereIn('review_logs.rating', ['again', 'hard', 'good', 'easy'])
            ->where('review_logs.reviewed_at', '>=', $bounds['range_start'])
            ->where('review_logs.reviewed_at', '<', $bounds['range_end']);
        if ($reviewSources === []) {
            $reviews->whereRaw('1 = 0');
        } else {
            $reviews->whereIn('review_logs.source', $reviewSources);
        }

        return DB::query()->fromSub($learning->unionAll($reviews), 'learning_history_events');
    }

    private function orderEventIdentifiers($query)
    {
        return $query
            ->orderByDesc('occurred_at')
            ->orderBy('event_rank')
            ->orderByDesc('source_id');
    }

    private function historyMeta(array $bounds, string $filter, Carbon $currentStateAsOf): array
    {
        return [
            'date_from' => $bounds['date_from'],
            'date_to' => $bounds['date_to'],
            'study_timezone' => $bounds['timezone'],
            'filter' => $filter,
            'current_state_as_of' => $currentStateAsOf->toIso8601String(),
        ];
    }

    /** @return array<int,array> */
    private function hydrateRows(
        Collection $identifiers,
        int $userId,
        string $language,
        Carbon $currentStateAsOf,
    ): array {
        $learningIds = $identifiers->where('event_type', 'learning_entry')->pluck('source_id')->map(fn ($id) => (int) $id);
        $reviewIds = $identifiers->where('event_type', 'review')->pluck('source_id')->map(fn ($id) => (int) $id);
        $learningSenses = WordSense::query()
            ->with(['reviewCard', 'learningStartedSourceOccurrence'])
            ->where('user_id', $userId)->where('language_id', $language)
            ->whereIn('id', $learningIds)->get()->keyBy('id');
        $reviewLogs = ReviewLog::query()
            ->with(['card.sense'])
            ->where('user_id', $userId)->where('language_id', $language)
            ->whereIn('id', $reviewIds)->get()->keyBy('id');

        $sourceContext = $this->reviewSourceContext($reviewLogs, $userId, $language);
        $chapterIds = $learningSenses->pluck('learningStartedSourceOccurrence.chapter_id')
            ->merge(collect($sourceContext)->pluck('chapter_id'))
            ->filter()->unique()->values();
        $chapters = Chapter::query()
            ->where('user_id', $userId)
            ->where('language', $language)
            ->whereIn('id', $chapterIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        return $identifiers->map(function ($identifier) use (
            $learningSenses,
            $reviewLogs,
            $sourceContext,
            $chapters,
            $currentStateAsOf,
            $userId,
            $language,
        ) {
            if ($identifier->event_type === 'learning_entry') {
                $sense = $learningSenses->get((int) $identifier->source_id);
                if (!$sense) {
                    return null;
                }
                $occurrence = $sense->learningStartedSourceOccurrence;
                if ($occurrence && (
                    (int) $occurrence->user_id !== $userId
                    || (string) $occurrence->language_id !== $language
                    || (int) $occurrence->word_sense_id !== (int) $sense->id
                )) {
                    $occurrence = null;
                }
                $context = $occurrence ? $this->occurrenceContext($occurrence) : $this->emptySourceContext();

                return $this->row(
                    'learning:'.$sense->id,
                    'learning_entry',
                    'learning_entry',
                    $sense->learning_started_origin,
                    $sense->learning_started_at,
                    $sense,
                    $sense->reviewCard,
                    null,
                    $context,
                    $chapters,
                    $currentStateAsOf,
                );
            }

            $log = $reviewLogs->get((int) $identifier->source_id);
            $sense = $log?->card?->sense;
            if (!$log || !$sense) {
                return null;
            }

            return $this->row(
                'review:'.$log->id,
                'review',
                $log->source,
                null,
                $log->reviewed_at,
                $sense,
                $log->card,
                $log,
                $sourceContext[$log->id] ?? $this->emptySourceContext(),
                $chapters,
                $currentStateAsOf,
            );
        })->filter()->values()->all();
    }

    /** @return array<int,array> */
    private function reviewSourceContext(Collection $reviewLogs, int $userId, string $language): array
    {
        $readingIds = $reviewLogs->filter(fn (ReviewLog $log) => in_array($log->source, [
            ReviewLog::SOURCE_READING_EXPLICIT,
            ReviewLog::SOURCE_READING_PASSIVE,
        ], true))->keys();
        if ($readingIds->isEmpty()) {
            return [];
        }

        $interactions = ReadingSessionInteraction::query()
            ->where('user_id', $userId)->where('language_id', $language)
            ->whereIn('review_log_id', $readingIds)->get()->keyBy('review_log_id');
        $settlements = ReadingSessionCardSettlement::query()
            ->where('user_id', $userId)->where('language_id', $language)
            ->whereIn('review_log_id', $readingIds)->get()->keyBy('review_log_id');
        $sessionIds = $interactions->pluck('reading_session_id')
            ->merge($settlements->pluck('reading_session_id'))->unique();
        $sessions = ReadingSession::query()
            ->where('user_id', $userId)->where('language_id', $language)
            ->whereIn('id', $sessionIds)->get()->keyBy('id');
        $chapterIds = $sessions->pluck('chapter_id')->filter()->unique();
        $senseIds = $reviewLogs->map(fn (ReviewLog $log) => $log->card?->sense?->id)->filter()->unique();
        $occurrences = WordSenseOccurrence::query()
            ->where('user_id', $userId)->where('language_id', $language)
            ->where('source', WordSenseOccurrence::SOURCE_READING_OCCURRENCE)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereIn('chapter_id', $chapterIds)
            ->whereIn('word_sense_id', $senseIds)
            ->get()
            ->groupBy(fn (WordSenseOccurrence $row) => $row->chapter_id.'|'.$row->word_sense_id);

        $contexts = [];
        foreach ($reviewLogs as $log) {
            if ($log->source === ReviewLog::SOURCE_READING_EXPLICIT) {
                $interaction = $interactions->get($log->id);
                $session = $interaction ? $sessions->get($interaction->reading_session_id) : null;
                $context = $this->chapterContext($session?->chapter_id);
                $metadata = $interaction?->metadata ?: [];
                $metadataMatchesSession = $interaction && $session
                    && (int) ($metadata['chapter_id'] ?? 0) === (int) $session->chapter_id
                    && (string) ($metadata['source_revision'] ?? '') === (string) $session->source_revision;
                if ($metadataMatchesSession) {
                    $matching = $occurrences->get($session->chapter_id.'|'.$log->card->sense->id, collect())
                        ->first(function (WordSenseOccurrence $occurrence) use ($interaction, $session, $metadata) {
                            $evidence = $occurrence->evidence ?: [];
                            return (string) ($evidence['reading_occurrence_id'] ?? '') === (string) $interaction->occurrence_id
                                && (string) ($evidence['source_revision'] ?? '') === (string) $session->source_revision
                                && (int) ($evidence['sentence_index'] ?? -1) === (int) ($metadata['sentence_index'] ?? -2);
                        });
                    if ($matching) {
                        $context = $this->occurrenceContext($matching);
                    }
                }
                $contexts[$log->id] = $context;
                continue;
            }

            if ($log->source === ReviewLog::SOURCE_READING_PASSIVE) {
                $settlement = $settlements->get($log->id);
                $session = $settlement ? $sessions->get($settlement->reading_session_id) : null;
                $context = $this->chapterContext($session?->chapter_id);
                if ($session) {
                    $matching = $occurrences->get($session->chapter_id.'|'.$log->card->sense->id, collect())
                        ->filter(fn (WordSenseOccurrence $occurrence) => (string) (($occurrence->evidence ?: [])['source_revision'] ?? '') === (string) $session->source_revision)
                        ->values();
                    if ($matching->count() === 1) {
                        $context = $this->occurrenceContext($matching->first());
                    }
                }
                $contexts[$log->id] = $context;
            }
        }

        return $contexts;
    }

    private function row(
        string $eventKey,
        string $eventType,
        string $eventSource,
        ?string $learningOrigin,
        Carbon $occurredAt,
        WordSense $sense,
        ?ReviewCard $card,
        ?ReviewLog $log,
        array $context,
        Collection $chapters,
        Carbon $currentStateAsOf,
    ): array {
        $chapterId = $context['chapter_id'];

        return [
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'event_source' => $eventSource,
            'learning_origin' => $learningOrigin,
            'occurred_at' => $occurredAt->toIso8601String(),
            'study_date' => $this->timezoneService->localDate($occurredAt),
            'word_sense_id' => (int) $sense->id,
            'review_card_id' => $card?->id,
            'review_log_id' => $log?->id,
            'rating' => $log?->rating,
            'lemma' => $sense->lemma,
            'surface_form' => $sense->surface_form,
            'pos' => $sense->pos,
            'sense_zh' => $sense->sense_zh,
            'sense_en' => $sense->sense_en,
            'source_accuracy' => $context['accuracy'],
            'source_occurrence_id' => $context['source_occurrence_id'],
            'chapter_id' => $chapterId,
            'chapter_title' => $chapterId ? $chapters->get($chapterId)?->name : null,
            'sentence_id' => $context['sentence_id'],
            'sentence_en' => $context['sentence_en'],
            'current_fsrs_state' => $card?->fsrs_state,
            'current_fsrs_due_at' => $card?->fsrs_due_at?->toIso8601String(),
            'current_stability' => $card?->fsrs_stability,
            'current_difficulty' => $card?->fsrs_difficulty,
            'current_reps' => $card?->fsrs_reps,
            'current_lapses' => $card?->fsrs_lapses,
            'current_lifecycle_state' => $card?->lifecycle_state,
            'current_state_as_of' => $currentStateAsOf->toIso8601String(),
        ];
    }

    private function emptySourceContext(): array
    {
        return [
            'accuracy' => 'unavailable',
            'source_occurrence_id' => null,
            'chapter_id' => null,
            'sentence_id' => null,
            'sentence_en' => null,
        ];
    }

    private function chapterContext(?int $chapterId): array
    {
        return array_merge($this->emptySourceContext(), [
            'accuracy' => $chapterId ? 'exact_chapter' : 'unavailable',
            'chapter_id' => $chapterId,
        ]);
    }

    private function occurrenceContext(WordSenseOccurrence $occurrence): array
    {
        return [
            'accuracy' => 'exact_occurrence',
            'source_occurrence_id' => (int) $occurrence->id,
            'chapter_id' => $occurrence->chapter_id ? (int) $occurrence->chapter_id : null,
            'sentence_id' => $occurrence->sentence_id,
            'sentence_en' => $occurrence->sentence_en,
        ];
    }
}
