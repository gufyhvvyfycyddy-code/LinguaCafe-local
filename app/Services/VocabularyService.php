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
        private VocabularyQueryService $vocabularyQueryService,
    ) {
        $this->itemsPerPage = 30;
    }

    /**
     * Read-only proxies to VocabularyQueryService.
     *
     * The implementations were extracted in GLM-ArchitectureFirst1000-
     * SafeStability-1 to clarify the boundary between read-only query
     * paths and write paths. These proxies remain so existing callers
     * (tests, jobs, controllers that haven't migrated yet) keep working.
     */
    public function getUniqueWord($userId, $wordId) {
        return $this->vocabularyQueryService->getUniqueWord($userId, $wordId);
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

        if ($wordStage !== null) {
            $word->save();

            if ($word->stage < 0) {
                $this->reviewCardService->ensureWordCard($word);
            } else {
                $this->reviewCardService->disableWordCard($word);
            }

            // 桥接仅属于显式 legacy stage transition。
            if ($word->stage < 0 && !empty($word->translation)) {
                $this->bridgeWordToSense($word, $bridgeContext);
            }
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
                $this->wordSenseService->removeSenseFromReviewSystem($sense, true, true);
            }

            // Delete legacy word-type review cards and their review logs
            $legacyCards = ReviewCard::where('user_id', $userId)
                ->where('language_id', $language)
                ->where('target_type', ReviewCard::TARGET_WORD)
                ->whereIn('target_id', $ids)
                ->get();

            foreach ($legacyCards as $legacyCard) {
                \App\Models\ReviewLog::where('review_card_id', $legacyCard->id)
                    ->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->delete();
                $legacyCard->delete();
            }

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
        return $this->vocabularyQueryService->getPhrase($userId, $phraseId);
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
        return $this->vocabularyQueryService->getExampleSentence($userId, $targetType, $targetId);
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
        return $this->vocabularyQueryService->searchVocabulary($userId, $language, $text, $bookId, $chapterId, $stage, $phrases, $orderBy, $translation, $page, $languagesWithoutSpaces);
    }

    public function exportToCsv($userId, $language, $text, $bookId, $chapterId, $stage, $phrases, $orderBy, $translation, $fields, $languagesWithoutSpaces) {
        return $this->vocabularyQueryService->exportToCsv($userId, $language, $text, $bookId, $chapterId, $stage, $phrases, $orderBy, $translation, $fields, $languagesWithoutSpaces);
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
        Moved to VocabularyQueryService::buildSearchRequest() during
        GLM-ArchitectureFirst1000-SafeStability-1. The original implementation
        has been removed from VocabularyService to enforce the read/write
        boundary; VocabularyService now proxies searchVocabulary() and
        exportToCsv() through VocabularyQueryService.
    */

    public function searchKanji($userId, $language, $groupBy, $showUnknown) {
        return $this->vocabularyQueryService->searchKanji($userId, $language, $groupBy, $showUnknown);
    }

    public function getKanjiDetails($userId, $kanjiCharacter) {
        return $this->vocabularyQueryService->getKanjiDetails($userId, $kanjiCharacter);
    }
}
