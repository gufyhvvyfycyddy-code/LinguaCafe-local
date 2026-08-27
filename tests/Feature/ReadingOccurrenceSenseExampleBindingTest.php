<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterAiReadingAssist;
use App\Models\ReadingOccurrenceSenseEvidence;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\ReadingOccurrenceSenseEvidenceService;
use App\Services\ReadingSessionService;
use App\Services\ReadingTargetCatalogService;
use App\Services\ReadingUnfamiliarTargetService;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use App\Services\WordSenseExamplePoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadingOccurrenceSenseExampleBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Book $book;
    private Chapter $chapter;
    private ReadingOccurrenceSenseEvidenceService $evidenceService;
    private ReadingTargetCatalogService $catalogService;
    private ReviewCardService $cardService;
    private ReviewCardFsrsSnapshotService $snapshotService;
    private WordSenseExamplePoolService $poolService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Reading Source Binding',
            'email' => 'reading-source-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'Reading Source Binding',
            'language' => 'english',
        ]);
        $this->chapter = $this->createChapter('Sentence B', $this->bankSentenceTokens());

        $this->evidenceService = app(ReadingOccurrenceSenseEvidenceService::class);
        $this->catalogService = app(ReadingTargetCatalogService::class);
        $this->cardService = app(ReviewCardService::class);
        $this->snapshotService = app(ReviewCardFsrsSnapshotService::class);
        $this->poolService = app(WordSenseExamplePoolService::class);
    }

    public function test_authoritative_user_match_projects_one_real_source_and_moves_the_same_row_on_correction(): void
    {
        $senseX = $this->createSense('bank-x', 'bank', '银行');
        $senseY = $this->createSense('bank-y', 'bank', '河岸');
        $cardX = $this->cardService->ensureSenseCard($senseX)->fresh();
        $cardY = $this->cardService->ensureSenseCard($senseY)->fresh();
        $snapshotX = $this->snapshotService->capture($cardX);
        $snapshotY = $this->snapshotService->capture($cardY);
        $sourceA = $this->createChapter('Sentence A', []);
        $this->createExistingOccurrence($senseX, $sourceA, 'Sentence A already belongs to the learned sense.');
        $target = $this->bankTarget();
        $beforeCards = ReviewCard::count();
        $beforeLogs = ReviewLog::count();

        $this->evidenceService->storeUserDecision(
            $this->user->id,
            'english',
            $this->chapter->id,
            $target['occurrence_id'],
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            $senseX->id,
        );

        $sourceB = $this->readingSource();
        $sourceBId = $sourceB->id;
        $this->assertSame($senseX->id, $sourceB->word_sense_id);
        $this->assertSame('The bank reopened.', $sourceB->sentence_en);
        $this->assertSame((string) $target['sentence_index'], $sourceB->sentence_id);
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $sourceB->status);
        $this->assertNull($sourceB->review_card_id);
        $this->assertFalse($sourceB->auto_fsrs_allowed);
        $this->assertContains('Sentence A already belongs to the learned sense.', $this->poolSentences($senseX));
        $this->assertContains('The bank reopened.', $this->poolSentences($senseX));

        $this->evidenceService->storeUserDecision(
            $this->user->id,
            'english',
            $this->chapter->id,
            $target['occurrence_id'],
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            $senseX->id,
        );
        $this->assertSame(1, $this->readingSourceCount());
        $this->assertSame($sourceBId, $this->readingSource()->id);

        $this->evidenceService->storeUserDecision(
            $this->user->id,
            'english',
            $this->chapter->id,
            $target['occurrence_id'],
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            $senseY->id,
        );
        $moved = $this->readingSource();
        $this->assertSame($sourceBId, $moved->id);
        $this->assertSame($senseY->id, $moved->word_sense_id);
        $this->assertNotContains('The bank reopened.', $this->poolSentences($senseX));
        $this->assertContains('The bank reopened.', $this->poolSentences($senseY));

        $this->evidenceService->storeUserDecision(
            $this->user->id,
            'english',
            $this->chapter->id,
            $target['occurrence_id'],
            ReadingOccurrenceSenseEvidence::RESOLUTION_EXCLUDED,
            null,
        );
        $detached = $this->readingSource();
        $this->assertSame($sourceBId, $detached->id);
        $this->assertSame(WordSenseOccurrence::STATUS_IGNORED, $detached->status);
        $this->assertNull($detached->word_sense_id);
        $this->assertNotContains('The bank reopened.', $this->poolSentences($senseY));

        $this->assertSame($beforeCards, ReviewCard::count());
        $this->assertSame($beforeLogs, ReviewLog::count());
        $this->assertTrue($this->snapshotService->matches($cardX->fresh(), $snapshotX));
        $this->assertTrue($this->snapshotService->matches($cardY->fresh(), $snapshotY));
    }

    public function test_matched_existing_binding_does_not_create_a_missing_review_card(): void
    {
        $sense = $this->createSense('bank-no-card', 'bank', '银行');
        $target = $this->bankTarget();

        $this->assertSame(0, ReviewCard::count());
        $this->evidenceService->storeUserDecision(
            $this->user->id,
            'english',
            $this->chapter->id,
            $target['occurrence_id'],
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            $sense->id,
        );

        $this->assertSame(0, ReviewCard::count());
        $this->assertSame($sense->id, $this->readingSource()->word_sense_id);
        $this->assertNull($this->readingSource()->review_card_id);
        $this->assertFalse($this->readingSource()->auto_fsrs_allowed);
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_trusted_ai_match_uses_current_reader_sentence_and_retry_is_idempotent(): void
    {
        $sense = $this->createSense('bank-ai', 'bank', '银行');
        $card = $this->cardService->ensureSenseCard($sense)->fresh();
        $snapshot = $this->snapshotService->capture($card);
        $target = $this->bankTarget();
        $returnedTarget = $target;
        $returnedTarget['source_sentence'] = 'AI-generated replacement sentence.';
        $match = [
            'target' => $returnedTarget,
            'word_sense_id' => $sense->id,
            'confidence' => 'high',
            'package_id' => 'pkg-source-binding-test',
        ];
        $beforeCards = ReviewCard::count();
        $beforeLogs = ReviewLog::count();

        $this->evidenceService->storeTrustedAiMatches(
            $this->user->id,
            'english',
            $this->chapter->id,
            [$match],
            'payload-hash-source-binding-test',
        );
        $source = $this->readingSource();
        $sourceId = $source->id;
        $this->assertSame('The bank reopened.', $source->sentence_en);
        $this->assertSame($sense->id, $source->word_sense_id);

        $this->evidenceService->storeTrustedAiMatches(
            $this->user->id,
            'english',
            $this->chapter->id,
            [$match],
            'payload-hash-source-binding-test',
        );
        $this->assertSame(1, $this->readingSourceCount());
        $this->assertSame($sourceId, $this->readingSource()->id);
        $this->assertSame($beforeCards, ReviewCard::count());
        $this->assertSame($beforeLogs, ReviewLog::count());
        $this->assertTrue($this->snapshotService->matches($card->fresh(), $snapshot));
    }

    public function test_exact_current_saved_translation_enriches_source_occurrence(): void
    {
        $sense = $this->createSense('bank-translation', 'bank', '银行');
        $target = $this->bankTarget();
        $catalog = $this->catalogService->build($this->user->id, 'english', $this->chapter->id);
        ChapterAiReadingAssist::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'chapter_id' => $this->chapter->id,
            'schema_version' => 'linguacafe_ai_reading_assist_v2',
            'source_revision' => $catalog['source_revision'],
            'payload_hash' => 'sha256:translation-test',
            'target_scope_hash' => 'sha256:translation-test',
            'sentence_translations' => [[
                'sentence_index' => $target['sentence_index'],
                'source_text' => 'The bank reopened.',
                'translation_zh' => '银行重新开门了。',
            ]],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
            'summary' => [],
            'validated_payload' => [],
        ]);

        $this->evidenceService->storeUserDecision(
            $this->user->id,
            'english',
            $this->chapter->id,
            $target['occurrence_id'],
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            $sense->id,
        );

        $this->assertSame('The bank reopened.', $this->readingSource()->sentence_en);
        $this->assertSame('银行重新开门了。', $this->readingSource()->sentence_zh);
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_stale_saved_translation_does_not_block_real_english_binding(): void
    {
        $sense = $this->createSense('bank-stale-translation', 'bank', '银行');
        $target = $this->bankTarget();
        ChapterAiReadingAssist::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'chapter_id' => $this->chapter->id,
            'schema_version' => 'linguacafe_ai_reading_assist_v2',
            'source_revision' => 'sha256:stale-translation',
            'payload_hash' => 'sha256:stale-translation',
            'target_scope_hash' => 'sha256:stale-translation',
            'sentence_translations' => [[
                'sentence_index' => $target['sentence_index'],
                'source_text' => 'The bank reopened.',
                'translation_zh' => '不应使用这条旧译文。',
            ]],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
            'summary' => [],
            'validated_payload' => [],
        ]);

        $this->evidenceService->storeUserDecision(
            $this->user->id,
            'english',
            $this->chapter->id,
            $target['occurrence_id'],
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            $sense->id,
        );

        $this->assertSame('The bank reopened.', $this->readingSource()->sentence_en);
        $this->assertNull($this->readingSource()->sentence_zh);
        $this->assertSame($sense->id, $this->readingSource()->word_sense_id);
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_reader_new_sense_reuses_the_pending_real_source_occurrence(): void
    {
        app(ReadingUnfamiliarTargetService::class)->createTarget(
            $this->user->id,
            'english',
            $this->chapter->id,
            'word',
            1,
            1,
        );
        $session = app(ReadingSessionService::class)->startSession(
            $this->user->id,
            'english',
            $this->chapter->id,
        );
        $target = collect($session['reading_targets'])->firstWhere('start_word_index', 1);
        $this->assertNotNull($target);

        $response = $this->actingAs($this->user)->postJson('/senses/manual', [
            'lemma' => 'bank',
            'surface_form' => 'bank',
            'pos' => 'noun',
            'sense_zh' => '银行',
            'sense_en' => 'financial institution',
            'chapter_id' => $this->chapter->id,
            'sentence_id' => 'client-supplied-id',
            'sentence_en' => 'Client supplied replacement sentence.',
            'sentence_zh' => 'Client supplied replacement translation.',
            'reading_session_id' => $session['reading_session_id'],
            'source_revision' => $session['source_revision'],
            'occurrence_id' => $target['occurrence_id'],
        ]);

        $response->assertOk();
        $sense = WordSense::where('user_id', $this->user->id)
            ->where('language_id', 'english')
            ->where('lemma', 'bank')
            ->firstOrFail();
        $source = $this->readingSource();

        $this->assertSame(1, $this->readingSourceCount());
        $this->assertSame(0, WordSenseOccurrence::where('source', WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD)->count());
        $this->assertSame($sense->id, $source->word_sense_id);
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $source->status);
        $this->assertSame('The bank reopened.', $source->sentence_en);
        $this->assertSame('The bank reopened.', $sense->example_sentence_en);
        $this->assertNull($sense->example_sentence_zh);
        $this->assertSame(WordSense::LEARNING_ORIGIN_READING, $sense->learning_started_origin);
        $this->assertSame($source->id, $sense->learning_started_source_occurrence_id);
        $this->assertNotNull($sense->learning_started_at);
        $this->assertNull($source->review_card_id);
        $this->assertFalse($source->auto_fsrs_allowed);
        $this->assertSame(1, ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $sense->id)->count());
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_stale_occurrence_id_fails_closed_without_creating_a_second_source_row(): void
    {
        $sense = $this->createSense('bank-stale', 'bank', '银行');
        $this->cardService->ensureSenseCard($sense);
        $target = $this->bankTarget();
        $this->evidenceService->storeUserDecision(
            $this->user->id,
            'english',
            $this->chapter->id,
            $target['occurrence_id'],
            ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
            $sense->id,
        );
        $sourceId = $this->readingSource()->id;

        $this->chapter->setProcessedText([
            $this->token(0, 'The', 'the', 'determiner', true),
            $this->token(1, 'bank', 'bank', 'noun', true),
            $this->token(2, 'closed.', 'close', 'verb', false),
        ]);
        $this->chapter->raw_text = 'The bank closed.';
        $this->chapter->save();

        try {
            $this->evidenceService->storeUserDecision(
                $this->user->id,
                'english',
                $this->chapter->id,
                $target['occurrence_id'],
                ReadingOccurrenceSenseEvidence::RESOLUTION_MATCHED_EXISTING,
                $sense->id,
            );
            $this->fail('Expected stale Reader occurrence to fail closed.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('READING_OCCURRENCE_STALE', $e->getMessage());
        }

        $this->assertSame(1, $this->readingSourceCount());
        $this->assertSame($sourceId, $this->readingSource()->id);
        $this->assertSame('The bank reopened.', $this->readingSource()->sentence_en);
        $this->assertSame(0, ReviewLog::count());
    }

    private function bankTarget(): array
    {
        $catalog = $this->catalogService->build($this->user->id, 'english', $this->chapter->id);
        foreach ($catalog['targets'] as $target) {
            if ((int) $target['start_word_index'] === 1 && (int) $target['end_word_index'] === 1) {
                return $target;
            }
        }

        $this->fail('Expected the bank Reader target to exist.');
    }

    private function createSense(string $key, string $lemma, string $senseZh): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => $senseZh,
            'sense_en' => $senseZh,
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', $key.'|'.Str::uuid()),
        ]);
    }

    private function createExistingOccurrence(WordSense $sense, Chapter $chapter, string $sentence): void
    {
        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'review_card_id' => null,
            'chapter_id' => $chapter->id,
            'sentence_id' => '0',
            'sentence_en' => $sentence,
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'bank',
            'lemma' => 'bank',
            'pos' => 'noun',
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ]);
    }

    private function readingSource(): WordSenseOccurrence
    {
        return WordSenseOccurrence::query()
            ->where('user_id', $this->user->id)
            ->where('chapter_id', $this->chapter->id)
            ->where('source', WordSenseOccurrence::SOURCE_READING_OCCURRENCE)
            ->firstOrFail();
    }

    private function readingSourceCount(): int
    {
        return WordSenseOccurrence::query()
            ->where('user_id', $this->user->id)
            ->where('chapter_id', $this->chapter->id)
            ->where('source', WordSenseOccurrence::SOURCE_READING_OCCURRENCE)
            ->count();
    }

    private function poolSentences(WordSense $sense): array
    {
        return array_column($this->poolService->exampleCandidates($sense->fresh()), 'sentence_en');
    }

    private function createChapter(string $name, array $tokens): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'name' => $name,
            'language' => 'english',
            'raw_text' => $tokens ? 'The bank reopened.' : '',
            'word_count' => count($tokens),
            'read_count' => 0,
            'unique_words' => '["bank"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($tokens), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function bankSentenceTokens(): array
    {
        return [
            $this->token(0, 'The', 'the', 'determiner', true),
            $this->token(1, 'bank', 'bank', 'noun', true),
            $this->token(2, 'reopened.', 'reopen', 'verb', false),
        ];
    }

    private function token(int $wordIndex, string $word, string $lemma, string $pos, bool $spaceAfter): array
    {
        return [
            'word_index' => $wordIndex,
            'word' => $word,
            'lemma' => $lemma,
            'pos' => $pos,
            'sentence_index' => 0,
            'spaceAfter' => $spaceAfter,
        ];
    }
}
