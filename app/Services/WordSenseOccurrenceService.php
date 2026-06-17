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

        return $query
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(min(max((int) Arr::get($filters, 'per_page', 20), 1), 100));
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
}
