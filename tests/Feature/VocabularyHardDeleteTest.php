<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\Goal;
use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class VocabularyHardDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_hard_delete_removes_word_without_marking_ignored(): void
    {
        $user = $this->createUser('hard-delete@example.com');
        $word = $this->createWord($user->id, 'remove-me', -1);
        $card = $this->createWordCard($user->id, $word->id);

        $this->actingAs($user)->post('/vocabulary/word/delete', [
            'id' => $word->id,
        ])->assertOk();

        $this->assertDatabaseMissing('encountered_words', [
            'id' => $word->id,
            'stage' => 1,
        ]);
        $this->assertDatabaseMissing('encountered_words', [
            'id' => $word->id,
        ]);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_batch_hard_delete_selected_ids_removes_words_only_for_current_user_and_language(): void
    {
        $user = $this->createUser('batch-delete@example.com');
        $otherUser = $this->createUser('other-batch-delete@example.com');
        $first = $this->createWord($user->id, 'alpha', 2);
        $second = $this->createWord($user->id, 'bravo', 2);
        $spanish = $this->createWord($user->id, 'alpha', 2, 'spanish');
        $other = $this->createWord($otherUser->id, 'alpha', 2);

        $response = $this->actingAs($user)->post('/vocabulary/words/batch-hard-delete', [
            'ids' => [$first->id, $second->id, $spanish->id, $other->id],
        ]);

        $response->assertOk()->assertJsonPath('deleted', 2);
        $this->assertDatabaseMissing('encountered_words', ['id' => $first->id]);
        $this->assertDatabaseMissing('encountered_words', ['id' => $second->id]);
        $this->assertDatabaseHas('encountered_words', ['id' => $spanish->id]);
        $this->assertDatabaseHas('encountered_words', ['id' => $other->id]);
    }

    public function test_batch_ignore_still_marks_words_as_ignored(): void
    {
        $user = $this->createUser('batch-ignore@example.com');
        $word = $this->createWord($user->id, 'ignore-me', -1);
        $card = $this->createWordCard($user->id, $word->id);

        $this->actingAs($user)->post('/vocabulary/words/batch-ignore', [
            'ids' => [$word->id],
        ])->assertOk()->assertJsonPath('ignored', 1);

        $word->refresh();
        $this->assertSame(1, $word->stage);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
    }

    public function test_bulk_hard_delete_by_filters_removes_more_than_current_page(): void
    {
        $user = $this->createUser('bulk-delete@example.com');
        for ($i = 1; $i <= 35; $i++) {
            $this->createWord($user->id, 'bulk-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1);
        }
        $this->createWord($user->id, 'keep-known', 0);

        $filters = $this->filters([
            'text' => 'bulk',
            'stage' => 1,
        ]);

        $this->actingAs($user)->post('/vocabulary/words/bulk-hard-delete-count', [
            'filters' => $filters,
        ])->assertOk()->assertJsonPath('count', 35);

        $this->actingAs($user)->post('/vocabulary/words/bulk-hard-delete', [
            'filters' => $filters,
        ])->assertOk()->assertJsonPath('deleted', 35);

        $this->assertSame(0, EncounteredWord::where('user_id', $user->id)->where('word', 'like', 'bulk%')->count());
        $this->assertDatabaseHas('encountered_words', [
            'user_id' => $user->id,
            'word' => 'keep-known',
        ]);
    }

    public function test_hard_delete_rejects_linked_sense_and_disables_review(): void
    {
        $user = $this->createUser('review-delete@example.com');
        $word = $this->createWord($user->id, 'reviewable', -1);
        $card = $this->createWordCard($user->id, $word->id);
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'encountered_word_id' => $word->id,
            'lemma' => 'reviewable',
            'surface_form' => 'reviewable',
            'sense_key' => 'reviewable|test',
            'sense_zh' => '可复习的',
            'sense_en' => 'available for review',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $senseCard = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);
        $occurrence = WordSenseOccurrence::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'sentence_id' => 's1',
            'sentence_en' => 'A reviewable word.',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'reviewable',
            'lemma' => 'reviewable',
            'decision' => 'match_existing_sense',
            'confidence' => 1,
            'auto_fsrs_allowed' => true,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_VOCAB_BRIDGE,
        ]);

        $this->actingAs($user)->post('/vocabulary/word/delete', [
            'id' => $word->id,
        ])->assertOk();

        // Word is deleted
        $this->assertDatabaseMissing('encountered_words', ['id' => $word->id]);

        // WordSense preserved but rejected
        $this->assertDatabaseHas('word_senses', ['id' => $sense->id]);
        $this->assertSame(WordSense::STATUS_REJECTED, $sense->fresh()->status);

        // Occurrence preserved
        $this->assertDatabaseHas('word_sense_occurrences', ['id' => $occurrence->id]);

        // Word-type review card disabled (legacy behavior)
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);

        // Sense-type review card deleted
        $this->assertNull(ReviewCard::find($senseCard->id));

        $response = $this->actingAs($user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('reviews'));
    }

    public function test_hard_delete_does_not_reject_same_lemma_different_encountered_word_sense(): void
    {
        $user = $this->createUser('cross-sense@example.com');
        $word1 = $this->createWord($user->id, 'cross-sense', -1);
        $word2 = $this->createWord($user->id, 'cross-sense-2', -1);
        $word2->update(['word' => 'cross-sense-2']);

        $sense1 = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'encountered_word_id' => $word1->id,
            'lemma' => 'shared-lemma',
            'surface_form' => 'shared-lemma',
            'sense_key' => 'shared-lemma|test1',
            'sense_zh' => '测试1',
            'sense_en' => 'test1',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $sense2 = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'encountered_word_id' => $word2->id,
            'lemma' => 'shared-lemma',
            'surface_form' => 'shared-lemma',
            'sense_key' => 'shared-lemma|test2',
            'sense_zh' => '测试2',
            'sense_en' => 'test2',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
        $card2 = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense2->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);

        // Delete only word1
        $this->actingAs($user)->post('/vocabulary/word/delete', [
            'id' => $word1->id,
        ])->assertOk();

        // Sense1 (linked to word1) is rejected
        $this->assertSame(WordSense::STATUS_REJECTED, $sense1->fresh()->status);

        // Sense2 (linked to word2, different encountered_word_id) is untouched
        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense2->fresh()->status);
        $this->assertNotNull(ReviewCard::find($card2->id));
    }

    public function test_hard_delete_sense_excluded_from_candidates(): void
    {
        $user = $this->createUser('candidate-delete@example.com');
        $word = $this->createWord($user->id, 'candidate-word', -1);
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'encountered_word_id' => $word->id,
            'lemma' => 'candidate-lemma',
            'surface_form' => 'candidate-lemma',
            'sense_key' => 'candidate-lemma|test',
            'sense_zh' => '候选词',
            'sense_en' => 'candidate word',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);

        // Before delete: sense in candidates
        $cBefore = $this->actingAs($user)
            ->get('/senses/candidates?lemma=candidate-lemma')
            ->json();
        $this->assertContains($sense->id, array_column($cBefore, 'sense_id'));

        // Delete word
        $this->actingAs($user)->post('/vocabulary/word/delete', [
            'id' => $word->id,
        ])->assertOk();

        // After delete: sense not in candidates (rejected)
        $cAfter = $this->actingAs($user)
            ->get('/senses/candidates?lemma=candidate-lemma')
            ->json();
        $this->assertNotContains($sense->id, array_column($cAfter, 'sense_id'));
    }

    public function test_deleted_word_can_be_created_again_later(): void
    {
        $user = $this->createUser('reimport-delete@example.com');
        $word = $this->createWord($user->id, 'returning', 2);

        $this->actingAs($user)->post('/vocabulary/word/delete', [
            'id' => $word->id,
        ])->assertOk();

        $newWord = $this->createWord($user->id, 'returning', 2);

        $this->assertNotSame($word->id, $newWord->id);
        $this->assertDatabaseHas('encountered_words', [
            'id' => $newWord->id,
            'word' => 'returning',
        ]);
    }

    private function createUser(string $email): User
    {
        $user = User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);

        Goal::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'type' => 'review',
            'name' => 'Reviews',
            'quantity' => 0,
        ]);

        return $user;
    }

    private function createWord(int $userId, string $word, int $stage, string $language = 'english'): EncounteredWord
    {
        return EncounteredWord::forceCreate([
            'user_id' => $userId,
            'language' => $language,
            'stage' => $stage,
            'word' => $word,
            'kanji' => '',
            'reading' => '',
            'translation' => '',
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'lemma' => '',
            'added_to_srs' => $stage < 0 ? now()->toDateString() : null,
            'next_review' => $stage < 0 ? now()->toDateString() : null,
            'relearning' => false,
        ]);
    }

    private function createWordCard(int $userId, int $wordId): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $userId,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $wordId,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);
    }

    private function filters(array $overrides = []): array
    {
        return array_merge([
            'text' => 'anytext',
            'book' => -1,
            'chapter' => -1,
            'stage' => -999,
            'phrases' => 'both',
            'orderBy' => 'words',
            'translation' => 'any',
        ], $overrides);
    }
}
