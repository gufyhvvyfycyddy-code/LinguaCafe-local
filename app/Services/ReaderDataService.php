<?php

namespace App\Services;

use App\Models\Phrase;
use App\Models\WordSense;
use App\Models\ReviewCard;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service responsible for read-only preparation of reader data.
 * Does NOT write to database, does NOT call tokenizer, does NOT
 * create EncounteredWord records.
 */
class ReaderDataService
{
    public function __construct(
        private int $userId,
        private string $language,
    ) {
    }

    /**
     * Collect unique words (lowercase) from processed token array.
     * Memory-only operation, no database access.
     */
    public function collectUniqueWords(array $processedWords): array
    {
        $uniqueWords = [];
        foreach ($processedWords as $word) {
            $w = $word->word;
            if (VocabularyTokenFilter::shouldSkip($w, $this->language)) {
                continue;
            }
            $lower = mb_strtolower($w, 'UTF-8');
            if (!in_array($lower, $uniqueWords, true)) {
                $uniqueWords[] = $lower;
            }
        }
        return $uniqueWords;
    }

    /**
     * Prepare reader words array from processed tokens.
     * Queries encountered_words and FSRS familiarity (read-only).
     *
     * @param array $processedWords Tokenized word objects from TextBlockService
     * @param array $uniqueWords Lowercase unique word list
     * @param array|null $fwLookup Pre-computed FSRS familiarity lookup (null = auto-load)
     * @return array Words array for vue TextBlockGroup component
     */
    public function prepareTextForReader(
        array $processedWords,
        iterable $encounteredWords,
        array $uniqueWords,
        ?array $fwLookup = null,
    ): array {
        $tokensWithNoSpaceBefore = config('linguacafe.tokens_with_no_space_before');
        $tokensWithNoSpaceAfter = config('linguacafe.tokens_with_no_space_after');
        $languagesWithoutSpaces = config('linguacafe.languages.languages_without_spaces');

        if ($fwLookup === null) {
            $fwLookup = $this->loadFsrsFamiliarityLookup();
        }

        $words = [];
        $wordCount = count($processedWords);

        for ($wordIndex = 0; $wordIndex < $wordCount; $wordIndex++) {
            $word = clone $processedWords[$wordIndex];
            $word->selected = false;
            $word->hover = false;
            $word->phraseStage = 'learning';
            $word->phraseStart = false;
            $word->phraseEnd = false;
            $word->phraseIndexes = [];
            $word->subtitleIndex = -1;

            // Add space for word if the language has spaces in it.
            if ($this->language === 'thai') {
                $word->spaceAfter = (
                    isset($processedWords[$wordIndex]->sentence_index) &&
                    $wordIndex < $wordCount - 1 &&
                    $processedWords[$wordIndex + 1]->sentence_index !== $processedWords[$wordIndex]->sentence_index
                );
            } else {
                $word->spaceAfter = !in_array($this->language, $languagesWithoutSpaces, true);
            }

            if ($wordIndex < count($processedWords) - 1 && in_array($processedWords[$wordIndex + 1]->word, $tokensWithNoSpaceBefore, true)) {
                $word->spaceAfter = false;
            }

            if (in_array($processedWords[$wordIndex]->word, $tokensWithNoSpaceAfter, true)) {
                $word->spaceAfter = false;
            }

            // Match encountered word
            $encounteredWordId = null;
            $stage = 1;
            $lookupCount = 0;
            $furigana = '';

            foreach ($encounteredWords as $ew) {
                if ($ew->word === mb_strtolower($word->word)) {
                    $encounteredWordId = $ew->id;
                    $stage = $ew->stage;
                    $lookupCount = $ew->lookup_count;
                    $furigana = $ew->reading ?? '';
                    break;
                }
            }

            $word->id = $encounteredWordId;
            $word->stage = $stage;
            $word->lookup_count = $lookupCount;
            $word->furigana = $furigana;

            // Override stage with FSRS familiarity for learning-system words
            if ($encounteredWordId !== null && $stage < 0 && array_key_exists($encounteredWordId, $fwLookup)) {
                $fw = $fwLookup[$encounteredWordId];
                $word->stage = max(-10, min(-1, -$fw['level_10']));
                $word->fsrs_familiarity_score = $fw['score'];
                $word->fsrs_familiarity_level_10 = $fw['level_10'];
                $word->fsrs_familiarity_percent = $fw['level_10'] * 10;
            }

            $words[] = $word;
        }

        return $words;
    }

    /**
     * Enrich unique words with FSRS familiarity fields.
     */
    public function enrichUniqueWords(iterable $uniqueWords, array $fwLookup): array
    {
        $result = [];
        foreach ($uniqueWords as $uw) {
            $uw->definitions_checked = false;
            if (isset($fwLookup[$uw->id])) {
                $fw = $fwLookup[$uw->id];
                $uw->fsrs_familiarity_score = $fw['score'];
                $uw->fsrs_familiarity_level_10 = $fw['level_10'];
                $uw->fsrs_familiarity_percent = $fw['level_10'] * 10;
                $uw->fsrs_familiarity_has_data = true;
            } else {
                $uw->fsrs_familiarity_has_data = false;
            }
            $result[] = $uw;
        }
        return $result;
    }

    /**
     * Load FSRS familiarity data for encountered words that have confirmed WordSenses.
     * Read-only: does NOT write review_cards, word_senses, or review_logs.
     *
     * @return array Keyed by encountered_word_id: ['level_10'=>int, 'level'=>int, 'score'=>float]
     */
    public function loadFsrsFamiliarityLookup(): array
    {
        $lookup = [];

        $rows = DB::table('word_senses')
            ->join('encountered_words', 'word_senses.encountered_word_id', '=', 'encountered_words.id')
            ->join('review_cards', function ($join) {
                $join->on('word_senses.id', '=', 'review_cards.target_id')
                     ->where('review_cards.target_type', '=', ReviewCard::TARGET_SENSE);
            })
            ->where('word_senses.user_id', $this->userId)
            ->where('word_senses.language', $this->language)
            ->where('word_senses.status', WordSense::STATUS_CONFIRMED)
            ->where('encountered_words.stage', '<', 0)
            ->select([
                'word_senses.encountered_word_id',
                'review_cards.fsrs_stability',
                'review_cards.fsrs_due_at',
                'review_cards.fsrs_state',
            ])
            ->get()
            ->toArray();

        foreach ($rows as $row) {
            $stability = (float) ($row->fsrs_stability ?? 0);
            $isOverdue = $row->fsrs_due_at && Carbon::parse($row->fsrs_due_at)->isPast();
            $state = $row->fsrs_state ?? 'new';

            $score = $stability > 0 ? min(1.0, $stability / 30.0) : 0.0;
            $level10 = (int) ceil(max(0.01, $score) * 10);
            $level10 = max(1, min(10, $level10));

            if ($state === 'new') {
                $level10 = 1;
            }
            if ($isOverdue && $level10 > 1) {
                $level10--;
            }

            $level7 = max(1, min(7, (int) ceil($level10 * 7 / 10)));

            $existing = $lookup[$row->encountered_word_id] ?? null;
            if (!$existing || $level10 < $existing['level_10']) {
                $lookup[$row->encountered_word_id] = [
                    'level_10' => $level10,
                    'level' => $level7,
                    'score' => round($score, 2),
                ];
            }
        }

        return $lookup;
    }

    /**
     * Load phrase records for phrase IDs referenced in words array.
     * Read-only DB query.
     *
     * @param array $words The prepared words array (must have phrase_ids)
     * @return \Illuminate\Support\Collection
     */
    public function loadPhrases(array $words): \Illuminate\Support\Collection
    {
        $phraseIds = [];
        foreach ($words as $word) {
            if (!empty($word->phrase_ids)) {
                foreach ($word->phrase_ids as $pid) {
                    if (!in_array($pid, $phraseIds)) {
                        $phraseIds[] = $pid;
                    }
                }
            }
        }

        sort($phraseIds);

        if (empty($phraseIds)) {
            return collect([]);
        }

        $phrases = Phrase::where('user_id', $this->userId)
            ->where('language', $this->language)
            ->whereIn('id', $phraseIds)
            ->orderBy('id')
            ->get();

        foreach ($phrases as $phrase) {
            $phrase->words = json_decode($phrase->words);
            $phrase->definitions_checked = false;
        }

        return $phrases;
    }

    /**
     * Re-index phrase_ids to phraseIndexes on words array (in-place).
     */
    public function indexPhraseIndexes(array &$words, iterable $phrases): void
    {
        $phraseIds = [];
        foreach ($phrases as $p) {
            $phraseIds[] = $p->id;
        }
        sort($phraseIds);

        foreach ($words as &$word) {
            $word->phraseIndexes = [];
            foreach ($word->phrase_ids as $phraseId) {
                $index = array_search($phraseId, $phraseIds);
                if ($index !== false) {
                    $word->phraseIndexes[] = $index;
                }
            }
        }
        unset($word);
    }
}
