<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class H10IosReaderBindingTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::forceCreate([
            'name' => 'reviewIntervals',
            'value' => json_encode([
                '-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3],
                '-3' => [7], '-2' => [15], '-1' => [30],
            ]),
        ]);
        $this->user = $this->createUser('h10-reader@example.test', 'english');
    }

    public function test_mobile_reader_manual_sense_reuses_canonical_task_marker_source(): void
    {
        [$token] = $this->issueToken($this->user);
        $chapter = $this->createChapter($this->user, 'The bank reopened.', [
            $this->token(0, 'The', 'the', 'determiner', true),
            $this->token(1, 'bank', 'bank', 'noun', true),
            $this->token(2, 'reopened.', 'reopen', 'verb', false),
        ]);

        $session = $this->withToken($token)
            ->postJson("/api/v1/mobile/chapters/{$chapter->id}/reading-sessions", [])
            ->assertOk();
        $sourceRevision = $session->json('data.source_revision');

        $marker = $this->withToken($token)
            ->postJson("/api/v1/mobile/chapters/{$chapter->id}/reading-unfamiliar-targets", [
                'kind' => 'word',
                'start_word_index' => 1,
                'end_word_index' => 1,
                'source_revision' => $sourceRevision,
            ])
            ->assertCreated()
            ->assertJsonPath('data.target.source_revision', $sourceRevision);

        $response = $this->withToken($token)
            ->postJson('/api/v1/mobile/word-senses', [
                'lemma' => 'bank',
                'surface_form' => 'bank',
                'pos' => 'noun',
                'sense_zh' => '银行',
                'sense_en' => 'financial institution',
                'chapter_id' => $chapter->id,
                'sentence_id' => 'client-forged-id',
                'sentence_en' => 'Client forged sentence.',
                'sentence_zh' => '客户端伪造翻译',
                'reading_session_id' => $session->json('data.reading_session_id'),
                'source_revision' => $sourceRevision,
                'occurrence_id' => $marker->json('data.target.occurrence_id'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.word_sense.lemma', 'bank');

        $sense = WordSense::findOrFail($response->json('data.word_sense.sense_id'));
        $source = WordSenseOccurrence::query()
            ->where('user_id', $this->user->id)
            ->where('language_id', 'english')
            ->where('source', WordSenseOccurrence::SOURCE_READING_OCCURRENCE)
            ->sole();

        $this->assertSame($sense->id, $source->word_sense_id);
        $this->assertSame('The bank reopened.', $source->sentence_en);
        $this->assertSame('The bank reopened.', $sense->example_sentence_en);
        $this->assertNull($sense->example_sentence_zh);
        $this->assertSame(WordSense::LEARNING_ORIGIN_READING, $sense->learning_started_origin);
        $this->assertSame($source->id, $sense->learning_started_source_occurrence_id);
        $this->assertSame(1, WordSenseOccurrence::where('source', WordSenseOccurrence::SOURCE_READING_OCCURRENCE)->count());
        $this->assertSame(0, WordSenseOccurrence::where('source', WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD)->count());
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_mobile_task_marker_rejects_stale_article_revision(): void
    {
        [$token] = $this->issueToken($this->user);
        $chapter = $this->createChapter($this->user, 'bank', [
            $this->token(0, 'bank', 'bank', 'noun', false),
        ]);
        $session = $this->withToken($token)
            ->postJson("/api/v1/mobile/chapters/{$chapter->id}/reading-sessions", [])
            ->assertOk();
        $oldRevision = $session->json('data.source_revision');

        $chapter->raw_text = 'bank changed';
        $chapter->save();

        $this->withToken($token)
            ->postJson("/api/v1/mobile/chapters/{$chapter->id}/reading-unfamiliar-targets", [
                'kind' => 'word',
                'start_word_index' => 0,
                'end_word_index' => 0,
                'source_revision' => $oldRevision,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'READING_TARGET_STALE_SOURCE');

        $this->assertDatabaseMissing('reading_unfamiliar_targets', [
            'user_id' => $this->user->id,
            'chapter_id' => $chapter->id,
        ]);
    }

    public function test_mobile_manual_sense_rejects_session_after_article_revision_changes(): void
    {
        [$token] = $this->issueToken($this->user);
        $chapter = $this->createChapter($this->user, 'bank', [
            $this->token(0, 'bank', 'bank', 'noun', false),
        ]);
        $session = $this->withToken($token)
            ->postJson("/api/v1/mobile/chapters/{$chapter->id}/reading-sessions", [])
            ->assertOk();
        $marker = $this->withToken($token)
            ->postJson("/api/v1/mobile/chapters/{$chapter->id}/reading-unfamiliar-targets", [
                'kind' => 'word',
                'start_word_index' => 0,
                'end_word_index' => 0,
                'source_revision' => $session->json('data.source_revision'),
            ])
            ->assertCreated();

        $chapter->raw_text = 'bank changed';
        $chapter->save();

        $this->withToken($token)
            ->postJson('/api/v1/mobile/word-senses', [
                'lemma' => 'bank',
                'surface_form' => 'bank',
                'pos' => 'noun',
                'sense_zh' => '银行',
                'chapter_id' => $chapter->id,
                'reading_session_id' => $session->json('data.reading_session_id'),
                'source_revision' => $session->json('data.source_revision'),
                'occurrence_id' => $marker->json('data.target.occurrence_id'),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'READING_SESSION_STALE_SOURCE');

        $this->assertSame(0, WordSense::where('lemma', 'bank')->count());
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_mobile_task_marker_rejects_another_users_chapter(): void
    {
        [$token] = $this->issueToken($this->user);
        $other = $this->createUser('h10-reader-other@example.test', 'english');
        $chapter = $this->createChapter($other, 'private', [
            $this->token(0, 'private', 'private', 'adjective', false),
        ]);
        $sourceRevision = app(\App\Services\ReadingChapterTextService::class)->sourceRevision($chapter);

        $this->withToken($token)
            ->postJson("/api/v1/mobile/chapters/{$chapter->id}/reading-unfamiliar-targets", [
                'kind' => 'word',
                'start_word_index' => 0,
                'end_word_index' => 0,
                'source_revision' => $sourceRevision,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ARTICLE_PACKAGE_NOT_FOUND');
    }

    private function issueToken(User $user): array
    {
        $deviceUuid = (string) Str::uuid();
        $response = $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => $deviceUuid,
            'platform' => 'ios',
            'device_name' => 'H10 iOS Reader test',
            'app_version' => '1.0.0',
        ])->assertCreated();

        return [$response->json('data.token'), $deviceUuid];
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function createChapter(User $user, string $rawText, array $tokens): Chapter
    {
        $book = Book::forceCreate([
            'name' => 'H10 Reader '.Str::uuid(),
            'language' => $user->selected_language,
            'user_id' => $user->id,
        ]);

        return Chapter::forceCreate([
            'name' => 'H10 Reader chapter',
            'language' => $user->selected_language,
            'user_id' => $user->id,
            'book_id' => $book->id,
            'read_count' => 0,
            'word_count' => count($tokens),
            'raw_text' => $rawText,
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($tokens, JSON_THROW_ON_ERROR), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function token(
        int $wordIndex,
        string $word,
        string $lemma,
        string $pos,
        bool $spaceAfter,
    ): object {
        return (object) [
            'word_index' => $wordIndex,
            'word' => $word,
            'lemma' => $lemma,
            'pos' => $pos,
            'sentence_index' => 0,
            'section_index' => 0,
            'spaceAfter' => $spaceAfter,
            'is_structure' => false,
        ];
    }
}
