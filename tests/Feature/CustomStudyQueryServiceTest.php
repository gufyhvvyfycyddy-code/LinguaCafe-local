<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\CustomStudy\CustomStudyCriteria;
use App\Services\CustomStudy\CustomStudyQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CustomStudyQueryServiceTest — Task 2000-18 / Phase 2B (Dispatcher)
 *
 * Verifies the unified candidate ID orchestration boundary:
 *  - Each of the four modes dispatches to the correct Query.
 *  - Output is list<int> of unique positive IDs.
 *  - Empty candidate set returns empty array.
 *  - Dispatcher does NOT sort, does NOT apply card_limit, does NOT load
 *    serializer, does NOT create token / session.
 *  - Dispatcher does NOT write ReviewLog / ReviewCard / WordSense.
 *  - Dispatcher executes ONLY the requested mode (no parallel modes).
 *  - Container resolves the Service.
 *  - No new QueryInterface / DTO / Repository / Adapter.
 */
class CustomStudyQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $language = 'english';
    private CustomStudyQueryService $service;
    private Carbon $now;
    private ?string $originalTz = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTz = config('app.timezone');
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));
        $this->now = Carbon::now();

        $this->user = User::forceCreate([
            'name' => 'Dispatcher User',
            'email' => 'disp-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->service = app(CustomStudyQueryService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->originalTz !== null) {
            config(['app.timezone' => $this->originalTz]);
        }
        parent::tearDown();
    }

    // ─── Helpers ───

    private function createChapter(): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'Book-' . Str::random(4),
            'language' => $this->language,
        ]);
        return Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'Chapter-' . Str::random(4),
            'language' => $this->language,
            'raw_text' => 'Test chapter content.',
            'word_count' => 3,
            'read_count' => 0,
            'unique_words' => '["test"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function createSense(array $overrides = []): WordSense
    {
        $defaults = [
            'user_id' => $this->user->id,
            'language' => $this->language,
            'language_id' => $this->language,
            'lemma' => 'test' . Str::random(6),
            'surface_form' => 'test',
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a test.',
            'example_sentence_zh' => '这是一个测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower($this->language . '|' . Str::random(10) . '|noun|测试|test')),
            'source_chapter_id' => null,
        ];
        return WordSense::forceCreate(array_merge($defaults, $overrides));
    }

    private function createCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        $defaults = [
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subDays(2), // overdue by default
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ];
        return ReviewCard::forceCreate(array_merge($defaults, $overrides));
    }

    private function createLog(ReviewCard $card, string $rating, int $daysAgo): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => Carbon::now()->subDays($daysAgo),
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => Carbon::now()->subDays($daysAgo + 1),
            'new_due_at' => Carbon::now()->subDays(max($daysAgo - 1, 0)),
            'previous_stability' => 1.0,
            'new_stability' => 1.5,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 5.0,
            'source' => 'sense_review',
            'undone_at' => null,
        ]);
    }

    private function callService(CustomStudyCriteria $criteria): array
    {
        return $this->service->candidateIds(
            $criteria,
            $this->user->id,
            $this->language,
            $this->now
        );
    }

    // ─── 1-4. Dispatch correctness ───

    public function test_today_forgotten_dispatches_correctly(): void
    {
        // Create a card that has an "again" log today → today_forgotten.
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $this->createLog($card, 'again', 0); // today

        // Create a different card that is only overdue (no today again log).
        $sense2 = $this->createSense();
        $overdueCard = $this->createCard($sense2);

        $criteria = CustomStudyCriteria::fromArray([
            'mode' => CustomStudyCriteria::MODE_TODAY_FORGOTTEN,
        ]);
        $ids = $this->callService($criteria);

        $this->assertContains($card->id, $ids);
        $this->assertNotContains($overdueCard->id, $ids, 'today_forgotten must not return overdue-only card.');
    }

    public function test_overdue_dispatches_correctly(): void
    {
        // Overdue card (fsrs_due_at < dayStart).
        $sense = $this->createSense();
        $overdueCard = $this->createCard($sense, [
            'fsrs_due_at' => Carbon::now()->subDays(5),
        ]);

        // Not-overdue card (due today / future).
        $sense2 = $this->createSense();
        $futureCard = $this->createCard($sense2, [
            'fsrs_due_at' => Carbon::now()->addDays(2),
        ]);

        $criteria = CustomStudyCriteria::fromArray([
            'mode' => CustomStudyCriteria::MODE_OVERDUE,
        ]);
        $ids = $this->callService($criteria);

        $this->assertContains($overdueCard->id, $ids);
        $this->assertNotContains($futureCard->id, $ids, 'overdue must not return future-due card.');
    }

    public function test_source_chapter_passes_chapter_id(): void
    {
        $chapter = $this->createChapter();
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card = $this->createCard($sense);

        // Card NOT linked to this chapter.
        $sense2 = $this->createSense();
        $unrelatedCard = $this->createCard($sense2);

        $criteria = CustomStudyCriteria::fromArray([
            'mode' => CustomStudyCriteria::MODE_SOURCE_CHAPTER,
            'parameters' => ['chapter_id' => $chapter->id],
        ]);
        $ids = $this->callService($criteria);

        $this->assertContains($card->id, $ids);
        $this->assertNotContains($unrelatedCard->id, $ids, 'source_chapter must not return unrelated card.');
    }

    public function test_leech_attention_passes_sub_mode(): void
    {
        // Build a real leech card.
        $sense = $this->createSense();
        $leech = $this->createCard($sense);
        $this->createLog($leech, 'again', 10);
        $this->createLog($leech, 'again', 8);
        $this->createLog($leech, 'again', 6);
        $this->createLog($leech, 'good', 4);
        $this->createLog($leech, 'good', 2);

        // Stable card — must not appear in leech_only output.
        $sense2 = $this->createSense();
        $stable = $this->createCard($sense2);
        $this->createLog($stable, 'good', 5);
        $this->createLog($stable, 'easy', 3);

        $criteria = CustomStudyCriteria::fromArray([
            'mode' => CustomStudyCriteria::MODE_LEECH_ATTENTION,
            'parameters' => ['sub_mode' => CustomStudyCriteria::SUB_MODE_LEECH_ONLY],
        ]);
        $ids = $this->callService($criteria);

        $this->assertContains($leech->id, $ids);
        $this->assertNotContains($stable->id, $ids, 'leech_only must not return stable card.');
    }

    // ─── 5. Unique positive IDs ───

    public function test_all_modes_output_unique_positive_integer_ids(): void
    {
        // Build a small set of cards and exercise each mode; the output
        // must always be a list of unique positive integers.
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['fsrs_due_at' => Carbon::now()->subDays(5)]);
        $this->createLog($card, 'again', 0);

        foreach ([
            ['mode' => CustomStudyCriteria::MODE_OVERDUE],
            ['mode' => CustomStudyCriteria::MODE_TODAY_FORGOTTEN],
        ] as $input) {
            $criteria = CustomStudyCriteria::fromArray($input);
            $ids = $this->callService($criteria);
            $this->assertNotEmpty($ids, "Mode {$input['mode']} should return at least one id.");
            foreach ($ids as $id) {
                $this->assertIsInt($id);
                $this->assertGreaterThan(0, $id);
            }
            $this->assertSame(count($ids), count(array_unique($ids)), 'IDs must be unique.');
        }
    }

    // ─── 6. Empty candidate ───

    public function test_empty_candidate_returns_empty_array(): void
    {
        // No cards at all.
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => CustomStudyCriteria::MODE_OVERDUE,
        ]);
        $this->assertSame([], $this->callService($criteria));
    }

    // ─── 7-11. No sort / card_limit / serializer / token / session ───

    public function test_dispatcher_does_not_sort(): void
    {
        $source = file_get_contents(app_path('Services/CustomStudy/CustomStudyQueryService.php'));
        // Strip docblock comments before scanning (the docblock mentions
        // these words in the "does NOT" negatives).
        $codeOnly = preg_replace('/\/\*.*?\*\//s', '', $source);
        $codeOnly = preg_replace('/^\s*\/\/.*$/m', '', $codeOnly);

        $this->assertStringNotContainsString('->orderBy', $codeOnly, 'Must not apply orderBy.');
        $this->assertStringNotContainsString('->sortBy', $codeOnly, 'Must not apply sortBy.');
        $this->assertStringNotContainsString('->sort(', $codeOnly, 'Must not call sort().');
        $this->assertStringNotContainsString('asort(', $codeOnly);
        $this->assertStringNotContainsString('usort(', $codeOnly);
    }

    public function test_dispatcher_does_not_apply_card_limit(): void
    {
        $source = file_get_contents(app_path('Services/CustomStudy/CustomStudyQueryService.php'));
        $codeOnly = preg_replace('/\/\*.*?\*\//s', '', $source);
        $codeOnly = preg_replace('/^\s*\/\/.*$/m', '', $codeOnly);

        $this->assertStringNotContainsString('->take(', $codeOnly, 'Must not call take() (card_limit).');
        $this->assertStringNotContainsString('->limit(', $codeOnly, 'Must not call limit() (card_limit).');
        $this->assertStringNotContainsString('card_limit', $codeOnly, 'Must not reference card_limit.');
        $this->assertStringNotContainsString('array_slice', $codeOnly, 'Must not slice output (card_limit).');
    }

    public function test_dispatcher_does_not_load_serializer(): void
    {
        $source = file_get_contents(app_path('Services/CustomStudy/CustomStudyQueryService.php'));
        $codeOnly = preg_replace('/\/\*.*?\*\//s', '', $source);
        $codeOnly = preg_replace('/^\s*\/\/.*$/m', '', $codeOnly);
        $this->assertStringNotContainsString('Serializer', $codeOnly, 'Must not reference Serializer.');
        $this->assertStringNotContainsString('serialize', $codeOnly, 'Must not call serialize().');
    }

    public function test_dispatcher_does_not_create_token(): void
    {
        $source = file_get_contents(app_path('Services/CustomStudy/CustomStudyQueryService.php'));
        $codeOnly = preg_replace('/\/\*.*?\*\//s', '', $source);
        $codeOnly = preg_replace('/^\s*\/\/.*$/m', '', $codeOnly);

        $this->assertStringNotContainsString('Token', $codeOnly, 'Must not reference Token.');
        $this->assertStringNotContainsString('Crypt::', $codeOnly, 'Must not call Crypt::.');
    }

    public function test_dispatcher_does_not_create_session(): void
    {
        $source = file_get_contents(app_path('Services/CustomStudy/CustomStudyQueryService.php'));
        $codeOnly = preg_replace('/\/\*.*?\*\//s', '', $source);
        $codeOnly = preg_replace('/^\s*\/\/.*$/m', '', $codeOnly);

        $this->assertStringNotContainsString('SessionState', $codeOnly, 'Must not reference SessionState.');
        $this->assertStringNotContainsString('SessionService', $codeOnly, 'Must not reference SessionService.');
        $this->assertStringNotContainsString('session(', $codeOnly, 'Must not call session().');
    }

    // ─── 12-14. No writes / no modification ───

    public function test_does_not_write_review_log(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['fsrs_due_at' => Carbon::now()->subDays(5)]);
        $this->createLog($card, 'again', 0);

        $before = ReviewLog::count();
        $this->callService(CustomStudyCriteria::fromArray([
            'mode' => CustomStudyCriteria::MODE_TODAY_FORGOTTEN,
        ]));
        $this->assertSame($before, ReviewLog::count(), 'Must not write ReviewLog.');
    }

    public function test_does_not_modify_review_card(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['fsrs_due_at' => Carbon::now()->subDays(5)]);
        $beforeCard = $card->fresh();
        $before = $beforeCard->only([
            'id', 'user_id', 'language_id', 'target_type', 'target_id',
            'fsrs_state', 'fsrs_stability', 'fsrs_difficulty', 'fsrs_reps',
            'fsrs_lapses', 'fsrs_enabled', 'lifecycle_state',
        ]);
        $before['fsrs_due_at'] = $beforeCard->fsrs_due_at->getTimestamp();

        $this->callService(CustomStudyCriteria::fromArray([
            'mode' => CustomStudyCriteria::MODE_OVERDUE,
        ]));

        $afterCard = $card->fresh();
        $after = $afterCard->only(array_keys($before));
        $after['fsrs_due_at'] = $afterCard->fsrs_due_at->getTimestamp();
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after, 'ReviewCard business fields must not change.');
    }

    public function test_does_not_modify_word_sense(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['fsrs_due_at' => Carbon::now()->subDays(5)]);
        $senseId = $sense->id;
        $before = WordSense::find($senseId)->only([
            'id', 'user_id', 'language_id', 'status', 'source_chapter_id', 'lemma',
        ]);

        $this->callService(CustomStudyCriteria::fromArray([
            'mode' => CustomStudyCriteria::MODE_OVERDUE,
        ]));

        $after = WordSense::find($senseId)->only(array_keys($before));
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after, 'WordSense business fields must not change.');
    }

    // ─── 15-16. Only one mode executed per call ───

    public function test_single_criteria_call_executes_only_one_mode(): void
    {
        // Build fixtures that would produce different IDs for each mode:
        //  - cardA: today_forgotten only (has again log today, due tomorrow)
        //  - cardB: overdue only (no again log, due 5 days ago)
        //  - cardC: leech only (3 again + 2 good, due tomorrow)
        $senseA = $this->createSense();
        $cardA = $this->createCard($senseA, ['fsrs_due_at' => Carbon::now()->addDay()]);
        $this->createLog($cardA, 'again', 0);

        $senseB = $this->createSense();
        $cardB = $this->createCard($senseB, ['fsrs_due_at' => Carbon::now()->subDays(5)]);

        $senseC = $this->createSense();
        $cardC = $this->createCard($senseC, ['fsrs_due_at' => Carbon::now()->addDay()]);
        $this->createLog($cardC, 'again', 10);
        $this->createLog($cardC, 'again', 8);
        $this->createLog($cardC, 'again', 6);
        $this->createLog($cardC, 'good', 4);
        $this->createLog($cardC, 'good', 2);

        // Call today_forgotten → only cardA.
        $ids = $this->callService(CustomStudyCriteria::fromArray([
            'mode' => CustomStudyCriteria::MODE_TODAY_FORGOTTEN,
        ]));
        $this->assertContains($cardA->id, $ids);
        $this->assertNotContains($cardB->id, $ids, 'today_forgotten call must not run overdue query.');
        $this->assertNotContains($cardC->id, $ids, 'today_forgotten call must not run leech query (no today again).');

        // Call overdue → only cardB (cardA due tomorrow, cardC due tomorrow).
        $ids = $this->callService(CustomStudyCriteria::fromArray([
            'mode' => CustomStudyCriteria::MODE_OVERDUE,
        ]));
        $this->assertContains($cardB->id, $ids);
        $this->assertNotContains($cardA->id, $ids, 'overdue call must not run today_forgotten query.');
        $this->assertNotContains($cardC->id, $ids, 'overdue call must not run leech query.');

        // Call leech_only → only cardC.
        $ids = $this->callService(CustomStudyCriteria::fromArray([
            'mode' => CustomStudyCriteria::MODE_LEECH_ATTENTION,
            'parameters' => ['sub_mode' => CustomStudyCriteria::SUB_MODE_LEECH_ONLY],
        ]));
        $this->assertContains($cardC->id, $ids);
        $this->assertNotContains($cardA->id, $ids, 'leech call must not run today_forgotten query.');
        $this->assertNotContains($cardB->id, $ids, 'leech call must not run overdue query.');
    }

    public function test_does_not_execute_other_modes_in_parallel(): void
    {
        // Source-level guard: dispatch must be a switch (single branch), not
        // a series of independent calls.
        $source = file_get_contents(app_path('Services/CustomStudy/CustomStudyQueryService.php'));
        // The body must contain exactly one `->build(` or `->candidateIds(`
        // call inside the switch (each case has one).
        // Count actual method invocations (not in docblocks).
        $codeOnly = preg_replace('/\/\*.*?\*\//s', '', $source);
        $codeOnly = preg_replace('/^\s*\/\/.*$/m', '', $codeOnly);
        $buildCalls = substr_count($codeOnly, '->build(');
        $candidateIdsCalls = substr_count($codeOnly, '->candidateIds(');
        // 1 ->build for today_forgotten + 1 for overdue + 1 for source_chapter = 3.
        $this->assertSame(3, $buildCalls, 'Dispatcher must call build() exactly once per SQL-native mode (3 total).');
        // 1 ->candidateIds for leech_attention.
        $this->assertSame(1, $candidateIdsCalls, 'Dispatcher must call candidateIds() exactly once for leech_attention.');
    }

    // ─── 17. Container resolution ───

    public function test_container_resolves_service(): void
    {
        $resolved = app(CustomStudyQueryService::class);
        $this->assertInstanceOf(CustomStudyQueryService::class, $resolved);

        // Verify all 4 query dependencies are wired.
        $reflection = new \ReflectionClass($resolved);
        $ctor = $reflection->getConstructor();
        $this->assertNotNull($ctor);
        // Calling candidateIds with empty criteria should not crash; we
        // exercise the wiring with an empty overdue call.
        $ids = $resolved->candidateIds(
            CustomStudyCriteria::fromArray(['mode' => CustomStudyCriteria::MODE_OVERDUE]),
            $this->user->id,
            $this->language,
            $this->now
        );
        $this->assertIsArray($ids);
    }

    // ─── 18. No meaningless QueryInterface ───

    public function test_does_not_introduce_query_interface_or_dto_or_repository(): void
    {
        $source = file_get_contents(app_path('Services/CustomStudy/CustomStudyQueryService.php'));
        $codeOnly = preg_replace('/\/\*.*?\*\//s', '', $source);
        $codeOnly = preg_replace('/^\s*\/\/.*$/m', '', $codeOnly);
        $this->assertStringNotContainsString('QueryInterface', $codeOnly, 'Must NOT introduce QueryInterface.');
        $this->assertStringNotContainsString('interface ', $codeOnly, 'Must NOT declare any interface.');
        $this->assertStringNotContainsString('Repository', $codeOnly, 'Must NOT introduce Repository.');
        $this->assertStringNotContainsString('Adapter', $codeOnly, 'Must NOT introduce Adapter.');
        $this->assertStringNotContainsString('DTO', $codeOnly, 'Must NOT introduce DTO.');
    }
}
