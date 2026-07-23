<?php

namespace Tests\Feature;

use App\Models\AiStudyCardPendingItem;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use App\Services\AiStudyCardPendingLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiStudyCardPendingLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_owner_creates_reuses_and_lists_pending_items(): void
    {
        [$user, $chapter] = $this->fixture('lifecycle-owner@example.test', 'english');
        $service = new AiStudyCardPendingLifecycleService();

        $created = $service->createOrGetPending($user, $this->payload($chapter));
        $reused = $service->createOrGetPending($user, $this->payload($chapter, ['word' => 'Landscape']));
        $listed = $service->listPending($user, $chapter->id, 'pending');

        $this->assertTrue($created['success']);
        $this->assertTrue($created['created']);
        $this->assertFalse($reused['created']);
        $this->assertSame($created['item']->id, $reused['item']->id);
        $this->assertCount(1, $listed['items']);
        $this->assertSame('landscape', $created['item']->normalized_word);
    }

    public function test_direct_owner_dismisses_restores_and_revives_the_same_row(): void
    {
        [$user, $chapter] = $this->fixture('lifecycle-transition@example.test', 'english');
        $service = new AiStudyCardPendingLifecycleService();
        $item = $service->createOrGetPending($user, $this->payload($chapter))['item'];

        $dismissed = $service->dismiss($user, $item->id);
        $restored = $service->restore($user, $item->id);
        $service->dismiss($user, $item->id);
        $revived = $service->createOrGetPending($user, $this->payload($chapter, [
            'sentence_text' => 'Updated source sentence.',
        ]));

        $this->assertSame(AiStudyCardPendingItem::STATUS_DISMISSED, $dismissed['item']->status);
        $this->assertSame(AiStudyCardPendingItem::STATUS_PENDING, $restored['item']->status);
        $this->assertFalse($revived['created']);
        $this->assertSame($item->id, $revived['item']->id);
        $this->assertSame('Updated source sentence.', $revived['item']->sentence_text);
    }

    public function test_direct_owner_preserves_user_language_and_chapter_isolation(): void
    {
        [$user, $chapter] = $this->fixture('lifecycle-isolation@example.test', 'english');
        [$otherUser] = $this->fixture('lifecycle-other@example.test', 'english');
        $service = new AiStudyCardPendingLifecycleService();
        $item = $service->createOrGetPending($user, $this->payload($chapter))['item'];

        $this->assertFalse($service->dismiss($otherUser, $item->id)['success']);
        $this->assertSame(404, $service->listPending($otherUser, $chapter->id, 'pending')['status']);

        $user->selected_language = 'french';
        $this->assertFalse($service->restore($user, $item->id)['success']);
    }

    public function test_direct_owner_marks_only_current_pending_item_processed_and_returns_exact_metadata(): void
    {
        [$user, $chapter] = $this->fixture('lifecycle-processed@example.test', 'english');
        $service = new AiStudyCardPendingLifecycleService();
        $item = $service->createOrGetPending($user, $this->payload($chapter))['item'];

        $processed = $service->markProcessed($user, 'english', $item->id, 'created');
        $repeated = $service->markProcessed($user, 'english', $item->id, 'created');

        $this->assertSame([
            'pending_item_id' => $item->id,
            'pending_item_status_before' => 'pending',
            'pending_item_status_after' => 'processed',
            'pending_item_processed' => true,
            'pending_item_process_reason' => 'created',
        ], $processed);
        $this->assertFalse($repeated['pending_item_processed']);
        $this->assertSame('pending', $repeated['pending_item_status_after']);
        $this->assertSame(AiStudyCardPendingItem::STATUS_PROCESSED, $item->fresh()->status);
    }

    public function test_direct_owner_returns_exact_empty_metadata_and_facade_delegation_source(): void
    {
        $service = new AiStudyCardPendingLifecycleService();

        $this->assertSame([
            'pending_item_id' => null,
            'pending_item_status_before' => null,
            'pending_item_status_after' => null,
            'pending_item_processed' => false,
            'pending_item_process_reason' => null,
        ], $service->emptyInfo());

        $source = file_get_contents(app_path('Services/AiStudyCardPendingItemService.php'));
        $generationSource = file_get_contents(app_path('Services/AiStudyCardGenerationService.php'));
        $this->assertStringContainsString(
            'private AiStudyCardPendingLifecycleService $pendingLifecycleService;',
            $source,
        );
        $this->assertStringContainsString(
            'return $this->pendingLifecycleService->createOrGetPending($user, $data);',
            $source,
        );
        $this->assertStringContainsString(
            '$this->pendingLifecycleService->markProcessed(',
            $generationSource,
        );
        $this->assertStringNotContainsString('private function normalizeWord(', $source);
        $this->assertStringNotContainsString('private function markPendingItemProcessed(', $source);
    }

    private function fixture(string $email, string $language): array
    {
        $user = User::forceCreate([
            'name' => 'AI Lifecycle Owner',
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => "Lifecycle {$language} Book",
            'language' => $language,
        ]);
        $chapter = Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => "Lifecycle {$language} Chapter",
            'language' => $language,
            'raw_text' => 'The intellectual landscape changed quickly.',
            'word_count' => 5,
            'read_count' => 0,
            'unique_words' => '["the","intellectual","landscape","changed","quickly"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        return [$user, $chapter];
    }

    private function payload(Chapter $chapter, array $overrides = []): array
    {
        return array_merge([
            'chapter_id' => $chapter->id,
            'text_block_index' => 0,
            'sentence_index' => 0,
            'sentence_id' => '0',
            'word' => 'landscape',
            'surface' => 'landscape',
            'lemma' => 'landscape',
            'sentence_text' => 'The intellectual landscape changed quickly.',
            'source_payload' => ['source' => 'test'],
        ], $overrides);
    }
}
