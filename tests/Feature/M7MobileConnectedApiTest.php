<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\MobileDevice;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\DictionaryService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class M7MobileConnectedApiTest extends TestCase
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
        $this->user = $this->createUser('m7@example.com', 'english');
    }

    public function test_local_dictionary_lookup_is_bounded_read_only_and_language_scoped(): void
    {
        [$token] = $this->issueToken($this->user);
        $result = [
            'term' => 'friendly',
            'definitions' => array_merge(['友好的'], array_fill(0, 9, '重复')),
            'warnings' => [],
            'configured' => true,
        ];
        $dictionary = $this->mock(DictionaryService::class);
        $dictionary->shouldReceive('searchDefinitionsForHoverVocabulary')
            ->once()
            ->with('english', 'friendly')
            ->andReturn($result);

        $before = [
            WordSense::count(),
            ReviewCard::count(),
            ReviewLog::count(),
        ];

        $response = $this->withToken($token)
            ->getJson('/api/v1/mobile/dictionary/lookup?term=friendly')
            ->assertOk()
            ->assertJsonPath('data.term', 'friendly')
            ->assertJsonPath('data.local_only', true)
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.warnings', []);

        $this->assertCount(10, $response->json('data.definitions'));
        $this->assertSame($before, [
            WordSense::count(),
            ReviewCard::count(),
            ReviewLog::count(),
        ]);
    }

    public function test_mobile_manual_sense_creation_uses_token_ownership_and_existing_service(): void
    {
        [$token] = $this->issueToken($this->user);

        $response = $this->withToken($token)
            ->postJson('/api/v1/mobile/word-senses', [
                'lemma' => 'friendly',
                'surface_form' => 'friendlier',
                'pos' => 'adjective',
                'sense_zh' => '友好的',
                'sense_en' => 'kind and pleasant',
                'keep_new' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.word_sense.lemma', 'friendly')
            ->assertJsonPath('data.word_sense.sense_zh', '友好的');

        $senseId = $response->json('data.word_sense.sense_id');
        $this->assertDatabaseHas('word_senses', [
            'id' => $senseId,
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_manual_sense_cannot_submit_server_owned_fields(): void
    {
        [$token] = $this->issueToken($this->user);
        $other = $this->createUser('other-m7@example.com', 'spanish');

        $this->withToken($token)
            ->postJson('/api/v1/mobile/word-senses', [
                'lemma' => 'safe',
                'pos' => 'adjective',
                'sense_zh' => '安全的',
                'user_id' => $other->id,
                'language_id' => 'spanish',
                'status' => 'rejected',
                'fsrs_reps' => 99,
            ])
            ->assertCreated();

        $sense = WordSense::where('lemma', 'safe')->firstOrFail();
        $this->assertSame($this->user->id, $sense->user_id);
        $this->assertSame('english', $sense->language_id);
        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->status);
    }

    public function test_manual_sense_rejects_article_context_from_another_user(): void
    {
        [$token] = $this->issueToken($this->user);
        $other = $this->createUser('article-owner@example.com', 'english');
        $book = Book::forceCreate([
            'name' => 'Private article',
            'language' => 'english',
            'user_id' => $other->id,
        ]);
        $chapter = Chapter::forceCreate([
            'name' => 'Private chapter',
            'language' => 'english',
            'user_id' => $other->id,
            'book_id' => $book->id,
            'read_count' => 0,
            'word_count' => 1,
            'raw_text' => 'This is private.',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress('{"words":[]}', 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/mobile/word-senses', [
                'lemma' => 'private',
                'pos' => 'adjective',
                'sense_zh' => '私人的',
                'chapter_id' => $chapter->id,
                'sentence_id' => 1,
                'sentence_en' => 'This is private.',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ARTICLE_PACKAGE_NOT_FOUND');

        $this->assertSame(0, WordSense::where('lemma', 'private')->count());
    }

    public function test_summary_is_read_only_and_excludes_other_users_cards(): void
    {
        [$token] = $this->issueToken($this->user);
        $ownCard = $this->createSenseCard($this->user, 'apple');
        $ownCard->forceFill(['fsrs_due_at' => now()->subMinute()])->save();
        $other = $this->createUser('summary-other@example.com', 'english');
        $otherCard = $this->createSenseCard($other, 'pear');
        $otherCard->forceFill(['fsrs_due_at' => now()->subMinute()])->save();
        $beforeUpdated = $ownCard->fresh()->updated_at?->toIso8601String();

        $this->withToken($token)
            ->getJson('/api/v1/mobile/summary')
            ->assertOk()
            ->assertJsonPath('data.today.reviewed_today_count', 0)
            ->assertJsonPath('data.today.introduced_today_count', 0)
            ->assertJsonPath('data.active_card_count', 1)
            ->assertJsonPath('data.due_now_count', 1)
            ->assertJsonPath('data.read_only', true);

        $this->assertSame(0, ReviewLog::count());
        $this->assertSame($beforeUpdated, $ownCard->fresh()->updated_at?->toIso8601String());
    }

    public function test_m7_endpoints_require_an_active_mobile_device(): void
    {
        $this->getJson('/api/v1/mobile/dictionary/lookup?term=test')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->postJson('/api/v1/mobile/word-senses', [])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->getJson('/api/v1/mobile/summary')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    private function issueToken(User $user): array
    {
        $deviceUuid = (string) Str::uuid();
        $response = $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => $deviceUuid,
            'platform' => 'android',
            'device_name' => 'M7 test device',
            'app_version' => '1.0.0',
        ])->assertCreated();

        return [
            $response->json('data.token'),
            MobileDevice::where('user_id', $user->id)
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

    private function createSenseCard(User $user, string $lemma): ReviewCard
    {
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => $user->selected_language,
            'language_id' => $user->selected_language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "{$user->id}|{$user->selected_language}|{$lemma}"),
        ]);

        return app(ReviewCardService::class)->ensureSenseCard($sense);
    }
}
