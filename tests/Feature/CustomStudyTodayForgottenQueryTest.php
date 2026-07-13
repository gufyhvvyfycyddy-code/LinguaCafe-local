<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\CustomStudy\Queries\TodayForgottenQuery;
use App\Services\ReviewStudyTimezoneService;
use App\Services\SenseReviewQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Custom Study Phase 2A — CS-3: TodayForgottenQuery Feature tests.
 *
 * Task 2000-17 §8.1 — 25-item test matrix.
 *
 * Verifies:
 *  1.  Correct Again log is included.
 *  2.  Hard / Good / Easy ratings NOT included.
 *  3.  Non-sense_review source NOT included.
 *  4.  Undone Again NOT included.
 *  5.  Yesterday's Again NOT included.
 *  6.  Next natural day's Again NOT included.
 *  7.  Current local natural day boundary.
 *  8.  UTC and non-UTC learning timezone.
 *  9.  DST boundary (at least one case).
 *  10. Multiple Again logs on same card don't produce duplicate cards.
 *  11. Other users' cards NOT leaked.
 *  12. Other languages' cards NOT leaked.
 *  13. Legacy word cards NOT included.
 *  14. Pending/rejected sense NOT included.
 *  15. Suspended cards NOT included.
 *  16. Archived cards NOT included.
 *  17. Future buried NOT included.
 *  18. Expired buried CAN be included.
 *  19. fsrs_enabled=false NOT included.
 *  20. Returns Builder.
 *  21. Pluck returns unique card IDs.
 *  22. Does NOT write ReviewLog.
 *  23. Does NOT modify ReviewCard.
 *  24. Does NOT modify WordSense.
 *  25. No N+1 query.
 */
class CustomStudyTodayForgottenQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $language = 'english';
    private ?string $originalTz = null;
    private TodayForgottenQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTz = config('app.timezone');

        $this->user = User::forceCreate([
            'name' => 'CS TF Test',
            'email' => 'cs-tf-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->query = new TodayForgottenQuery(
            app(SenseReviewQueryService::class),
            app(ReviewStudyTimezoneService::class)
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->originalTz !== null) {
            config(['app.timezone' => $this->originalTz]);
        }
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────

    private function setTimezone(string $tz): void
    {
        config(['app.timezone' => $tz]);
    }

    private function createSense(string $lemma, string $status = WordSense::STATUS_CONFIRMED, ?int $userId = null, string $language = 'english'): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $userId ?? $this->user->id,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Test sentence.',
            'example_sentence_zh' => '测试句子。',
            'status' => $status,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("{$language}|{$lemma}|noun|测试")),
        ]);
    }

    private function createCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language_id' => $this->language,
            'language' => $this->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subMinutes(5),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ], $overrides));
    }

    private function createReviewLog(ReviewCard $card, Carbon $reviewedAt, string $rating = 'again', string $source = 'sense_review', ?Carbon $undoneAt = null, ?int $userId = null, string $language = 'english'): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $userId ?? $this->user->id,
            'language_id' => $language,
            'language' => $language,
            'review_card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => $reviewedAt,
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => Carbon::now()->subDays(1),
            'new_due_at' => Carbon::now()->addDays(3),
            'source' => $source,
            'undone_at' => $undoneAt,
        ]);
    }

    private function pluckIds(): array
    {
        return $this->query->build($this->user->id, $this->language, Carbon::now())
            ->pluck('review_cards.id')
            ->sort()
            ->values()
            ->all();
    }

    // ── 1. Correct Again log is included ───────────

    public function test_correct_again_log_is_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $ids = $this->pluckIds();
        $this->assertSame([$card->id], $ids);
    }

    // ── 2. Hard / Good / Easy NOT included ───────────

    public function test_hard_rating_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'hard');

        $this->assertSame([], $this->pluckIds());
    }

    public function test_good_rating_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'good');

        $this->assertSame([], $this->pluckIds());
    }

    public function test_easy_rating_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'easy');

        $this->assertSame([], $this->pluckIds());
    }

    // ── 3. Non-sense_review source NOT included ───────────

    public function test_non_sense_review_source_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again', 'review');

        $this->assertSame([], $this->pluckIds());
    }

    // ── 4. Undone Again NOT included ───────────

    public function test_undone_again_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog(
            $card,
            Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'),
            'again',
            'sense_review',
            Carbon::create(2026, 7, 14, 11, 0, 0, 'UTC')
        );

        $this->assertSame([], $this->pluckIds());
    }

    // ── 5. Yesterday's Again NOT included ───────────

    public function test_yesterday_again_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 13, 23, 0, 0, 'UTC'), 'again');

        $this->assertSame([], $this->pluckIds());
    }

    // ── 6. Next natural day's Again NOT included ───────────

    public function test_next_day_again_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 15, 0, 0, 0, 'UTC'), 'again');

        $this->assertSame([], $this->pluckIds());
    }

    // ── 7. Current local natural day boundary ───────────

    public function test_current_local_day_boundary(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 0, 0, 1, 'UTC'));

        $sense = $this->createSense('alpha');
        $card1 = $this->createCard($sense);

        // 00:00:00 exactly — should be included (>= dayStart)
        $this->createReviewLog($card1, Carbon::create(2026, 7, 14, 0, 0, 0, 'UTC'), 'again');

        $ids = $this->pluckIds();
        $this->assertContains($card1->id, $ids);
    }

    // ── 8. UTC and non-UTC learning timezone ───────────

    public function test_utc_timezone(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 15, 30, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 8, 0, 0, 'UTC'), 'again');

        $this->assertSame([$card->id], $this->pluckIds());
    }

    public function test_non_utc_timezone(): void
    {
        // America/Los_Angeles is UTC-7 in July (PDT)
        $this->setTimezone('America/Los_Angeles');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 22, 0, 0, 'America/Los_Angeles'));

        // 2026-07-14 22:00 LA = 2026-07-15 05:00 UTC
        // Local day is 2026-07-14 00:00 LA to 2026-07-15 00:00 LA
        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);

        // 2026-07-14 06:00 LA = 2026-07-14 13:00 UTC — within local day
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 6, 0, 0, 'America/Los_Angeles'), 'again');

        $this->assertSame([$card->id], $this->pluckIds());
    }

    // ── 9. DST boundary (at least one case) ───────────

    public function test_dst_boundary(): void
    {
        // America/New_York: DST spring-forward 2026-03-08 02:00 → 03:00
        // On 2026-03-08, the local day boundary is 00:00 EST (UTC-5)
        $this->setTimezone('America/New_York');
        Carbon::setTestNow(Carbon::create(2026, 3, 8, 14, 0, 0, 'America/New_York'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);

        // 2026-03-08 01:30 EST — before spring-forward, within local day
        $this->createReviewLog($card, Carbon::create(2026, 3, 8, 1, 30, 0, 'America/New_York'), 'again');

        $this->assertSame([$card->id], $this->pluckIds());
    }

    // ── 10. Multiple Again logs on same card don't produce duplicate cards ───────────

    public function test_multiple_again_logs_no_duplicate_cards(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 8, 0, 0, 'UTC'), 'again');
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 9, 0, 0, 'UTC'), 'again');
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $ids = $this->pluckIds();
        $this->assertCount(1, $ids);
        $this->assertSame([$card->id], $ids);
    }

    // ── 11. Other users' cards NOT leaked ───────────

    public function test_other_users_not_leaked(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => 'other-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $otherSense = $this->createSense('other_alpha', WordSense::STATUS_CONFIRMED, $otherUser->id);
        $otherCard = ReviewCard::forceCreate([
            'user_id' => $otherUser->id,
            'language_id' => $this->language,
            'language' => $this->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherSense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subMinutes(5),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);
        $this->createReviewLog($otherCard, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again', 'sense_review', null, $otherUser->id);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 12. Other languages' cards NOT leaked ───────────

    public function test_other_languages_not_leaked(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $otherLang = 'japanese';
        $otherSense = $this->createSense('alpha_jp', WordSense::STATUS_CONFIRMED, $this->user->id, $otherLang);
        $otherCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => $otherLang,
            'language' => $otherLang,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherSense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subMinutes(5),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);
        $this->createReviewLog($otherCard, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again', 'sense_review', null, $this->user->id, $otherLang);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 13. Legacy word cards NOT included ───────────

    public function test_legacy_word_card_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $wordCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => $this->language,
            'language' => $this->language,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 999,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subMinutes(5),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);
        $this->createReviewLog($wordCard, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $this->assertSame([], $this->pluckIds());
    }

    // ── 14. Pending/rejected sense NOT included ───────────

    public function test_pending_sense_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha', WordSense::STATUS_AI_SUGGESTED);
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $this->assertSame([], $this->pluckIds());
    }

    public function test_rejected_sense_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha', WordSense::STATUS_REJECTED);
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $this->assertSame([], $this->pluckIds());
    }

    // ── 15. Suspended cards NOT included ───────────

    public function test_suspended_card_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, ['lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED]);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $this->assertSame([], $this->pluckIds());
    }

    // ── 16. Archived cards NOT included ───────────

    public function test_archived_card_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, ['lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED]);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $this->assertSame([], $this->pluckIds());
    }

    // ── 17. Future buried NOT included ───────────

    public function test_future_buried_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_BURIED,
            'buried_until' => Carbon::create(2026, 7, 15, 0, 0, 0, 'UTC'),
        ]);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $this->assertSame([], $this->pluckIds());
    }

    // ── 18. Expired buried CAN be included ───────────

    public function test_expired_buried_can_be_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_BURIED,
            'buried_until' => Carbon::create(2026, 7, 14, 6, 0, 0, 'UTC'),
        ]);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $ids = $this->pluckIds();
        $this->assertContains($card->id, $ids);
    }

    // ── 19. fsrs_enabled=false NOT included ───────────

    public function test_fsrs_disabled_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, ['fsrs_enabled' => false]);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $this->assertSame([], $this->pluckIds());
    }

    // ── 20. Returns Builder ───────────

    public function test_returns_builder(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $builder = $this->query->build($this->user->id, $this->language, Carbon::now());

        $this->assertInstanceOf(Builder::class, $builder);
    }

    // ── 21. Pluck returns unique card IDs ───────────

    public function test_pluck_returns_unique_card_ids(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense1 = $this->createSense('alpha');
        $card1 = $this->createCard($sense1);
        $this->createReviewLog($card1, Carbon::create(2026, 7, 14, 8, 0, 0, 'UTC'), 'again');

        $sense2 = $this->createSense('bravo');
        $card2 = $this->createCard($sense2);
        $this->createReviewLog($card2, Carbon::create(2026, 7, 14, 9, 0, 0, 'UTC'), 'again');

        $ids = $this->query->build($this->user->id, $this->language, Carbon::now())
            ->pluck('review_cards.id')
            ->all();

        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1]);
        sort($ids);
        $expected = [$card1->id, $card2->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    // ── 22. Does NOT write ReviewLog ───────────

    public function test_does_not_write_review_log(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $countBefore = ReviewLog::count();
        $this->query->build($this->user->id, $this->language, Carbon::now())->get();
        $countAfter = ReviewLog::count();

        $this->assertSame($countBefore, $countAfter);
    }

    // ── 23. Does NOT modify ReviewCard ───────────

    public function test_does_not_modify_review_card(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $snapshot = $card->fresh()->toArray();
        $this->query->build($this->user->id, $this->language, Carbon::now())->get();
        $after = $card->fresh()->toArray();

        $this->assertSame($snapshot, $after);
    }

    // ── 24. Does NOT modify WordSense ───────────

    public function test_does_not_modify_word_sense(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense);
        $this->createReviewLog($card, Carbon::create(2026, 7, 14, 10, 0, 0, 'UTC'), 'again');

        $snapshot = $sense->fresh()->toArray();
        $this->query->build($this->user->id, $this->language, Carbon::now())->get();
        $after = $sense->fresh()->toArray();

        $this->assertSame($snapshot, $after);
    }

    // ── 25. No N+1 query ───────────

    public function test_no_n_plus_one_query(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        // Create 5 cards with Again logs today
        for ($i = 0; $i < 5; $i++) {
            $sense = $this->createSense('word_' . $i);
            $card = $this->createCard($sense);
            $this->createReviewLog($card, Carbon::create(2026, 7, 14, 8 + $i, 0, 0, 'UTC'), 'again');
        }

        DB::enableQueryLog();
        $this->query->build($this->user->id, $this->language, Carbon::now())->get();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // The query should execute in a single SELECT (no N+1).
        // One query for the main SELECT with whereExists subquery.
        $this->assertCount(1, $queries, 'Expected exactly 1 query, got ' . count($queries));
    }
}
