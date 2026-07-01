<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Encapsulates the creation of encountered_words records from processed tokens.
 *
 * Extracted from TextBlockService::createNewEncounteredWords() to reduce the
 * size and responsibility of TextBlockService. This service handles the
 * database write logic (dedup, filtering, CJK handling, UserStudyBaseRule)
 * without any knowledge of tokenization, reader data preparation, or phrases.
 *
 * All behavior is locked by tests/Feature/TextBlockCreateNewEncounteredWordsTest.php.
 */
class EncounteredWordCreationService
{
    /**
     * Create encountered_words for new tokens that do not yet exist.
     *
     * @param int    $userId         Current user ID.
     * @param string $language       Language code (e.g. 'english', 'japanese').
     * @param array  $processedWords Array of stdClass objects with ->word, ->lemma,
     *                               ->reading, ->lemma_reading, ->phrase_ids.
     * @param array  $uniqueWords    Array of lowercase unique word strings used
     *                               for the dedup lookup query.
     *
     * @return void
     */
    public function create(int $userId, string $language, array $processedWords, array $uniqueWords): void
    {
        $wordsToSkip = config('linguacafe.words_to_skip');

        // a regular expression for japanese kanji characters
        $kanjipattern = "/[a-zA-Z0-9０-９あ-んア-ンー。、:？！＜＞： 「」（）｛｝≪≫〈〉《》【】『』〔〕［］・\n\r\t\s\(\)　]/u";
        DB::disableQueryLog();

        DB::transaction(function () use ($wordsToSkip, $kanjipattern, $userId, $language, $processedWords, $uniqueWords) {
            $encounteredWords = DB::table('encountered_words')
                ->select('word')
                ->where('user_id', $userId)
                ->where('language', $language)
                ->whereIn('word', $uniqueWords)
                ->lockForUpdate()
                ->pluck('word')
                ->toArray();

            $encounteredWordsToInsert = [];
            for ($wordIndex = 0; $wordIndex < count($processedWords); $wordIndex++) {
                if (
                    in_array(mb_strtolower($processedWords[$wordIndex]->word, 'UTF-8'), $encounteredWords, true) ||
                    VocabularyTokenFilter::shouldSkip($processedWords[$wordIndex]->word, $language)
                ) {
                    continue;
                }

                $encounteredWords[] = mb_strtolower($processedWords[$wordIndex]->word, 'UTF-8');

                if ($language == 'japanese' || $language == 'chinese') {
                    $kanji = preg_replace($kanjipattern, "", $processedWords[$wordIndex]->word);
                    $kanji = preg_split("//u", $kanji, -1, PREG_SPLIT_NO_EMPTY);
                }

                $encounteredWord = [];
                $encounteredWord['user_id'] = $userId;
                $encounteredWord['language'] = $language;
                $encounteredWord['word'] = mb_strtolower($processedWords[$wordIndex]->word, 'UTF-8');
                $encounteredWord['lemma'] = mb_strtolower($processedWords[$wordIndex]->lemma);
                $grammaticalLemma = mb_strtolower($processedWords[$wordIndex]->lemma);
                $encounteredWord['base_word'] = $grammaticalLemma;

                // study_base: use user rule if exists, otherwise default to grammatical lemma
                $surfaceLower = mb_strtolower($processedWords[$wordIndex]->word, 'UTF-8');
                $userRule = \App\Models\UserStudyBaseRule::where('user_id', $userId)
                    ->where('language', $language)
                    ->where('surface', $surfaceLower)
                    ->first();
                $encounteredWord['study_base'] = $userRule
                    ? $userRule->study_base
                    : $grammaticalLemma;
                $encounteredWord['reading'] = $processedWords[$wordIndex]->reading;
                $encounteredWord['kanji'] = $language == 'japanese' || $language == 'chinese' ? implode('', $kanji) : '';
                $encounteredWord['base_word_reading'] = $processedWords[$wordIndex]->lemma_reading;
                $encounteredWord['stage'] = 2;
                $encounteredWord['translation'] = '';
                $encounteredWord['created_at'] = Carbon::now();
                $encounteredWord['updated_at'] = Carbon::now();

                if (in_array($processedWords[$wordIndex]->word, $wordsToSkip, true) || VocabularyTokenFilter::shouldSkip($processedWords[$wordIndex]->word, $language)) {
                    $encounteredWord['stage'] = 1;
                    $encounteredWord['base_word'] = '';
                    $encounteredWord['lemma'] = '';
                    $encounteredWord['study_base'] = '';
                    $encounteredWord['reading'] = '';
                    $encounteredWord['base_word_reading'] = '';
                }

                // Only clear lemma/base_word for CJK languages where lemma==word is the default.
                // English and other European languages: keep base_word even if it matches the surface
                // (e.g., "series" → lemma "series" is correct; clearing it breaks WordSense lookups).
                $isCJK = in_array($language, ['japanese', 'chinese', 'korean', 'thai'], true);
                if ($isCJK && $encounteredWord['base_word'] == $encounteredWord['word']) {
                    $encounteredWord['base_word'] = '';
                    $encounteredWord['lemma'] = '';
                    $encounteredWord['study_base'] = '';
                    $encounteredWord['base_word_reading'] = '';
                }

                $encounteredWordsToInsert[] = $encounteredWord;
            }
            if (count($encounteredWordsToInsert)) {
                DB::table('encountered_words')->insert($encounteredWordsToInsert);
            }
        });
    }
}
