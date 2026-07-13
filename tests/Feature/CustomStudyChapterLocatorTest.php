<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use App\Services\CustomStudy\ChapterLocatorInterface;
use App\Services\CustomStudy\CustomStudyCriteriaValidator;
use App\Services\CustomStudy\EloquentChapterLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CustomStudyChapterLocatorTest — Task 2000-18 / Phase 2B
 *
 * Verifies the production EloquentChapterLocator:
 *   1. Owner + language match → true.
 *   2. Other user → false.
 *   3. Other language → false.
 *   4. Non-existent chapter → false.
 *   5. Container resolves to EloquentChapterLocator.
 *   6. Container resolves CustomStudyCriteriaValidator (locator injected).
 *   7. Validator accepts owned chapter.
 *   8. Validator throws chapter_id/chapter_not_owned for cross-user chapter.
 *   9. Validator throws chapter_id/chapter_not_owned for cross-language chapter.
 *  10. Single exists() query per check.
 *  11. Does NOT write Chapter.
 *  12. Does NOT write other learning data.
 *
 * Uses RefreshDatabase. The chapter creation requires a Book parent because
 * `chapters.book_id` is part of the table schema (NOT NULL in production
 * migrations; we pass 0 / a real book depending on what the schema allows).
 */
class CustomStudyChapterLocatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Chapter $chapter;
    private Chapter $otherLanguageChapter;
    private EloquentChapterLocator $locator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Locator User',
            'email' => 'locator-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => 'other-locator-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'Locator Book',
            'language' => 'english',
        ]);

        $this->chapter = $this->createChapter($this->user->id, 'english', $book->id, 'English Chapter');

        // Other-language chapter (same user, different language column).
        $jpBook = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'JP Book',
            'language' => 'japanese',
        ]);
        $this->otherLanguageChapter = $this->createChapter($this->user->id, 'japanese', $jpBook->id, 'Japanese Chapter');

        $this->locator = new EloquentChapterLocator();
    }

    public function test_returns_true_when_chapter_belongs_to_user_and_language(): void
    {
        $this->assertTrue(
            $this->locator->belongsToUserAndLanguage($this->chapter->id, $this->user->id, 'english')
        );
    }

    public function test_returns_false_for_other_user_chapter(): void
    {
        $this->assertFalse(
            $this->locator->belongsToUserAndLanguage($this->chapter->id, $this->otherUser->id, 'english')
        );
    }

    public function test_returns_false_for_other_language_chapter(): void
    {
        $this->assertFalse(
            $this->locator->belongsToUserAndLanguage($this->otherLanguageChapter->id, $this->user->id, 'english')
        );
    }

    public function test_returns_false_for_non_existent_chapter(): void
    {
        $this->assertFalse(
            $this->locator->belongsToUserAndLanguage(99999999, $this->user->id, 'english')
        );
    }

    public function test_returns_false_for_invalid_inputs(): void
    {
        // Negative / zero ids and empty language are rejected without a query.
        $this->assertFalse($this->locator->belongsToUserAndLanguage(0, $this->user->id, 'english'));
        $this->assertFalse($this->locator->belongsToUserAndLanguage(-1, $this->user->id, 'english'));
        $this->assertFalse($this->locator->belongsToUserAndLanguage($this->chapter->id, 0, 'english'));
        $this->assertFalse($this->locator->belongsToUserAndLanguage($this->chapter->id, $this->user->id, ''));
    }

    public function test_container_resolves_to_eloquent_chapter_locator(): void
    {
        $resolved = app(ChapterLocatorInterface::class);
        $this->assertInstanceOf(EloquentChapterLocator::class, $resolved);
    }

    public function test_container_resolves_custom_study_criteria_validator(): void
    {
        $validator = app(CustomStudyCriteriaValidator::class);
        $this->assertInstanceOf(CustomStudyCriteriaValidator::class, $validator);

        // Verify the locator is the production Eloquent implementation.
        $ref = new \ReflectionClass($validator);
        $prop = $ref->getProperty('chapterLocator');
        $prop->setAccessible(true);
        $this->assertInstanceOf(EloquentChapterLocator::class, $prop->getValue($validator));
    }

    public function test_validator_accepts_owned_chapter(): void
    {
        $validator = app(CustomStudyCriteriaValidator::class);
        $criteria = $validator->validate(
            ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => $this->chapter->id]],
            $this->user->id,
            'english'
        );
        $this->assertSame('source_chapter', $criteria->mode());
        $this->assertSame($this->chapter->id, $criteria->parameters()['chapter_id']);
    }

    public function test_validator_throws_for_cross_user_chapter(): void
    {
        $validator = app(CustomStudyCriteriaValidator::class);

        try {
            $validator->validate(
                ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => $this->chapter->id]],
                $this->otherUser->id,
                'english'
            );
            $this->fail('Expected CustomStudyValidationException for cross-user chapter.');
        } catch (\App\Exceptions\CustomStudyValidationException $e) {
            $this->assertSame('chapter_id', $e->getField());
            $this->assertSame('chapter_not_owned', $e->getReason());
        }
    }

    public function test_validator_throws_for_cross_language_chapter(): void
    {
        $validator = app(CustomStudyCriteriaValidator::class);

        try {
            $validator->validate(
                ['mode' => 'source_chapter', 'parameters' => ['chapter_id' => $this->otherLanguageChapter->id]],
                $this->user->id,
                'english'
            );
            $this->fail('Expected CustomStudyValidationException for cross-language chapter.');
        } catch (\App\Exceptions\CustomStudyValidationException $e) {
            $this->assertSame('chapter_id', $e->getField());
            $this->assertSame('chapter_not_owned', $e->getReason());
        }
    }

    public function test_locator_uses_single_exists_query(): void
    {
        DB::enableQueryLog();
        $this->locator->belongsToUserAndLanguage($this->chapter->id, $this->user->id, 'english');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Exactly one query (the exists() check on chapters).
        $this->assertCount(1, $queries, 'Locator must execute exactly one exists() query.');
        $this->assertStringContainsString('`chapters`', $queries[0]['query']);
    }

    public function test_locator_does_not_write_chapter(): void
    {
        $chapterCountBefore = Chapter::count();

        $this->locator->belongsToUserAndLanguage($this->chapter->id, $this->user->id, 'english');
        $this->locator->belongsToUserAndLanguage(99999999, $this->user->id, 'english');

        $this->assertSame($chapterCountBefore, Chapter::count(), 'Locator must not create or modify Chapter rows.');
    }

    public function test_locator_does_not_write_other_learning_data(): void
    {
        $chapterId = $this->chapter->id;

        $snapshot = [
            'review_cards' => DB::table('review_cards')->count(),
            'review_logs' => DB::table('review_logs')->count(),
            'word_senses' => DB::table('word_senses')->count(),
            'word_sense_occurrences' => DB::table('word_sense_occurrences')->count(),
        ];

        $this->locator->belongsToUserAndLanguage($chapterId, $this->user->id, 'english');
        $this->locator->belongsToUserAndLanguage($chapterId, $this->otherUser->id, 'english');
        $this->locator->belongsToUserAndLanguage(99999999, $this->user->id, 'english');

        $this->assertSame($snapshot['review_cards'], DB::table('review_cards')->count());
        $this->assertSame($snapshot['review_logs'], DB::table('review_logs')->count());
        $this->assertSame($snapshot['word_senses'], DB::table('word_senses')->count());
        $this->assertSame($snapshot['word_sense_occurrences'], DB::table('word_sense_occurrences')->count());
    }

    public function test_locator_source_code_does_not_use_auth_or_request(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/EloquentChapterLocator.php');

        // Strip block comments before checking — we only forbid actual code
        // references to Auth/Request/Session/ReviewCard/etc., not docblock
        // mentions.
        $codeOnly = preg_replace('/\/\*.*?\*\//s', '', $source);
        $codeOnly = preg_replace('/^\s*\/\/.*$/m', '', $codeOnly);

        $this->assertStringNotContainsString('Auth::', $codeOnly, 'Locator must not use Auth facade.');
        $this->assertStringNotContainsString('request(', $codeOnly, 'Locator must not use request().');
        $this->assertStringNotContainsString('session(', $codeOnly, 'Locator must not use session().');
        $this->assertStringNotContainsString('ReviewCard', $codeOnly, 'Locator must not query ReviewCard.');
        $this->assertStringNotContainsString('ReviewLog', $codeOnly, 'Locator must not query ReviewLog.');
        $this->assertStringNotContainsString('WordSense', $codeOnly, 'Locator must not query WordSense.');
        $this->assertStringNotContainsString('WordSenseOccurrence', $codeOnly, 'Locator must not query WordSenseOccurrence.');
    }

    // ─── Helpers ───

    private function createChapter(int $userId, string $language, int $bookId, string $name): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $userId,
            'book_id' => $bookId,
            'name' => $name,
            'language' => $language,
            'raw_text' => 'Test chapter content.',
            'word_count' => 3,
            'read_count' => 0,
            'unique_words' => '["test","chapter","content"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }
}
