<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterAiReadingAssist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiReadingAssistLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'AI Lookup Test',
            'email' => '__VG_EMAIL_ai_lookup__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => '__VG_EMAIL_ai_lookup_other__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'AI Lookup Book',
            'language' => 'english',
        ]);

        $this->chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'AI Lookup Chapter',
            'language' => 'english',
            'raw_text' => 'Test chapter.',
            'word_count' => 2,
            'read_count' => 0,
            'unique_words' => '["test","chapter"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        ChapterAiReadingAssist::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'chapter_id' => $this->chapter->id,
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [],
            'vocabulary_items' => [
                ['surface' => 'landscape', 'suggested_lemma' => 'landscape', 'pos' => 'NOUN', 'sentence_index' => 0, 'source_sentence' => 'The intellectual landscape.', 'meaning_zh' => '思想图景；知识领域', 'reason' => '比喻用法', 'confidence' => 'high'],
                ['surface' => 'unique', 'suggested_lemma' => 'unique', 'pos' => 'ADJ', 'sentence_index' => 1, 'source_sentence' => 'A unique perspective.', 'meaning_zh' => '独特的', 'reason' => '强调', 'confidence' => 'medium'],
            ],
            'phrase_items' => [
                ['phrase' => 'intellectual landscape', 'sentence_index' => 0, 'source_sentence' => 'The intellectual landscape.', 'meaning_zh' => '知识领域格局', 'trigger_words' => ['landscape', 'intellectual'], 'reason' => '固定搭配', 'confidence' => 'high'],
            ],
            'warnings' => [],
            'summary' => [],
        ]);
    }

    private function lookup(string $word, string $lemma = '', int $sentenceIndex = 0): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->getJson('/chapters/ai-assist/lookup/' . $this->chapter->id . '?' . http_build_query([
            'word' => $word,
            'lemma' => $lemma ?: $word,
            'sentence_index' => $sentenceIndex,
        ]));
    }

    /** @test */
    public function requires_authentication(): void
    {
        $response = $this->getJson('/chapters/ai-assist/lookup/' . $this->chapter->id . '?word=landscape&lemma=landscape&sentence_index=0');
        $response->assertUnauthorized();
    }

    /** @test */
    public function rejects_other_users_chapter(): void
    {
        $response = $this->actingAs($this->otherUser)->getJson('/chapters/ai-assist/lookup/' . $this->chapter->id . '?word=landscape&lemma=landscape&sentence_index=0');
        $response->assertStatus(404);
    }

    /** @test */
    public function returns_empty_when_no_data(): void
    {
        $chapter2 = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 0,
            'name' => 'Empty Chapter',
            'language' => 'english',
            'raw_text' => 'Empty.',
            'word_count' => 1,
            'read_count' => 0,
            'unique_words' => '["empty"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        $response = $this->actingAs($this->user)->getJson('/chapters/ai-assist/lookup/' . $chapter2->id . '?word=landscape&lemma=landscape&sentence_index=0');
        $response->assertOk();
        $response->assertJsonPath('vocabulary_suggestions', []);
        $response->assertJsonPath('phrase_suggestions', []);
    }

    /** @test */
    public function matches_vocab_by_surface_and_sentence(): void
    {
        $response = $this->lookup('landscape', 'landscape', 0);
        $response->assertOk();
        $response->assertJsonCount(1, 'vocabulary_suggestions');
        $response->assertJsonPath('vocabulary_suggestions.0.meaning_zh', '思想图景；知识领域');
    }

    /** @test */
    public function matches_vocab_by_lemma_and_sentence(): void
    {
        $response = $this->lookup('landscapes', 'landscape', 0);
        $response->assertOk();
        $response->assertJsonCount(1, 'vocabulary_suggestions');
    }

    /** @test */
    public function does_not_match_wrong_sentence_index(): void
    {
        $response = $this->lookup('landscape', 'landscape', 999);
        $response->assertOk();
        $response->assertJsonCount(0, 'vocabulary_suggestions');
        $response->assertJsonCount(0, 'phrase_suggestions');
    }

    /** @test */
    public function matches_phrase_by_trigger_word(): void
    {
        $response = $this->lookup('landscape', 'landscape', 0);
        $response->assertOk();
        $response->assertJsonCount(1, 'phrase_suggestions');
        $response->assertJsonPath('phrase_suggestions.0.phrase', 'intellectual landscape');
    }

    /** @test */
    public function matches_phrase_by_word_in_phrase_text(): void
    {
        $response = $this->lookup('intellectual', 'intellectual', 0);
        $response->assertOk();
        $response->assertJsonCount(1, 'phrase_suggestions');
    }

    /** @test */
    public function user_isolation(): void
    {
        $response = $this->actingAs($this->otherUser)->getJson('/chapters/ai-assist/lookup/' . $this->chapter->id . '?word=landscape&lemma=landscape&sentence_index=0');
        $response->assertStatus(404);
    }

    /** @test */
    public function does_not_create_learning_data(): void
    {
        $originalWordSense = \App\Models\WordSense::count();
        $originalReviewCard = \App\Models\ReviewCard::count();
        $originalReviewLog = \App\Models\ReviewLog::count();
        $originalEncountered = \App\Models\EncounteredWord::count();

        $this->lookup('landscape', 'landscape', 0)->assertOk();

        $this->assertEquals($originalWordSense, \App\Models\WordSense::count());
        $this->assertEquals($originalReviewCard, \App\Models\ReviewCard::count());
        $this->assertEquals($originalReviewLog, \App\Models\ReviewLog::count());
        $this->assertEquals($originalEncountered, \App\Models\EncounteredWord::count());
    }

    /** @test */
    public function returns_safe_fields_only(): void
    {
        $response = $this->lookup('landscape', 'landscape', 0);
        $response->assertOk();
        $vi = $response->json('vocabulary_suggestions.0');
        $this->assertArrayHasKey('surface', $vi);
        $this->assertArrayHasKey('meaning_zh', $vi);
        $this->assertArrayHasKey('pos', $vi);
        $this->assertArrayHasKey('confidence', $vi);
        $this->assertArrayNotHasKey('encountered_word_id', $vi);
        $this->assertArrayNotHasKey('user_id', $vi);
    }
}
