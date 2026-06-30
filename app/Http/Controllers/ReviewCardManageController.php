<?php

namespace App\Http\Controllers;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use App\Services\ReviewCardManageItemSerializerService;
use App\Services\ReviewCardService;
use App\Services\ReviewCardExportService;
use App\Services\ReviewCardManageQueryService;
use App\Services\ReviewCardManageMutationService;
use App\Services\WordSenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReviewCardManageController extends Controller
{
    public function __construct(
        private WordSenseService $wordSenseService,
        private ReviewCardService $reviewCardService,
        private ReviewCardExportService $exportService,
        private ReviewCardManageQueryService $queryService,
        private ReviewCardManageItemSerializerService $itemSerializer,
        private ReviewCardManageMutationService $mutationService,
    )
    {
    }
    /**
     * Whitelist of fields allowed for normal edit.
     */
    private const EDITABLE_FIELDS = [
        'pos',
        'sense_zh',
        'sense_en',
        'example_sentence_en',
        'example_sentence_zh',
        'aliases_zh',
        'collocations',
    ];

    public function data(Request $request): JsonResponse
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = $this->queryService->build($request, $userId, $language);

        // Paginate
        $paginator = $query->paginate($perPage);
        $cards = $paginator->getCollection();

        $items = $this->itemSerializer->buildItems($cards, $userId, $language);

        return response()->json([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
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

        $query = $this->queryService->build($request, $userId, $language);
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
    public function exportAnkiTsv(Request $request): \Illuminate\Http\Response
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $query = $this->queryService->build($request, $userId, $language);

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
    public function exportCsv(Request $request): \Illuminate\Http\Response
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $query = $this->queryService->build($request, $userId, $language);
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
        [$card, $sense] = $this->findManageableSenseCard($reviewCard);

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
        [$card, $sense] = $this->findManageableSenseCard($reviewCard);

        // Whitelist-only update — set each field individually
        foreach (self::EDITABLE_FIELDS as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);

                // Normalize array fields: accept comma-separated strings or arrays
                if (in_array($field, ['aliases_zh', 'collocations'], true)) {
                    $value = $this->normalizeArray($value);
                }

                $sense->{$field} = $value;
            }
        }

        $sense->save();

        return response()->json($this->itemSerializer->serializeCard($card->fresh(), $sense->fresh()));
    }

    /**
     * Normalize a value to an array of trimmed, non-empty strings.
     * Accepts arrays or comma-separated strings.
     */
    private function normalizeArray(mixed $values): array
    {
        if (!is_array($values)) {
            $values = explode(',', (string) $values);
        }

        return array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), $values),
            fn ($value) => $value !== ''
        ));
    }

    /**
     * PATCH /review-cards/manage/{reviewCard}/enabled
     * Toggle fsrs_enabled only.
     */
    public function enabled(Request $request, int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->findManageableSenseCard($reviewCard);

        $this->mutationService->setEnabled($card, $request->boolean('enabled'));

        return response()->json($this->itemSerializer->serializeCard($card->fresh(), $sense));
    }

    /**
     * POST /review-cards/manage/{reviewCard}/due-now
     * Set fsrs_due_at = now(). Does NOT auto-enable.
     */
    public function dueNow(Request $request, int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->findManageableSenseCard($reviewCard);

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
        [$card, $sense] = $this->findManageableSenseCard($reviewCard);

        $card = $this->reviewCardService->resetCard(
            Auth::user()->id,
            Auth::user()->selected_language,
            $reviewCard
        );

        return response()->json(array_merge(
            ['message' => '已重置为新学卡。该卡会重新进入复习队列。'],
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
        [$card, $sense] = $this->findManageableSenseCard($reviewCard);

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
     * Bulk archive or restore sense review cards.
     * Body: { ids: int[], enabled: bool }
     */
    public function bulkEnabled(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['message' => '请选择至少一张复习卡。'], 422);
        }

        $enabled = $request->boolean('enabled');
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $affected = 0;
        $skipped = 0;

        DB::transaction(function () use ($ids, $enabled, $userId, $language, &$affected, &$skipped) {
            foreach ($ids as $id) {
                $card = ReviewCard::query()
                    ->where('id', $id)
                    ->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->where('target_type', ReviewCard::TARGET_SENSE)
                    ->whereHas('sense', function ($q) use ($userId, $language) {
                        $q->where('user_id', $userId)
                            ->where('language_id', $language)
                            ->where('status', WordSense::STATUS_CONFIRMED);
                    })
                    ->first();

                if (!$card) {
                    $skipped++;
                    continue;
                }

                $card->fsrs_enabled = $enabled;
                $card->save();
                $affected++;
            }
        });

        $actionLabel = $enabled ? '恢复' : '归档';

        return response()->json([
            'affected' => $affected,
            'skipped' => $skipped,
            'enabled' => $enabled,
            'message' => $enabled
                ? "已恢复 {$affected} 张复习卡。它们会重新进入日常复习。"
                : "已归档 {$affected} 张复习卡。它们不会进入日常复习。",
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

        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $deleted = 0;
        $skipped = 0;

        DB::transaction(function () use ($ids, $userId, $language, &$deleted, &$skipped) {
            foreach ($ids as $id) {
                $card = ReviewCard::query()
                    ->where('id', $id)
                    ->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->where('target_type', ReviewCard::TARGET_SENSE)
                    ->whereHas('sense', function ($q) use ($userId, $language) {
                        $q->where('user_id', $userId)
                            ->where('language_id', $language)
                            ->where('status', WordSense::STATUS_CONFIRMED);
                    })
                    ->first();

                if (!$card) {
                    $skipped++;
                    continue;
                }

                $sense = WordSense::find($card->target_id);
                if (!$sense) {
                    $skipped++;
                    continue;
                }

                $this->wordSenseService->removeSenseFromReviewSystem($sense, true);
                $deleted++;
            }
        });

        return response()->json([
            'deleted' => $deleted,
            'skipped' => $skipped,
            'message' => "已彻底删除 {$deleted} 张词义复习卡。对应释义不会再出现在阅读页，阅读记录和复习历史已保留。",
        ]);
    }

    // ==================== Private helpers ====================

    
    private function findManageableSenseCard(int $reviewCardId): array
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $card = ReviewCard::query()
            ->where('id', $reviewCardId)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->first();

        if (!$card) {
            abort(404);
        }

        $sense = WordSense::query()
            ->where('id', $card->target_id)
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('status', WordSense::STATUS_CONFIRMED)
            ->first();

        if (!$sense) {
            abort(404);
        }

        return [$card, $sense];
    }}
