<?php

namespace App\Http\Controllers;

use App\Services\KnowledgeHygieneService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KnowledgeHygieneController extends Controller
{
    public function __construct(private KnowledgeHygieneService $service)
    {
    }

    public function preferences()
    {
        return response()->json($this->service->preferences(Auth::id()));
    }

    public function savePreferences(Request $request)
    {
        $validated = $request->validate([
            'columns' => 'required|array|max:16',
            'columns.*' => 'required|string|distinct',
            'views' => 'sometimes|array|max:20',
            'views.*.name' => 'required|string|max:80',
            'views.*.filter_state' => 'sometimes|array',
            'views.*.columns' => 'sometimes|array|max:16',
        ]);
        return response()->json($this->service->savePreferences(Auth::id(), $validated));
    }

    public function findReplacePreview(Request $request)
    {
        return response()->json($this->service->findReplacePreview(
            $request, Auth::id(), Auth::user()->selected_language,
        ));
    }

    public function applyFindReplace(Request $request)
    {
        $request->validate(['preview_fingerprint' => 'required|string|size:64']);
        $operation = $this->service->applyFindReplace(
            $request, Auth::id(), Auth::user()->selected_language,
        );
        return response()->json([
            'operation_id' => $operation->operation_id,
            'affected' => $operation->metadata['affected'] ?? 0,
        ]);
    }

    public function duplicates(Request $request)
    {
        return response()->json($this->service->duplicateCandidates(
            $request, Auth::id(), Auth::user()->selected_language,
        ));
    }

    public function mergePreview(Request $request)
    {
        $validated = $request->validate([
            'primary_review_card_id' => 'required|integer|min:1',
            'duplicate_review_card_id' => 'required|integer|min:1|different:primary_review_card_id',
        ]);
        return response()->json($this->service->mergePreview(
            $validated['primary_review_card_id'],
            $validated['duplicate_review_card_id'],
            Auth::id(),
            Auth::user()->selected_language,
        ));
    }

    public function applyMerge(Request $request)
    {
        $validated = $request->validate([
            'primary_review_card_id' => 'required|integer|min:1',
            'duplicate_review_card_id' => 'required|integer|min:1|different:primary_review_card_id',
            'preview_fingerprint' => 'required|string|size:64',
            'confirm' => 'required|accepted',
        ]);
        $operation = $this->service->applyMerge(
            $validated['primary_review_card_id'],
            $validated['duplicate_review_card_id'],
            $validated['preview_fingerprint'],
            Auth::id(),
            Auth::user()->selected_language,
        );
        return response()->json([
            'operation_id' => $operation->operation_id,
            'backup_id' => $operation->metadata['backup_id'] ?? null,
        ]);
    }

    public function recentDeletes()
    {
        return response()->json(['items' => $this->service->recentDeletes(
            Auth::id(), Auth::user()->selected_language,
        )]);
    }

    public function undo(string $operationId)
    {
        $operation = $this->service->undo(
            $operationId, Auth::id(), Auth::user()->selected_language,
        );
        return response()->json([
            'operation_id' => $operation->operation_id,
            'status' => $operation->status,
            'undone_at' => optional($operation->undone_at)->toISOString(),
        ]);
    }
}
