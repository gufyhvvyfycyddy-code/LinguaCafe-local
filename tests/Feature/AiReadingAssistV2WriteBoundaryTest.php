<?php

namespace Tests\Feature;

use App\Models\ChapterAiReadingAssist;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReadingOccurrenceSenseEvidence;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\AiReadingAssistV2Service;
use App\Services\ReadingChapterTextService;
use App\Services\ReadingOccurrenceSenseEvidenceService;
use App\Services\ReadingTargetCatalogService;
use App\Services\ReadingUnfamiliarTargetService;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\PabR3AiReadingAssistV2Harness as V2Harness;
use Tests\TestCase;

/**
 * Executable DB contract. The parallel Harness lane never runs this class;
 * Integration runs it only while holding the exclusive testing-DB lease.
 */
class AiReadingAssistV2WriteBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private array $catalog;
    private ReadingOccurrenceSenseEvidenceService $evidence;
    private AiReadingAssistV2Service $service;
    private ReviewCardService $cardService;
    private ReviewCardFsrsSnapshotService $snapshotService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'id' => V2Harness::USER_ID,
            'name' => 'PAB R3 Phase A Boundary',
            'email' => 'pab-r3-phase-a-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => V2Harness::LANGUAGE,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'PAB R3 Phase A Boundary',
            'language' => V2Harness::LANGUAGE,
        ]);
        Chapter::forceCreate([
            'id' => V2Harness::CHAPTER_ID,
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'PAB R3 Phase A Boundary',
            'language' => V2Harness::LANGUAGE,
            'raw_text' => 'Harness sentence.',
            'word_count' => 1,
            'read_count' => 0,
            'unique_words' => '["harness"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
        $this->catalog = V2Harness::catalog();
        $catalogService = Mockery::mock(ReadingTargetCatalogService::class);
        $catalogService->shouldReceive('build')->andReturnUsing(fn () => $this->catalog);
        $unfamiliar = Mockery::mock(ReadingUnfamiliarTargetService::class);
        $chapterTextService = new ReadingChapterTextService();
        $this->evidence = new ReadingOccurrenceSenseEvidenceService($catalogService, $chapterTextService);
        $this->app->instance(ReadingOccurrenceSenseEvidenceService::class, $this->evidence);
        $this->service = new AiReadingAssistV2Service(
            $catalogService,
            $unfamiliar,
            $this->evidence,
            $chapterTextService,
        );
        $this->cardService = app(ReviewCardService::class);
        $this->snapshotService = app(ReviewCardFsrsSnapshotService::class);
    }

    private function makeSense(string $suffix): WordSense
    {
        $lemma = 'pab-r3-'.$suffix.'-'.Str::lower(Str::random(5));
        return WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => V2Harness::LANGUAGE,
            'language_id' => V2Harness::LANGUAGE,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'NOUN',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', $lemma.'|'.Str::uuid()),
        ]);
    }

    private function bindCandidates(WordSense ...$senses): void
    {
        $this->catalog = V2Harness::catalog(1, [0 => array_map(fn (WordSense $sense) => (int) $sense->id, $senses)]);
    }

    private function packageAndPayload(string $mode = 'ambiguous'): array
    {
        $package = V2Harness::packages($this->service)[0];
        return [$package, V2Harness::aiPayload($package, $mode)];
    }

    private function confirm(array $package, array $payload, bool $trustAi = false): array
    {
        return $this->service->confirmImport(
            $this->user->id,
            V2Harness::LANGUAGE,
            V2Harness::CHAPTER_ID,
            [V2Harness::importPart($package, $payload)],
            $trustAi,
        );
    }

    private function businessCounts(): array
    {
        return [
            'assist' => ChapterAiReadingAssist::count(),
            'evidence' => ReadingOccurrenceSenseEvidence::count(),
            'sense' => WordSense::count(),
            'card' => ReviewCard::count(),
            'log' => ReviewLog::count(),
            'encountered' => EncounteredWord::count(),
        ];
    }

    public function test_v2_preview_has_zero_business_writes(): void
    {
        [$package, $payload] = $this->packageAndPayload();
        $before = $this->businessCounts();

        $result = $this->service->previewImport(
            $this->user->id,
            V2Harness::LANGUAGE,
            V2Harness::CHAPTER_ID,
            [V2Harness::importPart($package, $payload)],
        );

        $this->assertTrue($result['success']);
        $this->assertSame($before, $this->businessCounts());
    }

    public function test_v2_invalid_confirm_has_zero_partial_writes(): void
    {
        [$package, $payload] = $this->packageAndPayload();
        $payload['source_revision'] = 'sha256:stale';
        $before = $this->businessCounts();

        $result = $this->confirm($package, $payload, true);

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_STALE_SOURCE, $result['error_code']);
        $this->assertSame($before, $this->businessCounts());
    }

    public function test_trust_ai_high_matched_existing_writes_binding_evidence_without_rating(): void
    {
        $sense = $this->makeSense('trust');
        $this->bindCandidates($sense);
        $card = $this->cardService->ensureSenseCard($sense);
        $card->refresh();
        $snapshot = $this->snapshotService->capture($card);
        [$package, $payload] = $this->packageAndPayload('matched_existing');
        $beforeLogs = ReviewLog::count();
        $beforeSenses = WordSense::count();

        $result = $this->confirm($package, $payload, true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, ChapterAiReadingAssist::count());
        $this->assertSame(1, ReadingOccurrenceSenseEvidence::count());
        $evidence = ReadingOccurrenceSenseEvidence::firstOrFail();
        $this->assertSame(ReadingOccurrenceSenseEvidence::SOURCE_TRUST_AI, $evidence->resolution_source);
        $this->assertSame($sense->id, $evidence->word_sense_id);
        $this->assertSame($beforeLogs, ReviewLog::count());
        $this->assertSame($beforeSenses, WordSense::count());
        $this->assertTrue($this->snapshotService->matches($card->fresh(), $snapshot));
    }

    public function test_medium_low_ambiguous_and_new_sense_do_not_auto_bind_or_rate(): void
    {
        $sense = $this->makeSense('non-auto');
        $this->bindCandidates($sense);
        $card = $this->cardService->ensureSenseCard($sense);
        $card->refresh();
        $snapshot = $this->snapshotService->capture($card);

        foreach ([['matched_existing', 'medium'], ['matched_existing', 'low'], ['ambiguous', 'high'], ['new_sense', 'high']] as [$mode, $confidence]) {
            [$package, $payload] = $this->packageAndPayload($mode);
            $payload['word_results'][0]['confidence'] = $confidence;
            $result = $this->confirm($package, $payload, true);
            $this->assertTrue($result['success']);
        }

        $this->assertSame(0, ReadingOccurrenceSenseEvidence::count());
        $this->assertSame(0, ReviewLog::count());
        $this->assertTrue($this->snapshotService->matches($card->fresh(), $snapshot));
    }

    public function test_user_evidence_takes_precedence_and_reimport_cannot_overwrite_it(): void
    {
        $aiSense = $this->makeSense('ai');
        $userSense = $this->makeSense('user');
        $this->bindCandidates($aiSense, $userSense);
        [$package, $payload] = $this->packageAndPayload('matched_existing');
        $this->assertTrue($this->confirm($package, $payload, true)['success']);
        $occurrenceId = $this->catalog['targets'][0]['occurrence_id'];

        $userEvidence = $this->evidence->storeUserDecision(
            $this->user->id,
            V2Harness::LANGUAGE,
            V2Harness::CHAPTER_ID,
            $occurrenceId,
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            $userSense->id,
        );
        $this->assertSame(ReadingOccurrenceSenseEvidence::SOURCE_USER, $userEvidence->resolution_source);

        [$secondPackage, $secondPayload] = $this->packageAndPayload('matched_existing');
        $this->assertTrue($this->confirm($secondPackage, $secondPayload, true)['success']);
        $fresh = ReadingOccurrenceSenseEvidence::firstOrFail();
        $this->assertSame(ReadingOccurrenceSenseEvidence::SOURCE_USER, $fresh->resolution_source);
        $this->assertSame($userSense->id, $fresh->word_sense_id);
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_user_can_correct_and_reload_each_evidence_resolution_through_the_api_without_rating(): void
    {
        $firstSense = $this->makeSense('api-first');
        $secondSense = $this->makeSense('api-second');
        $this->bindCandidates($firstSense, $secondSense);
        $occurrenceId = $this->catalog['targets'][0]['occurrence_id'];
        $firstCard = $this->cardService->ensureSenseCard($firstSense)->fresh();
        $secondCard = $this->cardService->ensureSenseCard($secondSense)->fresh();
        $firstCardSnapshot = $this->snapshotService->capture($firstCard);
        $secondCardSnapshot = $this->snapshotService->capture($secondCard);
        $beforeSenseCount = WordSense::count();
        $beforeCardCount = ReviewCard::count();
        $beforeLogCount = ReviewLog::count();

        foreach ([
            [ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING, $firstSense->id],
            [ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING, $secondSense->id],
            [ReadingOccurrenceSenseEvidence::RESOLUTION_NEW_SENSE, null],
            [ReadingOccurrenceSenseEvidence::RESOLUTION_EXCLUDED, null],
        ] as [$resolution, $wordSenseId]) {
            $this->actingAs($this->user)
                ->postJson('/chapters/'.V2Harness::CHAPTER_ID.'/reading-occurrence-evidence', [
                    'occurrence_id' => $occurrenceId,
                    'resolution' => $resolution,
                    'word_sense_id' => $wordSenseId,
                ])
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->actingAs($this->user)
                ->getJson('/chapters/'.V2Harness::CHAPTER_ID.'/reading-occurrence-evidence')
                ->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('items.0.occurrence_id', $occurrenceId)
                ->assertJsonPath('items.0.resolution', $resolution)
                ->assertJsonPath('items.0.word_sense_id', $wordSenseId)
                ->assertJsonPath('items.0.resolution_source', ReadingOccurrenceSenseEvidence::SOURCE_USER);
        }

        $this->assertSame(1, ReadingOccurrenceSenseEvidence::count());
        $this->assertSame($beforeSenseCount, WordSense::count());
        $this->assertSame($beforeCardCount, ReviewCard::count());
        $this->assertSame($beforeLogCount, ReviewLog::count());
        $this->assertTrue($this->snapshotService->matches($firstCard->fresh(), $firstCardSnapshot));
        $this->assertTrue($this->snapshotService->matches($secondCard->fresh(), $secondCardSnapshot));
    }

    public function test_evidence_api_rejects_stale_user_and_language_scope_without_writes(): void
    {
        $sense = $this->makeSense('api-scope');
        $this->bindCandidates($sense);
        $occurrenceId = $this->catalog['targets'][0]['occurrence_id'];
        $path = '/chapters/'.V2Harness::CHAPTER_ID.'/reading-occurrence-evidence';
        $beforeSenseCount = WordSense::count();

        $this->actingAs($this->user)
            ->postJson($path, [
                'occurrence_id' => 'occ2_stale',
                'resolution' => ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
                'word_sense_id' => $sense->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'READING_OCCURRENCE_STALE');

        $otherUser = User::forceCreate([
            'name' => 'PAB R3 Other User',
            'email' => 'pab-r3-other-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => V2Harness::LANGUAGE,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->actingAs($otherUser)
            ->postJson($path, [
                'occurrence_id' => $occurrenceId,
                'resolution' => ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
                'word_sense_id' => $sense->id,
            ])
            ->assertUnauthorized();

        $this->user->selected_language = 'english-other-scope';
        $this->user->save();
        $this->actingAs($this->user)
            ->postJson($path, [
                'occurrence_id' => $occurrenceId,
                'resolution' => ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
                'word_sense_id' => $sense->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'READING_EVIDENCE_INVALID');

        $this->assertSame(0, ReadingOccurrenceSenseEvidence::count());
        $this->assertSame($beforeSenseCount, WordSense::count());
        $this->assertSame(0, ReviewCard::count());
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_evidence_api_hides_old_source_revision_and_reports_it_as_stale(): void
    {
        $occurrenceId = $this->catalog['targets'][0]['occurrence_id'];

        $this->actingAs($this->user)
            ->postJson('/chapters/'.V2Harness::CHAPTER_ID.'/reading-occurrence-evidence', [
                'occurrence_id' => $occurrenceId,
                'resolution' => ReadingOccurrenceSenseEvidence::RESOLUTION_NEW_SENSE,
                'word_sense_id' => null,
            ])
            ->assertOk();

        $currentTarget = $this->catalog['targets'][0];
        $currentTarget['occurrence_id'] = 'occ2_changed_source_revision';
        $this->catalog['source_revision'] = 'sha256:changed-source-revision';
        $this->catalog['targets'] = [$currentTarget];
        $this->catalog['targets_by_id'] = [$currentTarget['occurrence_id'] => $currentTarget];

        $this->actingAs($this->user)
            ->postJson('/chapters/'.V2Harness::CHAPTER_ID.'/reading-occurrence-evidence', [
                'occurrence_id' => $occurrenceId,
                'resolution' => ReadingOccurrenceSenseEvidence::RESOLUTION_NEW_SENSE,
                'word_sense_id' => null,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'READING_OCCURRENCE_STALE');

        $this->actingAs($this->user)
            ->getJson('/chapters/'.V2Harness::CHAPTER_ID.'/reading-occurrence-evidence')
            ->assertOk()
            ->assertJsonPath('source_revision', 'sha256:changed-source-revision')
            ->assertJsonPath('total', 0)
            ->assertJsonPath('items', [])
            ->assertJsonPath('stale_evidence_count', 1);

        $this->assertSame(1, ReadingOccurrenceSenseEvidence::count());
        $this->assertSame(0, WordSense::count());
        $this->assertSame(0, ReviewCard::count());
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_phase_a_preserves_full_existing_review_card_snapshot_and_review_log_count(): void
    {
        $sense = $this->makeSense('snapshot');
        $this->bindCandidates($sense);
        $card = $this->cardService->ensureSenseCard($sense);
        $card->refresh();
        $snapshot = $this->snapshotService->capture($card);
        $logCount = ReviewLog::count();
        [$package, $payload] = $this->packageAndPayload('matched_existing');

        $this->assertTrue($this->confirm($package, $payload, true)['success']);

        $this->assertSame($logCount, ReviewLog::count());
        $this->assertTrue($this->snapshotService->matches($card->fresh(), $snapshot));
    }

    public function test_v2_never_reuses_legacy_numeric_confidence_auto_fsrs_path(): void
    {
        $sense = $this->makeSense('legacy');
        $this->bindCandidates($sense);
        $card = $this->cardService->ensureSenseCard($sense);
        $card->refresh();
        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => V2Harness::LANGUAGE,
            'language_id' => V2Harness::LANGUAGE,
            'word_sense_id' => $sense->id,
            'review_card_id' => $card->id,
            'chapter_id' => V2Harness::CHAPTER_ID,
            'sentence_id' => 'pab-r3-legacy-sentence',
            'sentence_en' => 'Harness sentence.',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'legacy',
            'lemma' => $sense->lemma,
            'pos' => 'NOUN',
            'decision' => 'matched_existing',
            'confidence' => 0.99,
            'auto_fsrs_allowed' => true,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
        ]);
        $snapshot = $this->snapshotService->capture($card);
        [$package, $payload] = $this->packageAndPayload('matched_existing');

        $this->assertTrue($this->confirm($package, $payload, true)['success']);

        $this->assertSame(0, ReviewLog::count());
        $this->assertTrue($this->snapshotService->matches($card->fresh(), $snapshot));
    }
}
