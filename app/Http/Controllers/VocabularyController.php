<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

// services
use App\Services\VocabularyService;
use App\Services\TempFileService;

// request classes
use App\Http\Requests\Vocabulary\GetUniqueWordRequest;
use App\Http\Requests\Vocabulary\UpdateWordRequest;
use App\Http\Requests\Vocabulary\CreatePhraseRequest;
use App\Http\Requests\Vocabulary\UpdatePhraseRequest;
use App\Http\Requests\Vocabulary\GetPhraseRequest;
use App\Http\Requests\Vocabulary\DeletePhraseRequest;
use App\Http\Requests\Vocabulary\GetExampleSentenceRequest;
use App\Http\Requests\Vocabulary\CreateOrUpdateExampleSentenceRequest;
use App\Http\Requests\Vocabulary\SearchVocabularyRequest;
use App\Http\Requests\Vocabulary\ExportToCsvRequest;
use App\Http\Requests\Vocabulary\SearchKanjiRequest;
use App\Http\Requests\Vocabulary\GetKanjiDetailsRequest;
use App\Http\Requests\Vocabulary\ImportFromCsvRequest;

class VocabularyController extends Controller {
    private $vocabularyService;
    private $tempFileService;

    public function __construct(VocabularyService $vocabularyService, TempFileService $tempFileService) {
        $this->vocabularyService = $vocabularyService;
        $this->tempFileService = $tempFileService;
    }


    public function getUniqueWord($wordId, GetUniqueWordRequest $request) {
        $userId = Auth::user()->id;

        try {
            $word = $this->vocabularyService->getUniqueWord($userId, $wordId);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($word, 200);
    }

    public function updateWord(UpdateWordRequest $request) {
        $userId = Auth::user()->id;
        $wordId = $request->post('id');
        $wordData = [];
        $wordStage = null;

        if ($request->has('translation')) {
            $wordData['translation'] = $request->translation === NULL ? '' : $request->translation;
        }

        if ($request->has('reading')) {
            $wordData['reading'] = $request->reading === NULL ? '' : $request->reading;
        }

        if ($request->has('base_word')) {
            $wordData['base_word'] = $request->base_word === NULL ? '' : $request->base_word;
        }

        if ($request->has('study_base')) {
            $wordData['study_base'] = $request->study_base === NULL ? '' : $request->study_base;
        }

        if ($request->has('base_word_reading')) {
            $wordData['base_word_reading'] = $request->base_word_reading === NULL ? '' : $request->base_word_reading;
        }

        if (isset($request->lookup_count)) {
            $wordData['lookup_count'] = $request->lookup_count;
        }

        if (isset($request->read_count)) {
            $wordData['read_count'] = $request->read_count;
        }

        if (isset($request->relearning)) {
            $wordData['relearning'] = boolval($request->relearning);
        }

        if (isset($request->stage)) {
            $wordStage = $request->stage;
        }

        $bridgeContext = [];
        if ($request->has('chapter_id')) {
            $bridgeContext['chapter_id'] = (int) $request->post('chapter_id');
        }
        if ($request->has('sentence_index')) {
            $bridgeContext['sentence_index'] = (int) $request->post('sentence_index');
        }
        if ($request->has('word')) {
            $bridgeContext['word'] = (string) $request->post('word');
        }
        if ($request->has('translation')) {
            $bridgeContext['translation'] = (string) $request->post('translation');
        }

        try {
            $this->vocabularyService->updateWord($userId, $wordId, $wordData, $wordStage, $bridgeContext);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        // Save or delete user study_base rule
        if ($request->has('study_base')) {
            $word = \App\Models\EncounteredWord::where('id', $wordId)
                ->where('user_id', $userId)
                ->first();
            if ($word) {
                $surface = mb_strtolower(trim($word->word), 'UTF-8');
                $studyBase = $request->study_base === NULL || $request->study_base === ''
                    ? '' : mb_strtolower(trim($request->study_base), 'UTF-8');
                $baseWord = $word->base_word
                    ? mb_strtolower(trim($word->base_word), 'UTF-8')
                    : '';

                $language = $word->language;

                if ($surface !== '' && $studyBase !== '' && $language !== '') {
                    if ($studyBase !== $baseWord) {
                        // User set a custom study_base → save rule
                        \App\Models\UserStudyBaseRule::updateOrCreate(
                            [
                                'user_id' => $userId,
                                'language' => $language,
                                'surface' => $surface,
                            ],
                            ['study_base' => $studyBase]
                        );
                    } else {
                        // User reset study_base to match base_word → delete rule
                        \App\Models\UserStudyBaseRule::where('user_id', $userId)
                            ->where('language', $language)
                            ->where('surface', $surface)
                            ->delete();
                    }
                }
            }
        }

        return response()->json('Word has been successfully updated.', 200);
    }

    public function deleteWord(Request $request) {
        $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        try {
            $this->vocabularyService->hardDeleteWord($userId, $language, (int) $request->post('id'));
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('词条已彻底删除。', 200);
    }

    public function batchIgnoreWords(Request $request) {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $userId = Auth::user()->id;
        $ignored = 0;

        foreach ($request->post('ids') as $wordId) {
            try {
                if ($this->vocabularyService->ignoreWord($userId, (int) $wordId)) {
                    $ignored++;
                }
            } catch (\Exception $e) {
                // skip individual failures, continue with remaining
            }
        }

        return response()->json(['ignored' => $ignored, 'total' => count($request->post('ids'))], 200);
    }

    public function batchDeleteWords(Request $request) {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $userId = Auth::user()->id;
        $deleted = 0;

        foreach ($request->post('ids') as $wordId) {
            try {
                if ($this->vocabularyService->softDeleteWord($userId, (int) $wordId)) {
                    $deleted++;
                }
            } catch (\Exception $e) {
                // skip individual failures, continue with remaining
            }
        }

        return response()->json(['deleted' => $deleted, 'total' => count($request->post('ids'))], 200);
    }

    public function batchHardDeleteWords(Request $request) {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        try {
            $deleted = $this->vocabularyService->hardDeleteWordsByIds($userId, $language, $request->post('ids'));
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json(['deleted' => $deleted, 'total' => count($request->post('ids'))], 200);
    }

    public function bulkHardDeleteWordsCount(Request $request) {
        $filters = $this->validatedBulkDeleteFilters($request);
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        try {
            $count = $this->vocabularyService->countHardDeletableWordsByFilters($userId, $language, $filters);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json(['count' => $count], 200);
    }

    public function bulkHardDeleteWords(Request $request) {
        $filters = $this->validatedBulkDeleteFilters($request);
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        try {
            $deleted = $this->vocabularyService->hardDeleteWordsByFilters($userId, $language, $filters);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json(['deleted' => $deleted], 200);
    }

    private function validatedBulkDeleteFilters(Request $request): array
    {
        $validated = $request->validate([
            'filters' => ['required', 'array'],
            'filters.text' => ['required', 'string'],
            'filters.book' => ['required', 'numeric'],
            'filters.chapter' => ['required', 'numeric'],
            'filters.stage' => ['required', 'numeric'],
            'filters.phrases' => ['required', 'string'],
            'filters.orderBy' => ['required', 'string'],
            'filters.translation' => ['required', 'string'],
        ]);

        return $validated['filters'];
    }

    public function getPhrase($phraseId, GetPhraseRequest $request) {
        $userId = Auth::user()->id;

        try {
            $phrase = $this->vocabularyService->getPhrase($userId, $phraseId);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($phrase, 200);
    }

    public function createPhrase(CreatePhraseRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $words = json_decode($request->words);
        $stage = $request->stage;
        $reading = is_null($request->reading) ? '' : $request->reading;
        $translation = is_null($request->translation) ? '' : $request->translation;
        $languagesWithoutSpaces = config('linguacafe.languages.languages_without_spaces');

        try {
            $phraseId = $this->vocabularyService->createPhrase($userId, $language, $words, $stage, $reading, $translation, $languagesWithoutSpaces);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($phraseId, 200);
    }

    public function updatePhrase(UpdatePhraseRequest $request) {
        $userId = Auth::user()->id;
        $phraseId = $request->post('id');
        $phraseData = [];
        $phraseStage = null;

        if ($request->has('translation')) {
            $phraseData['translation'] = $request->translation === NULL ? '' : $request->translation;
        }

        if ($request->has('reading')) {
            $phraseData['reading'] = $request->reading === NULL ? '' : $request->reading;
        }

        if (isset($request->lookup_count)) {
            $phraseData['lookup_count'] = $request->lookup_count;
        }

        if (isset($request->relearning)) {
            $phraseData['relearning'] = boolval($request->relearning);
        }

        if (isset($request->stage)) {
            $phraseStage = $request->stage;
        }

        try {
            $this->vocabularyService->updatePhrase($userId, $phraseId, $phraseData, $phraseStage);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Phrase has been successfully updated.', 200);
    }

    public function deletePhrase(DeletePhraseRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $phraseId = $request->post('phraseId');
        
        try {
            $this->vocabularyService->deletePhrase($userId, $language, $phraseId);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Phrase has been successfully deleted.', 200);
    }

    public function getExampleSentence($targetType, $targetId, GetExampleSentenceRequest $request) {
        $userId = Auth::user()->id;
        
        try {
            $exampleSentence = $this->vocabularyService->getExampleSentence($userId, $targetType, $targetId);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($exampleSentence, 200);
    }

    public function createOrUpdateExampleSentence(CreateOrUpdateExampleSentenceRequest $request) {
        $language = Auth::user()->selected_language;
        $userId = Auth::user()->id;
        $targetType = $request->targetType;
        $targetId = $request->targetId;
        $exampleSentenceWords = json_decode($request->exampleSentenceWords);

        try {
            $this->vocabularyService->createOrUpdateExampleSentence($userId, $language, $targetType, $targetId, $exampleSentenceWords);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Example sentence has been successfully saved.', 200);
    }

    public function searchVocabulary(SearchVocabularyRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $text = $request->text;
        $bookId = $request->book;
        $chapterId = $request->chapter;
        $stage = $request->stage;
        $phrases = $request->phrases;
        $orderBy = $request->orderBy;
        $translation = $request->translation;
        $page = $request->page; 
        $languagesWithoutSpaces = config('linguacafe.languages.languages_without_spaces');

        try {
            $searchResults = $this->vocabularyService->searchVocabulary($userId, $language, $text, $bookId, $chapterId, $stage, $phrases, $orderBy, $translation, $page, $languagesWithoutSpaces);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($searchResults, 200);
    }

    public function exportToCsv(ExportToCsvRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $text = $request->post('text');
        $bookId = $request->post('book');
        $chapterId = $request->post('chapter');
        $stage = $request->post('stage');
        $phrases = $request->post('phrases');
        $orderBy = $request->post('orderBy');
        $translation = $request->post('translation');
        $fields = $request->post('fields');
        $languagesWithoutSpaces = config('linguacafe.languages.languages_without_spaces');

        try {
            $csv = $this->vocabularyService->exportToCsv(
                $userId,
                $language,
                $text,
                $bookId,
                $chapterId,
                $stage,
                $phrases,
                $orderBy,
                $translation,
                $fields,
                $languagesWithoutSpaces
            );
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        $csv->output('vocabulary.csv');
        return response('', 200);
    }

    public function searchKanji(SearchKanjiRequest $request) {
        $language = Auth::user()->selected_language;
        $userId = Auth::user()->id;
        $groupBy = $request->post('kanjiGroupBy');
        $showUnknown = $request->post('showUnknown');

        try {
            $kanji = $this->vocabularyService->searchKanji($userId, $language, $groupBy, $showUnknown);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($kanji, 200);
    }

    public function getKanjiDetails(GetKanjiDetailsRequest $request) {
        $userId = Auth::user()->id;
        $kanjiCharacter = $request->post('kanji');

        try {
            $kanjiData = $this->vocabularyService->getkanjiDetails($userId, $kanjiCharacter);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($kanjiData, 200);
    }

    public function importFromCsv(ImportFromCsvRequest $request) {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $importFile = $request->file('importFile');
        $onlyUpdate = $request->post('onlyUpdate');
        $skipHeader = $request->post('skipHeader');
        $delimiter = $request->post('delimiter');

        try {
            $fileName = $this->tempFileService->moveFileToTempFolder($userId, $importFile);
            $importResponseData = $this->vocabularyService->importFromCsv($userId, $language, $fileName, $delimiter, $onlyUpdate, $skipHeader);
        } catch (\Exception $e) {
            $this->tempFileService->deleteTempFile($fileName);
            abort(500, $e->getMessage());
        }

        $this->tempFileService->deleteTempFile($fileName);
        return response()->json($importResponseData, 200);
    }
}
