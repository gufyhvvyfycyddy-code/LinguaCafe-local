<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseMappingImportService;
use App\Services\SenseMappingValidationService;
use App\Services\WordSenseOccurrenceService;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class D06OccurrenceMorphologyConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private SenseMappingImportService $importService;
    private SenseMappingValidationService $validationService;
    private WordSenseOccurrenceService $occurrenceService;
    private array $mappingPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!Setting::where('name', 'reviewIntervals')->exists()) {
            Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0],
                    '-6' => [1],
                    '-5' => [2],
                    '-4' => [3],
                    '-3' => [7],
                    '-2' => [15],
                    '-1' => [30],
                ]),
            ]);
        }

        $this->user = User::forceCreate([
            'name' => 'd06@example.com',
            'email' => 'd06@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->wordSenseService = app(WordSenseService::class);
        $this->importService = app(SenseMappingImportService::class);
        $this->validationService = app(SenseMappingValidationService::class);
        $this->occurrenceService = app(WordSenseOccurrenceService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->mappingPaths as $path) {
            @unlink(base_path($path));
        }

        parent::tearDown();
    }

    public function test_compatible_high_confidence_match_still_binds_and_creates_card(): void
    {
        $sense = $this->createSense(['sense_key' => 'd06-compatible']);

        $summary = $this->import($this->mappingPayload($sense));

        $occurrence = WordSenseOccurrence::firstOrFail();
        $this->assertSame(1, $summary['bound_existing_senses']);
        $this->assertSame(0, $summary['pending_confirmations']);
        $this->assertSame(1, $summary['created_sense_cards']);
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $occurrence->status);
        $this->assertSame($sense->id, $occurrence->word_sense_id);
        $this->assertTrue($occurrence->auto_fsrs_allowed);
        $this->assertNotNull($occurrence->review_card_id);
    }

    public function test_lemma_conflict_becomes_pending_without_rewriting_occurrence_or_sense(): void
    {
        $sense = $this->createSense(['sense_key' => 'd06-lemma-conflict']);

        $summary = $this->import($this->mappingPayload($sense, ['lemma' => 'charges']));

        $occurrence = WordSenseOccurrence::firstOrFail();
        $sense->refresh();
        $this->assertSame(0, $summary['bound_existing_senses']);
        $this->assertSame(1, $summary['pending_confirmations']);
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $occurrence->status);
        $this->assertSame($sense->id, $occurrence->word_sense_id);
        $this->assertSame('charges', $occurrence->lemma);
        $this->assertSame('verb', $occurrence->pos);
        $this->assertFalse($occurrence->auto_fsrs_allowed);
        $this->assertNull($occurrence->review_card_id);
        $this->assertSame('charge', $sense->lemma);
        $this->assertSame('verb', $sense->pos);
        $this->assertSame(0, ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count());
    }

    public function test_pos_conflict_becomes_pending_without_rewriting_either_side(): void
    {
        $sense = $this->createSense(['sense_key' => 'd06-pos-conflict']);

        $this->import($this->mappingPayload($sense, ['pos' => 'noun']));

        $occurrence = WordSenseOccurrence::firstOrFail();
        $sense->refresh();
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $occurrence->status);
        $this->assertSame($sense->id, $occurrence->word_sense_id);
        $this->assertSame('charge', $occurrence->lemma);
        $this->assertSame('noun', $occurrence->pos);
        $this->assertFalse($occurrence->auto_fsrs_allowed);
        $this->assertNull($occurrence->review_card_id);
        $this->assertSame('charge', $sense->lemma);
        $this->assertSame('verb', $sense->pos);
    }

    public function test_case_and_pos_alias_equivalence_do_not_false_conflict(): void
    {
        $verbSense = $this->createSense(['sense_key' => 'd06-verb-alias']);
        $adjectiveSense = $this->createSense([
            'sense_key' => 'd06-adjective-alias',
            'lemma' => 'quick',
            'surface_form' => 'quick',
            'pos' => 'adjective',
        ]);

        $this->import($this->mappingPayload($verbSense, [
            'lemma' => ' CHARGE ',
            'pos' => 'VERB',
            'auto_fsrs_allowed' => false,
        ]));
        $this->import($this->mappingPayload($adjectiveSense, [
            'surface' => 'quick',
            'lemma' => 'QUICK',
            'pos' => 'adj',
            'auto_fsrs_allowed' => false,
        ]));

        $this->assertSame(2, WordSenseOccurrence::where('status', WordSenseOccurrence::STATUS_BOUND)->count());
    }

    public function test_empty_and_other_pos_alone_do_not_false_conflict(): void
    {
        $sense = $this->createSense(['sense_key' => 'd06-unknown-pos']);

        $this->import($this->mappingPayload($sense, ['pos' => '', 'auto_fsrs_allowed' => false]));
        $this->import($this->mappingPayload($sense, ['pos' => 'other', 'auto_fsrs_allowed' => false]));

        $this->assertSame(2, WordSenseOccurrence::where('status', WordSenseOccurrence::STATUS_BOUND)->count());
    }

    public function test_validation_summary_moves_conflict_to_needs_confirmation_without_invalidating_payload(): void
    {
        $sense = $this->createSense(['sense_key' => 'd06-validation-conflict']);
        $payload = $this->mappingPayload($sense, ['pos' => 'noun']);

        $validation = $this->validationService->validatePayload($payload, $this->user->id, 'english');

        $this->assertTrue($validation['valid']);
        $this->assertSame([], $validation['errors']);
        $this->assertSame(0, $validation['summary']['auto_bind_candidates']);
        $this->assertSame(1, $validation['summary']['needs_confirmation']);
    }

    public function test_dry_run_reports_same_conflict_outcome_as_real_import_without_writes(): void
    {
        $sense = $this->createSense(['sense_key' => 'd06-dry-run-conflict']);
        $payload = $this->mappingPayload($sense, ['lemma' => 'charges']);
        $path = $this->writeMapping($payload);

        $drySummary = $this->importService->importFile($path, $this->user->id, 'english', true);

        $this->assertSame(0, WordSenseOccurrence::count());
        $this->assertSame(0, ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count());
        $this->assertSame(0, $drySummary['bound_existing_senses']);
        $this->assertSame(1, $drySummary['pending_confirmations']);
        $this->assertSame(0, $drySummary['created_sense_cards']);

        $realSummary = $this->importService->importFile($path, $this->user->id, 'english', false);

        foreach (['total_items', 'imported_occurrences', 'bound_existing_senses', 'pending_confirmations', 'created_sense_cards'] as $key) {
            $this->assertSame($drySummary[$key], $realSummary[$key]);
        }
        $this->assertSame(1, WordSenseOccurrence::count());
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, WordSenseOccurrence::firstOrFail()->status);
    }

    public function test_bulk_high_confidence_skips_conflict_and_reports_manual_confirmation_reason(): void
    {
        $sense = $this->createSense(['sense_key' => 'd06-bulk-conflict']);
        $occurrence = $this->createOccurrence([
            'word_sense_id' => $sense->id,
            'lemma' => 'charges',
            'pos' => 'verb',
            'decision' => 'match_existing_sense',
            'confidence' => 0.99,
            'auto_fsrs_allowed' => true,
        ]);

        $summary = $this->occurrenceService->bulkConfirmHighConfidence($this->user->id, 'english');

        $occurrence->refresh();
        $this->assertSame(0, $summary['processed_count']);
        $this->assertSame(1, $summary['skipped_count']);
        $this->assertSame(0, $summary['confirmed_count']);
        $this->assertStringContainsString('morphology conflict requires manual confirmation', $summary['errors'][0]);
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $occurrence->status);
        $this->assertSame($sense->id, $occurrence->word_sense_id);
        $this->assertSame('charges', $occurrence->lemma);
        $this->assertSame('verb', $occurrence->pos);
        $this->assertTrue($occurrence->auto_fsrs_allowed);
        $this->assertNull($occurrence->review_card_id);
        $this->assertSame(0, ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count());
    }

    public function test_explicit_single_confirm_and_bind_still_allow_morphology_mismatch_without_rewrite(): void
    {
        $sense = $this->createSense(['sense_key' => 'd06-explicit-mismatch']);
        $confirmOccurrence = $this->createOccurrence([
            'sentence_id' => 'd06-confirm',
            'word_sense_id' => $sense->id,
            'lemma' => 'charges',
            'pos' => 'noun',
        ]);
        $bindOccurrence = $this->createOccurrence([
            'sentence_id' => 'd06-bind',
            'lemma' => 'charged',
            'pos' => 'adjective',
        ]);

        $this->actingAs($this->user)
            ->post("/senses/occurrences/{$confirmOccurrence->id}/confirm")
            ->assertOk();
        $this->actingAs($this->user)
            ->post("/senses/occurrences/{$bindOccurrence->id}/bind", [
                'sense_id' => $sense->id,
                'auto_fsrs_allowed' => false,
            ])
            ->assertOk();

        $sense->refresh();
        $confirmOccurrence->refresh();
        $bindOccurrence->refresh();
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $confirmOccurrence->status);
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $bindOccurrence->status);
        $this->assertSame('charges', $confirmOccurrence->lemma);
        $this->assertSame('noun', $confirmOccurrence->pos);
        $this->assertSame('charged', $bindOccurrence->lemma);
        $this->assertSame('adjective', $bindOccurrence->pos);
        $this->assertSame('charge', $sense->lemma);
        $this->assertSame('verb', $sense->pos);
    }

    public function test_import_does_not_rewrite_preexisting_bound_occurrence_or_matched_sense(): void
    {
        $sense = $this->createSense(['sense_key' => 'd06-preexisting']);
        $existing = $this->createOccurrence([
            'sentence_id' => 'd06-existing',
            'word_sense_id' => $sense->id,
            'lemma' => 'legacy-lemma',
            'pos' => 'noun',
            'decision' => 'match_existing_sense',
            'confidence' => 0.95,
            'status' => WordSenseOccurrence::STATUS_BOUND,
        ]);

        $this->import($this->mappingPayload($sense, [
            'lemma' => 'charges',
            'pos' => 'noun',
        ]));

        $existing->refresh();
        $sense->refresh();
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $existing->status);
        $this->assertSame($sense->id, $existing->word_sense_id);
        $this->assertSame('legacy-lemma', $existing->lemma);
        $this->assertSame('noun', $existing->pos);
        $this->assertSame('charge', $sense->lemma);
        $this->assertSame('verb', $sense->pos);
        $this->assertSame(2, WordSenseOccurrence::count());
        $this->assertSame(1, WordSenseOccurrence::where('status', WordSenseOccurrence::STATUS_PENDING)->count());
    }

    private function createSense(array $overrides = []): WordSense
    {
        return $this->wordSenseService->createSense(array_merge([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'charge',
            'surface_form' => 'charge',
            'pos' => 'verb',
            'sense_key' => 'd06-' . Str::uuid(),
            'sense_zh' => '收费；要价',
            'sense_en' => 'to ask for money as a price',
            'aliases_zh' => [],
            'collocations' => ['charge a fee'],
            'example_sentence_en' => 'They charge a fee.',
            'example_sentence_zh' => '他们收费。',
            'status' => WordSense::STATUS_CONFIRMED,
        ], $overrides));
    }

    private function createOccurrence(array $overrides = []): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'sentence_id' => (string) Str::uuid(),
            'sentence_en' => 'They charge a fee.',
            'sentence_zh' => '他们收费。',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'charge',
            'lemma' => 'charge',
            'pos' => 'verb',
            'decision' => 'uncertain',
            'confidence' => 0.5,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_PENDING,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
            'raw_payload' => ['decision' => 'uncertain'],
        ], $overrides));
    }

    private function mappingPayload(WordSense $sense, array $overrides = []): array
    {
        return [
            'schema_version' => 1,
            'items' => [[
                'sentence_id' => (string) Str::uuid(),
                'en' => 'They charge a fee.',
                'zh' => '他们收费。',
                'matches' => [array_merge([
                    'surface' => 'charge',
                    'lemma' => 'charge',
                    'pos' => 'verb',
                    'decision' => 'match_existing_sense',
                    'matched_sense_id' => $sense->id,
                    'confidence' => 0.95,
                    'auto_fsrs_allowed' => true,
                ], $overrides)],
            ]],
        ];
    }

    private function import(array $payload): array
    {
        return $this->importService->importFile($this->writeMapping($payload), $this->user->id, 'english');
    }

    private function writeMapping(array $payload): string
    {
        $relativePath = 'storage/app/d06-morphology-' . Str::uuid() . '.json';
        file_put_contents(base_path($relativePath), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->mappingPaths[] = $relativePath;

        return $relativePath;
    }
}
