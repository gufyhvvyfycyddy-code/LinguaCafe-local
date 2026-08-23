<?php

namespace Tests\Feature;

use App\Models\AiStudyCardPendingItem;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use App\Models\WordSense;
use App\Services\AiStudyCardCandidateValidationService;
use App\Services\AiStudyCardGenerationService;
use App\Services\AiStudyCardPendingLifecycleService;
use App\Services\AiStudyCardSourceBindingService;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiStudyCardGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_owner_creates_confirmed_sense_card_source_and_lifecycle_result(): void
    {
        [$user, $chapter] = $this->fixture();
        $item = $this->pending($user, $chapter);
        $candidate = $this->userSelectedCandidate($item, $chapter);
        $result = $this->service()->generate($user, [$candidate], [
            'user_selected_items' => [[
                'item_id' => $item->id,
                'word' => 'Landscape',
                'lemma' => 'landscape',
            ]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['results']['summary']['created_count']);
        $this->assertSame(0, $result['results']['summary']['skipped_count']);
        $this->assertSame('已生成 1 张学习卡，跳过 0 项，重复 0 项，失败 0 项。', $result['message']);
        $this->assertTrue($result['results']['created'][0]['occurrence_created']);
        $this->assertSame('来源已绑定', $result['results']['created'][0]['source_binding_status']);
        $this->assertTrue($result['results']['created'][0]['pending_item_processed']);
        $this->assertSame(AiStudyCardPendingItem::STATUS_PROCESSED, $item->fresh()->status);
        $this->assertDatabaseCount('word_senses', 1);
        $this->assertDatabaseCount('review_cards', 1);
        $this->assertDatabaseCount('word_sense_occurrences', 1);
        $this->assertDatabaseCount('review_logs', 0);
        $sense = WordSense::firstOrFail();
        $this->assertSame(WordSense::LEARNING_ORIGIN_NON_READING, $sense->learning_started_origin);
        $this->assertNull($sense->learning_started_source_occurrence_id);
        $this->assertTrue($result['safety_flags']['no_fsrs_rescheduled']);
        $this->assertTrue($result['safety_flags']['user_confirmation_received']);
    }

    public function test_direct_owner_returns_exact_skipped_result_without_learning_writes(): void
    {
        [$user, $chapter] = $this->fixture();
        $result = $this->service()->generate($user, [[
            'source' => 'ai_recommended',
            'word' => 'Agency',
            'sense_zh' => '能动性',
            'chapter_id' => $chapter->id,
        ]], [
            'ai_recommended_selected_items' => [],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['results']['summary']['skipped_count']);
        $this->assertSame(
            'not_in_final_package_ai_recommended',
            $result['results']['skipped'][0]['reason'],
        );
        $this->assertFalse($result['results']['skipped'][0]['pending_item_processed']);
        $this->assertDatabaseCount('word_senses', 0);
        $this->assertDatabaseCount('review_cards', 0);
        $this->assertDatabaseCount('word_sense_occurrences', 0);
        $this->assertDatabaseCount('review_logs', 0);
    }

    public function test_direct_owner_keeps_ai_recommended_generation_idempotent(): void
    {
        [$user, $chapter] = $this->fixture();
        $candidate = [
            'source' => 'ai_recommended',
            'word' => 'Agency',
            'lemma' => 'agency',
            'surface' => 'Agency',
            'sense_zh' => '能动性',
            'sense_en' => 'agency',
            'chapter_id' => $chapter->id,
            'sentence_id' => 'sentence-2',
            'sentence_text' => 'Agency matters.',
            'text_block_index' => 0,
            'sentence_index' => 1,
        ];
        $package = [
            'ai_recommended_selected_items' => [
                ['word' => 'Agency', 'lemma' => 'agency'],
            ],
        ];

        $first = $this->service()->generate($user, [$candidate], $package);
        $second = $this->service()->generate($user, [$candidate], $package);

        $this->assertSame(1, $first['results']['summary']['created_count']);
        $this->assertSame(1, $second['results']['summary']['duplicate_count']);
        $this->assertSame('sense_and_card_already_exist', $second['results']['duplicate'][0]['reason']);
        $this->assertFalse($second['results']['duplicate'][0]['pending_item_processed']);
        $this->assertDatabaseCount('word_senses', 1);
        $this->assertDatabaseCount('review_cards', 1);
        $this->assertDatabaseCount('word_sense_occurrences', 1);
        $this->assertDatabaseCount('review_logs', 0);
    }

    public function test_coordinator_keeps_public_facade_and_only_delegates_generation(): void
    {
        $source = file_get_contents(app_path('Services/AiStudyCardPendingItemService.php'));

        $this->assertStringContainsString(
            'private AiStudyCardGenerationService $generationService;',
            $source,
        );
        $this->assertStringContainsString(
            'return $this->generationService->generate($user, $confirmedItems, $finalCandidatesPackage);',
            $source,
        );
        $this->assertStringNotContainsString('DB::transaction(', $source);
        $this->assertStringNotContainsString('createOrFindSense(', $source);
        $this->assertStringNotContainsString("'no_review_log_written' => true", $source);
        $this->assertStringContainsString(
            'public function __construct(private WordSenseService $wordSenseService)',
            $source,
        );
    }

    private function service(): AiStudyCardGenerationService
    {
        return new AiStudyCardGenerationService(
            app(WordSenseService::class),
            new AiStudyCardCandidateValidationService(),
            new AiStudyCardSourceBindingService(),
            new AiStudyCardPendingLifecycleService(),
        );
    }

    private function fixture(): array
    {
        $user = User::forceCreate([
            'name' => 'Generation Owner',
            'email' => 'generation-owner@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Generation Book',
            'language' => 'english',
        ]);
        $chapter = Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'Generation Chapter',
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

    private function pending(User $user, Chapter $chapter): AiStudyCardPendingItem
    {
        return (new AiStudyCardPendingLifecycleService())->createOrGetPending($user, [
            'chapter_id' => $chapter->id,
            'text_block_index' => 0,
            'sentence_index' => 0,
            'sentence_id' => 'sentence-1',
            'word' => 'Landscape',
            'surface' => 'Landscape',
            'lemma' => 'landscape',
            'sentence_text' => 'The landscape changed.',
            'source_payload' => [],
        ])['item'];
    }

    private function userSelectedCandidate(AiStudyCardPendingItem $item, Chapter $chapter): array
    {
        return [
            'source' => 'user_selected',
            'item_id' => $item->id,
            'word' => 'Landscape',
            'lemma' => 'landscape',
            'surface' => 'Landscape',
            'sense_zh' => '景观',
            'sense_en' => 'landscape',
            'chapter_id' => $chapter->id,
            'sentence_id' => 'sentence-1',
            'sentence_text' => 'The landscape changed.',
            'text_block_index' => 0,
            'sentence_index' => 0,
        ];
    }
}
