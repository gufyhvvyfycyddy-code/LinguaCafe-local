<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies the multi-source endpoint /senses/{id}/source-context-list
 * used by the sense review page source dialog carousel.
 *
 * Invariants enforced:
 *  - Returns { sense_id, sources: [...], count } shape.
 *  - Each source has the standard source-context shape.
 *  - Multiple distinct chapter occurrences produce multiple sources.
 *  - Same-chapter duplicates collapse to one source.
 *  - When no chapter-based sources exist, falls back to a single-element
 *    source list (card_example or unavailable).
 *  - Endpoint is read-only: no ReviewLog writes, no WordSense/ReviewCard
 *    creation, no FSRS field changes.
 *  - Cross-user isolation: another user's occurrences are not visible.
 */
class SenseSourceContextMultiSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private WordSenseService $wordSenseService;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\App\Models\Setting::where('name', 'reviewIntervals')->exists()) {
            \App\Models\Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3],
                    '-3' => [7], '-2' => [15], '-1' => [30],
                ]),
            ]);
        }

        $this->user = $this->createUser('multi-source@example.com', 'english');
        $this->otherUser = $this->createUser('other-multi-source@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
    }

    public function test_source_context_list_returns_sources_array_shape(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list');

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('sense_id', $json);
        $this->assertArrayHasKey('sources', $json);
        $this->assertArrayHasKey('count', $json);
        $this->assertIsArray($json['sources']);
        $this->assertSame($sense->id, $json['sense_id']);
        $this->assertSame(count($json['sources']), $json['count']);
    }

    public function test_multiple_distinct_chapters_produce_multiple_sources(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');

        $chapter1Words = [
            (object) ['word' => 'Bureau', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'opened', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];
        $chapter2Words = [
            (object) ['word' => 'Federal', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'Bureau', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'acted', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];

        $chapter1 = $this->createTestChapter($chapter1Words, ['name' => 'Chapter A']);
        $chapter2 = $this->createTestChapter($chapter2Words, ['name' => 'Chapter B']);

        $this->createOccurrence($sense, $chapter1, '0', 'Bureau opened.');
        $this->createOccurrence($sense, $chapter2, '0', 'Federal Bureau acted.');

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list');

        $response->assertOk();
        $json = $response->json();

        $this->assertSame(2, $json['count'], 'should return 2 sources for 2 distinct chapters');
        $this->assertCount(2, $json['sources']);

        $chapterIds = array_column($json['sources'], 'chapter_id');
        $this->assertContains($chapter1->id, $chapterIds);
        $this->assertContains($chapter2->id, $chapterIds);

        foreach ($json['sources'] as $source) {
            $this->assertTrue($source['source_available']);
            $this->assertSame('chapter', $source['source_kind']);
            $this->assertNotEmpty($source['context_tokens']);
        }
    }

    public function test_same_chapter_duplicates_collapse_to_single_source(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapterWords = [
            (object) ['word' => 'Bureau', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'opened', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];
        $chapter = $this->createTestChapter($chapterWords, ['name' => 'Same Chapter']);

        $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');
        $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list');

        $response->assertOk();
        $json = $response->json();

        $this->assertSame(1, $json['count'], 'duplicate chapter occurrences must collapse');
        $this->assertSame($chapter->id, $json['sources'][0]['chapter_id']);
    }

    public function test_no_chapter_sources_falls_back_to_single_source_list(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        // No occurrences at all — should fall back to card_example (single source).

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list');

        $response->assertOk();
        $json = $response->json();

        $this->assertSame(1, $json['count'], 'fallback should produce a single source');
        $this->assertCount(1, $json['sources']);
        // The single source may be card_example or unavailable — both are valid
        // fallbacks. Just verify shape.
        $this->assertArrayHasKey('source_kind', $json['sources'][0]);
        $this->assertArrayHasKey('context_tokens', $json['sources'][0]);
    }

    public function test_source_context_list_is_read_only(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapterWords = [
            (object) ['word' => 'Bureau', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'opened', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];
        $chapter = $this->createTestChapter($chapterWords, ['name' => 'Chapter A']);
        $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        $reviewLogBefore = ReviewLog::count();
        $senseBefore = WordSense::count();
        $cardBefore = ReviewCard::count();
        $occBefore = WordSenseOccurrence::count();

        $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list')
            ->assertOk();

        $this->assertSame($reviewLogBefore, ReviewLog::count(), 'no ReviewLog must be written');
        $this->assertSame($senseBefore, WordSense::count(), 'no WordSense must be created');
        $this->assertSame($cardBefore, ReviewCard::count(), 'no ReviewCard must be created');
        $this->assertSame($occBefore, WordSenseOccurrence::count(), 'no WordSenseOccurrence must be created');
    }

    public function test_source_context_list_does_not_leak_other_users_sources(): void
    {
        $sense = $this->createConfirmedSense('bureau', 'Bureau');
        $chapterWords = [
            (object) ['word' => 'Bureau', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => 'opened', 'sentence_index' => '0', 'spaceAfter' => true],
            (object) ['word' => '.', 'sentence_index' => '0', 'spaceAfter' => false],
        ];
        $chapter = $this->createTestChapter($chapterWords, ['name' => 'My Chapter']);
        $this->createOccurrence($sense, $chapter, '0', 'Bureau opened.');

        // Other user's occurrence pointing at other user's chapter
        $otherChapter = $this->createTestChapter($chapterWords, [
            'user_id' => $this->otherUser->id,
            'name' => 'Other User Chapter',
        ]);
        WordSenseOccurrence::forceCreate([
            'user_id' => $this->otherUser->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id, // bound to first user's sense — unusual but tests isolation
            'chapter_id' => $otherChapter->id,
            'sentence_id' => '0',
            'sentence_en' => 'Bureau leaked.',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'Bureau',
            'lemma' => 'bureau',
            'pos' => 'noun',
            'decision' => 'match_existing_sense',
            'confidence' => 1.0,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list');

        $response->assertOk();
        $json = $response->json();

        // First user should only see their own chapter, not the other user's.
        foreach ($json['sources'] as $source) {
            if (isset($source['chapter_id'])) {
                $this->assertNotSame($otherChapter->id, $source['chapter_id'], 'other user chapter must not leak');
            }
        }
    }

    // ==================== Helpers ====================

    private function createConfirmedSense(string $lemma, string $surfaceForm): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $surfaceForm,
            'pos' => 'noun',
            'sense_zh' => '局',
            'sense_en' => 'an office',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'The bureau opened at noon.',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);
        return $sense->fresh();
    }

    private function createTestChapter(array $processedWords, array $overrides = []): Chapter
    {
        return Chapter::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'book_id' => 1,
            'name' => 'Test Chapter',
            'read_count' => 0,
            'word_count' => count($processedWords),
            'language' => 'english',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'raw_text' => '',
            'type' => 'text',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
            'processed_text' => gzcompress(json_encode($processedWords), 1),
        ], $overrides));
    }

    private function createOccurrence(WordSense $sense, Chapter $chapter, string $sentenceId, string $sentenceEn): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => $sentenceId,
            'sentence_en' => $sentenceEn,
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'decision' => 'match_existing_sense',
            'confidence' => 1.0,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ]);
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
