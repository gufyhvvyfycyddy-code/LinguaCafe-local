<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinishedReadingSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('finished-reading-user@example.test', 'english');
        $this->otherUser = $this->createUser('finished-reading-other@example.test', 'english');
        $this->chapter = $this->createChapter($this->user, 'english');
    }

    public function test_finish_reading_only_moves_current_language_new_words_to_known(): void
    {
        $newWord = $this->createEncounteredWord($this->user, 'english', 'yellow', 2, ['read_count' => 1]);
        $learningWord = $this->createEncounteredWord($this->user, 'english', 'green', -7, ['read_count' => 4]);
        $knownWord = $this->createEncounteredWord($this->user, 'english', 'known', 0, ['read_count' => 7]);
        $otherUserWord = $this->createEncounteredWord($this->otherUser, 'english', 'other-user', 2, ['read_count' => 2]);
        $otherLanguageWord = $this->createEncounteredWord($this->user, 'spanish', 'amarillo', 2, ['read_count' => 3]);
        $sense = $this->createWordSense($newWord);
        $reviewCard = $this->createReviewCard($sense);
        $occurrence = $this->createOccurrence($sense, $reviewCard);
        $reviewLog = $this->createReviewLog($reviewCard);

        $originalCard = $reviewCard->fresh()->only([
            'fsrs_state',
            'fsrs_due_at',
            'fsrs_stability',
            'fsrs_difficulty',
            'fsrs_reps',
            'fsrs_lapses',
            'fsrs_last_reviewed_at',
            'fsrs_enabled',
        ]);

        $this->actingAs($this->user)->postJson('/chapters/finish', $this->finishPayload([
            ['id' => $newWord->id, 'stage' => 2, 'read_count' => 2],
            ['id' => $learningWord->id, 'stage' => -7, 'read_count' => 4],
            ['id' => $knownWord->id, 'stage' => 0, 'read_count' => 7],
            ['id' => $otherUserWord->id, 'stage' => 2, 'read_count' => 9],
            ['id' => $otherLanguageWord->id, 'stage' => 2, 'read_count' => 9],
        ]))->assertOk();

        $this->assertSame(0, $newWord->fresh()->stage);
        $this->assertSame(2, $newWord->fresh()->read_count);
        $this->assertSame(-7, $learningWord->fresh()->stage);
        $this->assertSame(0, $knownWord->fresh()->stage);
        $this->assertSame(2, $otherUserWord->fresh()->stage);
        $this->assertSame(2, $otherLanguageWord->fresh()->stage);

        $sense->refresh();
        $reviewCard->refresh();
        $occurrence->refresh();

        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->status);
        $this->assertSame($newWord->id, $sense->encountered_word_id);
        $this->assertSame($sense->id, $occurrence->word_sense_id);
        $this->assertSame($reviewCard->id, $occurrence->review_card_id);
        $this->assertSame($originalCard['fsrs_state'], $reviewCard->fsrs_state);
        $this->assertEquals($originalCard['fsrs_due_at'], $reviewCard->fsrs_due_at);
        $this->assertSame($originalCard['fsrs_stability'], $reviewCard->fsrs_stability);
        $this->assertSame($originalCard['fsrs_difficulty'], $reviewCard->fsrs_difficulty);
        $this->assertSame($originalCard['fsrs_reps'], $reviewCard->fsrs_reps);
        $this->assertSame($originalCard['fsrs_lapses'], $reviewCard->fsrs_lapses);
        $this->assertEquals($originalCard['fsrs_last_reviewed_at'], $reviewCard->fsrs_last_reviewed_at);
        $this->assertSame($originalCard['fsrs_enabled'], $reviewCard->fsrs_enabled);
        $this->assertNotNull(ReviewLog::find($reviewLog->id));
        $this->assertSame(1, ReviewCard::count());
        $this->assertSame(1, ReviewLog::count());
        $this->assertSame(1, WordSense::count());
    }

    public function test_finish_reading_does_not_auto_mark_known_when_setting_is_disabled(): void
    {
        $newWord = $this->createEncounteredWord($this->user, 'english', 'yellow', 2);

        $this->actingAs($this->user)->postJson('/chapters/finish', $this->finishPayload([
            ['id' => $newWord->id, 'stage' => 2, 'read_count' => 5],
        ], ['autoMoveWordsToKnown' => false]))->assertOk();

        $this->assertSame(2, $newWord->fresh()->stage);
        $this->assertSame(1, $this->chapter->fresh()->read_count);
        $this->assertSame(0, ReviewLog::count());
    }

    private function finishPayload(array $uniqueWords, array $overrides = []): array
    {
        return array_merge([
            'chapterId' => $this->chapter->id,
            'uniqueWords' => json_encode($uniqueWords),
            'autoLevelUpWords' => false,
            'leveledUpWords' => json_encode([]),
            'leveledUpPhrases' => json_encode([]),
            'autoMoveWordsToKnown' => true,
        ], $overrides);
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => 'Finished Reading User',
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    private function createChapter(User $user, string $language): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => "Finished {$language} Book",
            'language' => $language,
        ]);

        return Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => "Finished {$language} Chapter",
            'language' => $language,
            'raw_text' => 'Yellow green known words.',
            'word_count' => 4,
            'read_count' => 0,
            'unique_words' => '["yellow","green","known","words"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    private function createEncounteredWord(User $user, string $language, string $word, int $stage, array $overrides = []): EncounteredWord
    {
        return EncounteredWord::forceCreate(array_merge([
            'user_id' => $user->id,
            'language' => $language,
            'stage' => $stage,
            'word' => $word,
            'lemma' => $word,
            'study_base' => $word,
            'kanji' => '',
            'reading' => '',
            'base_word' => $word,
            'base_word_reading' => '',
            'translation' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'relearning' => false,
            'next_review' => $stage < 0 ? now()->addDay()->toDateString() : null,
            'added_to_srs' => $stage < 0 ? now()->toDateString() : null,
        ], $overrides));
    }

    private function createWordSense(EncounteredWord $word): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $word->user_id,
            'language' => $word->language,
            'language_id' => $word->language,
            'word_id' => $word->id,
            'encountered_word_id' => $word->id,
            'lemma' => $word->lemma,
            'surface_form' => $word->word,
            'pos' => 'noun',
            'sense_key' => 'finished-reading-' . $word->id,
            'sense_zh' => '黄色词释义',
            'sense_en' => 'test sense',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
        ]);
    }

    private function createReviewCard(WordSense $sense): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDays(2),
            'fsrs_stability' => 4.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
            'fsrs_last_reviewed_at' => now()->subDay(),
            'fsrs_enabled' => true,
        ]);
    }

    private function createOccurrence(WordSense $sense, ReviewCard $card): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate([
            'user_id' => $sense->user_id,
            'language' => $sense->language,
            'language_id' => $sense->language_id,
            'word_sense_id' => $sense->id,
            'review_card_id' => $card->id,
            'chapter_id' => $this->chapter->id,
            'sentence_id' => '0',
            'sentence_hash' => 'finished-reading-sentence',
            'sentence_en' => 'Yellow green known words.',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1,
            'evidence' => [],
            'auto_fsrs_allowed' => true,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'raw_payload' => [],
        ]);
    }

    private function createReviewLog(ReviewCard $card): ReviewLog
    {
        return ReviewLog::forceCreate([
            'user_id' => $card->user_id,
            'language_id' => $card->language_id,
            'language' => $card->language,
            'review_card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => now()->subDay(),
            'previous_state' => 'learning',
            'new_state' => 'review',
            'previous_due_at' => now()->subDays(2),
            'new_due_at' => now()->addDays(2),
            'previous_stability' => 3.0,
            'new_stability' => 4.0,
            'previous_difficulty' => 6.0,
            'new_difficulty' => 5.0,
            'source' => 'review',
        ]);
    }
}
