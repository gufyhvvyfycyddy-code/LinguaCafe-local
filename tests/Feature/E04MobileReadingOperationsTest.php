<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\MobileDevice;
use App\Models\Operation;
use App\Models\ReadingSessionCardSettlement;
use App\Models\ReadingSessionCompletion;
use App\Models\ReadingSessionInteraction;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class E04MobileReadingOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_reading_actions_replay_without_duplicate_review_and_preserve_canonical_occurred_at(): void
    {
        $ratingAt = Carbon::now('UTC')->subMinute()->startOfSecond();
        $fixture = $this->createReadingFixture($ratingAt->copy()->subSeconds(86400));
        $openedActionId = (string) Str::uuid();
        $ratingActionId = (string) Str::uuid();
        $batchId = (string) Str::uuid();
        $actions = [
            $this->interactionAction($openedActionId, $fixture['session_id'], $fixture['occurrence_id'], $ratingAt->copy()->subSecond(), 1),
            $this->ratingAction($ratingActionId, $fixture, 'good', $ratingAt, 2),
        ];

        $this->sync($fixture['token'], $batchId, $actions)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results.0.outcome', 'applied')
            ->assertJsonPath('data.results.1.outcome', 'applied');
        $this->sync($fixture['token'], $batchId, $actions)
            ->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'replayed')
            ->assertJsonPath('data.results.1.outcome', 'replayed');

        $log = ReviewLog::query()
            ->where('review_card_id', $fixture['card']->id)
            ->where('source', ReviewLog::SOURCE_READING_EXPLICIT)
            ->sole();
        $this->assertSame('good', $log->rating);
        $this->assertSame($ratingAt->toIso8601String(), $log->reviewed_at->utc()->toIso8601String());
        $this->assertSame($fixture['session_id'], $log->review_session_id);
        $this->assertSame(5, $fixture['card']->fresh()->fsrs_reps);
        $this->assertSame(2, ReadingSessionInteraction::count());
        $this->assertSame(1, Operation::where('review_card_id', $fixture['card']->id)->count());

        $this->withToken($fixture['token'])
            ->postJson("/api/v1/mobile/chapters/{$fixture['chapter']->id}/reading-sessions/{$fixture['session_id']}/finish", [
                'settlement_mode' => 'preflight',
            ])->assertOk()
            ->assertJsonPath('data.can_commit', true)
            ->assertJsonPath('data.passive_good_count', 0);
        $this->withToken($fixture['token'])
            ->postJson("/api/v1/mobile/chapters/{$fixture['chapter']->id}/reading-sessions/{$fixture['session_id']}/finish", [
                'settlement_mode' => 'commit',
            ])->assertOk()
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.passive_good_count', 0);

        $this->assertSame(1, ReviewLog::where('review_card_id', $fixture['card']->id)->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count());
        $this->assertSame(1, ReadingSessionCompletion::count());
    }

    public function test_mobile_reader_good_at_86399_seconds_is_non_scoring_and_replays_without_mutation(): void
    {
        $ratingAt = Carbon::now('UTC')->subMinute()->startOfSecond();
        $fixture = $this->createReadingFixture($ratingAt->copy()->subSeconds(86399));
        $snapshotService = app(ReviewCardFsrsSnapshotService::class);
        $before = $snapshotService->capture($fixture['card']->fresh());
        $openedActionId = (string) Str::uuid();
        $ratingActionId = (string) Str::uuid();
        $batchId = (string) Str::uuid();
        $actions = [
            $this->interactionAction($openedActionId, $fixture['session_id'], $fixture['occurrence_id'], $ratingAt->copy()->subSecond(), 1),
            $this->ratingAction($ratingActionId, $fixture, 'good', $ratingAt, 2),
        ];

        $first = $this->sync($fixture['token'], $batchId, $actions)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results.1.outcome', 'applied')
            ->assertJsonPath('data.results.1.data.review_log_id', null)
            ->assertJsonPath('data.results.1.data.scored', false);
        $second = $this->sync($fixture['token'], $batchId, $actions)
            ->assertOk()
            ->assertJsonPath('data.results.1.outcome', 'replayed')
            ->assertJsonPath('data.results.1.data.review_log_id', null)
            ->assertJsonPath('data.results.1.data.scored', false);

        $this->assertSame($first->json('data.results.1.data'), $second->json('data.results.1.data'));
        $this->assertSame(0, ReviewLog::where('review_card_id', $fixture['card']->id)->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count());
        $this->assertSame(0, Operation::where('review_card_id', $fixture['card']->id)->count());
        $this->assertSame(0, ReadingSessionCardSettlement::where('review_card_id', $fixture['card']->id)->count());
        $this->assertSame(1, ReadingSessionInteraction::where('interaction_type', ReadingSessionInteraction::TYPE_EXPLICIT_NONSCORED)->where('reading_action_id', $ratingActionId)->count());
        $this->assertTrue($snapshotService->matches($fixture['card']->fresh(), $before));
    }

    public function test_mobile_reader_rejects_hard_and_easy_without_formal_write(): void
    {
        $ratingAt = Carbon::now('UTC')->subMinute()->startOfSecond();
        $fixture = $this->createReadingFixture($ratingAt->copy()->subDays(2));

        foreach (['hard', 'easy'] as $sequence => $rating) {
            $this->sync($fixture['token'], (string) Str::uuid(), [
                $this->ratingAction((string) Str::uuid(), $fixture, $rating, $ratingAt->copy()->addSeconds($sequence), $sequence + 1),
            ])->assertOk()
                ->assertJsonPath('data.status', 'failed')
                ->assertJsonPath('data.results.0.error.code', 'READING_EXPLICIT_CONTEXT_INVALID');
        }

        $this->assertSame(0, ReviewLog::where('review_card_id', $fixture['card']->id)->where('source', ReviewLog::SOURCE_READING_EXPLICIT)->count());
        $this->assertSame(0, Operation::where('review_card_id', $fixture['card']->id)->count());
    }

    public function test_mobile_reader_again_inside_24_hours_remains_a_real_failure_at_canonical_occurred_at(): void
    {
        $ratingAt = Carbon::now('UTC')->subMinute()->startOfSecond();
        $fixture = $this->createReadingFixture($ratingAt->copy()->subSeconds(30));
        $markedUnknownActionId = (string) Str::uuid();
        $ratingActionId = (string) Str::uuid();

        $this->sync($fixture['token'], (string) Str::uuid(), [
            $this->interactionAction(
                $markedUnknownActionId,
                $fixture['session_id'],
                $fixture['occurrence_id'],
                $ratingAt->copy()->subSecond(),
                1,
                'marked_unknown',
            ),
            $this->ratingAction($ratingActionId, $fixture, 'again', $ratingAt, 2),
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results.0.outcome', 'applied')
            ->assertJsonPath('data.results.0.data.interaction_type', 'marked_unknown')
            ->assertJsonPath('data.results.1.data.review_log_id', fn ($value) => is_int($value));

        $this->assertSame(1, ReadingSessionInteraction::query()
            ->where('occurrence_id', $fixture['occurrence_id'])
            ->where('interaction_type', ReadingSessionInteraction::TYPE_MARKED_UNKNOWN)
            ->count());
        $log = ReviewLog::query()
            ->where('review_card_id', $fixture['card']->id)
            ->where('source', ReviewLog::SOURCE_READING_EXPLICIT)
            ->sole();
        $this->assertSame('again', $log->rating);
        $this->assertSame($ratingAt->toIso8601String(), $log->reviewed_at->utc()->toIso8601String());
    }

    private function createReadingFixture(Carbon $anchor): array
    {
        if (!Setting::where('name', 'reviewIntervals')->exists()) {
            Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode(['-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3]]),
            ]);
        }
        $user = User::forceCreate([
            'name' => 'E04 Mobile Reader',
            'email' => 'e04-mobile-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'E04 Book',
            'language' => 'english',
        ]);
        $chapter = Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'E04 Chapter',
            'language' => 'english',
            'raw_text' => 'bank',
            'word_count' => 1,
            'read_count' => 0,
            'unique_words' => '["bank"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([[
                'word_index' => 0,
                'word' => 'bank',
                'lemma' => 'bank',
                'pos' => 'NOUN',
                'sentence_index' => 0,
                'spaceAfter' => false,
            ]]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bank',
            'surface_form' => 'bank',
            'pos' => 'NOUN',
            'sense_zh' => '银行',
            'sense_en' => 'financial institution',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => false,
            'sense_key' => hash('sha256', 'e04-bank-'.Str::uuid()),
        ]);
        $card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_step_index' => null,
            'fsrs_due_at' => $anchor->copy()->addDays(30),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 4,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => $anchor,
        ])->save();
        ReviewLog::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => $anchor,
            'previous_state' => 'review',
            'new_state' => 'review',
            'source' => ReviewLog::SOURCE_SENSE_REVIEW,
        ]);
        [$token] = $this->issueToken($user);

        $started = $this->withToken($token)
            ->postJson("/api/v1/mobile/chapters/{$chapter->id}/reading-sessions", [])
            ->assertOk()
            ->assertJsonPath('data.reading_targets.0.candidate_word_senses.0.review_card_id', $card->id);

        return [
            'user' => $user,
            'token' => $token,
            'chapter' => $chapter,
            'sense' => $sense,
            'card' => $card->fresh(),
            'session_id' => $started->json('data.reading_session_id'),
            'occurrence_id' => $started->json('data.reading_targets.0.occurrence_id'),
        ];
    }

    private function interactionAction(
        string $clientActionId,
        string $sessionId,
        string $occurrenceId,
        Carbon $occurredAt,
        int $sequence,
        string $interactionType = 'opened',
    ): array
    {
        return [
            'client_action_id' => $clientActionId,
            'type' => 'reading_session.interaction',
            'occurred_at' => $occurredAt->toIso8601String(),
            'sequence' => $sequence,
            'payload' => [
                'reading_session_id' => $sessionId,
                'interaction_type' => $interactionType,
                'occurrence_id' => $occurrenceId,
            ],
        ];
    }

    private function ratingAction(string $clientActionId, array $fixture, string $rating, Carbon $occurredAt, int $sequence): array
    {
        return [
            'client_action_id' => $clientActionId,
            'type' => 'sense_review.rating',
            'occurred_at' => $occurredAt->toIso8601String(),
            'sequence' => $sequence,
            'payload' => [
                'review_card_id' => $fixture['card']->id,
                'rating' => $rating,
                'review_duration_ms' => 250,
                'reading_session_id' => $fixture['session_id'],
                'occurrence_id' => $fixture['occurrence_id'],
            ],
        ];
    }

    private function sync(string $token, string $batchId, array $actions)
    {
        return $this->withToken($token)->postJson('/api/v1/mobile/sync/actions', [
            'batch_id' => $batchId,
            'actions' => $actions,
        ]);
    }

    private function issueToken(User $user): array
    {
        $deviceUuid = (string) Str::uuid();
        $response = $this->postJson('/api/v1/mobile/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => $deviceUuid,
            'platform' => 'web',
            'device_name' => 'E04 simulator test',
            'app_version' => '1.0.0',
        ])->assertCreated();

        return [
            $response->json('data.token'),
            MobileDevice::where('device_uuid', $deviceUuid)->firstOrFail(),
        ];
    }
}
