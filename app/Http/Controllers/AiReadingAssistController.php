<?php

namespace App\Http\Controllers;

use App\Services\AiReadingAssistService;
use App\Services\AiReadingAssistV2Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AiReadingAssistController extends Controller
{
    public function __construct(
        private AiReadingAssistService $aiReadingAssistService,
    ) {
    }

    public function source(Request $request)
    {
        $data = $request->validate([
            'chapterId' => ['required', 'integer', 'min:1'],
            'schema_version' => ['nullable', 'string'],
        ]);

        $schemaVersion = $data['schema_version'] ?? null;
        $markedTargets = null;
        if ($schemaVersion === AiReadingAssistV2Service::SCHEMA_VERSION) {
            $validatedV2 = $request->validate([
                'marked_targets' => ['nullable', 'array'],
                'marked_targets.*.kind' => ['required_with:marked_targets', 'in:word,phrase'],
                'marked_targets.*.start_word_index' => ['required_with:marked_targets', 'integer', 'min:0'],
                'marked_targets.*.end_word_index' => ['required_with:marked_targets', 'integer', 'min:0'],
            ]);
            if (array_key_exists('marked_targets', $validatedV2)) {
                $markedTargets = $validatedV2['marked_targets'];
            }
        }

        $result = $this->aiReadingAssistService->buildPromptForChapter(
            Auth::user()->id,
            Auth::user()->selected_language,
            (int) $data['chapterId'],
            $schemaVersion,
            $markedTargets,
        );

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    public function current(int $chapterId)
    {
        $result = $this->aiReadingAssistService->getCurrentAssist(Auth::user()->id, Auth::user()->selected_language, $chapterId);
        return response()->json($result, $result['success'] ? 200 : 404);
    }

    public function preview(Request $request)
    {
        $request->validate([
            'chapterId' => ['required', 'integer', 'min:1'],
            'schema_version' => ['nullable', 'string'],
        ]);

        $schemaVersion = $request->post('schema_version');
        if ($schemaVersion === AiReadingAssistV2Service::SCHEMA_VERSION) {
            $request->validate([
                'parts' => ['required', 'array', 'min:1'],
            ]);
            $payload = $request->post('parts');
        } else {
            $request->validate([
                'aiText' => ['required', 'string', 'min:1'],
            ]);
            $payload = (string) $request->post('aiText');
        }

        $result = $this->aiReadingAssistService->previewImport(
            Auth::user()->id,
            Auth::user()->selected_language,
            (int) $request->post('chapterId'),
            $payload,
            $schemaVersion,
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function lookup(int $chapterId, Request $request)
    {
        $request->validate([
            'word' => ['required', 'string', 'min:1'],
            'lemma' => ['required', 'string'],
            'sentence_index' => ['required', 'integer', 'min:0'],
        ]);

        $result = $this->aiReadingAssistService->lookupSuggestions(
            Auth::user()->id,
            Auth::user()->selected_language,
            $chapterId,
            $request->query('word', ''),
            $request->query('lemma', ''),
            (int) $request->query('sentence_index', 0),
        );

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'chapterId' => ['required', 'integer', 'min:1'],
            'schema_version' => ['nullable', 'string'],
            'apply_trust_ai' => ['nullable', 'boolean'],
        ]);

        $schemaVersion = $request->post('schema_version');
        if ($schemaVersion === AiReadingAssistV2Service::SCHEMA_VERSION) {
            $request->validate([
                'parts' => ['required', 'array', 'min:1'],
            ]);
            $payload = $request->post('parts');
        } else {
            $request->validate([
                'aiText' => ['required', 'string', 'min:1'],
            ]);
            $payload = (string) $request->post('aiText');
        }

        $result = $this->aiReadingAssistService->confirmImport(
            Auth::user()->id,
            Auth::user()->selected_language,
            (int) $request->post('chapterId'),
            $payload,
            $schemaVersion,
            (bool) $request->boolean('apply_trust_ai'),
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
