<?php

namespace App\Http\Controllers;

use App\Services\WordSenseTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WordSenseTagController extends Controller
{
    public function __construct(private WordSenseTagService $tagService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'items' => $this->tagService->list(
                $request->user()->id,
                $request->user()->selected_language,
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        return response()->json($this->tagService->create(
            $request->user()->id,
            $request->user()->selected_language,
            $validated['name'],
        ), 201);
    }

    public function update(Request $request, int $tag): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        return response()->json($this->tagService->rename(
            $tag,
            $request->user()->id,
            $request->user()->selected_language,
            $validated['name'],
        ));
    }

    public function destroy(Request $request, int $tag)
    {
        $this->tagService->delete(
            $tag,
            $request->user()->id,
            $request->user()->selected_language,
        );

        return response()->noContent();
    }

    public function bulkAssignments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'review_card_ids' => ['required', 'array', 'min:1', 'max:' . WordSenseTagService::MAX_BULK_CARDS],
            'review_card_ids.*' => ['integer', 'min:1', 'distinct'],
            'tag_ids' => ['required', 'array', 'min:1', 'max:' . WordSenseTagService::MAX_BULK_TAGS],
            'tag_ids.*' => ['integer', 'min:1', 'distinct'],
            'action' => ['required', 'string', 'in:add,remove'],
        ]);

        return response()->json([
            'result' => $this->tagService->applyToReviewCards(
                $request->user()->id,
                $request->user()->selected_language,
                $validated['review_card_ids'],
                $validated['tag_ids'],
                $validated['action'],
            ),
        ]);
    }
}
