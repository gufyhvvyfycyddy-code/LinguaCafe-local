<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterAiReadingAssist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiReadingAssistCurrentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'AI Current Test',
            'email' => '__VG_EMAIL_ai_current__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => '__VG_EMAIL_ai_current_other__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'AI Current Book',
            'language' => 'english',
        ]);

        $this->chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'AI Current Chapter',
            'language' => 'english',
            'raw_text' => 'This is a test chapter.',
            'word_count' => 5,
            'read_count' => 0,
            'unique_words' => '["this","is","a","test","chapter"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function createSavedAssist(): void
    {
        ChapterAiReadingAssist::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'chapter_id' => $this->chapter->id,
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 0, 'source_text' => 'This is a test.', 'translation_zh' => '这是一个测试。'],
                ['sentence_index' => 1, 'source_text' => 'It works.', 'translation_zh' => '它工作了。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
            'summary' => [
                'sentence_translation_count' => 2,
                'vocabulary_item_count' => 0,
                'phrase_item_count' => 0,
                'warning_count' => 0,
            ],
        ]);
    }

    /** @test */
    public function requires_authentication(): void
    {
        $response = $this->getJson('/chapters/ai-assist/current/' . $this->chapter->id);
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
            'raw_text' => 'Other.',
            'word_count' => 1,
            'read_count' => 0,
            'unique_words' => '["other"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        $response = $this->actingAs($this->user)->getJson('/chapters/ai-assist/current/' . $otherChapter->id);
        $response->assertStatus(404);
    }

    /** @test */
    public function returns_has_saved_false_when_no_data(): void
    {
        $response = $this->actingAs($this->user)->getJson('/chapters/ai-assist/current/' . $this->chapter->id);
        $response->assertOk();
        $response->assertJsonPath('has_saved_assist', false);
        $response->assertJsonPath('sentence_translations', []);
        $response->assertJsonMissingPath('updated_at');
    }

    /** @test */
    public function returns_has_saved_true_when_data_exists(): void
    {
        $this->createSavedAssist();

        $response = $this->actingAs($this->user)->getJson('/chapters/ai-assist/current/' . $this->chapter->id);
        $response->assertOk();
        $response->assertJsonPath('has_saved_assist', true);
        $response->assertJsonCount(2, 'sentence_translations');
    }

    /** @test */
    public function returns_sentence_translations(): void
    {
        $this->createSavedAssist();

        $response = $this->actingAs($this->user)->getJson('/chapters/ai-assist/current/' . $this->chapter->id);
        $response->assertJson([
            'sentence_translations' => [
                ['sentence_index' => 0, 'source_text' => 'This is a test.', 'translation_zh' => '这是一个测试。'],
                ['sentence_index' => 1, 'source_text' => 'It works.', 'translation_zh' => '它工作了。'],
            ],
        ]);
    }

    /** @test */
    public function returns_summary(): void
    {
        $this->createSavedAssist();

        $response = $this->actingAs($this->user)->getJson('/chapters/ai-assist/current/' . $this->chapter->id);
        $response->assertJsonPath('summary.sentence_translation_count', 2);
        $response->assertJsonPath('summary.vocabulary_item_count', 0);
    }

    /** @test */
    public function does_not_return_other_users_data(): void
    {
        $this->createSavedAssist();

        // Other user should not see this chapter's assist data
        $response = $this->actingAs($this->otherUser)->getJson('/chapters/ai-assist/current/' . $this->chapter->id);
        $response->assertStatus(404);
    }

    /** @test */
    public function language_isolation(): void
    {
        // User's selected_language is 'english', chapter language is 'english'
        // Accessing a japanese chapter should fail
        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'JP Book',
            'language' => 'japanese',
        ]);
        $jpChapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'JP Chapter',
            'language' => 'japanese',
            'raw_text' => '日本語。',
            'word_count' => 1,
            'read_count' => 0,
            'unique_words' => '["日本語"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        $response = $this->actingAs($this->user)->getJson('/chapters/ai-assist/current/' . $jpChapter->id);
        $response->assertStatus(404);
    }

    /** @test */
    public function does_not_create_word_sense(): void
    {
        $originalCount = \App\Models\WordSense::count();
        $this->createSavedAssist();
        $this->actingAs($this->user)->getJson('/chapters/ai-assist/current/' . $this->chapter->id)->assertOk();
        $this->assertEquals($originalCount, \App\Models\WordSense::count());
    }

    /** @test */
    public function does_not_create_review_card(): void
    {
        $originalCount = \App\Models\ReviewCard::count();
        $this->createSavedAssist();
        $this->actingAs($this->user)->getJson('/chapters/ai-assist/current/' . $this->chapter->id)->assertOk();
        $this->assertEquals($originalCount, \App\Models\ReviewCard::count());
    }

    /** @test */
    public function does_not_create_review_log(): void
    {
        $originalCount = \App\Models\ReviewLog::count();
        $this->createSavedAssist();
        $this->actingAs($this->user)->getJson('/chapters/ai-assist/current/' . $this->chapter->id)->assertOk();
        $this->assertEquals($originalCount, \App\Models\ReviewLog::count());
    }

    /** @test */
    public function does_not_modify_encountered_word(): void
    {
        $originalCount = \App\Models\EncounteredWord::count();
        $this->createSavedAssist();
        $this->actingAs($this->user)->getJson('/chapters/ai-assist/current/' . $this->chapter->id)->assertOk();
        $this->assertEquals($originalCount, \App\Models\EncounteredWord::count());
    }
}
