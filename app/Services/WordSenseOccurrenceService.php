<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class WordSenseOccurrenceService
{
    public function __construct(
        private WordSenseService $wordSenseService,
        private ReviewCardService $reviewCardService,
    ) {
    }

    public function listOccurrences(int $userId, string $language, array $filters = []): LengthAwarePaginator
    {
        $query = WordSenseOccurrence::query()
            ->with(['wordSense.reviewCard'])
            ->where('user_id', $userId)
            ->where('language_id', $language);

        foreach (['status', 'lemma', 'decision'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (Arr::get($filters, 'confidence_min') !== null && Arr::get($filters, 'confidence_min') !== '') {
            $query->where('confidence', '>=', (float) Arr::get($filters, 'confidence_min'));
        }

        if (Arr::get($filters, 'auto_fsrs_allowed') !== null && Arr::get($filters, 'auto_fsrs_allowed') !== '') {
            $query->where('auto_fsrs_allowed', filter_var(Arr::get($filters, 'auto_fsrs_allowed'), FILTER_VALIDATE_BOOLEAN));
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(min(max((int) Arr::get($filters, 'per_page', 20), 1), 100));
    }

    public function bulkConfirm(int $userId, string $language, array $occurrenceIds, bool $autoFsrsAllowed = false): array
    {
        return $this->bulkByIds($userId, $language, $occurrenceIds, function (WordSenseOccurrence $occurrence, array &$summary) use ($autoFsrsAllowed) {
            if (!in_array($occurrence->status, [WordSenseOccurrence::STATUS_PENDING, WordSenseOccurrence::STATUS_BOUND], true)) {
                $this->skip($summary, "Occurrence {$occurrence->id}: status {$occurrence->status} cannot be confirmed.");
                return;
            }

            $this->confirmExistingBinding($occurrence, $autoFsrsAllowed, $summary);
        });
    }

    public function bulkIgnore(int $userId, string $language, array $occurrenceIds): array
    {
        return $this->bulkByIds($userId, $language, $occurrenceIds, function (WordSenseOccurrence $occurrence, array &$summary) {
            $this->ignoreOccurrence($occurrence);
            $summary['processed_count']++;
            $summary['ignored_count']++;
        });
    }

    public function bulkReject(int $userId, string $language, array $occurrenceIds): array
    {
        return $this->bulkByIds($userId, $language, $occurrenceIds, function (WordSenseOccurrence $occurrence, array &$summary) {
            $this->rejectOccurrence($occurrence);
            $summary['processed_count']++;
            $summary['rejected_count']++;
        });
    }

    public function bulkConfirmHighConfidence(int $userId, string $language, array $filters = []): array
    {
        $confidenceMin = (float) Arr::get($filters, 'confidence_min', 0.90);
        $decision = Arr::get($filters, 'decision', 'match_existing_sense') ?: 'match_existing_sense';
        $query = WordSenseOccurrence::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('decision', $decision)
            ->where('decision', 'match_existing_sense')
            ->where('confidence', '>=', $confidenceMin)
            ->whereIn('status', [WordSenseOccurrence::STATUS_PENDING, WordSenseOccurrence::STATUS_BOUND])
            ->whereNotNull('word_sense_id')
            ->with('wordSense');

        if (!empty($filters['lemma'])) {
            $query->where('lemma', $filters['lemma']);
        }

        if ((bool) Arr::get($filters, 'only_auto_fsrs_allowed', false)) {
            $query->where('auto_fsrs_allowed', true);
        }

        $occurrences = $query->get();
        $summary = $this->emptyBulkSummary($occurrences->count());

        foreach ($occurrences as $occurrence) {
            $this->confirmExistingBinding($occurrence, $occurrence->auto_fsrs_allowed, $summary);
        }

        return $summary;
    }

    public function possibleDuplicates(int $userId, string $language, ?string $lemma = null): array
    {
        $senses = WordSense::query()
            ->with('reviewCard')
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->when($lemma !== null && $lemma !== '', fn ($query) => $query->where('lemma', mb_strtolower(trim($lemma))))
            ->where('status', '<>', WordSense::STATUS_REJECTED)
            ->orderBy('lemma')
            ->orderBy('pos')
            ->orderBy('id')
            ->get();

        $groups = [];
        foreach ($senses->groupBy('lemma') as $lemmaValue => $lemmaSenses) {
            $lemmaSenses = $lemmaSenses->values();
            for ($i = 0; $i < $lemmaSenses->count(); $i++) {
                for ($j = $i + 1; $j < $lemmaSenses->count(); $j++) {
                    $first = $lemmaSenses[$i];
                    $second = $lemmaSenses[$j];
                    if (!$this->sameOrEmptyPos($first->pos, $second->pos)) {
                        continue;
                    }

                    if ($this->normalize($first->sense_zh) !== $this->normalize($second->sense_zh)
                        && count(array_intersect($first->aliases_zh ?: [], $second->aliases_zh ?: [])) === 0) {
                        continue;
                    }

                    $key = implode('|', [$lemmaValue, $first->pos ?: $second->pos ?: '', $this->normalize($first->sense_zh)]);
                    $groups[$key]['lemma'] = $lemmaValue;
                    $groups[$key]['pos'] = $first->pos ?: $second->pos;
                    $groups[$key]['senses'][$first->id] = $first;
                    $groups[$key]['senses'][$second->id] = $second;
                }
            }
        }

        return array_values(array_map(function (array $group) {
            return [
                'lemma' => $group['lemma'],
                'pos' => $group['pos'],
                'senses' => array_values(array_map(fn (WordSense $sense) => [
                    'sense_id' => $sense->id,
                    'lemma' => $sense->lemma,
                    'pos' => $sense->pos,
                    'sense_zh' => $sense->sense_zh,
                    'aliases_zh' => $sense->aliases_zh ?: [],
                    'example_sentence_en' => $sense->example_sentence_en,
                    'review_card' => $sense->reviewCard ? [
                        'id' => $sense->reviewCard->id,
                        'fsrs_state' => $sense->reviewCard->fsrs_state,
                        'fsrs_enabled' => $sense->reviewCard->fsrs_enabled,
                    ] : null,
                ], $group['senses'])),
            ];
        }, $groups));
    }

    public function statusSummary(int $userId, string $language): array
    {
        $counts = WordSenseOccurrence::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            WordSenseOccurrence::STATUS_PENDING => (int) ($counts[WordSenseOccurrence::STATUS_PENDING] ?? 0),
            WordSenseOccurrence::STATUS_BOUND => (int) ($counts[WordSenseOccurrence::STATUS_BOUND] ?? 0),
            WordSenseOccurrence::STATUS_IGNORED => (int) ($counts[WordSenseOccurrence::STATUS_IGNORED] ?? 0),
            WordSenseOccurrence::STATUS_REJECTED => (int) ($counts[WordSenseOccurrence::STATUS_REJECTED] ?? 0),
        ];
    }

    public function candidates(int $userId, string $language, string $lemma, ?string $pos = null)
    {
        return WordSense::query()
            ->with('reviewCard')
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('lemma', mb_strtolower(trim($lemma)))
            ->where('status', '<>', WordSense::STATUS_REJECTED)
            ->when($pos !== null && $pos !== '', fn ($query) => $query->where('pos', $pos))
            ->orderByRaw("case when status = ? then 0 else 1 end", [WordSense::STATUS_CONFIRMED])
            ->orderBy('id')
            ->get();
    }

    public function confirmOccurrence(WordSenseOccurrence $occurrence): WordSenseOccurrence
    {
        $sense = $occurrence->wordSense ?: $this->createSenseFromOccurrence($occurrence);
        $this->wordSenseService->confirmSense($sense);

        return $this->bindOccurrenceToSense($occurrence, $sense, $occurrence->auto_fsrs_allowed);
    }

    public function rejectOccurrence(WordSenseOccurrence $occurrence): WordSenseOccurrence
    {
        $occurrence->fill([
            'status' => WordSenseOccurrence::STATUS_REJECTED,
            'auto_fsrs_allowed' => false,
            'review_card_id' => null,
        ])->save();

        return $occurrence->refresh();
    }

    public function ignoreOccurrence(WordSenseOccurrence $occurrence): WordSenseOccurrence
    {
        $occurrence->fill([
            'status' => WordSenseOccurrence::STATUS_IGNORED,
            'auto_fsrs_allowed' => false,
            'review_card_id' => null,
        ])->save();

        return $occurrence->refresh();
    }

    public function bindOccurrenceToSense(WordSenseOccurrence $occurrence, WordSense $sense, bool $enableFsrs = false): WordSenseOccurrence
    {
        $this->assertSameScope($occurrence, $sense);

        $occurrence->fill([
            'word_sense_id' => $sense->id,
            'status' => WordSenseOccurrence::STATUS_BOUND,
        ]);

        if ($enableFsrs) {
            $card = $this->enableFsrsForSense($sense);
            $occurrence->review_card_id = $card->id;
            $occurrence->auto_fsrs_allowed = true;
        }

        $occurrence->save();

        return $occurrence->refresh();
    }

    public function createSenseFromOccurrence(WordSenseOccurrence $occurrence, array $overrides = []): WordSense
    {
        if ($occurrence->type !== WordSenseOccurrence::TYPE_WORD) {
            throw ValidationException::withMessages([
                'occurrence' => 'Only word occurrences can create word senses in this phase.',
            ]);
        }

        return $this->wordSenseService->createSense(array_merge([
            'user_id' => $occurrence->user_id,
            'language' => $occurrence->language,
            'language_id' => $occurrence->language_id,
            'lemma' => $occurrence->lemma,
            'surface_form' => $occurrence->surface,
            'pos' => $occurrence->pos,
            'sense_zh' => $occurrence->raw_payload['sense_zh'] ?? '',
            'sense_en' => $occurrence->raw_payload['sense_en'] ?? null,
            'aliases_zh' => $occurrence->raw_payload['aliases_zh'] ?? [],
            'collocations' => $occurrence->raw_payload['collocations'] ?? [],
            'example_sentence_en' => $occurrence->sentence_en,
            'example_sentence_zh' => $occurrence->sentence_zh,
            'source_text_id' => $occurrence->text_id,
            'source_chapter_id' => $occurrence->chapter_id,
            'sentence_id' => $occurrence->sentence_id,
            'sentence_hash' => $occurrence->sentence_hash,
            'status' => WordSense::STATUS_AI_SUGGESTED,
        ], $overrides));
    }

    public function createConfirmedSenseFromOccurrence(WordSenseOccurrence $occurrence, array $data, bool $enableFsrs = false): WordSenseOccurrence
    {
        $sense = $this->createSenseFromOccurrence($occurrence, [
            'sense_zh' => Arr::get($data, 'sense_zh', ''),
            'sense_en' => Arr::get($data, 'sense_en'),
            'pos' => Arr::get($data, 'pos', $occurrence->pos),
            'aliases_zh' => Arr::get($data, 'aliases_zh', []),
            'collocations' => Arr::get($data, 'collocations', []),
            'status' => WordSense::STATUS_CONFIRMED,
        ]);

        return $this->bindOccurrenceToSense($occurrence, $sense, $enableFsrs);
    }

    public function enableFsrsForConfirmedOccurrence(WordSenseOccurrence $occurrence): ?ReviewCard
    {
        $sense = $occurrence->wordSense;
        if (!$sense || $sense->status !== WordSense::STATUS_CONFIRMED) {
            return null;
        }

        $card = $this->enableFsrsForSense($sense);
        $occurrence->fill([
            'review_card_id' => $card->id,
            'auto_fsrs_allowed' => true,
            'status' => WordSenseOccurrence::STATUS_BOUND,
        ])->save();

        return $card;
    }

    private function enableFsrsForSense(WordSense $sense): ReviewCard
    {
        if ($sense->status !== WordSense::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'sense' => 'Only confirmed senses can enter FSRS review.',
            ]);
        }

        return $this->reviewCardService->ensureSenseCard($sense);
    }

    private function assertSameScope(WordSenseOccurrence $occurrence, WordSense $sense): void
    {
        if ($occurrence->user_id !== $sense->user_id || $occurrence->language_id !== $sense->language_id) {
            throw ValidationException::withMessages([
                'sense' => 'The sense does not belong to the occurrence user and language.',
            ]);
        }
    }

    private function bulkByIds(int $userId, string $language, array $occurrenceIds, callable $callback): array
    {
        $ids = array_values(array_unique(array_map('intval', $occurrenceIds)));
        $summary = $this->emptyBulkSummary(count($ids));
        $occurrences = WordSenseOccurrence::query()
            ->with('wordSense')
            ->whereIn('id', $ids)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $occurrence = $occurrences->get($id);
            if (!$occurrence) {
                $this->skip($summary, "Occurrence {$id}: not found for current user and language.");
                continue;
            }

            $callback($occurrence, $summary);
        }

        return $summary;
    }

    private function confirmExistingBinding(WordSenseOccurrence $occurrence, bool $enableFsrs, array &$summary): void
    {
        $sense = $occurrence->wordSense;
        if (!$sense) {
            $this->skip($summary, "Occurrence {$occurrence->id}: no sense is bound.");
            return;
        }

        if ($sense->status === WordSense::STATUS_REJECTED) {
            $this->skip($summary, "Occurrence {$occurrence->id}: rejected sense cannot be confirmed.");
            return;
        }

        if ($sense->status === WordSense::STATUS_AI_SUGGESTED) {
            $this->wordSenseService->confirmSense($sense);
        }

        $hadCard = ReviewCard::where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->where('target_id', $sense->id)
            ->exists();

        $this->bindOccurrenceToSense($occurrence, $sense, $enableFsrs);

        if ($enableFsrs && !$hadCard) {
            $summary['created_review_cards']++;
        }

        $summary['processed_count']++;
        $summary['confirmed_count']++;
    }

    private function emptyBulkSummary(int $requestedCount): array
    {
        return [
            'requested_count' => $requestedCount,
            'processed_count' => 0,
            'skipped_count' => 0,
            'confirmed_count' => 0,
            'ignored_count' => 0,
            'rejected_count' => 0,
            'created_review_cards' => 0,
            'errors' => [],
        ];
    }

    private function skip(array &$summary, string $error): void
    {
        $summary['skipped_count']++;
        $summary['errors'][] = $error;
    }

    private function sameOrEmptyPos(?string $first, ?string $second): bool
    {
        return $first === $second || $first === null || $first === '' || $second === null || $second === '';
    }

    private function normalize(?string $value): string
    {
        return trim(mb_strtolower((string) $value));
    }
}
