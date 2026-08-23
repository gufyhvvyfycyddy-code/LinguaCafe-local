<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReadingProgress;
use App\Models\User;
use App\Services\ReadingChapterTextService;
use App\Services\ReadingContinuityService;
use App\Services\BookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadingContinuityLibraryProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Book $book;
    private Chapter $longChapter;
    private Chapter $shortChapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'G06D Library Progress',
            'email' => 'g06d-library-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'G06D Progress Material',
            'language' => 'english',
        ]);
        $this->longChapter = $this->createChapter('Four tokens', [
            $this->token(10, 'one'),
            $this->token(20, 'two'),
            $this->token(25, 'NEWLINE', true),
            $this->token(30, 'three'),
            $this->token(40, 'four'),
        ]);
        $this->shortChapter = $this->createChapter('Two tokens', [
            $this->token(100, 'one'),
            $this->token(200, 'two'),
        ]);
        $this->createChapter('No positionable tokens', [
            $this->token(500, 'NEWLINE', true),
        ]);
    }

    public function test_library_projects_current_furthest_and_token_weighted_material_progress(): void
    {
        $continuity = app(ReadingContinuityService::class);
        $revision = app(ReadingChapterTextService::class)->sourceRevision($this->longChapter);
        $continuity->saveWebPosition($this->user->id, 'english', $this->longChapter->id, $revision, 30);
        $continuity->saveWebPosition($this->user->id, 'english', $this->longChapter->id, $revision, 20);

        $this->actingAs($this->user)->postJson('/books')
            ->assertOk()
            ->assertJsonPath('0.readingProgress.available', true)
            ->assertJsonPath('0.readingProgress.reachedTokens', 3)
            ->assertJsonPath('0.readingProgress.totalTokens', 6)
            ->assertJsonPath('0.readingProgress.percentage', fn ($value) => (float) $value === 50.0);

        $response = $this->postJson('/chapters', ['bookId' => $this->book->id])
            ->assertOk()
            ->assertJsonMissingPath('chapters.0.raw_text')
            ->assertJsonMissingPath('chapters.0.processed_text');

        $chapters = collect($response->json('chapters'))->keyBy('id');
        $longProgress = $chapters[$this->longChapter->id]['readingProgress'];
        $this->assertTrue($longProgress['available']);
        $this->assertSame(75.0, (float) $longProgress['percentage']);
        $this->assertSame(3, $longProgress['reachedTokens']);
        $this->assertSame(4, $longProgress['totalTokens']);

        $shortProgress = $chapters[$this->shortChapter->id]['readingProgress'];
        $this->assertTrue($shortProgress['available']);
        $this->assertSame(0.0, (float) $shortProgress['percentage']);
        $this->assertSame(0, $shortProgress['reachedTokens']);
        $this->assertSame(2, $shortProgress['totalTokens']);
        $this->assertSame([
            'available' => false,
            'percentage' => null,
            'reachedTokens' => 0,
            'totalTokens' => 0,
        ], $chapters->firstWhere('name', 'No positionable tokens')['readingProgress']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(BookService::class)->getBooks($this->user->id, 'english');
        $progressQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'reading_progress'));
        DB::disableQueryLog();
        $this->assertCount(1, $progressQueries, 'Library material projection must batch progress rows in one query.');
    }

    public function test_old_revision_history_is_preserved_but_never_contributes_to_current_library_progress(): void
    {
        $continuity = app(ReadingContinuityService::class);
        $oldRevision = app(ReadingChapterTextService::class)->sourceRevision($this->longChapter);
        $continuity->saveWebPosition($this->user->id, 'english', $this->longChapter->id, $oldRevision, 40);

        $this->longChapter->forceFill(['raw_text' => 'Four tokens revised'])->save();
        $newRevision = app(ReadingChapterTextService::class)->sourceRevision($this->longChapter->fresh());
        $this->assertNotSame($oldRevision, $newRevision);

        $this->actingAs($this->user)->postJson('/books')
            ->assertOk()
            ->assertJsonPath('0.readingProgress.reachedTokens', 0)
            ->assertJsonPath('0.readingProgress.totalTokens', 6)
            ->assertJsonPath('0.readingProgress.percentage', fn ($value) => (float) $value === 0.0);

        $stored = ReadingProgress::query()
            ->where('user_id', $this->user->id)
            ->where('language_id', 'english')
            ->where('chapter_id', $this->longChapter->id)
            ->where('source_revision', $oldRevision)
            ->firstOrFail();
        $this->assertSame(40, $stored->canonical_token_index);
        $this->assertSame(40, $stored->furthest_canonical_token_index);
        $this->assertFalse(ReadingProgress::query()
            ->where('chapter_id', $this->longChapter->id)
            ->where('source_revision', $newRevision)
            ->exists());
    }

    private function createChapter(string $name, array $tokens): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'name' => $name,
            'language' => 'english',
            'raw_text' => $name,
            'word_count' => count($tokens),
            'read_count' => 0,
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($tokens), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function token(int $wordIndex, string $word, bool $structure = false): array
    {
        return [
            'word_index' => $wordIndex,
            'word' => $word,
            'lemma' => $structure ? '' : $word,
            'pos' => $structure ? 'STRUCT' : 'NOUN',
            'is_structure' => $structure,
            'sentence_index' => 0,
            'spaceAfter' => true,
        ];
    }
}
