<?php

namespace Tests\Feature;

use App\Jobs\ProcessChapter;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\ChapterService;
use App\Services\ReadingChapterTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class F04MaterialVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_requires_current_source_revision_and_rejects_stale_updates(): void
    {
        Queue::fake();
        [$user, $chapter] = $this->chapter();

        $editor = $this->actingAs($user)->postJson('/chapters/get/editor', [
            'chapterId' => $chapter->id,
        ])->assertOk()
            ->assertJsonMissingPath('processed_text')
            ->assertJsonMissingPath('id');
        $revision = $editor->json('source_revision');
        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $revision);

        $this->actingAs($user)->postJson('/chapters/update', [
            'chapterId' => $chapter->id,
            'chapterName' => 'First update',
            'chapterText' => 'The first editor wins.',
            'sourceRevision' => $revision,
        ])->assertOk();

        $this->actingAs($user)->postJson('/chapters/update', [
            'chapterId' => $chapter->id,
            'chapterName' => 'Stale update',
            'chapterText' => 'The stale editor must not overwrite.',
            'sourceRevision' => $revision,
        ])->assertConflict()
            ->assertJsonPath('error.code', ChapterService::ERROR_SOURCE_REVISION_CONFLICT);

        $chapter->refresh();
        $this->assertSame('First update', $chapter->name);
        $this->assertSame('The first editor wins.', $chapter->raw_text);
        $this->assertNotSame($revision, app(ReadingChapterTextService::class)->sourceRevision($chapter));
        Queue::assertPushed(ProcessChapter::class, 1);
    }

    public function test_article_update_preserves_existing_occurrence_source_snapshot(): void
    {
        Queue::fake();
        [$user, $chapter] = $this->chapter();
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'stable',
            'surface_form' => 'stable',
            'sense_key' => 'f04-' . Str::uuid(),
            'sense_zh' => '稳定的',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $occurrence = WordSenseOccurrence::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '0',
            'sentence_hash' => hash('sha256', 'A stable source sentence.'),
            'sentence_en' => 'A stable source sentence.',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'stable',
            'lemma' => 'stable',
            'decision' => 'match_existing_sense',
            'confidence' => 1,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
        ]);
        $snapshot = $occurrence->only([
            'word_sense_id', 'chapter_id', 'sentence_id', 'sentence_hash', 'sentence_en', 'surface', 'lemma', 'status',
        ]);
        $revision = app(ReadingChapterTextService::class)->sourceRevision($chapter);

        $this->actingAs($user)->postJson('/chapters/update', [
            'chapterId' => $chapter->id,
            'chapterName' => 'Revised article',
            'chapterText' => 'New article text.',
            'sourceRevision' => $revision,
        ])->assertOk();

        $this->assertSame($snapshot, $occurrence->fresh()->only(array_keys($snapshot)));
        $this->assertDatabaseHas('word_senses', ['id' => $sense->id, 'status' => WordSense::STATUS_CONFIRMED]);
    }

    public function test_update_without_source_revision_fails_closed(): void
    {
        Queue::fake();
        [$user, $chapter] = $this->chapter();

        $this->actingAs($user)->postJson('/chapters/update', [
            'chapterId' => $chapter->id,
            'chapterName' => 'Unversioned update',
            'chapterText' => 'This must be rejected.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('sourceRevision');

        $this->assertSame('Original article', $chapter->fresh()->raw_text);
        Queue::assertNothingPushed();
    }

    /** @return array{User, Chapter} */
    private function chapter(): array
    {
        $user = User::forceCreate([
            'name' => 'F04 user',
            'email' => 'f04-' . Str::uuid() . '@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'name' => 'F04 book',
            'word_count' => 2,
        ]);
        $chapter = Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'language' => 'english',
            'name' => 'Original chapter',
            'type' => 'text',
            'raw_text' => 'Original article',
            'processed_text' => gzcompress(json_encode([]), 1),
            'processing_status' => 'processed',
            'word_count' => 2,
            'read_count' => 0,
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'subtitle_timestamps' => '[]',
        ]);

        return [$user, $chapter];
    }
}
