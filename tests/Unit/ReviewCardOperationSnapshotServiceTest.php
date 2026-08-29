<?php

namespace Tests\Unit;

use App\Models\ReviewCard;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardLifecycleSnapshotService;
use App\Services\ReviewCardOperationSnapshotService;
use Carbon\Carbon;
use Tests\TestCase;

class ReviewCardOperationSnapshotServiceTest extends TestCase
{
    private ReviewCardOperationSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReviewCardOperationSnapshotService(
            new ReviewCardFsrsSnapshotService(),
            new ReviewCardLifecycleSnapshotService(),
        );
    }

    public function test_matches_ignores_associative_json_key_order_without_changing_wire_fingerprint(): void
    {
        $card = $this->makeCard();
        $snapshot = $this->service->capture($card);
        $reordered = array_reverse($snapshot, true);
        $reordered['fsrs'] = array_reverse($snapshot['fsrs'], true);
        $reordered['lifecycle'] = array_reverse($snapshot['lifecycle'], true);

        $this->assertNotSame(
            $this->service->fingerprint($snapshot),
            $this->service->fingerprint($reordered),
            'The public preview/apply fingerprint must keep its existing wire-compatible key-order behavior.',
        );
        $this->assertTrue(
            $this->service->matches($card, $reordered),
            'Persisted MySQL JSON object key ordering must not create a false card-state conflict.',
        );
    }

    public function test_matches_still_rejects_a_real_snapshot_value_change(): void
    {
        $card = $this->makeCard();
        $snapshot = $this->service->capture($card);
        $snapshot['fsrs']['fsrs_reps']++;

        $this->assertFalse($this->service->matches($card, $snapshot));
    }

    private function makeCard(): ReviewCard
    {
        $card = new ReviewCard();
        $card->fsrs_state = 'review';
        $card->fsrs_step_index = null;
        $card->fsrs_due_at = Carbon::parse('2026-08-29T10:00:00Z');
        $card->fsrs_stability = 12.5;
        $card->fsrs_difficulty = 5.25;
        $card->fsrs_last_reviewed_at = Carbon::parse('2026-08-28T10:00:00Z');
        $card->fsrs_reps = 4;
        $card->fsrs_lapses = 1;
        $card->fsrs_enabled = true;
        $card->lifecycle_state = ReviewCard::LIFECYCLE_ACTIVE;
        $card->buried_until = null;
        $card->lifecycle_version = 2;
        $card->lifecycle_changed_at = Carbon::parse('2026-08-28T09:00:00Z');

        return $card;
    }
}
