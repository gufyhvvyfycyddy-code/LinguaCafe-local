<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Services\ReviewCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReviewFsrsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'FSRS User',
            'email' => 'fsrs@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        foreach ([
            [$this->user->id, 'english', 'review', 'Reviews', 0],
            [$this->user->id, 'english', 'read_words', 'Reading', 1000],
            [$this->user->id, 'english', 'learn_words', 'New words', 10],
            [$this->user->id, 'spanish', 'review', 'Reviews', 0],
            [$this->otherUser->id, 'english', 'review', 'Reviews', 0],
        ] as $goal) {
            \App\Models\Goal::forceCreate([
                'user_id' => $goal[0],
                'language' => $goal[1],
                'type' => $goal[2],
                'name' => $goal[3],
                'quantity' => $goal[4],
            ]);
        }
    }

    public function test_initialize_cards_creates_only_reviewable_word_cards(): void
    {
        $this->createWord($this->user->id, 'english', -1, 'apple');
        $this->createWord($this->user->id, 'english', -3, 'banana');
        $this->createWord($this->user->id, 'english', 1, 'ignored');
        $this->createWord($this->user->id, 'english', 0, 'known');
        $this->createWord($this->user->id, 'english', 2, 'new');
        $this->createWord($this->user->id, 'spanish', -1, 'manzana');
        $this->createWord($this->otherUser->id, 'english', -1, 'orange');

        $this->artisan('reviews:initialize-cards --dry-run')
            ->expectsOutput('Dry run: 4 review cards would be created.')
            ->assertSuccessful();
        $this->assertSame(0, ReviewCard::count());

        $this->artisan('reviews:initialize-cards')
            ->expectsOutput('Created 4 review cards.')
            ->assertSuccessful();

        $this->assertSame(4, ReviewCard::count());
        $this->assertSame(2, ReviewCard::where('user_id', $this->user->id)->where('language', 'english')->count());
        $this->assertSame(1, ReviewCard::where('user_id', $this->user->id)->where('language', 'spanish')->count());
        $this->assertSame(1, ReviewCard::where('user_id', $this->otherUser->id)->where('language', 'english')->count());

        $this->artisan('reviews:initialize-cards --dry-run')
            ->expectsOutput('Dry run: 0 review cards would be created.')
            ->assertSuccessful();
    }

    public function test_review_queue_returns_only_due_enabled_cards_for_current_user_and_language(): void
    {
        $dueWord = $this->createWord($this->user->id, 'english', -1, 'apple');
        $disabledWord = $this->createWord($this->user->id, 'english', -1, 'banana');
        $futureWord = $this->createWord($this->user->id, 'english', -1, 'cherry');
        $this->createWord($this->user->id, 'english', 1, 'ignored');
        $this->createWord($this->user->id, 'english', 0, 'known');
        $this->createWord($this->user->id, 'english', 2, 'new');
        $this->createWord($this->user->id, 'spanish', -1, 'manzana');
        $this->createWord($this->otherUser->id, 'english', -1, 'orange');

        $service = app(ReviewCardService::class);
        $dueCard = $service->ensureWordCard($dueWord);
        $disabledCard = $service->ensureWordCard($disabledWord);
        $futureCard = $service->ensureWordCard($futureWord);

        $disabledCard->update(['fsrs_enabled' => false]);
        $futureCard->update(['fsrs_due_at' => now()->addDays(2)]);
        $service->initializeExistingWords();

        $response = $this->actingAs($this->user)->post('/reviews', [
            'bookId' => -1,
            'chapterId' => -1,
            'practiceMode' => false,
        ]);

        $response->assertOk();
        $reviews = $response->json('reviews');
        $this->assertCount(1, $reviews);
        $this->assertSame($dueWord->id, $reviews[0]['id']);
        $this->assertSame($dueCard->id, $reviews[0]['review_card_id']);
    }

    public function test_each_rating_updates_card_and_writes_log(): void
    {
        foreach (['again', 'hard', 'good', 'easy'] as $rating) {
            ReviewLog::query()->delete();
            ReviewCard::query()->delete();
            EncounteredWord::query()->delete();

            $word = $this->createWord($this->user->id, 'english', -1, "word-{$rating}");
            $card = app(ReviewCardService::class)->ensureWordCard($word);

            $response = $this->actingAs($this->user)->post('/reviews/rate', [
                'reviewCardId' => $card->id,
                'rating' => $rating,
            ]);

            $response->assertOk();
            $card->refresh();
            $this->assertSame(1, $card->fsrs_reps);
            $this->assertNotNull($card->fsrs_due_at);
            $this->assertNotNull($card->fsrs_last_reviewed_at);
            $this->assertNotNull($card->fsrs_stability);
            $this->assertNotNull($card->fsrs_difficulty);
            $this->assertSame($rating === 'again' ? 1 : 0, $card->fsrs_lapses);

            $log = ReviewLog::first();
            $this->assertNotNull($log);
            $this->assertSame($this->user->id, $log->user_id);
            $this->assertSame('english', $log->language);
            $this->assertSame($card->id, $log->review_card_id);
            $this->assertSame($rating, $log->rating);
            $this->assertSame('new', $log->previous_state);
            $this->assertSame($card->fsrs_state, $log->new_state);
            $this->assertNotNull($log->previous_due_at);
            $this->assertNotNull($log->new_due_at);
            $this->assertNotNull($log->new_stability);
            $this->assertNotNull($log->new_difficulty);
        }
    }

    public function test_rating_cannot_cross_user_or_language(): void
    {
        $otherWord = $this->createWord($this->otherUser->id, 'english', -1, 'orange');
        $otherCard = app(ReviewCardService::class)->ensureWordCard($otherWord);

        $this->actingAs($this->user)->post('/reviews/rate', [
            'reviewCardId' => $otherCard->id,
            'rating' => 'good',
        ])->assertStatus(500);

        $spanishWord = $this->createWord($this->user->id, 'spanish', -1, 'manzana');
        $spanishCard = app(ReviewCardService::class)->ensureWordCard($spanishWord);

        $this->actingAs($this->user)->post('/reviews/rate', [
            'reviewCardId' => $spanishCard->id,
            'rating' => 'good',
        ])->assertStatus(500);

        $this->assertSame(0, ReviewLog::count());
    }

    public function test_vocabulary_export_still_uses_existing_search_path(): void
    {
        $this->createWord($this->user->id, 'english', -1, 'apple');

        $csv = app(\App\Services\VocabularyService::class)->exportToCsv(
            $this->user->id,
            'english',
            'anytext',
            -1,
            -1,
            -999,
            'only words',
            'words',
            'any',
            [
                ['export' => true, 'headerName' => 'Word', 'searchObjectProperty' => 'word'],
                ['export' => true, 'headerName' => 'Translation', 'searchObjectProperty' => 'translation'],
            ],
            []
        );

        $content = $csv->toString();
        $this->assertStringContainsString('Word|Translation', $content);
        $this->assertStringContainsString('apple|"apple translation"', $content);
    }

    private function createWord(int $userId, string $language, int $stage, string $word): EncounteredWord
    {
        return EncounteredWord::forceCreate([
            'user_id' => $userId,
            'language' => $language,
            'stage' => $stage,
            'word' => $word,
            'kanji' => '',
            'reading' => '',
            'translation' => "{$word} translation",
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
}
