<?php

namespace App\Http\Controllers;

use App\Models\ReviewCardSavedSearch;
use App\Services\StudyOverviewQueryService;
use Illuminate\Http\Request;

class StudyOverviewController extends Controller
{
    public function __construct(private StudyOverviewQueryService $queryService, private HomeController $homeController)
    {
    }

    public function index()
    {
        return $this->homeController->index();
    }

    public function data(Request $request)
    {
        $validated = $request->validate([
            'period' => ['nullable', 'integer', 'in:30,90,365'],
            'saved_search_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user();
        $period = (int) ($validated['period'] ?? 30);
        $savedSearchId = isset($validated['saved_search_id']) ? (int) $validated['saved_search_id'] : null;
        $result = $this->queryService->build($user->id, $user->selected_language, $period, $savedSearchId);
        $result['saved_searches'] = ReviewCardSavedSearch::query()
            ->where('user_id', $user->id)->where('language_id', $user->selected_language)
            ->orderBy('name')->get(['id', 'name']);

        return response()->json($result);
    }
}
