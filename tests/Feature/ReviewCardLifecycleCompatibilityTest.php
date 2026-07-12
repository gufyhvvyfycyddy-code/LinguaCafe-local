<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewCardStateEvent;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardLifecycleCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ReviewCardLifecycleCompatibilityTest
 *
 * ADR-0010: Verifies backward compatibility with the legacy fsrs_enabled
 * binary and the legacy archive/restore endpoint.
 *
 * Covers:
 *   - fsrs_enabled mirror invariant (active/buried → true, suspended/archived → false)
 *   - Legacy PATCH /review-cards/manage/{id}/enabled delegates to CommandService
 *   - Legacy endpoint idempotency (enabled=true on active card = 200)
 *   - Reset preserves lifecycle state (does not force fsrs_enabled=true)
 *   - Delete is NOT available via lifecycle endpoint
 *   - Old fsrs_enabled=false data is treated as archived
 */
class ReviewCardLifecycleCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Compat Test',
            'email' => 'compat-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    // ─── fsrs_enabled mirror invariant ───

    public function test_mirror_active_has_fsrs_enabled_true(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);
        $this->assertTrue((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_mirror_buried_has_fsrs_enabled_true(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'buried',
            'fsrs_enabled' => true,
            'buried_until' => now()->addHours(12),
        ]);
        $this->assertTrue((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_mirror_suspended_has_fsrs_enabled_false(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'suspended',
            'fsrs_enabled' => false,
        ]);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_mirror_archived_has_fsrs_enabled_false(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'archived',
            'fsrs_enabled' => false,
        ]);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
    }

    // ─── Command service maintains mirror ───

    public function test_suspend_sets_fsrs_enabled_false(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);
        $service = app(ReviewCardLifecycleCommandService::class);

        $service->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_resume_sets_fsrs_enabled_true(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'suspended',
            'fsrs_enabled' => false,
        ]);
        $service = app(ReviewCardLifecycleCommandService::class);

        $service->act(
            $card, 'resume', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertTrue((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_archive_sets_fsrs_enabled_false(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);
        $service = app(ReviewCardLifecycleCommandService::class);

        $service->act(
            $card, 'archive', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_restore_sets_fsrs_enabled_true(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'archived',
            'fsrs_enabled' => false,
        ]);
        $service = app(ReviewCardLifecycleCommandService::class);

        $service->act(
            $card, 'restore', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $this->assertTrue((bool) $card->fresh()->fsrs_enabled);
    }

    // ─── Legacy endpoint delegation ───

    public function test_legacy_enabled_false_delegates_to_archive(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);

        $response = $this->actingAs($this->user)
            ->patchJson("/review-cards/manage/{$card->id}/enabled", [
                'enabled' => false,
            ]);

        $response->assertStatus(200);
        $this->assertSame('archived', $card->fresh()->lifecycle_state);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);

        // A state event should have been created.
        $this->assertSame(1, ReviewCardStateEvent::where('review_card_id', $card->id)->count());
    }

    public function test_legacy_enabled_true_delegates_to_restore(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'archived',
            'fsrs_enabled' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/review-cards/manage/{$card->id}/enabled", [
                'enabled' => true,
            ]);

        $response->assertStatus(200);
        $this->assertSame('active', $card->fresh()->lifecycle_state);
        $this->assertTrue((bool) $card->fresh()->fsrs_enabled);
    }

    // ─── Legacy endpoint idempotency ───

    public function test_legacy_enabled_true_on_active_card_is_idempotent(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);

        $response = $this->actingAs($this->user)
            ->patchJson("/review-cards/manage/{$card->id}/enabled", [
                'enabled' => true,
            ]);

        $response->assertStatus(200);
        // No state event should be created for a no-op.
        $this->assertSame(0, ReviewCardStateEvent::where('review_card_id', $card->id)->count());
    }

    public function test_legacy_enabled_false_on_archived_card_is_idempotent(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'archived',
            'fsrs_enabled' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/review-cards/manage/{$card->id}/enabled", [
                'enabled' => false,
            ]);

        $response->assertStatus(200);
        $this->assertSame(0, ReviewCardStateEvent::where('review_card_id', $card->id)->count());
    }

    // ─── Reset preserves lifecycle ───

    public function test_reset_on_active_card_preserves_active(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'active',
            'fsrs_enabled' => true,
            'fsrs_reps' => 10,
            'fsrs_stability' => 5.0,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset");

        $response->assertStatus(200);
        $fresh = $card->fresh();
        $this->assertSame('active', $fresh->lifecycle_state);
        $this->assertTrue((bool) $fresh->fsrs_enabled);
        $this->assertSame(0, (int) $fresh->fsrs_reps);
    }

    public function test_reset_on_archived_card_preserves_archived(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'archived',
            'fsrs_enabled' => false,
            'fsrs_reps' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/review-cards/manage/{$card->id}/reset");

        $response->assertStatus(200);
        $fresh = $card->fresh();
        $this->assertSame('archived', $fresh->lifecycle_state, 'Reset must preserve archived state');
        $this->assertFalse((bool) $fresh->fsrs_enabled, 'Reset must not re-enable archived card');
        $this->assertSame(0, (int) $fresh->fsrs_reps, 'Reset should clear FSRS memory');
    }

    // ─── Delete NOT via lifecycle endpoint ───

    public function test_delete_action_not_accepted_by_lifecycle_endpoint(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);

        $response = $this->actingAs($this->user)
            ->postJson("/review-cards/{$card->id}/lifecycle-actions", [
                'action' => 'delete',
                'request_id' => Str::uuid()->toString(),
                'source' => 'test',
            ]);

        // 'delete' is not a valid lifecycle action → 422 validation error.
        $response->assertStatus(422);
        $this->assertSame('active', $card->fresh()->lifecycle_state, 'Card must not be modified');
    }

    // ─── Old fsrs_enabled=false data treated as archived ───

    public function test_legacy_fsrs_disabled_card_works_with_archive_filter(): void
    {
        // Simulate a legacy card that was archived before the migration.
        $card = $this->makeCard([
            'lifecycle_state' => 'archived',
            'fsrs_enabled' => false,
        ]);

        // The management page 'disabled' filter (legacy) should include it.
        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=disabled&per_page=100');

        $response->assertStatus(200);
        $ids = collect($response->json('items'))->pluck('review_card_id')->toArray();
        $this->assertContains($card->id, $ids);
    }

    public function test_legacy_fsrs_enabled_card_works_with_enabled_filter(): void
    {
        $card = $this->makeCard([
            'lifecycle_state' => 'active',
            'fsrs_enabled' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=enabled&per_page=100');

        $response->assertStatus(200);
        $ids = collect($response->json('items'))->pluck('review_card_id')->toArray();
        $this->assertContains($card->id, $ids);
    }

    // ─── New lifecycle filters ───

    public function test_active_filter_returns_only_active_cards(): void
    {
        $active = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);
        $suspended = $this->makeCard(['lifecycle_state' => 'suspended', 'fsrs_enabled' => false]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=active&per_page=100');

        $response->assertStatus(200);
        $ids = collect($response->json('items'))->pluck('review_card_id')->toArray();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($suspended->id, $ids);
    }

    public function test_archived_filter_returns_only_archived_cards(): void
    {
        $active = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);
        $archived = $this->makeCard(['lifecycle_state' => 'archived', 'fsrs_enabled' => false]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=archived&per_page=100');

        $response->assertStatus(200);
        $ids = collect($response->json('items'))->pluck('review_card_id')->toArray();
        $this->assertContains($archived->id, $ids);
        $this->assertNotContains($active->id, $ids);
    }

    public function test_suspended_filter_returns_only_suspended_cards(): void
    {
        $suspended = $this->makeCard(['lifecycle_state' => 'suspended', 'fsrs_enabled' => false]);
        $active = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);

        $response = $this->actingAs($this->user)
            ->get('/review-cards/manage/data?filter=suspended&per_page=100');

        $response->assertStatus(200);
        $ids = collect($response->json('items'))->pluck('review_card_id')->toArray();
        $this->assertContains($suspended->id, $ids);
        $this->assertNotContains($active->id, $ids);
    }

    public function test_buried_filter_returns_only_buried_cards(): void
    {
        $buried = $this->makeCard([
            'lifecycle_state' => 'buried',
            'fsrs_enabled' => true,
            'buried_until' => now()->addHours(12),
        ]);
        $active = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);

        $response = $this->actingAs($this->user)
            ->getJson('/review-cards/manage/data?filter=buried&per_page=100');

        $response->assertStatus(200);
        $ids = collect($response->json('items'))->pluck('review_card_id')->toArray();
        $this->assertContains($buried->id, $ids);
        $this->assertNotContains($active->id, $ids);
    }

    // ─── Lifecycle API endpoint ───

    public function test_lifecycle_show_returns_descriptor(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/{$card->id}/lifecycle");

        $response->assertStatus(200);
        $response->assertJsonPath('review_card_id', $card->id);
        $response->assertJsonPath('lifecycle.persistent_state', 'active');
        $response->assertJsonPath('lifecycle.effective_state', 'active');
        $response->assertJsonPath('lifecycle.queue_eligible', true);
    }

    public function test_lifecycle_events_returns_audit_trail(): void
    {
        $card = $this->makeCard(['lifecycle_state' => 'active', 'fsrs_enabled' => true]);
        $service = app(ReviewCardLifecycleCommandService::class);

        $service->act(
            $card, 'suspend', Str::uuid()->toString(), null,
            'test', $this->user->id, 'english', 'UTC'
        );

        $response = $this->actingAs($this->user)
            ->getJson("/review-cards/{$card->id}/lifecycle-events");

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('suspend', $items[0]['action']);
    }

    // ─── Helpers ───

    private function makeCard(array $overrides = []): ReviewCard
    {
        $lemma = 'test' . Str::random(4);
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a test.',
            'example_sentence_zh' => '这是一个测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("english|{$lemma}|noun|测试|test")),
        ]);

        return ReviewCard::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
        ], $overrides));
    }
}
