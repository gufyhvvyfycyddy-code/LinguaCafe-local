<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use App\Services\AiStudyCardCandidateValidationService;
use App\Services\AiStudyCardPendingLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiStudyCardCandidateValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_owner_rejects_oversized_generation_batch(): void
    {
        [$user] = $this->fixture();
        $result = (new AiStudyCardCandidateValidationService())->prepare(
            $user,
            array_fill(0, 51, []),
            [],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertSame('单次最多生成 50 张学习卡，请分批确认。', $result['message']);
    }

    public function test_direct_owner_normalizes_and_validates_user_selected_candidate(): void
    {
        [$user, $chapter] = $this->fixture();
        $item = $this->pending($user, $chapter, 'Landscape', 'landscape');
        $service = new AiStudyCardCandidateValidationService();
        $confirmed = [
            'source' => 'user_selected',
            'item_id' => $item->id,
            'word' => ' Landscape ',
            'lemma' => ' landscape ',
            'surface' => ' Landscape ',
            'sense_zh' => ' 景观 ',
            'sense_en' => '   ',
            'chapter_id' => $chapter->id,
            'sentence_id' => 'sentence-1',
            'sentence_text' => ' The landscape changed. ',
            'text_block_index' => '2',
            'sentence_index' => '3',
        ];
        $prepared = $service->prepare($user, [$confirmed], [
            'user_selected_items' => [[
                'item_id' => $item->id,
                'word' => 'Landscape',
                'lemma' => 'landscape',
            ]],
        ]);
        $result = $service->validate($confirmed, $prepared['context']);

        $this->assertTrue($prepared['success']);
        $this->assertTrue($result['success']);
        $this->assertSame('Landscape', $result['candidate']['word']);
        $this->assertSame('landscape', $result['candidate']['lemma']);
        $this->assertSame('Landscape', $result['candidate']['surface']);
        $this->assertSame('景观', $result['candidate']['sense_zh']);
        $this->assertNull($result['candidate']['sense_en']);
        $this->assertSame('The landscape changed.', $result['candidate']['sentence_text']);
        $this->assertSame(2, $result['candidate']['text_block_index']);
        $this->assertSame(3, $result['candidate']['sentence_index']);
    }

    public function test_direct_owner_preserves_package_membership_and_skipped_reasons(): void
    {
        [$user, $chapter] = $this->fixture();
        $service = new AiStudyCardCandidateValidationService();
        $validCandidate = [
            'source' => 'ai_recommended',
            'word' => ' Agency ',
            'sense_zh' => ' 能动性 ',
            'chapter_id' => $chapter->id,
        ];
        $prepared = $service->prepare($user, [$validCandidate], [
            'ai_recommended_selected_items' => [
                ['word' => 'Agency', 'lemma' => 'agency'],
            ],
        ]);

        $valid = $service->validate($validCandidate, $prepared['context']);
        $notSelected = $service->validate([
            'source' => 'ai_recommended',
            'word' => 'Mediation',
            'sense_zh' => '调解',
            'chapter_id' => $chapter->id,
        ], $prepared['context']);
        $invalidSource = $service->validate([
            'source' => 'automatic',
            'word' => 'Agency',
            'sense_zh' => '能动性',
        ], $prepared['context']);
        $emptySense = $service->validate([
            'source' => 'ai_recommended',
            'word' => 'Agency',
            'sense_zh' => ' ',
        ], $prepared['context']);

        $this->assertTrue($valid['success']);
        $this->assertSame('Agency', $valid['candidate']['lemma']);
        $this->assertSame('not_in_final_package_ai_recommended', $notSelected['skipped']['reason']);
        $this->assertSame('invalid_source', $invalidSource['skipped']['reason']);
        $this->assertSame('empty_sense_zh', $emptySense['skipped']['reason']);
        $this->assertFalse($notSelected['skipped']['pending_item_processed']);
    }

    public function test_direct_owner_keeps_pending_chapter_isolation_and_coordinator_calls(): void
    {
        [$user, $chapter] = $this->fixture();
        [$otherUser, $otherChapter] = $this->fixture('candidate-validation-other@example.test');
        $otherItem = $this->pending($otherUser, $otherChapter, 'Landscape', 'landscape');
        $service = new AiStudyCardCandidateValidationService();
        $confirmed = [
            'source' => 'user_selected',
            'item_id' => $otherItem->id,
            'word' => 'Landscape',
            'lemma' => 'landscape',
            'sense_zh' => '景观',
            'chapter_id' => $chapter->id,
        ];
        $prepared = $service->prepare($user, [$confirmed], [
            'user_selected_items' => [[
                'item_id' => $otherItem->id,
                'word' => 'Landscape',
                'lemma' => 'landscape',
            ]],
        ]);
        $invalidPending = $service->validate($confirmed, $prepared['context']);

        $aiPrepared = $service->prepare($user, [], [
            'ai_recommended_selected_items' => [
                ['word' => 'Agency', 'lemma' => 'agency'],
            ],
        ]);
        $invalidChapter = $service->validate([
            'source' => 'ai_recommended',
            'word' => 'Agency',
            'sense_zh' => '能动性',
            'chapter_id' => $otherChapter->id,
        ], $aiPrepared['context']);

        $source = file_get_contents(app_path('Services/AiStudyCardGenerationService.php'));
        $this->assertSame('invalid_pending_item', $invalidPending['skipped']['reason']);
        $this->assertSame('invalid_chapter', $invalidChapter['skipped']['reason']);
        $this->assertStringContainsString(
            'private AiStudyCardCandidateValidationService $candidateValidationService,',
            $source,
        );
        $this->assertStringContainsString(
            '$this->candidateValidationService->prepare(',
            $source,
        );
        $this->assertStringContainsString(
            '$this->candidateValidationService->validate($confirmedItem, $validationContext);',
            $source,
        );
        $this->assertStringNotContainsString('private function packageDedupeKey(', $source);
        $this->assertStringNotContainsString("'word_lemma_mismatch_with_final_package'", $source);
    }

    private function fixture(string $email = 'candidate-validation-owner@example.test'): array
    {
        $user = User::forceCreate([
            'name' => 'Candidate Validation Owner',
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Candidate Validation Book',
            'language' => 'english',
        ]);
        $chapter = Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'Candidate Validation Chapter',
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

        return [$user, $chapter];
    }

    private function pending(User $user, Chapter $chapter, string $word, string $lemma)
    {
        return (new AiStudyCardPendingLifecycleService())->createOrGetPending($user, [
            'chapter_id' => $chapter->id,
            'text_block_index' => 0,
            'sentence_index' => 0,
            'sentence_id' => '0',
            'word' => $word,
            'surface' => $word,
            'lemma' => $lemma,
            'sentence_text' => 'The landscape changed.',
            'source_payload' => [],
        ])['item'];
    }
}
