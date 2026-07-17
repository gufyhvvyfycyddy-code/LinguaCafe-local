<?php

namespace App\Http\Controllers;

use App\Models\ReviewCard;
use App\Services\ReviewCardManageAccessService;
use App\Services\ReviewCardMarkerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewCardMarkerController extends Controller
{
    public function __construct(
        private ReviewCardManageAccessService $accessService,
        private ReviewCardMarkerService $markerService,
    ) {
    }

    public function update(Request $request, int $reviewCard): JsonResponse
    {
        $validated = $request->validate([
            'marker' => ['required', 'integer', 'min:' . ReviewCard::MARKER_MIN, 'max:' . ReviewCard::MARKER_MAX],
        ]);

        [$card] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard,
            Auth::id(),
            Auth::user()->selected_language,
        );

        $updated = $this->markerService->set($card, (int) $validated['marker']);

        return response()->json([
            'review_card_id' => $updated->id,
            'marker' => $updated->marker,
        ]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'marker' => ['required', 'integer', 'min:' . ReviewCard::MARKER_MIN, 'max:' . ReviewCard::MARKER_MAX],
        ]);

        return response()->json($this->markerService->setBulk(
            $validated['ids'],
            (int) $validated['marker'],
            Auth::id(),
            Auth::user()->selected_language,
        ));
    }
}
