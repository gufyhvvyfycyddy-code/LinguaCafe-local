<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_chapter_options_and_session_query_share_one_eligible_chapter_match_builder(): void
    {
        $this->assertTrue(
            method_exists(\App\Services\CustomStudy\Queries\SourceChapterQuery::class, 'eligibleChapterMatches'),
            'Chapter options and source_chapter sessions must share one eligible chapter match builder.',
        );

        $serviceSource = file_get_contents(
            app_path('Services/CustomStudy/CustomStudyChapterOptionsService.php'),
        );
        $this->assertStringContainsString('eligibleChapterMatches', $serviceSource);
    }

    #[DataProvider('chapterScaleProvider')]
    public function test_query_budget_remains_constant_for_large_chapter_sets(int $chapterCount): void
    {
        $book = Book::forceCreate(['user_id' => $this->user->id, 'name' => 'Scale book', 'language' => 'english']);
        for ($index = 0; $index < $chapterCount; $index++) {
            $chapter = $this->chapter($this->user, $book, sprintf('Scale chapter %03d', $index));
            $sense = $this->sense(['source_chapter_id' => $chapter->id]);
            $this->card($sense);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($this->user)->getJson('/custom-study/chapter-options');
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertCount($chapterCount, $response->json('items'));
        $this->assertLessThanOrEqual(2, $queries->count(), 'Total option-query budget must be constant.');
        foreach (['chapters', 'books', 'review_cards', 'word_sense_occurrences'] as $table) {
            $this->assertLessThanOrEqual(
                1,
                $queries->filter(fn (array $query) => str_contains(strtolower($query['query'] ?? ''), $table))->count(),
                "{$table} must not be loaded with an N+1 query.",
            );
        }
        $this->assertTrue($queries->every(
            fn (array $query) => str_starts_with(ltrim(strtolower($query['query'] ?? '')), 'select'),
        ), 'Chapter options must stay read-only.');
    }

    public static function chapterScaleProvider(): array
    {
        return [[1], [100], [500]];
    }

    public function test_card_limit_never_changes_the_full_candidate_count(): void
    {
        $book = Book::forceCreate(['user_id' => $this->user->id, 'name' => 'Limit book', 'language' => 'english']);
        $chapter = $this->chapter($this->user, $book, 'Limit chapter');
        for ($index = 0; $index < 3; $index++) {
            $this->card($this->sense(['source_chapter_id' => $chapter->id]));
        }

        $small = $this->actingAs($this->user)->getJson('/custom-study/chapter-options?card_limit=1');
        $large = $this->actingAs($this->user)->getJson('/custom-study/chapter-options?card_limit=500');

        $this->assertSame(3, $small->json('items.0.candidate_count'));
        $this->assertSame(3, $large->json('items.0.candidate_count'));
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

    private function card(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
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
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ], $overrides));
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
