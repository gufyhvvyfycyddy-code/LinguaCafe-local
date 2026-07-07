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
 * Locks the source-context write boundary.
 *
 * Source context is mostly a lookup feature, but recovered chapter matches
 * intentionally update WordSense / WordSenseOccurrence source location fields.
 */
class SenseSourceContextWriteBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
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

        $this->user = User::forceCreate([
            'name' => 'source-boundary@example.com',
            'email' => 'source-boundary@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->wordSenseService = app(WordSenseService::class);
    }

    public function test_source_context_list_recovery_fallback_writes_only_source_location_fields(): void
    {
        $sense = $this->createConfirmedSense();
        $chapter = $this->createChapterForSentence('The bureau opened at noon.');

        $this->assertNull($sense->source_chapter_id);
        $this->assertNull($sense->sentence_id);

        $reviewLogBefore = ReviewLog::count();
        $cardBefore = ReviewCard::count();
        $occurrenceBefore = WordSenseOccurrence::count();

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list');

        $response->assertOk();
        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertSame('chapter_recovered', $json['sources'][0]['source_kind']);
        $this->assertSame($chapter->id, $json['sources'][0]['chapter_id']);

        $sense->refresh();
        $this->assertSame($chapter->id, $sense->source_chapter_id);
        $this->assertSame('0', (string) $sense->sentence_id);
        $this->assertSame($reviewLogBefore, ReviewLog::count(), 'source recovery must not write ReviewLog');
        $this->assertSame($cardBefore, ReviewCard::count(), 'source recovery must not create ReviewCard');
        $this->assertSame($occurrenceBefore, WordSenseOccurrence::count(), 'source recovery must not create occurrence rows');
    }

    public function test_direct_chapter_source_context_list_does_not_change_source_location_fields(): void
    {
        $sense = $this->createConfirmedSense();
        $chapter = $this->createChapterForSentence('The bureau opened at noon.');
        $occurrence = WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '0',
            'sentence_en' => 'The bureau opened at noon.',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'bureau',
            'lemma' => 'bureau',
            'pos' => 'noun',
            'decision' => 'match_existing_sense',
            'confidence' => 1.0,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ]);

        $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list')
            ->assertOk();

        $sense->refresh();
        $occurrence->refresh();
        $this->assertNull($sense->source_chapter_id);
        $this->assertNull($sense->sentence_id);
        $this->assertSame($chapter->id, $occurrence->chapter_id);
        $this->assertSame('0', (string) $occurrence->sentence_id);
    }

    private function createConfirmedSense(): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'bureau',
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

    private function createChapterForSentence(string $sentence): Chapter
    {
        $words = [];
        preg_match_all('/[A-Za-z]+|[.!?]/', $sentence, $matches);
        $parts = $matches[0] ?? [];
        foreach ($parts as $index => $part) {
            $words[] = (object) [
                'word' => $part,
                'sentence_index' => '0',
                'spaceAfter' => !in_array($part, ['.', '!', '?'], true) && $index < count($parts) - 1,
            ];
        }

        return Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 1,
            'name' => 'Recovery Chapter',
            'read_count' => 0,
            'word_count' => count($words),
            'language' => 'english',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'raw_text' => $sentence,
            'type' => 'text',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
            'processed_text' => gzcompress(json_encode($words), 1),
        ]);
    }
}
