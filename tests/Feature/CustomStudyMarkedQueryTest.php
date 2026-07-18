<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\CustomStudy\Queries\MarkedQuery;
use App\Services\SenseReviewQueryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomStudyMarkedQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-07-18 12:00:00', 'UTC');
        $this->user = $this->createUser('marked-query-owner@example.com');
        $this->otherUser = $this->createUser('marked-query-other@example.com');
    }

    public function test_build_returns_a_composable_builder_and_only_marked_eligible_cards(): void
    {
        $included = $this->createCard($this->createSense($this->user->id, 'english'), [
            'marker' => ReviewCard::MARKER_RED,
        ]);
        $unmarked = $this->createCard($this->createSense($this->user->id, 'english'), [
            'marker' => ReviewCard::MARKER_NONE,
        ]);
        $suspended = $this->createCard($this->createSense($this->user->id, 'english'), [
            'marker' => ReviewCard::MARKER_ORANGE,
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
            'fsrs_enabled' => false,
        ]);
        $archived = $this->createCard($this->createSense($this->user->id, 'english'), [
            'marker' => ReviewCard::MARKER_GREEN,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED,
            'fsrs_enabled' => false,
        ]);
        $buried = $this->createCard($this->createSense($this->user->id, 'english'), [
            'marker' => ReviewCard::MARKER_BLUE,
            'lifecycle_state' => ReviewCard::LIFECYCLE_BURIED,
            'buried_until' => $this->now->copy()->addDay(),
        ]);
        $disabled = $this->createCard($this->createSense($this->user->id, 'english'), [
            'marker' => ReviewCard::MARKER_PINK,
            'fsrs_enabled' => false,
        ]);
        $unconfirmed = $this->createCard($this->createSense(
            $this->user->id,
            'english',
            WordSense::STATUS_AI_SUGGESTED
        ), ['marker' => ReviewCard::MARKER_TURQUOISE]);
        $otherUser = $this->createCard($this->createSense($this->otherUser->id, 'english'), [
            'marker' => ReviewCard::MARKER_PURPLE,
        ]);
        $otherLanguage = $this->createCard($this->createSense($this->user->id, 'french'), [
            'marker' => ReviewCard::MARKER_RED,
        ]);
        $legacy = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 991003,
            'marker' => ReviewCard::MARKER_RED,
            'fsrs_state' => 'review',
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);

        $query = $this->query();
        $this->assertInstanceOf(Builder::class, $query);
        $this->assertSame([$included->id], $query->pluck('review_cards.id')->all());

        foreach ([$unmarked, $suspended, $archived, $buried, $disabled, $unconfirmed, $otherUser, $otherLanguage, $legacy] as $excluded) {
            $this->assertNotSame($included->id, $excluded->id);
        }
    }

    public function test_expired_buried_marked_card_is_eligible(): void
    {
        $card = $this->createCard($this->createSense($this->user->id, 'english'), [
            'marker' => ReviewCard::MARKER_ORANGE,
            'lifecycle_state' => ReviewCard::LIFECYCLE_BURIED,
            'buried_until' => $this->now->copy()->subMinute(),
        ]);

        $this->assertSame([$card->id], $this->query()->pluck('review_cards.id')->all());
    }

    public function test_query_is_read_only(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createCard($sense, ['marker' => ReviewCard::MARKER_GREEN]);
        $cardBefore = $card->fresh()->getRawOriginal();
        $senseBefore = $sense->fresh()->getRawOriginal();
        $logsBefore = ReviewLog::count();

        $this->query()->get();

        $this->assertSame($cardBefore, $card->fresh()->getRawOriginal());
        $this->assertSame($senseBefore, $sense->fresh()->getRawOriginal());
        $this->assertSame($logsBefore, ReviewLog::count());
    }

    private function query(): Builder
    {
        return (new MarkedQuery(app(SenseReviewQueryService::class)))
            ->build($this->user->id, 'english', $this->now);
    }

    private function createUser(string $email): User
    {
        return User::forceCreate([
            'name' => 'Marked Query Test',
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function createSense(int $userId, string $language, string $status = WordSense::STATUS_CONFIRMED): WordSense
    {
        $lemma = Str::lower(Str::random(10));

        return WordSense::forceCreate([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '已标记',
            'sense_en' => 'marked',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This card is marked.',
            'example_sentence_zh' => '这张卡已标记。',
            'status' => $status,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "{$userId}|{$language}|{$lemma}|{$status}"),
        ]);
    }

    private function createCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'marker' => ReviewCard::MARKER_NONE,
            'fsrs_state' => 'review',
            'fsrs_due_at' => $this->now->copy()->addDay(),
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ], $overrides));
    }
}
