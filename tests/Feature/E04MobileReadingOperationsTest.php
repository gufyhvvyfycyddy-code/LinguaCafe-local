<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\MobileDevice;
use App\Models\ReadingSessionCompletion;
use App\Models\ReadingSessionInteraction;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReviewCardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class E04MobileReadingOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_reading_actions_replay_without_duplicate_review_and_finish_remains_server_authoritative(): void
    {
        Setting::forceCreate([
            'name' => 'reviewIntervals',
            'value' => json_encode(['-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3]]),
        ]);
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
            'fsrs_due_at' => now(),
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
        ])->save();
        [$token] = $this->issueToken($user);

        $started = $this->withToken($token)
            ->postJson("/api/v1/mobile/chapters/{$chapter->id}/reading-sessions", [])
            ->assertOk()
            ->assertJsonPath('data.reading_targets.0.candidate_word_senses.0.review_card_id', $card->id);
        $sessionId = $started->json('data.reading_session_id');
        $occurrenceId = $started->json('data.reading_targets.0.occurrence_id');
        $batchId = (string) Str::uuid();
        $openedActionId = (string) Str::uuid();
        $ratingActionId = (string) Str::uuid();
        $occurredAt = Carbon::now('UTC')->subMinute();
        $actions = [
            [
                'client_action_id' => $openedActionId,
                'type' => 'reading_session.interaction',
                'occurred_at' => $occurredAt->toIso8601String(),
                'sequence' => 1,
                'payload' => [
                    'reading_session_id' => $sessionId,
                    'interaction_type' => 'opened',
                    'occurrence_id' => $occurrenceId,
                ],
            ],
            [
                'client_action_id' => $ratingActionId,
                'type' => 'sense_review.rating',
                'occurred_at' => $occurredAt->copy()->addSecond()->toIso8601String(),
                'sequence' => 2,
                'payload' => [
                    'review_card_id' => $card->id,
                    'rating' => 'good',
                    'review_duration_ms' => 250,
                    'reading_session_id' => $sessionId,
                    'occurrence_id' => $occurrenceId,
                ],
            ],
        ];

        $this->sync($token, $batchId, $actions)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results.0.outcome', 'applied')
            ->assertJsonPath('data.results.1.outcome', 'applied');
        $this->sync($token, $batchId, $actions)
            ->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'replayed')
            ->assertJsonPath('data.results.1.outcome', 'replayed');

        $this->assertSame(1, ReviewLog::where('review_card_id', $card->id)->count());
        $this->assertSame(ReviewLog::SOURCE_READING_EXPLICIT, ReviewLog::firstOrFail()->source);
        $this->assertSame(1, $card->fresh()->fsrs_reps);
        $this->assertSame(2, ReadingSessionInteraction::count());

        $this->withToken($token)
            ->postJson("/api/v1/mobile/chapters/{$chapter->id}/reading-sessions/{$sessionId}/finish", [
                'settlement_mode' => 'preflight',
            ])->assertOk()
            ->assertJsonPath('data.can_commit', true)
            ->assertJsonPath('data.passive_good_count', 0);
        $this->withToken($token)
            ->postJson("/api/v1/mobile/chapters/{$chapter->id}/reading-sessions/{$sessionId}/finish", [
                'settlement_mode' => 'commit',
            ])->assertOk()
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.passive_good_count', 0);

        $this->assertSame(1, ReviewLog::where('review_card_id', $card->id)->count());
        $this->assertSame(1, ReadingSessionCompletion::count());
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
