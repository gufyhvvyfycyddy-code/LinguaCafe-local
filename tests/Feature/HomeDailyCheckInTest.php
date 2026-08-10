<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReadingSession;
use App\Models\ReadingSessionCompletion;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReadingSessionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class HomeDailyCheckInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::create(2026, 8, 10, 12, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_counts_only_current_user_language_formal_activity_and_due_cards(): void
    {
        $user = $this->user('authority');
        $foreign = $this->user('foreign');

        $this->formalReview($user, 'english', now());
        $this->formalReview($user, 'english', now()->subHour(), 'good', now()->subMinute());
        $this->formalReview($user, 'english', now()->subHours(2), 'reset');
        $this->formalReview($user, 'english', now()->subDays(2));
        $this->formalReview($foreign, 'english', now());
        $this->formalReview($user, 'german', now());

        $this->senseCard($user, 'english', now()->subMinute());
        $this->senseCard($foreign, 'english', now()->subMinute());
        $this->senseCard($user, 'german', now()->subMinute());

        $this->readingCompletion($user, 'english', now());
        $this->readingCompletion($user, 'english', now()->subDays(2));
        $this->readingCompletion($foreign, 'english', now());
        $this->readingCompletion($user, 'german', now());
        $this->activeReadingSession($user, 'english');

        $response = $this->actingAs($user)->getJson('/home/study-summary');

        $response->assertOk()
            ->assertJsonPath('study_date', '2026-08-10')
            ->assertJsonPath('timezone', 'UTC')
            ->assertJsonPath('streak_days', 1)
            ->assertJsonPath('today.reviewed_count', 1)
            ->assertJsonPath('today.reading_completed_count', 1)
            ->assertJsonPath('today.review_due_count', 1)
            ->assertJsonPath('today.checked_in', true)
            ->assertJsonPath('continue_learning.kind', 'review')
            ->assertJsonPath('continue_learning.href', '/reviews/senses')
            ->assertJsonPath('generated_at', '2026-08-10T12:00:00+00:00');
    }

    public function test_pristine_get_is_zero_write_and_falls_back_to_library(): void
    {
        $user = $this->user('pristine');
        $this->chapter($user, 'english', 4);
        $before = $this->businessFingerprint($user);
        $this->assertSame(0, $before['review_setting_presets']);
        $this->assertSame(0, $before['review_setting_preset_bindings']);
        $this->assertSame(0, $before['review_logs']);
        $this->assertSame(0, $before['review_cards']);
        $this->assertSame(0, $before['reading_sessions']);
        $this->assertSame(0, $before['reading_completions']);
        $this->assertSame(0, $before['goal_achievements']);

        $response = $this->actingAs($user)->getJson('/home/study-summary');

        $response->assertOk()
            ->assertJsonPath('streak_days', 0)
            ->assertJsonPath('today.reviewed_count', 0)
            ->assertJsonPath('today.reading_completed_count', 0)
            ->assertJsonPath('today.review_due_count', 0)
            ->assertJsonPath('today.checked_in', false)
            ->assertJsonPath('continue_learning.kind', 'library')
            ->assertJsonPath('continue_learning.href', '/books');

        $this->assertSame($before, $this->businessFingerprint($user));
    }

    public function test_checked_in_is_true_for_review_only_and_reading_only(): void
    {
        $reviewUser = $this->user('review-only');
        $this->formalReview($reviewUser, 'english', now());

        $this->actingAs($reviewUser)->getJson('/home/study-summary')
            ->assertOk()
            ->assertJsonPath('today.reviewed_count', 1)
            ->assertJsonPath('today.reading_completed_count', 0)
            ->assertJsonPath('today.checked_in', true);

        $readingUser = $this->user('reading-only');
        $this->readingCompletion($readingUser, 'english', now());
        $this->flushSession();

        $this->actingAs($readingUser)->getJson('/home/study-summary')
            ->assertOk()
            ->assertJsonPath('today.reviewed_count', 0)
            ->assertJsonPath('today.reading_completed_count', 1)
            ->assertJsonPath('today.checked_in', true);
    }

    public function test_streak_keeps_yesterday_when_today_is_inactive(): void
    {
        $user = $this->user('yesterday-streak');
        $this->formalReview($user, 'english', now()->subDay());

        $this->actingAs($user)->getJson('/home/study-summary')
            ->assertOk()
            ->assertJsonPath('streak_days', 1)
            ->assertJsonPath('today.checked_in', false);
    }

    public function test_streak_stops_at_gap_even_with_older_activity(): void
    {
        $user = $this->user('gap-streak');
        $this->formalReview($user, 'english', now());
        $this->formalReview($user, 'english', now()->subDays(2));

        $this->actingAs($user)->getJson('/home/study-summary')
            ->assertOk()
            ->assertJsonPath('streak_days', 1)
            ->assertJsonPath('today.checked_in', true);
    }

    public function test_active_reading_session_is_continue_target_when_no_review_is_due(): void
    {
        $user = $this->user('reading-cta');
        [$chapter] = $this->activeReadingSession($user, 'english');

        $this->actingAs($user)->getJson('/home/study-summary')
            ->assertOk()
            ->assertJsonPath('today.review_due_count', 0)
            ->assertJsonPath('continue_learning.kind', 'reading')
            ->assertJsonPath('continue_learning.href', '/chapters/read/'.$chapter->id);
    }

    private function user(string $label): User
    {
        return User::forceCreate([
            'name' => 'Home Summary '.$label,
            'email' => 'home-summary-'.$label.'-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function chapter(User $user, string $language, int $readCount = 0): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Home Summary '.$language.' Book '.Str::uuid(),
            'language' => $language,
        ]);

        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'Home Summary Chapter '.Str::uuid(),
            'language' => $language,
            'raw_text' => 'study',
            'word_count' => 1,
            'read_count' => $readCount,
            'unique_words' => '["study"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function senseCard(User $user, string $language, Carbon $due): ReviewCard
    {
        $key = (string) Str::uuid();
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'lemma' => 'summary-'.$key,
            'surface_form' => 'summary',
            'pos' => 'noun',
            'sense_zh' => '摘要',
            'sense_en' => 'summary',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'A summary.',
            'example_sentence_zh' => '一段摘要。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => false,
            'sense_key' => hash('sha256', $key),
        ]);

        return ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => $due,
            'fsrs_last_reviewed_at' => now()->subDay(),
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);
    }

    private function formalReview(
        User $user,
        string $language,
        Carbon $reviewedAt,
        string $rating = 'good',
        ?Carbon $undoneAt = null,
    ): ReviewLog {
        $card = $this->senseCard($user, $language, now()->addDays(7));

        return ReviewLog::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'review_card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => $reviewedAt,
            'previous_state' => 'review',
            'new_state' => 'review',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
            'undone_at' => $undoneAt,
        ]);
    }

    private function readingCompletion(User $user, string $language, Carbon $completedAt): ReadingSessionCompletion
    {
        [$chapter, $session] = $this->activeReadingSession($user, $language);
        $session->forceFill([
            'status' => ReadingSession::STATUS_COMPLETED,
            'completed_at' => $completedAt,
        ])->save();

        return ReadingSessionCompletion::forceCreate([
            'reading_session_id' => $session->id,
            'user_id' => $user->id,
            'language_id' => $language,
            'chapter_id' => $chapter->id,
            'source_revision' => $session->source_revision,
            'result' => [
                'success' => true,
                'completed' => true,
                'reading_session_id' => $session->uuid,
            ],
        ]);
    }

    /** @return array{0: Chapter, 1: ReadingSession} */
    private function activeReadingSession(User $user, string $language): array
    {
        $chapter = $this->chapter($user, $language);
        $started = app(ReadingSessionService::class)->startSession(
            $user->id,
            $language,
            $chapter->id,
        );
        $session = ReadingSession::query()
            ->where('uuid', $started['reading_session_id'])
            ->firstOrFail();

        return [$chapter, $session];
    }

    private function businessFingerprint(User $user): array
    {
        return [
            'review_setting_presets' => DB::table('review_setting_presets')->where('user_id', $user->id)->count(),
            'review_setting_preset_bindings' => DB::table('review_setting_preset_bindings')->where('user_id', $user->id)->count(),
            'review_logs' => ReviewLog::where('user_id', $user->id)->count(),
            'review_cards' => ReviewCard::where('user_id', $user->id)->count(),
            'reading_sessions' => ReadingSession::where('user_id', $user->id)->count(),
            'reading_completions' => ReadingSessionCompletion::where('user_id', $user->id)->count(),
            'goal_achievements' => DB::table('goal_achievements')->where('user_id', $user->id)->count(),
            'chapter_read_counts' => Chapter::where('user_id', $user->id)
                ->orderBy('id')
                ->pluck('read_count', 'id')
                ->all(),
        ];
    }
}
