<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiReadingAssistSentenceAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Chapter $chapter;
    private array $processedWords;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Alignment Test',
            'email' => '__VG_EMAIL_alignment__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'Alignment Book',
            'language' => 'english',
        ]);

        // Build processed words with known sentence_index values:
        // sentence_index 0: "This is the first sentence."
        // sentence_index 1: "Here is the second one."
        // sentence_index 2: "And a third."
        $this->processedWords = [
            (object) ['word' => 'This', 'sentence_index' => 0, 'is_structure' => false, 'spaceAfter' => true],
            (object) ['word' => 'is', 'sentence_index' => 0, 'is_structure' => false, 'spaceAfter' => true],
            (object) ['word' => 'the', 'sentence_index' => 0, 'is_structure' => false, 'spaceAfter' => true],
            (object) ['word' => 'first', 'sentence_index' => 0, 'is_structure' => false, 'spaceAfter' => true],
            (object) ['word' => 'sentence.', 'sentence_index' => 0, 'is_structure' => false, 'spaceAfter' => false],
            (object) ['word' => 'Here', 'sentence_index' => 1, 'is_structure' => false, 'spaceAfter' => true],
            (object) ['word' => 'is', 'sentence_index' => 1, 'is_structure' => false, 'spaceAfter' => true],
            (object) ['word' => 'the', 'sentence_index' => 1, 'is_structure' => false, 'spaceAfter' => true],
            (object) ['word' => 'second', 'sentence_index' => 1, 'is_structure' => false, 'spaceAfter' => true],
            (object) ['word' => 'one.', 'sentence_index' => 1, 'is_structure' => false, 'spaceAfter' => false],
            (object) ['word' => 'And', 'sentence_index' => 2, 'is_structure' => false, 'spaceAfter' => true],
            (object) ['word' => 'a', 'sentence_index' => 2, 'is_structure' => false, 'spaceAfter' => true],
            (object) ['word' => 'third.', 'sentence_index' => 2, 'is_structure' => false, 'spaceAfter' => false],
        ];

        $this->chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'Alignment Chapter',
            'language' => 'english',
            'raw_text' => "This is the first sentence.\nHere is the second one.\nAnd a third.",
            'word_count' => 13,
            'read_count' => 0,
            'unique_words' => '["this","is","the","first","sentence","here","second","one","and","a","third"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($this->processedWords), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    /** @test */
    public function source_prompt_contains_sentence_list(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/chapters/ai-assist/source', ['chapterId' => $this->chapter->id]);

        $response->assertOk();
        $prompt = $response->json('prompt');
        // Should contain the sentence list section
        $this->assertStringContainsString('LinguaCafe 句子列表', $prompt);
        // Should contain the actual sentence texts
        $this->assertStringContainsString('This is the first sentence.', $prompt);
        $this->assertStringContainsString('Here is the second one.', $prompt);
    }

    /** @test */
    public function source_prompt_contains_correct_sentence_count(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/chapters/ai-assist/source', ['chapterId' => $this->chapter->id]);

        $response->assertOk();
        $this->assertEquals(3, $response->json('sentence_count'));
    }

    /** @test */
    public function preview_accepts_aligned_sentence_translations(): void
    {
        $payload = json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 0, 'source_text' => 'This is the first sentence.', 'translation_zh' => '这是第一句。'],
                ['sentence_index' => 1, 'source_text' => 'Here is the second one.', 'translation_zh' => '这是第二句。'],
                ['sentence_index' => 2, 'source_text' => 'And a third.', 'translation_zh' => '第三句。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/chapters/ai-assist/preview', ['chapterId' => $this->chapter->id, 'aiText' => $payload]);
        $response->assertOk();
    }

    /** @test */
    public function preview_rejects_nonexistent_sentence_index(): void
    {
        $payload = json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 999, 'source_text' => 'Bogus.', 'translation_zh' => '假的。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/chapters/ai-assist/preview', ['chapterId' => $this->chapter->id, 'aiText' => $payload]);
        $response->assertStatus(422);
        $response->assertJsonPath('parsed', false);
    }

    /** @test */
    public function confirm_rejects_nonexistent_sentence_index(): void
    {
        $payload = json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 999, 'source_text' => 'Bogus.', 'translation_zh' => '假的。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/chapters/ai-assist/confirm', ['chapterId' => $this->chapter->id, 'aiText' => $payload]);
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function confirm_accepts_aligned_data(): void
    {
        $payload = json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 0, 'source_text' => 'This is the first sentence.', 'translation_zh' => '这是第一句。'],
                ['sentence_index' => 1, 'source_text' => 'Here is the second one.', 'translation_zh' => '这是第二句。'],
                ['sentence_index' => 2, 'source_text' => 'And a third.', 'translation_zh' => '第三句。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/chapters/ai-assist/confirm', ['chapterId' => $this->chapter->id, 'aiText' => $payload]);
        $response->assertOk();
    }

    /** @test */
    public function allows_missing_translations_with_warning(): void
    {
        $payload = json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 0, 'source_text' => 'This is the first sentence.', 'translation_zh' => '这是第一句。'],
                // sentence_index 1 is missing — should warn but not error
                ['sentence_index' => 2, 'source_text' => 'And a third.', 'translation_zh' => '第三句。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/chapters/ai-assist/confirm', ['chapterId' => $this->chapter->id, 'aiText' => $payload]);
        $response->assertOk();
        // Should have been saved despite missing some translations
        $this->assertEquals(1, \App\Models\ChapterAiReadingAssist::count());
    }

    /** @test */
    public function rejects_duplicate_sentence_index(): void
    {
        $payload = json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 0, 'source_text' => 'This is the first sentence.', 'translation_zh' => '第一句。'],
                ['sentence_index' => 0, 'source_text' => 'This is the first sentence.', 'translation_zh' => '重复的第一句。'],
                ['sentence_index' => 1, 'source_text' => 'Here is the second one.', 'translation_zh' => '第二句。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/chapters/ai-assist/preview', ['chapterId' => $this->chapter->id, 'aiText' => $payload]);
        // Duplicates are warnings, not errors — should still pass validation
        $response->assertOk();
    }

    /** @test */
    public function does_not_create_learning_data_on_confirm(): void
    {
        $originalWordSense = \App\Models\WordSense::count();
        $originalReviewCard = \App\Models\ReviewCard::count();
        $originalReviewLog = \App\Models\ReviewLog::count();
        $originalEncountered = \App\Models\EncounteredWord::count();

        $payload = json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 0, 'source_text' => 'This is the first sentence.', 'translation_zh' => '第一句。'],
                ['sentence_index' => 1, 'source_text' => 'Here is the second one.', 'translation_zh' => '第二句。'],
                ['sentence_index' => 2, 'source_text' => 'And a third.', 'translation_zh' => '第三句。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);

        $this->actingAs($this->user)
            ->postJson('/chapters/ai-assist/confirm', ['chapterId' => $this->chapter->id, 'aiText' => $payload])
            ->assertOk();

        $this->assertEquals($originalWordSense, \App\Models\WordSense::count());
        $this->assertEquals($originalReviewCard, \App\Models\ReviewCard::count());
        $this->assertEquals($originalReviewLog, \App\Models\ReviewLog::count());
        $this->assertEquals($originalEncountered, \App\Models\EncounteredWord::count());
    }

    /** @test */
    public function user_isolation(): void
    {
        $otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => '__VG_EMAIL_alignment_other__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $payload = json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 0, 'source_text' => 'This is the first sentence.', 'translation_zh' => '第一句。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);

        // Other user cannot confirm this chapter
        $response = $this->actingAs($otherUser)
            ->postJson('/chapters/ai-assist/confirm', ['chapterId' => $this->chapter->id, 'aiText' => $payload]);
        $response->assertStatus(422);
    }
}
