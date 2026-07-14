<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterAiReadingAssist;
use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseReviewCardSerializerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SenseReviewSelectedExampleAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_occurrence_owns_sentence_tokens_and_explicit_translation(): void
    {
        [$user, $sense, $first, $second, $card] = $this->twoOccurrenceCard();

        $payload = app(SenseReviewCardSerializerService::class)->serialize($card->load('sense'), [
            'preferred_occurrence_id' => $first->id,
        ]);

        $this->assertSame($first->id, $payload['displayed_occurrence_id']);
        $this->assertSame('First unrelated example.', $payload['example_sentence_en']);
        $this->assertSame('第一条例句。', $payload['example_sentence_zh']);
        $this->assertSame('occurrence', $payload['example_sentence_token_source']);
        $this->assertSame(['First', 'unrelated', 'example.'], array_column($payload['example_sentence_tokens'], 'word'));
        $this->assertSame('occurrence', $payload['example_sentence_translation_source']);
    }

    public function test_selected_occurrence_uses_only_exact_saved_assist_translation(): void
    {
        [$user, $sense, $first, $second, $card] = $this->twoOccurrenceCard(['sentence_zh' => null]);

        ChapterAiReadingAssist::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'chapter_id' => $second->chapter_id,
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 1, 'source_text' => 'Second aligned example.', 'translation_zh' => '保存的第二句。'],
                ['sentence_index' => 0, 'source_text' => 'First unrelated example.', 'translation_zh' => '错误句子不得泄漏。'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
            'summary' => [],
        ]);

        $payload = app(SenseReviewCardSerializerService::class)->serialize($card->load('sense'), [
            'preferred_occurrence_id' => $second->id,
        ]);

        $this->assertSame('Second aligned example.', $payload['example_sentence_en']);
        $this->assertSame('保存的第二句。', $payload['example_sentence_zh']);
        $this->assertSame('chapter_ai_reading_assist', $payload['example_sentence_translation_source']);
    }

    public function test_batch_serialization_uses_one_assist_lookup_for_multiple_cards(): void
    {
        [$user, $sense, $first, $second, $card] = $this->twoOccurrenceCard(['sentence_zh' => null]);

        $otherSense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'other-aligned',
            'surface_form' => 'other-aligned',
            'pos' => 'adjective',
            'sense_zh' => 'other',
            'sense_en' => 'other aligned',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Other aligned example.',
            'status' => WordSense::STATUS_CONFIRMED,
            'sense_key' => hash('sha256', Str::random()),
        ]);
        $otherOccurrence = $this->occurrence(
            $otherSense,
            Chapter::query()->findOrFail($first->chapter_id),
            0,
            'First unrelated example.',
            null,
        );
        $otherCard = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $otherSense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 1,
            'fsrs_difficulty' => 5,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SenseReviewCardSerializerService::class)->serializeMany(collect([
            $card->fresh()->load('sense'),
            $otherCard->fresh()->load('sense'),
        ]));
        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['query'] ?? '', 'chapter_ai_reading_assists'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
        $this->assertSame($otherOccurrence->id, $otherOccurrence->fresh()->id);
        $this->assertSame($second->id, $second->fresh()->id);
    }

    public function test_ai_study_card_sentence_identity_aligns_translation_and_chapter_tokens(): void
    {
        [$user, $sense, $first, $second, $card] = $this->twoOccurrenceCard([
            'sentence_zh' => null,
        ]);
        $second->forceFill([
            'sentence_id' => "ai-study-card:{$second->chapter_id}:0:1:aligned",
        ])->save();

        ChapterAiReadingAssist::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'chapter_id' => $second->chapter_id,
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 1, 'source_text' => 'Second aligned example.', 'translation_zh' => 'Synthetic aligned translation.'],
            ],
            'vocabulary_items' => [],
            'phrase_items' => [],
            'warnings' => [],
            'summary' => [],
        ]);

        $payload = app(SenseReviewCardSerializerService::class)->serialize($card->load('sense'), [
            'preferred_occurrence_id' => $second->id,
        ]);

        $this->assertSame($second->id, $payload['displayed_occurrence_id']);
        $this->assertSame('Second aligned example.', $payload['example_sentence_en']);
        $this->assertSame('Synthetic aligned translation.', $payload['example_sentence_zh']);
        $this->assertSame('chapter_ai_reading_assist', $payload['example_sentence_translation_source']);
        $this->assertSame('occurrence', $payload['example_sentence_token_source']);
        $this->assertSame(['Second', 'aligned', 'example.'], array_column($payload['example_sentence_tokens'], 'word'));
        $this->assertSame($first->id, $first->fresh()->id);
    }

    private function twoOccurrenceCard(array $secondOverrides = []): array
    {
        $user = User::forceCreate([
            'name' => 'alignment@example.com',
            'email' => 'alignment@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $chapter = Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => 0,
            'name' => 'Alignment chapter',
            'language' => 'english',
            'raw_text' => 'First unrelated example. Second aligned example.',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode((object) ['words' => [
                (object) ['word' => 'First', 'sentence_index' => 0, 'spaceAfter' => true],
                (object) ['word' => 'unrelated', 'sentence_index' => 0, 'spaceAfter' => true],
                (object) ['word' => 'example.', 'sentence_index' => 0, 'spaceAfter' => false],
                (object) ['word' => 'Second', 'sentence_index' => 1, 'spaceAfter' => true],
                (object) ['word' => 'aligned', 'sentence_index' => 1, 'spaceAfter' => true],
                (object) ['word' => 'example.', 'sentence_index' => 1, 'spaceAfter' => false],
            ]]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
            'read_count' => 0,
            'word_count' => 0,
        ]);
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'aligned',
            'surface_form' => 'aligned',
            'pos' => 'adjective',
            'sense_zh' => '对齐',
            'sense_en' => 'aligned',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Fallback must not win.',
            'example_sentence_zh' => '错误回退译文。',
            'status' => WordSense::STATUS_CONFIRMED,
            'sense_key' => hash('sha256', Str::random()),
        ]);
        $first = $this->occurrence($sense, $chapter, 0, 'First unrelated example.', '第一条例句。');
        $second = $this->occurrence($sense, $chapter, 1, 'Second aligned example.', '第二条例句。', $secondOverrides);
        $card = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 1,
            'fsrs_difficulty' => 5,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
        ]);

        return [$user, $sense, $first, $second, $card];
    }

    private function occurrence(WordSense $sense, Chapter $chapter, int $sentenceId, string $sentenceEn, ?string $sentenceZh, array $overrides = []): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate(array_merge([
            'word_sense_id' => $sense->id,
            'user_id' => $sense->user_id,
            'language' => 'english',
            'language_id' => 'english',
            'chapter_id' => $chapter->id,
            'sentence_id' => $sentenceId,
            'sentence_en' => $sentenceEn,
            'sentence_zh' => $sentenceZh,
            'surface' => 'aligned',
            'lemma' => 'aligned',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => 'test',
            'decision' => 'manual',
            'confidence' => 1,
            'auto_fsrs_allowed' => false,
        ], $overrides));
    }
}
