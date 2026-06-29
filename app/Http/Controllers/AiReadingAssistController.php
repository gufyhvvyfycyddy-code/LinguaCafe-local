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
}
