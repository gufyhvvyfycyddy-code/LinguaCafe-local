<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\LegacyWordCardMigrationClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyWordCardMigrationClassifierTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('legacy-classifier@example.com');
        $this->otherUser = $this->createUser('legacy-classifier-other@example.com');
    }

    public function test_classifier_applies_precedence_and_preserves_all_direct_evidence(): void
    {
        $missing = $this->createCard($this->user->id, 'english', 900001);

        $foreignTarget = $this->createWord($this->otherUser->id, 'english', -1, 'foreign-target');
        $scopeMismatch = $this->createCard($this->user->id, 'english', $foreignTarget->id);

        $invalidWord = $this->createWord($this->user->id, 'english', -1, 'invalid-occurrence');
        $invalidCard = $this->createCard($this->user->id, 'english', $invalidWord->id);
        $invalidSelected = $this->createSense($this->user->id, 'english', $invalidWord->id, WordSense::STATUS_CONFIRMED, 'invalid-occurrence');
        $foreignSense = $this->createSense($this->otherUser->id, 'english', null, WordSense::STATUS_CONFIRMED, 'foreign-sense');
        $this->createOccurrence($invalidCard, $foreignSense, $this->user->id, 'english');
        $this->createOccurrence($invalidCard, null, $this->otherUser->id, 'english', 999991);
        $this->createOccurrence($invalidCard, null, $this->user->id, 'spanish', 999992);

        $noCandidateWord = $this->createWord($this->user->id, 'english', -1, 'same-lemma');
        $noCandidate = $this->createCard($this->user->id, 'english', $noCandidateWord->id);
        $this->createSense($this->user->id, 'english', null, WordSense::STATUS_CONFIRMED, 'same-lemma');

        $aiWord = $this->createWord($this->user->id, 'english', -1, 'ai-only');
        $aiOnly = $this->createCard($this->user->id, 'english', $aiWord->id);
        $this->createSense($this->user->id, 'english', $aiWord->id, WordSense::STATUS_AI_SUGGESTED, 'ai-only');

        $unresolvedWord = $this->createWord($this->user->id, 'english', -1, 'unresolved');
        $unresolved = $this->createCard($this->user->id, 'english', $unresolvedWord->id);
        $this->createSense($this->user->id, 'english', $unresolvedWord->id, WordSense::STATUS_CONFIRMED, 'unresolved');
        $this->createOccurrence($unresolved, null, $this->user->id, 'english');

        $competingWord = $this->createWord($this->user->id, 'english', -1, 'competing');
        $competing = $this->createCard($this->user->id, 'english', $competingWord->id);
        $this->createSense($this->user->id, 'english', $competingWord->id, WordSense::STATUS_CONFIRMED, 'competing-confirmed');
        $this->createSense($this->user->id, 'english', $competingWord->id, WordSense::STATUS_AI_SUGGESTED, 'competing-ai');

        $conflictWord = $this->createWord($this->user->id, 'english', -1, 'conflict');
        $conflict = $this->createCard($this->user->id, 'english', $conflictWord->id);
        $conflictSelected = $this->createSense($this->user->id, 'english', $conflictWord->id, WordSense::STATUS_CONFIRMED, 'conflict-confirmed');
        $conflictRejected = $this->createSense($this->user->id, 'english', null, WordSense::STATUS_REJECTED, 'conflict-rejected');
        $this->createOccurrence($conflict, $conflictRejected, $this->user->id, 'english');

        $uniqueWord = $this->createWord($this->user->id, 'english', 0, 'unique');
        $unique = $this->createCard($this->user->id, 'english', $uniqueWord->id, false);
        $uniqueSelected = $this->createSense($this->user->id, 'english', $uniqueWord->id, WordSense::STATUS_CONFIRMED, 'unique');
        $this->createSense($this->otherUser->id, 'english', $uniqueWord->id, WordSense::STATUS_CONFIRMED, 'other-user');
        $this->createSense($this->user->id, 'spanish', $uniqueWord->id, WordSense::STATUS_CONFIRMED, 'other-language');
        $this->createSense($this->user->id, 'english', $uniqueWord->id, WordSense::STATUS_REJECTED, 'rejected');
        $uniqueOccurrence = $this->createOccurrence($unique, $uniqueSelected, $this->user->id, 'english');

        $log = ReviewLog::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $unique->id,
            'rating' => 'good',
            'reviewed_at' => '2026-08-12 01:00:00',
            'previous_state' => 'new',
            'new_state' => 'learning',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
            'review_session_id' => (string) Str::uuid(),
            'before_card_snapshot' => ['state' => 'before'],
            'after_card_snapshot' => ['state' => 'after'],
            'undone_at' => '2026-08-12 01:01:00',
            'undo_request_id' => (string) Str::uuid(),
            'undo_source' => 'test',
        ]);
        $this->createDependencies($unique, $uniqueSelected, $log);

        $before = $this->fingerprint();
        $result = app(LegacyWordCardMigrationClassifier::class)->classify();
        $after = $this->fingerprint();

        $this->assertSame($before, $after, 'Classifier must not write to any queried table.');
        $this->assertSame([
            'total' => 9,
            'unique_mapping' => 1,
            'needs_user_confirmation' => 3,
            'unmapped_read_only' => 5,
        ], $result['counts']);

        $rows = collect($result['cards'])->keyBy(fn (array $row) => $row['review_card']['id']);
        $this->assertSame('target_missing', $rows[$missing->id]['primary_reason_code']);
        $this->assertSame('target_scope_mismatch', $rows[$scopeMismatch->id]['primary_reason_code']);
        $this->assertSame('occurrence_binding_invalid_scope_or_target', $rows[$invalidCard->id]['primary_reason_code']);
        $this->assertSame('no_direct_candidate', $rows[$noCandidate->id]['primary_reason_code']);
        $this->assertSame('no_confirmed_direct_candidate', $rows[$aiOnly->id]['primary_reason_code']);
        $this->assertSame('unresolved_occurrence_binding', $rows[$unresolved->id]['primary_reason_code']);
        $this->assertSame('competing_direct_candidates', $rows[$competing->id]['primary_reason_code']);
        $this->assertSame('conflicting_direct_bindings', $rows[$conflict->id]['primary_reason_code']);
        $this->assertSame('unique_confirmed_direct_candidate', $rows[$unique->id]['primary_reason_code']);

        $invalidOccurrenceCodes = collect($rows[$invalidCard->id]['occurrences'])->pluck('reason_codes')->flatten()->unique()->sort()->values()->all();
        $this->assertSame([
            'occurrence_language_mismatch',
            'occurrence_user_mismatch',
            'occurrence_word_sense_missing',
        ], $invalidOccurrenceCodes);
        $this->assertContains('occurrence_unresolved', $rows[$unresolved->id]['occurrences'][0]['reason_codes']);
        $this->assertContains('occurrence_conflicts_with_selected', $rows[$conflict->id]['occurrences'][0]['reason_codes']);

        $uniqueRow = $rows[$unique->id];
        $this->assertSame($uniqueSelected->id, $uniqueRow['selected_word_sense_id']);
        $this->assertSame([
            'review_card',
            'encountered_word',
            'target',
            'candidates',
            'occurrences',
            'dependencies',
            'classification',
            'selected_word_sense_id',
            'primary_reason_code',
            'reason_codes',
        ], array_keys($uniqueRow));
        $this->assertSame(
            collect((array) DB::table('review_cards')->find($unique->id))->sortKeys()->keys()->all(),
            array_keys($uniqueRow['review_card']),
        );
        $this->assertSame(
            collect((array) DB::table('encountered_words')->find($uniqueWord->id))->sortKeys()->keys()->all(),
            array_keys($uniqueRow['encountered_word']),
        );
        $this->assertSame([
            'unique_confirmed_direct_candidate',
            'candidate_language_mismatch',
            'candidate_status_rejected',
            'candidate_user_mismatch',
            'card_disabled',
            'target_stage_not_learning',
        ], $uniqueRow['reason_codes']);
        $this->assertSame([
            'exists', 'in_scope', 'stage_is_learning', 'card_enabled', 'reason_codes',
        ], array_keys($uniqueRow['target']));
        $this->assertSame([
            'review_logs',
            'review_card_state_events',
            'reschedule_snapshot_items',
            'operations',
            'reading_session_interactions',
            'reading_session_card_settlements',
            'word_sense_occurrences',
        ], array_keys($uniqueRow['dependencies']));

        $candidateCodes = collect($uniqueRow['candidates'])->pluck('reason_codes')->flatten()->unique()->sort()->values()->all();
        $this->assertSame([
            'candidate_language_mismatch',
            'candidate_status_rejected',
            'candidate_user_mismatch',
        ], $candidateCodes);
        $allCandidateCodes = collect($result['cards'])
            ->pluck('candidates')
            ->flatten(1)
            ->pluck('reason_codes')
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $this->assertSame([
            'candidate_competes_with_selected',
            'candidate_language_mismatch',
            'candidate_status_ai_suggested',
            'candidate_status_rejected',
            'candidate_user_mismatch',
        ], $allCandidateCodes);
        $this->assertSame([
            'word_sense', 'evidence_sources', 'sense_review_card_ids', 'in_scope', 'confirmed', 'reason_codes',
        ], array_keys($uniqueRow['candidates'][0]));
        $this->assertSame([
            'occurrence', 'word_sense', 'in_scope', 'resolved', 'reason_codes',
        ], array_keys($uniqueRow['occurrences'][0]));
        $this->assertSame($uniqueOccurrence->id, $uniqueRow['dependencies']['word_sense_occurrences']['ids'][0]);

        $reviewLogRow = $uniqueRow['dependencies']['review_logs']['rows'][0];
        $this->assertSame(ReviewLog::SOURCE_SENSE_REVIEW, $reviewLogRow['source']);
        $this->assertSame('good', $reviewLogRow['rating']);
        $this->assertIsString($reviewLogRow['reviewed_at']);
        $this->assertNotNull($reviewLogRow['before_card_snapshot']);
        $this->assertNotNull($reviewLogRow['after_card_snapshot']);
        $this->assertNotNull($reviewLogRow['undone_at']);
        $this->assertSame(3, $uniqueRow['dependencies']['operations']['count']);
        $this->assertCount(3, array_unique($uniqueRow['dependencies']['operations']['ids']));
        $this->assertSame(1, $uniqueRow['dependencies']['review_card_state_events']['count']);
        $this->assertSame(1, $uniqueRow['dependencies']['reschedule_snapshot_items']['count']);
        $this->assertSame(1, $uniqueRow['dependencies']['reading_session_interactions']['count']);
        $this->assertSame(1, $uniqueRow['dependencies']['reading_session_card_settlements']['count']);

        $conflictCandidate = collect($rows[$conflict->id]['candidates'])->firstWhere('word_sense.id', $conflictRejected->id);
        $this->assertContains('candidate_competes_with_selected', $conflictCandidate['reason_codes']);
        $this->assertSame($invalidSelected->id, collect($rows[$invalidCard->id]['candidates'])->firstWhere('confirmed', true)['word_sense']['id']);
        $this->assertSame($conflictSelected->id, collect($rows[$conflict->id]['candidates'])->firstWhere('confirmed', true)['word_sense']['id']);
    }

    public function test_command_is_repeatable_filters_scope_and_rejects_invalid_filters(): void
    {
        $englishWord = $this->createWord($this->user->id, 'english', -1, 'english-word');
        $this->createCard($this->user->id, 'english', $englishWord->id);
        $this->createSense($this->user->id, 'english', $englishWord->id, WordSense::STATUS_CONFIRMED, 'english-word');

        $spanishWord = $this->createWord($this->otherUser->id, 'spanish', -1, 'spanish-word');
        $this->createCard($this->otherUser->id, 'spanish', $spanishWord->id);
        $this->createSense($this->otherUser->id, 'spanish', $spanishWord->id, WordSense::STATUS_CONFIRMED, 'spanish-word');

        $sameUserSpanishWord = $this->createWord($this->user->id, 'spanish', -1, 'same-user-spanish');
        $this->createCard($this->user->id, 'spanish', $sameUserSpanishWord->id);
        $this->createSense($this->user->id, 'spanish', $sameUserSpanishWord->id, WordSense::STATUS_CONFIRMED, 'same-user-spanish');

        $otherUserEnglishWord = $this->createWord($this->otherUser->id, 'english', -1, 'other-user-english');
        $this->createCard($this->otherUser->id, 'english', $otherUserEnglishWord->id);
        $this->createSense($this->otherUser->id, 'english', $otherUserEnglishWord->id, WordSense::STATUS_CONFIRMED, 'other-user-english');

        $this->assertSame(0, Artisan::call('reviews:classify-legacy-word-cards'));
        $first = Artisan::output();
        $this->assertSame(0, Artisan::call('reviews:classify-legacy-word-cards'));
        $second = Artisan::output();

        $this->assertSame($first, $second);
        $this->assertStringEndsWith("}\n", $first);
        $this->assertFalse(str_ends_with($first, "}\n\n"));
        $decoded = json_decode(substr($first, 0, -1), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['schema_version', 'filters', 'counts', 'cards'], array_keys($decoded));
        $this->assertSame(['user_id', 'language'], array_keys($decoded['filters']));
        $this->assertSame([
            'total', 'unique_mapping', 'needs_user_confirmation', 'unmapped_read_only',
        ], array_keys($decoded['counts']));
        $this->assertSame('legacy_word_card_classification_v1', $decoded['schema_version']);
        $this->assertSame(4, $decoded['counts']['total']);
        $actualOrder = collect($decoded['cards'])
            ->map(fn (array $row): array => [
                $row['review_card']['user_id'],
                $row['review_card']['language_id'],
                $row['review_card']['id'],
            ])
            ->all();
        $expectedOrder = $actualOrder;
        usort($expectedOrder, fn (array $left, array $right): int => $left <=> $right);
        $this->assertSame($expectedOrder, $actualOrder);

        $this->assertSame(0, Artisan::call('reviews:classify-legacy-word-cards', [
            '--user_id' => (string) $this->user->id,
        ]));
        $userFiltered = json_decode(substr(Artisan::output(), 0, -1), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $userFiltered['counts']['total']);
        $this->assertSame([$this->user->id], collect($userFiltered['cards'])->pluck('review_card.user_id')->unique()->values()->all());

        $this->assertSame(0, Artisan::call('reviews:classify-legacy-word-cards', [
            '--language' => 'english',
        ]));
        $languageFiltered = json_decode(substr(Artisan::output(), 0, -1), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $languageFiltered['counts']['total']);
        $this->assertSame(['english'], collect($languageFiltered['cards'])->pluck('review_card.language_id')->unique()->values()->all());

        $this->assertSame(0, Artisan::call('reviews:classify-legacy-word-cards', [
            '--user_id' => (string) $this->user->id,
            '--language' => 'english',
        ]));
        $filtered = json_decode(substr(Artisan::output(), 0, -1), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['user_id' => $this->user->id, 'language' => 'english'], $filtered['filters']);
        $this->assertSame(1, $filtered['counts']['total']);

        foreach ([
            ['--user_id' => '0'],
            ['--user_id' => '1e3'],
            ['--language' => ''],
            ['--language' => 'English'],
            ['--language' => '../english'],
        ] as $options) {
            $this->assertNotSame(0, Artisan::call('reviews:classify-legacy-word-cards', $options));
            $this->assertStringNotContainsString('"schema_version"', Artisan::output());
        }
    }

    public function test_cross_scope_unresolved_occurrence_uses_priority_three(): void
    {
        $word = $this->createWord($this->user->id, 'english', -1, 'cross-scope-unresolved');
        $card = $this->createCard($this->user->id, 'english', $word->id);
        $this->createSense($this->user->id, 'english', $word->id, WordSense::STATUS_CONFIRMED, 'cross-scope-unresolved');
        $this->createOccurrence($card, null, $this->otherUser->id, 'english');

        $row = app(LegacyWordCardMigrationClassifier::class)->classify($this->user->id, 'english')['cards'][0];

        $this->assertSame('unmapped_read_only', $row['classification']);
        $this->assertSame('occurrence_binding_invalid_scope_or_target', $row['primary_reason_code']);
        $this->assertSame([
            'occurrence_unresolved',
            'occurrence_user_mismatch',
        ], $row['occurrences'][0]['reason_codes']);
    }

    public function test_cross_scope_bound_occurrence_is_not_resolved_or_conflicting_evidence(): void
    {
        $word = $this->createWord($this->user->id, 'english', -1, 'cross-scope-bound');
        $card = $this->createCard($this->user->id, 'english', $word->id);
        $this->createSense($this->user->id, 'english', $word->id, WordSense::STATUS_CONFIRMED, 'cross-scope-selected');
        $competing = $this->createSense($this->user->id, 'english', null, WordSense::STATUS_REJECTED, 'cross-scope-competing');
        $this->createOccurrence($card, $competing, $this->otherUser->id, 'english');

        $row = app(LegacyWordCardMigrationClassifier::class)->classify($this->user->id, 'english')['cards'][0];
        $candidate = collect($row['candidates'])->firstWhere('word_sense.id', $competing->id);

        $this->assertSame('occurrence_binding_invalid_scope_or_target', $row['primary_reason_code']);
        $this->assertFalse($row['occurrences'][0]['resolved']);
        $this->assertSame(['occurrence_user_mismatch'], $row['occurrences'][0]['reason_codes']);
        $this->assertSame(['candidate_status_rejected'], $candidate['reason_codes']);
        $this->assertNotContains('occurrence_conflicts_with_selected', $row['reason_codes']);
        $this->assertNotContains('candidate_competes_with_selected', $row['reason_codes']);
    }

    private function createUser(string $email): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function createWord(int $userId, string $language, int $stage, string $word): EncounteredWord
    {
        return EncounteredWord::forceCreate([
            'user_id' => $userId,
            'language' => $language,
            'stage' => $stage,
            'word' => $word,
            'lemma' => $word,
            'study_base' => $word,
            'kanji' => '',
            'reading' => '',
            'translation' => "{$word} translation",
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'relearning' => false,
        ]);
    }

    private function createCard(int $userId, string $language, int $targetId, bool $enabled = true): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $userId,
            'language_id' => $language,
            'language' => $language,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $targetId,
            'fsrs_due_at' => '2026-08-12 01:00:00',
            'fsrs_enabled' => $enabled,
        ]);
    }

    private function createSense(int $userId, string $language, ?int $encounteredWordId, string $status, string $lemma): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'encountered_word_id' => $encounteredWordId,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_key' => hash('sha256', implode('|', [$userId, $language, $lemma, $status, (string) Str::uuid()])),
            'sense_zh' => "{$lemma} zh",
            'sense_en' => "{$lemma} en",
            'status' => $status,
        ]);
    }

    private function createOccurrence(
        ReviewCard $card,
        ?WordSense $sense,
        int $userId,
        string $language,
        ?int $missingSenseId = null,
    ): WordSenseOccurrence {
        return WordSenseOccurrence::forceCreate([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'word_sense_id' => $sense?->id ?? $missingSenseId,
            'review_card_id' => $card->id,
            'sentence_id' => 'sentence-'.Str::uuid(),
            'sentence_en' => 'A stable sentence.',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'surface',
            'lemma' => 'lemma',
            'decision' => $sense ? 'matched_existing' : 'unresolved',
            'confidence' => 0.9,
            'status' => $sense ? WordSenseOccurrence::STATUS_BOUND : WordSenseOccurrence::STATUS_PENDING,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
        ]);
    }

    private function createDependencies(ReviewCard $card, WordSense $sense, ReviewLog $log): void
    {
        $now = '2026-08-12 01:02:00';
        $secondLog = ReviewLog::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $card->id,
            'rating' => 'hard',
            'reviewed_at' => '2026-08-12 01:00:30',
            'previous_state' => 'learning',
            'new_state' => 'learning',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);
        DB::table('review_card_state_events')->insert([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'review_card_id' => $card->id,
            'action' => 'suspend',
            'request_id' => (string) Str::uuid(),
            'created_at' => $now,
        ]);
        $snapshotId = DB::table('reschedule_snapshots')->insertGetId([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'batch_id' => (string) Str::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('reschedule_snapshot_items')->insert([
            'reschedule_snapshot_id' => $snapshotId,
            'review_card_id' => $card->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $baseOperation = [
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'operation_type' => 'review',
            'scope_type' => 'review_card',
            'scope_id' => (string) $card->id,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::table('operations')->insert($baseOperation + [
            'operation_id' => (string) Str::uuid(),
            'review_card_id' => $card->id,
        ]);
        DB::table('operations')->insert($baseOperation + [
            'operation_id' => (string) Str::uuid(),
            'review_log_id' => $log->id,
        ]);
        DB::table('operations')->insert($baseOperation + [
            'operation_id' => (string) Str::uuid(),
            'review_card_id' => $card->id,
            'review_log_id' => $secondLog->id,
        ]);

        DB::table('reading_session_interactions')->insert([
            'reading_session_id' => 91001,
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'interaction_key' => 'legacy-card-interaction',
            'interaction_type' => 'explicit_rated',
            'word_sense_id' => $sense->id,
            'review_card_id' => $card->id,
            'review_log_id' => $log->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('reading_session_card_settlements')->insert([
            'reading_session_id' => 91001,
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'review_card_id' => $card->id,
            'word_sense_id' => $sense->id,
            'review_log_id' => $log->id,
            'rating' => 'good',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function fingerprint(): string
    {
        $tables = [
            'review_cards',
            'encountered_words',
            'word_senses',
            'word_sense_occurrences',
            'review_logs',
            'review_card_state_events',
            'reschedule_snapshot_items',
            'operations',
            'reading_session_interactions',
            'reading_session_card_settlements',
        ];

        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
