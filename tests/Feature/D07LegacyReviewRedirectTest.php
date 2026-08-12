<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class D07LegacyReviewRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_legacy_review_paths_redirect_to_sense_review(): void
    {
        $user = User::factory()->create([
            'selected_language' => 'english',
        ]);

        foreach ([
            '/review',
            '/review/practice',
            '/review/practice/12/34',
        ] as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertRedirect('/reviews/senses');
        }
    }

    public function test_authenticated_sense_review_route_remains_reachable(): void
    {
        $user = User::factory()->create([
            'selected_language' => 'english',
        ]);

        $this->actingAs($user)
            ->get('/reviews/senses')
            ->assertOk();
    }

    public function test_unauthenticated_legacy_review_route_still_requires_login(): void
    {
        $this->get('/review')->assertRedirect('/login');
    }

    public function test_legacy_review_redirect_does_not_write_review_log_or_change_review_card_fsrs(): void
    {
        $user = User::factory()->create([
            'selected_language' => 'english',
        ]);

        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'legacy-review-redirect',
            'surface_form' => 'legacy-review-redirect',
            'pos' => 'noun',
            'sense_key' => 'd07-legacy-review-redirect',
            'sense_zh' => '旧复习重定向',
            'sense_en' => 'legacy review redirect',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);

        $card = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDay(),
            'fsrs_enabled' => true,
            'fsrs_stability' => 12.5,
            'fsrs_difficulty' => 4.5,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
            'fsrs_last_reviewed_at' => now()->subDay(),
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'buried_until' => null,
            'lifecycle_version' => 0,
            'lifecycle_changed_at' => null,
        ]);

        $fsrsFields = [
            'fsrs_state',
            'fsrs_due_at',
            'fsrs_enabled',
            'fsrs_stability',
            'fsrs_difficulty',
            'fsrs_reps',
            'fsrs_lapses',
            'fsrs_last_reviewed_at',
            'lifecycle_state',
            'buried_until',
            'lifecycle_version',
            'lifecycle_changed_at',
        ];
        $beforeCardCount = ReviewCard::count();
        $beforeLogCount = ReviewLog::count();
        $beforeCard = $card->fresh();
        $beforeFsrs = array_map(
            fn (string $field) => $beforeCard->getRawOriginal($field),
            $fsrsFields,
        );

        $this->actingAs($user)
            ->get('/review/practice/12/34')
            ->assertRedirect('/reviews/senses');

        $afterCard = $card->fresh();
        $afterFsrs = array_map(
            fn (string $field) => $afterCard->getRawOriginal($field),
            $fsrsFields,
        );

        $this->assertSame($beforeCardCount, ReviewCard::count());
        $this->assertSame($beforeLogCount, ReviewLog::count());
        $this->assertSame($beforeFsrs, $afterFsrs);
    }

    public function test_vocabulary_search_compatibility_route_still_exists(): void
    {
        $route = Route::getRoutes()->match(Request::create('/vocabulary/search', 'GET'));

        $this->assertSame(
            'App\\Http\\Controllers\\HomeController@index',
            $route->getActionName(),
        );
    }
}
