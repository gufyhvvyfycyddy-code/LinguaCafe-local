<?php

namespace App\Http\Controllers;

use App\Services\ArticleHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ArticleHealthController extends Controller
{
    public function __construct(private readonly ArticleHealthService $articleHealthService)
    {
    }

    public function show(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'article_health' => $this->articleHealthService->report(
                (int) $user->id,
                (string) $user->selected_language,
            ),
        ]);
    }
}
