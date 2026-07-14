<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\CustomStudy\CustomStudyCriteria;
use App\Services\CustomStudy\CustomStudySessionEligibilityService;
use App\Services\CustomStudy\CustomStudySessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * Task 2000-22 — Phase 4B CustomStudySessionEligibilityService tests.
 *
 * Verifies the batch eligibility recheck service that reuses
 * SenseReviewQueryService::confirmedSenseCardQuery + senseReviewEligible scope
 * to find which session cards are NO LONGER eligible for review.
 *
 * The service is READ-ONLY: it does NOT write to DB, does NOT write ReviewLog,
 * does NOT issue/verify token, does NOT call PreviewPolicy.
 */
class CustomStudySessionEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $language = 'english';
    private CustomStudySessionEligibilityService $service;
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));
        $this->now = Carbon::now();

        $this->user = User::forceCreate([
            'name' => 'Eligibility User',
            'email' => 'elig-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->service = app(CustomStudySessionEligibilityService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ───

    private function validUuidV4(): string
    {
        return '550e8400-e29b-41d4-a716-446655440000';
    }

    private function validDelayConfig(): array
    {
        return [
            'again_secs' => 60,
            'hard_secs' => 600,
            'good_secs' => 0,
            'easy_secs' => 0,
        ];
    }

    private function createSense(array $overrides = []): WordSense
    {
        $defaults = [
            'user_id' => $this->user->id,
            'language' => $this->language,
            'language_id' => $this->language,
            'lemma' => 'test' . Str::random(6),
            'surface_form' => 'test',
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'This is a test.',
            'example_sentence_zh' => '这是一个测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower($this->language . '|' . Str::random(10) . '|noun|测试|test')),
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

    /**
     * Creates a session state referencing the given card IDs.
     * First card = current, rest = ready_queue.
     */
    private function stateForCards(array $cardIds, int $step = 0): CustomStudySessionState
    {
        $payload = [
            'version' => 1,
            'user_id' => $this->user->id,
            'language' => $this->language,
            'mode' => 'today_forgotten',
            'parameters' => [],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->now->getTimestamp(),
            'expires_at' => $this->now->getTimestamp() + 14400,
            'ordered_candidate_ids' => $cardIds,
            'ready_queue' => count($cardIds) > 1 ? array_slice($cardIds, 1) : [],
            'delayed_repeat_queue' => [],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => count($cardIds),
            'current_card_id' => count($cardIds) > 0 ? $cardIds[0] : null,
            'step' => $step,
            'preview_delay_config' => $this->validDelayConfig(),
            'available_candidate_count' => count($cardIds),
        ];
        return CustomStudySessionState::fromArray($payload);
    }

    private function callService(CustomStudySessionState $state): array
    {
        return $this->service->findIneligibleCardIds($state, $this->now);
    }

    // ─── 1. Service resolves from container ───

    public function test_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(
            CustomStudySessionEligibilityService::class,
            app(CustomStudySessionEligibilityService::class)
        );
    }

    // ─── 2. Empty state → empty ineligible ───

    public function test_empty_state_returns_empty_array(): void
    {
        $state = $this->stateForCards([]);
        $result = $this->callService($state);

        $this->assertSame([], $result);
    }

    // ─── 3. All eligible → empty ineligible ───

    public function test_all_eligible_returns_empty_array(): void
    {
        $sense1 = $this->createSense();
        $card1 = $this->createCard($sense1);
        $sense2 = $this->createSense();
        $card2 = $this->createCard($sense2);

        $state = $this->stateForCards([$card1->id, $card2->id]);
        $result = $this->callService($state);

        $this->assertSame([], $result);
    }

    // ─── 4. Suspended card → ineligible ───

    public function test_suspended_card_is_ineligible(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED]);

        $state = $this->stateForCards([$card->id]);
        $result = $this->callService($state);

        $this->assertContains($card->id, $result);
    }

    // ─── 5. Archived card → ineligible ───

    public function test_archived_card_is_ineligible(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED]);

        $state = $this->stateForCards([$card->id]);
        $result = $this->callService($state);

        $this->assertContains($card->id, $result);
    }

    // ─── 6. Unconfirmed WordSense → ineligible ───

    public function test_unconfirmed_sense_card_is_ineligible(): void
    {
        $sense = $this->createSense(['status' => 'pending']);
        $card = $this->createCard($sense);

        $state = $this->stateForCards([$card->id]);
        $result = $this->callService($state);

        $this->assertContains($card->id, $result);
    }

    // ─── 7. fsrs_enabled=false → ineligible ───

    public function test_fsrs_disabled_card_is_ineligible(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['fsrs_enabled' => false]);

        $state = $this->stateForCards([$card->id]);
        $result = $this->callService($state);

        $this->assertContains($card->id, $result);
    }

    // ─── 8. Buried (not yet expired) → ineligible ───

    public function test_buried_card_not_yet_expired_is_ineligible(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'buried_until' => Carbon::now()->addHours(1),
        ]);

        $state = $this->stateForCards([$card->id]);
        $result = $this->callService($state);

        $this->assertContains($card->id, $result);
    }

    // ─── 9. Expired buried → eligible (auto-revert) ───

    public function test_expired_buried_card_is_eligible(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_BURIED,
            'buried_until' => Carbon::now()->subMinutes(5),
        ]);

        $state = $this->stateForCards([$card->id]);
        $result = $this->callService($state);

        $this->assertNotContains($card->id, $result);
    }

    // ─── 10. Multiple ineligible → all returned ───

    public function test_multiple_ineligible_cards_all_returned(): void
    {
        $sense1 = $this->createSense();
        $card1 = $this->createCard($sense1, ['lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED]);
        $sense2 = $this->createSense();
        $card2 = $this->createCard($sense2, ['fsrs_enabled' => false]);
        $sense3 = $this->createSense();
        $card3 = $this->createCard($sense3); // eligible

        $state = $this->stateForCards([$card1->id, $card2->id, $card3->id]);
        $result = $this->callService($state);

        $this->assertContains($card1->id, $result);
        $this->assertContains($card2->id, $result);
        $this->assertNotContains($card3->id, $result);
    }

    // ─── 11. Does NOT check completed_ids ───

    public function test_does_not_check_completed_ids(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED]);

        // Card is in completed_ids, not in current/ready/delayed
        $payload = [
            'version' => 1,
            'user_id' => $this->user->id,
            'language' => $this->language,
            'mode' => 'today_forgotten',
            'parameters' => [],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->now->getTimestamp(),
            'expires_at' => $this->now->getTimestamp() + 14400,
            'ordered_candidate_ids' => [$card->id],
            'ready_queue' => [],
            'delayed_repeat_queue' => [],
            'completed_ids' => [$card->id],
            'skipped_ineligible_ids' => [],
            'completed_count' => 1,
            'total_count' => 1,
            'current_card_id' => null,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
            'available_candidate_count' => 1,
        ];
        $state = CustomStudySessionState::fromArray($payload);
        $result = $this->callService($state);

        // Completed cards are not re-checked — result is empty (no active cards)
        $this->assertSame([], $result);
    }

    // ─── 12. Checks current_card_id ───

    public function test_checks_current_card_id(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense, ['lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED]);

        $state = $this->stateForCards([$card->id]);
        $result = $this->callService($state);

        $this->assertContains($card->id, $result);
    }

    // ─── 13. Checks ready_queue cards ───

    public function test_checks_ready_queue_cards(): void
    {
        $sense1 = $this->createSense();
        $card1 = $this->createCard($sense1); // current, eligible
        $sense2 = $this->createSense();
        $card2 = $this->createCard($sense2, ['fsrs_enabled' => false]); // ready, ineligible

        $state = $this->stateForCards([$card1->id, $card2->id]);
        $result = $this->callService($state);

        $this->assertNotContains($card1->id, $result);
        $this->assertContains($card2->id, $result);
    }

    // ─── 14. Checks delayed_repeat_queue cards ───

    public function test_checks_delayed_repeat_queue_cards(): void
    {
        $sense1 = $this->createSense();
        $card1 = $this->createCard($sense1); // current, eligible
        $sense2 = $this->createSense();
        $card2 = $this->createCard($sense2, ['fsrs_enabled' => false]); // delayed, ineligible

        $payload = [
            'version' => 1,
            'user_id' => $this->user->id,
            'language' => $this->language,
            'mode' => 'today_forgotten',
            'parameters' => [],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->now->getTimestamp(),
            'expires_at' => $this->now->getTimestamp() + 14400,
            'ordered_candidate_ids' => [$card1->id, $card2->id],
            'ready_queue' => [],
            'delayed_repeat_queue' => [
                ['card_id' => $card2->id, 'available_at' => $this->now->getTimestamp() + 60],
            ],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 2,
            'current_card_id' => $card1->id,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
            'available_candidate_count' => 2,
        ];
        $state = CustomStudySessionState::fromArray($payload);
        $result = $this->callService($state);

        $this->assertNotContains($card1->id, $result);
        $this->assertContains($card2->id, $result);
    }

    // ─── 15. Ignores cards from other users ───

    public function test_ignores_cards_from_other_users(): void
    {
        $otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => 'other-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $sense = $this->createSense(['user_id' => $otherUser->id]);
        $card = $this->createCard($sense, ['user_id' => $otherUser->id]);

        // Our session references this card ID, but the card belongs to another user
        $state = $this->stateForCards([$card->id]);
        $result = $this->callService($state);

        // The card is not found in our user's eligible set → ineligible
        $this->assertContains($card->id, $result);
    }

    // ─── 16. Does NOT write to DB ───

    public function test_does_not_write_to_db(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense);

        $reviewLogCountBefore = ReviewLog::count();
        $cardCountBefore = ReviewCard::count();

        $state = $this->stateForCards([$card->id]);
        $this->callService($state);

        $this->assertSame($reviewLogCountBefore, ReviewLog::count(), 'Must not create ReviewLog.');
        $this->assertSame($cardCountBefore, ReviewCard::count(), 'Must not create/update/delete ReviewCard.');
    }

    // ─── 17. Does NOT modify card state ───

    public function test_does_not_modify_card_state(): void
    {
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $originalLifecycle = $card->lifecycle_state;
        $originalFsrsEnabled = $card->fsrs_enabled;
        $originalDueAt = $card->fsrs_due_at;

        $state = $this->stateForCards([$card->id]);
        $this->callService($state);

        $card->refresh();
        $this->assertSame($originalLifecycle, $card->lifecycle_state);
        $this->assertSame($originalFsrsEnabled, $card->fsrs_enabled);
        $this->assertEquals($originalDueAt, $card->fsrs_due_at);
    }

    // ─── 18. Returns list<int> ───

    public function test_returns_list_of_integers(): void
    {
        $sense1 = $this->createSense();
        $card1 = $this->createCard($sense1, ['fsrs_enabled' => false]);
        $sense2 = $this->createSense();
        $card2 = $this->createCard($sense2);

        $state = $this->stateForCards([$card1->id, $card2->id]);
        $result = $this->callService($state);

        foreach ($result as $id) {
            $this->assertIsInt($id);
        }
    }

    // ─── 19. Method signature has exactly 2 parameters ───

    public function test_method_signature_has_two_parameters(): void
    {
        $reflection = new ReflectionClass(CustomStudySessionEligibilityService::class);
        $method = $reflection->getMethod('findIneligibleCardIds');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('state', $params[0]->getName());
        $this->assertSame('now', $params[1]->getName());
    }

    // ─── 20. Source reuses confirmedSenseCardQuery ───

    public function test_source_reuses_confirmed_sense_card_query(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(CustomStudySessionEligibilityService::class))->getFileName()
        );
        $this->assertStringContainsString('confirmedSenseCardQuery', $source);
    }

    // ─── 21. Source reuses senseReviewEligible scope ───

    public function test_source_reuses_sense_review_eligible_scope(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(CustomStudySessionEligibilityService::class))->getFileName()
        );
        $this->assertStringContainsString('senseReviewEligible', $source);
    }

    // ─── 22. Source does NOT call PreviewPolicy ───

    public function test_source_does_not_call_preview_policy(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(CustomStudySessionEligibilityService::class))->getFileName()
        );
        $this->assertStringNotContainsString('PreviewPolicy', $source);
    }

    // ─── 23. Source does NOT issue/verify token ───

    public function test_source_does_not_issue_or_verify_token(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(CustomStudySessionEligibilityService::class))->getFileName()
        );
        $this->assertStringNotContainsString('->issue(', $source);
        $this->assertStringNotContainsString('->verify(', $source);
        $this->assertStringNotContainsString('Crypt::', $source);
    }

    // ─── 24. Source does NOT write ReviewLog ───

    public function test_source_does_not_write_review_log(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(CustomStudySessionEligibilityService::class))->getFileName()
        );
        $this->assertStringNotContainsString('ReviewLog', $source);
        $this->assertStringNotContainsString('->insert(', $source);
        $this->assertStringNotContainsString('->update(', $source);
        $this->assertStringNotContainsString('->delete(', $source);
        $this->assertStringNotContainsString('->save(', $source);
    }

    // ─── 25. Handles mixed eligible+ineligible from all three queues ───

    public function test_handles_mixed_from_all_three_queues(): void
    {
        $sense1 = $this->createSense();
        $currentCard = $this->createCard($sense1); // eligible
        $sense2 = $this->createSense();
        $readyIneligible = $this->createCard($sense2, ['fsrs_enabled' => false]); // ineligible
        $sense3 = $this->createSense();
        $delayedIneligible = $this->createCard($sense3, ['lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED]); // ineligible
        $sense4 = $this->createSense();
        $readyEligible = $this->createCard($sense4); // eligible

        $payload = [
            'version' => 1,
            'user_id' => $this->user->id,
            'language' => $this->language,
            'mode' => 'today_forgotten',
            'parameters' => [],
            'session_id' => $this->validUuidV4(),
            'issued_at' => $this->now->getTimestamp(),
            'expires_at' => $this->now->getTimestamp() + 14400,
            'ordered_candidate_ids' => [$currentCard->id, $readyIneligible->id, $readyEligible->id, $delayedIneligible->id],
            'ready_queue' => [$readyIneligible->id, $readyEligible->id],
            'delayed_repeat_queue' => [
                ['card_id' => $delayedIneligible->id, 'available_at' => $this->now->getTimestamp() + 60],
            ],
            'completed_ids' => [],
            'skipped_ineligible_ids' => [],
            'completed_count' => 0,
            'total_count' => 4,
            'current_card_id' => $currentCard->id,
            'step' => 0,
            'preview_delay_config' => $this->validDelayConfig(),
            'available_candidate_count' => 4,
        ];
        $state = CustomStudySessionState::fromArray($payload);
        $result = $this->callService($state);

        $this->assertNotContains($currentCard->id, $result);
        $this->assertContains($readyIneligible->id, $result);
        $this->assertNotContains($readyEligible->id, $result);
        $this->assertContains($delayedIneligible->id, $result);
        $this->assertCount(2, $result);
    }
}
