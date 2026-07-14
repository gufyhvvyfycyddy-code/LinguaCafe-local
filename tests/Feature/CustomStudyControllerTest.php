<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 2000-22 — Phase 4B CustomStudyController HTTP behavior tests.
 *
 * Verifies §19.7 items 6-14:
 *  6.  open  → 200
 *  7.  answer → 200
 *  8.  resume → 200
 *  9.  422 payload shape (structured field/reason)
 *  10. 404 payload shape (session not found, no internal leakage)
 *  11. selected_language from Auth::user() (not body)
 *  12. Client-supplied language is ignored
 *  13. Client-supplied user_id is ignored
 *  14. Queue Order is read-only (no settings writes)
 *
 * Controller is the ONLY place that reads Auth::user(). The SessionService
 * is stateless and receives trusted userId/language from the caller.
 *
 * 422 frozen payload (§17.3):
 *   {
 *     "success": false,
 *     "message": "...",
 *     "errors": { "field_name": ["..."] },
 *     "error": { "field": "field_name", "reason": "machine_reason" }
 *   }
 *
 * 404 frozen payload:
 *   {
 *     "success": false,
 *     "message": "Custom Study session not found or expired.",
 *     "error": { "reason": "session_not_found" }
 *   }
 */
class CustomStudyControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $language = 'english';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));

        $this->user = User::forceCreate([
            'name' => 'Controller User',
            'email' => 'ctrl-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
            'is_admin' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ───

    private function createSense(array $overrides = []): WordSense
    {
        $defaults = [
            'user_id' => $this->user->id,
            'language' => $this->language,
            'language_id' => $this->language,
            'lemma' => 'ctrl' . Str::random(6),
            'surface_form' => 'ctrl',
            'pos' => 'noun',
            'sense_zh' => '控制器',
            'sense_en' => 'controller',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a controller test.',
            'example_sentence_zh' => '这是一个控制器测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower($this->language . '|' . Str::random(10) . '|noun|控制器|controller')),
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
            'fsrs_due_at' => Carbon::now()->subDays(2),
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

    private function eligibleCard(): ReviewCard
    {
        $sense = $this->createSense();
        return $this->createCard($sense);
    }

    // ─── 6. open → 200 ───

    public function test_open_session_returns_200_with_valid_payload(): void
    {
        $card = $this->eligibleCard();

        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'overdue',
        ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertNotEmpty($data['token']);
        $this->assertNotEmpty($data['session_id']);
        $this->assertSame($card->id, $data['current_card']['review_card_id']);
        $this->assertArrayHasKey('summary', $data);
        $this->assertSame(1, $data['summary']['total_candidates']);
        $this->assertSame(1, $data['summary']['total_count']);
        $this->assertSame('overdue', $data['summary']['mode']);
        $this->assertArrayHasKey('expires_at', $data);
    }

    // ─── 7. answer → 200 ───

    public function test_answer_returns_200_with_refreshed_token(): void
    {
        $card = $this->eligibleCard();

        $open = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'overdue',
        ]);
        $open->assertStatus(200);
        $token = $open->json('token');

        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions/answer', [
            'token' => $token,
            'rating' => 'good',
        ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertNotEmpty($data['refreshed_token']);
        $this->assertNotSame($token, $data['refreshed_token']);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('completed', $data);
    }

    // ─── 8. resume → 200 ───

    public function test_resume_returns_200_with_refreshed_token(): void
    {
        $card = $this->eligibleCard();

        $open = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'overdue',
        ]);
        $open->assertStatus(200);
        $token = $open->json('token');

        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions/resume', [
            'token' => $token,
        ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertNotEmpty($data['refreshed_token']);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('completed', $data);
    }

    // ─── 9. 422 payload shape ───

    public function test_422_payload_shape_for_invalid_card_limit(): void
    {
        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'overdue',
            'card_limit' => 0,
        ]);

        $response->assertStatus(422);
        $data = $response->json();
        $this->assertFalse($data['success']);
        $this->assertNotEmpty($data['message']);
        $this->assertIsArray($data['errors']);
        $this->assertArrayHasKey('card_limit', $data['errors']);
        $this->assertIsArray($data['errors']['card_limit']);
        $this->assertNotEmpty($data['errors']['card_limit']);
        $this->assertSame('card_limit', $data['error']['field']);
        $this->assertSame('below_minimum', $data['error']['reason']);
    }

    public function test_422_payload_shape_for_invalid_mode(): void
    {
        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'non_existent_mode',
        ]);

        $response->assertStatus(422);
        $data = $response->json();
        $this->assertFalse($data['success']);
        $this->assertSame('mode', $data['error']['field']);
        $this->assertNotEmpty($data['error']['reason']);
    }

    public function test_422_for_invalid_rating_uses_rating_field(): void
    {
        $card = $this->eligibleCard();
        $open = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'overdue',
        ]);
        $token = $open->json('token');

        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions/answer', [
            'token' => $token,
            'rating' => 'not_a_real_rating',
        ]);

        $response->assertStatus(422);
        $data = $response->json();
        $this->assertFalse($data['success']);
        $this->assertSame('rating', $data['error']['field']);
        $this->assertSame('invalid_rating', $data['error']['reason']);
    }

    // ─── 10. 404 payload shape ───

    public function test_404_payload_shape_for_invalid_token(): void
    {
        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions/answer', [
            'token' => 'invalid.tampered.token',
            'rating' => 'good',
        ]);

        $response->assertStatus(404);
        $data = $response->json();
        $this->assertFalse($data['success']);
        $this->assertNotEmpty($data['message']);
        $this->assertSame('session_not_found', $data['error']['reason']);
        // No internal leakage of token verification details.
        $this->assertArrayNotHasKey('trace', $data);
        $this->assertArrayNotHasKey('exception', $data);
    }

    public function test_404_payload_shape_for_empty_token(): void
    {
        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions/resume', [
            'token' => '',
        ]);

        $response->assertStatus(404);
        $data = $response->json();
        $this->assertFalse($data['success']);
        $this->assertSame('session_not_found', $data['error']['reason']);
    }

    // ─── 11. selected_language from Auth::user() ───

    public function test_selected_language_comes_from_authenticated_user(): void
    {
        // Create a card in the user's selected_language (english).
        $englishCard = $this->eligibleCard();

        // Create a card in another language for the same user — should be ignored.
        $spanishSense = $this->createSense([
            'language' => 'spanish',
            'language_id' => 'spanish',
            'lemma' => 'palabra',
        ]);
        $spanishCard = $this->createCard($spanishSense, [
            'language' => 'spanish',
            'language_id' => 'spanish',
        ]);

        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'overdue',
        ]);

        $response->assertStatus(200);
        $currentCardId = $response->json('current_card.review_card_id');
        $this->assertSame($englishCard->id, $currentCardId, 'Must return the english card (user selected_language).');
        $this->assertNotSame($spanishCard->id, $currentCardId);
    }

    // ─── 12. Client-supplied language is ignored ───

    public function test_client_supplied_language_in_body_is_ignored(): void
    {
        $englishCard = $this->eligibleCard();

        // Malicious body tries to override language.
        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'overdue',
            'language' => 'spanish',
            'language_id' => 'spanish',
        ]);

        $response->assertStatus(200);
        $this->assertSame(
            $englishCard->id,
            $response->json('current_card.review_card_id'),
            'Body-supplied language must be ignored — Auth user selected_language is used.'
        );
    }

    // ─── 13. Client-supplied user_id is ignored ───

    public function test_client_supplied_user_id_in_body_is_ignored(): void
    {
        $englishCard = $this->eligibleCard();

        // Create another user with their own card — must NOT be served.
        $otherUser = User::forceCreate([
            'name' => 'Other Controller',
            'email' => 'other-ctrl-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $otherSense = $this->createSense(['user_id' => $otherUser->id]);
        $otherCard = $this->createCard($otherSense, ['user_id' => $otherUser->id]);

        // Malicious body tries to override user_id.
        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'overdue',
            'user_id' => $otherUser->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame(
            $englishCard->id,
            $response->json('current_card.review_card_id'),
            'Body-supplied user_id must be ignored — Auth user id is used.'
        );
        $this->assertNotSame($otherCard->id, $response->json('current_card.review_card_id'));
    }

    // ─── 14. Queue Order is read-only (no settings writes) ───

    public function test_queue_order_settings_not_modified_by_open_session(): void
    {
        $this->eligibleCard();

        $queueOrderKeys = [
            'fsrs_queue_interday_learning_review_order',
            'fsrs_queue_new_review_order',
            'fsrs_queue_review_sort_order',
            'fsrs_queue_new_sort_order',
        ];

        // Snapshot settings rows before.
        $beforeCount = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)->count();
        $beforeValues = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)
            ->pluck('value', 'name')->all();

        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions', [
            'mode' => 'overdue',
        ]);
        $response->assertStatus(200);

        // After: no new rows, no value changes.
        $afterCount = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)->count();
        $afterValues = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)
            ->pluck('value', 'name')->all();

        $this->assertSame($beforeCount, $afterCount, 'No new Queue Order settings rows should be inserted.');
        $this->assertSame($beforeValues, $afterValues, 'No Queue Order settings values should be modified.');
    }

    public function test_queue_order_settings_not_modified_by_answer(): void
    {
        $this->eligibleCard();
        $open = $this->actingAs($this->user)->postJson('/custom-study/sessions', ['mode' => 'overdue']);
        $token = $open->json('token');

        $queueOrderKeys = [
            'fsrs_queue_interday_learning_review_order',
            'fsrs_queue_new_review_order',
            'fsrs_queue_review_sort_order',
            'fsrs_queue_new_sort_order',
        ];
        $beforeCount = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)->count();
        $beforeValues = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)
            ->pluck('value', 'name')->all();

        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions/answer', [
            'token' => $token,
            'rating' => 'good',
        ]);
        $response->assertStatus(200);

        $afterCount = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)->count();
        $afterValues = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)
            ->pluck('value', 'name')->all();

        $this->assertSame($beforeCount, $afterCount);
        $this->assertSame($beforeValues, $afterValues);
    }

    public function test_queue_order_settings_not_modified_by_resume(): void
    {
        $this->eligibleCard();
        $open = $this->actingAs($this->user)->postJson('/custom-study/sessions', ['mode' => 'overdue']);
        $token = $open->json('token');

        $queueOrderKeys = [
            'fsrs_queue_interday_learning_review_order',
            'fsrs_queue_new_review_order',
            'fsrs_queue_review_sort_order',
            'fsrs_queue_new_sort_order',
        ];
        $beforeCount = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)->count();
        $beforeValues = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)
            ->pluck('value', 'name')->all();

        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions/resume', [
            'token' => $token,
        ]);
        $response->assertStatus(200);

        $afterCount = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)->count();
        $afterValues = Setting::where('user_id', -1)->whereIn('name', $queueOrderKeys)
            ->pluck('value', 'name')->all();

        $this->assertSame($beforeCount, $afterCount);
        $this->assertSame($beforeValues, $afterValues);
    }
}
