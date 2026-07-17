<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewCardStateEvent;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewCardMarkerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser('english');
    }

    public function test_single_marker_write_and_clear_are_isolated_from_learning_state(): void
    {
        $card = $this->makeCard($this->user, 'english');
        $before = $this->learningState($card->fresh());

        $this->actingAs($this->user)
            ->patchJson("/review-cards/{$card->id}/marker", ['marker' => 3])
            ->assertOk()
            ->assertJson(['review_card_id' => $card->id, 'marker' => 3]);

        $this->assertSame(3, $card->fresh()->marker);
        $this->assertSame($before, $this->learningState($card->fresh()));
        $this->assertSame(0, ReviewLog::where('review_card_id', $card->id)->count());
        $this->assertSame(0, ReviewCardStateEvent::where('review_card_id', $card->id)->count());

        $this->actingAs($this->user)
            ->patchJson("/review-cards/{$card->id}/marker", ['marker' => 0])
            ->assertOk()
            ->assertJson(['review_card_id' => $card->id, 'marker' => 0]);

        $this->assertSame(0, $card->fresh()->marker);
    }

    public function test_marker_range_is_validated(): void
    {
        $card = $this->makeCard($this->user, 'english');

        $this->actingAs($this->user)
            ->patchJson("/review-cards/{$card->id}/marker", ['marker' => 8])
            ->assertStatus(422)
            ->assertJsonValidationErrors('marker');
    }

    public function test_single_marker_rejects_other_user_language_and_legacy_cards(): void
    {
        $otherUser = $this->makeUser('english');
        $otherCard = $this->makeCard($otherUser, 'english');
        $otherLanguageCard = $this->makeCard($this->user, 'french');
        $legacyCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 999999,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ]);

        foreach ([$otherCard, $otherLanguageCard, $legacyCard] as $card) {
            $this->actingAs($this->user)
                ->patchJson("/review-cards/{$card->id}/marker", ['marker' => 4])
                ->assertNotFound();
        }
    }

    public function test_bulk_marker_applies_only_accessible_confirmed_sense_cards(): void
    {
        $first = $this->makeCard($this->user, 'english');
        $second = $this->makeCard($this->user, 'english');
        $other = $this->makeCard($this->makeUser('english'), 'english');

        $this->actingAs($this->user)
            ->patchJson('/review-cards/manage/markers', [
                'ids' => [$first->id, $second->id, $other->id, 999999],
                'marker' => 6,
            ])
            ->assertOk()
            ->assertJson([
                'marker' => 6,
                'applied_ids' => [$first->id, $second->id],
                'failed_ids' => [$other->id, 999999],
            ]);

        $this->assertSame(6, $first->fresh()->marker);
        $this->assertSame(6, $second->fresh()->marker);
        $this->assertSame(0, $other->fresh()->marker);
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, ReviewCardStateEvent::count());
    }

    public function test_flag_search_and_card_info_use_the_same_marker_field(): void
    {
        $marked = $this->makeCard($this->user, 'english');
        $unmarked = $this->makeCard($this->user, 'english');

        $this->actingAs($this->user)
            ->patchJson("/review-cards/{$marked->id}/marker", ['marker' => 5])
            ->assertOk();

        $items = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?q=flag:5')
            ->assertOk()
            ->assertJsonPath('search_meta.tokens.0', 'flag:5')
            ->json('items');

        $this->assertSame([$marked->id], array_column($items, 'review_card_id'));
        $this->assertSame(5, $items[0]['marker']);

        $this->actingAs($this->user)
            ->getJson("/review-cards/manage/{$marked->id}/detail")
            ->assertOk()
            ->assertJsonPath('marker', 5)
            ->assertJsonPath('card_info.marker', 5);

        $this->assertSame(0, $unmarked->fresh()->marker);
    }

    public function test_sense_review_payload_exposes_marker(): void
    {
        $card = $this->makeCard($this->user, 'english');

        $this->actingAs($this->user)
            ->patchJson("/review-cards/{$card->id}/marker", ['marker' => 2])
            ->assertOk();

        $cards = $this->actingAs($this->user)
            ->getJson('/reviews/senses?ignoreDailyLimits=1')
            ->assertOk()
            ->json('cards');

        $payload = collect($cards)->firstWhere('review_card_id', $card->id);
        $this->assertNotNull($payload);
        $this->assertSame(2, $payload['marker']);
    }

    public function test_marked_custom_study_is_preview_only_and_excludes_unmarked_cards(): void
    {
        $marked = $this->makeCard($this->user, 'english');
        $this->makeCard($this->user, 'english');

        $this->actingAs($this->user)
            ->patchJson("/review-cards/{$marked->id}/marker", ['marker' => 7])
            ->assertOk();

        $before = $this->learningState($marked->fresh());
        $response = $this->actingAs($this->user)
            ->postJson('/custom-study/sessions', [
                'mode' => 'marked',
                'parameters' => [],
                'card_limit' => 100,
            ])
            ->assertOk();

        $response->assertJsonPath('current_card.review_card_id', $marked->id)
            ->assertJsonPath('current_card.marker', 7)
            ->assertJsonPath('summary.total_count', 1);

        $this->assertSame($before, $this->learningState($marked->fresh()));
        $this->assertSame(0, ReviewLog::count());
    }

    private function makeUser(string $language): User
    {
        return User::forceCreate([
            'name' => 'Marker User',
            'email' => 'marker-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function makeCard(User $user, string $language): ReviewCard
    {
        $lemma = 'marker-' . Str::random(8);
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '标记',
            'sense_en' => 'marker',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a marker card.',
            'example_sentence_zh' => '这是一张标记卡。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("{$language}|{$lemma}|noun|标记|marker")),
        ]);

        return ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => $language,
            'language' => $language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_enabled' => true,
            'fsrs_stability' => 3.5,
            'fsrs_difficulty' => 5.5,
            'fsrs_reps' => 2,
            'fsrs_lapses' => 1,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'lifecycle_version' => 0,
        ]);
    }

    private function learningStateFields(): array
    {
        return [
            'fsrs_due_at', 'fsrs_state', 'fsrs_stability', 'fsrs_difficulty',
            'fsrs_reps', 'fsrs_lapses', 'fsrs_enabled', 'lifecycle_state',
            'buried_until', 'lifecycle_version', 'lifecycle_changed_at',
        ];
    }

    private function learningState(ReviewCard $card): array
    {
        return collect($this->learningStateFields())
            ->mapWithKeys(fn ($field) => [$field => $card->getRawOriginal($field)])
            ->all();
    }
}
