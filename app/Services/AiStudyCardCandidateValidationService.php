<?php

namespace App\Services;

use App\Models\AiStudyCardPendingItem;
use App\Models\Chapter;
use App\Models\User;

class AiStudyCardCandidateValidationService
{
    public function prepare(User $user, array $confirmedItems, array $finalCandidatesPackage): array
    {
        if (count($confirmedItems) > 50) {
            return [
                'success' => false,
                'status' => 422,
                'message' => '单次最多生成 50 张学习卡，请分批确认。',
            ];
        }

        $packageSelectedItemIds = [];
        $packageAiRecommendedKeys = [];
        $packageUserSelectedKeys = [];

        if (isset($finalCandidatesPackage['user_selected_items'])
            && is_array($finalCandidatesPackage['user_selected_items'])) {
            foreach ($finalCandidatesPackage['user_selected_items'] as $packageItem) {
                if (isset($packageItem['item_id'])) {
                    $itemId = (int) $packageItem['item_id'];
                    $packageSelectedItemIds[] = $itemId;
                    $key = $this->dedupeKey($packageItem['lemma'] ?? null, $packageItem['word'] ?? null);
                    if ($key !== '') {
                        $packageUserSelectedKeys[$itemId] = $key;
                    }
                }
            }
        }

        if (isset($finalCandidatesPackage['ai_recommended_selected_items'])
            && is_array($finalCandidatesPackage['ai_recommended_selected_items'])) {
            foreach ($finalCandidatesPackage['ai_recommended_selected_items'] as $packageItem) {
                $key = $this->dedupeKey($packageItem['lemma'] ?? null, $packageItem['word'] ?? null);
                if ($key !== '') {
                    $packageAiRecommendedKeys[$key] = true;
                }
            }
        }

        $language = $user->selected_language;
        $validPendingItems = !empty($packageSelectedItemIds)
            ? AiStudyCardPendingItem::where('user_id', $user->id)
                ->where('language_id', $language)
                ->where('status', AiStudyCardPendingItem::STATUS_PENDING)
                ->whereIn('id', $packageSelectedItemIds)
                ->get()
                ->keyBy('id')
            : collect();

        $chapterIds = [];
        foreach ($confirmedItems as $confirmedItem) {
            if (is_array($confirmedItem) && !empty($confirmedItem['chapter_id'])) {
                $chapterIds[] = (int) $confirmedItem['chapter_id'];
            }
        }
        $chapterIds = array_unique($chapterIds);

        $validChapters = !empty($chapterIds)
            ? Chapter::where('user_id', $user->id)
                ->where('language', $language)
                ->whereIn('id', $chapterIds)
                ->get()
                ->keyBy('id')
            : collect();

        return [
            'success' => true,
            'context' => [
                'package_selected_item_ids' => $packageSelectedItemIds,
                'package_ai_recommended_keys' => $packageAiRecommendedKeys,
                'package_user_selected_keys' => $packageUserSelectedKeys,
                'valid_pending_items' => $validPendingItems,
                'valid_chapters' => $validChapters,
            ],
        ];
    }

    public function validate(array $confirmedItem, array $context): array
    {
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

        if ($word === '') {
            return $this->skipped($source, '', 'empty_word', null, null);
        }
        if ($senseZh === '') {
            return $this->skipped($source, $word, 'empty_sense_zh', $lemma, $itemId);
        }
        if (!in_array($source, ['user_selected', 'ai_recommended'], true)) {
            return $this->skipped($source, $word, 'invalid_source', $lemma, $itemId);
        }

        if ($source === 'user_selected') {
            if (!$itemId || !in_array($itemId, $context['package_selected_item_ids'], true)) {
                return $this->skipped(
                    $source,
                    $word,
                    'not_in_final_package_user_selected',
                    $lemma,
                    $itemId,
                );
            }
            if (!$context['valid_pending_items']->has($itemId)) {
                return $this->skipped($source, $word, 'invalid_pending_item', $lemma, $itemId);
            }

            $expectedKey = $context['package_user_selected_keys'][$itemId] ?? '';
            $actualKey = $this->dedupeKey($lemma, $word);
            if ($expectedKey !== '' && $actualKey !== '' && $expectedKey !== $actualKey) {
                return $this->skipped(
                    $source,
                    $word,
                    'word_lemma_mismatch_with_final_package',
                    $lemma,
                    $itemId,
                );
            }
        } else {
            $actualKey = $this->dedupeKey($lemma, $word);
            if ($actualKey === '' || !isset($context['package_ai_recommended_keys'][$actualKey])) {
                return $this->skipped(
                    $source,
                    $word,
                    'not_in_final_package_ai_recommended',
                    $lemma,
                    $itemId,
                );
            }
        }

        if ($chapterId !== null && !$context['valid_chapters']->has($chapterId)) {
            return $this->skipped($source, $word, 'invalid_chapter', $lemma, $itemId);
        }

        return [
            'success' => true,
            'candidate' => array_merge($confirmedItem, [
                'source' => $source,
                'word' => $word,
                'sense_zh' => $senseZh,
                'lemma' => $lemma,
                'surface' => $surface,
                'chapter_id' => $chapterId,
                'item_id' => $itemId,
                'sentence_id' => $sentenceId,
                'sentence_text' => $sentenceText,
                'text_block_index' => $textBlockIndex,
                'sentence_index' => $sentenceIndex,
                'sense_en' => $this->normalizeNullableString($confirmedItem['sense_en'] ?? null),
            ]),
        ];
    }

    private function dedupeKey(?string $lemma, ?string $word): string
    {
        $candidate = trim((string) $lemma);
        if ($candidate === '') {
            $candidate = trim((string) $word);
        }

        return $candidate === '' ? '' : mb_strtolower($candidate, 'UTF-8');
    }

    private function normalizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function skipped(
        string $source,
        string $word,
        string $reason,
        ?string $lemma,
        ?int $itemId
    ): array {
        return [
            'success' => false,
            'skipped' => [
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
            ],
        ];
    }
}
