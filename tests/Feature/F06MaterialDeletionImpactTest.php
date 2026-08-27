<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReadingProgress;
use App\Models\ReadingSession;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class F06MaterialDeletionImpactTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_reports_retained_history_without_writing(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['user'])->postJson('/books/delete', [
            'bookId' => $fixture['book']->id,
            'mode' => 'preview',
        ])->assertOk()->assertExactJson([
            'book_name' => 'F06 material',
            'chapter_count' => 2,
            'source_occurrence_count' => 1,
            'word_sense_count' => 1,
            'review_card_count' => 1,
            'review_log_count' => 1,
            'reading_session_count' => 1,
        ]);

        $this->assertDatabaseHas('books', ['id' => $fixture['book']->id]);
        $this->assertDatabaseCount('chapters', 2);
        $this->assertDatabaseHas('word_sense_occurrences', ['id' => $fixture['occurrence']->id]);
    }

    public function test_delete_requires_impact_confirmation(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['user'])->postJson('/books/delete', [
            'bookId' => $fixture['book']->id,
            'mode' => 'delete',
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmImpact');

        $this->assertDatabaseHas('books', ['id' => $fixture['book']->id]);
        $this->assertDatabaseCount('chapters', 2);
    }

    public function test_confirmed_delete_removes_material_and_retains_learning_history(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['user'])->postJson('/books/delete', [
            'bookId' => $fixture['book']->id,
            'mode' => 'delete',
            'confirmImpact' => true,
        ])->assertOk();

        $this->assertDatabaseMissing('books', ['id' => $fixture['book']->id]);
        $this->assertDatabaseMissing('chapters', ['book_id' => $fixture['book']->id]);
        $this->assertDatabaseMissing('reading_progress', ['chapter_id' => $fixture['chapter']->id]);
        $this->assertDatabaseMissing('reading_progress', ['chapter_id' => $fixture['secondChapter']->id]);
        $this->assertDatabaseHas('word_sense_occurrences', ['id' => $fixture['occurrence']->id]);
        $this->assertDatabaseHas('word_senses', ['id' => $fixture['sense']->id]);
        $this->assertDatabaseHas('review_cards', ['id' => $fixture['card']->id]);
        $this->assertDatabaseHas('review_logs', ['id' => $fixture['log']->id]);
        $this->assertDatabaseHas('reading_sessions', ['id' => $fixture['session']->id]);
    }

    public function test_single_chapter_delete_removes_only_its_reading_progress_and_retains_history(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['user'])->postJson('/chapters/delete', [
            'chapterId' => $fixture['chapter']->id,
        ])->assertOk();

        $this->assertDatabaseMissing('chapters', ['id' => $fixture['chapter']->id]);
        $this->assertDatabaseHas('chapters', ['id' => $fixture['secondChapter']->id]);
        $this->assertDatabaseMissing('reading_progress', ['id' => $fixture['firstProgress']->id]);
        $this->assertDatabaseHas('reading_progress', ['id' => $fixture['secondProgress']->id]);
        $this->assertDatabaseHas('word_sense_occurrences', ['id' => $fixture['occurrence']->id]);
        $this->assertDatabaseHas('word_senses', ['id' => $fixture['sense']->id]);
        $this->assertDatabaseHas('review_cards', ['id' => $fixture['card']->id]);
        $this->assertDatabaseHas('review_logs', ['id' => $fixture['log']->id]);
        $this->assertDatabaseHas('reading_sessions', ['id' => $fixture['session']->id]);
    }

    public function test_other_user_cannot_preview_or_delete_material(): void
    {
        $fixture = $this->fixture();
        $other = $this->user('F06 other');

        $this->actingAs($other)->postJson('/books/delete', [
            'bookId' => $fixture['book']->id,
            'mode' => 'preview',
        ])->assertServerError();
        $this->actingAs($other)->postJson('/books/delete', [
            'bookId' => $fixture['book']->id,
            'mode' => 'delete',
            'confirmImpact' => true,
        ])->assertServerError();

        $this->assertDatabaseHas('books', ['id' => $fixture['book']->id]);
        $this->assertDatabaseCount('chapters', 2);
    }

    private function fixture(): array
    {
        $user = $this->user('F06 owner');
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'name' => 'F06 material',
            'word_count' => 4,
        ]);
        $chapter = $this->chapter($user, $book, 'Chapter one');
        $secondChapter = $this->chapter($user, $book, 'Chapter two');
        $firstProgress = $this->progress($user, $chapter, 'f06-revision-one');
        $secondProgress = $this->progress($user, $secondChapter, 'f06-revision-two');
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'retain',
            'surface_form' => 'retain',
            'sense_key' => 'f06-' . Str::uuid(),
            'sense_zh' => '保留',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $card = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now(),
            'fsrs_stability' => 4,
            'fsrs_difficulty' => 5,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);
        $occurrence = WordSenseOccurrence::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'review_card_id' => $card->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '0',
            'sentence_en' => 'Retain the learning history.',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'retain',
            'lemma' => 'retain',
            'decision' => 'match_existing_sense',
            'confidence' => 1,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
        ]);
        $log = ReviewLog::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => now(),
            'previous_state' => 'learning',
            'new_state' => 'review',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);
        $session = ReadingSession::forceCreate([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'language_id' => 'english',
            'chapter_id' => $chapter->id,
            'source_revision' => 'sha256:' . str_repeat('a', 64),
            'status' => ReadingSession::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        return compact(
            'user',
            'book',
            'chapter',
            'secondChapter',
            'firstProgress',
            'secondProgress',
            'sense',
            'card',
            'occurrence',
            'log',
            'session',
        );
    }

    private function progress(User $user, Chapter $chapter, string $sourceRevision): ReadingProgress
    {
        return ReadingProgress::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'chapter_id' => $chapter->id,
            'source_revision' => $sourceRevision,
            'canonical_token_index' => 0,
            'furthest_canonical_token_index' => 0,
            'position_occurred_at' => now(),
        ]);
    }

    private function user(string $name): User
    {
        return User::forceCreate([
            'name' => $name,
            'email' => 'f06-' . Str::uuid() . '@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function chapter(User $user, Book $book, string $name): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'language' => 'english',
            'name' => $name,
            'type' => 'text',
            'raw_text' => 'Retain the learning history.',
            'processed_text' => gzcompress(json_encode([]), 1),
            'processing_status' => 'processed',
            'word_count' => 2,
            'read_count' => 0,
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'subtitle_timestamps' => '[]',
        ]);
    }
}
