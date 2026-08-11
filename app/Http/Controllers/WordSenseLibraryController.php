<?php

namespace App\Http\Controllers;

use App\Services\WordSenseLibraryQueryService;
use Illuminate\Http\Request;

class WordSenseLibraryController extends Controller
{
    public function __construct(
        private WordSenseLibraryQueryService $queryService,
    ) {
        //
    }

    public function data(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'q' => 'nullable|string|max:200',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = array_key_exists('q', $validated) && $validated['q'] !== null
            ? trim($validated['q'])
            : null;

        return response()->json($this->queryService->page(
            $user->id,
            $user->selected_language,
            $query,
            $validated['page'] ?? 1,
            $validated['per_page'] ?? 20,
        ));
    }
}
