<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Kanji;
use App\Models\Phrase;

// models
use League\Csv\Reader;
use League\Csv\Writer;
use App\Models\Chapter;
use App\Models\Radical;
use App\Models\ReviewCard;
use App\Models\EncounteredWord;
use App\Models\ExampleSentence;
use App\Enums\ChapterProcessingStatusEnum;

// services
use App\Services\TextBlockService;
use Illuminate\Support\Facades\DB;;
use App\Services\VocabularyTokenFilter;

class VocabularyService {
    private $itemsPerPage;

    public function __construct(
        private ReviewCardService $reviewCardService,
        private WordSenseService $wordSenseService,
    ) {
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

    public function updateWord($userId, $wordId, $wordData, $wordStage = null, array $bridgeContext = []) {
        $word = EncounteredWord
            ::where('user_id', $userId)
            ->where('id', $wordId)
            ->first();

        if (!$word) {
            throw new \Exception('Word does not exist, or it belongs to a different user.');
        }

        if ($wordStage !== null) {
            $word->setStage($wordStage);
        }

        $word->update($wordData);
        $word->save();

        if ($word->stage < 0) {
            $this->reviewCardService->ensureWordCard($word);
        } else {
            $this->reviewCardService->disableWordCard($word);
        }

        // 桥接：Learning 词自动创建 word_sense 草稿
        if ($word->stage < 0 && !empty($word->translation)) {
            $this->bridgeWordToSense($word, $bridgeContext);
        }

        return true;
    }

    private function bridgeWordToSense(EncounteredWord $word, array $context): void
    {
        $lemma = $word->base_word ?: mb_strtolower($word->word, 'UTF-8');
        $surface = $context['word'] ?? $word->word;

        // 已有关联 sense 则复用，否则创建
        $existingSense = \App\Models\WordSense::where('encountered_word_id', $word->id)
            ->where('user_id', $word->user_id)
            ->first();

        if ($existingSense) {
            $sense = $existingSense;
        } else {
            $senseData = [
                'user_id' => $word->user_id,
                'language' => $word->language,
                'language_id' => $word->language,
                'encountered_word_id' => $word->id,
                'lemma' => $lemma,
                'surface_form' => $surface,
                'sense_zh' => $word->translation,
                'sense_en' => '',
                'status' => \App\Models\WordSense::STATUS_AI_SUGGESTED,
            ];

            if (!empty($context['chapter_id'])) {
                $senseData['source_chapter_id'] = (int) $context['chapter_id'];
            }

            if (isset($context['sentence_index'])) {
                $senseData['sentence_id'] = (string) $context['sentence_index'];
            }

            // 生成 sense_key
            $senseData['sense_key'] = $lemma . '|' . md5($senseData['sense_zh']);

            $sense = \App\Models\WordSense::create($senseData);
        }

        // 创建 WordSenseOccurrence（仅当有章节上下文时）
        if (empty($context['chapter_id']) || !isset($context['sentence_index'])) {
            return;
        }

        $chapterId = (int) $context['chapter_id'];
        $sentenceId = (string) $context['sentence_index'];

        // 提取句子文本
        $sentenceEn = $this->extractSentenceText($chapterId, (int) $context['sentence_index']);
        if ($sentenceEn === '') {
            // fallback: 至少用 surface 或 lemma，避免 /senses/review 显示空句子
            $sentenceEn = $surface ?: $lemma;
            \Log::warning('bridgeWordToSense: could not extract sentence text, using fallback', [
                'chapter_id' => $chapterId,
                'sentence_index' => $context['sentence_index'],
                'surface' => $surface,
            ]);
        }

        // 去重：检查是否已有 manual_vocab_bridge 来源的 occurrence
        $existingOccurrence = \App\Models\WordSenseOccurrence::where('user_id', $word->user_id)
            ->where('language_id', $word->language)
            ->where('chapter_id', $chapterId)
            ->where('sentence_id', $sentenceId)
            ->where('surface', $surface)
            ->where('source', \App\Models\WordSenseOccurrence::SOURCE_MANUAL_VOCAB_BRIDGE)
            ->first();

        if ($existingOccurrence) {
            // pending → 更新建议词义
            if ($existingOccurrence->status === \App\Models\WordSenseOccurrence::STATUS_PENDING) {
                $existingOccurrence->update([
                    'word_sense_id' => $sense->id,
                    'raw_payload' => ['sense_zh' => $word->translation, 'source' => 'manual_vocab_bridge'],
                ]);
            }
            // confirmed / bound / rejected / ignored → 不覆盖已有确认结果
            return;
        }

        // 创建新 occurrence，状态 pending，等待用户在 /senses/review 确认
        \App\Models\WordSenseOccurrence::create([
            'user_id' => $word->user_id,
            'language' => $word->language,
            'language_id' => $word->language,
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapterId,
            'sentence_id' => $sentenceId,
            'sentence_en' => $sentenceEn,
            'surface' => $surface,
            'lemma' => $lemma,
            'type' => \App\Models\WordSenseOccurrence::TYPE_WORD,
            'decision' => 'manual_vocab_bridge',
            'confidence' => 1.0,
            'auto_fsrs_allowed' => true,
            'status' => \App\Models\WordSenseOccurrence::STATUS_PENDING,
            'source' => \App\Models\WordSenseOccurrence::SOURCE_MANUAL_VOCAB_BRIDGE,
            'raw_payload' => ['sense_zh' => $word->translation, 'source' => 'manual_vocab_bridge'],
        ]);
    }

    private function extractSentenceText(int $chapterId, int $sentenceIndex): string
    {
        $chapter = Chapter::find($chapterId);
        if (!$chapter) {
            return '';
        }

        try {
            $words = $chapter->getProcessedText();
        } catch (\Throwable $e) {
            \Log::warning('extractSentenceText: failed to decode processed_text', [
                'chapter_id' => $chapterId,
                'error' => $e->getMessage(),
            ]);
            return '';
        }

        if (!is_array($words)) {
            return '';
        }

        $sentenceWords = [];
        foreach ($words as $w) {
            if (!is_object($w)) {
                continue;
            }
            $si = $w->sentence_index ?? -1;
            if ((int) $si === $sentenceIndex) {
                $sentenceWords[] = $w;
            }
        }

        if (empty($sentenceWords)) {
            return '';
        }

        $text = '';
        foreach ($sentenceWords as $w) {
            $text .= $w->word ?? '';
            if (!empty($w->spaceAfter)) {
                $text .= ' ';
            }
        }

        return trim($text);
    }

    public function ignoreWord($userId, $wordId): bool
    {
        return $this->setWordIgnored($userId, $wordId);
    }

    public function softDeleteWord($userId, $wordId): bool
    {
        return $this->setWordIgnored($userId, $wordId);
    }

    public function hardDeleteWord(int $userId, string $language, int $wordId): bool
    {
        $deleted = $this->hardDeleteWordsByIds($userId, $language, [$wordId]);

        if ($deleted === 0) {
            throw new \Exception('Word does not exist, or it belongs to a different user.');
        }

        return true;
    }

    public function hardDeleteWordsByIds(int $userId, string $language, array $wordIds): int
    {
        $wordIds = collect($wordIds)
            ->map(fn ($wordId) => (int) $wordId)
            ->filter(fn ($wordId) => $wordId > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($wordIds)) {
            return 0;
        }

        return DB::transaction(function () use ($userId, $language, $wordIds) {
            $ids = EncounteredWord::where('user_id', $userId)
                ->where('language', $language)
                ->whereIn('id', $wordIds)
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if (empty($ids)) {
                return 0;
            }

            // Reject WordSenses linked via encountered_word_id before deleting words.
            // Only senses with status != rejected are processed to avoid redundant work.
            // Other senses with the same lemma but different encountered_word_id are NOT affected.
            $linkedSenses = \App\Models\WordSense::where('user_id', $userId)
                ->where('language_id', $language)
                ->whereIn('encountered_word_id', $ids)
                ->where('status', '<>', \App\Models\WordSense::STATUS_REJECTED)
                ->get();

            foreach ($linkedSenses as $sense) {
                $this->wordSenseService->removeSenseFromReviewSystem($sense, true);
            }

            // Disable legacy word-type review cards
            ReviewCard::where('user_id', $userId)
                ->where('language_id', $language)
                ->where('target_type', ReviewCard::TARGET_WORD)
                ->whereIn('target_id', $ids)
                ->update(['fsrs_enabled' => false]);

            return EncounteredWord::where('user_id', $userId)
                ->where('language', $language)
                ->whereIn('id', $ids)
                ->delete();
        });
    }

    public function countHardDeletableWordsByFilters(int $userId, string $language, array $filters): int
    {
        return $this->buildHardDeleteWordQuery($userId, $language, $filters)->count();
    }

    public function hardDeleteWordsByFilters(int $userId, string $language, array $filters): int
    {
        $ids = $this->buildHardDeleteWordQuery($userId, $language, $filters)
            ->pluck('id')
            ->all();

        return $this->hardDeleteWordsByIds($userId, $language, $ids);
    }

    public function cleanupInvalidTokens(int $userId, string $language, bool $dryRun = false): array
    {
        $words = EncounteredWord::where('user_id', $userId)
            ->where('language', $language)
            ->get()
            ->filter(fn ($word) => VocabularyTokenFilter::shouldSkip($word->word, $language));

        $examples = $words->pluck('word')->unique()->take(20)->values()->all();

        if (!$dryRun) {
            foreach ($words as $word) {
                $this->markWordIgnored($word);
                $word->save();
                $this->reviewCardService->disableWordCard($word);
            }
        }

        return [
            'matched_count' => $words->count(),
            'examples' => $examples,
            'dry_run' => $dryRun,
        ];
    }

    private function setWordIgnored($userId, $wordId): bool
    {
        $word = EncounteredWord::where('user_id', $userId)
            ->where('id', $wordId)
            ->first();

        if (!$word) {
            throw new \Exception('Word does not exist, or it belongs to a different user.');
        }

        $this->markWordIgnored($word);
        $word->save();
        $this->reviewCardService->disableWordCard($word);

        return true;
    }

    private function markWordIgnored(EncounteredWord $word): void
    {
        $word->stage = 1;
        $word->relearning = false;
        $word->next_review = null;
    }

    public function createPhrase($userId, $language, $words, $stage, $reading, $translation, $languagesWithoutSpaces) {
        $phrase = new Phrase();
        $phrase->user_id = $userId;
        $phrase->language = $language;
        $phrase->stage = $stage;
        $phrase->reading = $reading;
        $phrase->translation = $translation;
        $phrase->words = json_encode($words);

        if (!is_array($words)) {
            throw new \Exception('Words parameter must be an array!');
        }

        if (!count($words)) {
            throw new \Exception('Words parameter must not be empty!');
        }

        if (in_array($language, $languagesWithoutSpaces, true)) {
            $phrase->words_searchable = implode('', $words);
        } else {
            $phrase->words_searchable = implode(' ', $words);
        }
        
        $phrase->save();

        // update phrase ids in chapter texts
        $chapterIds = Chapter
                ::where('user_id', $userId)
                ->where('language', $language)
                ->where('processing_status', ChapterProcessingStatusEnum::PROCESSED->value)
                ->pluck('id')
                ->toArray();

        $phraseWords = array_unique(json_decode($phrase->words));
        foreach ($chapterIds as $chapterId) {
            DB::transaction(function() use($chapterId, $phraseWords, $userId, $language, $phrase) {
                $chapter = Chapter
                    ::lockForUpdate()
                    ->where('id', $chapterId)
                    ->where('user_id', $userId)
                    ->where('language', $language)
                    ->where('processing_status', ChapterProcessingStatusEnum::PROCESSED->value)
                    ->first();
    
                $uniqueWords = json_decode($chapter->unique_words);
    
                if (count(array_intersect($uniqueWords, $phraseWords)) === count($phraseWords)) {
                    $words = $chapter->getProcessedText();
    
                    $textBlock = new TextBlockService($userId, $language);
                    $textBlock->setProcessedWords($words);
                    $textBlock->collectUniqueWords();
                    $phraseIdsChanged = $textBlock->updatePhraseIds($phrase);
    
                    // save chapter words
                    if ($phraseIdsChanged) {
                        $chapter->setProcessedText($textBlock->processedWords);
                        $chapter->save();
                    }
                }
            });
        }

        // update phrase ids in example sentences
        $exampleSentences = ExampleSentence
            ::where('user_id', $userId)
            ->where('language', $language)
            ->get();

        DB::beginTransaction();
        foreach ($exampleSentences as $exampleSentence) {
            $uniqueWords = json_decode($exampleSentence->unique_words);
            if (count(array_intersect($uniqueWords, $phraseWords)) !== count($phraseWords)) {
                continue;
            }

            $textBlock = new TextBlockService($userId, $language);
            $textBlock->setProcessedWords(json_decode($exampleSentence->words));
            $textBlock->collectUniqueWords();
            $textBlock->updatePhraseIds($phrase);
            $textBlock->createNewEncounteredWords();
            
            $exampleSentence->words = json_encode($textBlock->processedWords);
            $exampleSentence->unique_words = json_encode($textBlock->uniqueWords);
            $exampleSentence->save();
        }

        DB::commit();
        
        return $phrase->id;
    }

    public function indexPhraseInChapter($chapterId, $userId, $language, $phrase) {
        DB::transaction(function() use($chapterId, $userId, $language, $phrase) {
            $phraseWords = json_decode($phrase->words);

            $chapter = Chapter
                ::lockForUpdate()
                ->where('id', $chapterId)
                ->where('user_id', $userId)
                ->where('language', $language)
                ->where('processing_status', ChapterProcessingStatusEnum::PROCESSED->value)
                ->first();

            if (!$chapter) {
                throw new \Exception('Chapter not found.');
            }

            $uniqueWords = json_decode($chapter->unique_words);

            if (count(array_intersect($uniqueWords, $phraseWords)) === count($phraseWords)) {
                $words = $chapter->getProcessedText();

                $textBlock = new TextBlockService($userId, $language);
                $textBlock->setProcessedWords($words);
                $textBlock->collectUniqueWords();
                $phraseIdsChanged = $textBlock->updatePhraseIds($phrase);

                // save chapter words
                if ($phraseIdsChanged) {
                    $chapter->setProcessedText($textBlock->processedWords);
                    $chapter->save();
                }
            }
        });
    }
    
    public function updatePhrase($userId, $phraseId, $phraseData, $phraseStage = null) {
        
        /*
            Unset words in case it somehow ended up in the array, because
            it is also a fillable property, but should not be changed after
            the phrase was created.
        */
        unset($phraseData['words']);

        $phrase = Phrase
            ::where('user_id', $userId)
            ->where('id', $phraseId)
            ->first();
        
        if (!$phrase) {
            throw new \Exception('Phrase does not exist, or it belongs to a different user.');
        }
        
        if ($phraseStage !== null) {
            $phrase->setStage($phraseStage);
        }

        $phrase->update($phraseData);
        $phrase->save();

        return true;
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

    public function deletePhrase($userId, $language, $phraseId) {
        $phrase = Phrase
            ::where('user_id', $userId)
            ->where('language', $language)
            ->where('id', $phraseId)
            ->first();

        if (!$phrase) {
            throw new \Exception('Phrase does not exist, or it belongs to a different user.');
        }

        // remove phrase ids from text words
        $chapters = Chapter
            ::where('user_id', $userId)
            ->where('processing_status', ChapterProcessingStatusEnum::PROCESSED->value)
            ->where('language', $language)
            ->get();

        foreach($chapters as $chapter) {
            $words = $chapter->getProcessedText();
            $chapterChanged = false;

            // delete phrase id from chapter words
            foreach ($words as $word) {
                $index = array_search($phraseId, $word->phrase_ids);
                if ($index !== false) {
                    $modifiedPhraseIds = $word->phrase_ids;
                    array_splice($modifiedPhraseIds, $index, 1);
                    $word->phrase_ids = $modifiedPhraseIds;
                    $chapterChanged = true;
                }
            }

            // save chapter if changed
            if ($chapterChanged) {
                $chapter->setProcessedText($words);
                $chapter->save();
            }
        }

        // remove phrase ids from example sentence words
        $exampleSentences = ExampleSentence
            ::where('user_id', $userId)
            ->where('language', $language)
            ->get();

        DB::beginTransaction();
        foreach ($exampleSentences as $exampleSentence) {
            $exampleSentence->deletePhraseId($phraseId);
        }

        DB::commit();
        
        ExampleSentence
            ::where('user_id', $userId)
            ->where('target_type', 'phrase')
            ->where('target_id', $phraseId)
            ->delete();

        Phrase
            ::where('user_id', $userId)
            ->where('language', $language)
            ->where('id', $phraseId)
            ->delete();

        return true;
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

    public function createOrUpdateExampleSentence($userId, $language, $targetType, $targetId, $exampleSentenceWords) {
        // Retrieve example sentence.
        $exampleSentence = ExampleSentence
            ::where('user_id', $userId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        // Create new example sentence record if it didn't exist, and update words.
        if (!$exampleSentence) {
            $exampleSentence = new ExampleSentence();
            $exampleSentence->user_id = $userId;
            $exampleSentence->language = $language;
            $exampleSentence->target_type = $targetType;
            $exampleSentence->target_id = $targetId;
            $exampleSentence->unique_words = [];
        }

        // Update unique words.
        $uniqueWords = [];
        foreach ($exampleSentenceWords as $word) {
            $lowerCaseWord = mb_strtolower($word->word, 'UTF-8');
            if (!in_array($lowerCaseWord, $uniqueWords, true)) {
                array_push($uniqueWords, $lowerCaseWord);
            }
        }
        
        $textBlock = new TextBlockService($userId, $language);
        $textBlock->setProcessedWords($exampleSentenceWords);
        $textBlock->collectUniqueWords();
        $textBlock->updateAllPhraseIds();

        // Save example sentence.
        $exampleSentence->words = json_encode($textBlock->processedWords);
        $exampleSentence->unique_words = json_encode($textBlock->uniqueWords);
        $exampleSentence->save();

        return true;
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

    public function importFromCsv($userId, $language, $fileName, $delimiter, $onlyUpdate, $skipHeader) {
        $stageMapping = [
            'new' => 2,
            'ignored' => 1,
            'learned' => 0,
            '1' => -1,
            '2' => -2,
            '3' => -3,
            '4' => -4,
            '5' => -5,
            '6' => -6,
            '7' => -7,
        ];

        DB::disableQueryLog();
        $reader = Reader::createFromPath(storage_path('app/temp') . '/' . $fileName, 'r');
        $reader->setDelimiter($delimiter);
        $records = $reader->getRecords();
        $createdWords = 0;
        $updatedWords = 0;
        $rejectedWords = 0;

        // collect data from csv file
        DB::beginTransaction();
        foreach($records as $index => $record) {
            $lowerCaseWord = mb_strtolower($record[0]); 
            
            // skip header if option is enabled
            if ($index === 0 && $skipHeader) {
                continue;
            }

            // reject word if contains space character
            if (str_contains($lowerCaseWord, ' ')) {
                $rejectedWords ++;
                continue;
            }

            // reject word if it's too long
            if (mb_strlen($lowerCaseWord) >= 255) {
                $rejectedWords ++;
                continue;
            }

            // reject word if word field is missing
            if (mb_strlen($lowerCaseWord) === 0) {
                $rejectedWords ++;
                continue;
            }

            // reject word if it's stage is stage is an incorrect value
            $stage = isset($record[5]) ? $record[5] : 'learned';
            if (isset($record[5]) && !isset($stageMapping[$stage])) {
                $rejectedWords ++;
                continue;
            }

            // try to retrieve word
            $encounteredWord = EncounteredWord
                ::where('user_id', $userId)
                ->where('language', $language)
                ->where('word', $lowerCaseWord)
                ->first();

            // if does not exist, create it
            if (!$encounteredWord) {

                // reject word if does not exist and only update option is used
                if ($onlyUpdate) {
                    $rejectedWords ++;
                    continue;
                }

                $encounteredWord = new EncounteredWord();
                $encounteredWord->user_id = $userId;
                $encounteredWord->language = $language;
                $encounteredWord->word = $lowerCaseWord;
                $encounteredWord->translation = '';
                $encounteredWord->lemma = '';
                $encounteredWord->base_word = '';
                $encounteredWord->reading = '';
                $encounteredWord->base_word_reading = '';
                $encounteredWord->stage = 0;
                $encounteredWord->kanji = '';

                $createdWords ++;
            } else {
                $updatedWords ++;
            }

            // set translation
            if (isset($record[1])) {
                $encounteredWord->translation = $record[1];
            }
            
            // set lemma
            if (isset($record[2])) {
                $encounteredWord->base_word = $record[2];
            }
            
            // set reading
            if (isset($record[3])) {
                $encounteredWord->reading = $record[3];
            }
            
            // set lemma reading
            if (isset($record[4])) {
                $encounteredWord->base_word_reading = $record[4];
            }

            // set stage
            if (isset($record[5])) {
                $encounteredWord->setStage($stageMapping[$stage], true);
            }

            // save word with new data
            $encounteredWord->save();
            $this->reviewCardService->ensureWordCard($encounteredWord);

            // add word to accepted words list
            $acceptedWords[] = $lowerCaseWord;
        }

        DB::commit();

        $responseData = new \StdClass();
        $responseData->createdWords = $createdWords;
        $responseData->updatedWords = $updatedWords;
        $responseData->rejectedWords = $rejectedWords;
        
        return $responseData;
    }

    private function buildHardDeleteWordQuery(int $userId, string $language, array $filters)
    {
        $phrases = $filters['phrases'] ?? 'both';
        if ($phrases === 'only phrases') {
            return EncounteredWord::whereRaw('1 = 0');
        }

        $text = $filters['text'] ?? 'anytext';
        $bookId = (int) ($filters['book'] ?? -1);
        $chapterId = (int) ($filters['chapter'] ?? -1);
        $stage = (int) ($filters['stage'] ?? -999);
        $translation = $filters['translation'] ?? 'any';
        $wordsToSkip = config('linguacafe.words_to_skip');

        $query = EncounteredWord::select('id')
            ->where('user_id', $userId)
            ->where('language', $language)
            ->whereNotIn('word', $wordsToSkip);

        if ($text !== 'anytext') {
            $query->where(function ($query) use ($text) {
                $query->orWhere('word', 'like', '%' . $text . '%')
                    ->orWhere('reading', 'like', '%' . $text . '%');
            });
        }

        if ($bookId !== -1) {
            $filteredWords = $this->filteredVocabularyWords($userId, $language, $bookId, $chapterId);
            $query->whereIn('word', $filteredWords);
        }

        if ($stage !== -999) {
            $query->where('stage', $stage);
        }

        if ($translation === 'not empty') {
            $query->where('translation', '<>', '');
        }

        return $query;
    }

    private function filteredVocabularyWords(int $userId, string $language, int $bookId, int $chapterId): array
    {
        $filteredChapters = Chapter::where('user_id', $userId)
            ->where('language', $language)
            ->where('book_id', $bookId);

        if ($chapterId !== -1) {
            $filteredChapters->where('id', $chapterId);
        }

        $filteredWords = [];
        foreach ($filteredChapters->get() as $filteredChapter) {
            $chapterWords = json_decode($filteredChapter->unique_words) ?: [];
            foreach ($chapterWords as $chapterWord) {
                if (!in_array($chapterWord, $filteredWords, true)) {
                    $filteredWords[] = $chapterWord;
                }
            }
        }

        return $filteredWords;
    }

    /*
        Builds a search request. It's used for both searching and exporting vocabulary.
    */
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
}
