<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Models\WordSenseTag;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class M12SpecialStudyQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_forgotten_accepts_formal_special_study_again_and_excludes_undone(): void
    {
        $user = $this->createUser('m12-forgotten@example.test');
        $included = $this->createSenseCard($user, 'included');
        $undone = $this->createSenseCard($user, 'undone');
        $this->createAgainLog($user, $included, null);
        $this->createAgainLog($user, $undone, now());

        $response = $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'today_forgotten',
            'execution_mode' => 'preview',
            'sort' => 'most_lapses',
        ])->assertCreated();

        $this->assertSame(1, $response->json('total_candidates'));
        $this->assertSame(
            $included->id,
            $response->json('current_card.review_card_id'),
        );
    }

    public function test_review_ahead_uses_future_window_and_review_states_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00'));
        try {
            $user = $this->createUser('m12-ahead@example.test');
            $tomorrow = $this->createSenseCard($user, 'tomorrow', [
                'fsrs_due_at' => now()->addDay(),
            ]);
            $this->createSenseCard($user, 'far', [
                'fsrs_due_at' => now()->addDays(10),
            ]);
            $this->createSenseCard($user, 'new-future', [
                'fsrs_state' => 'new',
                'fsrs_due_at' => now()->addDay(),
            ]);
            $this->createSenseCard($user, 'already-due', [
                'fsrs_due_at' => now()->subMinute(),
            ]);

            $response = $this->actingAs($user)->postJson(
                '/special-study/sessions',
                [
                    'scenario' => 'review_ahead',
                    'execution_mode' => 'early_review',
                    'days' => 2,
                ],
            )->assertCreated();

            $this->assertSame(1, $response->json('total_candidates'));
            $this->assertSame(
                $tomorrow->id,
                $response->json('current_card.review_card_id'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_recent_new_uses_creation_window_without_writing(): void
    {
        $user = $this->createUser('m12-recent@example.test');
        $recent = $this->createSenseCard($user, 'recent', [
            'fsrs_state' => 'new',
            'fsrs_reps' => 0,
        ]);
        $old = $this->createSenseCard($user, 'old', [
            'fsrs_state' => 'new',
            'fsrs_reps' => 0,
        ]);
        $old->forceFill(['created_at' => now()->subDays(20)])->save();
        $this->createSenseCard($user, 'recent-review', [
            'fsrs_state' => 'review',
        ]);

        $response = $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'recent_new',
            'execution_mode' => 'preview',
            'days' => 7,
        ])->assertCreated();

        $this->assertSame(1, $response->json('total_candidates'));
        $this->assertSame(
            $recent->id,
            $response->json('current_card.review_card_id'),
        );
        $this->assertDatabaseCount('review_logs', 0);
    }

    public function test_tag_marker_article_chapter_and_state_filters_intersect(): void
    {
        $user = $this->createUser('m12-filter@example.test');
        $other = $this->createUser('m12-filter-other@example.test');
        $book = Book::forceCreate([
            'user_id' => $user->id,
            'name' => 'Target Book',
            'language' => 'english',
        ]);
        $chapter = Chapter::forceCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'name' => 'Target Chapter',
            'language' => 'english',
            'raw_text' => 'M12 target chapter.',
            'word_count' => 3,
            'read_count' => 0,
            'unique_words' => '["target"]',
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode([]), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
        [$matching, $matchingSense] = $this->createSenseCardWithSense(
            $user,
            'matching',
            ['marker' => ReviewCard::MARKER_BLUE, 'fsrs_state' => 'review'],
        );
        [$wrongMarker, $wrongSense] = $this->createSenseCardWithSense(
            $user,
            'wrong-marker',
            ['marker' => ReviewCard::MARKER_RED, 'fsrs_state' => 'review'],
        );
        $tag = WordSenseTag::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'name' => 'Focus',
            'normalized_name' => 'focus',
        ]);
        $matchingSense->tags()->attach($tag->id);
        $wrongSense->tags()->attach($tag->id);
        $this->createOccurrence($user, $matching, $matchingSense, $chapter);
        $this->createOccurrence($user, $wrongMarker, $wrongSense, $chapter);
        $otherBook = Book::forceCreate([
            'user_id' => $other->id,
            'name' => 'Private',
            'language' => 'english',
        ]);

        $response = $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'execution_mode' => 'preview',
            'filters' => [
                'tag_ids' => [$tag->id],
                'markers' => [ReviewCard::MARKER_BLUE],
                'article_ids' => [$book->id],
                'chapter_ids' => [$chapter->id],
                'lifecycle_states' => ['active'],
                'fsrs_states' => ['review'],
            ],
        ])->assertCreated();

        $this->assertSame(1, $response->json('total_candidates'));
        $this->assertSame(
            $matching->id,
            $response->json('current_card.review_card_id'),
        );

        $options = $this->actingAs($user)
            ->getJson('/special-study/options')
            ->assertOk();
        $this->assertSame([$tag->id], $options->json('tags.*.id'));
        $this->assertSame([$book->id], $options->json('articles.*.id'));
        $this->assertSame([$chapter->id], $options->json('chapters.*.id'));
        $this->assertNotContains($otherBook->id, $options->json('articles.*.id'));

        $this->actingAs($user)->postJson('/special-study/sessions', [
            'scenario' => 'filtered',
            'execution_mode' => 'preview',
            'filters' => ['article_ids' => [$otherBook->id]],
        ])->assertCreated()
            ->assertJsonPath('total_candidates', 0)
            ->assertJsonPath('current_card', null);
    }

    private function createAgainLog(
        User $user,
        ReviewCard $card,
        ?Carbon $undoneAt,
    ): void {
        ReviewLog::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'review_card_id' => $card->id,
            'rating' => 'again',
            'reviewed_at' => now(),
            'source' => 'special_study',
            'undone_at' => $undoneAt,
            'previous_state' => $card->fsrs_state,
            'new_state' => $card->fsrs_state,
        ]);
    }

    private function createOccurrence(
        User $user,
        ReviewCard $card,
        WordSense $sense,
        Chapter $chapter,
    ): void {
        WordSenseOccurrence::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'review_card_id' => $card->id,
            'chapter_id' => $chapter->id,
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'auto_fsrs_allowed' => true,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'sentence_id' => (string) Str::uuid(),
            'sentence_en' => 'M12 source sentence.',
            'decision' => 'accept',
        ]);
    }

    private function createUser(string $email): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function createSenseCard(
        User $user,
        string $lemma,
        array $overrides = [],
    ): ReviewCard {
        return $this->createSenseCardWithSense($user, $lemma, $overrides)[0];
    }

    /**
     * @return array{ReviewCard, WordSense}
     */
    private function createSenseCardWithSense(
        User $user,
        string $lemma,
        array $overrides = [],
    ): array {
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => $lemma,
            'sense_en' => $lemma,
            'aliases_zh' => [],
            'collocations' => [],
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "{$user->id}|english|{$lemma}"),
        ]);
        $card = ReviewCard::forceCreate(array_merge([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->subDay(),
            'fsrs_stability' => 5,
            'fsrs_difficulty' => 5,
            'fsrs_reps' => 3,
            'fsrs_lapses' => 0,
            'fsrs_enabled' => true,
            'lifecycle_state' => ReviewCard::LIFECYCLE_ACTIVE,
        ], $overrides));

        return [$card, $sense];
    }
}
