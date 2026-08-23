<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\ArticleHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArticleHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'article_health.tokenizer_url' => 'http://tokenizer.test',
            'article_health.scan_limit' => 100,
            'article_health.sample_limit' => 5,
            'article_health.fallback_minimum_senses' => 10,
            'article_health.fallback_warning_ratio' => 0.25,
        ]);
        Http::fake([
            'http://tokenizer.test/tokenizer/health' => Http::response(['status' => 'healthy']),
        ]);
    }

    public function test_routes_require_authentication_and_health_surface_is_get_only(): void
    {
        $this->getJson('/article-health/data')->assertUnauthorized();

        $user = $this->user('health-route@example.test');
        $this->actingAs($user)->get('/article-health')->assertOk();
        $this->actingAs($user)->getJson('/article-health/data')->assertOk();
        $this->actingAs($user)->postJson('/article-health/data')->assertMethodNotAllowed();
    }

    public function test_healthy_report_has_stable_scope_summary_and_check_schema(): void
    {
        $user = $this->user('health-clean@example.test');

        $this->actingAs($user)
            ->getJson('/article-health/data')
            ->assertOk()
            ->assertJsonPath('article_health.scope.language', 'english')
            ->assertJsonPath('article_health.status', 'healthy')
            ->assertJsonPath('article_health.summary.total', 0)
            ->assertJsonPath('article_health.checks.tokenizer.status', 'available')
            ->assertJsonPath('article_health.checks.chapter_positions.status', 'not_configured')
            ->assertJsonPath('article_health.scan.truncated', false)
            ->assertJsonCount(0, 'article_health.findings')
            ->assertJsonStructure([
                'article_health' => [
                    'generated_at',
                    'scope' => ['language'],
                    'status',
                    'summary' => ['total', 'critical', 'warning', 'info'],
                    'checks',
                    'findings',
                    'scan' => ['limit', 'truncated'],
                ],
            ]);
    }

    public function test_book_scope_is_exact_and_foreign_or_wrong_language_books_are_hidden(): void
    {
        $user = $this->user('health-book-scope@example.test');
        $other = $this->user('health-book-scope-other@example.test');
        $emptyBook = $this->book($user, 'Empty scoped book');
        $healthyBook = $this->book($user, 'Healthy scoped book');
        $this->chapter($user, $healthyBook);
        $foreignBook = $this->book($other, 'Foreign book');
        $wrongLanguageBook = $this->book($user, 'Wrong language book');
        $wrongLanguageBook->forceFill(['language' => 'japanese'])->save();

        $this->actingAs($user)
            ->getJson("/article-health/data?book_id={$emptyBook->id}")
            ->assertOk()
            ->assertJsonPath('article_health.scope.book_id', $emptyBook->id)
            ->assertJsonPath('article_health.scope.book_name', 'Empty scoped book')
            ->assertJsonFragment(['code' => 'ARTICLE_BOOK_EMPTY']);

        $this->actingAs($user)
            ->getJson("/article-health/data?book_id={$healthyBook->id}")
            ->assertOk()
            ->assertJsonPath('article_health.scope.book_id', $healthyBook->id)
            ->assertJsonPath('article_health.summary.total', 0)
            ->assertJsonMissing(['code' => 'ARTICLE_BOOK_EMPTY']);

        $this->actingAs($user)->getJson("/article-health/data?book_id={$foreignBook->id}")->assertNotFound();
        $this->actingAs($user)->getJson("/article-health/data?book_id={$wrongLanguageBook->id}")->assertNotFound();
        $this->actingAs($user)->getJson('/article-health/data?book_id=0')->assertUnprocessable();
    }

    public function test_book_scope_limits_reference_findings_to_occurrences_in_that_book(): void
    {
        $user = $this->user('health-book-reference@example.test');
        $selectedBook = $this->book($user, 'Selected book');
        $selectedChapter = $this->chapter($user, $selectedBook);
        $otherBook = $this->book($user, 'Other book');
        $otherChapter = $this->chapter($user, $otherBook);
        $selectedOccurrence = $this->occurrence($user, [
            'chapter_id' => $selectedChapter->id,
            'word_sense_id' => 930001,
            'review_card_id' => 930002,
        ]);
        $otherOccurrence = $this->occurrence($user, [
            'chapter_id' => $otherChapter->id,
            'word_sense_id' => 940001,
            'review_card_id' => 940002,
        ]);

        $findings = $this->actingAs($user)
            ->getJson("/article-health/data?book_id={$selectedBook->id}")
            ->assertOk()
            ->json('article_health.findings');
        $byCode = collect($findings)->keyBy('code');

        $this->assertSame([$selectedOccurrence->id], $byCode['ARTICLE_OCCURRENCE_SENSE_INVALID']['metadata']['sample_ids']);
        $this->assertSame([$selectedOccurrence->id], $byCode['ARTICLE_OCCURRENCE_CARD_INVALID']['metadata']['sample_ids']);
        $this->assertNotContains($otherOccurrence->id, $byCode['ARTICLE_OCCURRENCE_SENSE_INVALID']['metadata']['sample_ids']);
        $this->assertArrayNotHasKey('ARTICLE_OCCURRENCE_CHAPTER_INVALID', $byCode->all());
    }

    public function test_content_readiness_findings_cover_empty_invalid_pending_and_failed_chapters(): void
    {
        $user = $this->user('health-content@example.test');
        $this->book($user, 'Empty book');

        $book = $this->book($user, 'Chapter findings');
        $this->chapter($user, $book, [
            'name' => 'Empty processed chapter',
            'raw_text' => '',
            'word_count' => 0,
            'processing_status' => 'processed',
            'processed_text' => gzcompress('[]', 1),
        ]);
        $this->chapter($user, $book, [
            'name' => 'Invalid processed chapter',
            'processing_status' => 'processed',
            'processed_text' => 'not-gzip',
        ]);
        $this->chapter($user, $book, [
            'name' => 'Pending chapter',
            'processing_status' => 'unprocessed',
        ]);
        $this->chapter($user, $book, [
            'name' => 'Failed chapter',
            'processing_status' => 'failed',
        ]);

        $codes = collect(app(ArticleHealthService::class)->report($user->id, 'english')['findings'])
            ->pluck('code');

        $this->assertTrue($codes->contains('ARTICLE_BOOK_EMPTY'));
        $this->assertTrue($codes->contains('ARTICLE_CHAPTER_EMPTY'));
        $this->assertTrue($codes->contains('ARTICLE_TEXT_BLOCK_EMPTY'));
        $this->assertTrue($codes->contains('ARTICLE_TEXT_BLOCK_INVALID'));
        $this->assertTrue($codes->contains('ARTICLE_TOKENIZATION_PENDING'));
        $this->assertTrue($codes->contains('ARTICLE_TOKENIZATION_FAILED'));
    }

    public function test_processed_text_expansion_is_bounded(): void
    {
        config(['article_health.max_processed_text_bytes' => 1024]);
        $user = $this->user('health-expansion@example.test');
        $book = $this->book($user, 'Expansion bound');
        $this->chapter($user, $book, [
            'processed_text' => gzcompress(json_encode([
                ['word' => str_repeat('a', 2048)],
            ]), 9),
        ]);

        $this->assertContains(
            'ARTICLE_TEXT_BLOCK_INVALID',
            collect(app(ArticleHealthService::class)->report($user->id, 'english')['findings'])
                ->pluck('code')
                ->all(),
        );
    }

    public function test_invalid_reference_findings_are_scoped_to_current_user_and_language(): void
    {
        $user = $this->user('health-reference@example.test');
        $other = $this->user('health-reference-other@example.test');
        $sense = $this->sense($user, ['source_chapter_id' => 900001]);
        $ownOccurrence = $this->occurrence($user, [
            'word_sense_id' => 900002,
            'review_card_id' => 900003,
            'chapter_id' => 900004,
        ]);
        $otherOccurrence = $this->occurrence($other, [
            'word_sense_id' => 910002,
            'review_card_id' => 910003,
            'chapter_id' => 910004,
        ]);
        $otherLanguageOccurrence = $this->occurrence($user, [
            'language' => 'japanese',
            'language_id' => 'japanese',
            'word_sense_id' => 920002,
            'review_card_id' => 920003,
            'chapter_id' => 920004,
        ]);

        $findings = collect(app(ArticleHealthService::class)->report($user->id, 'english')['findings'])
            ->keyBy('code');

        $this->assertSame([$ownOccurrence->id], $findings['ARTICLE_OCCURRENCE_CHAPTER_INVALID']['metadata']['sample_ids']);
        $this->assertSame([$ownOccurrence->id], $findings['ARTICLE_OCCURRENCE_SENSE_INVALID']['metadata']['sample_ids']);
        $this->assertSame([$ownOccurrence->id], $findings['ARTICLE_OCCURRENCE_CARD_INVALID']['metadata']['sample_ids']);
        $this->assertSame([$sense->id], $findings['ARTICLE_SENSE_SOURCE_CHAPTER_INVALID']['metadata']['sample_ids']);
        $this->assertNotContains(
            $otherOccurrence->id,
            $findings['ARTICLE_OCCURRENCE_CHAPTER_INVALID']['metadata']['sample_ids'],
        );
        $this->assertNotContains(
            $otherLanguageOccurrence->id,
            $findings['ARTICLE_OCCURRENCE_CHAPTER_INVALID']['metadata']['sample_ids'],
        );
    }

    public function test_excessive_fallback_and_vocabulary_pollution_are_bounded_and_scoped(): void
    {
        config(['article_health.fallback_minimum_senses' => 2]);
        $user = $this->user('health-fallback@example.test');
        $other = $this->user('health-fallback-other@example.test');
        $this->sense($user, ['lemma' => 'first']);
        $this->sense($user, ['lemma' => 'second']);
        $this->word($user, 'https://example.test/path');
        $this->word($user, 'ordinary');
        $this->word($other, 'other@example.test');
        EncounteredWord::forceCreate(array_merge(
            $this->wordAttributes($user, '/other-language/path'),
            ['language' => 'japanese'],
        ));

        $findings = collect(app(ArticleHealthService::class)->report($user->id, 'english')['findings'])
            ->keyBy('code');

        $this->assertSame(2, $findings['ARTICLE_SOURCE_FALLBACK_EXCESSIVE']['count']);
        $this->assertSame(2, $findings['ARTICLE_SOURCE_FALLBACK_EXCESSIVE']['metadata']['eligible_count']);
        $this->assertSame(1, $findings['ARTICLE_VOCABULARY_POLLUTION']['count']);
    }

    public function test_optional_tokenizer_states_do_not_fail_the_report(): void
    {
        $user = $this->user('health-tokenizer@example.test');
        config(['article_health.tokenizer_url' => '']);
        $notConfigured = app(ArticleHealthService::class)->report($user->id, 'english');
        $this->assertSame('not_configured', $notConfigured['checks']['tokenizer']['status']);
        $this->assertContains(
            'ARTICLE_TOKENIZER_NOT_CONFIGURED',
            collect($notConfigured['findings'])->pluck('code')->all(),
        );

        config(['article_health.tokenizer_url' => 'http://unavailable.test']);
        Http::fake([
            'http://unavailable.test/tokenizer/health' => Http::response([], 503),
        ]);
        $unavailable = app(ArticleHealthService::class)->report($user->id, 'english');
        $this->assertSame('unavailable', $unavailable['checks']['tokenizer']['status']);
        $this->assertContains(
            'ARTICLE_TOKENIZER_UNAVAILABLE',
            collect($unavailable['findings'])->pluck('code')->all(),
        );
    }

    public function test_scan_limit_is_explicit_and_reports_truncation(): void
    {
        config(['article_health.scan_limit' => 1]);
        $user = $this->user('health-limit@example.test');
        $this->word($user, 'first');
        $this->word($user, 'second');

        $report = app(ArticleHealthService::class)->report($user->id, 'english');

        $this->assertTrue($report['scan']['truncated']);
        $this->assertContains(
            'ARTICLE_HEALTH_SCAN_TRUNCATED',
            collect($report['findings'])->pluck('code')->all(),
        );
    }

    public function test_report_does_not_write_learning_article_or_operation_data(): void
    {
        $user = $this->user('health-readonly@example.test');
        $book = $this->book($user, 'Read only');
        $this->chapter($user, $book);
        $this->sense($user);
        $this->occurrence($user);
        $this->word($user, 'ordinary');
        $tables = [
            'books',
            'chapters',
            'encountered_words',
            'word_senses',
            'word_sense_occurrences',
            'review_cards',
            'review_logs',
            'operations',
            'operation_changes',
        ];
        $before = collect($tables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()],
        )->all();
        $senseBefore = DB::table('word_senses')->where('user_id', $user->id)->first();

        $this->actingAs($user)->getJson('/article-health/data')->assertOk();
        $this->actingAs($user)->getJson("/article-health/data?book_id={$book->id}")->assertOk();

        $after = collect($tables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()],
        )->all();
        $this->assertSame($before, $after);
        $this->assertEquals(
            $senseBefore,
            DB::table('word_senses')->where('user_id', $user->id)->first(),
        );
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

    private function book(User $user, string $name): Book
    {
        return Book::forceCreate([
            'user_id' => $user->id,
            'name' => $name,
            'language' => 'english',
            'word_count' => 0,
        ]);
    }

    private function chapter(User $user, Book $book, array $overrides = []): Chapter
    {
        return Chapter::forceCreate(array_merge([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'Healthy chapter',
            'read_count' => 0,
            'word_count' => 1,
            'language' => 'english',
            'raw_text' => 'Healthy text.',
            'unique_words' => '["healthy"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([
                ['word' => 'Healthy', 'sentence_index' => 0],
            ]), 1),
            'type' => 'text',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ], $overrides));
    }

    private function sense(User $user, array $overrides = []): WordSense
    {
        return WordSense::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'health',
            'surface_form' => 'health',
            'pos' => 'noun',
            'sense_key' => hash('sha256', $user->id . ':' . ($overrides['lemma'] ?? 'health') . ':' . Str::uuid()),
            'sense_zh' => '健康',
            'sense_en' => 'health',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Health matters.',
            'example_sentence_zh' => '健康很重要。',
            'status' => 'confirmed',
        ], $overrides));
    }

    private function occurrence(User $user, array $overrides = []): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'sentence_id' => 'health-1',
            'sentence_en' => 'Health matters.',
            'sentence_zh' => '健康很重要。',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'health',
            'lemma' => 'health',
            'pos' => 'noun',
            'decision' => 'uncertain',
            'confidence' => 0.5,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_PENDING,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
            'raw_payload' => ['decision' => 'uncertain'],
        ], $overrides));
    }

    private function word(User $user, string $word): EncounteredWord
    {
        return EncounteredWord::forceCreate($this->wordAttributes($user, $word));
    }

    private function wordAttributes(User $user, string $word): array
    {
        return [
            'user_id' => $user->id,
            'language' => 'english',
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
        ];
    }
}
