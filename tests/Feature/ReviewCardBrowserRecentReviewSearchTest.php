<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewCardBrowserRecentReviewSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::create(2026, 7, 17, 12, 0, 0, 'UTC'));
        $this->user = $this->makeUser('phase8i');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_recent_windows_use_inclusive_start_and_exclusive_tomorrow_boundary(): void
    {
        $today = $this->makeCard('today');
        $start = $this->makeCard('window-start');
        $before = $this->makeCard('before-window');
        $tomorrow = $this->makeCard('tomorrow-boundary');

        $this->makeLog($today, 'good', Carbon::today('UTC')->addHour());
        $this->makeLog($start, 'hard', Carbon::today('UTC')->subDays(6));
        $this->makeLog($before, 'again', Carbon::today('UTC')->subDays(6)->subSecond());
        $this->makeLog($tomorrow, 'easy', Carbon::tomorrow('UTC'));

        $response = $this->search('rated:7');
        $response->assertStatus(200);

        $ids = $this->ids($response->json('items'));
        $this->assertContains($today->id, $ids);
        $this->assertContains($start->id, $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($tomorrow->id, $ids);
    }

    public function test_one_thirty_and_365_day_windows_follow_the_frozen_natural_day_contract(): void
    {
        $today = $this->makeCard('today-window');
        $dayThirtyStart = $this->makeCard('thirty-day-start');
        $day365Start = $this->makeCard('365-day-start');
        $tooOld = $this->makeCard('older-than-365');

        $this->makeLog($today, 'good', Carbon::today('UTC')->addHour());
        $this->makeLog($dayThirtyStart, 'hard', Carbon::today('UTC')->subDays(29));
        $this->makeLog($day365Start, 'again', Carbon::today('UTC')->subDays(364));
        $this->makeLog($tooOld, 'easy', Carbon::today('UTC')->subDays(365));

        $this->assertSame([$today->id], $this->ids($this->search('rated:1')->json('items')));
        $this->assertSame(
            $this->sorted([$today->id, $dayThirtyStart->id]),
            $this->ids($this->search('rated:30')->json('items')),
        );
        $this->assertSame(
            $this->sorted([$today->id, $dayThirtyStart->id, $day365Start->id]),
            $this->ids($this->search('rated:365')->json('items')),
        );
    }

    public function test_rating_codes_map_to_again_hard_good_and_easy(): void
    {
        $ratings = [1 => 'again', 2 => 'hard', 3 => 'good', 4 => 'easy'];
        $cards = [];
        foreach ($ratings as $code => $rating) {
            $cards[$code] = $this->makeCard('rating-' . $rating);
            $this->makeLog($cards[$code], $rating, Carbon::today('UTC')->addHours($code));
        }

        foreach ($ratings as $code => $rating) {
            $response = $this->search('rated:1:' . $code);
            $response->assertStatus(200);
            $this->assertSame([$cards[$code]->id], $this->ids($response->json('items')), $rating);
        }
    }

    public function test_recent_search_excludes_non_formal_undone_foreign_legacy_and_unconfirmed_history(): void
    {
        $matching = $this->makeCard('matching');
        $this->makeLog($matching, 'good', now());

        $nonFormal = $this->makeCard('non-formal');
        $this->makeLog($nonFormal, 'good', now(), 'review');

        $reset = $this->makeCard('reset');
        $this->makeLog($reset, 'reset', now(), 'reset');

        $undone = $this->makeCard('undone');
        $this->makeLog($undone, 'good', now(), 'sense_review', now());

        $otherUser = $this->makeUser('phase8i-other');
        $foreign = $this->makeCard('foreign', $otherUser);
        $this->makeLog($foreign, 'good', now());

        $otherLanguage = $this->makeCard('french', $this->user, 'french');
        $this->makeLog($otherLanguage, 'good', now());

        $unconfirmed = $this->makeCard('unconfirmed', $this->user, 'english', WordSense::STATUS_REJECTED);
        $this->makeLog($unconfirmed, 'good', now());

        $legacy = $this->makeCard('legacy', $this->user, 'english', WordSense::STATUS_CONFIRMED, ReviewCard::TARGET_WORD);
        $this->makeLog($legacy, 'good', now());

        $response = $this->search('rated:1');
        $response->assertStatus(200);
        $this->assertSame([$matching->id], $this->ids($response->json('items')));
    }

    public function test_multiple_numeric_tokens_are_independent_and_combined_with_and(): void
    {
        $matching = $this->makeCard('matching-numeric-and');
        $this->makeLog($matching, 'again', Carbon::today('UTC')->subDay());
        $this->makeLog($matching, 'easy', Carbon::today('UTC')->addHour());

        $againOnly = $this->makeCard('again-only');
        $this->makeLog($againOnly, 'again', Carbon::today('UTC')->subDay());

        $easyOnly = $this->makeCard('easy-only');
        $this->makeLog($easyOnly, 'easy', Carbon::today('UTC')->addHour());

        $response = $this->search('rated:7:1 rated:7:4');
        $response->assertStatus(200);
        $this->assertSame([$matching->id], $this->ids($response->json('items')));
    }

    public function test_numeric_tokens_and_symbolic_tokens_are_independent_and_combined_with_and(): void
    {
        $matching = $this->makeCard('matching-combination');
        $this->makeLog($matching, 'again', Carbon::today('UTC')->subDay());
        $this->makeLog($matching, 'easy', Carbon::today('UTC')->addHour());

        $recentOnly = $this->makeCard('recent-only');
        $this->makeLog($recentOnly, 'easy', Carbon::today('UTC')->addHour());

        $lifetimeOnly = $this->makeCard('lifetime-only');
        $this->makeLog($lifetimeOnly, 'again', Carbon::today('UTC')->subDays(10));

        $response = $this->search('rated:7:4 rated:again');
        $response->assertStatus(200);
        $this->assertSame([$matching->id], $this->ids($response->json('items')));
    }

    public function test_recent_results_match_list_and_all_export_consumers(): void
    {
        $matching = $this->makeCard('phase8i-export-match');
        $this->makeLog($matching, 'good', now());
        $old = $this->makeCard('phase8i-export-old');
        $this->makeLog($old, 'good', Carbon::today('UTC')->subDays(8));

        $query = urlencode('rated:7:3');
        $base = '/review-cards/manage/';
        $list = $this->actingAs($this->user)->getJson($base . 'data?filter=all&per_page=50&q=' . $query);
        $json = $this->actingAs($this->user)->getJson($base . 'export?filter=all&q=' . $query);
        $csv = $this->actingAs($this->user)->get($base . 'export-csv?filter=all&q=' . $query);
        $tsv = $this->actingAs($this->user)->get($base . 'export-anki-tsv?filter=all&q=' . $query);

        $list->assertStatus(200);
        $json->assertStatus(200);
        $csv->assertStatus(200);
        $tsv->assertStatus(200);
        $this->assertSame([$matching->id], $this->ids($list->json('items')));
        $this->assertSame([$matching->id], $this->ids($json->json('items')));
        $this->assertSame('1', $csv->headers->get('X-Export-Count'));
        $this->assertSame('1', $tsv->headers->get('X-Export-Count'));
        $this->assertStringContainsString('phase8i-export-match', $csv->getContent());
        $this->assertStringContainsString('phase8i-export-match', $tsv->getContent());
    }

    public function test_recent_search_returns_structured_422_is_read_only_and_has_constant_query_shape(): void
    {
        $invalid = $this->search('rated:366');
        $invalid->assertStatus(422)->assertJsonPath('code', 'invalid_browser_search');

        for ($i = 0; $i < 5; $i++) {
            $card = $this->makeCard('readonly-' . $i);
            $this->makeLog($card, 'good', now()->subDays($i));
        }

        $before = [ReviewCard::count(), ReviewLog::count(), WordSense::count()];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->search('rated:7');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertStatus(200);
        $this->assertLessThan(25, $queryCount);
        $this->assertSame($before, [ReviewCard::count(), ReviewLog::count(), WordSense::count()]);
    }

    private function search(string $query)
    {
        return $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=all&per_page=100&q=' . urlencode($query));
    }

    private function ids(array $items): array
    {
        return $this->sorted(array_map('intval', array_column($items, 'review_card_id')));
    }

    private function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }

    private function makeUser(string $prefix): User
    {
        return User::forceCreate([
            'name' => $prefix,
            'email' => $prefix . '-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function makeCard(
        string $lemma,
        ?User $user = null,
        string $language = 'english',
        string $senseStatus = WordSense::STATUS_CONFIRMED,
        string $targetType = ReviewCard::TARGET_SENSE,
    ): ReviewCard {
        $user ??= $this->user;
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '释义',
            'sense_en' => 'definition',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Example.',
            'status' => $senseStatus,
            'sense_key' => hash('sha256', $lemma . Str::uuid()),
        ]);

        return ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'target_type' => $targetType,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);
    }

    private function makeLog(
        ReviewCard $card,
        string $rating,
        Carbon $reviewedAt,
        string $source = 'sense_review',
        ?Carbon $undoneAt = null,
    ): ReviewLog {
        return ReviewLog::forceCreate([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => $reviewedAt,
            'previous_state' => 'review',
            'new_state' => 'review',
            'previous_due_at' => $reviewedAt->copy()->subDay(),
            'new_due_at' => $reviewedAt->copy()->addDay(),
            'previous_stability' => 1.0,
            'new_stability' => 1.5,
            'previous_difficulty' => 5.0,
            'new_difficulty' => 5.0,
            'source' => $source,
            'undone_at' => $undoneAt,
        ]);
    }
}
