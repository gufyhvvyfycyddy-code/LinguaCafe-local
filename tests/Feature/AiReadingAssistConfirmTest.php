<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Book;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiReadingAssistConfirmTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Chapter $chapter;

    private string $validPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'AI Confirm Test',
            'email' => '__VG_EMAIL_ai_confirm__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => '__VG_EMAIL_ai_confirm_other__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'AI Confirm Book',
            'language' => 'english',
        ]);

        $this->chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'AI Confirm Chapter',
            'language' => 'english',
            'raw_text' => 'This is a test chapter for AI assist confirm.',
            'word_count' => 8,
            'read_count' => 0,
            'unique_words' => '["this","is","a","test","chapter","for","ai","assist"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        $this->validPayload = json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 1, 'source_text' => 'This is a test.', 'translation_zh' => '这是一个测试。'],
            ],
            'vocabulary_items' => [
                ['surface' => 'test', 'suggested_lemma' => 'test', 'pos' => 'NOUN', 'sentence_index' => 1, 'source_sentence' => 'This is a test.', 'meaning_zh' => '测试', 'confidence' => 'high'],
            ],
            'phrase_items' => [
                ['phrase' => 'test out', 'sentence_index' => 1, 'source_sentence' => 'This is a test.', 'meaning_zh' => '测试一下', 'trigger_words' => ['test'], 'confidence' => 'medium'],
            ],
            'warnings' => [
                ['type' => 'length', 'message' => '文章较短。'],
            ],
        ]);
    }

    private function confirm(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validPayload,
        ], $overrides);

        return $this->actingAs($this->user)->postJson('/chapters/ai-assist/confirm', $payload);
    }

    /** @test */
    public function requires_authentication(): void
    {
        $response = $this->postJson('/chapters/ai-assist/confirm', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validPayload,
        ]);
        $response->assertUnauthorized();
    }

    /** @test */
    public function rejects_other_users_chapter(): void
    {
        $otherChapter = Chapter::forceCreate([
            'user_id' => $this->otherUser->id,
            'book_id' => 0,
            'name' => 'Other Chapter',
            'language' => 'english',
            'raw_text' => 'Other chapter.',
            'word_count' => 2,
            'read_count' => 0,
            'unique_words' => '["other","chapter"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        $response = $this->confirm(['chapterId' => $otherChapter->id]);
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function saves_valid_payload(): void
    {
        $response = $this->confirm();
        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'chapter_id', 'summary' => [
                'sentence_translation_count',
                'vocabulary_item_count',
                'phrase_item_count',
                'warning_count',
            ],
        ]);
    }

    /** @test */
    public function saves_sentence_translations(): void
    {
        $this->confirm()->assertOk();

        $record = \App\Models\ChapterAiReadingAssist::where('chapter_id', $this->chapter->id)->first();
        $this->assertNotNull($record);
        $this->assertCount(1, $record->sentence_translations);
        $this->assertEquals('This is a test.', $record->sentence_translations[0]['source_text']);
    }

    /** @test */
    public function saves_vocabulary_items(): void
    {
        $this->confirm()->assertOk();

        $record = \App\Models\ChapterAiReadingAssist::where('chapter_id', $this->chapter->id)->first();
        $this->assertCount(1, $record->vocabulary_items);
        $this->assertEquals('test', $record->vocabulary_items[0]['surface']);
    }

    /** @test */
    public function saves_phrase_items(): void
    {
        $this->confirm()->assertOk();

        $record = \App\Models\ChapterAiReadingAssist::where('chapter_id', $this->chapter->id)->first();
        $this->assertCount(1, $record->phrase_items);
        $this->assertEquals('test out', $record->phrase_items[0]['phrase']);
    }

    /** @test */
    public function saves_warnings(): void
    {
        $this->confirm()->assertOk();

        $record = \App\Models\ChapterAiReadingAssist::where('chapter_id', $this->chapter->id)->first();
        $this->assertCount(1, $record->warnings);
        $this->assertEquals('length', $record->warnings[0]['type']);
    }

    /** @test */
    public function saves_summary_counts(): void
    {
        $this->confirm()->assertOk();

        $record = \App\Models\ChapterAiReadingAssist::where('chapter_id', $this->chapter->id)->first();
        $this->assertEquals(1, $record->summary['sentence_translation_count']);
        $this->assertEquals(1, $record->summary['vocabulary_item_count']);
        $this->assertEquals(1, $record->summary['phrase_item_count']);
        $this->assertEquals(1, $record->summary['warning_count']);
    }

    /** @test */
    public function overwrites_existing_same_chapter(): void
    {
        // First save
        $this->confirm()->assertOk();
        $this->assertEquals(1, \App\Models\ChapterAiReadingAssist::count());

        // Second save with different data
        $updatedPayload = json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 1, 'source_text' => 'Updated.', 'translation_zh' => '更新。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);

        $this->confirm(['aiText' => $updatedPayload])->assertOk();

        // Still only 1 record
        $this->assertEquals(1, \App\Models\ChapterAiReadingAssist::count());

        // But content is updated
        $record = \App\Models\ChapterAiReadingAssist::where('chapter_id', $this->chapter->id)->first();
        $this->assertEquals('Updated.', $record->sentence_translations[0]['source_text']);
        $this->assertCount(0, $record->vocabulary_items);
    }

    /** @test */
    public function rejects_missing_schema_version(): void
    {
        $invalid = json_encode([
            'sentence_translations' => [],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);
        $response = $this->confirm(['aiText' => $invalid]);
        $response->assertStatus(422);
    }

    /** @test */
    public function rejects_wrong_schema_version(): void
    {
        $invalid = json_encode([
            'schema_version' => 'wrong_v1',
            'sentence_translations' => [],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);
        $response = $this->confirm(['aiText' => $invalid]);
        $response->assertStatus(422);
    }

    /** @test */
    public function does_not_create_word_sense(): void
    {
        $originalCount = \App\Models\WordSense::count();
        $this->confirm()->assertOk();
        $this->assertEquals($originalCount, \App\Models\WordSense::count());
    }

    /** @test */
    public function does_not_create_review_card(): void
    {
        $originalCount = \App\Models\ReviewCard::count();
        $this->confirm()->assertOk();
        $this->assertEquals($originalCount, \App\Models\ReviewCard::count());
    }

    /** @test */
    public function does_not_create_review_log(): void
    {
        $originalCount = \App\Models\ReviewLog::count();
        $this->confirm()->assertOk();
        $this->assertEquals($originalCount, \App\Models\ReviewLog::count());
    }

    /** @test */
    public function does_not_modify_encountered_word(): void
    {
        $originalCount = \App\Models\EncounteredWord::count();
        $this->confirm()->assertOk();
        $this->assertEquals($originalCount, \App\Models\EncounteredWord::count());
    }

    /** @test */
    public function user_isolation(): void
    {
        $response = $this->actingAs($this->otherUser)->postJson('/chapters/ai-assist/confirm', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validPayload,
        ]);
        // Other user cannot confirm this user's chapter
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function language_isolation(): void
    {
        // User's selected_language is 'english' and chapter language is 'english'
        // A chapter with different language should be rejected
        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'Japanese Book',
            'language' => 'japanese',
        ]);
        $jpChapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'Japanese Chapter',
            'language' => 'japanese',
            'raw_text' => '日本語の文章。',
            'word_count' => 3,
            'read_count' => 0,
            'unique_words' => '["日本語"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        $response = $this->confirm(['chapterId' => $jpChapter->id]);
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function returns_correct_summary(): void
    {
        $response = $this->confirm();
        $response->assertOk();
        $response->assertJson([
            'summary' => [
                'sentence_translation_count' => 1,
                'vocabulary_item_count' => 1,
                'phrase_item_count' => 1,
                'warning_count' => 1,
            ],
        ]);
    }

    /** @test */
    public function preserves_existing_preview_test_integrity(): void
    {
        // This test confirms confirm does not interfere with preview
        $previewResponse = $this->actingAs($this->user)
            ->postJson('/chapters/ai-assist/preview', [
                'chapterId' => $this->chapter->id,
                'aiText' => $this->validPayload,
            ]);
        $previewResponse->assertOk();

        $this->confirm()->assertOk();

        // Preview still works after confirm
        $previewResponse2 = $this->actingAs($this->user)
            ->postJson('/chapters/ai-assist/preview', [
                'chapterId' => $this->chapter->id,
                'aiText' => $this->validPayload,
            ]);
        $previewResponse2->assertOk();
    }
}
