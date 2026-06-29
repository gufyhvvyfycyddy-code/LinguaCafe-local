<?php

namespace App\Http\Controllers;

use App\Services\AiReadingAssistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AiReadingAssistController extends Controller
{
    public function __construct(
        private AiReadingAssistService $aiReadingAssistService,
    ) {
    }

    /**
     * Return the AI analysis prompt for a chapter.
     *
     * POST /chapters/ai-assist/source
     */
    public function source(Request $request)
    {
        $request->validate([
            'chapterId' => ['required', 'integer', 'min:1'],
        ]);

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $chapterId = (int) $request->post('chapterId');

        $result = $this->aiReadingAssistService->buildPromptForChapter($userId, $language, $chapterId);

        if (!$result['success']) {
            return response()->json($result, 404);
        }

        return response()->json($result, 200);
    }

    /**
     * Preview-parse AI returned content without writing anything.
     *
     * POST /chapters/ai-assist/preview
     */
    /**
     * Get the current saved AI reading assist data for a chapter.
     *
     * GET /chapters/ai-assist/current/{chapterId}
     */
    public function current(int $chapterId)
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $result = $this->aiReadingAssistService->getCurrentAssist($userId, $language, $chapterId);

        $statusCode = $result['success'] ? 200 : 404;

        return response()->json($result, $statusCode);
    }

    public function preview(Request $request)
    {
        $request->validate([
            'chapterId' => ['required', 'integer', 'min:1'],
            'aiText' => ['required', 'string', 'min:1'],
        ]);

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $chapterId = (int) $request->post('chapterId');
        $aiText = $request->post('aiText');

        $result = $this->aiReadingAssistService->previewImport($userId, $language, $chapterId, $aiText);

        $statusCode = $result['success'] ? 200 : 422;

        return response()->json($result, $statusCode);
    }

    /**
     * Confirm and save the AI analysis result.
     *
     * POST /chapters/ai-assist/confirm
     */
    /**
     * Look up AI vocabulary and phrase suggestions for a word in a sentence.
     *
     * GET /chapters/ai-assist/lookup/{chapterId}
     */
    public function lookup(int $chapterId, Request $request)
    {
        $request->validate([
            'word' => ['required', 'string', 'min:1'],
            'lemma' => ['required', 'string'],
            'sentence_index' => ['required', 'integer', 'min:0'],
        ]);

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $word = $request->query('word', '');
        $lemma = $request->query('lemma', '');
        $sentenceIndex = (int) $request->query('sentence_index', 0);

        $result = $this->aiReadingAssistService->lookupSuggestions($userId, $language, $chapterId, $word, $lemma, $sentenceIndex);

        $statusCode = $result['success'] ? 200 : 404;

        return response()->json($result, $statusCode);
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'chapterId' => ['required', 'integer', 'min:1'],
            'aiText' => ['required', 'string', 'min:1'],
        ]);

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $chapterId = (int) $request->post('chapterId');
        $aiText = $request->post('aiText');

        $result = $this->aiReadingAssistService->confirmImport($userId, $language, $chapterId, $aiText);

        $statusCode = $result['success'] ? 200 : 422;

        return response()->json($result, $statusCode);
    }
}
