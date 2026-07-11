<?php

namespace Tests\Unit;

use App\Models\ReviewCard;
use App\Services\ReviewCardFsrsSnapshotService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * ReviewCardFsrsSnapshotServiceTest
 *
 * ADR-0009: pure unit tests for the FSRS snapshot service.
 *
 * Verifies capture / restore / matches / fingerprint / validate
 * without touching the database. The snapshot service is the
 * foundation of the undo ledger — partial restore or unstable
 * normalization would corrupt card state.
 */
class ReviewCardFsrsSnapshotServiceTest extends TestCase
{
    private ReviewCardFsrsSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReviewCardFsrsSnapshotService();
    }

    /**
     * Build an in-memory ReviewCard with all 8 FSRS fields populated.
     * No database connection is required — attributes are set directly.
     *
     * Uses array_key_exists (not ??) so that explicit null overrides
     * are respected rather than replaced by defaults.
     */
    private function makeCard(array $overrides = []): ReviewCard
    {
        $card = new ReviewCard();
        $card->fsrs_state = array_key_exists('fsrs_state', $overrides) ? $overrides['fsrs_state'] : 'review';
        $card->fsrs_due_at = array_key_exists('fsrs_due_at', $overrides) ? $overrides['fsrs_due_at'] : Carbon::parse('2026-07-11T10:00:00+00:00');
        $card->fsrs_stability = array_key_exists('fsrs_stability', $overrides) ? $overrides['fsrs_stability'] : 1.23456789;
        $card->fsrs_difficulty = array_key_exists('fsrs_difficulty', $overrides) ? $overrides['fsrs_difficulty'] : 5.67890123;
        $card->fsrs_last_reviewed_at = array_key_exists('fsrs_last_reviewed_at', $overrides) ? $overrides['fsrs_last_reviewed_at'] : Carbon::parse('2026-07-10T08:00:00+00:00');
        $card->fsrs_reps = array_key_exists('fsrs_reps', $overrides) ? $overrides['fsrs_reps'] : 3;
        $card->fsrs_lapses = array_key_exists('fsrs_lapses', $overrides) ? $overrides['fsrs_lapses'] : 1;
        $card->fsrs_enabled = array_key_exists('fsrs_enabled', $overrides) ? $overrides['fsrs_enabled'] : true;
        return $card;
    }

    // ==================== capture ====================

    public function test_capture_returns_exactly_8_fields(): void
    {
        $card = $this->makeCard();
        $snapshot = $this->service->capture($card);

        $this->assertEqualsCanonicalizing(
            ReviewCardFsrsSnapshotService::SNAPSHOT_FIELDS,
            array_keys($snapshot),
        );
    }

    public function test_capture_normalizes_datetime_to_iso8601(): void
    {
        $card = $this->makeCard([
            'fsrs_due_at' => Carbon::parse('2026-07-11T10:00:00+00:00'),
            'fsrs_last_reviewed_at' => Carbon::parse('2026-07-10T08:30:15+00:00'),
        ]);
        $snapshot = $this->service->capture($card);

        $this->assertSame('2026-07-11T10:00:00+00:00', $snapshot['fsrs_due_at']);
        $this->assertSame('2026-07-10T08:30:15+00:00', $snapshot['fsrs_last_reviewed_at']);
    }

    public function test_capture_normalizes_floats_to_6_decimal_places(): void
    {
        $card = $this->makeCard([
            'fsrs_stability' => 1.234567899999,
            'fsrs_difficulty' => 5.678901234567,
        ]);
        $snapshot = $this->service->capture($card);

        $this->assertSame(1.234568, $snapshot['fsrs_stability']);
        $this->assertSame(5.678901, $snapshot['fsrs_difficulty']);
    }

    public function test_capture_handles_null_datetime_and_floats(): void
    {
        $card = $this->makeCard([
            'fsrs_due_at' => null,
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
            'fsrs_last_reviewed_at' => null,
        ]);
        $snapshot = $this->service->capture($card);

        $this->assertNull($snapshot['fsrs_due_at']);
        $this->assertNull($snapshot['fsrs_stability']);
        $this->assertNull($snapshot['fsrs_difficulty']);
        $this->assertNull($snapshot['fsrs_last_reviewed_at']);
    }

    public function test_capture_casts_reps_and_lapses_to_int(): void
    {
        $card = $this->makeCard([
            'fsrs_reps' => '5',
            'fsrs_lapses' => '2',
        ]);
        $snapshot = $this->service->capture($card);

        $this->assertSame(5, $snapshot['fsrs_reps']);
        $this->assertSame(2, $snapshot['fsrs_lapses']);
        $this->assertIsInt($snapshot['fsrs_reps']);
        $this->assertIsInt($snapshot['fsrs_lapses']);
    }

    public function test_capture_casts_enabled_to_bool(): void
    {
        $card = $this->makeCard(['fsrs_enabled' => 1]);
        $snapshot = $this->service->capture($card);
        $this->assertTrue($snapshot['fsrs_enabled']);

        $card2 = $this->makeCard(['fsrs_enabled' => 0]);
        $snapshot2 = $this->service->capture($card2);
        $this->assertFalse($snapshot2['fsrs_enabled']);
    }

    // ==================== restore ====================

    public function test_restore_sets_all_8_attributes(): void
    {
        $card = $this->makeCard();
        $snapshot = [
            'fsrs_state' => 'relearning',
            'fsrs_due_at' => '2026-07-12T12:00:00+00:00',
            'fsrs_stability' => 2.5,
            'fsrs_difficulty' => 7.8,
            'fsrs_last_reviewed_at' => '2026-07-11T09:00:00+00:00',
            'fsrs_reps' => 10,
            'fsrs_lapses' => 3,
            'fsrs_enabled' => true,
        ];

        $this->service->restore($card, $snapshot);

        $this->assertSame('relearning', $card->fsrs_state);
        $this->assertSame('2026-07-12T12:00:00+00:00', $card->fsrs_due_at->toIso8601String());
        $this->assertSame(2.5, $card->fsrs_stability);
        $this->assertSame(7.8, $card->fsrs_difficulty);
        $this->assertSame('2026-07-11T09:00:00+00:00', $card->fsrs_last_reviewed_at->toIso8601String());
        $this->assertSame(10, $card->fsrs_reps);
        $this->assertSame(3, $card->fsrs_lapses);
        $this->assertTrue($card->fsrs_enabled);
    }

    public function test_restore_handles_null_datetime_and_floats(): void
    {
        $card = $this->makeCard();
        $snapshot = [
            'fsrs_state' => 'new',
            'fsrs_due_at' => null,
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
            'fsrs_last_reviewed_at' => null,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ];

        $this->service->restore($card, $snapshot);

        $this->assertNull($card->fsrs_due_at);
        $this->assertNull($card->fsrs_stability);
        $this->assertNull($card->fsrs_difficulty);
        $this->assertNull($card->fsrs_last_reviewed_at);
    }

    public function test_restore_does_not_save(): void
    {
        // restore() on an in-memory card should not trigger any DB query.
        // We verify by using a card that was never persisted — restore
        // must work without errors.
        $card = $this->makeCard();
        $snapshot = $this->service->capture($card);

        $card->fsrs_state = 'changed';
        $this->service->restore($card, $snapshot);

        $this->assertSame('review', $card->fsrs_state);
    }

    public function test_restore_rejects_missing_field(): void
    {
        $card = $this->makeCard();
        $incomplete = [
            'fsrs_state' => 'review',
            'fsrs_due_at' => null,
            // missing fsrs_stability
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => null,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fsrs_stability');

        $this->service->restore($card, $incomplete);
    }

    public function test_restore_does_not_allow_partial_restore(): void
    {
        // If restore() throws, the card attributes must not be partially
        // modified. The validate() runs before any attribute is set.
        $card = $this->makeCard();
        $originalState = $card->fsrs_state;

        $invalid = [
            'fsrs_state' => 'relearning',
            // missing the rest
        ];

        try {
            $this->service->restore($card, $invalid);
            $this->fail('restore should have thrown');
        } catch (\InvalidArgumentException $e) {
            // expected
        }

        // Card state must be unchanged.
        $this->assertSame($originalState, $card->fsrs_state);
    }

    // ==================== matches ====================

    public function test_matches_returns_true_for_identical_state(): void
    {
        $card = $this->makeCard();
        $snapshot = $this->service->capture($card);

        $this->assertTrue($this->service->matches($card, $snapshot));
    }

    public function test_matches_returns_true_after_capture_restore_cycle(): void
    {
        $original = $this->makeCard();
        $snapshot = $this->service->capture($original);

        $modified = $this->makeCard();
        $modified->fsrs_state = 'relearning';
        $modified->fsrs_reps = 99;

        $this->service->restore($modified, $snapshot);

        $this->assertTrue($this->service->matches($modified, $snapshot));
    }

    public function test_matches_returns_false_for_changed_state(): void
    {
        $card = $this->makeCard();
        $snapshot = $this->service->capture($card);

        $card->fsrs_state = 'relearning';

        $this->assertFalse($this->service->matches($card, $snapshot));
    }

    public function test_matches_returns_false_for_changed_reps(): void
    {
        $card = $this->makeCard();
        $snapshot = $this->service->capture($card);

        $card->fsrs_reps = 999;

        $this->assertFalse($this->service->matches($card, $snapshot));
    }

    public function test_matches_returns_false_for_changed_stability(): void
    {
        $card = $this->makeCard();
        $snapshot = $this->service->capture($card);

        $card->fsrs_stability = 99.999;

        $this->assertFalse($this->service->matches($card, $snapshot));
    }

    public function test_matches_returns_false_for_changed_enabled(): void
    {
        $card = $this->makeCard(['fsrs_enabled' => true]);
        $snapshot = $this->service->capture($card);

        $card->fsrs_enabled = false;

        $this->assertFalse($this->service->matches($card, $snapshot));
    }

    public function test_matches_returns_false_for_invalid_snapshot(): void
    {
        $card = $this->makeCard();
        $invalid = ['fsrs_state' => 'review']; // missing fields

        $this->assertFalse($this->service->matches($card, $invalid));
    }

    public function test_matches_handles_float_precision(): void
    {
        $card = $this->makeCard(['fsrs_stability' => 1.234567]);
        $snapshot = $this->service->capture($card);

        // Slightly different value that rounds to the same 6 decimal places.
        $card->fsrs_stability = 1.2345674;

        $this->assertTrue($this->service->matches($card, $snapshot));
    }

    // ==================== fingerprint ====================

    public function test_fingerprint_is_deterministic(): void
    {
        $card = $this->makeCard();
        $snapshot = $this->service->capture($card);

        $fp1 = $this->service->fingerprint($snapshot);
        $fp2 = $this->service->fingerprint($snapshot);

        $this->assertSame($fp1, $fp2);
    }

    public function test_fingerprint_differs_for_different_snapshots(): void
    {
        $card1 = $this->makeCard(['fsrs_state' => 'review']);
        $card2 = $this->makeCard(['fsrs_state' => 'relearning']);

        $fp1 = $this->service->fingerprint($this->service->capture($card1));
        $fp2 = $this->service->fingerprint($this->service->capture($card2));

        $this->assertNotSame($fp1, $fp2);
    }

    public function test_fingerprint_is_32_char_md5(): void
    {
        $card = $this->makeCard();
        $snapshot = $this->service->capture($card);

        $fp = $this->service->fingerprint($snapshot);

        $this->assertSame(32, strlen($fp));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fp);
    }

    public function test_fingerprint_throws_on_invalid_snapshot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->fingerprint(['fsrs_state' => 'review']);
    }

    // ==================== validate ====================

    public function test_validate_passes_for_complete_snapshot(): void
    {
        $card = $this->makeCard();
        $snapshot = $this->service->capture($card);

        // Should not throw.
        $this->service->validate($snapshot);
        $this->assertTrue(true);
    }

    public function test_validate_throws_on_missing_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required field: fsrs_reps');

        $this->service->validate([
            'fsrs_state' => 'review',
            'fsrs_due_at' => null,
            'fsrs_stability' => null,
            'fsrs_difficulty' => null,
            'fsrs_last_reviewed_at' => null,
            // missing fsrs_reps
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
        ]);
    }

    public function test_validate_throws_on_wrong_type_for_state(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fsrs_state must be a string');

        $snapshot = $this->service->capture($this->makeCard());
        $snapshot['fsrs_state'] = 123;

        $this->service->validate($snapshot);
    }

    public function test_validate_throws_on_wrong_type_for_enabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fsrs_enabled must be a boolean');

        $snapshot = $this->service->capture($this->makeCard());
        $snapshot['fsrs_enabled'] = 'yes';

        $this->service->validate($snapshot);
    }
}
