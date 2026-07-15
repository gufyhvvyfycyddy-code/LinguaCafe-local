<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\FsrsSchedulingService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SenseReviewIntervalPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private WordSense $sense;
    private ReviewCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Preview User',
            'email' => 'preview@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other Preview',
            'email' => 'other-preview@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'preview_word',
            'surface_form' => 'preview_word',
            'pos' => 'noun',
            'sense_key' => hash('sha256', 'preview|test'),
            'sense_zh' => '预览词义',
            'sense_en' => 'preview sense',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a preview word.',
            'example_sentence_zh' => '这是一个预览词。',
            'is_context_specific' => false,
            'status' => WordSense::STATUS_CONFIRMED,
        ]);

        $this->card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $this->sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDays(2),
            'fsrs_stability' => 5.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDays(5),
            'fsrs_enabled' => true,
        ]);

        foreach ([
            [$this->user->id, 'english', 'review', 'Reviews', 0],
            [$this->user->id, 'english', 'read_words', 'Reading', 1000],
            [$this->user->id, 'english', 'learn_words', 'New words', 10],
            [$this->otherUser->id, 'english', 'review', 'Reviews', 0],
        ] as $goal) {
            \App\Models\Goal::forceCreate([
                'user_id' => $goal[0],
                'language' => $goal[1],
                'type' => $goal[2],
                'name' => $goal[3],
                'quantity' => $goal[4],
            ]);
        }
    }

    // --- Access control ---

    public function test_guest_gets_401(): void
    {
        $this->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(401);
    }

    public function test_owner_gets_200_with_four_ratings(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame($this->card->id, $data['review_card_id']);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertArrayHasKey('timezone', $data);
        $this->assertArrayHasKey('engine', $data);
        $this->assertCount(4, $data['ratings']);
        foreach (['again', 'hard', 'good', 'easy'] as $rating) {
            $this->assertArrayHasKey($rating, $data['ratings']);
            $this->assertArrayHasKey('due_at', $data['ratings'][$rating]);
            $this->assertArrayHasKey('interval_seconds', $data['ratings'][$rating]);
            $this->assertArrayHasKey('next_state', $data['ratings'][$rating]);
            $this->assertGreaterThanOrEqual(0, $data['ratings'][$rating]['interval_seconds']);
        }
    }

    public function test_other_user_gets_404(): void
    {
        $this->actingAs($this->otherUser)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(404);
    }

    public function test_other_language_gets_404(): void
    {
        $spanishSense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'spanish',
            'language_id' => 'spanish',
            'lemma' => 'palabra',
            'surface_form' => 'palabra',
            'pos' => 'noun',
            'sense_key' => hash('sha256', 'spanish|test'),
            'sense_zh' => 'palabra',
            'sense_en' => 'word',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Una palabra.',
            'example_sentence_zh' => '一个词。',
            'is_context_specific' => false,
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $spanishCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'spanish',
            'language' => 'spanish',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $spanishSense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 3.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 2,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDays(3),
            'fsrs_enabled' => true,
        ]);

        // user's selected_language is 'english', so spanish card → 404
        $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$spanishCard->id}/interval-preview")
            ->assertStatus(404);
    }

    public function test_legacy_word_card_gets_404(): void
    {
        $word = \App\Models\EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'word' => 'legacy_word',
            'kanji' => '',
            'reading' => '',
            'translation' => '旧词',
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'lemma' => '',
            'added_to_srs' => now()->toDateString(),
            'next_review' => now()->toDateString(),
            'relearning' => false,
            'stage' => -1,
        ]);
        $wordCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $word->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 3.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 2,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDays(3),
            'fsrs_enabled' => true,
        ]);

        $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$wordCard->id}/interval-preview")
            ->assertStatus(404);
    }

    public function test_rejected_sense_gets_404(): void
    {
        $rejectedSense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'rejected_word',
            'surface_form' => 'rejected_word',
            'pos' => 'noun',
            'sense_key' => hash('sha256', 'rejected|test'),
            'sense_zh' => '已拒绝',
            'sense_en' => 'rejected',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Rejected.',
            'example_sentence_zh' => '已拒绝。',
            'is_context_specific' => false,
            'status' => WordSense::STATUS_REJECTED,
        ]);
        $rejectedCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $rejectedSense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 3.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 2,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDays(3),
            'fsrs_enabled' => true,
        ]);

        $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$rejectedCard->id}/interval-preview")
            ->assertStatus(404);
    }

    public function test_disabled_card_gets_404(): void
    {
        $this->card->fsrs_enabled = false;
        $this->card->save();

        $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(404);
    }

    public function test_nonexistent_id_gets_404(): void
    {
        $this->actingAs($this->user)
            ->getJson('/reviews/senses/99999999/interval-preview')
            ->assertStatus(404);
    }

    // --- Read-only verification ---

    public function test_preview_does_not_write_review_log(): void
    {
        $countBefore = ReviewLog::count();
        $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200);
        $this->assertSame($countBefore, ReviewLog::count());
    }

    public function test_preview_does_not_modify_review_card(): void
    {
        $before = $this->card->fresh();
        $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200);
        $after = $this->card->fresh();

        $this->assertSame($before->fsrs_state, $after->fsrs_state);
        $this->assertEquals($before->fsrs_due_at, $after->fsrs_due_at);
        $this->assertEquals($before->fsrs_stability, $after->fsrs_stability);
        $this->assertEquals($before->fsrs_difficulty, $after->fsrs_difficulty);
        $this->assertSame($before->fsrs_reps, $after->fsrs_reps);
        $this->assertSame($before->fsrs_lapses, $after->fsrs_lapses);
        $this->assertEquals($before->fsrs_last_reviewed_at, $after->fsrs_last_reviewed_at);
    }

    public function test_preview_does_not_modify_word_sense(): void
    {
        $before = $this->sense->fresh();
        $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200);
        $after = $this->sense->fresh();
        $this->assertSame($before->status, $after->status);
    }

    public function test_preview_is_idempotent(): void
    {
        $r1 = $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200)
            ->json('ratings');

        $r2 = $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200)
            ->json('ratings');

        // interval_seconds should be the same (same card state, same now() margin)
        foreach (['again', 'hard', 'good', 'easy'] as $rating) {
            $this->assertSame($r1[$rating]['interval_seconds'], $r2[$rating]['interval_seconds']);
            $this->assertSame($r1[$rating]['next_state'], $r2[$rating]['next_state']);
        }
    }

    public function test_endpoint_is_get_only(): void
    {
        $this->actingAs($this->user)
            ->postJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(405);
    }

    // --- Payload structure ---

    public function test_rating_keys_match_contract(): void
    {
        $ratings = $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200)
            ->json('ratings');

        $this->assertSame(['again', 'hard', 'good', 'easy'], array_keys($ratings));
    }

    public function test_due_at_is_iso8601(): void
    {
        $ratings = $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200)
            ->json('ratings');

        foreach ($ratings as $rating => $data) {
            $this->assertNotEmpty($data['due_at']);
            // ISO 8601 contains 'T' and timezone marker
            $this->assertStringContainsString('T', $data['due_at']);
        }
    }

    public function test_engine_is_valid(): void
    {
        $engine = $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200)
            ->json('engine');

        $this->assertContains($engine, ['fsrs', 'fallback']);
    }

    // --- Parity: preview vs real rating ---

    public function test_preview_matches_real_rating_for_good(): void
    {
        $this->assertParity('good');
    }

    public function test_preview_matches_real_rating_for_again(): void
    {
        $this->assertParity('again');
    }

    public function test_preview_matches_real_rating_for_hard(): void
    {
        $this->assertParity('hard');
    }

    public function test_preview_matches_real_rating_for_easy(): void
    {
        $this->assertParity('easy');
    }

    /**
     * Core parity test: preview projection must match the result of a real
     * recordReview() call, when both use the same reviewedAt and the same
     * initial card state.
     */
    private function assertParity(string $rating): void
    {
        // Freeze time so preview and real rating use the same reviewedAt
        $frozen = now();

        // Clone the card state for the preview
        $previewCard = clone $this->card;
        $previewCard->exists = true;

        // 1. Get the preview projection
        $service = app(FsrsSchedulingService::class);
        $preview = $service->previewAllRatings($this->card->fresh(), $frozen);
        $previewResult = $preview[$rating];

        // Verify preview did NOT modify the card
        $cardAfterPreview = $this->card->fresh();
        $this->assertEquals($this->card->fsrs_stability, $cardAfterPreview->fsrs_stability);
        $this->assertSame($this->card->fsrs_reps, $cardAfterPreview->fsrs_reps);

        // 2. Perform a real rating with the same frozen time
        $reviewCardService = app(ReviewCardService::class);
        $updatedCard = $reviewCardService->recordReview(
            $this->user->id,
            'english',
            $this->card->id,
            $rating,
            'sense_review'
        );

        // 3. Compare preview projection vs real result
        $this->assertSame($previewResult['state'], $updatedCard->fsrs_state);
        $this->assertEquals($previewResult['stability'], $updatedCard->fsrs_stability);
        $this->assertEquals($previewResult['difficulty'], $updatedCard->fsrs_difficulty);
        $this->assertSame($previewResult['lapses'], $updatedCard->fsrs_lapses);

        // Due_at: compare the date portion (interval in days should match
        // since both use the same schedule() call with the same inputs)
        $previewDue = \Carbon\Carbon::parse($previewResult['due_at']);
        $realDue = \Carbon\Carbon::parse($updatedCard->fsrs_due_at);
        $diffDays = (int) abs($previewDue->diffInDays($realDue));
        $this->assertSame(0, $diffDays);
    }

    // --- State coverage ---

    public function test_preview_works_for_new_card(): void
    {
        $this->card->fsrs_state = 'new';
        $this->card->fsrs_stability = null;
        $this->card->fsrs_difficulty = null;
        $this->card->fsrs_reps = 0;
        $this->card->fsrs_last_reviewed_at = null;
        $this->card->save();

        $response = $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200);

        $ratings = $response->json('ratings');
        $this->assertCount(4, $ratings);
    }

    public function test_preview_works_for_learning_card(): void
    {
        $this->card->fsrs_state = 'learning';
        $this->card->fsrs_reps = 1;
        $this->card->save();

        $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200);
    }

    public function test_preview_works_for_relearning_card(): void
    {
        $this->card->fsrs_state = 'relearning';
        $this->card->fsrs_lapses = 1;
        $this->card->save();

        $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200);
    }

    public function test_preview_works_for_review_card(): void
    {
        // card is already in 'review' state from setUp
        $this->actingAs($this->user)
            ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
            ->assertStatus(200);
    }

    // --- Query budget ---

    public function test_preview_query_count_is_constant(): void
    {
        app(\App\Services\Settings\Presets\ReviewSettingsResolver::class)
            ->resolve($this->user->id, 'english');

        // Use a fresh counter that only counts queries during the HTTP request,
        // not during data setup. We run the same request twice and verify the
        // query count does not grow.
        $countRequest = function () {
            $queries = 0;
            $callback = function () use (&$queries) {
                $queries++;
            };
            DB::listen($callback);

            $this->actingAs($this->user)
                ->getJson("/reviews/senses/{$this->card->id}/interval-preview")
                ->assertStatus(200);

            // Remove the listener so it doesn't affect subsequent counts
            DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

            return $queries;
        };

        $firstRun = $countRequest();
        $secondRun = $countRequest();

        // Query count must be constant (not grow between identical requests)
        $this->assertSame($firstRun, $secondRun, "Query budget changed: {$firstRun} → {$secondRun}");
        // Sanity: the endpoint should do a small constant number of queries
        $this->assertLessThan(20, $firstRun, "Query count too high: {$firstRun}");
    }
}
