<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewCardMarkerApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('marker-owner@example.com');
        $this->otherUser = $this->createUser('marker-other@example.com');
    }

    public function test_single_marker_set_clear_and_idempotence_preserve_learning_data(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense, [
            'fsrs_state' => 'review',
            'fsrs_stability' => 4.5,
            'fsrs_difficulty' => 6.25,
            'fsrs_reps' => 8,
            'fsrs_lapses' => 2,
        ]);
        $protectedFields = [
            'fsrs_state', 'fsrs_due_at', 'fsrs_stability', 'fsrs_difficulty',
            'fsrs_reps', 'fsrs_lapses', 'fsrs_last_reviewed_at', 'fsrs_enabled',
            'lifecycle_state', 'buried_until', 'lifecycle_version', 'lifecycle_changed_at',
        ];
        $before = array_intersect_key(
            $card->fresh()->getRawOriginal(),
            array_flip($protectedFields)
        );
        $reviewLogCount = ReviewLog::count();
        $senseText = $sense->only(['sense_zh', 'sense_en', 'status']);

        foreach ([4, 4, 0] as $marker) {
            $this->actingAs($this->user)
                ->patchJson("/review-cards/manage/{$card->id}/marker", ['marker' => $marker])
                ->assertOk()
                ->assertExactJson(['review_card_id' => $card->id, 'marker' => $marker]);
        }

        $fresh = $card->fresh();
        $this->assertSame(0, $fresh->marker);
        $this->assertSame(
            $before,
            array_intersect_key($fresh->getRawOriginal(), array_flip($protectedFields))
        );
        $this->assertSame($reviewLogCount, ReviewLog::count());
        $this->assertSame($senseText, $sense->fresh()->only(array_keys($senseText)));
    }

    public function test_single_marker_validates_the_boundary_payload(): void
    {
        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'));

        foreach ([[], ['marker' => -1], ['marker' => 8], ['marker' => '4'], ['marker' => null]] as $payload) {
            $this->actingAs($this->user)
                ->patchJson("/review-cards/manage/{$card->id}/marker", $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('marker');
        }

        $this->assertSame(0, $card->fresh()->marker);
    }

    public function test_single_marker_hides_inaccessible_or_non_manageable_cards(): void
    {
        $otherCard = $this->createSenseCard($this->createSense($this->otherUser->id, 'english'));
        $otherLanguage = $this->createSenseCard($this->createSense($this->user->id, 'french'));
        $unconfirmed = $this->createSenseCard($this->createSense(
            $this->user->id,
            'english',
            ['status' => WordSense::STATUS_AI_SUGGESTED]
        ));
        $legacy = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => 991002,
            'fsrs_state' => 'new',
            'fsrs_enabled' => true,
        ]);

        foreach ([999999, $otherCard->id, $otherLanguage->id, $unconfirmed->id, $legacy->id] as $id) {
            $this->actingAs($this->user)
                ->patchJson("/review-cards/manage/{$id}/marker", ['marker' => 2])
                ->assertNotFound();
        }

        $this->assertSame(0, $otherCard->fresh()->marker);
        $this->assertSame(0, $otherLanguage->fresh()->marker);
        $this->assertSame(0, $unconfirmed->fresh()->marker);
        $this->assertSame(0, $legacy->fresh()->marker);
    }

    public function test_bulk_marker_updates_manageable_cards_and_counts_skips_without_disclosure(): void
    {
        $first = $this->createSenseCard($this->createSense($this->user->id, 'english', ['lemma' => 'first']));
        $second = $this->createSenseCard($this->createSense($this->user->id, 'english', ['lemma' => 'second']));
        $other = $this->createSenseCard($this->createSense($this->otherUser->id, 'english', ['lemma' => 'other']));
        $reviewLogCount = ReviewLog::count();

        $this->actingAs($this->user)
            ->postJson('/review-cards/manage/bulk-marker', [
                'ids' => [$first->id, $second->id, $other->id, 999999],
                'marker' => 6,
            ])
            ->assertOk()
            ->assertExactJson(['affected' => 2, 'skipped' => 2, 'marker' => 6]);

        $this->assertSame(6, $first->fresh()->marker);
        $this->assertSame(6, $second->fresh()->marker);
        $this->assertSame(0, $other->fresh()->marker);
        $this->assertSame($reviewLogCount, ReviewLog::count());
    }

    public function test_bulk_marker_validates_ids_and_marker_before_writing(): void
    {
        $card = $this->createSenseCard($this->createSense($this->user->id, 'english'));
        $invalidPayloads = [
            [[], ['ids', 'marker']],
            [['ids' => [], 'marker' => 1], ['ids']],
            [['ids' => [$card->id, $card->id], 'marker' => 1], ['ids.1']],
            [['ids' => ['card' => $card->id], 'marker' => 1], ['ids']],
            [['ids' => [0], 'marker' => 1], ['ids.0']],
            [['ids' => range(1, 101), 'marker' => 1], ['ids']],
            [['ids' => [$card->id], 'marker' => 8], ['marker']],
            [['ids' => [$card->id], 'marker' => '2'], ['marker']],
        ];

        foreach ($invalidPayloads as [$payload, $fields]) {
            $response = $this->actingAs($this->user)
                ->postJson('/review-cards/manage/bulk-marker', $payload)
                ->assertStatus(422);
            foreach ($fields as $field) {
                $response->assertJsonValidationErrors($field);
            }
        }

        $this->actingAs($this->user)
            ->call(
                'POST',
                '/review-cards/manage/bulk-marker',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                '{"ids":{"0":' . $card->id . '},"marker":1}'
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');

        $this->assertSame(0, $card->fresh()->marker);
    }

    private function createUser(string $email): User
    {
        return User::forceCreate([
            'name' => 'Marker API Test',
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function createSense(int $userId, string $language, array $overrides = []): WordSense
    {
        $lemma = $overrides['lemma'] ?? Str::lower(Str::random(10));
        $status = $overrides['status'] ?? WordSense::STATUS_CONFIRMED;

        return WordSense::forceCreate(array_merge([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '标记测试',
            'sense_en' => 'marker test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a marker test.',
            'example_sentence_zh' => '这是标记测试。',
            'status' => $status,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "{$userId}|{$language}|{$lemma}|{$status}|" . Str::uuid()),
        ], $overrides));
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ], $overrides));
    }
}
