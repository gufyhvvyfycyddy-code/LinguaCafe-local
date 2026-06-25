<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\ReviewCardService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReviewCardManageController extends Controller
{
    public function __construct(
        private WordSenseService $wordSenseService,
        private ReviewCardService $reviewCardService,
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

    /**
     * Whitelist of sortable columns.
     * Maps query-param keys to fully-qualified column expressions.
     * Only review_cards direct fields are supported — no word_senses join.
     */
    private const SORTABLE_COLUMNS = [
        'id'                    => 'review_cards.id',
        'fsrs_state'            => 'review_cards.fsrs_state',
        'fsrs_due_at'           => 'review_cards.fsrs_due_at',
        'fsrs_stability'        => 'review_cards.fsrs_stability',
        'fsrs_difficulty'       => 'review_cards.fsrs_difficulty',
        'fsrs_reps'             => 'review_cards.fsrs_reps',
        'fsrs_lapses'           => 'review_cards.fsrs_lapses',
        'fsrs_last_reviewed_at' => 'review_cards.fsrs_last_reviewed_at',
    ];

    /**
     * Maximum number of cards that can be exported in a single request.
     */
    private const EXPORT_LIMIT = 5000;

    /**
     * Whitelist of allowed export field keys.
     */
    private const EXPORT_FIELDS = [
        'review_card_id',
        'word_sense_id',
        'lemma',
        'surface_form',
        'pos',
        'sense_zh',
        'sense_en',
        'example_sentence_en',
        'example_sentence_zh',
        'aliases_zh',
        'collocations',
        'source_chapter_id',
        'source_chapter_title',
        'source_kind',
        'fsrs_state',
        'fsrs_due_at',
        'fsrs_stability',
        'fsrs_difficulty',
        'fsrs_reps',
        'fsrs_lapses',
        'fsrs_last_reviewed_at',
        'fsrs_enabled',
        'missing_definition',
        'missing_example',
        'missing_source',
    ];

    /**
     * GET /review-cards/manage/data
     * Read-only paginated list of sense review cards for current user/language.
     */
    public function data(Request $request): JsonResponse
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = $this->buildManageQuery($request, $userId, $language);

        // Paginate
        $paginator = $query->paginate($perPage);
        $cards = $paginator->getCollection();

        $items = $this->buildItems($cards, $userId, $language);

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

        $query = $this->buildManageQuery($request, $userId, $language);
        $total = $query->count();

        if ($total > self::EXPORT_LIMIT) {
            return response()->json([
                'message' => '当前筛选结果超过 ' . self::EXPORT_LIMIT . ' 条，请缩小筛选范围后再导出。',
                'total' => $total,
                'limit' => self::EXPORT_LIMIT,
            ], 422);
        }

        $cards = $query->get();
        $items = $this->buildItems($cards, $userId, $language);

        // Field selection
        $requestedFields = $request->input('fields', []);
        if (!is_array($requestedFields)) {
            $requestedFields = [];
        }

        $selectedFields = [];
        if (!empty($requestedFields)) {
            $validFields = array_intersect($requestedFields, self::EXPORT_FIELDS);
            if (empty($validFields)) {
                return response()->json([
                    'message' => '请选择至少一个有效导出字段。',
                    'allowed_fields' => self::EXPORT_FIELDS,
                ], 422);
            }
            $selectedFields = array_values($validFields);

            $items = $items->map(fn ($item) => array_intersect_key(
                $item,
                array_flip($selectedFields)
            ));
        } else {
            $selectedFields = self::EXPORT_FIELDS;
        }

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

        $data = [
            'exported_at' => now()->toISOString(),
            'language' => $language,
            'filters' => $filters,
            'fields' => $selectedFields,
            'count' => $items->count(),
            'items' => $items,
        ];

        $filename = 'review-cards-export-' . now()->format('Ymd-His') . '.json';

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * GET /review-cards/manage/export-anki-tsv
     * Export current filtered/sorted results as Anki-compatible TSV.
     * Reuses buildManageQuery — no mode, no card_ids, no all/selected.
     * Fixed 13 columns, Front/Back are HTML-rendered question/answer faces.
     */
    public function exportAnkiTsv(Request $request): \Illuminate\Http\Response
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $query = $this->buildManageQuery($request, $userId, $language);

        $total = $query->count();
        if ($total > self::EXPORT_LIMIT) {
            return response()->json([
                'message' => '导出数量超过上限。',
                'total' => $total,
                'limit' => self::EXPORT_LIMIT,
            ], 422);
        }

        $cards = $query->get();
        $items = $this->buildItems($cards, $userId, $language);

        $headers = ['Front', 'Back', 'Lemma', 'Surface', 'POS', 'SenseZh', 'SenseEn', 'ExampleEn', 'ExampleZh', 'AliasesZh', 'Collocations', 'Source', 'FsrsState'];

        $lines = [];
        $lines[] = implode("\t", $headers);

        foreach ($items as $item) {
            $lemma = $this->tsvEscape($item['lemma'] ?? '');
            $surface = $this->tsvEscape($item['surface_form'] ?? '');
            $pos = $this->tsvEscape($item['pos'] ?? '');
            $senseZh = $this->tsvEscape($item['sense_zh'] ?? '');
            $senseEn = $this->tsvEscape($item['sense_en'] ?? '');
            $exampleEn = $this->tsvEscape($item['example_sentence_en'] ?? '');
            $exampleZh = $this->tsvEscape($item['example_sentence_zh'] ?? '');
            $aliasesZh = $this->tsvEscape($this->joinArray($item['aliases_zh'] ?? []));
            $collocations = $this->tsvEscape($this->joinArray($item['collocations'] ?? []));
            $source = $this->tsvEscape($item['source_chapter_title'] ?? '');
            $fsrsState = $this->tsvEscape($item['fsrs_state'] ?? '');

            // HTML-escape user text embedded in Anki Front/Back HTML faces.
            // Only fixed structural tags (<strong>, <br>) are kept raw.
            $exampleEnHtml = $this->htmlEscape($exampleEn);
            $lemmaHtml = $this->htmlEscape($lemma);
            $surfaceHtml = $this->htmlEscape($surface);
            $posHtml = $this->htmlEscape($pos);
            $senseZhHtml = $this->htmlEscape($senseZh);
            $senseEnHtml = $this->htmlEscape($senseEn);
            $exampleZhHtml = $this->htmlEscape($exampleZh);
            $aliasesZhHtml = $this->htmlEscape($aliasesZh);
            $collocationsHtml = $this->htmlEscape($collocations);
            $sourceHtml = $this->htmlEscape($source);

            $front = $this->tsvEscape(
                $exampleEnHtml . "<br><br> <strong>" . $lemmaHtml . "</strong> / " . $surfaceHtml . " / " . $posHtml
            );
            $back = $this->tsvEscape(
                "<strong>中文释义</strong><br>" . $senseZhHtml . "<br><br> <strong>英文释义</strong><br>" . $senseEnHtml
                . "<br><br> <strong>例句翻译</strong><br>" . $exampleZhHtml
                . "<br><br> <strong>近义译法</strong><br>" . $aliasesZhHtml
                . "<br><br> <strong>搭配</strong><br>" . $collocationsHtml
                . "<br><br> <strong>来源</strong><br>" . $sourceHtml
            );

            $row = [
                $front,
                $back,
                $lemma,
                $surface,
                $pos,
                $senseZh,
                $senseEn,
                $exampleEn,
                $exampleZh,
                $aliasesZh,
                $collocations,
                $source,
                $fsrsState,
            ];

            $lines[] = implode("\t", $row);
        }

        $tsv = implode("\n", $lines);
        $filename = 'review-cards-anki-' . now()->format('Ymd-His') . '.tsv';

        return response($tsv, 200)
            ->header('Content-Type', 'text/tab-separated-values; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('X-Export-Count', count($items));
    }

    /**
     * GET /review-cards/manage/export-csv
     * Export current filtered/sorted results as CSV download.
     * Reuses buildManageQuery, buildItems, EXPORT_FIELDS, EXPORT_LIMIT.
     * No mode, no card_ids, no all/selected. Uses fputcsv + BOM.
     */
    public function exportCsv(Request $request): \Illuminate\Http\Response
    {
        $userId = Auth::user()->id;
        $language = Auth::user()->selected_language;

        $query = $this->buildManageQuery($request, $userId, $language);
        $total = $query->count();

        if ($total > self::EXPORT_LIMIT) {
            return response()->json([
                'message' => '当前筛选结果超过 ' . self::EXPORT_LIMIT . ' 条，请缩小筛选范围后再导出。',
                'total' => $total,
                'limit' => self::EXPORT_LIMIT,
            ], 422);
        }

        $cards = $query->get();
        $items = $this->buildItems($cards, $userId, $language);

        // Field selection — same as export()
        $requestedFields = $request->input('fields', []);
        if (!is_array($requestedFields)) {
            $requestedFields = [];
        }

        $selectedFields = [];
        if (!empty($requestedFields)) {
            $validFields = array_intersect($requestedFields, self::EXPORT_FIELDS);
            if (empty($validFields)) {
                return response()->json([
                    'message' => '请选择至少一个有效导出字段。',
                    'allowed_fields' => self::EXPORT_FIELDS,
                ], 422);
            }
            $selectedFields = array_values($validFields);
        } else {
            $selectedFields = self::EXPORT_FIELDS;
        }

        // Build CSV using fputcsv with in-memory stream
        $stream = fopen('php://temp', 'r+');

        // UTF-8 BOM
        fwrite($stream, "\xEF\xBB\xBF");

        // Header row
        fputcsv($stream, $selectedFields);

        foreach ($items as $item) {
            $row = [];
            foreach ($selectedFields as $field) {
                $row[] = $this->csvCellValue($item[$field] ?? null);
            }
            fputcsv($stream, $row);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = 'review-cards-' . now()->format('Ymd-His') . '.csv';

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('X-Export-Count', count($items));
    }

    /**
     * Convert a buildItems value to a CSV-safe cell string.
     * - null → ''
     * - array → '；'-joined string
     * - Applies Excel formula injection protection (prefixes with quote)
     */
    private function csvCellValue($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            $value = $this->joinArray($value);
        }

        $value = (string) $value;

        // Excel formula injection protection
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
            $value = "'" . $value;
        }

        return $value;
    }

    private function tsvEscape(?string $value): string
    {
        if ($value === null) return '';
        $value = str_replace(["\t", "\r", "\n"], [' ', ' ', ' '], $value);
        return $value;
    }

    private function htmlEscape(?string $value): string
    {
        if ($value === null) return '';
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function joinArray(?array $arr): string
    {
        if (empty($arr)) return '';
        return implode('；', $arr);
    }

    /**
     * GET /review-cards/manage/{reviewCard}/logs
     * Return the most recent 20 ReviewLog entries for a manageable sense card.
     * Read-only — no delete, no export, no pagination, no charts.
     */
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

        return response()->json($this->serializeCard($card->fresh(), $sense->fresh()));
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

        $card->fsrs_enabled = $request->boolean('enabled');
        $card->save();

        return response()->json($this->serializeCard($card->fresh(), $sense));
    }

    /**
     * POST /review-cards/manage/{reviewCard}/due-now
     * Set fsrs_due_at = now(). Does NOT auto-enable.
     */
    public function dueNow(Request $request, int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->findManageableSenseCard($reviewCard);

        $card->fsrs_due_at = Carbon::now();
        $card->save();

        return response()->json($this->serializeCard($card->fresh(), $sense));
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
            $this->serializeCard($card->fresh(), $sense->fresh())
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

    /**
     * Apply advanced filter query parameters within the already-scoped query.
     * All filters use whitelist/enum/int-safe parsing — no raw user input in SQL.
     */
    private function applyAdvancedFilters($query, Request $request): void
    {
        // fsrs_states[] — whitelist each value
        $allowedStates = ['new', 'learning', 'review', 'relearning'];
        $fsrsStates = $request->input('fsrs_states', []);
        if (is_array($fsrsStates) && !empty($fsrsStates)) {
            $validStates = array_values(array_intersect($fsrsStates, $allowedStates));
            if (!empty($validStates)) {
                $query->whereIn('review_cards.fsrs_state', $validStates);
            }
        }

        // due_range — whitelist via switch
        $dueRange = $request->input('due_range', 'all');
        $allowedRanges = ['all', 'overdue', 'today', 'next7', 'future', 'none'];
        if (!in_array($dueRange, $allowedRanges, true)) {
            $dueRange = 'all';
        }
        switch ($dueRange) {
            case 'overdue':
                $query->where('review_cards.fsrs_due_at', '<', Carbon::today());
                break;
            case 'today':
                $query->whereBetween('review_cards.fsrs_due_at', [Carbon::today(), Carbon::tomorrow()]);
                break;
            case 'next7':
                $query->whereBetween('review_cards.fsrs_due_at', [Carbon::now(), Carbon::now()->addDays(7)]);
                break;
            case 'future':
                $query->where('review_cards.fsrs_due_at', '>', Carbon::now());
                break;
            case 'none':
                $query->whereNull('review_cards.fsrs_due_at');
                break;
            case 'all':
            default:
                break; // no filter
        }

        // reps_min — non-negative int, ctype_digit guard
        $repsMin = $request->input('reps_min');
        if ($repsMin !== null && $repsMin !== '' && ctype_digit((string) $repsMin)) {
            $repsMin = (int) $repsMin;
            $query->where('review_cards.fsrs_reps', '>=', $repsMin);
        }

        // lapses_min — non-negative int, ctype_digit guard
        $lapsesMin = $request->input('lapses_min');
        if ($lapsesMin !== null && $lapsesMin !== '' && ctype_digit((string) $lapsesMin)) {
            $lapsesMin = (int) $lapsesMin;
            $query->where('review_cards.fsrs_lapses', '>=', $lapsesMin);
        }
    }

    /**
     * Build the shared base query with all security constraints, search, filters,
     * advanced filters, and sort applied. Used by both data() and export().
     */
    private function buildManageQuery(Request $request, int $userId, string $language)
    {
        $filter = $request->input('filter', 'enabled');
        $q = trim($request->input('q', ''));

        // Base query — all security constraints baked in via whereHas
        $query = ReviewCard::query()
            ->where('review_cards.user_id', $userId)
            ->where('review_cards.language_id', $language)
            ->where('review_cards.target_type', ReviewCard::TARGET_SENSE)
            ->whereHas('sense', function ($subQuery) use ($userId, $language) {
                $subQuery->where('user_id', $userId)
                    ->where('language_id', $language)
                    ->where('status', WordSense::STATUS_CONFIRMED);
            })
            ->with('sense');

        // Search — scoped inside whereHas to prevent escaping security constraints
        if (!empty($q)) {
            $query->whereHas('sense', function ($subQuery) use ($q) {
                $subQuery->where(function ($inner) use ($q) {
                    $inner->where('lemma', 'like', "%{$q}%")
                        ->orWhere('surface_form', 'like', "%{$q}%")
                        ->orWhere('sense_zh', 'like', "%{$q}%")
                        ->orWhere('sense_en', 'like', "%{$q}%")
                        ->orWhere('example_sentence_en', 'like', "%{$q}%");
                });
            });
        }

        // Apply standard filters
        $this->applyFilters($query, $filter, $userId, $language);

        // Advanced filters — all within security scope (user_id/language_id/sense confirmed)
        $this->applyAdvancedFilters($query, $request);

        // Sort — whitelist only, no raw user input in orderBy
        $this->applySort($query, $request);

        return $query;
    }

    /**
     * Apply standard filter to a query already scoped to current user/language/sense.
     */
    private function applyFilters($query, string $filter, int $userId, string $language): void
    {
        $now = Carbon::now();
        switch ($filter) {
            case 'due':
                $query->where('review_cards.fsrs_due_at', '<=', $now);
                break;
            case 'future':
                $query->where('review_cards.fsrs_due_at', '>', $now);
                break;
            case 'enabled':
                $query->where('review_cards.fsrs_enabled', true);
                break;
            case 'disabled':
                $query->where('review_cards.fsrs_enabled', false);
                break;
            case 'missing_definition':
                $query->whereHas('sense', function ($subQuery) {
                    $subQuery->where(function ($q) {
                        $q->whereNull('sense_zh')->orWhere('sense_zh', '');
                    })->where(function ($q) {
                        $q->whereNull('sense_en')->orWhere('sense_en', '');
                    });
                });
                break;
            case 'missing_example':
                $query->whereHas('sense', function ($subQuery) {
                    $subQuery->where(function ($q) {
                        $q->whereNull('example_sentence_en')->orWhere('example_sentence_en', '');
                    });
                });
                break;
            case 'missing_source':
                $query->whereHas('sense', function ($subQuery) {
                    $subQuery->whereNull('source_chapter_id');
                })->whereNotExists(function ($subQuery) use ($userId, $language) {
                    $subQuery->select(DB::raw(1))
                        ->from('word_sense_occurrences')
                        ->whereColumn('word_sense_occurrences.word_sense_id', 'review_cards.target_id')
                        ->where('word_sense_occurrences.user_id', $userId)
                        ->where('word_sense_occurrences.language_id', $language)
                        ->where('word_sense_occurrences.status', WordSenseOccurrence::STATUS_BOUND)
                        ->whereNotNull('word_sense_occurrences.chapter_id');
                });
                break;
        }
    }

    /**
     * Apply sort to a query — whitelist only, no raw user input in orderBy.
     */
    private function applySort($query, Request $request): void
    {
        $sortBy = $request->input('sort_by', 'id');
        $sortDir = strtolower($request->input('sort_dir', 'desc'));

        if (!array_key_exists($sortBy, self::SORTABLE_COLUMNS)) {
            $sortBy = 'id';
        }

        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        $sortColumn = self::SORTABLE_COLUMNS[$sortBy];
        $query->orderBy($sortColumn, $sortDir);

        // Tie-breaker for stable pagination
        if ($sortBy !== 'id') {
            $query->orderBy('review_cards.id', 'desc');
        }
    }

    /**
     * Map a collection of ReviewCards to the unified item array format.
     * Batch-fetches occurrence chapters and chapter names for performance.
     */
    private function buildItems($cards, int $userId, string $language)
    {
        // Collect all sense IDs
        $senseIds = $cards->pluck('target_id')->filter()->unique()->values()->toArray();

        // Batch-fetch occurrence chapters for all senses at once
        $occurrenceChapters = [];
        if (!empty($senseIds)) {
            $occurrenceChapters = WordSenseOccurrence::query()
                ->select('word_sense_id', 'chapter_id')
                ->whereIn('word_sense_id', $senseIds)
                ->where('user_id', $userId)
                ->where('language_id', $language)
                ->where('status', WordSenseOccurrence::STATUS_BOUND)
                ->whereNotNull('chapter_id')
                ->get()
                ->groupBy('word_sense_id')
                ->map(fn($group) => $group->first()->chapter_id)
                ->toArray();
        }

        // Collect all relevant chapter IDs and fetch chapters in one query
        $chapterIds = collect();
        foreach ($cards as $card) {
            $sense = $card->sense;
            if ($sense) {
                if ($sense->source_chapter_id) {
                    $chapterIds->push($sense->source_chapter_id);
                }
            }
            $sid = $card->target_id;
            if (isset($occurrenceChapters[$sid])) {
                $chapterIds->push($occurrenceChapters[$sid]);
            }
        }
        $chapterIds = $chapterIds->filter()->unique()->values()->toArray();

        $chapters = [];
        if (!empty($chapterIds)) {
            $chapters = Chapter::query()
                ->whereIn('id', $chapterIds)
                ->where('user_id', $userId)
                ->where('language', $language)
                ->pluck('name', 'id')
                ->toArray();
        }

        // Map items
        return $cards->map(function (ReviewCard $card) use ($chapters, $occurrenceChapters) {
            $sense = $card->sense;
            $senseId = $card->target_id;

            if (!$sense) {
                return null;
            }

            $occChapterId = $occurrenceChapters[$senseId] ?? null;
            $sourceChapterId = $sense->source_chapter_id ?? $occChapterId;

            $sourceKind = $this->inferSourceKind(
                $sense->source_chapter_id,
                $occChapterId,
                $sense->example_sentence_en
            );

            $sourceChapterTitle = null;
            if ($sourceChapterId && isset($chapters[$sourceChapterId])) {
                $sourceChapterTitle = $chapters[$sourceChapterId];
            }

            return [
                'review_card_id' => $card->id,
                'word_sense_id' => $senseId,
                'lemma' => $sense->lemma,
                'surface_form' => $sense->surface_form,
                'pos' => $sense->pos,
                'sense_zh' => $sense->sense_zh,
                'sense_en' => $sense->sense_en,
                'example_sentence_en' => $sense->example_sentence_en,
                'example_sentence_zh' => $sense->example_sentence_zh,
                'source_chapter_id' => $sourceChapterId,
                'source_chapter_title' => $sourceChapterTitle,
                'source_kind' => $sourceKind,
                'fsrs_state' => $card->fsrs_state,
                'fsrs_due_at' => optional($card->fsrs_due_at)->toISOString(),
                'fsrs_stability' => $card->fsrs_stability,
                'fsrs_difficulty' => $card->fsrs_difficulty,
                'fsrs_reps' => $card->fsrs_reps,
                'fsrs_lapses' => $card->fsrs_lapses,
                'fsrs_last_reviewed_at' => optional($card->fsrs_last_reviewed_at)->toISOString(),
                'aliases_zh' => $sense->aliases_zh ?: [],
                'collocations' => $sense->collocations ?: [],
                'fsrs_enabled' => $card->fsrs_enabled,
                'missing_definition' => empty($sense->sense_zh) && empty($sense->sense_en),
                'missing_example' => empty($sense->example_sentence_en),
                'missing_source' => empty($sense->source_chapter_id) && empty($occChapterId),
            ];
        })->filter()->values();
    }

    /**
     * Security query: find a manageable sense card scoped to current user/language.
     *
     * @return array{0: ReviewCard, 1: WordSense}
     */
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
    }

    private function inferSourceKind(?int $sourceChapterId, ?int $occurrenceChapterId, ?string $exampleSentenceEn): string
    {
        if ($sourceChapterId) {
            return 'chapter';
        }
        if ($occurrenceChapterId) {
            return 'occurrence_chapter';
        }
        if (!empty($exampleSentenceEn)) {
            return 'card_example';
        }
        return 'missing';
    }

    private function serializeCard(ReviewCard $card, WordSense $sense): array
    {
        $occurrenceChapterId = WordSenseOccurrence::query()
            ->where('word_sense_id', $sense->id)
            ->where('user_id', $sense->user_id)
            ->where('language_id', $sense->language_id)
            ->where('status', WordSenseOccurrence::STATUS_BOUND)
            ->whereNotNull('chapter_id')
            ->value('chapter_id');

        $sourceChapterId = $sense->source_chapter_id ?? $occurrenceChapterId;
        $sourceKind = $this->inferSourceKind($sense->source_chapter_id, $occurrenceChapterId, $sense->example_sentence_en);

        $sourceChapterTitle = null;
        if ($sourceChapterId) {
            $sourceChapterTitle = Chapter::query()
                ->where('id', $sourceChapterId)
                ->where('user_id', $sense->user_id)
                ->where('language', $sense->language_id)
                ->value('name');
        }

        return [
            'review_card_id' => $card->id,
            'word_sense_id' => $sense->id,
            'lemma' => $sense->lemma,
            'surface_form' => $sense->surface_form,
            'pos' => $sense->pos,
            'sense_zh' => $sense->sense_zh,
            'sense_en' => $sense->sense_en,
            'example_sentence_en' => $sense->example_sentence_en,
            'example_sentence_zh' => $sense->example_sentence_zh,
            'aliases_zh' => $sense->aliases_zh ?: [],
            'collocations' => $sense->collocations ?: [],
            'source_chapter_id' => $sourceChapterId,
            'source_chapter_title' => $sourceChapterTitle,
            'source_kind' => $sourceKind,
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => optional($card->fsrs_due_at)->toISOString(),
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
            'fsrs_last_reviewed_at' => optional($card->fsrs_last_reviewed_at)->toISOString(),
            'fsrs_enabled' => $card->fsrs_enabled,
            'missing_definition' => empty($sense->sense_zh) && empty($sense->sense_en),
            'missing_example' => empty($sense->example_sentence_en),
            'missing_source' => empty($sense->source_chapter_id) && empty($occurrenceChapterId),
        ];
    }
}
