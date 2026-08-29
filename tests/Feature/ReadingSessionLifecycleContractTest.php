<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReadingSession;
use App\Models\ReadingSessionCardSettlement;
use App\Models\ReadingSessionCompletion;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\ReadingSessionService;
use App\Services\ReviewCardFsrsSnapshotService;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadingSessionLifecycleContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Chapter $chapter;
    private ReadingSession $session;
    private ReviewCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'B01 Reading Session',
            'email' => 'b01-reading-session-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'B01 Reading Session Book',
            'language' => 'english',
        ]);
        $processed = [[
            'word_index' => 0,
            'word' => 'bank',
            'lemma' => 'bank',
            'pos' => 'NOUN',
            'sentence_index' => 0,
            'spaceAfter' => false,
        ]];
        $this->chapter = Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'B01 Reading Session Chapter',
            'language' => 'english',
            'raw_text' => 'bank',
            'word_count' => 1,
            'read_count' => 0,
            'unique_words' => '["bank"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($processed), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
        $sense = WordSense::forceCreate([
            'user_id' => $this->user->id,
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
            'sense_key' => hash('sha256', 'b01-bank-'.Str::uuid()),
        ]);
        $this->card = app(ReviewCardService::class)->ensureSenseCard($sense);
        $this->card->forceFill([
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
            'fsrs_enabled' => true,
            'fsrs_due_at' => now(),
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
        ])->save();

        $started = app(ReadingSessionService::class)->startSession(
            $this->user->id,
            'english',
            $this->chapter->id,
        );
        $this->session = ReadingSession::where('uuid', $started['reading_session_id'])->firstOrFail();
    }

    public function test_start_endpoint_reuses_current_session_and_rejects_foreign_or_stale_recovery_ids(): void
    {
        $before = $this->scopedCounts();
        $beforeCard = $this->cardSnapshot();
        $beforeSession = $this->sessionSnapshot();

        $this->actingAs($this->user)->postJson(
            '/chapters/'.$this->chapter->id.'/reading-sessions',
            [],
        )->assertOk()
            ->assertJsonPath('reading_session_id', $this->session->uuid)
            ->assertJsonPath('resumed', true)
            ->assertJsonPath('completed', false)
            ->assertJsonPath('is_current_source', true);

        $this->postJson(
            '/chapters/'.$this->chapter->id.'/reading-sessions',
            ['resume_reading_session_id' => $this->session->uuid],
        )->assertOk()
            ->assertJsonPath('reading_session_id', $this->session->uuid)
            ->assertJsonPath('resumed', true);

        $otherChapter = $this->chapter->replicate();
        $otherChapter->name = 'B01 Other Chapter';
        $otherChapter->save();
        $this->postJson(
            '/chapters/'.$otherChapter->id.'/reading-sessions',
            ['resume_reading_session_id' => $this->session->uuid],
        )->assertStatus(409)->assertJsonPath(
            'error_code',
            ReadingSessionService::ERROR_SESSION_CHAPTER_MISMATCH,
        );

        $foreignUser = User::forceCreate([
            'name' => 'B01 Foreign Session Owner',
            'email' => 'b01-foreign-session-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->flushSession();
        $this->actingAs($foreignUser)->postJson(
            '/chapters/'.$this->chapter->id.'/reading-sessions',
            ['resume_reading_session_id' => $this->session->uuid],
        )->assertNotFound()->assertJsonPath(
            'error_code',
            ReadingSessionService::ERROR_SESSION_NOT_FOUND,
        );

        $this->user->forceFill(['selected_language' => 'spanish'])->save();
        $this->flushSession();
        $this->actingAs($this->user)->postJson(
            '/chapters/'.$this->chapter->id.'/reading-sessions',
            ['resume_reading_session_id' => $this->session->uuid],
        )->assertNotFound()->assertJsonPath(
            'error_code',
            ReadingSessionService::ERROR_SESSION_NOT_FOUND,
        );
        $this->user->forceFill(['selected_language' => 'english'])->save();

        $this->chapter->forceFill(['raw_text' => 'bank changed'])->save();
        $this->actingAs($this->user)->postJson(
            '/chapters/'.$this->chapter->id.'/reading-sessions',
            ['resume_reading_session_id' => $this->session->uuid],
        )->assertStatus(409)->assertJsonPath(
            'error_code',
            ReadingSessionService::ERROR_SESSION_STALE_SOURCE,
        );

        $this->assertSame($before, $this->scopedCounts());
        $this->assertSame(ReadingSession::STATUS_ACTIVE, $this->session->fresh()->status);
        $this->assertSame($beforeSession, $this->sessionSnapshot());
        $this->assertSame($beforeCard, $this->cardSnapshot());
    }

    public function test_completed_session_endpoint_replays_exact_stored_result_twice_without_writes(): void
    {
        $this->session->forceFill([
            'status' => ReadingSession::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();
        $stored = [
            'success' => true,
            'completed' => true,
            'reading_session_id' => $this->session->uuid,
            'chapter_id' => $this->chapter->id,
            'source_revision' => $this->session->source_revision,
            'settled_count' => 0,
        ];
        ReadingSessionCompletion::forceCreate([
            'reading_session_id' => $this->session->id,
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'chapter_id' => $this->chapter->id,
            'source_revision' => $this->session->source_revision,
            'result' => $stored,
        ]);
        $before = $this->scopedCounts();
        $beforeCard = $this->cardSnapshot();
        $beforeSession = $this->sessionSnapshot();

        $payload = ['resume_reading_session_id' => $this->session->uuid];
        $first = $this->actingAs($this->user)->postJson(
            '/chapters/'.$this->chapter->id.'/reading-sessions',
            $payload,
        );
        $second = $this->postJson(
            '/chapters/'.$this->chapter->id.'/reading-sessions',
            $payload,
        );

        $first->assertOk();
        $second->assertOk();
        $this->assertJsonStringEqualsJsonString(
            json_encode($stored, JSON_THROW_ON_ERROR),
            json_encode($first->json(), JSON_THROW_ON_ERROR),
        );
        $this->assertJsonStringEqualsJsonString(
            json_encode($stored, JSON_THROW_ON_ERROR),
            json_encode($second->json(), JSON_THROW_ON_ERROR),
        );
        $this->assertSame($before, $this->scopedCounts());
        $this->assertSame($beforeSession, $this->sessionSnapshot());
        $this->assertSame($beforeCard, $this->cardSnapshot());
        $this->assertJsonStringEqualsJsonString(
            json_encode($stored, JSON_THROW_ON_ERROR),
            json_encode(
                ReadingSessionCompletion::where('reading_session_id', $this->session->id)->firstOrFail()->result,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function scopedCounts(): array
    {
        return [
            'all_sessions' => ReadingSession::count(),
            'sessions' => ReadingSession::where('user_id', $this->user->id)->count(),
            'completions' => ReadingSessionCompletion::where('user_id', $this->user->id)->count(),
            'settlements' => ReadingSessionCardSettlement::where('user_id', $this->user->id)->count(),
            'senses' => WordSense::where('user_id', $this->user->id)->count(),
            'cards' => ReviewCard::where('user_id', $this->user->id)->count(),
            'logs' => ReviewLog::where('user_id', $this->user->id)->count(),
        ];
    }

    private function cardSnapshot(): array
    {
        return app(ReviewCardFsrsSnapshotService::class)->capture($this->card->fresh());
    }

    private function sessionSnapshot(): array
    {
        return array_intersect_key(
            $this->session->fresh()->getAttributes(),
            array_flip(['status', 'source_revision', 'started_at', 'completed_at', 'updated_at']),
        );
    }
}
