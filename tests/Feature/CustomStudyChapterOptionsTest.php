<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomStudyChapterOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->user('chapter-options@example.com');
        $this->otherUser = $this->user('chapter-options-other@example.com');
    }

    public function test_returns_only_owned_eligible_chapters_with_full_distinct_candidate_counts(): void
    {
        $book = Book::forceCreate(['user_id' => $this->user->id, 'name' => 'Owned book', 'language' => 'english']);
        $directChapter = $this->chapter($this->user, $book, 'Direct chapter');
        $occurrenceChapter = $this->chapter($this->user, $book, 'Occurrence chapter');
        $emptyChapter = $this->chapter($this->user, $book, 'Empty chapter');
        $otherChapter = $this->chapter($this->otherUser, null, 'Other chapter');

        $directSense = $this->sense(['source_chapter_id' => $directChapter->id]);
        $directCard = $this->card($directSense);
        $occurrenceSense = $this->sense();
        $occurrenceCard = $this->card($occurrenceSense);
        $this->occurrence($occurrenceSense, $occurrenceChapter);
        $this->occurrence($occurrenceSense, $occurrenceChapter);

        $bothSense = $this->sense(['source_chapter_id' => $directChapter->id]);
        $bothCard = $this->card($bothSense);
        $this->occurrence($bothSense, $directChapter);

        $foreignSense = $this->sense(['source_chapter_id' => $otherChapter->id]);
        $this->card($foreignSense);

        $response = $this->actingAs($this->user)->getJson('/custom-study/chapter-options');

        $response->assertOk()->assertExactJson([
            'items' => [
                [
                    'chapter_id' => $directChapter->id,
                    'chapter_name' => 'Direct chapter',
                    'book_id' => $book->id,
                    'book_name' => 'Owned book',
                    'candidate_count' => 2,
                ],
                [
                    'chapter_id' => $occurrenceChapter->id,
                    'chapter_name' => 'Occurrence chapter',
                    'book_id' => $book->id,
                    'book_name' => 'Owned book',
                    'candidate_count' => 1,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('chapters', ['id' => $emptyChapter->id, 'name' => 'mutated']);
        $this->assertSame($directCard->id, $directCard->fresh()->id);
        $this->assertSame($occurrenceCard->id, $occurrenceCard->fresh()->id);
        $this->assertSame($bothCard->id, $bothCard->fresh()->id);
    }

    private function user(string $email): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function chapter(User $user, ?Book $book, string $name): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book?->id ?? 0,
            'name' => $name,
            'language' => 'english',
            'raw_text' => '',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress('{}', 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
            'read_count' => 0,
            'word_count' => 0,
        ]);
    }

    private function sense(array $overrides = []): WordSense
    {
        return WordSense::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'word-' . Str::random(8),
            'surface_form' => 'word',
            'pos' => 'noun',
            'sense_zh' => '释义',
            'sense_en' => 'meaning',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'An example sentence.',
            'status' => WordSense::STATUS_CONFIRMED,
            'sense_key' => hash('sha256', Str::random()),
        ], $overrides));
    }

    private function card(WordSense $sense): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 1,
            'fsrs_difficulty' => 5,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
        ]);
    }

    private function occurrence(WordSense $sense, Chapter $chapter): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate([
            'word_sense_id' => $sense->id,
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'chapter_id' => $chapter->id,
            'sentence_id' => 0,
            'sentence_en' => 'Occurrence sentence.',
            'surface' => 'word',
            'lemma' => 'word',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => 'test',
            'decision' => 'manual',
            'confidence' => 1,
            'auto_fsrs_allowed' => false,
        ]);
    }
}
