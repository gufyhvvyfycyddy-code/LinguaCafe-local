<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WordSenseService
{
    public function __construct(private ReviewCardService $reviewCardService)
    {
    }

    public function createSense(array $data): WordSense
    {
        $data = $this->normalizeSenseData($data);

        return WordSense::create($data);
    }

    public function findBySenseKey(int $userId, string $language, string $senseKey): ?WordSense
    {
        return WordSense::where('user_id', $userId)
            ->where('language_id', $language)
            ->where('sense_key', $senseKey)
            ->first();
    }

    public function findByAlias(int $userId, string $language, string $alias): ?WordSense
    {
        $normalizedAlias = $this->normalizeText($alias);

        return WordSense::where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', '<>', WordSense::STATUS_REJECTED)
            ->get()
            ->first(function (WordSense $sense) use ($normalizedAlias) {
                foreach ($sense->aliases_zh ?: [] as $alias) {
                    if ($this->normalizeText($alias) === $normalizedAlias) {
                        return true;
                    }
                }

                return false;
            });
    }

    public function createOrFindSense(array $data): WordSense
    {
        $data = $this->normalizeSenseData($data);
        $existing = $this->findBySenseKey($data['user_id'], $data['language_id'], $data['sense_key']);

        if ($existing) {
            return $existing;
        }

        foreach ($data['aliases_zh'] ?: [] as $alias) {
            $existing = $this->findByAlias($data['user_id'], $data['language_id'], $alias);
            if ($existing) {
                return $existing;
            }
        }

        return WordSense::create($data);
    }

    public function createReviewCardForSense(WordSense $sense): ?ReviewCard
    {
        return $this->reviewCardService->ensureSenseCard($sense);
    }

    public function rejectSense(WordSense $sense): WordSense
    {
        $sense->status = WordSense::STATUS_REJECTED;
        $sense->save();

        return $sense;
    }

    public function archiveSense(WordSense $sense): WordSense
    {
        return DB::transaction(function () use ($sense) {
            $sense->status = WordSense::STATUS_REJECTED;
            $sense->save();

            // Disable FSRS review card without deleting history
            if ($card = $sense->reviewCard) {
                $card->fsrs_enabled = false;
                $card->save();
            }

            return $sense->fresh('reviewCard');
        });
    }

    public function confirmSense(WordSense $sense): WordSense
    {
        $sense->status = WordSense::STATUS_CONFIRMED;
        $sense->save();

        return $sense;
    }

    public function createManualSense(int $userId, string $language, array $data): WordSense
    {
        return DB::transaction(function () use ($userId, $language, $data) {
            $sense = $this->createSense([
                'user_id' => $userId,
                'language' => $language,
                'language_id' => $language,
                'lemma' => Arr::get($data, 'lemma'),
                'surface_form' => Arr::get($data, 'surface_form', Arr::get($data, 'lemma')),
                'pos' => Arr::get($data, 'pos'),
                'sense_zh' => Arr::get($data, 'sense_zh'),
                'sense_en' => Arr::get($data, 'sense_en'),
                'aliases_zh' => Arr::get($data, 'aliases_zh', []),
                'collocations' => Arr::get($data, 'collocations', []),
                'example_sentence_en' => Arr::get($data, 'sentence_en'),
                'example_sentence_zh' => Arr::get($data, 'sentence_zh'),
                'source_chapter_id' => Arr::get($data, 'chapter_id'),
                'sentence_id' => Arr::get($data, 'sentence_id'),
                'status' => WordSense::STATUS_CONFIRMED,
            ]);

            $card = $this->createReviewCardForSense($sense);
            $this->createManualOccurrence($sense, $card, $data);

            return $sense->fresh('reviewCard');
        });
    }

    public function updateManualSense(int $userId, string $language, int $senseId, array $data): WordSense
    {
        return DB::transaction(function () use ($userId, $language, $senseId, $data) {
            $sense = WordSense::where('id', $senseId)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->firstOrFail();

            $sense->fill([
                'pos' => Arr::get($data, 'pos', $sense->pos),
                'sense_zh' => Arr::get($data, 'sense_zh', $sense->sense_zh),
                'sense_en' => Arr::get($data, 'sense_en', $sense->sense_en),
                'aliases_zh' => $this->normalizeArray(Arr::get($data, 'aliases_zh', $sense->aliases_zh ?: [])),
                'collocations' => $this->normalizeArray(Arr::get($data, 'collocations', $sense->collocations ?: [])),
                'status' => WordSense::STATUS_CONFIRMED,
            ]);
            $sense->save();

            $this->createReviewCardForSense($sense);

            return $sense->fresh('reviewCard');
        });
    }

    private function createManualOccurrence(WordSense $sense, ?ReviewCard $card, array $data): void
    {
        $sentenceId = Arr::get($data, 'sentence_id');
        $sentenceEn = trim((string) Arr::get($data, 'sentence_en', ''));

        if ($sentenceId === null || $sentenceEn === '') {
            return;
        }

        WordSenseOccurrence::updateOrCreate(
            [
                'user_id' => $sense->user_id,
                'language_id' => $sense->language_id,
                'word_sense_id' => $sense->id,
                'chapter_id' => Arr::get($data, 'chapter_id'),
                'sentence_id' => (string) $sentenceId,
                'surface' => Arr::get($data, 'surface_form', $sense->surface_form ?: $sense->lemma),
                'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            ],
            [
                'language' => $sense->language,
                'review_card_id' => $card?->id,
                'sentence_en' => $sentenceEn,
                'sentence_zh' => Arr::get($data, 'sentence_zh'),
                'type' => WordSenseOccurrence::TYPE_WORD,
                'lemma' => $sense->lemma,
                'pos' => $sense->pos,
                'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
                'confidence' => 1.0,
                'evidence' => ['source' => 'manual reading page add'],
                'auto_fsrs_allowed' => true,
                'status' => WordSenseOccurrence::STATUS_BOUND,
                'raw_payload' => [
                    'sense_zh' => $sense->sense_zh,
                    'sense_en' => $sense->sense_en,
                    'aliases_zh' => $sense->aliases_zh ?: [],
                    'collocations' => $sense->collocations ?: [],
                ],
            ]
        );
    }

    private function normalizeSenseData(array $data): array
    {
        $language = Arr::get($data, 'language_id', Arr::get($data, 'language'));
        $data['language'] = $language;
        $data['language_id'] = $language;
        $data['lemma'] = $this->normalizeText($data['lemma']);
        $data['surface_form'] = Arr::get($data, 'surface_form', $data['lemma']);
        $data['pos'] = Arr::get($data, 'pos');
        $data['aliases_zh'] = $this->normalizeArray(Arr::get($data, 'aliases_zh', []));
        $data['collocations'] = $this->normalizeArray(Arr::get($data, 'collocations', []));
        $data['status'] = Arr::get($data, 'status', WordSense::STATUS_CONFIRMED);
        $data['is_context_specific'] = Arr::get($data, 'is_context_specific', true);
        $data['sense_key'] = Arr::get($data, 'sense_key') ?: $this->generateSenseKey($data);

        return $data;
    }

    private function generateSenseKey(array $data): string
    {
        $parts = [
            $data['language_id'],
            $data['lemma'],
            $data['pos'] ?: '',
            Arr::get($data, 'sense_zh', ''),
            Arr::get($data, 'sense_en', ''),
        ];

        return hash('sha256', Str::lower(implode('|', array_map([$this, 'normalizeText'], $parts))));
    }

    private function normalizeArray(mixed $values): array
    {
        if (!is_array($values)) {
            $values = explode(',', (string) $values);
        }

        return array_values(array_filter(array_map([$this, 'normalizeText'], $values), fn ($value) => $value !== ''));
    }

    private function normalizeText(?string $value): string
    {
        return trim(mb_strtolower((string) $value));
    }
}
