<?php

namespace Tests\Feature;

use App\Exceptions\SpecialStudyException;
use App\Models\ReviewCard;
use App\Models\SpecialStudySession;
use App\Models\User;
use App\Models\WordSense;
use App\Services\SpecialStudy\SpecialStudyCriteria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class M12SpecialStudyFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_new_is_preview_only_and_early_mode_is_scenario_bound(): void
    {
        foreach ([
            [
                'scenario' => 'recent_new',
                'execution_mode' => 'formal',
                'reason' => 'recent_new_preview_only',
            ],
            [
                'scenario' => 'backlog',
                'execution_mode' => 'early_review',
                'reason' => 'early_mode_requires_review_ahead',
            ],
            [
                'scenario' => 'review_ahead',
                'execution_mode' => 'formal',
                'reason' => 'review_ahead_requires_early_mode',
            ],
        ] as $case) {
            try {
                SpecialStudyCriteria::fromArray($case);
                $this->fail('Expected Special Study criteria validation to fail.');
            } catch (SpecialStudyException $exception) {
                $this->assertSame($case['reason'], $exception->reason);
                $this->assertSame('execution_mode', $exception->field);
            }
        }
    }

    public function test_formal_mode_rejects_non_active_lifecycle_filters(): void
    {
        $this->expectException(SpecialStudyException::class);

        SpecialStudyCriteria::fromArray([
            'scenario' => 'filtered',
            'execution_mode' => 'formal',
            'filters' => ['lifecycle_states' => ['suspended']],
        ]);
    }

    public function test_create_orders_complete_candidate_set_before_limit(): void
    {
        $user = $this->createUser('m12-order@example.test');
        $low = $this->createSenseCard($user, 'low', ['fsrs_lapses' => 1]);
        $high = $this->createSenseCard($user, 'high', ['fsrs_lapses' => 9]);
        $middle = $this->createSenseCard($user, 'middle', ['fsrs_lapses' => 5]);

        $response = $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'execution_mode' => 'preview',
            'sort' => 'most_lapses',
            'card_limit' => 2,
        ]);

        $response->assertCreated()
            ->assertJsonPath('total_candidates', 3)
            ->assertJsonPath('total_count', 2)
            ->assertJsonPath('current_card.review_card_id', $high->id);

        $session = SpecialStudySession::findOrFail($response->json('id'));
        $this->assertSame([$high->id, $middle->id], $session->ordered_card_ids);
        $this->assertNotContains($low->id, $session->ordered_card_ids);
    }

    public function test_preview_can_select_suspended_cards_but_formal_cannot(): void
    {
        $user = $this->createUser('m12-lifecycle@example.test');
        $suspended = $this->createSenseCard($user, 'suspended', [
            'lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED,
            'fsrs_enabled' => false,
        ]);

        $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'execution_mode' => 'preview',
            'filters' => ['lifecycle_states' => ['suspended']],
        ])->assertCreated()
            ->assertJsonPath('current_card.review_card_id', $suspended->id);

        $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'execution_mode' => 'formal',
            'filters' => ['lifecycle_states' => ['suspended']],
        ])->assertStatus(422)
            ->assertJsonPath('error.reason', 'formal_requires_active');
    }

    public function test_scope_save_end_and_saved_rebuild_are_revisioned(): void
    {
        $user = $this->createUser('m12-owner@example.test');
        $other = $this->createUser('m12-other@example.test');
        $card = $this->createSenseCard($user, 'owned');

        $created = $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'execution_mode' => 'preview',
        ])->assertCreated();
        $sessionId = $created->json('id');

        $this->flushSession();
        $this->actingAs($other)
            ->getJson("/special-study/sessions/{$sessionId}")
            ->assertNotFound();

        $this->flushSession();
        $saved = $this->actingAs($user)->putJson(
            "/special-study/sessions/{$sessionId}/save",
            ['name' => '  Backlog   focus  ', 'expected_revision' => 1],
        )->assertOk()
            ->assertJsonPath('name', 'Backlog focus')
            ->assertJsonPath('revision', 2);

        $this->actingAs($user)->postJson(
            "/special-study/sessions/{$sessionId}/end",
            ['expected_revision' => 1],
        )->assertStatus(409)
            ->assertJsonPath('error.reason', 'revision_conflict');

        $ended = $this->actingAs($user)->postJson(
            "/special-study/sessions/{$sessionId}/end",
            ['expected_revision' => $saved->json('revision')],
        )->assertOk()
            ->assertJsonPath('status', 'ended')
            ->assertJsonPath('revision', 3);

        $rebuilt = $this->actingAs($user)->postJson(
            "/special-study/sessions/{$sessionId}/rebuild",
            ['expected_revision' => $ended->json('revision')],
        )->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('revision', 4)
            ->assertJsonPath('current_card.review_card_id', $card->id);

        $this->actingAs($user)->getJson('/special-study/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.id', $rebuilt->json('id'));
    }

    public function test_saved_name_is_unique_per_user_and_language(): void
    {
        $user = $this->createUser('m12-names@example.test');
        $this->createSenseCard($user, 'name-card');

        $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'name' => 'Travel',
        ])->assertCreated();

        $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'name' => ' travel ',
        ])->assertStatus(409)
            ->assertJsonPath('error.reason', 'name_conflict');
    }

    private function createUser(string $email): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function createSenseCard(
        User $user,
        string $lemma,
        array $overrides = [],
    ): ReviewCard {
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => $lemma,
            'sense_en' => $lemma,
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "{$user->id}|english|{$lemma}"),
        ]);

        return ReviewCard::forceCreate(array_merge([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 5,
            'fsrs_difficulty' => 5,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ], $overrides));
    }
}
