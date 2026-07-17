<?php

namespace App\Services;

use App\Models\AiStudyCardPendingItem;
use App\Models\User;

class AiStudyCardPendingPackageService
{
    public function buildPreviewPackage(User $user, array $itemIds): array
    {
        if (empty($itemIds)) {
            return ['success' => false, 'status' => 422, 'message' => '请至少选择一个待解释项。'];
        }
        if (count($itemIds) > 100) {
            return ['success' => false, 'status' => 422, 'message' => '单次生成包最多 100 项。'];
        }

        $items = AiStudyCardPendingItem::where('user_id', $user->id)
            ->where('language_id', $user->selected_language)
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

        $selectedItems = $items->map(fn ($item) => [
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
        ])->values()->toArray();

        return [
            'success' => true,
            'package' => [
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
            ],
            'message' => '已生成安全预览包（未调用 AI，未生成复习卡）。',
        ];
    }

    public function buildFinalCandidatesPackage(User $user, array $payload): array
    {
        $selectedItemIds = is_array($payload['selected_item_ids'] ?? null) ? $payload['selected_item_ids'] : [];
        $selectedAi = is_array($payload['selected_ai_recommendations'] ?? null) ? $payload['selected_ai_recommendations'] : [];
        $unselectedAi = is_array($payload['unselected_ai_recommendations'] ?? null) ? $payload['unselected_ai_recommendations'] : [];
        $dedupeSummary = is_array($payload['dedupe_summary'] ?? null) ? $payload['dedupe_summary'] : [];
        $sourcePreviewPackage = is_array($payload['source_preview_package'] ?? null) ? $payload['source_preview_package'] : null;

        if (empty($selectedItemIds) && empty($selectedAi)) {
            return ['success' => false, 'status' => 422, 'message' => '请至少选择一个用户已选词或勾选一个 AI 推荐词。'];
        }
        if (count($selectedItemIds) > 100) {
            return ['success' => false, 'status' => 422, 'message' => '单次最终候选包最多 100 个用户已选词。'];
        }
        if (count($selectedAi) + count($unselectedAi) > 200) {
            return ['success' => false, 'status' => 422, 'message' => '单次最终候选包最多 200 个 AI 推荐词。'];
        }

        $items = $selectedItemIds
            ? AiStudyCardPendingItem::where('user_id', $user->id)
                ->where('language_id', $user->selected_language)
                ->where('status', AiStudyCardPendingItem::STATUS_PENDING)
                ->whereIn('id', $selectedItemIds)
                ->get()
            : collect();

        $userSelectedKeys = [];
        foreach ($items as $item) {
            $key = $this->dedupeKey($item->lemma, $item->word);
            if ($key !== '') {
                $userSelectedKeys[$key] = true;
            }
        }

        $seenAiKeys = [];
        $cleanSelectedAi = [];
        $droppedDuplicateWithUser = 0;
        $droppedAiInternalDuplicate = 0;
        foreach ($selectedAi as $recommendation) {
            $normalized = $this->normalizeRecommendation($recommendation);
            if (!$normalized) {
                continue;
            }
            $key = $this->dedupeKey($normalized['lemma'], $normalized['word']);
            if (isset($userSelectedKeys[$key])) {
                $droppedDuplicateWithUser++;
                continue;
            }
            if (isset($seenAiKeys[$key])) {
                $droppedAiInternalDuplicate++;
                continue;
            }
            $seenAiKeys[$key] = true;
            $cleanSelectedAi[] = $normalized;
        }

        $cleanUnselectedAi = [];
        foreach ($unselectedAi as $recommendation) {
            $normalized = $this->normalizeRecommendation($recommendation);
            if (!$normalized) {
                continue;
            }
            $key = $this->dedupeKey($normalized['lemma'], $normalized['word']);
            if (isset($userSelectedKeys[$key]) || isset($seenAiKeys[$key])) {
                continue;
            }
            $seenAiKeys[$key] = true;
            $cleanUnselectedAi[] = $normalized;
        }

        $userSelectedItems = $items->map(fn ($item) => [
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
        ])->values()->toArray();

        if (!$userSelectedItems && !$cleanSelectedAi) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '没有有效的用户已选词或 AI 推荐词可打包（可能已被取消、不属于当前用户或语言不匹配）。',
            ];
        }

        return [
            'success' => true,
            'package' => [
                'schema_version' => 'ai-study-card-final-candidates-v1',
                'source_preview_package_schema_version' => $sourcePreviewPackage['schema_version'] ?? null,
                'created_at' => now()->toIso8601String(),
                'user_selected_items' => $userSelectedItems,
                'ai_recommended_selected_items' => $cleanSelectedAi,
                'ai_recommended_unselected_items' => $cleanUnselectedAi,
                'dedupe_summary' => [
                    'original_ai_count' => $dedupeSummary['original_ai_count'] ?? count($selectedAi) + count($unselectedAi),
                    'valid_ai_count' => count($cleanSelectedAi) + count($cleanUnselectedAi),
                    'dropped_missing_word' => $dedupeSummary['dropped_missing_word'] ?? 0,
                    'dropped_duplicate_with_user' => ($dedupeSummary['dropped_duplicate_with_user'] ?? 0) + $droppedDuplicateWithUser,
                    'dropped_ai_internal_duplicate' => ($dedupeSummary['dropped_ai_internal_duplicate'] ?? 0) + $droppedAiInternalDuplicate,
                    'backend_deduplication_applied' => $droppedDuplicateWithUser + $droppedAiInternalDuplicate > 0,
                ],
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
            ],
            'message' => '已生成最终候选包（未调用 AI，未生成复习卡）。',
        ];
    }

    private function dedupeKey(?string $lemma, ?string $word): string
    {
        return mb_strtolower(trim((string) $lemma) ?: trim((string) $word), 'UTF-8');
    }

    private function normalizeRecommendation($recommendation): ?array
    {
        if (!is_array($recommendation)) {
            return null;
        }
        $word = trim((string) ($recommendation['word'] ?? ''));
        if ($word === '') {
            return null;
        }
        $lemma = trim((string) ($recommendation['lemma'] ?? '')) ?: $word;

        return [
            'word' => $word,
            'lemma' => $lemma,
            'surface' => trim((string) ($recommendation['surface'] ?? '')) ?: $word,
            'reason' => trim((string) ($recommendation['reason'] ?? '')) ?: '无说明',
            'sentence_text' => trim((string) ($recommendation['sentence_text'] ?? '')) ?: null,
            'confidence' => array_key_exists('confidence', $recommendation) ? $recommendation['confidence'] : null,
            'source' => 'ai_recommended',
        ];
    }
}
