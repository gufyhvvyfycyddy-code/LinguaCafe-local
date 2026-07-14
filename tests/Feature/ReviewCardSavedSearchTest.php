<?php

namespace Tests\Feature;

use App\Models\ReviewCardSavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewCardSavedSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Saved Search User',
            'email' => 'saved-search@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function state(array $overrides = []): array
    {
        return array_merge([
            'q' => 'is:review difficult',
            'filter' => 'all',
            'sort_by' => 'fsrs_due_at',
            'sort_dir' => 'asc',
            'fsrs_states' => ['review'],
            'due_range' => 'today',
            'reps_min' => 2,
            'lapses_min' => 1,
        ], $overrides);
    }

    public function test_crud_returns_canonical_filter_state(): void
    {
        $created = $this->actingAs($this->user)->postJson('/review-cards/manage/saved-searches', [
            'name' => '  Today Review  ',
            'filter_state' => $this->state(['sort_dir' => 'ASC']),
        ])->assertCreated()
            ->assertJsonPath('name', 'Today Review')
            ->assertJsonPath('filter_state.sort_dir', 'asc')
            ->assertJsonPath('filter_state_version', 1);

        $id = $created->json('id');

        $this->actingAs($this->user)->getJson('/review-cards/manage/saved-searches')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $id);

        $this->actingAs($this->user)->patchJson("/review-cards/manage/saved-searches/{$id}", [
            'name' => 'Hard reviews',
            'filter_state' => $this->state(['due_range' => 'overdue']),
        ])->assertOk()
            ->assertJsonPath('name', 'Hard reviews')
            ->assertJsonPath('filter_state.due_range', 'overdue');

        $this->actingAs($this->user)->deleteJson("/review-cards/manage/saved-searches/{$id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('review_card_saved_searches', ['id' => $id]);
    }

    public function test_duplicate_normalized_name_is_422(): void
    {
        $this->actingAs($this->user)->postJson('/review-cards/manage/saved-searches', [
            'name' => 'Today   Review',
            'filter_state' => $this->state(),
        ])->assertCreated();

        $this->actingAs($this->user)->postJson('/review-cards/manage/saved-searches', [
            'name' => ' today review ',
            'filter_state' => $this->state(),
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_cap_is_exactly_fifty_per_user_and_language(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            ReviewCardSavedSearch::forceCreate([
                'user_id' => $this->user->id,
                'language_id' => 'english',
                'name' => "Search {$i}",
                'normalized_name' => "search {$i}",
                'filter_state_version' => 1,
                'filter_state' => $this->state(),
            ]);
        }

        $this->actingAs($this->user)->postJson('/review-cards/manage/saved-searches', [
            'name' => 'Overflow',
            'filter_state' => $this->state(),
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_other_user_and_other_language_are_404(): void
    {
        $other = User::forceCreate([
            'name' => 'Other',
            'email' => 'saved-search-other@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $row = ReviewCardSavedSearch::forceCreate([
            'user_id' => $other->id,
            'language_id' => 'english',
            'name' => 'Private',
            'normalized_name' => 'private',
            'filter_state_version' => 1,
            'filter_state' => $this->state(),
        ]);

        $this->actingAs($this->user)->patchJson("/review-cards/manage/saved-searches/{$row->id}", [
            'name' => 'Stolen',
        ])->assertNotFound();

        $row->update(['user_id' => $this->user->id, 'language_id' => 'spanish']);
        $this->actingAs($this->user)->deleteJson("/review-cards/manage/saved-searches/{$row->id}")
            ->assertNotFound();
    }

    public function test_invalid_filter_state_returns_422_without_row(): void
    {
        $this->actingAs($this->user)->postJson('/review-cards/manage/saved-searches', [
            'name' => 'Invalid',
            'filter_state' => $this->state(['filter' => 'not-real']),
        ])->assertStatus(422)->assertJsonValidationErrors('filter');

        $this->assertDatabaseCount('review_card_saved_searches', 0);
    }

    public function test_blank_name_and_unknown_persisted_version_are_rejected(): void
    {
        $this->actingAs($this->user)->postJson('/review-cards/manage/saved-searches', [
            'name' => " \t ",
            'filter_state' => $this->state(),
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        ReviewCardSavedSearch::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'name' => 'Future schema',
            'normalized_name' => 'future schema',
            'filter_state_version' => 2,
            'filter_state' => $this->state(),
        ]);

        $this->actingAs($this->user)->getJson('/review-cards/manage/saved-searches')
            ->assertStatus(422)->assertJsonValidationErrors('saved_searches');
    }
}
