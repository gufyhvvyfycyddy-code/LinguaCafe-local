<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiStudyCardPendingItemService
{
    private AiStudyCardPendingLifecycleService $pendingLifecycleService;
    private AiStudyCardCandidatePackageService $candidatePackageService;
    private AiStudyCardCandidateValidationService $candidateValidationService;

    public function __construct(private WordSenseService $wordSenseService)
    {
        $this->pendingLifecycleService = new AiStudyCardPendingLifecycleService();
        $this->candidatePackageService = new AiStudyCardCandidatePackageService();
        $this->candidateValidationService = new AiStudyCardCandidateValidationService();
    }

    public function createOrGetPending(User $user, array $data): array
    {
        return $this->pendingLifecycleService->createOrGetPending($user, $data);
    }

    public function listPending(User $user, ?int $chapterId = null, string $statusFilter = 'pending'): array
    {
        return $this->pendingLifecycleService->listPending($user, $chapterId, $statusFilter);
    }

    public function buildPreviewPackage(User $user, array $itemIds): array
    {
        return $this->candidatePackageService->buildPreviewPackage($user, $itemIds);
    }

    public function buildFinalCandidatesPackage(User $user, array $payload): array
    {
        return $this->candidatePackageService->buildFinalCandidatesPackage($user, $payload);
    }

    public function dismiss(User $user, int $itemId): array
    {
        return $this->pendingLifecycleService->dismiss($user, $itemId);
    }

    public function restore(User $user, int $itemId): array
    {
        return $this->pendingLifecycleService->restore($user, $itemId);
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
        $validationPreparation = $this->candidateValidationService->prepare(
            $user,
            $confirmedItems,
            $finalCandidatesPackage
        );
        if (!$validationPreparation['success']) {
            return $validationPreparation;
        }
        $validationContext = $validationPreparation['context'];
        $language = $user->selected_language;

        $created = [];
        $skipped = [];
        $duplicate = [];
        $failed = [];

        foreach ($confirmedItems as $confirmedItem) {
            try {
                $validation = $this->candidateValidationService->validate($confirmedItem, $validationContext);
                if (!$validation['success']) {
                    $skipped[] = $validation['skipped'];
                    continue;
                }
                $candidate = $validation['candidate'];
                $source = $candidate['source'];
                $word = $candidate['word'];
                $senseZh = $candidate['sense_zh'];
                $lemma = $candidate['lemma'];
                $surface = $candidate['surface'];
                $chapterId = $candidate['chapter_id'];
                $itemId = $candidate['item_id'];
                $sentenceId = $candidate['sentence_id'];
                $sentenceText = $candidate['sentence_text'];
                $textBlockIndex = $candidate['text_block_index'];
                $sentenceIndex = $candidate['sentence_index'];

                // ===== 创建/查找 WordSense + ReviewCard + Occurrence（事务内） =====
                $result = DB::transaction(function () use (
                    $user, $language, $candidate, $word, $lemma, $surface,
                    $senseZh, $chapterId, $sentenceId, $sentenceText,
                    $textBlockIndex, $sentenceIndex
                ) {
                    $senseData = [
                        'user_id' => $user->id,
                        'language' => $language,
                        'language_id' => $language,
                        'lemma' => $lemma,
                        'surface_form' => $surface,
                        'pos' => $candidate['pos'] ?? null,
                        'sense_zh' => $senseZh,
                        'sense_en' => $candidate['sense_en'],
                        'aliases_zh' => $candidate['aliases_zh'] ?? [],
                        'collocations' => $candidate['collocations'] ?? [],
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
                    $lifecycle = $this->pendingLifecycleService->emptyInfo();
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

}
