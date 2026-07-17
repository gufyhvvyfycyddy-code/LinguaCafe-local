<?php

namespace App\Services;

use App\Models\AiStudyCardPendingItem;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiStudyCardGenerationService
{
    public function __construct(
        private WordSenseService $wordSenseService,
        private AiStudyCardPendingLifecycleService $pendingLifecycleService
    ) {
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

        // ===== V5 hardening: 反向校验 final_candidates_package =====
        // 提取 V4 候选包中的 user_selected item_id 集合 + ai_recommended 安全 key 集合
        $packageSelectedItemIds = [];
        $packageAiRecommendedKeys = []; // normalized_lemma|normalized_word => true
        $packageUserSelectedKeys = [];  // item_id => normalized_lemma|normalized_word

        if (isset($finalCandidatesPackage['user_selected_items']) && is_array($finalCandidatesPackage['user_selected_items'])) {
            foreach ($finalCandidatesPackage['user_selected_items'] as $pkgItem) {
                if (isset($pkgItem['item_id'])) {
                    $id = (int) $pkgItem['item_id'];
                    $packageSelectedItemIds[] = $id;
                    $key = $this->packageDedupeKey($pkgItem['lemma'] ?? null, $pkgItem['word'] ?? null);
                    if ($key !== '') {
                        $packageUserSelectedKeys[$id] = $key;
                    }
                }
            }
        }

        if (isset($finalCandidatesPackage['ai_recommended_selected_items']) && is_array($finalCandidatesPackage['ai_recommended_selected_items'])) {
            foreach ($finalCandidatesPackage['ai_recommended_selected_items'] as $pkgItem) {
                $key = $this->packageDedupeKey($pkgItem['lemma'] ?? null, $pkgItem['word'] ?? null);
                if ($key !== '') {
                    $packageAiRecommendedKeys[$key] = true;
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
                $textBlockIndex = isset($confirmedItem['text_block_index']) && $confirmedItem['text_block_index'] !== null
                    ? (int) $confirmedItem['text_block_index']
                    : null;
                $sentenceIndex = isset($confirmedItem['sentence_index']) && $confirmedItem['sentence_index'] !== null
                    ? (int) $confirmedItem['sentence_index']
                    : null;

                // ===== 严格校验 =====
                // 4a. 必填字段：word / sense_zh 非空
                if ($word === '') {
                    $skipped[] = $this->skippedResult($source, '', 'empty_word', null, null);
                    continue;
                }
                if ($senseZh === '') {
                    $skipped[] = $this->skippedResult($source, $word, 'empty_sense_zh', $lemma, $itemId);
                    continue;
                }

                // 4b. source 合法
                if (!in_array($source, ['user_selected', 'ai_recommended'], true)) {
                    $skipped[] = $this->skippedResult($source, $word, 'invalid_source', $lemma, $itemId);
                    continue;
                }

                // 4c. V5 hardening: 反向校验 — confirmed item 必须来自 final_candidates_package
                if ($source === 'user_selected') {
                    // item_id 必须在 final package.user_selected_items 中
                    if (!$itemId || !in_array($itemId, $packageSelectedItemIds, true)) {
                        $skipped[] = $this->skippedResult($source, $word, 'not_in_final_package_user_selected', $lemma, $itemId);
                        continue;
                    }
                    // item_id 必须属于当前用户/语言/pending
                    if (!$validPendingItems->has($itemId)) {
                        $skipped[] = $this->skippedResult($source, $word, 'invalid_pending_item', $lemma, $itemId);
                        continue;
                    }
                    // V5 hardening: word/lemma 必须与 final package 中该 item_id 对应的 word/lemma 匹配
                    $expectedKey = $packageUserSelectedKeys[$itemId] ?? '';
                    $actualKey = $this->packageDedupeKey($lemma, $word);
                    if ($expectedKey !== '' && $actualKey !== '' && $expectedKey !== $actualKey) {
                        $skipped[] = $this->skippedResult($source, $word, 'word_lemma_mismatch_with_final_package', $lemma, $itemId);
                        continue;
                    }
                } else { // ai_recommended
                    // 必须在 final package.ai_recommended_selected_items 中找到对应 key
                    $actualKey = $this->packageDedupeKey($lemma, $word);
                    if ($actualKey === '' || !isset($packageAiRecommendedKeys[$actualKey])) {
                        $skipped[] = $this->skippedResult($source, $word, 'not_in_final_package_ai_recommended', $lemma, $itemId);
                        continue;
                    }
                }

                // 4d. chapter 归属
                if ($chapterId !== null && !$validChapters->has($chapterId)) {
                    $skipped[] = $this->skippedResult($source, $word, 'invalid_chapter', $lemma, $itemId);
                    continue;
                }

                // ===== 创建/查找 WordSense + ReviewCard + Occurrence（事务内） =====
                $result = DB::transaction(function () use (
                    $user, $language, $confirmedItem, $word, $lemma, $surface,
                    $senseZh, $chapterId, $sentenceId, $sentenceText,
                    $textBlockIndex, $sentenceIndex
                ) {
                    $senseData = [
                        'user_id' => $user->id,
                        'language' => $language,
                        'language_id' => $language,
                        'lemma' => $lemma,
                        'surface_form' => $surface,
                        'pos' => $confirmedItem['pos'] ?? null,
                        'sense_zh' => $senseZh,
                        // V5 hardening: sense_en 允许为空（null 或空字符串都接受）
                        'sense_en' => $this->normalizeNullableString($confirmedItem['sense_en'] ?? null),
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
                    $senseNeededUpgrade = !$senseWasNewlyCreated && $sense->status !== WordSense::STATUS_CONFIRMED;
                    if ($senseNeededUpgrade) {
                        $this->wordSenseService->confirmSense($sense);
                        $sense->refresh();
                    }

                    $card = $this->wordSenseService->createReviewCardForSense($sense);
                    $cardWasNewlyCreated = $card ? $card->wasRecentlyCreated : false;

                    // ===== V5 hardening: 来源例句/occurrence 绑定收口 =====
                    // 1. 有 sentence_id + sentence_text：直接写 occurrence
                    // 2. 无 sentence_id 但有 chapter_id + sentence_text + (text_block_index 或 sentence_index)：
                    //    生成 synthetic sentence_id: ai-study-card:{chapter_id}:{text_block_index}:{sentence_index}:{normalized_word}
                    // 3. 无足够来源信息：不写 occurrence，但仍创建 sense/card
                    // 4. chapter 不属于当前用户/语言：不写 occurrence（已在 4d 跳过）
                    $occurrenceCreated = false;
                    $occurrenceId = null;
                    $occurrenceReason = null;
                    $effectiveSentenceId = $sentenceId;

                    if ($sentenceText === '') {
                        $occurrenceReason = 'no_sentence_text';
                    } elseif ($chapterId === null) {
                        $occurrenceReason = 'no_chapter_id';
                    } elseif ($textBlockIndex === null && $sentenceIndex === null && ($sentenceId === null || $sentenceId === '')) {
                        $occurrenceReason = 'insufficient_source_info';
                    } else {
                        // 生成 synthetic sentence_id（如果原 sentence_id 为空）
                        if ($effectiveSentenceId === null || $effectiveSentenceId === '') {
                            $tb = $textBlockIndex ?? 0;
                            $si = $sentenceIndex ?? 0;
                            $normalizedWord = mb_strtolower(trim($word), 'UTF-8');
                            $effectiveSentenceId = 'ai-study-card:' . $chapterId . ':' . $tb . ':' . $si . ':' . $normalizedWord;
                            $occurrenceReason = 'synthetic_sentence_id';
                        } else {
                            $occurrenceReason = 'explicit_sentence_id';
                        }

                        $occurrence = WordSenseOccurrence::updateOrCreate(
                            [
                                'user_id' => $sense->user_id,
                                'language_id' => $sense->language_id,
                                'word_sense_id' => $sense->id,
                                'chapter_id' => $chapterId,
                                'sentence_id' => (string) $effectiveSentenceId,
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
                                    'sentence_id_source' => $occurrenceReason,
                                ],
                            ]
                        );
                        $occurrenceCreated = true;
                        $occurrenceId = $occurrence->id;
                    }

                    $isCreated = $senseWasNewlyCreated || $senseNeededUpgrade || $cardWasNewlyCreated;

                    return [
                        'sense' => $sense,
                        'card' => $card,
                        'is_created' => $isCreated,
                        'occurrence_created' => $occurrenceCreated,
                        'occurrence_id' => $occurrenceId,
                        'occurrence_reason' => $occurrenceReason,
                        'effective_sentence_id' => $effectiveSentenceId,
                    ];
                });

                // V5 hardening: 来源绑定状态文案
                $sourceBindingStatus = $this->resolveSourceBindingStatus(
                    $result['occurrence_created'],
                    $result['occurrence_reason']
                );

                $baseResult = [
                    'source' => $source,
                    'item_id' => $itemId,
                    'word' => $word,
                    'lemma' => $result['sense']->lemma,
                    'sense_id' => $result['sense']->id,
                    'review_card_id' => $result['card']?->id,
                    'occurrence_created' => $result['occurrence_created'],
                    'occurrence_id' => $result['occurrence_id'],
                    'source_binding_status' => $sourceBindingStatus,
                    'source_binding_reason' => $result['occurrence_reason'],
                ];

                // V5-lifecycle: user_selected + created/duplicate → mark pending item as processed.
                // ai_recommended → no pending item to update.
                if ($source === 'user_selected' && $itemId) {
                    $lifecycle = $this->pendingLifecycleService->markProcessed(
                        $user, $language, $itemId,
                        $result['is_created'] ? 'created' : 'duplicate'
                    );
                } else {
                    $lifecycle = $this->pendingLifecycleService->emptyLifecycleInfo();
                }
                $baseResult = array_merge($baseResult, $lifecycle);

                if ($result['is_created']) {
                    $baseResult['is_new_sense'] = !$result['sense']->exists || $result['sense']->wasRecentlyCreated;
                    $baseResult['is_new_card'] = $result['card'] ? $result['card']->wasRecentlyCreated : false;
                    $created[] = $baseResult;
                } else {
                    $baseResult['reason'] = 'sense_and_card_already_exist';
                    $duplicate[] = $baseResult;
                }
            } catch (Throwable $e) {
                $failedItemId = !empty($confirmedItem['item_id']) ? (int) $confirmedItem['item_id'] : null;
                $failed[] = [
                    'source' => $confirmedItem['source'] ?? '',
                    'word' => $confirmedItem['word'] ?? '',
                    'reason' => 'exception: ' . $e->getMessage(),
                    'pending_item_id' => $failedItemId,
                    'pending_item_status_before' => null,
                    'pending_item_status_after' => null,
                    'pending_item_processed' => false,
                    'pending_item_process_reason' => null,
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

    /**
     * V5 hardening: 反向校验用的 dedupe key（与 V4 buildFinalCandidatesPackage 一致）。
     * 优先 lemma，否则 word；大小写不敏感。
     */
    private function packageDedupeKey(?string $lemma, ?string $word): string
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
     * V5 hardening: 归一化可空字符串。空字符串转 null，其余 trim。
     */
    private function normalizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * V5 hardening: 根据 occurrence 创建结果返回前端可读的来源绑定状态。
     */
    private function resolveSourceBindingStatus(bool $occurrenceCreated, ?string $reason): string
    {
        if ($occurrenceCreated) {
            return $reason === 'synthetic_sentence_id'
                ? '来源已绑定（合成 sentence_id）'
                : '来源已绑定';
        }
        return '来源信息不足，已创建卡片但未绑定来源';
    }

    /**
     * V5 hardening: 统一构造 skipped 结果项。
     * V5-lifecycle: 包含 pending_item 生命周期字段（skipped 不标记 processed）。
     */
    private function skippedResult(string $source, string $word, string $reason, ?string $lemma, ?int $itemId): array
    {
        return [
            'source' => $source,
            'word' => $word,
            'lemma' => $lemma,
            'item_id' => $itemId,
            'reason' => $reason,
            'pending_item_id' => $itemId,
            'pending_item_status_before' => null,
            'pending_item_status_after' => null,
            'pending_item_processed' => false,
            'pending_item_process_reason' => null,
        ];
    }

}
