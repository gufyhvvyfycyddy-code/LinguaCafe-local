<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\CustomStudy\Queries\SourceChapterQuery;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CustomStudySourceChapterQueryTest — Task 2000-18 / Phase 2B
 *
 * Verifies the SourceChapterQuery boundary:
 *  - Direct path: WordSense.source_chapter_id matches.
 *  - Occurrence path: bound WordSenseOccurrence with matching chapter_id.
 *  - Both paths combined without duplicating cards.
 *  - Strict user / language isolation on cards, WordSense, occurrence.
 *  - Confirmed-sense + lifecycle + fsrs_enabled isolation reused via
 *    confirmedSenseCardQuery + scopeSenseReviewEligible.
 *  - Returns a composable Builder; no writes; no N+1.
 */
class CustomStudySourceChapterQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private string $language = 'english';
    private SourceChapterQuery $query;
    private Carbon $now;
    private ?string $originalTz = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTz = config('app.timezone');
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0, 'UTC'));
        $this->now = Carbon::now();

        $this->user = User::forceCreate([
            'name' => 'SourceChapter User',
            'email' => 'sc-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'SourceChapter Other',
            'email' => 'sc-other-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => $this->language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->query = app(SourceChapterQuery::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->originalTz !== null) {
            config(['app.timezone' => $this->originalTz]);
        }
        parent::tearDown();
    }

    // ─── Helpers ───

    private function createChapter(int $userId, string $language): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $userId,
            'name' => 'Book-' . Str::random(4),
            'language' => $language,
        ]);

        return Chapter::forceCreate([
            'user_id' => $userId,
            'book_id' => $book->id,
            'name' => 'Chapter-' . Str::random(4),
            'language' => $language,
            'raw_text' => 'Test chapter content.',
            'word_count' => 3,
            'read_count' => 0,
            'unique_words' => '["test"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function createSense(array $overrides = []): WordSense
    {
        $defaults = [
            'user_id' => $this->user->id,
            'language' => $this->language,
            'language_id' => $this->language,
            'lemma' => 'test' . Str::random(4),
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
            'sense_key' => hash('sha256', strtolower($this->language . '|' . Str::random(8) . '|noun|测试|test')),
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
            'fsrs_due_at' => Carbon::now()->subMinutes(5),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 1,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'lifecycle_state' => 'active',
        ];
        return ReviewCard::forceCreate(array_merge($defaults, $overrides));
    }

    private function createOccurrence(WordSense $sense, Chapter $chapter, array $overrides = []): WordSenseOccurrence
    {
        $defaults = [
            'user_id' => $sense->user_id,
            'language' => $sense->language_id,
            'language_id' => $sense->language_id,
            'word_sense_id' => $sense->id,
            'review_card_id' => null,
            'chapter_id' => $chapter->id,
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'auto_fsrs_allowed' => true,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'sentence_id' => 1,
            'sentence_en' => 'test sentence',
            'decision' => 'accept',
        ];
        return WordSenseOccurrence::forceCreate(array_merge($defaults, $overrides));
    }

    private function pluckIds(int $userId, string $language, int $chapterId): array
    {
        return $this->query->build($userId, $language, $chapterId, $this->now)
            ->pluck('review_cards.id')
            ->all();
    }

    // ─── Tests ───

    public function test_direct_source_chapter_id_match_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card = $this->createCard($sense);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertContains($card->id, $ids);
    }

    public function test_bound_occurrence_match_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(); // source_chapter_id null
        $card = $this->createCard($sense);
        $this->createOccurrence($sense, $chapter);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertContains($card->id, $ids);
    }

    public function test_direct_and_occurrence_paths_combined_no_duplicate(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card = $this->createCard($sense);
        $this->createOccurrence($sense, $chapter);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertSame([$card->id], $ids, 'Card must appear exactly once even when both paths match.');
    }

    public function test_multiple_bound_occurrences_no_duplicate(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $this->createOccurrence($sense, $chapter, ['sentence_id' => 1]);
        $this->createOccurrence($sense, $chapter, ['sentence_id' => 2]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertSame([$card->id], $ids);
    }

    public function test_pending_occurrence_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $this->createOccurrence($sense, $chapter, ['status' => WordSenseOccurrence::STATUS_PENDING]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_rejected_occurrence_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $this->createOccurrence($sense, $chapter, ['status' => WordSenseOccurrence::STATUS_REJECTED]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_ignored_occurrence_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $this->createOccurrence($sense, $chapter, ['status' => WordSenseOccurrence::STATUS_IGNORED]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_occurrence_other_user_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $this->createOccurrence($sense, $chapter, ['user_id' => $this->otherUser->id]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($card->id, $ids, 'Occurrence bound to another user must not leak the card.');
    }

    public function test_occurrence_other_language_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense();
        $card = $this->createCard($sense);
        $this->createOccurrence($sense, $chapter, ['language' => 'japanese', 'language_id' => 'japanese']);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_other_user_card_not_leaked(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $otherSense = $this->createSense([
            'user_id' => $this->otherUser->id,
            'source_chapter_id' => $chapter->id, // attacker tries to bind to OUR chapter
        ]);
        $otherCard = $this->createCard($otherSense, ['user_id' => $this->otherUser->id]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($otherCard->id, $ids);
    }

    public function test_other_language_card_not_leaked(): void
    {
        // Same user, different language — should not leak into english query.
        $jpChapter = $this->createChapter($this->user->id, 'japanese');
        $jpSense = $this->createSense([
            'language' => 'japanese',
            'language_id' => 'japanese',
            'source_chapter_id' => $jpChapter->id,
        ]);
        $jpCard = $this->createCard($jpSense, ['language_id' => 'japanese', 'language' => 'japanese']);

        // Query english with the japanese chapter — chapter exists but the
        // card is filtered by language via confirmedSenseCardQuery.
        $ids = $this->pluckIds($this->user->id, $this->language, $jpChapter->id);
        $this->assertNotContains($jpCard->id, $ids);
    }

    public function test_legacy_word_card_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        // Legacy word card targeting the same target_id but target_type=word.
        $wordCard = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => $this->language,
            'language' => $this->language,
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subMinutes(5),
            'fsrs_enabled' => true,
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'lifecycle_state' => 'active',
        ]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($wordCard->id, $ids);
    }

    public function test_ai_suggested_sense_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense([
            'source_chapter_id' => $chapter->id,
            'status' => WordSense::STATUS_AI_SUGGESTED,
        ]);
        $card = $this->createCard($sense);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_rejected_sense_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense([
            'source_chapter_id' => $chapter->id,
            'status' => WordSense::STATUS_REJECTED,
        ]);
        $card = $this->createCard($sense);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_suspended_card_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card = $this->createCard($sense, ['lifecycle_state' => ReviewCard::LIFECYCLE_SUSPENDED]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_archived_card_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card = $this->createCard($sense, ['lifecycle_state' => ReviewCard::LIFECYCLE_ARCHIVED]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_future_buried_card_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card = $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_BURIED,
            'buried_until' => Carbon::now()->addDays(2),
        ]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_expired_buried_card_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card = $this->createCard($sense, [
            'lifecycle_state' => ReviewCard::LIFECYCLE_BURIED,
            'buried_until' => Carbon::now()->subMinutes(5),
        ]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertContains($card->id, $ids);
    }

    public function test_fsrs_disabled_card_not_included(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card = $this->createCard($sense, ['fsrs_enabled' => false]);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_returns_builder_instance(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $builder = $this->query->build($this->user->id, $this->language, $chapter->id, $this->now);
        $this->assertInstanceOf(Builder::class, $builder);
    }

    public function test_pluck_returns_unique_ids(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense1 = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card1 = $this->createCard($sense1);
        $sense2 = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card2 = $this->createCard($sense2);

        $ids = $this->pluckIds($this->user->id, $this->language, $chapter->id);
        $this->assertSame(count($ids), count(array_unique($ids)), 'Plucked IDs must be unique.');
        $this->assertContains($card1->id, $ids);
        $this->assertContains($card2->id, $ids);
    }

    public function test_single_candidate_sql_no_n_plus_1(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $this->createCard($sense);
        $this->createOccurrence($sense, $chapter);

        DB::enableQueryLog();
        $this->query->build($this->user->id, $this->language, $chapter->id, $this->now)
            ->pluck('review_cards.id');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'Terminated query must execute a single SQL statement.');
    }

    public function test_does_not_write_review_log(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $this->createCard($sense);

        $before = ReviewLog::count();
        $this->query->build($this->user->id, $this->language, $chapter->id, $this->now)
            ->pluck('review_cards.id');
        $this->assertSame($before, ReviewLog::count(), 'Query must not write ReviewLog.');
    }

    public function test_does_not_modify_review_card(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $card = $this->createCard($sense);
        $originalState = $card->fresh()->lifecycle_state;
        $originalDue = $card->fresh()->fsrs_due_at;

        $this->query->build($this->user->id, $this->language, $chapter->id, $this->now)
            ->pluck('review_cards.id');

        $refreshed = $card->fresh();
        $this->assertSame($originalState, $refreshed->lifecycle_state);
        $this->assertEquals($originalDue, $refreshed->fsrs_due_at);
    }

    public function test_does_not_modify_word_sense(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $chapter->id]);
        $this->createCard($sense);
        $originalStatus = $sense->fresh()->status;
        $originalChapterId = $sense->fresh()->source_chapter_id;

        $this->query->build($this->user->id, $this->language, $chapter->id, $this->now)
            ->pluck('review_cards.id');

        $refreshed = $sense->fresh();
        $this->assertSame($originalStatus, $refreshed->status);
        $this->assertSame($originalChapterId, $refreshed->source_chapter_id);
    }

    public function test_does_not_modify_word_sense_occurrence(): void
    {
        $chapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense();
        $this->createCard($sense);
        $occ = $this->createOccurrence($sense, $chapter);
        $originalStatus = $occ->fresh()->status;
        $originalChapter = $occ->fresh()->chapter_id;

        $this->query->build($this->user->id, $this->language, $chapter->id, $this->now)
            ->pluck('review_cards.id');

        $refreshed = $occ->fresh();
        $this->assertSame($originalStatus, $refreshed->status);
        $this->assertSame($originalChapter, $refreshed->chapter_id);
    }

    public function test_no_chapter_match_returns_empty(): void
    {
        $emptyChapter = $this->createChapter($this->user->id, $this->language);
        // Create a card whose sense has source_chapter_id pointing to a different chapter.
        $otherChapter = $this->createChapter($this->user->id, $this->language);
        $sense = $this->createSense(['source_chapter_id' => $otherChapter->id]);
        $this->createCard($sense);

        $ids = $this->pluckIds($this->user->id, $this->language, $emptyChapter->id);
        $this->assertSame([], $ids);
    }
}
