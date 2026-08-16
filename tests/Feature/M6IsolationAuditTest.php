<?php

namespace Tests\Feature;

use App\Jobs\ProcessChapter;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ExampleSentence;
use App\Models\Phrase;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\ChapterService;
use App\Services\QueueStatsService;
use App\Services\SafeFilePathService;
use App\Services\VocabularyQueryService;
use App\Services\VocabularyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class M6IsolationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_file_resolver_accepts_only_an_existing_direct_child(): void
    {
        $base = storage_path('framework/testing/m6d-file-' . Str::uuid());
        $root = $base . DIRECTORY_SEPARATOR . 'approved';
        File::ensureDirectoryExists($root);
        File::put($root . DIRECTORY_SEPARATOR . 'valid file.txt', 'safe');
        File::put($base . DIRECTORY_SEPARATOR . 'outside.txt', 'secret');

        try {
            $files = app(SafeFilePathService::class);
            $this->assertSame(
                realpath($root . DIRECTORY_SEPARATOR . 'valid file.txt'),
                $files->resolveExistingDirectChild($root, 'valid file.txt'),
            );

            foreach ([
                '',
                '.',
                '..',
                '../outside.txt',
                '..\\outside.txt',
                'folder/file.txt',
                'folder\\file.txt',
                "nul\0.txt",
                'missing.txt',
            ] as $unsafe) {
                try {
                    $files->resolveExistingDirectChild($root, $unsafe);
                    $this->fail("Unsafe file name was accepted: {$unsafe}");
                } catch (NotFoundHttpException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_public_file_resolver_rejects_a_direct_child_symlink(): void
    {
        $base = storage_path('framework/testing/m6d-link-' . Str::uuid());
        $root = $base . DIRECTORY_SEPARATOR . 'approved';
        File::ensureDirectoryExists($root);
        File::put($base . DIRECTORY_SEPARATOR . 'outside.txt', 'secret');
        $link = $root . DIRECTORY_SEPARATOR . 'linked.txt';

        try {
            if (!@symlink($base . DIRECTORY_SEPARATOR . 'outside.txt', $link)) {
                $source = File::get(app_path('Services/SafeFilePathService.php'));
                $this->assertStringContainsString('is_link($requestedPath)', $source);
                return;
            }

            $this->expectException(NotFoundHttpException::class);
            app(SafeFilePathService::class)->resolveExistingDirectChild($root, 'linked.txt');
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            File::deleteDirectory($base);
        }
    }

    public function test_existing_manual_file_still_renders_through_the_authenticated_route(): void
    {
        $user = $this->user('m6d-manual@example.test');

        $this->actingAs($user)
            ->get('/manual/get-manual-file/Home')
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8');
    }

    public function test_encoded_backslash_traversal_is_rejected_by_every_public_file_route(): void
    {
        $user = $this->user('m6d-file-routes@example.test');

        foreach ([
            '/manual/get-manual-file/..%5CHome',
            '/fonts/file/..%5Coutside.ttf',
            '/images/kanji/..%5Coutside.svg',
            '/images/book_images/..%5Coutside.png',
        ] as $path) {
            $this->actingAs($user)->get($path)->assertStatus(404);
        }
    }

    public function test_book_routes_reject_cross_user_and_cross_language_resources_without_mutation(): void
    {
        $user = $this->user('m6d-book@example.test');
        $other = $this->user('m6d-book-other@example.test');
        $otherUserBook = $this->book($other, 'english', 'other user');
        $otherLanguageBook = $this->book($user, 'japanese', 'other language');

        foreach ([$otherUserBook, $otherLanguageBook] as $book) {
            $this->actingAs($user)
                ->post('/books/update', [
                    'bookId' => $book->id,
                    'bookName' => 'tampered',
                ])
                ->assertStatus(500);
            $this->assertSame(
                $book->name,
                Book::query()->findOrFail($book->id)->name,
            );

            $this->actingAs($user)
                ->get('/books/get-word-counts/' . $book->id)
                ->assertStatus(500);
        }
    }

    public function test_chapter_routes_reject_cross_user_and_cross_language_resources_without_mutation(): void
    {
        Queue::fake();
        $user = $this->user('m6d-chapter@example.test');
        $other = $this->user('m6d-chapter-other@example.test');
        $otherUserChapter = $this->chapter(
            $other,
            $this->book($other, 'english', 'other user'),
            'english',
            'other user chapter',
        );
        $otherLanguageChapter = $this->chapter(
            $user,
            $this->book($user, 'japanese', 'other language'),
            'japanese',
            'other language chapter',
        );

        foreach ([$otherUserChapter, $otherLanguageChapter] as $chapter) {
            $this->actingAs($user)
                ->post('/chapters/get/editor', ['chapterId' => $chapter->id])
                ->assertStatus(500);

            $this->actingAs($user)
                ->post('/chapters/update', [
                    'chapterId' => $chapter->id,
                    'chapterName' => 'tampered',
                    'chapterText' => 'tampered',
                    'sourceRevision' => app(\App\Services\ReadingChapterTextService::class)->sourceRevision($chapter),
                ])
                ->assertStatus(500);

            $fresh = Chapter::query()->findOrFail($chapter->id);
            $this->assertSame($chapter->name, $fresh->name);
            $this->assertSame($chapter->raw_text, $fresh->raw_text);
        }

        Queue::assertNothingPushed();
    }

    public function test_vocabulary_routes_reject_cross_user_and_cross_language_resources_without_mutation(): void
    {
        $user = $this->user('m6d-vocabulary@example.test');
        $other = $this->user('m6d-vocabulary-other@example.test');
        $otherUserWord = $this->word($other, 'english', 'other-user-secret');
        $otherLanguageWord = $this->word($user, 'japanese', 'other-language-secret');
        $otherUserPhrase = $this->phrase($other, 'english', 'other user phrase');
        $otherLanguagePhrase = $this->phrase($user, 'japanese', 'other language phrase');

        foreach ([$otherUserWord, $otherLanguageWord] as $word) {
            $this->actingAs($user)
                ->get('/vocabulary/words/get/' . $word->id)
                ->assertStatus(500);
            $this->actingAs($user)
                ->post('/vocabulary/word/update', [
                    'id' => $word->id,
                    'translation' => 'tampered',
                ])
                ->assertStatus(500);
            $this->assertSame('', EncounteredWord::query()->findOrFail($word->id)->translation);
        }

        foreach ([$otherUserPhrase, $otherLanguagePhrase] as $phrase) {
            $this->actingAs($user)
                ->get('/vocabulary/phrases/get/' . $phrase->id)
                ->assertStatus(500);
            $this->actingAs($user)
                ->post('/vocabulary/phrases/update', [
                    'id' => $phrase->id,
                    'translation' => 'tampered',
                ])
                ->assertStatus(500);
            $this->assertSame('', Phrase::query()->findOrFail($phrase->id)->translation);
        }
    }

    public function test_vocabulary_bridge_rejects_an_unowned_source_chapter_before_learning_writes(): void
    {
        $user = $this->user('m6d-bridge@example.test');
        $other = $this->user('m6d-bridge-other@example.test');
        $word = $this->word($user, 'english', 'bridge');
        $chapter = $this->chapter(
            $other,
            $this->book($other, 'english', 'other source'),
            'english',
            'other source chapter',
        );

        app(VocabularyService::class)->updateWord(
            $user->id,
            'english',
            $word->id,
            ['translation' => '桥'],
            -1,
            [
                'chapter_id' => $chapter->id,
                'sentence_index' => 0,
                'word' => 'bridge',
                'translation' => '桥',
            ],
        );

        $this->assertSame(0, WordSense::where('user_id', $user->id)->count());
        $this->assertSame(0, WordSenseOccurrence::where('user_id', $user->id)->count());
    }

    public function test_example_sentence_target_must_belong_to_selected_language(): void
    {
        $user = $this->user('m6d-example@example.test');
        $word = $this->word($user, 'japanese', '別');

        try {
            app(VocabularyService::class)->createOrUpdateExampleSentence(
                $user->id,
                'english',
                'word',
                $word->id,
                [],
            );
            $this->fail('Cross-language example target was accepted.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('selected language', $exception->getMessage());
        }

        $this->assertSame(0, ExampleSentence::where('user_id', $user->id)->count());
    }

    public function test_process_chapter_rejects_a_mismatched_language_before_calling_services(): void
    {
        $user = $this->user('m6d-job@example.test');
        $chapter = $this->chapter(
            $user,
            $this->book($user, 'english', 'job book'),
            'english',
            'job chapter',
        );
        $chapterBefore = $chapter->only([
            'user_id',
            'book_id',
            'language',
            'name',
            'raw_text',
            'processed_text',
            'processing_status',
            'word_count',
        ]);
        $vocabulary = Mockery::mock(VocabularyService::class);
        $chapters = Mockery::mock(ChapterService::class);
        $stats = Mockery::mock(QueueStatsService::class);
        $vocabulary->shouldNotReceive('indexPhraseInChapter');
        $chapters->shouldNotReceive('processChapterText');
        $stats->shouldNotReceive('insertChapterProcessedStat');

        try {
            (new ProcessChapter($user->id, $user->uuid, $chapter->id, 'japanese'))
                ->handle($vocabulary, $chapters, $stats);
            $this->fail('Mismatched queued language was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('scope does not match', $exception->getMessage());
        }

        $this->assertSame($chapterBefore, $chapter->fresh()->only(array_keys($chapterBefore)));
    }

    public function test_vocabulary_export_is_bounded_to_user_and_language(): void
    {
        $user = $this->user('m6d-export@example.test');
        $other = $this->user('m6d-export-other@example.test');
        $this->word($user, 'english', 'own-visible');
        $this->word($user, 'japanese', 'other-language-secret');
        $this->word($other, 'english', 'other-user-secret');
        $fields = [
            ['export' => true, 'headerName' => 'Word', 'searchObjectProperty' => 'word'],
        ];

        $csv = app(VocabularyQueryService::class)->exportToCsv(
            $user->id,
            'english',
            'anytext',
            -1,
            -1,
            -999,
            'only words',
            'words',
            'any',
            $fields,
            [],
        )->toString();

        $this->assertStringContainsString('own-visible', $csv);
        $this->assertStringNotContainsString('other-language-secret', $csv);
        $this->assertStringNotContainsString('other-user-secret', $csv);
    }

    private function user(string $email): User
    {
        return User::forceCreate([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function book(User $user, string $language, string $name): Book
    {
        return Book::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'name' => $name,
            'word_count' => 0,
        ]);
    }

    private function chapter(
        User $user,
        Book $book,
        string $language,
        string $name
    ): Chapter {
        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'language' => $language,
            'name' => $name,
            'read_count' => 0,
            'word_count' => 1,
            'raw_text' => 'Original text.',
            'unique_words' => '["original"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([
                ['word' => 'Original', 'sentence_index' => 0],
            ]), 1),
            'type' => 'text',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function word(User $user, string $language, string $word): EncounteredWord
    {
        return EncounteredWord::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'stage' => 2,
            'word' => $word,
            'lemma' => $word,
            'kanji' => '',
            'reading' => '',
            'base_word' => '',
            'base_word_reading' => '',
            'translation' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'relearning' => false,
        ]);
    }

    private function phrase(User $user, string $language, string $words): Phrase
    {
        return Phrase::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'words' => json_encode([$words]),
            'words_searchable' => $words,
            'reading' => '',
            'stage' => 2,
            'translation' => '',
            'lookup_count' => 0,
        ]);
    }
}
