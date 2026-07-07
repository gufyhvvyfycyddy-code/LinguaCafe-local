<?php

namespace App\Services;

use App\Models\AiStudyCardPendingItem;
use App\Models\User;

class AiStudyCardV6RequestPackageService
{
    private const MAX_ITEMS_PER_PACKAGE = 50;

    /**
     * Build the V6 provider-disabled request package.
     *
     * This method intentionally does not call any AI provider and does not write
     * WordSense, ReviewCard, ReviewLog, FSRS, or pending-item state. It only
     * packages the current user's selected pending items for a future provider
     * implementation round.
     */
    public function buildRequestPackage(User $user, array $itemIds, ?string $contextPolicy = null): array
    {
        if (empty($itemIds)) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '请至少选择一个待解释项。',
            ];
        }

        $itemIds = collect($itemIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($itemIds)) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '请至少选择一个有效的待解释项。',
            ];
        }

        if (count($itemIds) > self::MAX_ITEMS_PER_PACKAGE) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '单次 V6 请求包最多 50 项。',
            ];
        }

        $language = $user->selected_language;
        $normalizedContextPolicy = $this->normalizeContextPolicy($contextPolicy);

        $items = AiStudyCardPendingItem::where('user_id', $user->id)
            ->where('language_id', $language)
            ->where('status', AiStudyCardPendingItem::STATUS_PENDING)
            ->whereIn('id', $itemIds)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if ($items->isEmpty()) {
            return [
                'success' => false,
                'status' => 404,
                'message' => '没有可打包的 V6 待解释项（可能已被取消、已处理或不属于当前用户/语言）。',
            ];
        }

        $selectedItems = $items->map(function (AiStudyCardPendingItem $item) {
            return [
                'item_id' => $item->id,
                'chapter_id' => $item->chapter_id,
                'text_block_index' => $item->text_block_index,
                'sentence_index' => $item->sentence_index,
                'sentence_id' => $item->sentence_id,
                'word' => $item->word,
                'normalized_word' => $item->normalized_word,
                'surface' => $item->surface,
                'lemma' => $item->lemma,
                'sentence_text' => $item->sentence_text,
                'status' => $item->status,
                'source' => 'user_selected_pending_item',
            ];
        })->values()->toArray();

        $package = [
            'schema_version' => 'ai-study-card-v6-request-package-v1',
            'created_at' => now()->toIso8601String(),
            'language' => $language,
            'provider_request_state' => 'provider_disabled',
            'context_policy' => [
                'mode' => $normalizedContextPolicy,
                'include_sentence_text' => true,
                'include_full_chapter_text' => false,
                'max_items' => self::MAX_ITEMS_PER_PACKAGE,
                'raw_source_payload_excluded' => true,
            ],
            'selected_pending_item_ids' => $items->pluck('id')->values()->all(),
            'selected_items' => $selectedItems,
            'provider_instructions' => [
                'ai_generated_suggestions_only' => true,
                'return_schema_version' => 'ai-study-card-v6-recommendation-package-v1',
                'do_not_create_cards' => true,
                'do_not_rate_reviews' => true,
                'user_confirmation_required' => true,
            ],
            'generation_rules' => [
                'provider_disabled_in_this_round' => true,
                'explicit_user_action_required_before_future_provider_call' => true,
                'ai_recommendations_default_unchecked' => true,
                'ai_reason_not_final_sense_zh' => true,
                'user_confirmation_required_before_card_generation' => true,
                'final_card_creation_must_use_v5_generate_cards' => true,
            ],
            'safety_flags' => [
                'user_triggered_request' => true,
                'provider_disabled' => true,
                'no_provider_called' => true,
                'no_card_creation' => true,
                'no_review_log_created' => true,
                'no_fsrs_changed' => true,
                'no_word_sense_created' => true,
                'no_review_card_created' => true,
                'no_legacy_word_card_created' => true,
                'user_confirmation_required' => true,
            ],
        ];

        return [
            'success' => true,
            'package' => $package,
            'message' => '已生成 V6 请求包（provider disabled，未调用 AI，未生成学习卡）。',
        ];
    }

    private function normalizeContextPolicy(?string $contextPolicy): string
    {
        $contextPolicy = trim((string) $contextPolicy);

        return match ($contextPolicy) {
            'selected_items_with_sentence' => 'selected_items_with_sentence',
            default => 'selected_items_only',
        };
    }
}
