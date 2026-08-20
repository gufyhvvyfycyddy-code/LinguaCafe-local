<?php

namespace App\Services;

use App\Models\ReviewLog;

class SenseReviewExampleRotationStateService
{
    /**
     * @return array{formal_question_ordinal: int, latest_question_example_key: string|null}
     */
    public function stateForCard(int $reviewCardId): array
    {
        return $this->stateForCards([$reviewCardId])[$reviewCardId] ?? $this->emptyState();
    }

    /**
     * @param  list<int>  $reviewCardIds
     * @return array<int, array{formal_question_ordinal: int, latest_question_example_key: string|null}>
     */
    public function stateForCards(array $reviewCardIds): array
    {
        $reviewCardIds = array_values(array_unique(array_map('intval', $reviewCardIds)));
        if ($reviewCardIds === []) {
            return [];
        }

        $states = [];
        foreach ($reviewCardIds as $reviewCardId) {
            $states[$reviewCardId] = $this->emptyState();
        }

        $logs = ReviewLog::query()
            ->whereIn('review_card_id', $reviewCardIds)
            ->notUndone()
            ->whereNotNull('question_example_key')
            ->where('question_example_key', '<>', '')
            ->orderBy('id')
            ->get(['review_card_id', 'question_example_key']);

        foreach ($logs as $log) {
            $reviewCardId = (int) $log->review_card_id;
            $states[$reviewCardId]['formal_question_ordinal']++;
            $states[$reviewCardId]['latest_question_example_key'] = (string) $log->question_example_key;
        }

        return $states;
    }

    /**
     * @return array{formal_question_ordinal: int, latest_question_example_key: string|null}
     */
    private function emptyState(): array
    {
        return [
            'formal_question_ordinal' => 0,
            'latest_question_example_key' => null,
        ];
    }
}
