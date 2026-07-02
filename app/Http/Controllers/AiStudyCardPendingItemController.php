<?php

namespace App\Http\Controllers;

use App\Services\AiStudyCardPendingItemService;
use Illuminate\Http\Request;

class AiStudyCardPendingItemController extends Controller
{
    public function __construct(
        private AiStudyCardPendingItemService $pendingItemService,
    ) {
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'chapter_id' => ['required', 'integer', 'min:1'],
            'text_block_index' => ['required', 'integer', 'min:0'],
            'sentence_index' => ['nullable', 'integer', 'min:0'],
            'sentence_id' => ['nullable', 'string', 'max:255'],
            'word' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (preg_match('/\s/u', trim((string) $value))) {
                        $fail('第一版只支持单个单词。');
                    }
                },
            ],
            'surface' => ['nullable', 'string', 'max:255'],
            'lemma' => ['nullable', 'string', 'max:255'],
            'sentence_text' => ['nullable', 'string', 'max:2000'],
            'source_payload' => ['nullable', 'array'],
        ]);

        $result = $this->pendingItemService->createOrGetPending($request->user(), $validated);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'success' => true,
            'created' => $result['created'],
            'message' => $result['message'],
            'item' => [
                'id' => $result['item']->id,
                'status' => $result['item']->status,
                'word' => $result['item']->word,
                'lemma' => $result['item']->lemma,
                'chapter_id' => $result['item']->chapter_id,
                'text_block_index' => $result['item']->text_block_index,
                'sentence_index' => $result['item']->sentence_index,
            ],
        ]);
    }
}
