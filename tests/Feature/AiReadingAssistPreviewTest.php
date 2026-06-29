<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiReadingAssistPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Chapter $chapter;
    private string $language = 'english';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'AI Assist Test',
            'email' => '__VG_EMAIL_ai_assist_test__',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => '__VG_EMAIL_ai_assist_other__',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'AI Assist Test Book',
            'language' => $this->language,
        ]);

        $this->chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'Test Chapter',
            'language' => $this->language,
            'raw_text' => "Phenomenology is a philosophical tradition that investigates the structures of experience.\n\nIt draws on each other in ways that are not always obvious.\n\nIn this sense, we can say that the method is ubiquitous in modern thought.\n\nTheir approach emerged from the work of Husserl.",
            'word_count' => 30,
            'read_count' => 0,
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    // ── Valid sample AI return payloads ───────────

    private function validJsonPayload(): string
    {
        return json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'language' => 'english',
            'source' => ['chapter_title' => 'Test Chapter', 'word_count_estimate' => 30],
            'sentence_translations' => [
                ['sentence_index' => 1, 'source_text' => 'Phenomenology is a philosophical tradition.', 'translation_zh' => '现象学是一种哲学传统。'],
                ['sentence_index' => 2, 'source_text' => 'It draws on each other.', 'translation_zh' => '它相互借鉴。'],
            ],
            'vocabulary_items' => [
                ['surface' => 'phenomenology', 'suggested_lemma' => 'phenomenology', 'pos' => 'noun', 'sentence_index' => 1, 'source_sentence' => 'Phenomenology is a philosophical tradition.', 'meaning_zh' => '现象学', 'confidence' => 'high'],
                ['surface' => 'ubiquitous', 'suggested_lemma' => 'ubiquitous', 'pos' => 'adj', 'sentence_index' => 3, 'source_sentence' => 'the method is ubiquitous.', 'meaning_zh' => '无处不在的', 'confidence' => 'high'],
            ],
            'phrase_items' => [
                ['phrase' => 'draw on each other', 'sentence_index' => 2, 'source_sentence' => 'It draws on each other.', 'meaning_zh' => '相互借鉴', 'trigger_words' => ['draws'], 'confidence' => 'high'],
            ],
            'warnings' => [
                ['type' => 'note', 'message' => 'sample only'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function validCodeBlockPayload(): string
    {
        $json = $this->validJsonPayload();
        return "```json\n{$json}\n```";
    }

    private function validWithSurroundingText(): string
    {
        $json = $this->validJsonPayload();
        return "Below is the analysis:\n{$json}\n\nPlease use this data.";
    }

    private function missingFieldPayload(): string
    {
        return json_encode([
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'language' => 'english',
            // missing: sentence_translations, vocabulary_items, phrase_items, warnings
        ]);
    }

    private function wrongSchemaVersionPayload(): string
    {
        return json_encode([
            'schema_version' => 'v2',
            'language' => 'english',
            'source' => [],
            'sentence_translations' => [],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
        ]);
    }

    // ── Auth tests ───────────────────────────────

    public function test_source_requires_auth(): void
    {
        $response = $this->postJson('/chapters/ai-assist/source', ['chapterId' => $this->chapter->id]);
        $response->assertStatus(401);
    }

    public function test_preview_requires_auth(): void
    {
        $response = $this->postJson('/chapters/ai-assist/preview', ['chapterId' => $this->chapter->id, 'aiText' => 'test']);
        $response->assertStatus(401);
    }

    // ── Chapter ownership ────────────────────────

    public function test_other_user_cannot_access_source(): void
    {
        $response = $this->actingAs($this->otherUser)->postJson('/chapters/ai-assist/source', ['chapterId' => $this->chapter->id]);
        $response->assertStatus(404);
    }

    public function test_other_user_cannot_access_preview(): void
    {
        $response = $this->actingAs($this->otherUser)->postJson('/chapters/ai-assist/preview', ['chapterId' => $this->chapter->id, 'aiText' => 'test']);
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    // ── Source endpoint ──────────────────────────

    public function test_source_returns_prompt(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/source', ['chapterId' => $this->chapter->id]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('chapter_id', $this->chapter->id);
        $response->assertJsonPath('chapter_title', 'Test Chapter');
        $this->assertNotEmpty($response->json('prompt'));
        $this->assertGreaterThan(0, $response->json('article_word_count'));
    }

    public function test_prompt_contains_schema_version(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/source', ['chapterId' => $this->chapter->id]);

        $prompt = $response->json('prompt');
        $this->assertStringContainsString('linguacafe_ai_reading_assist_v1', $prompt);
    }

    public function test_prompt_contains_article_markers(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/source', ['chapterId' => $this->chapter->id]);

        $prompt = $response->json('prompt');
        $this->assertStringContainsString('ARTICLE_TEXT_START', $prompt);
        $this->assertStringContainsString('ARTICLE_TEXT_END', $prompt);
    }

    public function test_prompt_contains_require_json_only(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/source', ['chapterId' => $this->chapter->id]);

        $prompt = $response->json('prompt');
        $this->assertStringContainsString('只返回 JSON', $prompt);
    }

    public function test_prompt_contains_chapter_text(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/source', ['chapterId' => $this->chapter->id]);

        $prompt = $response->json('prompt');
        $this->assertStringContainsString('Phenomenology is a philosophical tradition', $prompt);
        $this->assertStringContainsString('work of Husserl', $prompt);
    }

    public function test_prompt_does_not_contain_api_key_info(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/source', ['chapterId' => $this->chapter->id]);

        $prompt = $response->json('prompt');
        $this->assertStringNotContainsString('API key', $prompt);
        $this->assertStringNotContainsString('api_key', $prompt);
    }

    // ── Preview: pure JSON ───────────────────────

    public function test_preview_parses_pure_json(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validJsonPayload(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('parsed', true);
    }

    public function test_preview_returns_summary_counts(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validJsonPayload(),
        ]);

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertEquals(2, $summary['sentence_translation_count']);
        $this->assertEquals(2, $summary['vocabulary_item_count']);
        $this->assertEquals(1, $summary['phrase_item_count']);
        $this->assertEquals(1, $summary['warning_count']);
    }

    public function test_preview_returns_samples(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validJsonPayload(),
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('samples.sentence_translations'));
        $this->assertNotEmpty($response->json('samples.vocabulary_items'));
        $this->assertNotEmpty($response->json('samples.phrase_items'));
    }

    public function test_preview_returns_schema_version(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validJsonPayload(),
        ]);

        $response->assertOk();
        $this->assertEquals('linguacafe_ai_reading_assist_v1', $response->json('schema_version'));
    }

    // ── Preview: code block JSON ─────────────────

    public function test_preview_parses_code_block_json(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validCodeBlockPayload(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('parsed', true);
        $response->assertJsonPath('summary.sentence_translation_count', 2);
    }

    // ── Preview: surrounding text JSON ───────────

    public function test_preview_parses_surrounding_text(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validWithSurroundingText(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('parsed', true);
        $response->assertJsonPath('summary.sentence_translation_count', 2);
    }

    // ── Preview: error cases ─────────────────────

    public function test_preview_rejects_empty_text(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => '',
        ]);

        $response->assertStatus(422);
    }

    public function test_preview_rejects_missing_fields(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->missingFieldPayload(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('parsed', false);
        $this->assertNotEmpty($response->json('errors'));
    }

    public function test_preview_rejects_wrong_schema_version(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->wrongSchemaVersionPayload(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('parsed', false);
        $this->assertNotEmpty($response->json('errors'));
        $this->assertStringContainsString('schema_version', $response->json('errors.0.field'));
    }

    public function test_preview_rejects_garbage_text(): void
    {
        $response = $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => 'This is not JSON at all. Just some random words.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('parsed', false);
    }

    // ── No DB writes ─────────────────────────────

    public function test_preview_does_not_create_word_sense(): void
    {
        $originalCount = \App\Models\WordSense::count();

        $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validJsonPayload(),
        ]);

        $this->assertEquals($originalCount, \App\Models\WordSense::count());
    }

    public function test_preview_does_not_create_review_card(): void
    {
        $originalCount = \App\Models\ReviewCard::count();

        $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validJsonPayload(),
        ]);

        $this->assertEquals($originalCount, \App\Models\ReviewCard::count());
    }

    public function test_preview_does_not_modify_chapter(): void
    {
        $originalName = $this->chapter->name;

        $this->actingAs($this->user)->postJson('/chapters/ai-assist/preview', [
            'chapterId' => $this->chapter->id,
            'aiText' => $this->validJsonPayload(),
        ]);

        $this->chapter->refresh();
        $this->assertEquals($originalName, $this->chapter->name);
    }
}
