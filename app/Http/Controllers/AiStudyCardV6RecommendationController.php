<?php

namespace App\Http\Controllers;

use App\Services\AiStudyCardV6RequestPackageService;
use Illuminate\Http\Request;

class AiStudyCardV6RecommendationController extends Controller
{
    public function __construct(
        private AiStudyCardV6RequestPackageService $requestPackageService,
    )
    {
    }

    /**
     * V6-1: Build a provider-disabled AI recommendation request package.
     *
     * This endpoint does not call any AI provider and does not create or update
     * WordSense, ReviewCard, ReviewLog, FSRS, or pending-item state.
     */
    public function requestPackage(Request $request)
    {
        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'min:1'],
            'context_policy' => ['nullable', 'string', 'in:selected_items_only,selected_items_with_sentence'],
        ]);

        $result = $this->requestPackageService->buildRequestPackage(
            $request->user(),
            $validated['item_ids'],
            $validated['context_policy'] ?? null,
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'package' => $result['package'],
        ]);
    }
}
