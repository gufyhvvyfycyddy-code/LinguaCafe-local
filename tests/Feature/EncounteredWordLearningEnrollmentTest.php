<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\Goal;
use App\Models\GoalAchievement;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\GoalService;
use App\Services\ReviewCardService;
use App\Services\VocabularyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class EncounteredWordLearningEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Enrollment Test',
            'email' => 'enrollment@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        app(GoalService::class)->createGoalsForLanguage($this->user->id, 'english');
    }

    public function test_default_confirmed_sense_enrolls_at_stage_minus_one_without_legacy_artifacts(): void
    {
        $word = $this->word('enroll-default', 2, [
            'next_review' => now()->toDateString(),
            'added_to_srs' => now()->toDateString(),
            'relearning' => true,
        ]);

        $response = $this->addSense($word);

        $response->assertOk()
            ->assertJsonPath('updated_word.stage', -1)
            ->assertJsonPath('updated_word.stage_changed', true);
        $word->refresh();
        $this->assertSame(-1, $word->stage);
        $this->assertFalse((bool) $word->relearning);
        $this->assertNull($word->next_review);
        $this->assertNull($word->added_to_srs);
        $this->assertSame(1, WordSense::where('encountered_word_id', $word->id)->where('status', WordSense::STATUS_CONFIRMED)->count());
        $this->assertSame(1, ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count());
        $this->assertSame(0, ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count());
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(1, $this->learnedToday());
    }

    public function test_keep_new_creates_sense_card_but_keeps_yellow_stage_and_goal(): void
    {
        $word = $this->word('enroll-keep-new', 2);

        $response = $this->addSense($word, ['keep_new' => true]);

        $response->assertOk()
            ->assertJsonPath('updated_word.stage', 2)
            ->assertJsonPath('updated_word.stage_changed', false);
        $this->assertSame(2, $word->fresh()->stage);
        $this->assertSame(1, ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count());
        $this->assertSame(0, ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count());
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, $this->learnedToday());
    }

    public function test_already_enrolled_word_keeps_stage_and_legacy_schedule_without_goal_increment(): void
    {
        $day = now()->addDays(4)->toDateString();
        $word = $this->word('enroll-existing', -5, [
            'next_review' => $day,
            'added_to_srs' => now()->subDays(2)->toDateString(),
            'relearning' => true,
        ]);
        $added = $word->added_to_srs;

        $response = $this->addSense($word);

        $response->assertOk()
            ->assertJsonPath('updated_word.stage', -5)
            ->assertJsonPath('updated_word.stage_changed', false);
        $word->refresh();
        $this->assertSame(-5, $word->stage);
        $this->assertSame($day, $word->next_review);
        $this->assertSame($added, $word->added_to_srs);
        $this->assertTrue((bool) $word->relearning);
        $this->assertSame(0, ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count());
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, $this->learnedToday());
    }

    public function test_known_and_ignored_words_are_not_enrolled(): void
    {
        foreach ([0, 1] as $stage) {
            $word = $this->word("enroll-stage-{$stage}", $stage);
            $this->addSense($word)->assertOk()->assertJsonPath('updated_word', null);
            $this->assertSame($stage, $word->fresh()->stage);
        }

        $this->assertSame(0, ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count());
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, $this->learnedToday());
    }

    public function test_repeated_manual_sense_does_not_count_enrollment_twice_or_create_word_card(): void
    {
        $word = $this->word('enroll-repeat', 2);

        $this->addSense($word)->assertOk();
        $this->addSense($word, ['sense_zh' => '第二释义'])->assertOk();

        $this->assertSame(-1, $word->fresh()->stage);
        $this->assertSame(1, $this->learnedToday());
        $this->assertSame(0, ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count());
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_content_only_edit_on_negative_stage_has_no_learning_side_effects(): void
    {
        $word = $this->word('content-negative', -1, [
            'translation' => '',
            'next_review' => now()->addDays(3)->toDateString(),
            'added_to_srs' => now()->subDay()->toDateString(),
            'relearning' => true,
        ]);
        $snapshot = $word->only(['stage', 'next_review', 'added_to_srs', 'relearning']);

        app(VocabularyService::class)->updateWord(
            $this->user->id,
            $word->id,
            ['translation' => '纯内容编辑', 'reading' => 'reading', 'study_base' => 'base'],
            null,
            ['chapter_id' => 123, 'sentence_index' => 0, 'word' => $word->word],
        );

        $word->refresh();
        $this->assertSame('纯内容编辑', $word->translation);
        $this->assertEquals($snapshot, $word->only(['stage', 'next_review', 'added_to_srs', 'relearning']));
        $this->assertSame(0, WordSense::count());
        $this->assertSame(0, WordSenseOccurrence::count());
        $this->assertSame(0, ReviewCard::count());
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, $this->learnedToday());
    }

    public function test_content_only_edit_on_new_word_stays_new_without_learning_artifacts(): void
    {
        $word = $this->word('content-new', 2);

        app(VocabularyService::class)->updateWord(
            $this->user->id,
            $word->id,
            ['translation' => '新词内容'],
            null,
            ['chapter_id' => 123, 'sentence_index' => 0, 'word' => $word->word],
        );

        $word->refresh();
        $this->assertSame('新词内容', $word->translation);
        $this->assertSame(2, $word->stage);
        $this->assertSame(0, WordSense::count());
        $this->assertSame(0, WordSenseOccurrence::count());
        $this->assertSame(0, ReviewCard::count());
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, $this->learnedToday());
    }

    public function test_content_only_edit_does_not_change_existing_legacy_card(): void
    {
        $word = $this->word('content-card', -7, ['translation' => 'before']);
        $card = app(ReviewCardService::class)->ensureWordCard($word);
        $card->forceFill([
            'fsrs_state' => 'review',
            'fsrs_stability' => 4.2,
            'fsrs_difficulty' => 6.1,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 1,
            'fsrs_enabled' => false,
        ])->save();
        $snapshot = $card->fresh()->only(['fsrs_state', 'fsrs_stability', 'fsrs_difficulty', 'fsrs_reps', 'fsrs_lapses', 'fsrs_enabled', 'fsrs_due_at']);

        app(VocabularyService::class)->updateWord($this->user->id, $word->id, ['translation' => 'after']);

        $this->assertEquals($snapshot, $card->fresh()->only(array_keys($snapshot)));
        $this->assertSame(0, WordSense::count());
        $this->assertSame(0, WordSenseOccurrence::count());
    }

    public function test_explicit_negative_and_non_negative_stage_requests_keep_legacy_card_behavior(): void
    {
        $word = $this->word('explicit-stage', 2, ['translation' => '旧翻译']);
        $service = app(VocabularyService::class);

        $service->updateWord($this->user->id, $word->id, [], -7);
        $card = ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->where('target_id', $word->id)->firstOrFail();
        $this->assertTrue((bool) $card->fsrs_enabled);
        $this->assertSame(1, WordSense::where('encountered_word_id', $word->id)->count());

        $service->updateWord($this->user->id, $word->id, [], 0);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
    }

    private function addSense(EncounteredWord $word, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/senses/manual', array_merge([
            'lemma' => $word->word,
            'surface_form' => $word->word,
            'pos' => 'verb',
            'sense_zh' => '测试释义',
            'encountered_word_id' => $word->id,
        ], $overrides));
    }

    private function word(string $word, int $stage, array $overrides = []): EncounteredWord
    {
        return EncounteredWord::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language' => 'english',
            'stage' => $stage,
            'word' => $word,
            'lemma' => $word,
            'kanji' => '',
            'reading' => '',
            'translation' => '',
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'relearning' => false,
        ], $overrides));
    }

    private function learnedToday(): int
    {
        $goal = Goal::where('user_id', $this->user->id)
            ->where('language', 'english')
            ->where('type', 'learn_words')
            ->firstOrFail();

        return (int) GoalAchievement::where('user_id', $this->user->id)
            ->where('language', 'english')
            ->where('goal_id', $goal->id)
            ->where('day', now()->toDateString())
            ->value('achieved_quantity');
    }
}
