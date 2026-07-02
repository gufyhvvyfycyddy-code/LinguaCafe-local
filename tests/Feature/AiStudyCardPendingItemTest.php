<?php

namespace Tests\Feature;

use App\Models\AiStudyCardPendingItem;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiStudyCardPendingItemTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('ai-pending-user@example.test', 'english');
        $this->otherUser = $this->createUser('ai-pending-other@example.test', 'english');
        $this->chapter = $this->createChapter($this->user, 'english');
    }

    public function test_logged_in_user_can_create_pending_item(): void
    {
        $response = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload());

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('created', true);
        $response->assertJsonPath('item.word', 'landscape');

        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
            'text_block_index' => 0,
            'sentence_index' => 0,
            'word' => 'landscape',
            'normalized_word' => 'landscape',
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ]);
    }

    public function test_unauthenticated_user_cannot_create_pending_item(): void
    {
        $this->postJson('/ai-study-card/pending-items', $this->payload())
            ->assertUnauthorized();

        $this->assertSame(0, AiStudyCardPendingItem::count());
    }

    public function test_user_isolation_rejects_other_users_chapter(): void
    {
        $response = $this->actingAs($this->otherUser)->postJson('/ai-study-card/pending-items', $this->payload());

        $response->assertStatus(404);
        $this->assertSame(0, AiStudyCardPendingItem::count());
    }

    public function test_language_isolation_uses_current_selected_language(): void
    {
        $spanishChapter = $this->createChapter($this->user, 'spanish');

        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items', $this->payload(['chapter_id' => $spanishChapter->id]))
            ->assertStatus(404);

        $this->user->selected_language = 'spanish';
        $this->user->save();

        $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items', $this->payload(['chapter_id' => $spanishChapter->id]))
            ->assertOk();

        $this->assertDatabaseHas('ai_study_card_pending_items', [
            'user_id' => $this->user->id,
            'language_id' => 'spanish',
            'chapter_id' => $spanishChapter->id,
        ]);
        $this->assertDatabaseMissing('ai_study_card_pending_items', [
            'language_id' => 'english',
            'chapter_id' => $spanishChapter->id,
        ]);
    }

    public function test_duplicate_click_does_not_create_unlimited_rows(): void
    {
        $first = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload());
        $second = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload());

        $first->assertOk()->assertJsonPath('created', true);
        $second->assertOk()->assertJsonPath('created', false);

        $this->assertSame(1, AiStudyCardPendingItem::where([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
            'text_block_index' => 0,
            'normalized_word' => 'landscape',
            'status' => AiStudyCardPendingItem::STATUS_PENDING,
        ])->count());
    }

    public function test_pending_item_creation_does_not_create_learning_or_review_data(): void
    {
        $before = [
            'word_senses' => WordSense::count(),
            'review_cards' => ReviewCard::count(),
            'review_logs' => ReviewLog::count(),
            'encountered_words' => EncounteredWord::count(),
            'word_sense_occurrences' => WordSenseOccurrence::count(),
        ];

        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();

        $this->assertSame($before['word_senses'], WordSense::count());
        $this->assertSame($before['review_cards'], ReviewCard::count());
        $this->assertSame($before['review_logs'], ReviewLog::count());
        $this->assertSame($before['encountered_words'], EncounteredWord::count());
        $this->assertSame($before['word_sense_occurrences'], WordSenseOccurrence::count());
    }

    public function test_existing_sense_and_review_card_state_is_unchanged(): void
    {
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'existing',
            'surface_form' => 'existing',
            'pos' => 'noun',
            'sense_key' => 'existing-key',
            'sense_zh' => '已有释义',
            'sense_en' => 'existing sense',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
        ]);

        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_stability' => 4.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 2,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDay(),
            'fsrs_enabled' => true,
        ]);

        $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload())->assertOk();

        $sense->refresh();
        $card->refresh();

        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->status);
        $this->assertSame('review', $card->fsrs_state);
        $this->assertSame(2, $card->fsrs_reps);
        $this->assertTrue((bool) $card->fsrs_enabled);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'chapter_id' => $this->chapter->id,
            'text_block_index' => 0,
            'sentence_index' => 0,
            'sentence_id' => '0',
            'word' => 'landscape',
            'surface' => 'landscape',
            'lemma' => 'landscape',
            'sentence_text' => 'The intellectual landscape changed quickly.',
            'source_payload' => [
                'source' => 'test',
            ],
        ], $overrides);
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => 'AI Study Card Pending User',
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    private function createChapter(User $user, string $language): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => "Pending {$language} Book",
            'language' => $language,
        ]);

        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => "Pending {$language} Chapter",
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
    }
}
