<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AiStudyCardFullLoopGuard
 * ========================
 * Index guard test file for the AI Study Card main chain:
 *   V6 provider-preview -> V4 final-candidates-package -> V5 generate-cards
 *   -> /reviews/senses queue -> /reviews/senses/{id}/rate
 *
 * Purpose:
 *   Future GLM / OpenCode / WorkBuddy agents who change anything on the AI
 *   study card path can run this single file to confirm the main-chain
 *   safety contract is still intact. The file is intentionally self-contained
 *   and exercises the full chain in one test, plus two focused index tests
 *   that document the V5-generation and sense-rating contracts in one place.
 *
 * Coverage matrix (point numbers refer to the regression playbook at
 * docs/testing/ai-study-card-full-loop-regression-playbook.md):
 *   Point 1  — V6 provider-preview does not write learning data
 *   Point 2  — V4 final-candidates-package requires user confirmation
 *   Point 3  — V4 final-candidates-package reflects default-unchecked state
 *   Point 4  — V5 generate-cards rejects empty sense_zh (fail-closed fallback)
 *   Point 5  — V5 generate-cards does not write ReviewLog
 *   Point 6  — V5 generate-cards does not create legacy word ReviewCard
 *   Point 7  — V5 result is displayable (carries created cards + safety flags)
 *   Point 8  — Newly generated sense card immediately enters /reviews/senses queue
 *   Point 9  — Sense rating creates exactly one ReviewLog
 *   Point 10 — Sense rating only updates target ReviewCard
 *   Point 11 — Sense rating does not create a new WordSense
 *   Point 12 — Sense rating does not create a legacy word ReviewCard
 *
 * Relationship to existing guards:
 *   - AiStudyCardV6ProviderPreviewRouteTest locks point 1 in isolation.
 *   - AiStudyCardPendingItemTest locks points 4, 5, 6 at the V5 layer.
 *   - VocabularyBoxV5UiGuardTest locks V5 dialog/result UI source strings.
 *   - WordSenseTest::test_v5_generated_sense_card_is_immediately_reviewable_with_single_log_and_no_side_effects
 *     locks points 8-12 in one closed-loop test.
 *   - This file re-asserts all 12 points as a single chained index guard and
 *     disperses the single-point failure risk of the closed-loop guard by
 *     re-asserting points 9-12 in a separate file.
 */
class AiStudyCardFullLoopGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        // reviewIntervals setting fixture (same pattern as WordSenseTest).
        // Required by the FSRS rating path; RefreshDatabase wipes it.
        if (!Setting::where('name', 'reviewIntervals')->exists()) {
            Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0],
                    '-6' => [1],
                    '-5' => [2],
                    '-4' => [3],
                    '-3' => [7],
                    '-2' => [15],
                    '-1' => [30],
                ]),
            ]);
        }

        $this->user = $this->createUser('full-loop-guard@example.test', 'english');
        $this->chapter = $this->createChapter($this->user, 'english');
    }

    /**
     * Full-chain index guard: walks V6 -> V4 -> V5 -> /reviews/senses -> rate
     * in one test, locking the cross-stage safety contract.
     *
     * If this test fails, the main chain is broken — stop and investigate
     * before merging the change. Do not modify this test to make it pass
     * artificially; instead, find the regression and fix the underlying code.
     */
    public function test_full_loop_v6_to_sense_rating_locks_main_chain_safety_contract(): void
    {
        // ===== Stage 0: seed one pending item to drive the chain. =====
        $createPending = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload());
        $createPending->assertOk();
        $itemId = $createPending->json('item.id');

        // ===== Point 1: V6 provider-preview must not write learning data. =====
        // Provider-preview fails closed while security preconditions are not met
        // (provider disabled) — the contract we lock here is "no learning data is
        // written regardless of preview outcome".
        $beforeV6Preview = $this->snapshotLearningCounts();
        $this->actingAs($this->user)->postJson(
            '/ai-study-card/v6/recommendations/provider-preview',
            $this->validV6RequestPackage([$itemId])
        );
        $this->assertSame($beforeV6Preview['word_senses'], WordSense::count(), 'V6 provider-preview must not create WordSense.');
        $this->assertSame($beforeV6Preview['review_cards'], ReviewCard::count(), 'V6 provider-preview must not create ReviewCard.');
        $this->assertSame($beforeV6Preview['review_logs'], ReviewLog::count(), 'V6 provider-preview must not create ReviewLog.');

        // ===== Points 2 + 3: V4 final-candidates-package does not write learning data, =====
        // ===== and reflects ai_recommended_default_unchecked generation rule.            =====
        $beforeV4 = $this->snapshotLearningCounts();
        $v4Response = $this->actingAs($this->user)->postJson(
            '/ai-study-card/pending-items/final-candidates-package',
            [
                'selected_item_ids' => [$itemId],
                'selected_ai_recommendations' => [
                    ['word' => 'agency', 'lemma' => 'agency', 'reason' => 'recommended'],
                ],
                'unselected_ai_recommendations' => [],
            ]
        );
        $v4Response->assertOk();
        $v4Response->assertJsonPath('package.generation_rules.ai_recommended_default_unchecked', true);
        $v4Response->assertJsonPath('package.safety_flags.user_confirmation_required_before_card_generation', true);
        $this->assertSame($beforeV4['word_senses'], WordSense::count(), 'V4 must not create WordSense.');
        $this->assertSame($beforeV4['review_cards'], ReviewCard::count(), 'V4 must not create ReviewCard.');
        $this->assertSame($beforeV4['review_logs'], ReviewLog::count(), 'V4 must not create ReviewLog.');
        $finalPackage = $v4Response->json('package');

        // ===== Point 4: V5 generate-cards fails closed when sense_zh is empty. =====
        // This is the backend fallback for the frontend filterConfirmedGenerateCardItems()
        // silent-skip path. Both layers must exist; here we lock the backend 422 behaviour.
        $beforeV5Reject = $this->snapshotLearningCounts();
        $v5Reject = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $finalPackage,
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sense_zh' => '', // empty — must be rejected
                ],
            ],
        ]);
        $v5Reject->assertStatus(422);
        $this->assertSame($beforeV5Reject['word_senses'], WordSense::count(), 'Rejected V5 must not create WordSense.');
        $this->assertSame($beforeV5Reject['review_cards'], ReviewCard::count(), 'Rejected V5 must not create ReviewCard.');
        $this->assertSame($beforeV5Reject['review_logs'], ReviewLog::count(), 'Rejected V5 must not create ReviewLog.');

        // ===== Points 5 + 6 + 7: V5 generate-cards with one filled sense_zh. =====
        $beforeV5Generate = $this->snapshotLearningCounts();
        $legacyWordCardsBeforeV5 = ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count();

        $v5Generate = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $finalPackage,
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sentence_text' => 'The intellectual landscape changed quickly.',
                    'sense_zh' => '风景；景观',
                ],
            ],
        ]);
        $v5Generate->assertOk();
        $v5Generate->assertJsonPath('results.summary.created_count', 1);
        // Point 7: result carries safety flags for frontend display.
        $v5Generate->assertJsonPath('safety_flags.no_review_log_written', true);
        $v5Generate->assertJsonPath('safety_flags.no_legacy_word_card_created', true);
        $v5Generate->assertJsonPath('safety_flags.no_fsrs_rescheduled', true);

        // Point 5: no ReviewLog written by V5 generation.
        $this->assertSame($beforeV5Generate['review_logs'], ReviewLog::count(), 'V5 generate-cards must not write ReviewLog.');
        // Point 6: no legacy word card created.
        $this->assertSame(
            $legacyWordCardsBeforeV5,
            ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count(),
            'V5 generate-cards must not create legacy word ReviewCard.'
        );

        // V5 created exactly 1 sense + 1 sense card.
        $this->assertSame($beforeV5Generate['word_senses'] + 1, WordSense::count(), 'V5 must create exactly one WordSense.');
        $this->assertSame($beforeV5Generate['review_cards'] + 1, ReviewCard::count(), 'V5 must create exactly one ReviewCard.');

        $createdCardId = $v5Generate->json('results.created.0.review_card_id');
        $createdSenseId = $v5Generate->json('results.created.0.sense_id');
        $this->assertNotNull($createdCardId, 'V5 result must expose review_card_id.');
        $this->assertNotNull($createdSenseId, 'V5 result must expose sense_id.');

        $createdCard = ReviewCard::find($createdCardId);
        $this->assertSame(ReviewCard::TARGET_SENSE, $createdCard->target_type, 'V5 created card must be target_type=sense.');
        $this->assertSame('new', $createdCard->fsrs_state, 'V5 created card must start in fsrs_state=new.');
        $this->assertTrue((bool) $createdCard->fsrs_enabled, 'V5 created card must have fsrs_enabled=true.');

        // ===== Point 8: newly generated sense card immediately enters /reviews/senses queue. =====
        $queueResponse = $this->actingAs($this->user)->getJson('/reviews/senses?ignoreDailyLimits=1');
        $queueResponse->assertOk();
        $queueCardIds = collect($queueResponse->json('cards'))->pluck('review_card_id')->all();
        $this->assertContains(
            $createdCardId,
            $queueCardIds,
            'Newly generated sense card must be immediately reviewable in /reviews/senses queue.'
        );

        // ===== Points 9, 10, 11, 12: rate the new card once and lock the safety contract. =====
        $beforeRate = $this->snapshotLearningCounts();
        $senseCardsBeforeRate = ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count();
        $legacyWordCardsBeforeRate = ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count();

        $rateResponse = $this->actingAs($this->user)->postJson("/reviews/senses/{$createdCardId}/rate", [
            'rating' => 'good',
        ]);
        $rateResponse->assertOk();

        // Point 9: exactly one ReviewLog created.
        $this->assertSame($beforeRate['review_logs'] + 1, ReviewLog::count(), 'Rating must create exactly one ReviewLog.');
        // Point 11: no new WordSense.
        $this->assertSame($beforeRate['word_senses'], WordSense::count(), 'Rating must not create a new WordSense.');
        // Point 10: no new ReviewCard, only the target card updated.
        $this->assertSame($beforeRate['review_cards'], ReviewCard::count(), 'Rating must not create a new ReviewCard.');
        $this->assertSame($senseCardsBeforeRate, ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count(), 'Rating must not create a new sense card.');
        // Point 12: no new legacy word card.
        $this->assertSame($legacyWordCardsBeforeRate, ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count(), 'Rating must not create a legacy word ReviewCard.');

        // Target card FSRS fields advanced.
        $createdCard->refresh();
        $this->assertSame(1, $createdCard->fsrs_reps, 'Target card fsrs_reps must advance to 1.');
        $this->assertNotNull($createdCard->fsrs_stability, 'Target card fsrs_stability must be set.');
        $this->assertNotNull($createdCard->fsrs_difficulty, 'Target card fsrs_difficulty must be set.');
        $this->assertNotNull($createdCard->fsrs_last_reviewed_at, 'Target card fsrs_last_reviewed_at must be set.');
        $this->assertNotSame('new', $createdCard->fsrs_state, 'Target card fsrs_state must leave new.');

        // New ReviewLog correctly tied to target card with source=sense_review.
        $newLog = ReviewLog::orderByDesc('id')->first();
        $this->assertNotNull($newLog, 'A new ReviewLog must exist.');
        $this->assertSame($createdCardId, $newLog->review_card_id, 'New ReviewLog must reference the target card.');
        $this->assertSame('good', $newLog->rating, 'New ReviewLog must carry the submitted rating.');
        $this->assertSame('sense_review', $newLog->source, 'New ReviewLog must have source=sense_review.');

        // ===== Final delta: V5 generation + one rating. =====
        // word_senses +1 (V5 only), review_cards +1 (V5 only), review_logs +1 (rating only).
        $this->assertSame($beforeV6Preview['word_senses'] + 1, WordSense::count(), 'Final WordSense delta must be +1 (V5 only).');
        $this->assertSame($beforeV6Preview['review_cards'] + 1, ReviewCard::count(), 'Final ReviewCard delta must be +1 (V5 only).');
        $this->assertSame($beforeV6Preview['review_logs'] + 1, ReviewLog::count(), 'Final ReviewLog delta must be +1 (rating only).');
    }

    /**
     * V5 generation safety contract index: documents all V5 generate-cards
     * safety invariants in a single focused test. Acts as a fast-failure index
     * for future agents changing the V5 generation service / controller.
     *
     * This test intentionally re-asserts invariants that are also covered by
     * AiStudyCardPendingItemTest. The duplication is intentional: it gives
     * future agents a single test name to grep for when asking "is V5
     * generation safety still intact?".
     */
    public function test_v5_generation_safety_contract_index_documented_in_one_place(): void
    {
        // Seed: pending item + final-candidates-package.
        $createPending = $this->actingAs($this->user)->postJson('/ai-study-card/pending-items', $this->payload());
        $createPending->assertOk();
        $itemId = $createPending->json('item.id');

        $finalPackage = $this->actingAs($this->user)
            ->postJson('/ai-study-card/pending-items/final-candidates-package', [
                'selected_item_ids' => [$itemId],
                'selected_ai_recommendations' => [],
                'unselected_ai_recommendations' => [],
            ])
            ->assertOk()
            ->json('package');

        $legacyWordCardsBefore = ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count();

        // Pre-existing scheduled card — V5 must not reschedule it.
        $existingScheduledCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => 99999,
            'fsrs_state' => 'review',
            'fsrs_stability' => 5.5,
            'fsrs_difficulty' => 0.3,
            'fsrs_due_at' => now()->addDays(7),
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
            'fsrs_enabled' => true,
        ]);

        // Snapshot AFTER the fixture card is created so the +1 delta assertion
        // only counts the V5-generated card.
        $before = $this->snapshotLearningCounts();

        $response = $this->actingAs($this->user)->postJson('/ai-study-card/generate-cards', [
            'final_candidates_package' => $finalPackage,
            'confirmed_items' => [
                [
                    'source' => 'user_selected',
                    'item_id' => $itemId,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'chapter_id' => $this->chapter->id,
                    'sentence_text' => 'The intellectual landscape changed quickly.',
                    'sense_zh' => '风景',
                ],
            ],
        ]);
        $response->assertOk();
        $response->assertJsonPath('results.summary.created_count', 1);

        // Point 5: no ReviewLog written.
        $this->assertSame($before['review_logs'], ReviewLog::count(), 'V5 must not write ReviewLog.');

        // Point 6: no legacy word card created.
        $this->assertSame(
            $legacyWordCardsBefore,
            ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count(),
            'V5 must not create legacy word ReviewCard.'
        );

        // V5 created exactly 1 WordSense + 1 sense ReviewCard.
        $this->assertSame($before['word_senses'] + 1, WordSense::count(), 'V5 must create exactly one WordSense.');
        $this->assertSame($before['review_cards'] + 1, ReviewCard::count(), 'V5 must create exactly one ReviewCard.');

        // V5 did not reschedule the existing scheduled card.
        $existingScheduledCard->refresh();
        $this->assertSame('review', $existingScheduledCard->fsrs_state, 'V5 must not change existing card fsrs_state.');
        $this->assertSame(3, $existingScheduledCard->fsrs_reps, 'V5 must not change existing card fsrs_reps.');
        $this->assertSame(1, $existingScheduledCard->fsrs_lapses, 'V5 must not change existing card fsrs_lapses.');
        $this->assertSame(5.5, $existingScheduledCard->fsrs_stability, 'V5 must not change existing card fsrs_stability.');

        // V5 result payload has all safety flags.
        $flags = $response->json('safety_flags');
        $this->assertTrue($flags['no_review_log_written'], 'safety_flags.no_review_log_written must be true.');
        $this->assertTrue($flags['no_legacy_word_card_created'], 'safety_flags.no_legacy_word_card_created must be true.');
        $this->assertTrue($flags['no_fsrs_rescheduled'], 'safety_flags.no_fsrs_rescheduled must be true.');
        $this->assertTrue($flags['no_ai_called_by_linguacafe'], 'safety_flags.no_ai_called_by_linguacafe must be true.');
        $this->assertTrue($flags['user_confirmation_received'], 'safety_flags.user_confirmation_received must be true.');

        // V5 created card has the correct shape.
        $createdCardId = $response->json('results.created.0.review_card_id');
        $createdCard = ReviewCard::find($createdCardId);
        $this->assertSame(ReviewCard::TARGET_SENSE, $createdCard->target_type, 'V5 card target_type must be sense.');
        $this->assertSame('new', $createdCard->fsrs_state, 'V5 card fsrs_state must be new.');
        $this->assertTrue((bool) $createdCard->fsrs_enabled, 'V5 card fsrs_enabled must be true.');

        // V5 saved sense_zh must equal what the user submitted (not the AI reason).
        $createdSenseId = $response->json('results.created.0.sense_id');
        $createdSense = WordSense::find($createdSenseId);
        $this->assertSame('风景', $createdSense->sense_zh, 'V5 must save the user-submitted sense_zh, not AI reason.');
    }

    /**
     * Sense rating safety contract index: documents all /reviews/senses/{id}/rate
     * safety invariants in a single focused test.
     *
     * This test disperses the single-point failure risk of
     * WordSenseTest::test_v5_generated_sense_card_is_immediately_reviewable_with_single_log_and_no_side_effects
     * by re-asserting points 9, 10, 11, 12 in a separate file. If the closed-loop
     * guard is broken by a WordSenseTest refactor, this test still catches
     * rating safety regressions.
     */
    public function test_sense_rating_safety_contract_index_documented_in_one_place(): void
    {
        // Pre-existing scope noise: another sense card + a legacy word card.
        // Both must remain untouched by the target rating.
        $otherSense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'other-sense',
            'surface_form' => 'other-sense',
            'pos' => 'noun',
            'sense_key' => 'other-sense-key',
            'sense_zh' => '其它释义',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
        ]);
        $otherCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherSense->id,
            'fsrs_state' => 'review',
            'fsrs_stability' => 3.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_due_at' => now()->addDay(),
            'fsrs_reps' => 4,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => now()->subDay(),
            'fsrs_enabled' => true,
        ]);
        $word = EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'stage' => -1,
            'word' => 'standalone-word',
            'kanji' => '',
            'reading' => '',
            'translation' => 'standalone translation',
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'lemma' => '',
            'added_to_srs' => now()->toDateString(),
            'next_review' => now()->toDateString(),
            'relearning' => false,
        ]);
        $legacyWordCard = app(ReviewCardService::class)->ensureWordCard($word);
        $legacyWordCard->update(['fsrs_reps' => 2, 'fsrs_due_at' => now()->addDay()]);

        // Target sense + freshly generated sense card (simulates V5 output).
        $targetSense = WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'mediation',
            'surface_form' => 'mediation',
            'pos' => 'noun',
            'sense_key' => 'mediation-key',
            'sense_zh' => '调解；斡旋',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
        ]);
        $targetCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $targetSense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);

        // Snapshot before rating.
        $wordSensesBefore = WordSense::count();
        $reviewCardsBefore = ReviewCard::count();
        $senseCardsBefore = ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count();
        $legacyWordCardsBefore = ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count();
        $reviewLogsBefore = ReviewLog::count();
        $otherCardRepsBefore = $otherCard->fsrs_reps;
        $legacyWordCardRepsBefore = $legacyWordCard->fsrs_reps;

        // Perform the rating.
        $rateResponse = $this->actingAs($this->user)->postJson("/reviews/senses/{$targetCard->id}/rate", [
            'rating' => 'good',
        ]);
        $rateResponse->assertOk();

        // Point 9: exactly one ReviewLog created.
        $this->assertSame($reviewLogsBefore + 1, ReviewLog::count(), 'Rating must create exactly one ReviewLog.');
        // Point 11: no new WordSense.
        $this->assertSame($wordSensesBefore, WordSense::count(), 'Rating must not create a new WordSense.');
        // Point 10: no new ReviewCard.
        $this->assertSame($reviewCardsBefore, ReviewCard::count(), 'Rating must not create a new ReviewCard.');
        $this->assertSame($senseCardsBefore, ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count(), 'Rating must not create a new sense card.');
        // Point 12: no new legacy word card.
        $this->assertSame($legacyWordCardsBefore, ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count(), 'Rating must not create a legacy word ReviewCard.');

        // Point 10 (continued): only target card updated.
        $targetCard->refresh();
        $this->assertSame(1, $targetCard->fsrs_reps, 'Target card fsrs_reps must advance to 1.');
        $this->assertNotSame('new', $targetCard->fsrs_state, 'Target card fsrs_state must leave new.');
        $this->assertNotNull($targetCard->fsrs_last_reviewed_at, 'Target card fsrs_last_reviewed_at must be set.');

        $otherCard->refresh();
        $legacyWordCard->refresh();
        $this->assertSame($otherCardRepsBefore, $otherCard->fsrs_reps, 'Other sense card must not be updated.');
        $this->assertSame($legacyWordCardRepsBefore, $legacyWordCard->fsrs_reps, 'Legacy word card must not be updated.');

        // The new ReviewLog is correctly tied to the target card.
        $newLog = ReviewLog::orderByDesc('id')->first();
        $this->assertNotNull($newLog, 'A new ReviewLog must exist.');
        $this->assertSame($targetCard->id, $newLog->review_card_id, 'New ReviewLog must reference the target card.');
        $this->assertSame('good', $newLog->rating, 'New ReviewLog must carry the submitted rating.');
        $this->assertSame('sense_review', $newLog->source, 'New ReviewLog must have source=sense_review.');
    }

    // ===== Helpers =====

    private function snapshotLearningCounts(): array
    {
        return [
            'word_senses' => WordSense::count(),
            'review_cards' => ReviewCard::count(),
            'review_logs' => ReviewLog::count(),
        ];
    }

    private function validV6RequestPackage(array $itemIds): array
    {
        return [
            'schema_version' => 'ai-study-card-v6-request-package-v1',
            'language' => 'english',
            'selected_pending_item_ids' => $itemIds,
            'selected_items' => [
                [
                    'item_id' => $itemIds[0] ?? 1,
                    'word' => 'landscape',
                    'lemma' => 'landscape',
                    'surface' => 'landscape',
                    'sentence_text' => 'The intellectual landscape changed quickly.',
                    'source' => 'user_selected_pending_item',
                ],
            ],
            'safety_flags' => [
                'user_triggered_request' => true,
                'no_card_creation' => true,
                'no_review_log_created' => true,
                'no_fsrs_changed' => true,
                'no_word_sense_created' => true,
                'no_review_card_created' => true,
                'user_confirmation_required' => true,
            ],
        ];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'chapter_id' => $this->chapter->id,
            'text_block_index' => 0,
            'sentence_index' => 0,
            'sentence_id' => '0',
            'word' => 'landscape',
            'surface' => 'landscape',
            'lemma' => 'landscape',
            'sentence_text' => 'The intellectual landscape changed quickly.',
            'source_payload' => ['source' => 'test'],
        ], $overrides);
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function createChapter(User $user, string $language): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => "Full Loop Guard {$language} Book",
            'language' => $language,
        ]);

        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => "Full Loop Guard {$language} Chapter",
            'language' => $language,
            'raw_text' => 'The intellectual landscape changed quickly.',
            'word_count' => 5,
            'read_count' => 0,
            'unique_words' => '["the","intellectual","landscape","changed","quickly"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }
}
