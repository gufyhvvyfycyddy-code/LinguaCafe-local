<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SenseMappingImportService
{
    public function __construct(
        private SenseMappingValidationService $validator,
        private WordSenseService $wordSenseService,
        private WordSenseOccurrenceService $occurrenceService,
    ) {
    }

    public function importFile(string $path, int $userId, string $language, bool $dryRun = false): array
    {
        $validation = $this->validator->validateFile($path, $userId, $language);
        $summary = $this->emptySummary($this->countMatches($validation['items'] ?? []), $validation['errors']);

        if (!$validation['valid']) {
            return $summary;
        }

        $runner = function () use ($validation, $userId, $language, $dryRun, &$summary) {
            foreach ($validation['items'] as $itemIndex => $item) {
                foreach ($item['matches'] as $matchIndex => $match) {
                    $this->importMatch($item, $match, $itemIndex, $matchIndex, $userId, $language, $dryRun, $summary);
                }
            }
        };

        if ($dryRun) {
            $runner();

            return $summary;
        }

        DB::transaction($runner);

        return $summary;
    }

    private function importMatch(array $item, array $match, int $itemIndex, int $matchIndex, int $userId, string $language, bool $dryRun, array &$summary): void
    {
        $decision = $match['decision'];
        $confidence = (float) $match['confidence'];

        match ($decision) {
            'match_existing_sense' => $this->importExistingSenseMatch($item, $match, $confidence, $userId, $language, $dryRun, $summary),
            'new_sense' => $this->importNewSenseMatch($item, $match, $userId, $language, $dryRun, $summary),
            'uncertain' => $this->importPendingOccurrence($item, $match, $userId, $language, $dryRun, $summary, WordSenseOccurrence::STATUS_PENDING, WordSenseOccurrence::TYPE_WORD),
            'ignore' => $this->importPendingOccurrence($item, $match, $userId, $language, $dryRun, $summary, WordSenseOccurrence::STATUS_IGNORED, WordSenseOccurrence::TYPE_WORD),
            'phrase_match' => $this->importPendingOccurrence($item, $match, $userId, $language, $dryRun, $summary, WordSenseOccurrence::STATUS_PENDING, WordSenseOccurrence::TYPE_PHRASE),
            default => $summary['errors'][] = "Item {$itemIndex} match {$matchIndex}: decision is invalid.",
        };
    }

    private function importExistingSenseMatch(array $item, array $match, float $confidence, int $userId, string $language, bool $dryRun, array &$summary): void
    {
        $sense = WordSense::where('id', (int) $match['matched_sense_id'])
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->first();

        if (!$sense) {
            $summary['errors'][] = 'matched_sense_id does not belong to the current user and language.';

            return;
        }

        $isBound = $confidence >= 0.90;
        $canAutoFsrs = $isBound
            && ($match['auto_fsrs_allowed'] ?? false) === true
            && $sense->status === WordSense::STATUS_CONFIRMED;
        $status = $isBound ? WordSenseOccurrence::STATUS_BOUND : WordSenseOccurrence::STATUS_PENDING;
        $card = null;
        $createdCard = false;

        if ($canAutoFsrs && !$dryRun) {
            $card = $this->wordSenseService->createReviewCardForSense($sense);
            $createdCard = $card?->wasRecentlyCreated === true;
        } elseif ($canAutoFsrs && $dryRun) {
            $createdCard = !ReviewCard::where('user_id', $userId)
                ->where('language_id', $language)
                ->where('target_type', ReviewCard::TARGET_SENSE)
                ->where('target_id', $sense->id)
                ->exists();
        }

        if (!$dryRun) {
            WordSenseOccurrence::create($this->occurrenceData($item, $match, $userId, $language, [
                'word_sense_id' => $sense->id,
                'review_card_id' => $card?->id,
                'type' => WordSenseOccurrence::TYPE_WORD,
                'status' => $status,
                'auto_fsrs_allowed' => $canAutoFsrs,
            ]));
        }

        $summary['imported_occurrences']++;
        if ($isBound) {
            $summary['bound_existing_senses']++;
        } else {
            $summary['pending_confirmations']++;
        }
        if ($createdCard) {
            $summary['created_sense_cards']++;
        }
    }

    private function importNewSenseMatch(array $item, array $match, int $userId, string $language, bool $dryRun, array &$summary): void
    {
        $sense = null;
        if (!$dryRun) {
            $sense = $this->wordSenseService->createSense([
                'user_id' => $userId,
                'language' => $language,
                'language_id' => $language,
                'lemma' => $this->lemma($item, $match),
                'surface_form' => $this->surface($item, $match),
                'pos' => Arr::get($match, 'pos'),
                'sense_key' => Arr::get($match, 'sense_key'),
                'sense_zh' => Arr::get($match, 'sense_zh', ''),
                'sense_en' => Arr::get($match, 'sense_en'),
                'aliases_zh' => Arr::get($match, 'aliases_zh', []),
                'collocations' => Arr::get($match, 'collocations', []),
                'example_sentence_en' => Arr::get($item, 'en'),
                'example_sentence_zh' => Arr::get($item, 'zh'),
                'source_text_id' => Arr::get($item, 'text_id'),
                'source_chapter_id' => Arr::get($item, 'chapter_id'),
                'sentence_id' => Arr::get($item, 'sentence_id'),
                'sentence_hash' => Arr::get($item, 'sentence_hash'),
                'status' => WordSense::STATUS_AI_SUGGESTED,
            ]);

            WordSenseOccurrence::create($this->occurrenceData($item, $match, $userId, $language, [
                'word_sense_id' => $sense->id,
                'type' => WordSenseOccurrence::TYPE_WORD,
                'status' => WordSenseOccurrence::STATUS_PENDING,
                'auto_fsrs_allowed' => false,
            ]));
        }

        $summary['imported_occurrences']++;
        $summary['created_new_senses']++;
        $summary['pending_confirmations']++;
    }

    private function importPendingOccurrence(array $item, array $match, int $userId, string $language, bool $dryRun, array &$summary, string $status, string $type): void
    {
        if (!$dryRun) {
            WordSenseOccurrence::create($this->occurrenceData($item, $match, $userId, $language, [
                'type' => $type,
                'status' => $status,
                'auto_fsrs_allowed' => false,
            ]));
        }

        $summary['imported_occurrences']++;
        if ($status === WordSenseOccurrence::STATUS_IGNORED) {
            $summary['ignored_items']++;
        } else {
            $summary['pending_confirmations']++;
        }
        if ($type === WordSenseOccurrence::TYPE_PHRASE) {
            $summary['phrase_deferred']++;
        }
    }

    private function occurrenceData(array $item, array $match, int $userId, string $language, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'document_id' => Arr::get($item, 'document_id'),
            'text_id' => Arr::get($item, 'text_id'),
            'chapter_id' => Arr::get($item, 'chapter_id'),
            'sentence_id' => (string) Arr::get($item, 'sentence_id'),
            'sentence_hash' => Arr::get($item, 'sentence_hash'),
            'sentence_en' => (string) Arr::get($item, 'en'),
            'sentence_zh' => Arr::get($item, 'zh'),
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $this->surface($item, $match),
            'lemma' => $this->lemma($item, $match),
            'pos' => Arr::get($match, 'pos'),
            'decision' => $match['decision'],
            'confidence' => (float) $match['confidence'],
            'evidence' => Arr::get($match, 'evidence'),
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_PENDING,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
            'raw_payload' => $match,
        ], $overrides);
    }

    private function surface(array $item, array $match): string
    {
        return (string) (Arr::get($match, 'surface')
            ?? Arr::get($match, 'text')
            ?? Arr::get($match, 'word')
            ?? Arr::get($item, 'surface')
            ?? Arr::get($item, 'word')
            ?? Arr::get($item, 'en'));
    }

    private function lemma(array $item, array $match): string
    {
        return Str::lower(trim((string) (Arr::get($match, 'lemma') ?? $this->surface($item, $match))));
    }

    private function countMatches(array $items): int
    {
        return array_sum(array_map(fn ($item) => is_array($item) && isset($item['matches']) && is_array($item['matches']) ? count($item['matches']) : 0, $items));
    }

    private function emptySummary(int $totalItems, array $errors = []): array
    {
        return [
            'total_items' => $totalItems,
            'imported_occurrences' => 0,
            'bound_existing_senses' => 0,
            'created_new_senses' => 0,
            'pending_confirmations' => 0,
            'ignored_items' => 0,
            'phrase_deferred' => 0,
            'created_sense_cards' => 0,
            'errors' => $errors,
        ];
    }
}
