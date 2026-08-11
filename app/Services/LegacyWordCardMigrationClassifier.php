<?php

namespace App\Services;

use App\Models\ReviewCard;
use App\Models\WordSense;
use Illuminate\Support\Facades\DB;

class LegacyWordCardMigrationClassifier
{
    private const DEPENDENCY_TABLES = [
        'review_logs',
        'review_card_state_events',
        'reschedule_snapshot_items',
        'operations',
        'reading_session_interactions',
        'reading_session_card_settlements',
        'word_sense_occurrences',
    ];

    public function classify(?int $userId = null, ?string $language = null): array
    {
        $query = DB::table('review_cards')
            ->where('target_type', ReviewCard::TARGET_WORD)
            ->orderBy('user_id')
            ->orderBy('language_id')
            ->orderBy('id');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        if ($language !== null) {
            $query->where('language_id', $language);
        }

        $cards = $query->get()->all();
        $cardIds = $this->integerValues($cards, 'id');
        $targetIds = $this->integerValues($cards, 'target_id');

        $targetsById = $this->keyBy($this->rowsWhereIn('encountered_words', 'id', $targetIds), 'id');
        $occurrences = $this->rowsWhereIn('word_sense_occurrences', 'review_card_id', $cardIds);
        $occurrencesByCard = $this->groupBy($occurrences, 'review_card_id');

        $encounteredSenses = $this->rowsWhereIn('word_senses', 'encountered_word_id', $targetIds);
        $encounteredSensesByTarget = $this->groupBy($encounteredSenses, 'encountered_word_id');
        $occurrenceSenseIds = $this->integerValues($occurrences, 'word_sense_id');
        $occurrenceSenses = $this->rowsWhereIn('word_senses', 'id', $occurrenceSenseIds);
        $sensesById = $this->keyBy(array_merge($encounteredSenses, $occurrenceSenses), 'id');
        $senseCardsBySense = $this->groupBy(
            $this->rowsWhereIn('review_cards', 'target_id', array_keys($sensesById), [
                'target_type' => ReviewCard::TARGET_SENSE,
            ]),
            'target_id',
        );

        $reviewLogs = $this->rowsWhereIn('review_logs', 'review_card_id', $cardIds);
        $reviewLogsByCard = $this->groupBy($reviewLogs, 'review_card_id');
        $reviewLogToCard = [];
        foreach ($reviewLogs as $reviewLog) {
            $reviewLogToCard[(int) $reviewLog->id] = (int) $reviewLog->review_card_id;
        }

        $dependenciesByTable = [
            'review_logs' => $reviewLogsByCard,
            'review_card_state_events' => $this->groupBy(
                $this->rowsWhereIn('review_card_state_events', 'review_card_id', $cardIds),
                'review_card_id',
            ),
            'reschedule_snapshot_items' => $this->groupBy(
                $this->rowsWhereIn('reschedule_snapshot_items', 'review_card_id', $cardIds),
                'review_card_id',
            ),
            'reading_session_interactions' => $this->groupBy(
                $this->rowsWhereIn('reading_session_interactions', 'review_card_id', $cardIds),
                'review_card_id',
            ),
            'reading_session_card_settlements' => $this->groupBy(
                $this->rowsWhereIn('reading_session_card_settlements', 'review_card_id', $cardIds),
                'review_card_id',
            ),
            'word_sense_occurrences' => $occurrencesByCard,
        ];

        $operationRows = $this->deduplicateRows(array_merge(
            $this->rowsWhereIn('operations', 'review_card_id', $cardIds),
            $this->rowsWhereIn('operations', 'review_log_id', array_keys($reviewLogToCard)),
        ));
        $dependenciesByTable['operations'] = $this->operationsByCard($operationRows, $reviewLogToCard, $cardIds);

        $classifiedCards = [];
        foreach ($cards as $card) {
            $classifiedCards[] = $this->classifyCard(
                $card,
                $targetsById[(int) $card->target_id] ?? null,
                $occurrencesByCard[(int) $card->id] ?? [],
                $encounteredSensesByTarget[(int) $card->target_id] ?? [],
                $sensesById,
                $senseCardsBySense,
                $dependenciesByTable,
            );
        }

        $counts = [
            'total' => count($classifiedCards),
            'unique_mapping' => 0,
            'needs_user_confirmation' => 0,
            'unmapped_read_only' => 0,
        ];
        foreach ($classifiedCards as $classifiedCard) {
            $counts[$classifiedCard['classification']]++;
        }

        return [
            'schema_version' => 'legacy_word_card_classification_v1',
            'filters' => [
                'user_id' => $userId,
                'language' => $language,
            ],
            'counts' => $counts,
            'cards' => $classifiedCards,
        ];
    }

    private function classifyCard(
        object $card,
        ?object $target,
        array $occurrences,
        array $encounteredSenses,
        array $sensesById,
        array $senseCardsBySense,
        array $dependenciesByTable,
    ): array {
        $cardId = (int) $card->id;
        $cardUserId = (int) $card->user_id;
        $cardLanguage = (string) $card->language_id;
        $targetInScope = $target !== null
            && (int) $target->user_id === $cardUserId
            && (string) $target->language === $cardLanguage;

        $candidateSources = [];
        foreach ($encounteredSenses as $sense) {
            $candidateSources[(int) $sense->id]['encountered_word'] = true;
        }
        foreach ($occurrences as $occurrence) {
            $senseId = $occurrence->word_sense_id !== null ? (int) $occurrence->word_sense_id : null;
            if ($senseId !== null && isset($sensesById[$senseId])) {
                $candidateSources[$senseId]['occurrence'] = true;
            }
        }
        ksort($candidateSources, SORT_NUMERIC);

        $confirmedCandidateIds = [];
        $nonRejectedCandidateIds = [];
        $encounteredConfirmedCandidateIds = [];
        foreach (array_keys($candidateSources) as $senseId) {
            $sense = $sensesById[$senseId];
            $inScope = $this->senseInScope($sense, $cardUserId, $cardLanguage);
            if ($inScope && $sense->status === WordSense::STATUS_CONFIRMED) {
                $confirmedCandidateIds[] = (int) $senseId;
                if (isset($candidateSources[$senseId]['encountered_word'])) {
                    $encounteredConfirmedCandidateIds[] = (int) $senseId;
                }
            }
            if ($inScope && $sense->status !== WordSense::STATUS_REJECTED) {
                $nonRejectedCandidateIds[] = (int) $senseId;
            }
        }

        $invalidOccurrence = false;
        $unresolvedOccurrence = false;
        $resolvedOccurrenceCandidateIds = [];
        foreach ($occurrences as $occurrence) {
            $rowInScope = (int) $occurrence->user_id === $cardUserId
                && (string) $occurrence->language_id === $cardLanguage;
            if (! $rowInScope) {
                $invalidOccurrence = true;
                if ($occurrence->word_sense_id === null) {
                    $unresolvedOccurrence = true;
                }
                continue;
            }
            if ($occurrence->word_sense_id === null) {
                $unresolvedOccurrence = true;
                continue;
            }

            $sense = $sensesById[(int) $occurrence->word_sense_id] ?? null;
            if ($sense === null || ! $this->senseInScope($sense, $cardUserId, $cardLanguage)) {
                $invalidOccurrence = true;
                continue;
            }

            $resolvedOccurrenceCandidateIds[] = (int) $sense->id;
        }

        $evidence = [
            'target_exists' => $target !== null,
            'target_in_scope' => $targetInScope,
            'invalid_occurrence' => $invalidOccurrence,
            'candidate_count' => count($candidateSources),
            'confirmed_candidate_ids' => $this->sortedUniqueIntegers($confirmedCandidateIds),
            'unresolved_occurrence' => $unresolvedOccurrence,
            'non_rejected_candidate_ids' => $this->sortedUniqueIntegers($nonRejectedCandidateIds),
            'resolved_occurrence_candidate_ids' => $this->sortedUniqueIntegers($resolvedOccurrenceCandidateIds),
            'encountered_confirmed_candidate_ids' => $this->sortedUniqueIntegers($encounteredConfirmedCandidateIds),
        ];
        $classification = $this->classifyEvidence($evidence);

        $baselineCandidateId = count($evidence['encountered_confirmed_candidate_ids']) === 1
            ? $evidence['encountered_confirmed_candidate_ids'][0]
            : null;
        $candidateEntries = [];
        foreach ($candidateSources as $senseId => $sources) {
            $sense = $sensesById[$senseId];
            $reasonCodes = [];
            if ((int) $sense->user_id !== $cardUserId) {
                $reasonCodes[] = 'candidate_user_mismatch';
            }
            if ((string) $sense->language_id !== $cardLanguage) {
                $reasonCodes[] = 'candidate_language_mismatch';
            }
            if ($sense->status === WordSense::STATUS_AI_SUGGESTED) {
                $reasonCodes[] = 'candidate_status_ai_suggested';
            }
            if ($sense->status === WordSense::STATUS_REJECTED) {
                $reasonCodes[] = 'candidate_status_rejected';
            }
            if ($baselineCandidateId !== null
                && (int) $senseId !== $baselineCandidateId
                && in_array((int) $senseId, $evidence['resolved_occurrence_candidate_ids'], true)) {
                $reasonCodes[] = 'candidate_competes_with_selected';
            }

            $senseCardIds = array_map(
                fn (object $senseCard): int => (int) $senseCard->id,
                $senseCardsBySense[(int) $senseId] ?? [],
            );
            $candidateEntries[] = [
                'word_sense' => $this->canonicalRow($sense),
                'evidence_sources' => $this->sortedStrings(array_keys($sources)),
                'sense_review_card_ids' => $this->sortedUniqueIntegers($senseCardIds),
                'in_scope' => $this->senseInScope($sense, $cardUserId, $cardLanguage),
                'confirmed' => $sense->status === WordSense::STATUS_CONFIRMED,
                'reason_codes' => $this->sortedStrings($reasonCodes),
            ];
        }

        $occurrenceEntries = [];
        foreach ($occurrences as $occurrence) {
            $sense = $occurrence->word_sense_id !== null
                ? ($sensesById[(int) $occurrence->word_sense_id] ?? null)
                : null;
            $rowInScope = (int) $occurrence->user_id === $cardUserId
                && (string) $occurrence->language_id === $cardLanguage;
            $reasonCodes = [];
            if ((int) $occurrence->user_id !== $cardUserId) {
                $reasonCodes[] = 'occurrence_user_mismatch';
            }
            if ((string) $occurrence->language_id !== $cardLanguage) {
                $reasonCodes[] = 'occurrence_language_mismatch';
            }
            if ($occurrence->word_sense_id === null) {
                $reasonCodes[] = 'occurrence_unresolved';
            } elseif ($sense === null) {
                $reasonCodes[] = 'occurrence_word_sense_missing';
            }
            if ($baselineCandidateId !== null
                && $sense !== null
                && $rowInScope
                && $this->senseInScope($sense, $cardUserId, $cardLanguage)
                && (int) $sense->id !== $baselineCandidateId) {
                $reasonCodes[] = 'occurrence_conflicts_with_selected';
            }

            $occurrenceEntries[] = [
                'occurrence' => $this->canonicalRow($occurrence),
                'word_sense' => $sense !== null ? $this->canonicalRow($sense) : null,
                'in_scope' => $rowInScope,
                'resolved' => $sense !== null
                    && $rowInScope
                    && $this->senseInScope($sense, $cardUserId, $cardLanguage),
                'reason_codes' => $this->sortedStrings($reasonCodes),
            ];
        }

        $targetReasonCodes = [];
        if ($target !== null && (int) $target->stage >= 0) {
            $targetReasonCodes[] = 'target_stage_not_learning';
        }
        if (! (bool) $card->fsrs_enabled) {
            $targetReasonCodes[] = 'card_disabled';
        }
        $targetReasonCodes = $this->sortedStrings($targetReasonCodes);

        $secondaryReasonCodes = $targetReasonCodes;
        foreach (array_merge($candidateEntries, $occurrenceEntries) as $entry) {
            $secondaryReasonCodes = array_merge($secondaryReasonCodes, $entry['reason_codes']);
        }
        $secondaryReasonCodes = $this->sortedStrings($secondaryReasonCodes);

        return [
            'review_card' => $this->canonicalRow($card),
            'encountered_word' => $target !== null ? $this->canonicalRow($target) : null,
            'target' => [
                'exists' => $target !== null,
                'in_scope' => $targetInScope,
                'stage_is_learning' => $target !== null && (int) $target->stage < 0,
                'card_enabled' => (bool) $card->fsrs_enabled,
                'reason_codes' => $targetReasonCodes,
            ],
            'candidates' => $candidateEntries,
            'occurrences' => $occurrenceEntries,
            'dependencies' => $this->dependenciesForCard($cardId, $dependenciesByTable),
            'classification' => $classification['classification'],
            'selected_word_sense_id' => $classification['selected_word_sense_id'],
            'primary_reason_code' => $classification['primary_reason_code'],
            'reason_codes' => array_merge([$classification['primary_reason_code']], $secondaryReasonCodes),
        ];
    }

    private function classifyEvidence(array $evidence): array
    {
        if (! $evidence['target_exists']) {
            return $this->classification('unmapped_read_only', 'target_missing');
        }
        if (! $evidence['target_in_scope']) {
            return $this->classification('unmapped_read_only', 'target_scope_mismatch');
        }
        if ($evidence['invalid_occurrence']) {
            return $this->classification('unmapped_read_only', 'occurrence_binding_invalid_scope_or_target');
        }
        if ($evidence['candidate_count'] === 0) {
            return $this->classification('unmapped_read_only', 'no_direct_candidate');
        }
        if ($evidence['confirmed_candidate_ids'] === []) {
            return $this->classification('unmapped_read_only', 'no_confirmed_direct_candidate');
        }
        if ($evidence['unresolved_occurrence']) {
            return $this->classification('needs_user_confirmation', 'unresolved_occurrence_binding');
        }
        if (count($evidence['non_rejected_candidate_ids']) > 1) {
            return $this->classification('needs_user_confirmation', 'competing_direct_candidates');
        }

        $resolvedIds = $evidence['resolved_occurrence_candidate_ids'];
        $encounteredConfirmedIds = $evidence['encountered_confirmed_candidate_ids'];
        $conflictingWithEncountered = count($encounteredConfirmedIds) === 1
            && array_diff($resolvedIds, $encounteredConfirmedIds) !== [];
        if (count($resolvedIds) > 1 || $conflictingWithEncountered) {
            return $this->classification('needs_user_confirmation', 'conflicting_direct_bindings');
        }

        if (count($evidence['confirmed_candidate_ids']) === 1
            && count($evidence['non_rejected_candidate_ids']) === 1
            && ($resolvedIds === [] || $resolvedIds === $evidence['confirmed_candidate_ids'])) {
            return $this->classification(
                'unique_mapping',
                'unique_confirmed_direct_candidate',
                $evidence['confirmed_candidate_ids'][0],
            );
        }

        throw new \LogicException('Legacy word-card classification predicates are not exhaustive.');
    }

    private function classification(string $classification, string $reasonCode, ?int $selectedId = null): array
    {
        return [
            'classification' => $classification,
            'selected_word_sense_id' => $selectedId,
            'primary_reason_code' => $reasonCode,
        ];
    }

    private function dependenciesForCard(int $cardId, array $dependenciesByTable): array
    {
        $dependencies = [];
        foreach (self::DEPENDENCY_TABLES as $table) {
            $rows = $dependenciesByTable[$table][$cardId] ?? [];
            usort($rows, fn (object $left, object $right): int => (int) $left->id <=> (int) $right->id);
            $dependencies[$table] = [
                'count' => count($rows),
                'ids' => array_map(fn (object $row): int => (int) $row->id, $rows),
                'rows' => array_map(fn (object $row): array => $this->canonicalRow($row), $rows),
            ];
        }

        return $dependencies;
    }

    private function operationsByCard(array $operations, array $reviewLogToCard, array $cardIds): array
    {
        $knownCards = array_fill_keys($cardIds, true);
        $byCard = [];
        foreach ($operations as $operation) {
            $relatedCardIds = [];
            if ($operation->review_card_id !== null && isset($knownCards[(int) $operation->review_card_id])) {
                $relatedCardIds[] = (int) $operation->review_card_id;
            }
            if ($operation->review_log_id !== null && isset($reviewLogToCard[(int) $operation->review_log_id])) {
                $relatedCardIds[] = $reviewLogToCard[(int) $operation->review_log_id];
            }
            foreach (array_unique($relatedCardIds) as $cardId) {
                $byCard[$cardId][(int) $operation->id] = $operation;
            }
        }
        foreach ($byCard as $cardId => $rows) {
            ksort($rows, SORT_NUMERIC);
            $byCard[$cardId] = array_values($rows);
        }

        return $byCard;
    }

    private function rowsWhereIn(string $table, string $column, array $values, array $equals = []): array
    {
        $values = array_values(array_unique(array_filter($values, fn ($value) => $value !== null)));
        if ($values === []) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($values, 500) as $chunk) {
            $query = DB::table($table)->whereIn($column, $chunk);
            foreach ($equals as $equalsColumn => $equalsValue) {
                $query->where($equalsColumn, $equalsValue);
            }
            foreach ($query->orderBy('id')->get() as $row) {
                $rows[] = $row;
            }
        }

        return $this->deduplicateRows($rows);
    }

    private function deduplicateRows(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }
        ksort($byId, SORT_NUMERIC);

        return array_values($byId);
    }

    private function keyBy(array $rows, string $column): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row->{$column}] = $row;
        }
        ksort($keyed, SORT_NUMERIC);

        return $keyed;
    }

    private function groupBy(array $rows, string $column): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            if ($row->{$column} !== null) {
                $grouped[(int) $row->{$column}][] = $row;
            }
        }

        return $grouped;
    }

    private function integerValues(array $rows, string $column): array
    {
        $values = [];
        foreach ($rows as $row) {
            if ($row->{$column} !== null) {
                $values[] = (int) $row->{$column};
            }
        }

        return $this->sortedUniqueIntegers($values);
    }

    private function sortedUniqueIntegers(array $values): array
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values, SORT_NUMERIC);

        return $values;
    }

    private function sortedStrings(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    private function senseInScope(object $sense, int $userId, string $language): bool
    {
        return (int) $sense->user_id === $userId
            && (string) $sense->language_id === $language;
    }

    private function canonicalRow(object $row): array
    {
        $values = (array) $row;
        ksort($values, SORT_STRING);

        return $values;
    }
}
