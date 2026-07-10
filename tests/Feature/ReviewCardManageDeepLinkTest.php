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

/**
 * ReviewCardManageDeepLinkTest
 *
 * ADR-0007 — SenseReview Report Card Deep Link
 *
 * Tests the read-only detail endpoint (GET /review-cards/manage/{reviewCard}/detail)
 * and the access-control rules centralized in ReviewCardManageAccessService.
 *
 * Contract:
 *  - GET only, read-only.
 *  - 404 for: not found, other user, other language, legacy word card,
 *    rejected sense, deleted sense.
 *  - Archived sense cards (fsrs_enabled=false) ARE allowed.
 *  - Payload shape matches ReviewCardManageItemSerializerService::serializeCard().
 *  - No ReviewLog write, no FSRS change.
 */
class ReviewCardManageDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'DeepLink User',
            'email' => 'deeplink@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other DeepLink User',
            'email' => 'other.deeplink@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function createSense(int $userId, string $language, array $overrides = []): WordSense
    {
        $data = array_merge([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => 'bank',
            'surface_form' => 'bank',
            'pos' => 'noun',
            'sense_zh' => '银行',
            'sense_en' => 'bank',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'I went to the bank.',
            'example_sentence_zh' => '我去了银行。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("{$language}|bank|noun|银行|bank")),
        ], $overrides);

        return WordSense::forceCreate($data);
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now(),
            'fsrs_stability' => 1.5,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ], $overrides));
    }

    private function createWordCard(int $userId, string $language, int $wordId): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $userId,
            'language_id' => $language,
            'language' => $language,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $wordId,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);
    }

    /**
     * D1. Valid card → 200 with correct payload.
     */
    public function test_detail_returns_correct_payload_for_valid_card(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)
            ->get("/review-cards/manage/{$card->id}/detail");

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame($card->id, $payload['review_card_id']);
        $this->assertSame($sense->id, $payload['word_sense_id']);
        $this->assertSame('bank', $payload['lemma']);
        $this->assertSame('银行', $payload['sense_zh']);
    }

    /**
     * D2. Not logged in → redirect (302).
     */
    public function test_detail_requires_auth(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $response = $this->get("/review-cards/manage/{$card->id}/detail");
        $response->assertRedirect();
    }

    /**
     * D3. Other user's card → 404.
     */
    public function test_detail_other_user_card_returns_404(): void
    {
        $sense = $this->createSense($this->otherUser->id, 'english');
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)
            ->get("/review-cards/manage/{$card->id}/detail");

        $response->assertNotFound();
    }

    /**
     * D4. Other language → 404.
     */
    public function test_detail_other_language_returns_404(): void
    {
        $germanUser = User::forceCreate([
            'name' => 'German User',
            'email' => 'german@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'german',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $sense = $this->createSense($germanUser->id, 'german');
        $card = $this->createSenseCard($sense);

        // This user has 'english' selected — should not see german card
        $response = $this->actingAs($this->user)
            ->get("/review-cards/manage/{$card->id}/detail");

        $response->assertNotFound();
    }

    /**
     * D5. Legacy word card → 404.
     */
    public function test_detail_legacy_word_card_returns_404(): void
    {
        $wordCard = $this->createWordCard($this->user->id, 'english', 999);

        $response = $this->actingAs($this->user)
            ->get("/review-cards/manage/{$wordCard->id}/detail");

        $response->assertNotFound();
    }

    /**
     * D6. Rejected sense → 404.
     */
    public function test_detail_rejected_sense_returns_404(): void
    {
        $sense = $this->createSense($this->user->id, 'english', [
            'status' => WordSense::STATUS_REJECTED,
        ]);
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)
            ->get("/review-cards/manage/{$card->id}/detail");

        $response->assertNotFound();
    }

    /**
     * D7. Non-existent ID → 404.
     */
    public function test_detail_nonexistent_id_returns_404(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/review-cards/manage/999999/detail');

        $response->assertNotFound();
    }

    /**
     * D8. Archived card (fsrs_enabled=false) → 200 (archived is allowed).
     */
    public function test_detail_archived_card_allowed(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense, ['fsrs_enabled' => false]);

        $response = $this->actingAs($this->user)
            ->get("/review-cards/manage/{$card->id}/detail");

        $response->assertOk();
        $this->assertFalse($response->json('fsrs_enabled'));
    }

    /**
     * D9. Does not write ReviewLog.
     */
    public function test_detail_does_not_write_review_log(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $logCountBefore = ReviewLog::count();

        $this->actingAs($this->user)
            ->get("/review-cards/manage/{$card->id}/detail");

        $this->assertSame($logCountBefore, ReviewLog::count(),
            'detail endpoint must not write ReviewLog');
    }

    /**
     * D10. Does not modify FSRS fields.
     */
    public function test_detail_does_not_modify_fsrs(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense, [
            'fsrs_stability' => 2.5,
            'fsrs_difficulty' => 6.0,
            'fsrs_reps' => 5,
            'fsrs_lapses' => 1,
        ]);

        $this->actingAs($this->user)
            ->get("/review-cards/manage/{$card->id}/detail");

        $card->refresh();
        $this->assertSame(2.5, $card->fsrs_stability);
        $this->assertSame(6.0, $card->fsrs_difficulty);
        $this->assertSame(5, $card->fsrs_reps);
        $this->assertSame(1, $card->fsrs_lapses);
    }

    /**
     * D11. Payload matches serializer shape (has all expected fields).
     */
    public function test_detail_payload_has_expected_fields(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)
            ->get("/review-cards/manage/{$card->id}/detail");

        $payload = $response->json();
        $expectedKeys = [
            'review_card_id', 'word_sense_id', 'lemma', 'surface_form',
            'pos', 'sense_zh', 'sense_en', 'example_sentence_en',
            'example_sentence_zh', 'aliases_zh', 'collocations',
            'source_chapter_id', 'source_chapter_title', 'source_kind',
            'source_display_status', 'source_display_label',
            'fsrs_state', 'fsrs_due_at', 'fsrs_stability', 'fsrs_difficulty',
            'fsrs_reps', 'fsrs_lapses', 'fsrs_last_reviewed_at',
            'fsrs_enabled', 'missing_definition', 'missing_example',
            'missing_source',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $payload, "Payload missing key: {$key}");
        }
    }

    /**
     * D12. GET-only — POST returns 405.
     */
    public function test_detail_post_returns_method_not_allowed(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense);

        $response = $this->actingAs($this->user)
            ->post("/review-cards/manage/{$card->id}/detail");

        $response->assertStatus(405);
    }
}
