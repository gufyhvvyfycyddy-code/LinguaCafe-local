<?php

namespace App\Http\Controllers;

use App\Services\ReviewCardSavedSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewCardSavedSearchController extends Controller
{
    public function __construct(private ReviewCardSavedSearchService $service)
    {
    }

    public function index()
    {
        $user = Auth::user();

        return response()->json(['items' => $this->service->list($user->id, $user->selected_language)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'filter_state' => ['required', 'array'],
        ]);
        $user = Auth::user();
        $row = $this->service->create($user->id, $user->selected_language, $validated['name'], $validated['filter_state']);

        return response()->json($row, 201);
    }

    public function update(int $savedSearch, Request $request)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'filter_state' => ['sometimes', 'required', 'array'],
        ]);
        $user = Auth::user();
        $row = $this->service->update(
            $savedSearch,
            $user->id,
            $user->selected_language,
            $validated['name'] ?? null,
            $validated['filter_state'] ?? null,
        );

        return response()->json($row);
    }

    public function destroy(int $savedSearch)
    {
        $user = Auth::user();
        $this->service->delete($savedSearch, $user->id, $user->selected_language);

        return response()->noContent();
    }
}
