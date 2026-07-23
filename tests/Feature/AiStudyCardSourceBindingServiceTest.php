<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\AiStudyCardSourceBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiStudyCardSourceBindingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_owner_binds_explicit_sentence_id_with_exact_payload(): void
    {
        [$sense, $card, $chapter] = $this->fixture();
        $result = (new AiStudyCardSourceBindingService())->bind($sense, $card, [
            'word' => 'Landscape',
            'surface' => 'Landscape',
            'chapter_id' => $chapter->id,
            'sentence_id' => 'sentence-1',
            'sentence_text' => 'The landscape changed.',
            'text_block_index' => 0,
            'sentence_index' => 0,
        ]);

        $occurrence = WordSenseOccurrence::findOrFail($result['occurrence_id']);
        $this->assertTrue($result['occurrence_created']);
        $this->assertSame('explicit_sentence_id', $result['occurrence_reason']);
        $this->assertSame('sentence-1', $result['effective_sentence_id']);
        $this->assertSame('来源已绑定', $result['source_binding_status']);
        $this->assertSame($card->id, $occurrence->review_card_id);
        $this->assertSame(WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD, $occurrence->source);
        $this->assertSame('ai_study_card_confirmed_candidate', $occurrence->evidence['source']);
        $this->assertSame('explicit_sentence_id', $occurrence->raw_payload['sentence_id_source']);
    }

    public function test_direct_owner_builds_exact_synthetic_sentence_id(): void
    {
        [$sense, $card, $chapter] = $this->fixture();
        $result = (new AiStudyCardSourceBindingService())->bind($sense, $card, [
            'word' => ' Landscape ',
            'surface' => 'Landscape',
            'chapter_id' => $chapter->id,
            'sentence_id' => null,
            'sentence_text' => 'The landscape changed.',
            'text_block_index' => 2,
            'sentence_index' => 3,
        ]);

        $expected = 'ai-study-card:' . $chapter->id . ':2:3:landscape';
        $this->assertTrue($result['occurrence_created']);
        $this->assertSame('synthetic_sentence_id', $result['occurrence_reason']);
        $this->assertSame($expected, $result['effective_sentence_id']);
        $this->assertSame('来源已绑定（合成 sentence_id）', $result['source_binding_status']);
        $this->assertDatabaseHas('word_sense_occurrences', [
            'id' => $result['occurrence_id'],
            'sentence_id' => $expected,
        ]);
    }

    public function test_direct_owner_preserves_missing_source_results_without_writes(): void
    {
        [$sense, $card, $chapter] = $this->fixture();
        $service = new AiStudyCardSourceBindingService();
        $noSentence = $service->bind($sense, $card, [
            'word' => 'Landscape',
            'surface' => 'Landscape',
            'chapter_id' => $chapter->id,
            'sentence_id' => null,
            'sentence_text' => '',
            'text_block_index' => 0,
            'sentence_index' => 0,
        ]);
        $noChapter = $service->bind($sense, $card, [
            'word' => 'Landscape',
            'surface' => 'Landscape',
            'chapter_id' => null,
            'sentence_id' => 'sentence-1',
            'sentence_text' => 'The landscape changed.',
            'text_block_index' => 0,
            'sentence_index' => 0,
        ]);
        $insufficient = $service->bind($sense, $card, [
            'word' => 'Landscape',
            'surface' => 'Landscape',
            'chapter_id' => $chapter->id,
            'sentence_id' => null,
            'sentence_text' => 'The landscape changed.',
            'text_block_index' => null,
            'sentence_index' => null,
        ]);

        $this->assertSame('no_sentence_text', $noSentence['occurrence_reason']);
        $this->assertSame('no_chapter_id', $noChapter['occurrence_reason']);
        $this->assertSame('insufficient_source_info', $insufficient['occurrence_reason']);
        $this->assertFalse($noSentence['occurrence_created']);
        $this->assertNull($noSentence['occurrence_id']);
        $this->assertSame('来源信息不足，已创建卡片但未绑定来源', $noSentence['source_binding_status']);
        $this->assertDatabaseCount('word_sense_occurrences', 0);
    }

    public function test_direct_owner_is_idempotent_and_coordinator_delegates_inside_transaction(): void
    {
        [$sense, $card, $chapter] = $this->fixture();
        $service = new AiStudyCardSourceBindingService();
        $candidate = [
            'word' => 'Landscape',
            'surface' => 'Landscape',
            'chapter_id' => $chapter->id,
            'sentence_id' => 'sentence-1',
            'sentence_text' => 'The landscape changed.',
            'text_block_index' => 0,
            'sentence_index' => 0,
        ];
        $first = $service->bind($sense, $card, $candidate);
        $second = $service->bind($sense, $card, $candidate);

        $source = file_get_contents(app_path('Services/AiStudyCardGenerationService.php'));
        $this->assertSame($first['occurrence_id'], $second['occurrence_id']);
        $this->assertDatabaseCount('word_sense_occurrences', 1);
        $this->assertStringContainsString(
            'private AiStudyCardSourceBindingService $sourceBindingService,',
            $source,
        );
        $this->assertStringContainsString(
            '$this->sourceBindingService->bind($sense, $card, $candidate);',
            $source,
        );
        $this->assertStringNotContainsString('WordSenseOccurrence::updateOrCreate(', $source);
        $this->assertStringNotContainsString('private function resolveSourceBindingStatus(', $source);
    }

    private function fixture(): array
    {
        $user = User::forceCreate([
            'name' => 'Source Binding Owner',
            'email' => 'source-binding-owner@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Source Binding Book',
            'language' => 'english',
        ]);
        $chapter = Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'Source Binding Chapter',
            'language' => 'english',
            'raw_text' => 'The landscape changed.',
            'word_count' => 3,
            'read_count' => 0,
            'unique_words' => '["the","landscape","changed"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'landscape',
            'surface_form' => 'Landscape',
            'pos' => 'noun',
            'sense_zh' => '景观',
            'sense_en' => 'landscape',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'The landscape changed.',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', 'source-binding-' . Str::uuid()),
        ]);
        $card = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);

        return [$sense, $card, $chapter];
    }
}
