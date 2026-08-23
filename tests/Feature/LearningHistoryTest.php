<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\Goal;
use App\Models\ReadingSession;
use App\Models\ReadingSessionCardSettlement;
use App\Models\ReadingSessionInteraction;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearningHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));
        $this->user = $this->user('history');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_timeline_keeps_historical_entries_and_excludes_non_formal_or_undone_logs(): void
    {
        Goal::forceCreate([
            'user_id' => $this->user->id, 'language' => 'english', 'name' => 'New words',
            'type' => 'learn_words', 'quantity' => 12,
        ]);
        $sense = $this->sense('bank');
        $occurrence = $this->occurrence($sense, null, 'The bank reopened.');
        $sense->forceFill([
            'learning_started_at' => now()->setTime(10, 0),
            'learning_started_origin' => WordSense::LEARNING_ORIGIN_READING,
            'learning_started_source_occurrence_id' => $occurrence->id,
            'status' => WordSense::STATUS_REJECTED,
        ])->save();
        $card = $this->card($sense, ReviewCard::LIFECYCLE_ARCHIVED);
        $formal = $this->log($card, ReviewLog::SOURCE_SENSE_REVIEW, now()->setTime(11, 0), 'good');
        $this->log($card, ReviewLog::SOURCE_SPECIAL_STUDY, now()->setTime(11, 30), 'easy', now());
        $this->log($card, 'reset', now()->setTime(11, 45), 'good');
        $this->log($card, ReviewLog::SOURCE_SENSE_REVIEW, now()->setTime(11, 50), 'reset');

        $response = $this->actingAs($this->user)->getJson(
            '/learning-history/data?date_from=2026-07-14&date_to=2026-07-14'
        )->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('data.0.event_key', 'review:'.$formal->id)
            ->assertJsonPath('data.0.source_accuracy', 'unavailable')
            ->assertJsonPath('data.0.sentence_en', null)
            ->assertJsonPath('data.1.event_key', 'learning:'.$sense->id)
            ->assertJsonPath('data.1.source_accuracy', 'exact_occurrence')
            ->assertJsonPath('data.0.current_lifecycle_state', ReviewCard::LIFECYCLE_ARCHIVED)
            ->assertJsonPath('meta.daily_reading_counts.2026-07-14', 1)
            ->assertJsonPath('meta.reading_goal_target', 12);

        $this->assertSame(WordSense::STATUS_REJECTED, $sense->fresh()->status);
    }

    public function test_filters_tie_order_and_server_pagination_are_stable(): void
    {
        $sense = $this->sense('stable');
        $sense->forceFill([
            'learning_started_at' => now()->setTime(9, 0),
            'learning_started_origin' => WordSense::LEARNING_ORIGIN_NON_READING,
        ])->save();
        $card = $this->card($sense);
        $formal = $this->log($card, ReviewLog::SOURCE_SPECIAL_STUDY, now()->setTime(9, 0), 'hard');
        $reading = $this->log($card, ReviewLog::SOURCE_READING_EXPLICIT, now()->setTime(9, 0), 'good');

        $pageOne = $this->actingAs($this->user)->getJson(
            '/learning-history/data?date_from=2026-07-14&date_to=2026-07-14&per_page=2'
        )->assertOk()->assertJsonPath('pagination.total', 3)->assertJsonPath('pagination.last_page', 2);
        $this->assertSame(['learning:'.$sense->id, 'review:'.$reading->id], array_column($pageOne->json('data'), 'event_key'));

        $this->actingAs($this->user)->getJson(
            '/learning-history/data?date_from=2026-07-14&date_to=2026-07-14&filter=formal_review'
        )->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.event_key', 'review:'.$formal->id);
        $this->actingAs($this->user)->getJson(
            '/learning-history/data?date_from=2026-07-14&date_to=2026-07-14&filter=reading_review'
        )->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.event_key', 'review:'.$reading->id);
        $this->actingAs($this->user)->getJson(
            '/learning-history/data?date_from=2026-07-14&date_to=2026-07-14&filter=new_learning'
        )->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.event_key', 'learning:'.$sense->id);
    }

    public function test_reading_review_source_accuracy_never_invents_a_sentence(): void
    {
        $chapter = $this->chapter('Source Chapter');
        $sense = $this->sense('source');
        $card = $this->card($sense);
        $exact = $this->occurrence($sense, $chapter->id, 'The exact source.', [
            'reading_occurrence_id' => 'occurrence-1', 'source_revision' => 'revision-a', 'sentence_index' => 0,
        ]);
        $explicitLog = $this->log($card, ReviewLog::SOURCE_READING_EXPLICIT, now()->setTime(10, 0), 'good');
        $explicitSession = $this->readingSession($chapter, 'revision-a');
        ReadingSessionInteraction::forceCreate([
            'reading_session_id' => $explicitSession->id, 'user_id' => $this->user->id, 'language_id' => 'english',
            'interaction_key' => 'explicit-1', 'occurrence_id' => 'occurrence-1',
            'interaction_type' => ReadingSessionInteraction::TYPE_EXPLICIT_RATED,
            'word_sense_id' => $sense->id, 'review_card_id' => $card->id, 'review_log_id' => $explicitLog->id,
            'metadata' => ['chapter_id' => $chapter->id, 'source_revision' => 'revision-a', 'sentence_index' => 0],
        ]);

        $this->occurrence($sense, $chapter->id, 'First ambiguous source.', ['source_revision' => 'revision-b']);
        $this->occurrence($sense, $chapter->id, 'Second ambiguous source.', ['source_revision' => 'revision-b']);
        $passiveLog = $this->log($card, ReviewLog::SOURCE_READING_PASSIVE, now()->setTime(11, 0), 'good');
        $passiveSession = $this->readingSession($chapter, 'revision-b');
        ReadingSessionCardSettlement::forceCreate([
            'reading_session_id' => $passiveSession->id, 'user_id' => $this->user->id, 'language_id' => 'english',
            'review_card_id' => $card->id, 'word_sense_id' => $sense->id,
            'review_log_id' => $passiveLog->id, 'rating' => 'good',
        ]);

        $response = $this->actingAs($this->user)->getJson(
            '/learning-history/data?date_from=2026-07-14&date_to=2026-07-14&filter=reading_review'
        )->assertOk();
        $rows = collect($response->json('data'))->keyBy('review_log_id');
        $this->assertSame('exact_occurrence', $rows[$explicitLog->id]['source_accuracy']);
        $this->assertSame($exact->id, $rows[$explicitLog->id]['source_occurrence_id']);
        $this->assertSame('The exact source.', $rows[$explicitLog->id]['sentence_en']);
        $this->assertSame('exact_chapter', $rows[$passiveLog->id]['source_accuracy']);
        $this->assertSame('Source Chapter', $rows[$passiveLog->id]['chapter_title']);
        $this->assertNull($rows[$passiveLog->id]['sentence_en']);
    }

    public function test_study_timezone_boundaries_isolation_validation_and_authentication(): void
    {
        $this->getJson('/learning-history/data')->assertUnauthorized();
        config(['app.timezone' => 'Asia/Shanghai']);
        $inside = $this->sense('inside');
        $inside->forceFill([
            'learning_started_at' => Carbon::create(2026, 7, 14, 0, 0, 0, 'Asia/Shanghai'),
            'learning_started_origin' => WordSense::LEARNING_ORIGIN_READING,
        ])->save();
        $outside = $this->sense('outside');
        $outside->forceFill([
            'learning_started_at' => Carbon::create(2026, 7, 15, 0, 0, 0, 'Asia/Shanghai'),
            'learning_started_origin' => WordSense::LEARNING_ORIGIN_READING,
        ])->save();
        $foreign = $this->user('foreign');
        $foreignSense = $this->sense('foreign', $foreign);
        $foreignSense->forceFill([
            'learning_started_at' => Carbon::create(2026, 7, 14, 9, 0, 0, 'Asia/Shanghai'),
            'learning_started_origin' => WordSense::LEARNING_ORIGIN_READING,
        ])->save();

        $this->actingAs($this->user)->getJson(
            '/learning-history/data?date_from=2026-07-14&date_to=2026-07-14'
        )->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.event_key', 'learning:'.$inside->id)
            ->assertJsonPath('meta.study_timezone', 'Asia/Shanghai')
            ->assertJsonPath('meta.daily_reading_counts.2026-07-14', 1);

        $this->actingAs($this->user)->getJson('/learning-history/data?date_from=2026-07-14')
            ->assertUnprocessable()->assertJsonValidationErrors(['date_to']);
        $this->actingAs($this->user)->getJson('/learning-history/data?filter=unsupported')
            ->assertUnprocessable()->assertJsonValidationErrors(['filter']);
    }

    private function user(string $label): User
    {
        return User::forceCreate([
            'name' => 'History '.$label, 'email' => 'history-'.$label.'-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'), 'selected_language' => 'english',
            'password_changed' => true, 'uuid' => (string) Str::uuid(),
        ]);
    }

    private function sense(string $lemma, ?User $owner = null): WordSense
    {
        $owner ??= $this->user;
        return WordSense::forceCreate([
            'user_id' => $owner->id, 'language' => 'english', 'language_id' => 'english',
            'lemma' => $lemma, 'surface_form' => $lemma, 'pos' => 'noun',
            'sense_key' => $lemma.'-'.Str::uuid(), 'sense_zh' => '释义',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
    }

    private function card(WordSense $sense, string $lifecycle = ReviewCard::LIFECYCLE_ACTIVE): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $sense->user_id, 'language_id' => $sense->language_id, 'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE, 'target_id' => $sense->id,
            'fsrs_state' => 'review', 'fsrs_due_at' => now()->addDay(), 'fsrs_reps' => 4,
            'fsrs_lapses' => 1, 'fsrs_enabled' => $lifecycle !== ReviewCard::LIFECYCLE_ARCHIVED,
            'lifecycle_state' => $lifecycle,
        ]);
    }

    private function log(ReviewCard $card, string $source, Carbon $at, string $rating, ?Carbon $undoneAt = null): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $card->user_id, 'language_id' => $card->language_id, 'language' => 'english',
            'review_card_id' => $card->id, 'rating' => $rating, 'reviewed_at' => $at,
            'previous_state' => 'review', 'new_state' => 'review', 'source' => $source,
            'undone_at' => $undoneAt,
        ]);
    }

    private function occurrence(WordSense $sense, ?int $chapterId, string $sentence, array $evidence = []): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate([
            'user_id' => $sense->user_id, 'language' => 'english', 'language_id' => $sense->language_id,
            'word_sense_id' => $sense->id, 'chapter_id' => $chapterId, 'sentence_id' => '0',
            'sentence_en' => $sentence, 'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->lemma, 'lemma' => $sense->lemma, 'decision' => 'test',
            'status' => WordSenseOccurrence::STATUS_BOUND, 'source' => WordSenseOccurrence::SOURCE_READING_OCCURRENCE,
            'evidence' => $evidence,
        ]);
    }

    private function chapter(string $name): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $this->user->id, 'book_id' => 1, 'name' => $name, 'language' => 'english',
            'raw_text' => '', 'word_count' => 0, 'read_count' => 0, 'unique_words' => '[]',
            'unique_word_ids' => '[]', 'processed_text' => gzcompress('[]', 1),
            'subtitle_timestamps' => '[]', 'processing_status' => 'processed',
        ]);
    }

    private function readingSession(Chapter $chapter, string $revision): ReadingSession
    {
        return ReadingSession::forceCreate([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->user->id, 'language_id' => 'english',
            'chapter_id' => $chapter->id, 'source_revision' => $revision,
            'status' => ReadingSession::STATUS_COMPLETED, 'started_at' => now()->subHour(), 'completed_at' => now(),
        ]);
    }
}
