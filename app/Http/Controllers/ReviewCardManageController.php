<?php

namespace App\Http\Controllers;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use App\Services\LifecycleConflictException;
use App\Services\ReviewCardInfoQueryService;
use App\Services\ReviewCardLifecycleCommandService;
use App\Services\ReviewCardManageAccessService;
use App\Services\ReviewCardManageItemSerializerService;
use App\Services\ReviewCardService;
use App\Services\ReviewCardExportService;
use App\Services\ReviewCardManageQueryService;
use App\Services\ReviewCardManageMutationService;
use App\Services\InvalidBrowserSearchException;
use App\Services\SenseReviewLeechQueryService;
use App\Services\WordSenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ReviewCardManageController extends Controller
{
    public function __construct(
        private WordSenseService $wordSenseService,
        private ReviewCardService $reviewCardService,
        private ReviewCardExportService $exportService,
        private ReviewCardManageQueryService $queryService,
        private ReviewCardManageItemSerializerService $itemSerializer,
        private ReviewCardManageMutationService $mutationService,
        private ReviewCardManageAccessService $accessService,
        private ReviewCardLifecycleCommandService $lifecycleCommandService,
        private SenseReviewLeechQueryService $leechQueryService,
        private ReviewCardInfoQueryService $cardInfoQueryService,
    )
    {
    }
    public function data(Request $request): JsonResponse
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $perPage = min((int) $request->input('per_page', 20), 100);
        $filter = $request->input('filter', 'enabled');
        $includeLeech = $request->boolean('include_leech') || in_array($filter, ['leech', 'struggling'], true);

        // ADR-0013: Parse criteria exactly ONCE per request. The same criteria
        // is reused for the 422 guard, search_meta, and buildFromCriteria().
        // buildFromCriteria() does NOT re-parse.
        try {
            $criteria = $this->queryService->parseCriteria($request);
        } catch (InvalidBrowserSearchException $e) {
            return response()->json($e->toResponseArray(), 422);
        }

        $query = $this->queryService->buildFromCriteria($request, $criteria, $userId, $language);

        // Paginate
        $paginator = $query->paginate($perPage);
        $cards = $paginator->getCollection();

        $items = $this->itemSerializer->buildItems($cards, $userId, $language);

        // ADR-0011: Inject leech descriptors when requested or when filtering by leech/struggling.
        if ($includeLeech && $items->count() > 0) {
            $cardIds = $items->pluck('review_card_id')->all();
            $leechMap = $this->leechQueryService->describeForCards($cardIds, $cards);
            $items = $items->map(function ($item) use ($leechMap) {
                $desc = $leechMap[$item['review_card_id']] ?? null;
                if ($desc) {
                    $item['leech_status'] = $desc['status'];
                    $item['leech_severity'] = $desc['severity'];
                    $item['leech_reasons'] = $desc['reasons'];
                    $item['leech_suggestions'] = $desc['suggestions'];
                } else {
                    $item['leech_status'] = 'stable';
                    $item['leech_severity'] = 0;
                    $item['leech_reasons'] = [];
                    $item['leech_suggestions'] = [];
                }
                return $item;
            });
        }

        return response()->json([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            // ADR-0012: search_meta lets the frontend render token chips without
            // re-parsing. The raw_query preserves the user's input, text_query is
            // the plain-text portion, tokens are normalized advanced tokens, and
            // advanced is true when at least one advanced token was recognized.
            'search_meta' => $criteria->toSearchMeta(),
        ]);
    }
    /**
     * GET /review-cards/manage/export
     * Export current filtered/sorted results as JSON download.
     * Reuses the same security constraints and filtering logic as data().
     * Does NOT paginate — exports all matching cards up to EXPORT_LIMIT.
     */
    public function export(Request $request): JsonResponse
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        // ADR-0013: Parse criteria exactly ONCE. Reuse for buildFromCriteria().
        try {
            $criteria = $this->queryService->parseCriteria($request);
        } catch (InvalidBrowserSearchException $e) {
            return response()->json($e->toResponseArray(), 422);
        }

        $query = $this->queryService->buildFromCriteria($request, $criteria, $userId, $language);
        $total = $query->count();

        if ($total > ReviewCardExportService::EXPORT_LIMIT) {
            return response()->json([
                'message' => '当前筛选结果超过 ' . ReviewCardExportService::EXPORT_LIMIT . ' 条，请缩小筛选范围后再导出。',
                'total' => $total,
                'limit' => ReviewCardExportService::EXPORT_LIMIT,
            ], 422);
        }

        $cards = $query->get();
        $items = $this->itemSerializer->buildItems($cards, $userId, $language);

        $requestedFields = $request->input('fields', []);
        if (!is_array($requestedFields)) {
            $requestedFields = [];
        }

        $fieldResult = $this->exportService->resolveFields($requestedFields);
        if ($fieldResult['error'] !== null) {
            return response()->json($fieldResult['error'], 422);
        }

        $selectedFields = $fieldResult['selectedFields'];

        $filters = [
            'q' => trim($request->input('q', '')),
            'filter' => $request->input('filter', 'enabled'),
            'fsrs_states' => $request->input('fsrs_states', []),
            'due_range' => $request->input('due_range', 'all'),
            'reps_min' => $request->input('reps_min'),
            'lapses_min' => $request->input('lapses_min'),
            'sort_by' => $request->input('sort_by', 'id'),
            'sort_dir' => $request->input('sort_dir', 'desc'),
        ];

        $data = $this->exportService->buildJsonExportData($items, $selectedFields, $filters, $language);
        $filename = 'review-cards-export-' . now()->format('Ymd-His') . '.json';

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * GET /review-cards/manage/export-anki-tsv
     * Export current filtered/sorted results as Anki-compatible TSV.
     * Reuses ReviewCardManageQueryService via queryService->build() — no mode, no card_ids, no all/selected.
     * Fixed 13 columns, Front/Back are HTML-rendered question/answer faces.
     */
    public function exportAnkiTsv(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        // ADR-0013: Parse criteria exactly ONCE. Reuse for buildFromCriteria().
        try {
            $criteria = $this->queryService->parseCriteria($request);
        } catch (InvalidBrowserSearchException $e) {
            return response()->json($e->toResponseArray(), 422);
        }

        $query = $this->queryService->buildFromCriteria($request, $criteria, $userId, $language);

        $total = $query->count();
        if ($total > ReviewCardExportService::EXPORT_LIMIT) {
            return response()->json([
                'message' => '导出数量超过上限。',
                'total' => $total,
                'limit' => ReviewCardExportService::EXPORT_LIMIT,
            ], 422);
        }

        $cards = $query->get();
        $items = $this->itemSerializer->buildItems($cards, $userId, $language);

        $tsv = $this->exportService->buildAnkiTsv($items);
        $filename = 'review-cards-anki-' . now()->format('Ymd-His') . '.tsv';

        return response($tsv, 200)
            ->header('Content-Type', 'text/tab-separated-values; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('X-Export-Count', count($items));
    }

    /**
     * GET /review-cards/manage/export-csv
     * Export current filtered/sorted results as CSV download.
     * Reuses ReviewCardManageQueryService, buildItems, and ReviewCardExportService export field/limit rules.
     * No mode, no card_ids, no all/selected. Uses fputcsv + BOM.
     */
    public function exportCsv(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        // ADR-0013: Parse criteria exactly ONCE. Reuse for buildFromCriteria().
        try {
            $criteria = $this->queryService->parseCriteria($request);
        } catch (InvalidBrowserSearchException $e) {
            return response()->json($e->toResponseArray(), 422);
        }

        $query = $this->queryService->buildFromCriteria($request, $criteria, $userId, $language);
        $total = $query->count();

        if ($total > ReviewCardExportService::EXPORT_LIMIT) {
            return response()->json([
                'message' => '当前筛选结果超过 ' . ReviewCardExportService::EXPORT_LIMIT . ' 条，请缩小筛选范围后再导出。',
                'total' => $total,
                'limit' => ReviewCardExportService::EXPORT_LIMIT,
            ], 422);
        }

        $cards = $query->get();
        $items = $this->itemSerializer->buildItems($cards, $userId, $language);

        $requestedFields = $request->input('fields', []);
        if (!is_array($requestedFields)) {
            $requestedFields = [];
        }

        $fieldResult = $this->exportService->resolveFields($requestedFields);
        if ($fieldResult['error'] !== null) {
            return response()->json($fieldResult['error'], 422);
        }

        $selectedFields = $fieldResult['selectedFields'];

        $csv = $this->exportService->buildCsv($items, $selectedFields);
        $filename = 'review-cards-' . now()->format('Ymd-His') . '.csv';

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('X-Export-Count', count($items));
    }

    public function logs(int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::user()->id, Auth::user()->selected_language
        );

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $logs = ReviewLog::query()
            ->where('review_card_id', $card->id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->orderBy('reviewed_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn (ReviewLog $log) => [
                'id' => $log->id,
                'rating' => $log->rating,
                'source' => $log->source,
                'reviewed_at' => optional($log->reviewed_at)->toISOString(),
                'previous_state' => $log->previous_state,
                'new_state' => $log->new_state,
                'previous_due_at' => optional($log->previous_due_at)->toISOString(),
                'new_due_at' => optional($log->new_due_at)->toISOString(),
                'previous_stability' => $log->previous_stability,
                'new_stability' => $log->new_stability,
                'previous_difficulty' => $log->previous_difficulty,
                'new_difficulty' => $log->new_difficulty,
                // ADR-0009: undo audit fields — retained for audit, not
                // excluded from the management page log trail.
                'undone' => $log->undone_at !== null,
                'undone_at' => optional($log->undone_at)->toISOString(),
                'undo_source' => $log->undo_source,
            ]);

        return response()->json([
            'items' => $logs,
        ]);
    }

    /**
     * PATCH /review-cards/manage/{reviewCard}
     * Edit WordSense text fields only.
     */
    public function update(Request $request, int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::user()->id, Auth::user()->selected_language
        );

        $this->mutationService->updateSenseTextFields($sense, $request);

        return response()->json($this->itemSerializer->serializeCard($card->fresh(), $sense->fresh()));
    }

    /**
     * PATCH /review-cards/manage/{reviewCard}/enabled
     * Legacy archive/restore endpoint (ADR-0010 compatibility).
     *
     * Delegates to ReviewCardLifecycleCommandService:
     *   enabled=true  → action=restore (from archived)
     *   enabled=false → action=archive (from active/suspended)
     *
     * Idempotent: if the card is already in the target state, returns 200
     * without calling the CommandService. This preserves the old behavior
     * where setting enabled=true on an already-enabled card was a no-op.
     *
     * The response format is preserved for backward compatibility.
     */
    public function enabled(Request $request, int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::user()->id, Auth::user()->selected_language
        );

        $enabled = $request->boolean('enabled');

        // ADR-0010: Idempotency — if already in the target state, return 200.
        $currentState = $card->lifecycle_state ?? ReviewCard::LIFECYCLE_ACTIVE;
        if ($enabled && $currentState === ReviewCard::LIFECYCLE_ACTIVE) {
            return response()->json($this->itemSerializer->serializeCard($card->fresh(), $sense));
        }
        if (!$enabled && $currentState === ReviewCard::LIFECYCLE_ARCHIVED) {
            return response()->json($this->itemSerializer->serializeCard($card->fresh(), $sense));
        }

        $action = $enabled ? 'restore' : 'archive';
        $requestId = Str::uuid()->toString();

        try {
            $this->lifecycleCommandService->act(
                $card,
                $action,
                $requestId,
                null, // legacy endpoint skips optimistic lock
                'legacy_enabled_endpoint',
                Auth::user()->id,
                Auth::user()->selected_language,
                config('app.timezone', 'UTC')
            );
        } catch (LifecycleConflictException $e) {
            return response()->json([
                'error' => $e->reason,
                'message' => $e->getMessage(),
                'review_card_id' => $reviewCard,
            ], 409);
        }

        return response()->json($this->itemSerializer->serializeCard($card->fresh(), $sense));
    }

    /**
     * POST /review-cards/manage/{reviewCard}/due-now
     * Set fsrs_due_at = now(). Does NOT auto-enable.
     */
    public function dueNow(Request $request, int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::user()->id, Auth::user()->selected_language
        );

        $this->mutationService->setDueNow($card);

        return response()->json($this->itemSerializer->serializeCard($card->fresh(), $sense));
    }

    /**
     * POST /review-cards/manage/{reviewCard}/reset
     * Reset a sense review card to new-card state, erasing all FSRS memory.
     * Archived cards are force-enabled. Existing review_logs are preserved.
     */
    public function reset(int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::user()->id, Auth::user()->selected_language
        );

        $card = $this->reviewCardService->resetCard(
            Auth::user()->id,
            Auth::user()->selected_language,
            $reviewCard
        );

        return response()->json(array_merge(
            ['message' => '已重置复习进度。该卡会重新进入复习队列。'],
            $this->itemSerializer->serializeCard($card->fresh(), $sense->fresh())
        ));
    }

    /**
     * DELETE /review-cards/manage/{reviewCard}
     * Permanently delete a sense review card and reject the linked WordSense.
     * WordSense is set to rejected so it no longer appears in reading page candidates.
     * WordSenseOccurrence and review_logs are preserved.
     * Reading materials, chapters, and EncounteredWord are never deleted.
     */
    public function destroy(int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::user()->id, Auth::user()->selected_language
        );

        $this->wordSenseService->removeSenseFromReviewSystem($sense, true);

        return response()->json([
            'deleted' => true,
            'review_card_id' => $reviewCard,
            'word_sense_id' => $sense->id,
            'message' => '已彻底删除词义复习卡。该释义不会再出现在阅读页，阅读记录和复习历史已保留。',
        ]);
    }

    /**
     * POST /review-cards/manage/bulk-enabled
     * Legacy bulk archive/restore endpoint (ADR-0010 compatibility).
     *
     * Delegates to ReviewCardLifecycleCommandService::bulkAct:
     *   enabled=true  → action=restore
     *   enabled=false → action=archive
     *
     * The response format is preserved for backward compatibility.
     * Body: { ids: int[], enabled: bool }
     */
    public function bulkEnabled(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['message' => '请选择至少一张复习卡。'], 422);
        }

        $enabled = $request->boolean('enabled');
        $action = $enabled ? 'restore' : 'archive';

        $bulkResult = $this->lifecycleCommandService->bulkAct(
            array_map('intval', $ids),
            $action,
            'legacy_bulk_enabled_endpoint',
            Auth::user()->id,
            Auth::user()->selected_language,
            config('app.timezone', 'UTC')
        );

        $affected = 0;
        $skipped = 0;
        foreach ($bulkResult['results'] as $r) {
            if (!empty($r['success']) || !empty($r['already_applied'])) {
                $affected++;
            } else {
                $skipped++;
            }
        }

        return response()->json([
            'affected' => $affected,
            'skipped' => $skipped,
            'enabled' => $enabled,
            'message' => $enabled
                ? "已恢复 {$affected} 张复习卡。它们会重新进入日常复习。"
                : "已归档 {$affected} 张复习卡。它们不会进入日常复习。",
            'results' => $bulkResult['results'],
        ]);
    }

    /**
     * POST /review-cards/manage/bulk-delete
     * Bulk permanently delete sense review cards and reject the linked WordSenses.
     * WordSense is set to rejected so it no longer appears in reading page candidates.
     * WordSenseOccurrence and review_logs are preserved.
     * Body: { ids: int[] }
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['message' => '请选择至少一张复习卡。'], 422);
        }

        $result = $this->mutationService->bulkDestroy(
            $ids,
            Auth::user()->id,
            Auth::user()->selected_language,
            $this->wordSenseService,
        );

        return response()->json([
            'deleted' => $result['deleted'],
            'skipped' => $result['skipped'],
            'message' => "已彻底删除 {$result['deleted']} 张词义复习卡。对应释义不会再出现在阅读页，阅读记录和复习历史已保留。",
        ]);
    }

    /**
     * GET /review-cards/manage/{reviewCard}/detail
     * ADR-0007 — Read-only exact card detail for deep-link navigation.
     * ADR-0014 — Converged Card Info read model (additive card_info payload).
     *
     * Returns the serialized card item (same shape as list serializer) for
     * a specific sense ReviewCard, PLUS an additive `card_info` object that
     * aggregates recent review logs, lifecycle events, and the leech
     * descriptor. Used by the management page detail drawer so the frontend
     * can render the entire drawer from a single canonical request.
     *
     * Access control: ReviewCardManageAccessService (single source of truth).
     * 404 for: not found, other user, other language, legacy word card,
     * rejected/deleted sense. Archived cards (fsrs_enabled=false) allowed.
     *
     * Read-only: no ReviewLog write, no FSRS change, no lifecycle change.
     * Backward compat: all pre-existing top-level fields preserved unchanged.
     */
    public function detail(int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->accessService->findManageableSenseCardOrFail(
            $reviewCard, Auth::user()->id, Auth::user()->selected_language
        );

        // ADR-0014: Top-level fields preserved unchanged (additive only).
        $payload = $this->itemSerializer->serializeCard($card, $sense);

        // ADR-0014: Additive card_info — single aggregated payload so the
        // frontend drawer makes one request instead of four.
        $payload['card_info'] = $this->cardInfoQueryService->build(
            $card,
            $sense,
            Auth::user()->id,
            Auth::user()->selected_language
        );

        return response()->json($payload);
    }
}
