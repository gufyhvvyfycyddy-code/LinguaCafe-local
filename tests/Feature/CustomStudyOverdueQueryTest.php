<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\CustomStudy\Queries\OverdueQuery;
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
 * Custom Study Phase 2A — CS-4: OverdueQuery Feature tests.
 *
 * Task 2000-17 §8.2 — 22-item test matrix.
 *
 * Verifies:
 *  1.  Yesterday's due included.
 *  2.  Earlier due included.
 *  3.  Exactly equal to dayStart NOT included.
 *  4.  Due later today NOT included.
 *  5.  Tomorrow's due NOT included.
 *  6.  NULL due NOT included.
 *  7.  UTC and non-UTC learning timezone.
 *  8.  DST boundary (at least one case).
 *  9.  Other users' cards NOT leaked.
 *  10. Other languages' cards NOT leaked.
 *  11. Legacy word cards NOT included.
 *  12. Pending/rejected sense NOT included.
 *  13. Suspended cards NOT included.
 *  14. Archived cards NOT included.
 *  15. Future buried NOT included.
 *  16. Expired buried CAN be included.
 *  17. fsrs_enabled=false NOT included.
 *  18. Returns Builder.
 *  19. Does NOT write ReviewLog.
 *  20. Does NOT modify ReviewCard.
 *  21. Does NOT modify WordSense.
 *  22. Single query, no N+1.
 */
class CustomStudyOverdueQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $language = 'english';
    private ?string $originalTz = null;
    private OverdueQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTz = config('app.timezone');

        $this->user = User::forceCreate([
            'name' => 'CS OD Test',
            'email' => 'cs-od-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->query = new OverdueQuery(
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

    private function pluckIds(): array
    {
        return $this->query->build($this->user->id, $this->language, Carbon::now())
            ->pluck('review_cards.id')
            ->sort()
            ->values()
            ->all();
    }

    // ── 1. Yesterday's due included ───────────

    public function test_yesterday_due_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            'fsrs_due_at' => Carbon::create(2026, 7, 13, 10, 0, 0, 'UTC'),
        ]);

        $this->assertSame([$card->id], $this->pluckIds());
    }

    // ── 2. Earlier due included ───────────

    public function test_earlier_due_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            'fsrs_due_at' => Carbon::create(2026, 7, 1, 0, 0, 0, 'UTC'),
        ]);

        $this->assertSame([$card->id], $this->pluckIds());
    }

    // ── 3. Exactly equal to dayStart NOT included ───────────

    public function test_equal_to_daystart_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        // dayStart = 2026-07-14 00:00:00 UTC
        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            'fsrs_due_at' => Carbon::create(2026, 7, 14, 0, 0, 0, 'UTC'),
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 4. Due later today NOT included ───────────

    public function test_due_later_today_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            'fsrs_due_at' => Carbon::create(2026, 7, 14, 18, 0, 0, 'UTC'),
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 5. Tomorrow's due NOT included ───────────

    public function test_tomorrow_due_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            'fsrs_due_at' => Carbon::create(2026, 7, 15, 10, 0, 0, 'UTC'),
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 6. NULL due NOT included ───────────

    public function test_null_due_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            'fsrs_due_at' => null,
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 7. UTC and non-UTC learning timezone ───────────

    public function test_utc_timezone(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 15, 30, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            'fsrs_due_at' => Carbon::create(2026, 7, 13, 22, 0, 0, 'UTC'),
        ]);

        $this->assertSame([$card->id], $this->pluckIds());
    }

    public function test_non_utc_timezone(): void
    {
        // America/Los_Angeles is UTC-7 in July (PDT)
        $this->setTimezone('America/Los_Angeles');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 22, 0, 0, 'America/Los_Angeles'));

        // Local day start: 2026-07-14 00:00 LA = 2026-07-14 07:00 UTC
        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            // 2026-07-13 20:00 LA = 2026-07-14 03:00 UTC — before local day start
            'fsrs_due_at' => Carbon::create(2026, 7, 13, 20, 0, 0, 'America/Los_Angeles'),
        ]);

        $this->assertSame([$card->id], $this->pluckIds());
    }

    // ── 8. DST boundary (at least one case) ───────────

    public function test_dst_boundary(): void
    {
        // America/New_York: DST spring-forward 2026-03-08 02:00 → 03:00
        $this->setTimezone('America/New_York');
        Carbon::setTestNow(Carbon::create(2026, 3, 8, 14, 0, 0, 'America/New_York'));

        // Local day start: 2026-03-08 00:00 EST (UTC-5)
        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            // 2026-03-07 23:00 EST — before local day start
            'fsrs_due_at' => Carbon::create(2026, 3, 7, 23, 0, 0, 'America/New_York'),
        ]);

        $this->assertSame([$card->id], $this->pluckIds());
    }

    // ── 9. Other users' cards NOT leaked ───────────

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
        ReviewCard::forceCreate([
            'user_id' => $otherUser->id,
            'language_id' => $this->language,
            'language' => $this->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherSense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 10. Other languages' cards NOT leaked ───────────

    public function test_other_languages_not_leaked(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $otherLang = 'japanese';
        $otherSense = $this->createSense('alpha_jp', WordSense::STATUS_CONFIRMED, $this->user->id, $otherLang);
        ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => $otherLang,
            'language' => $otherLang,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherSense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 11. Legacy word cards NOT included ───────────

    public function test_legacy_word_card_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => $this->language,
            'language' => $this->language,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 999,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 12. Pending/rejected sense NOT included ───────────

    public function test_pending_sense_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha', WordSense::STATUS_AI_SUGGESTED);
        $this->createCard($sense, [
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    public function test_rejected_sense_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha', WordSense::STATUS_REJECTED);
        $this->createCard($sense, [
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 13. Suspended cards NOT included ───────────

    public function test_suspended_card_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 14. Archived cards NOT included ───────────

    public function test_archived_card_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED,
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 15. Future buried NOT included ───────────

    public function test_future_buried_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_BURIED,
            'buried_until' => Carbon::create(2026, 7, 15, 0, 0, 0, 'UTC'),
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 16. Expired buried CAN be included ───────────

    public function test_expired_buried_can_be_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_BURIED,
            'buried_until' => Carbon::create(2026, 7, 14, 6, 0, 0, 'UTC'),
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
        ]);

        $ids = $this->pluckIds();
        $this->assertContains($card->id, $ids);
    }

    // ── 17. fsrs_enabled=false NOT included ───────────

    public function test_fsrs_disabled_not_included(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $this->createCard($sense, [
            'fsrs_enabled' => false,
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
        ]);

        $this->assertSame([], $this->pluckIds());
    }

    // ── 18. Returns Builder ───────────

    public function test_returns_builder(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $builder = $this->query->build($this->user->id, $this->language, Carbon::now());

        $this->assertInstanceOf(Builder::class, $builder);
    }

    // ── 19. Does NOT write ReviewLog ───────────

    public function test_does_not_write_review_log(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $this->createCard($sense, [
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
        ]);

        $countBefore = ReviewLog::count();
        $this->query->build($this->user->id, $this->language, Carbon::now())->get();
        $countAfter = ReviewLog::count();

        $this->assertSame($countBefore, $countAfter);
    }

    // ── 20. Does NOT modify ReviewCard ───────────

    public function test_does_not_modify_review_card(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $card = $this->createCard($sense, [
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
        ]);

        $snapshot = $card->fresh()->toArray();
        $this->query->build($this->user->id, $this->language, Carbon::now())->get();
        $after = $card->fresh()->toArray();

        $this->assertSame($snapshot, $after);
    }

    // ── 21. Does NOT modify WordSense ───────────

    public function test_does_not_modify_word_sense(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $sense = $this->createSense('alpha');
        $this->createCard($sense, [
            'fsrs_due_at' => Carbon::create(2026, 7, 10, 0, 0, 0, 'UTC'),
        ]);

        $snapshot = $sense->fresh()->toArray();
        $this->query->build($this->user->id, $this->language, Carbon::now())->get();
        $after = $sense->fresh()->toArray();

        $this->assertSame($snapshot, $after);
    }

    // ── 22. Single query, no N+1 ───────────

    public function test_single_query_no_n_plus_one(): void
    {
        $this->setTimezone('UTC');
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        // Create 5 overdue cards
        for ($i = 0; $i < 5; $i++) {
            $sense = $this->createSense('word_' . $i);
            $this->createCard($sense, [
                'fsrs_due_at' => Carbon::create(2026, 7, 10, $i, 0, 0, 'UTC'),
            ]);
        }

        DB::enableQueryLog();
        $this->query->build($this->user->id, $this->language, Carbon::now())->get();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // The query should execute in a single SELECT (no N+1).
        $this->assertCount(1, $queries, 'Expected exactly 1 query, got ' . count($queries));
    }
}
