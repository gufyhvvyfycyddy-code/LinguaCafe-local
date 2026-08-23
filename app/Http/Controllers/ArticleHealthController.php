<?php

namespace App\Http\Controllers;

use App\Services\ArticleHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ArticleHealthController extends Controller
{
    public function __construct(private readonly ArticleHealthService $articleHealthService)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = Auth::user();
        $validated = $request->validate([
            'book_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'article_health' => $this->articleHealthService->report(
                (int) $user->id,
                (string) $user->selected_language,
                isset($validated['book_id']) ? (int) $validated['book_id'] : null,
            ),
        ]);
    }
}
