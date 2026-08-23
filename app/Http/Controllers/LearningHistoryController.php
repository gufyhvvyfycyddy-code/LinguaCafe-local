<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Services\LearningHistoryQueryService;
use App\Services\ReviewStudyTimezoneService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LearningHistoryController extends Controller
{
    public function __construct(
        private LearningHistoryQueryService $queryService,
        private ReviewStudyTimezoneService $timezoneService,
        private HomeController $homeController,
    ) {
    }

    public function index()
    {
        return $this->homeController->index();
    }

    /**
     * Return one server-paginated, immutable learning/review timeline.
     *
     * Each row is a flat historical event. Fields prefixed with `current_`
     * describe the card snapshot at `meta.current_state_as_of`, not its state
     * when the historical event occurred.
     */
    public function data(Request $request)
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'filter' => ['nullable', Rule::in(LearningHistoryQueryService::FILTERS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();
        $today = $this->timezoneService->localDate(Carbon::now());
        $dateFrom = $validated['date_from'] ?? $today;
        $dateTo = $validated['date_to'] ?? $today;
        $result = $this->queryService->paginate(
            (int) $user->id,
            (string) $user->selected_language,
            $dateFrom,
            $dateTo,
            $validated['filter'] ?? LearningHistoryQueryService::FILTER_ALL,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 25),
        );
        $result['meta']['reading_goal_target'] = (int) (Goal::query()
            ->where('user_id', $user->id)
            ->where('language', $user->selected_language)
            ->where('type', 'learn_words')
            ->value('quantity') ?? 0);

        return response()->json($result);
    }
}
