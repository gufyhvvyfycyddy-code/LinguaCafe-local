<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VocabularyCleanupTokensCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_update_invalid_tokens(): void
    {
        $user = $this->createUser();
        $word = $this->createWord($user->id, '2016', -1);

        $this->artisan("vocabulary:cleanup-tokens --user_id={$user->id} --language=english --dry-run")
            ->assertExitCode(0);

        $this->assertSame(-1, $word->fresh()->stage);
    }

    public function test_cleanup_marks_invalid_tokens_as_ignored_and_disables_review_cards(): void
    {
        $user = $this->createUser();
        $word = $this->createWord($user->id, '15.2%', -1);
        $normalWord = $this->createWord($user->id, 'charge', -1);

        $card = ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_WORD,
            'target_id' => $word->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);

        $this->artisan("vocabulary:cleanup-tokens --user_id={$user->id} --language=english")
            ->assertExitCode(0);

        $this->assertSame(1, $word->fresh()->stage);
        $this->assertFalse((bool) $card->fresh()->fsrs_enabled);
        $this->assertSame(-1, $normalWord->fresh()->stage);
    }

    private function createUser(): User
    {
        return User::forceCreate([
            'name' => 'Vocabulary User',
            'email' => 'vocab@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    private function createWord(int $userId, string $word, int $stage): EncounteredWord
    {
        return EncounteredWord::forceCreate([
            'user_id' => $userId,
            'language' => 'english',
            'word' => $word,
            'lemma' => '',
            'base_word' => '',
            'reading' => '',
            'kanji' => '',
            'base_word_reading' => '',
            'stage' => $stage,
            'translation' => '',
        ]);
    }
}
