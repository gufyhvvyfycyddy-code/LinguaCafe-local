<?php

namespace App\Services;

use App\Models\AiStudyCardPendingItem;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiStudyCardPendingItemService
{
    public function __construct(private WordSenseService $wordSenseService)
    {
    }

    public function createOrGetPending(User $user, array $data): array
    {
        $language = $user->selected_language;
        $chapterId = (int) $data['chapter_id'];

        $chapter = Chapter::where('id', $chapterId)
            ->where('user_id', $user->id)
            ->where('language', $language)
            ->first();

        if (!$chapter) {
            return [
                'success' => false,
                'status' => 404,
                'message' => '章节不存在或不属于当前用户。',
            ];
        }

        $word = trim((string) $data['word']);
        $normalizedWord = $this->normalizeWord($word);
        $textBlockIndex = (int) $data['text_block_index'];

        $baseLookup = [
            'user_id' => $user->id,
            'language_id' => $language,
            'chapter_id' => $chapterId,
            'text_block_index' => $textBlockIndex,
            'normalized_word' => $normalizedWord,
        ];

        $pendingLookup = array_merge($baseLookup, [
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);

        $existingPending = AiStudyCardPendingItem::where($pendingLookup)->first();
        if ($existingPending) {
            return [
                'success' => true,
                'created' => false,
                'item' => $existingPending,
                'message' => '已在待 AI 解释列表中。',
            ];
        }

        // V2: 若同一 key 存在 dismissed 项，恢复为 pending，而不是新建。
        // 这样避免同一 key 同时存在 dismissed + pending 两条记录，
        // 也避免无限新建 dismissed 历史行。
        $dismissedItem = AiStudyCardPendingItem::where(array_merge($baseLookup, [
            'status' => AiStudyCardPendingItem::STATUS_DISMISSED,
        ]))->first();

        if ($dismissedItem) {
            $dismissedItem->update([
                'status' => AiStudyCardPendingItem::STATUS_PENDING,
                'word' => $word,
                'surface' => $data['surface'] ?? $word,
                'lemma' => $data['lemma'] ?? null,
                'sentence_text' => $data['sentence_text'] ?? null,
                'source_payload' => $data['source_payload'] ?? [],
            ]);

            return [
                'success' => true,
                'created' => false,
                'item' => $dismissedItem->fresh(),
                'message' => '已重新加入待 AI 解释。',
            ];
        }

        try {
            $item = AiStudyCardPendingItem::create(array_merge($pendingLookup, [
                'language' => $language,
                'sentence_index' => array_key_exists('sentence_index', $data) && $data['sentence_index'] !== null
                    ? (int) $data['sentence_index'] : null,
                'sentence_id' => $data['sentence_id'] ?? null,
                'word' => $word,
                'surface' => $data['surface'] ?? $word,
                'lemma' => $data['lemma'] ?? null,
                'sentence_text' => $data['sentence_text'] ?? null,
                'source_payload' => $data['source_payload'] ?? [],
            ]));
        } catch (QueryException $e) {
            $item = AiStudyCardPendingItem::where($pendingLookup)->first();
            if (!$item) {
                throw $e;
            }

            return [
                'success' => true,
                'created' => false,
                'item' => $item,
                'message' => '已在待 AI 解释列表中。',
            ];
        }

        return [
            'success' => true,
            'created' => true,
            'item' => $item,
            'message' => '已加入待 AI 解释。',
        ];
    }

    /**
     * V2: 列出当前用户的待 AI 解释项。
     * V3: 扩展支持 status=pending|dismissed|all，默认 pending。
     * 支持按 chapter_id 过滤（可选）。
     */
    public function listPending(User $user, ?int $chapterId = null, string $statusFilter = 'pending'): array
    {
        $language = $user->selected_language;

        $query = AiStudyCardPendingItem::where('user_id', $user->id)
            ->where('language_id', $language);

        // V3: status 过滤。只接受 pending / dismissed / all，其他值回退为 pending。
        if ($statusFilter === 'all') {
            // 不加 status 条件
        } elseif ($statusFilter === 'dismissed') {
            $query->where('status', AiStudyCardPendingItem::STATUS_DISMISSED);
        } else {
            $query->where('status', AiStudyCardPendingItem::STATUS_PENDING);
        }

        if ($chapterId !== null) {
            // 仅当章节属于当前用户当前语言时才过滤；否则忽略过滤返回空集，避免泄露。
            $chapter = Chapter::where('id', $chapterId)
                ->where('user_id', $user->id)
                ->where('language', $language)
                ->first();
            if (!$chapter) {
                return [
                    'success' => false,
                    'status' => 404,
                    'message' => '章节不存在或不属于当前用户。',
                ];
            }
            $query->where('chapter_id', $chapterId);
        }

        $items = $query->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return [
            'success' => true,
            'items' => $items,
        ];
    }

    /**
     * V3: 构建安全生成包（preview-package）。
     *
     * 只打包当前用户、当前语言、pending 状态的 item。
     * dismissed 项不能进入生成包。
     * 不调用 AI，不生成 WordSense/ReviewCard/ReviewLog，不触发 FSRS。
     * 不改变 pending item 状态。
     */
    public function buildPreviewPackage(User $user, array $itemIds): array
    {
        if (empty($itemIds)) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '请至少选择一个待解释项。',
            ];
        }

        // 限制单次打包数量，避免过大 payload。
        if (count($itemIds) > 100) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '单次生成包最多 100 项。',
            ];
        }

        $language = $user->selected_language;

        // 只查当前用户、当前语言、pending 状态的 item。
        // dismissed / 其他用户 / 其他语言的 item 会被自动排除。
        $items = AiStudyCardPendingItem::where('user_id', $user->id)
            ->where('language_id', $language)
            ->where('status', AiStudyCardPendingItem::STATUS_PENDING)
            ->whereIn('id', $itemIds)
            ->get();

        if ($items->isEmpty()) {
            return [
                'success' => false,
                'status' => 404,
                'message' => '没有可打包的待解释项（可能已被取消或不属于当前用户）。',
            ];
        }

        $selectedItems = $items->map(function ($item) {
            return [
                'item_id' => $item->id,
                'chapter_id' => $item->chapter_id,
                'text_block_index' => $item->text_block_index,
                'sentence_index' => $item->sentence_index,
                'word' => $item->word,
                'normalized_word' => $item->normalized_word,
                'surface' => $item->surface,
                'lemma' => $item->lemma,
                'sentence_text' => $item->sentence_text,
                'status' => $item->status,
                'created_at' => $item->created_at?->toIso8601String(),
            ];
        })->values()->toArray();

        $package = [
            'schema_version' => 'ai-study-card-preview-package-v1',
            'created_at' => now()->toIso8601String(),
            'selected_items' => $selectedItems,
            'generation_rules' => [
                'no_auto_review_card' => true,
                'ai_recommended_default_unchecked' => true,
                'ai_recommended_exclude_user_selected' => true,
                'user_confirmation_required_before_generation' => true,
            ],
            'safety_flags' => [
                'no_ai_called' => true,
                'no_review_card_created' => true,
                'no_word_sense_created' => true,
                'no_fsrs_changed' => true,
            ],
        ];

        return [
            'success' => true,
            'package' => $package,
            'message' => '已生成安全预览包（未调用 AI，未生成复习卡）。',
        ];
    }

    /**
     * V4: 构建最终候选包（final-candidates-package）。
     *
     * 入参：
     *   - selected_item_ids: 用户已选 pending item id 列表
     *   - selected_ai_recommendations: 用户勾选的 AI 推荐词（已去重，前端已勾选）
     *   - unselected_ai_recommendations: 用户未勾选的 AI 推荐词（已去重，前端未勾选）
     *   - dedupe_summary: 前端去重摘要（可选）
     *   - source_preview_package: 来源 V3 安全生成包（可选，仅作记录）
     *
     * 后端二次去重：
     *   - selected_item_ids 必须属于当前用户/当前语言/pending
     *   - selected_ai_recommendations 不能和用户已选词重复（lemma 或 word，大小写不敏感）
     *   - selected_ai_recommendations 之间不能重复
     *   - unselected_ai_recommendations 不能和 selected_ai_recommendations 重复
     *
     * 不调用 AI，不生成 WordSense/ReviewCard/ReviewLog，不触发 FSRS。
     * 不改变 pending item 状态。
     */
    public function buildFinalCandidatesPackage(User $user, array $payload): array
    {
        $selectedItemIds = is_array($payload['selected_item_ids'] ?? null) ? $payload['selected_item_ids'] : [];
        $selectedAi = is_array($payload['selected_ai_recommendations'] ?? null) ? $payload['selected_ai_recommendations'] : [];
        $unselectedAi = is_array($payload['unselected_ai_recommendations'] ?? null) ? $payload['unselected_ai_recommendations'] : [];
        $dedupeSummary = is_array($payload['dedupe_summary'] ?? null) ? $payload['dedupe_summary'] : [];
        $sourcePreviewPackage = is_array($payload['source_preview_package'] ?? null) ? $payload['source_preview_package'] : null;

        // 至少要有用户已选词或 AI 推荐词被选中
        if (empty($selectedItemIds) && empty($selectedAi)) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '请至少选择一个用户已选词或勾选一个 AI 推荐词。',
            ];
        }

        // 限制 selected_item_ids 数量
        if (count($selectedItemIds) > 100) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '单次最终候选包最多 100 个用户已选词。',
            ];
        }

        // 限制 AI 推荐词数量
        $aiCount = count($selectedAi) + count($unselectedAi);
        if ($aiCount > 200) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '单次最终候选包最多 200 个 AI 推荐词。',
            ];
        }

        $language = $user->selected_language;

        // 查询用户已选词，三重隔离：user_id / language_id / status=pending
        $items = !empty($selectedItemIds)
            ? AiStudyCardPendingItem::where('user_id', $user->id)
                ->where('language_id', $language)
                ->where('status', AiStudyCardPendingItem::STATUS_PENDING)
                ->whereIn('id', $selectedItemIds)
                ->get()
            : collect();

        // 用户已选词的 lemma/word 集合（用于排除 AI 推荐词）
        $userSelectedKeys = [];
        foreach ($items as $item) {
            $key = $this->dedupeKey($item->lemma, $item->word);
            if ($key !== '') {
                $userSelectedKeys[$key] = true;
            }
        }

        // 后端二次去重：selected_ai_recommendations
        $seenAiKeys = [];
        $cleanSelectedAi = [];
        $droppedDuplicateWithUser = 0;
        $droppedAiInternalDuplicate = 0;
        foreach ($selectedAi as $rec) {
            $word = trim((string) ($rec['word'] ?? ''));
            if ($word === '') {
                continue;
            }
            $lemma = trim((string) ($rec['lemma'] ?? '')) ?: $word;
            $key = $this->dedupeKey($lemma, $word);
            if ($key === '') {
                continue;
            }
            if (isset($userSelectedKeys[$key])) {
                $droppedDuplicateWithUser++;
                continue;
            }
            if (isset($seenAiKeys[$key])) {
                $droppedAiInternalDuplicate++;
                continue;
            }
            $seenAiKeys[$key] = true;
            $cleanSelectedAi[] = $this->normalizeAiRecommendation($rec, $word, $lemma);
        }

        // unselected_ai_recommendations 去重（不与 selected_ai 重复，不与用户已选词重复）
        $cleanUnselectedAi = [];
        foreach ($unselectedAi as $rec) {
            $word = trim((string) ($rec['word'] ?? ''));
            if ($word === '') {
                continue;
            }
            $lemma = trim((string) ($rec['lemma'] ?? '')) ?: $word;
            $key = $this->dedupeKey($lemma, $word);
            if ($key === '') {
                continue;
            }
            if (isset($userSelectedKeys[$key])) {
                continue;
            }
            if (isset($seenAiKeys[$key])) {
                continue;
            }
            $seenAiKeys[$key] = true;
            $cleanUnselectedAi[] = $this->normalizeAiRecommendation($rec, $word, $lemma);
        }

        $userSelectedItems = $items->map(function ($item) {
            return [
                'item_id' => $item->id,
                'chapter_id' => $item->chapter_id,
                'text_block_index' => $item->text_block_index,
                'sentence_index' => $item->sentence_index,
                'word' => $item->word,
                'normalized_word' => $item->normalized_word,
                'surface' => $item->surface,
                'lemma' => $item->lemma,
                'sentence_text' => $item->sentence_text,
                'status' => $item->status,
                'source' => 'user_selected',
            ];
        })->values()->toArray();

        // V4: 查询后再次检查 — 如果用户已选词被全部过滤掉（不属于当前用户/语言/pending），
        // 且 AI 推荐词也为空，则返回 422。
        // 这防止了 selected_item_ids 非空但实际查询结果为空的边界情况。
        if (empty($userSelectedItems) && empty($cleanSelectedAi)) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '没有有效的用户已选词或 AI 推荐词可打包（可能已被取消、不属于当前用户或语言不匹配）。',
            ];
        }

        // 合并前后端去重摘要
        $mergedDedupeSummary = [
            'original_ai_count' => $dedupeSummary['original_ai_count'] ?? count($selectedAi) + count($unselectedAi),
            'valid_ai_count' => count($cleanSelectedAi) + count($cleanUnselectedAi),
            'dropped_missing_word' => $dedupeSummary['dropped_missing_word'] ?? 0,
            'dropped_duplicate_with_user' => ($dedupeSummary['dropped_duplicate_with_user'] ?? 0) + $droppedDuplicateWithUser,
            'dropped_ai_internal_duplicate' => ($dedupeSummary['dropped_ai_internal_duplicate'] ?? 0) + $droppedAiInternalDuplicate,
            'backend_deduplication_applied' => $droppedDuplicateWithUser + $droppedAiInternalDuplicate > 0,
        ];

        $package = [
            'schema_version' => 'ai-study-card-final-candidates-v1',
            'source_preview_package_schema_version' => $sourcePreviewPackage['schema_version'] ?? null,
            'created_at' => now()->toIso8601String(),
            'user_selected_items' => $userSelectedItems,
            'ai_recommended_selected_items' => $cleanSelectedAi,
            'ai_recommended_unselected_items' => $cleanUnselectedAi,
            'dedupe_summary' => $mergedDedupeSummary,
            'generation_rules' => [
                'no_auto_review_card' => true,
                'ai_recommended_default_unchecked' => true,
                'ai_recommended_exclude_user_selected' => true,
                'user_confirmation_required_before_generation' => true,
                'user_confirmation_required_before_card_generation' => true,
            ],
            'safety_flags' => [
                'no_ai_called_by_linguacafe' => true,
                'ai_response_pasted_by_user' => true,
                'no_review_card_created' => true,
                'no_word_sense_created' => true,
                'no_fsrs_changed' => true,
                'user_confirmation_required_before_card_generation' => true,
            ],
        ];

        return [
            'success' => true,
            'package' => $package,
            'message' => '已生成最终候选包（未调用 AI，未生成复习卡）。',
        ];
    }

    /**
     * V4: 去重 key：优先 lemma，否则 word；大小写不敏感；前后空格忽略。
     */
    private function dedupeKey(?string $lemma, ?string $word): string
    {
        $candidate = trim((string) $lemma);
        if ($candidate === '') {
            $candidate = trim((string) $word);
        }
        if ($candidate === '') {
            return '';
        }
        return mb_strtolower($candidate, 'UTF-8');
    }

    /**
     * V4: 归一化 AI 推荐词条目。
     */
    private function normalizeAiRecommendation(array $rec, string $word, string $lemma): array
    {
        return [
            'word' => $word,
            'lemma' => $lemma,
            'surface' => trim((string) ($rec['surface'] ?? '')) ?: $word,
            'reason' => trim((string) ($rec['reason'] ?? '')) ?: '无说明',
            'sentence_text' => trim((string) ($rec['sentence_text'] ?? '')) ?: null,
            'confidence' => array_key_exists('confidence', $rec) ? $rec['confidence'] : null,
            'source' => 'ai_recommended',
        ];
    }

    /**
     * V2: 取消（dismiss）一个待解释项。
     * 不物理删除，状态从 pending 改为 dismissed。
     */
    public function dismiss(User $user, int $itemId): array
    {
        $item = AiStudyCardPendingItem::where('id', $itemId)
            ->where('user_id', $user->id)
            ->where('language_id', $user->selected_language)
            ->first();

        if (!$item) {
            return [
                'success' => false,
                'status' => 404,
                'message' => '待解释项不存在或不属于当前用户。',
            ];
        }

        if ($item->status === AiStudyCardPendingItem::STATUS_DISMISSED) {
            return [
                'success' => true,
                'item' => $item,
                'message' => '已取消。',
            ];
        }

        $item->update([
            'status' => AiStudyCardPendingItem::STATUS_DISMISSED,
        ]);

        return [
            'success' => true,
            'item' => $item->fresh(),
            'message' => '已取消。',
        ];
    }

    /**
     * V2: 恢复一个已 dismissed 的待解释项为 pending。
     * 用于用户在待解释列表中误取消后的恢复。
     */
    public function restore(User $user, int $itemId): array
    {
        $item = AiStudyCardPendingItem::where('id', $itemId)
            ->where('user_id', $user->id)
            ->where('language_id', $user->selected_language)
            ->first();

        if (!$item) {
            return [
                'success' => false,
                'status' => 404,
                'message' => '待解释项不存在或不属于当前用户。',
            ];
        }

        if ($item->status === AiStudyCardPendingItem::STATUS_PENDING) {
            return [
                'success' => true,
                'item' => $item,
                'message' => '已在待 AI 解释列表中。',
            ];
        }

        // 恢复前先检查是否已存在 pending 行（避免 unique 冲突）
        $existingPending = AiStudyCardPendingItem::where([
            'user_id' => $user->id,
            'language_id' => $user->selected_language,
            'chapter_id' => $item->chapter_id,
            'text_block_index' => $item->text_block_index,
            'normalized_word' => $item->normalized_word,
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ])->first();

        if ($existingPending) {
            // 已有 pending 行，直接把 dismissed 行物理删除保持干净
            // （这种情况理论上不应发生，但作为兜底）
            $item->delete();
            return [
                'success' => true,
                'item' => $existingPending,
                'message' => '已重新加入待 AI 解释。',
            ];
        }

        $item->update([
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);

        return [
            'success' => true,
            'item' => $item->fresh(),
            'message' => '已重新加入待 AI 解释。',
        ];
    }

    private function normalizeWord(string $word): string
    {
        return mb_strtolower(trim($word), 'UTF-8');
    }

    /**
     * V5: 从用户确认的最终候选项生成学习卡。
     *
     * 入参：
     *   - confirmed_items: 用户在 V4 最终候选包基础上确认（输入释义）后的候选项列表
     *     每项包含：source (user_selected|ai_recommended), word, lemma, surface, chapter_id,
     *               sentence_id, sentence_text, sense_zh, sense_en, pos, aliases_zh, collocations,
     *               item_id (source=user_selected 时必填)
     *   - final_candidates_package: V4 输出的完整候选包（用于交叉校验）
     *
     * 处理逻辑（每项独立事务，单项失败不影响其他）：
     *   1. 严格校验：当前用户、当前语言、pending item 归属、chapter 归属、lemma/surface/sense_zh 合法
     *   2. 创建/查找 confirmed WordSense（按 sense_key + alias 去重）
     *   3. 创建/确保 target_type=sense ReviewCard（firstOrCreate 幂等）
     *   4. 保存来源例句（WordSenseOccurrence, SOURCE_MANUAL_SENSE_ADD）
     *
     * 安全边界：
     *   - 不调用 AI。
     *   - 不写 ReviewLog。
     *   - 不改 FSRS 调度（新卡 fsrs_state='new', fsrs_due_at=now()）。
     *   - 不创建 legacy word ReviewCard。
     *   - 不删除 WordSense/ReviewCard/ReviewLog。
     *   - 不改变 pending item 状态（用户可手动 dismiss）。
     */
    public function generateCardsFromConfirmedCandidates(User $user, array $confirmedItems, array $finalCandidatesPackage): array
    {
        // 超量批量拒绝（保守上限 50）
        if (count($confirmedItems) > 50) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '单次最多生成 50 张学习卡，请分批确认。',
            ];
        }

        $language = $user->selected_language;

        // 提取 V4 候选包中的 pending item_id 集合（用于交叉校验 user_selected 项）
        $packageSelectedItemIds = [];
        if (isset($finalCandidatesPackage['user_selected_items']) && is_array($finalCandidatesPackage['user_selected_items'])) {
            foreach ($finalCandidatesPackage['user_selected_items'] as $pkgItem) {
                if (isset($pkgItem['item_id'])) {
                    $packageSelectedItemIds[] = (int) $pkgItem['item_id'];
                }
            }
        }

        // 查询当前用户/当前语言/pending 的合法 pending items（三重隔离）
        $validPendingItems = !empty($packageSelectedItemIds)
            ? AiStudyCardPendingItem::where('user_id', $user->id)
                ->where('language_id', $language)
                ->where('status', AiStudyCardPendingItem::STATUS_PENDING)
                ->whereIn('id', $packageSelectedItemIds)
                ->get()
                ->keyBy('id')
            : collect();

        // 查询当前用户/当前语言的合法 chapters
        $chapterIdsInConfirmed = [];
        foreach ($confirmedItems as $confirmedItem) {
            if (!empty($confirmedItem['chapter_id'])) {
                $chapterIdsInConfirmed[] = (int) $confirmedItem['chapter_id'];
            }
        }
        $chapterIdsInConfirmed = array_unique($chapterIdsInConfirmed);

        $validChapters = !empty($chapterIdsInConfirmed)
            ? Chapter::where('user_id', $user->id)
                ->where('language', $language)
                ->whereIn('id', $chapterIdsInConfirmed)
                ->get()
                ->keyBy('id')
            : collect();

        $created = [];
        $skipped = [];
        $duplicate = [];
        $failed = [];

        foreach ($confirmedItems as $confirmedItem) {
            try {
                $source = (string) ($confirmedItem['source'] ?? '');
                $word = trim((string) ($confirmedItem['word'] ?? ''));
                $senseZh = trim((string) ($confirmedItem['sense_zh'] ?? ''));
                $lemma = trim((string) ($confirmedItem['lemma'] ?? '')) ?: $word;
                $surface = trim((string) ($confirmedItem['surface'] ?? '')) ?: $word;
                $chapterId = !empty($confirmedItem['chapter_id']) ? (int) $confirmedItem['chapter_id'] : null;
                $itemId = !empty($confirmedItem['item_id']) ? (int) $confirmedItem['item_id'] : null;
                $sentenceId = $confirmedItem['sentence_id'] ?? null;
                $sentenceText = trim((string) ($confirmedItem['sentence_text'] ?? ''));

                // ===== 严格校验 =====
                // 4a. 必填字段：word / sense_zh 非空（Laravel 已校验 max 长度，这里校验 trim 后非空）
                if ($word === '') {
                    $skipped[] = ['source' => $source, 'word' => '', 'reason' => 'empty_word'];
                    continue;
                }
                if ($senseZh === '') {
                    $skipped[] = ['source' => $source, 'word' => $word, 'reason' => 'empty_sense_zh'];
                    continue;
                }

                // 4b. source 合法
                if (!in_array($source, ['user_selected', 'ai_recommended'], true)) {
                    $skipped[] = ['source' => $source, 'word' => $word, 'reason' => 'invalid_source'];
                    continue;
                }

                // 4c. pending item 归属（仅 user_selected）
                if ($source === 'user_selected') {
                    if (!$itemId || !$validPendingItems->has($itemId)) {
                        $skipped[] = ['source' => $source, 'word' => $word, 'reason' => 'invalid_pending_item'];
                        continue;
                    }
                }

                // 4d. chapter 归属
                if ($chapterId !== null && !$validChapters->has($chapterId)) {
                    $skipped[] = ['source' => $source, 'word' => $word, 'reason' => 'invalid_chapter'];
                    continue;
                }

                // ===== 创建/查找 WordSense（事务内） =====
                $result = DB::transaction(function () use (
                    $user, $language, $confirmedItem, $word, $lemma, $surface,
                    $senseZh, $chapterId, $sentenceId, $sentenceText
                ) {
                    $senseData = [
                        'user_id' => $user->id,
                        'language' => $language,
                        'language_id' => $language,
                        'lemma' => $lemma,
                        'surface_form' => $surface,
                        'pos' => $confirmedItem['pos'] ?? null,
                        'sense_zh' => $senseZh,
                        'sense_en' => $confirmedItem['sense_en'] ?? null,
                        'aliases_zh' => $confirmedItem['aliases_zh'] ?? [],
                        'collocations' => $confirmedItem['collocations'] ?? [],
                        'example_sentence_en' => $sentenceText !== '' ? $sentenceText : null,
                        'example_sentence_zh' => null,
                        'source_chapter_id' => $chapterId,
                        'sentence_id' => $sentenceId,
                        'status' => WordSense::STATUS_CONFIRMED,
                    ];

                    // createOrFindSense 内部按 sense_key + alias 去重
                    $sense = $this->wordSenseService->createOrFindSense($senseData);
                    $senseWasNewlyCreated = $sense->wasRecentlyCreated;
                    // 如果 sense 已存在但不是 confirmed（如 ai_suggested），升级为 confirmed
                    $senseNeededUpgrade = !$senseWasNewlyCreated && $sense->status !== WordSense::STATUS_CONFIRMED;
                    if ($senseNeededUpgrade) {
                        $this->wordSenseService->confirmSense($sense);
                        $sense->refresh();
                    }

                    // 创建/确保 target_type=sense ReviewCard（firstOrCreate 幂等）
                    $card = $this->wordSenseService->createReviewCardForSense($sense);
                    $cardWasNewlyCreated = $card ? $card->wasRecentlyCreated : false;

                    // 保存来源例句（WordSenseOccurrence）
                    if ($sentenceId !== null && $sentenceId !== '' && $sentenceText !== '') {
                        WordSenseOccurrence::updateOrCreate(
                            [
                                'user_id' => $sense->user_id,
                                'language_id' => $sense->language_id,
                                'word_sense_id' => $sense->id,
                                'chapter_id' => $chapterId,
                                'sentence_id' => (string) $sentenceId,
                                'surface' => $surface,
                                'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
                            ],
                            [
                                'language' => $sense->language,
                                'review_card_id' => $card?->id,
                                'sentence_en' => $sentenceText,
                                'sentence_zh' => null,
                                'type' => WordSenseOccurrence::TYPE_WORD,
                                'lemma' => $sense->lemma,
                                'pos' => $sense->pos,
                                'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
                                'confidence' => 1.0,
                                'evidence' => ['source' => 'ai_study_card_confirmed_candidate'],
                                'auto_fsrs_allowed' => true,
                                'status' => WordSenseOccurrence::STATUS_BOUND,
                                'raw_payload' => [
                                    'sense_zh' => $sense->sense_zh,
                                    'sense_en' => $sense->sense_en,
                                    'aliases_zh' => $sense->aliases_zh ?: [],
                                    'collocations' => $sense->collocations ?: [],
                                    'confirmed_from' => 'ai_study_card_candidate',
                                ],
                            ]
                        );
                    }

                    $isCreated = $senseWasNewlyCreated || $senseNeededUpgrade || $cardWasNewlyCreated;

                    return [
                        'sense' => $sense,
                        'card' => $card,
                        'is_created' => $isCreated,
                    ];
                });

                if ($result['is_created']) {
                    $created[] = [
                        'source' => $source,
                        'item_id' => $itemId,
                        'word' => $word,
                        'lemma' => $result['sense']->lemma,
                        'sense_id' => $result['sense']->id,
                        'review_card_id' => $result['card']?->id,
                        'is_new_sense' => !$result['sense']->exists || $result['sense']->wasRecentlyCreated,
                        'is_new_card' => $result['card'] ? $result['card']->wasRecentlyCreated : false,
                    ];
                } else {
                    $duplicate[] = [
                        'source' => $source,
                        'item_id' => $itemId,
                        'word' => $word,
                        'lemma' => $result['sense']->lemma,
                        'sense_id' => $result['sense']->id,
                        'review_card_id' => $result['card']?->id,
                        'reason' => 'sense_and_card_already_exist',
                    ];
                }
            } catch (Throwable $e) {
                $failed[] = [
                    'source' => $confirmedItem['source'] ?? '',
                    'word' => $confirmedItem['word'] ?? '',
                    'reason' => 'exception: ' . $e->getMessage(),
                ];
            }
        }

        $summary = [
            'total' => count($confirmedItems),
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'duplicate_count' => count($duplicate),
            'failed_count' => count($failed),
        ];

        return [
            'success' => true,
            'message' => sprintf(
                '已生成 %d 张学习卡，跳过 %d 项，重复 %d 项，失败 %d 项。',
                $summary['created_count'],
                $summary['skipped_count'],
                $summary['duplicate_count'],
                $summary['failed_count']
            ),
            'results' => [
                'created' => $created,
                'skipped' => $skipped,
                'duplicate' => $duplicate,
                'failed' => $failed,
                'summary' => $summary,
            ],
            'safety_flags' => [
                'no_ai_called_by_linguacafe' => true,
                'ai_response_pasted_by_user' => true,
                'no_review_log_written' => true,
                'no_fsrs_rescheduled' => true,
                'no_legacy_word_card_created' => true,
                'user_confirmation_received' => true,
            ],
        ];
    }
}
