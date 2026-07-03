<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Kanji;
use App\Models\Phrase;

// models
use League\Csv\Writer;
use App\Models\Chapter;
use App\Models\Radical;
use App\Models\EncounteredWord;
use App\Models\ExampleSentence;
use App\Enums\ChapterProcessingStatusEnum;

// services
use Illuminate\Support\Facades\DB;

/**
 * Read-only vocabulary query service.
 *
 * Extracted from VocabularyService as part of GLM-ArchitectureFirst1000-
 * SafeStability-1. Holds every pure read-only query path used by the
 * vocabulary search / export / single-resource fetch routes.
 *
 * Boundary rules:
 *  - No ReviewCard, WordSense, ReviewLog, EncounteredWord write calls.
 *  - No FSRS scheduling.
 *  - No stage updates.
 *  - No import logic.
 *  - The CSV writer returned by exportToCsv() writes to a SplTempFileObject
 *    only — no DB writes.
 *
 * VocabularyService keeps a thin proxy to each public method here so
 * existing callers (tests, jobs, controllers) that depend on
 * VocabularyService continue to work unchanged.
 */
class VocabularyQueryService {
    private $itemsPerPage;

    public function __construct() {
        $this->itemsPerPage = 30;
    }

    public function getUniqueWord($userId, $wordId) {
        $word = EncounteredWord
            ::where('user_id', $userId)
            ->where('id', $wordId)
            ->first();

        if (!$word) {
            throw new \Exception('Word does not exist, or it belongs to a different user.');
        }

        return $word;
    }

    public function getPhrase($userId, $phraseId) {
        $phrase = Phrase
            ::where('user_id', $userId)
            ->where('id', $phraseId)
            ->first();

        if (!$phrase) {
            throw new \Exception('Phrase does not exist, or it belongs to a different user.');
        }

        return $phrase;
    }

    public function getExampleSentence($userId, $targetType, $targetId) {
        $exampleSentence = ExampleSentence
            ::where('user_id', $userId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        if (!$exampleSentence) {
            return null;
        }

        $textBlock = new TextBlockService($userId, $exampleSentence->language);
        $textBlock->setProcessedWords(json_decode($exampleSentence->words));
        $textBlock->uniqueWords = json_decode($exampleSentence->unique_words);
        $textBlock->prepareTextForReader();
        $textBlock->indexPhrases();

        return $textBlock->getReaderData();
    }

    public function searchVocabulary($userId, $language, $text, $bookId, $chapterId, $stage, $phrases, $orderBy, $translation, $page, $languagesWithoutSpaces) {
        // get books and chapters
        $books = Book::where('user_id', $userId)->where('language', $language)->get();
        $bookIndex = -1;
        for ($i = 0; $i < count($books); $i++) {
            $books[$i]->chapters = Chapter
                ::select(['id', 'name'])
                ->where('user_id', $userId)
                ->where('processing_status', ChapterProcessingStatusEnum::PROCESSED->value)
                ->where('language', $language)
                ->where('book_id', $books[$i]->id)
                ->get();

            if (isset($bookId) && $books[$i]->id == $bookId) {
                $bookIndex = $i;
            }
        }

        $search = $this->buildSearchRequest($userId, $language, $text, $bookId, $chapterId, $stage, $phrases, $orderBy, $translation);

        $data = new \stdClass();
        $data->wordCount = $search->count();
        $data->words = $search->skip(($page - 1) * $this->itemsPerPage)->take($this->itemsPerPage)->get();
        $data->books = $books;
        $data->bookIndex = $bookIndex;
        $data->pageCount = ceil($data->wordCount / $this->itemsPerPage);
        $data->currentPage = $page;
        $data->languageSpaces = !in_array($language, $languagesWithoutSpaces, true);

        return $data;
    }

    public function exportToCsv($userId, $language, $text, $bookId, $chapterId, $stage, $phrases, $orderBy, $translation, $fields, $languagesWithoutSpaces) {
        $words = $this->buildSearchRequest($userId, $language, $text, $bookId, $chapterId, $stage, $phrases, $orderBy, $translation)->get();

        // create csv file
        $csv = Writer::createFromFileObject(new \SplTempFileObject());
        $csv->setDelimiter('|');

        // insert headers to csv
        $csvArray = [];
        foreach ($fields as $field) {
            if ($field['export']) {
                $csvArray[] = str_replace('Stage', 'Level', $field['headerName']);
            }
        }

        $csv->insertOne($csvArray);

        // insert data to csv
        $phraseWordDelimiter = in_array($language, $languagesWithoutSpaces, true) ? '' : ' ';
        foreach($words as $word) {
            $csvArray = [];
            foreach ($fields as $field) {
                if (!$field['export']) {
                    continue;
                }

                $searchObjectProperty = $field['searchObjectProperty'];

                if ($word->type === 'phrase' && $searchObjectProperty === 'word') {
                    $csvArray[] = implode($phraseWordDelimiter, json_decode($word->$searchObjectProperty));
                } else {
                    $csvArray[] = $word->$searchObjectProperty;
                }
            }

            $csv->insertOne($csvArray);
        }

        return $csv;
    }

    public function searchKanji($userId, $language, $groupBy, $showUnknown) {
        $words = EncounteredWord
            ::where('user_id', $userId)
            ->where('stage', 0)
            ->where('language', $language)
            ->where('kanji', '<>', '')
            ->get();

        // get knwon kanji
        $knownKanji = [];
        foreach ($words as $word) {
            $wordKanji = preg_split("//u", $word->kanji, -1, PREG_SPLIT_NO_EMPTY);
            foreach($wordKanji as $currentKanji) {
                if(!in_array($currentKanji, $knownKanji, true)) {
                    array_push($knownKanji, $currentKanji);
                }
            }
        }

        // get kanji list
        if ($groupBy == 'grade') {
            $kanji = Kanji::where(function($query) use($knownKanji) {
                $query->where('grade', '>', 0)->orWhereIn('kanji', $knownKanji);
            });
        } else {
            $kanji = Kanji::where(function($query) use($knownKanji) {
                $query->where('jlpt', '>', 0)->orWhereIn('kanji', $knownKanji);
            });
        }

        if (!$showUnknown) {
            $kanji = $kanji->whereIn('kanji', $knownKanji);
        }

        $kanji = $kanji->get();

        // label kanji list
        foreach ($kanji as $currentKanji) {
            $currentKanji->known = in_array($currentKanji->kanji, $knownKanji);
        }

        // group kanji list
        if ($groupBy == 'grade') {
            $kanji = $kanji->groupBy('grade');
        } else {
            $kanji = $kanji->groupBy('jlpt');
        }


        // get count for statistics
        if ($groupBy == 'grade') {
            $totalCount = Kanji
                ::select('grade', DB::raw('count(id) as total'))
                ->groupBy('grade')
                ->get()
                ->keyBy('grade');

            $knownCount = Kanji
                ::select('grade', DB::raw('count(id) as total'))
                ->whereIn('kanji', $knownKanji)->groupBy('grade')
                ->get()
                ->keyBy('grade');
        } else {
            $totalCount = Kanji
                ::select('jlpt', DB::raw('count(id) as total'))
                ->groupBy('jlpt')
                ->get()
                ->keyBy('jlpt');

            $knownCount = Kanji
                ::select('jlpt', DB::raw('count(id) as total'))
                ->whereIn('kanji', $knownKanji)->groupBy('jlpt')
                ->get()
                ->keyBy('jlpt');
        }

        $searchResults = new \stdClass();
        $searchResults->kanji = $kanji;
        $searchResults->total = $totalCount;
        $searchResults->known = $knownCount;

        return $searchResults;
    }

    public function getKanjiDetails($userId, $kanjiCharacter) {
        $kanjiData = Kanji
            ::where('kanji', $kanjiCharacter)
            ->first();

        if (!$kanjiData) {
            throw new \Exception('Kanji not found in database.');
        }

        $words = EncounteredWord
            ::where('word', 'like', '%' . $kanjiCharacter . '%')
            ->where('user_id', $userId)
            ->limit(12)
            ->get();

        $radicals = Radical
            ::select('radicals')
            ->where('kanji', $kanjiCharacter)
            ->first();

        $kanjiDetails = new \stdClass();
        $kanjiDetails->kanji = $kanjiData;
        $kanjiDetails->radicals = $radicals->radicals;
        $kanjiDetails->words = $words;

        return $kanjiDetails;
    }

    private function buildSearchRequest($userId, $language, $text, $bookId, $chapterId, $stage, $phrases, $orderBy, $translation) {
        $wordsToSkip = config('linguacafe.words_to_skip');

        // get words and phrases
        // from filtered chapters
        $filteredChapters = Chapter::where('user_id', $userId)->where('language', $language);
        $filteredWords = [];
        $filteredPhraseIds = [];
        if ($bookId !== -1) {
            $filteredChapters = $filteredChapters->where('book_id', $bookId);
        }

        if ($chapterId !== -1) {
            $filteredChapters = $filteredChapters->where('id', $chapterId);
        }

        $filteredChapters = $filteredChapters->get();

        if ($bookId !== -1) {
            foreach ($filteredChapters as $filteredChapter) {
                $chapter = Chapter
                    ::where('user_id', $userId)
                    ->where('id', $filteredChapter->id)
                    ->first();

                // add filtered phrase ids
                $filteredChapterWords = $chapter->getProcessedText();

                foreach ($filteredChapterWords as $filteredChapterWord) {
                    $filteredChapterWord->phrase_ids = $filteredChapterWord->phrase_ids;
                    foreach ($filteredChapterWord->phrase_ids as $phraseId) {
                        if (!in_array($phraseId, $filteredPhraseIds, true)) {
                            array_push($filteredPhraseIds, $phraseId);
                        }
                    }
                }

                // add filtered words
                $filteredChapterUniqueWords = json_decode($filteredChapter->unique_words);
                foreach ($filteredChapterUniqueWords as $filteredChapterUniqueWord) {
                    if (!in_array($filteredChapterUniqueWord, $filteredWords, true)) {
                        array_push($filteredWords, $filteredChapterUniqueWord);
                    }
                }
            }
        }

        // search for words and apply filters
        $wordSearch = EncounteredWord
            ::select('id', 'base_word', 'word', DB::raw("'' AS words_searchable"), 'reading', 'base_word_reading', 'stage', 'translation', 'read_count', 'lookup_count', 'added_to_srs', DB::raw("'word' AS type"))->where('user_id', $userId)
            ->where('language', $language)
            ->whereNotIn('word', $wordsToSkip);

        if ($text !== 'anytext') {
            $wordSearch = $wordSearch->where(function($query) use ($text) {
                $query->orWhere('word', 'like', '%' . $text . '%')
                    ->orWhere('reading', 'like', '%' . $text . '%');
            });
        }

        if ($bookId !== -1) {
            $wordSearch->whereIn('word', $filteredWords);
        }

        if ($stage !== -999) {
            $wordSearch = $wordSearch->where('stage', $stage);
        }

        if ($translation == 'not empty') {
            $wordSearch = $wordSearch->where('translation', '<>', '');
        }

        // search for phrases and apply filters
        $phraseSearch = Phrase
            ::select('id', DB::raw("'' AS base_word"), 'words as word', 'words_searchable', 'reading', DB::raw("'' AS base_word_reading"), 'stage', 'translation', DB::raw("-1 AS read_count"), DB::raw("-1 AS lookup_count"), 'added_to_srs', DB::raw("'phrase' AS type"))
            ->where('user_id', $userId)
            ->where('language', $language);

        if ($text !== 'anytext') {
            $phraseSearch = $phraseSearch->where(function($query) use ($text) {
                $query->orWhere('words_searchable', 'like', '%' . $text . '%')
                    ->orWhere('reading', 'like', '%' . $text . '%');
            });
        }

        if ($bookId !== -1) {
            $phraseSearch->whereIn('id', $filteredPhraseIds);
        }

        if ($stage !== -999) {
            $phraseSearch = $phraseSearch->where('stage', $stage);
        }

        if ($translation == 'not empty') {
            $phraseSearch = $phraseSearch->where('translation', '<>', '');
        }

        if ($phrases == 'only words') {
            $search = $wordSearch;
        } else if ($phrases == 'only phrases') {
            $search = $phraseSearch;
        } else {
            $search = $wordSearch->union($phraseSearch);
        }

        if ($orderBy == 'words') {
            $search = $search->orderBy('word');
        }

        if ($orderBy == 'words desc') {
            $search = $search->orderBy('word', 'desc');
        }

        if ($orderBy == 'stage') {
            $search = $search->orderBy('stage');
        }

        if ($orderBy == 'stage desc') {
            $search = $search->orderBy('stage', 'desc');
        }

        $search = $search->orderBy('id')->orderBy('type');

        return $search;
    }
}
