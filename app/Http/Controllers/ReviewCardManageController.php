<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
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

        // Apply filters
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

        // Paginate
        $paginator = $query->orderBy('review_cards.id')->paginate($perPage);
        $cards = $paginator->getCollection();

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
        $items = $cards->map(function (ReviewCard $card) use ($chapters, $occurrenceChapters) {
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
                'fsrs_enabled' => $card->fsrs_enabled,
                'missing_definition' => empty($sense->sense_zh) && empty($sense->sense_en),
                'missing_example' => empty($sense->example_sentence_en),
                'missing_source' => empty($sense->source_chapter_id) && empty($occChapterId),
            ];
        })->filter()->values();

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
     * PATCH /review-cards/manage/{reviewCard}
     * Edit WordSense text fields only.
     */
    public function update(Request $request, int $reviewCard): JsonResponse
    {
        [$card, $sense] = $this->findManageableSenseCard($reviewCard);

        // Whitelist-only update — set each field individually
        foreach (self::EDITABLE_FIELDS as $field) {
            if ($request->has($field)) {
                $sense->{$field} = $request->input($field);
            }
        }

        $sense->save();

        return response()->json($this->serializeCard($card->fresh(), $sense->fresh()));
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

        return response()->json([
            'deleted' => $deleted,
            'skipped' => $skipped,
            'message' => "已彻底删除 {$deleted} 张词义复习卡。对应释义不会再出现在阅读页，阅读记录和复习历史已保留。",
        ]);
    }

    // ==================== Private helpers ====================

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
            'source_chapter_id' => $sourceChapterId,
            'source_chapter_title' => $sourceChapterTitle,
            'source_kind' => $sourceKind,
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => optional($card->fsrs_due_at)->toISOString(),
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => $card->fsrs_reps,
            'fsrs_lapses' => $card->fsrs_lapses,
            'fsrs_enabled' => $card->fsrs_enabled,
            'missing_definition' => empty($sense->sense_zh) && empty($sense->sense_en),
            'missing_example' => empty($sense->example_sentence_en),
            'missing_source' => empty($sense->source_chapter_id) && empty($occurrenceChapterId),
        ];
    }
}
