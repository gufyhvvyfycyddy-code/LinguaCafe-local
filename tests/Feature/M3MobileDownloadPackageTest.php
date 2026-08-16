<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterAiReadingAssist;
use App\Models\MobileClientAction;
use App\Models\MobileDevice;
use App\Models\Operation;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\MobileArticlePackageService;
use App\Services\DictionaryService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class M3MobileDownloadPackageTest extends TestCase
{
    use RefreshDatabase;

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
        $this->user = $this->createUser('m3-packages@example.test', 'english');
    }

    public function test_article_manifest_and_shards_are_deterministic_bounded_and_source_complete(): void
    {
        $dictionary = $this->mock(DictionaryService::class);
        $dictionary->shouldReceive('localContentVersion')->andReturn('sha256:dictionary-v1');
        $dictionary->shouldReceive('localDefinitionSummaries')
            ->once()
            ->with('english', ['hello', 'world'])
            ->andReturn(['hello' => ['你好'], 'world' => ['世界']]);
        $dictionary->shouldReceive('localDefinitionSummaries')
            ->once()
            ->with('english', ['again'])
            ->andReturn(['again' => ['再次']]);
        [$token] = $this->issueToken($this->user);
        [$book, $chapter] = $this->createArticle($this->user, [
            $this->token('Hello', 0, false),
            $this->token('world', 0, false),
            $this->token('.', 0, false),
            $this->token('PARAGRAPH_BREAK', 0, true),
            $this->token('Again', 1, false),
            $this->token('.', 1, false),
        ]);
        ChapterAiReadingAssist::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'chapter_id' => $chapter->id,
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 0, 'source_text' => 'Hello world.', 'translation_zh' => '你好，世界。'],
                ['sentence_index' => 1, 'source_text' => 'Again.', 'translation_zh' => '再一次。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
            'summary' => [],
        ]);
        $sense = $this->createSense($this->user, 'world');
        WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => 0,
            'sentence_en' => 'Hello world.',
            'sentence_zh' => '你好，世界。',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'world',
            'lemma' => 'world',
            'pos' => 'noun',
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ]);

        $before = $this->writeBoundarySnapshot();
        $manifest = $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.schema_version', 'mobile_download_package_v1')
            ->assertJsonPath('data.package_type', 'article')
            ->assertJsonPath('data.chapter_count', 1)
            ->assertJsonPath('data.invalidation.strategy', 'replace_when_version_differs');
        $version = $manifest->json('data.content_version');

        $this->withToken($token)
            ->getJson('/api/v1/mobile/article-packages?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.items.0.content_version', $version)
            ->assertJsonPath('data.pagination.total', 1);

        $first = $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}/chapters/{$chapter->id}?token_limit=4")
            ->assertOk()
            ->assertJsonCount(4, 'data.tokens')
            ->assertJsonPath('data.tokens.0.token_identity', "chapter:{$chapter->id}:token:0")
            ->assertJsonPath('data.tokens.0.sentence_identity', "chapter:{$chapter->id}:sentence:0")
            ->assertJsonPath('data.tokens.0.section_identity', "chapter:{$chapter->id}:section:0")
            ->assertJsonPath('data.sentence_translations.0.translation_zh', '你好，世界。')
            ->assertJsonPath('data.sense_summaries.0.word_sense_id', $sense->id)
            ->assertJsonPath('data.dictionary_version', 'sha256:dictionary-v1')
            ->assertJsonPath('data.dictionary_summaries.hello.0', '你好')
            ->assertJsonPath('data.dictionary_summaries.world.0', '世界')
            ->assertJsonPath('data.has_more', true);
        $this->assertLessThanOrEqual(
            MobileArticlePackageService::MAX_SHARD_BYTES,
            $first->json('data.payload_bytes'),
        );

        $second = $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}/chapters/{$chapter->id}?" . http_build_query([
                'token_limit' => 4,
                'cursor' => $first->json('data.next_cursor'),
            ]))
            ->assertOk()
            ->assertJsonPath('data.offset', 4)
            ->assertJsonPath('data.tokens.0.token_identity', "chapter:{$chapter->id}:token:4")
            ->assertJsonPath('data.tokens.0.section_identity', "chapter:{$chapter->id}:section:1")
            ->assertJsonPath('data.dictionary_summaries.again.0', '再次')
            ->assertJsonPath('data.has_more', false);
        $this->assertSame($version, $manifest->json('data.content_version'));
        $this->assertNotEmpty($second->json('data.sentence_translations'));
        $this->assertSame($before, $this->writeBoundarySnapshot());
    }

    public function test_dictionary_source_version_invalidates_article_manifest(): void
    {
        $dictionary = $this->mock(DictionaryService::class);
        $dictionary->shouldReceive('localContentVersion')
            ->twice()
            ->andReturn('sha256:dictionary-v1', 'sha256:dictionary-v2');
        [$token] = $this->issueToken($this->user);
        [$book] = $this->createArticle($this->user, [$this->token('Hello', 0)]);

        $first = $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}")
            ->assertOk();
        $second = $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}")
            ->assertOk();

        $this->assertNotSame(
            $first->json('data.content_version'),
            $second->json('data.content_version'),
        );
        $this->assertSame(
            'sha256:dictionary-v2',
            $second->json('data.chapters.0.dictionary_version'),
        );
    }

    public function test_material_metadata_is_packaged_and_invalidates_manifest_version(): void
    {
        [$token] = $this->issueToken($this->user);
        [$book] = $this->createArticle($this->user, [$this->token('Material', 0)]);
        $first = $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.book.material_type', 'personal')
            ->assertJsonPath('data.book.exam_year', null)
            ->assertJsonPath('data.book.exam_set', null);

        $book->forceFill([
            'material_type' => 'cet6',
            'exam_year' => 2025,
            'exam_set' => 2,
        ])->save();

        $second = $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.book.material_type', 'cet6')
            ->assertJsonPath('data.book.exam_year', 2025)
            ->assertJsonPath('data.book.exam_set', 2);

        $this->assertNotSame(
            $first->json('data.content_version'),
            $second->json('data.content_version'),
        );
    }

    public function test_article_change_invalidates_version_and_old_cursor(): void
    {
        [$token] = $this->issueToken($this->user);
        [$book, $chapter] = $this->createArticle($this->user, [
            $this->token('One', 0),
            $this->token('two', 0),
        ]);
        $firstManifest = $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}")
            ->assertOk();
        $firstShard = $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}/chapters/{$chapter->id}?token_limit=1")
            ->assertOk();

        $chapter->forceFill([
            'processed_text' => gzcompress(json_encode((object) [
                'words' => [
                    $this->token('Changed', 0),
                    $this->token('text', 0),
                ],
            ]), 1),
        ])->save();

        $secondManifest = $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}")
            ->assertOk();
        $this->assertNotSame(
            $firstManifest->json('data.content_version'),
            $secondManifest->json('data.content_version'),
        );

        $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}/chapters/{$chapter->id}?" . http_build_query([
                'cursor' => $firstShard->json('data.next_cursor'),
            ]))
            ->assertConflict()
            ->assertJsonPath('error.code', 'ARTICLE_PACKAGE_CHANGED');
    }

    public function test_article_packages_reject_foreign_wrong_language_nested_and_corrupt_sources(): void
    {
        [$token] = $this->issueToken($this->user);
        $other = $this->createUser('m3-other@example.test', 'english');
        [$foreignBook, $foreignChapter] = $this->createArticle($other, [$this->token('Hidden', 0)]);
        [$ownBook, $ownChapter] = $this->createArticle($this->user, [$this->token('Visible', 0)]);
        [$spanishBook] = $this->createArticle($this->user, [$this->token('Oculto', 0)], 'spanish');

        foreach ([
            "/api/v1/mobile/article-packages/{$foreignBook->id}",
            "/api/v1/mobile/article-packages/{$spanishBook->id}",
            "/api/v1/mobile/article-packages/{$ownBook->id}/chapters/{$foreignChapter->id}",
        ] as $url) {
            $this->withToken($token)
                ->getJson($url)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'ARTICLE_PACKAGE_NOT_FOUND');
        }

        $ownChapter->forceFill(['processed_text' => 'not-gzip'])->save();
        $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$ownBook->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'INVALID_PACKAGE_SOURCE')
            ->assertJsonMissing(['processed_text' => 'not-gzip']);
    }

    public function test_short_term_review_package_is_ordered_cursor_stable_isolated_and_read_only(): void
    {
        [$token] = $this->issueToken($this->user);
        $overdue = $this->createCard($this->user, 'overdue', now()->subDay());
        $near = $this->createCard($this->user, 'near', now()->addDays(3));
        $this->createCard($this->user, 'outside', now()->addDays(8));
        $suspended = $this->createCard($this->user, 'suspended', now()->subHour());
        $suspended->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
            'fsrs_enabled' => false,
        ])->save();
        $other = $this->createUser('m3-review-other@example.test', 'english');
        $this->createCard($other, 'foreign', now()->subHour());
        $before = $this->writeBoundarySnapshot();

        $first = $this->withToken($token)
            ->getJson('/api/v1/mobile/review-packages/short-term?horizon_days=7&limit=1')
            ->assertOk()
            ->assertJsonPath('data.package_type', 'short_term_review')
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.offline_rating_upload_supported', true)
            ->assertJsonPath('data.items.0.review_card_id', $overdue->id)
            ->assertJsonPath('data.items.0.scheduling_snapshot.fsrs_due_at', $overdue->fresh()->fsrs_due_at->utc()->toIso8601String())
            ->assertJsonPath('data.has_more', true);
        $version = $first->json('data.package_version');
        $generatedAt = $first->json('data.generated_at');
        $cursor = $first->json('data.next_cursor');

        $second = $this->withToken($token)
            ->getJson('/api/v1/mobile/review-packages/short-term?' . http_build_query([
                'horizon_days' => 7,
                'limit' => 1,
                'cursor' => $cursor,
            ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.review_card_id', $near->id)
            ->assertJsonPath('data.has_more', false);
        $this->assertSame($version, $second->json('data.package_version'));
        $this->assertSame($generatedAt, $second->json('data.generated_at'));
        $this->assertSame($before, $this->writeBoundarySnapshot());

        $this->withToken($token)
            ->getJson('/api/v1/mobile/review-packages/short-term?' . http_build_query([
                'horizon_days' => 6,
                'cursor' => $cursor,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_PACKAGE_CURSOR');

        [$otherToken] = $this->issueToken($other);
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.user.id', $other->id);
        $this->withToken($otherToken)
            ->getJson('/api/v1/mobile/review-packages/short-term?' . http_build_query([
                'horizon_days' => 7,
                'cursor' => $cursor,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_PACKAGE_CURSOR');
    }

    public function test_large_article_and_due_queue_remain_bounded_without_n_plus_one_growth(): void
    {
        [$token] = $this->issueToken($this->user);
        $tokens = [];
        for ($index = 0; $index < 5000; $index++) {
            $tokens[] = $this->token('token' . $index, intdiv($index, 10));
        }
        [$book, $chapter] = $this->createArticle($this->user, $tokens);

        $article = $this->withToken($token)
            ->getJson("/api/v1/mobile/article-packages/{$book->id}/chapters/{$chapter->id}?token_limit=1000")
            ->assertOk()
            ->assertJsonCount(1000, 'data.tokens')
            ->assertJsonPath('data.total_tokens', 5000)
            ->assertJsonPath('data.has_more', true);
        $this->assertLessThanOrEqual(
            MobileArticlePackageService::MAX_SHARD_BYTES,
            $article->json('data.payload_bytes'),
        );

        for ($index = 0; $index < 250; $index++) {
            $this->createCard($this->user, 'bulk-' . $index, now()->subMinutes(250 - $index));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $review = $this->withToken($token)
            ->getJson('/api/v1/mobile/review-packages/short-term?horizon_days=0&limit=100')
            ->assertOk()
            ->assertJsonCount(100, 'data.items')
            ->assertJsonPath('data.has_more', true);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(20, $queryCount, "review package used {$queryCount} queries");
        $this->assertLessThan(3 * 1024 * 1024, strlen($review->getContent()));
    }

    public function test_auth_validation_bootstrap_and_package_endpoints_use_mobile_contract(): void
    {
        $this->getJson('/api/v1/mobile/article-packages')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        [$token] = $this->issueToken($this->user);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.capabilities.article_packages', true)
            ->assertJsonPath('data.capabilities.review_packages', true)
            ->assertJsonPath('data.capabilities.offline_queue', true);
        $this->withToken($token)
            ->getJson('/api/v1/mobile/article-packages?per_page=21')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->withToken($token)
            ->getJson('/api/v1/mobile/review-packages/short-term?horizon_days=31')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->withToken($token)
            ->getJson('/api/v1/mobile/review-packages/short-term?cursor=invalid')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_PACKAGE_CURSOR');
    }

    private function createArticle(User $user, array $tokens, ?string $language = null): array
    {
        $language ??= $user->selected_language;
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'M3 Book ' . Str::random(6),
            'cover_image' => null,
            'language' => $language,
        ]);
        $chapter = Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'M3 Chapter ' . Str::random(6),
            'read_count' => 0,
            'word_count' => count($tokens),
            'language' => $language,
            'raw_text' => implode(' ', array_map(fn ($token) => $token->word, $tokens)),
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode((object) ['words' => $tokens]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        return [$book, $chapter];
    }

    private function token(
        string $word,
        int $sentenceIndex,
        bool $structure = false,
    ): object {
        return (object) [
            'word' => $word,
            'lemma' => strtolower($word),
            'pos' => $structure ? 'STRUCT' : 'NOUN',
            'sentence_index' => $sentenceIndex,
            'is_structure' => $structure,
            'spaceAfter' => !$structure,
            'phrase_ids' => [],
        ];
    }

    private function createCard(User $user, string $lemma, $dueAt): ReviewCard
    {
        $sense = $this->createSense($user, $lemma);
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $card->forceFill([
            'fsrs_due_at' => $dueAt,
            'fsrs_state' => 'review',
            'fsrs_stability' => 5.5,
            'fsrs_difficulty' => 6.5,
            'fsrs_reps' => 2,
            'fsrs_lapses' => 1,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ])->save();

        return $card->fresh();
    }

    private function createSense(User $user, string $lemma): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => $user->selected_language,
            'language_id' => $user->selected_language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '释义-' . $lemma,
            'sense_en' => 'meaning-' . $lemma,
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => "Example for {$lemma}.",
            'example_sentence_zh' => "{$lemma} 的例句。",
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "{$user->id}|{$user->selected_language}|{$lemma}"),
        ]);
    }

    private function issueToken(User $user): array
    {
        $deviceUuid = (string) Str::uuid();
        $response = $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => $deviceUuid,
            'platform' => 'android',
            'device_name' => 'M3 test device',
            'app_version' => '1.0.0',
        ])->assertCreated();

        return [
            $response->json('data.token'),
            MobileDevice::query()
                ->where('user_id', $user->id)
                ->where('device_uuid', $deviceUuid)
                ->firstOrFail(),
        ];
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

    private function writeBoundarySnapshot(): array
    {
        return [
            'books' => Book::query()->count(),
            'chapters' => Chapter::query()->count(),
            'assists' => ChapterAiReadingAssist::query()->count(),
            'senses' => WordSense::query()->count(),
            'occurrences' => WordSenseOccurrence::query()->count(),
            'cards' => ReviewCard::query()->count(),
            'article_rows' => [
                'books' => Book::query()->orderBy('id')->get(['id', 'updated_at'])->toArray(),
                'chapters' => Chapter::query()->orderBy('id')->get(['id', 'updated_at'])->toArray(),
                'assists' => ChapterAiReadingAssist::query()->orderBy('id')->get(['id', 'updated_at'])->toArray(),
                'senses' => WordSense::query()->orderBy('id')->get(['id', 'updated_at'])->toArray(),
                'occurrences' => WordSenseOccurrence::query()->orderBy('id')->get(['id', 'updated_at'])->toArray(),
            ],
            'card_state' => ReviewCard::query()->orderBy('id')->get([
                'id',
                'fsrs_state',
                'fsrs_due_at',
                'fsrs_stability',
                'fsrs_difficulty',
                'fsrs_reps',
                'fsrs_lapses',
                'fsrs_last_reviewed_at',
                'fsrs_enabled',
                'lifecycle_state',
                'lifecycle_version',
                'updated_at',
            ])->toArray(),
            'logs' => ReviewLog::query()->count(),
            'operations' => Operation::query()->count(),
            'actions' => MobileClientAction::query()->count(),
        ];
    }
}
