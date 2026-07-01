<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserStudyBaseRule;
use App\Services\TextBlockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TextBlockCreateNewEncounteredWordsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Encountered Word User',
            'email' => '__VG_EMAIL_tbs_cnew_1__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other Encountered Word User',
            'email' => '__VG_EMAIL_tbs_cnew_2__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  Helper: build processedWord stdClass
    // ════════════════════════════════════════════════════════════════

    private function makeWord(string $word, string $lemma, string $reading = '', string $lemmaReading = ''): \stdClass
    {
        $obj = new \stdClass();
        $obj->word = $word;
        $obj->lemma = $lemma;
        $obj->reading = $reading;
        $obj->lemma_reading = $lemmaReading;
        $obj->phrase_ids = [];
        return $obj;
    }

    private function makeService(?User $user = null, string $language = 'english'): TextBlockService
    {
        $u = $user ?? $this->user;
        $service = new TextBlockService($u->id, $language);
        return $service;
    }

    // ════════════════════════════════════════════════════════════════
    //  A. creates encountered words for new English processed words
    // ════════════════════════════════════════════════════════════════

    public function test_creates_encountered_words_for_new_english_words(): void
    {
        $service = $this->makeService();
        $service->setProcessedWords([
            $this->makeWord('Geese', 'goose'),
        ]);
        $service->uniqueWords = ['geese'];

        $service->createNewEncounteredWords();

        $this->assertDatabaseHas('encountered_words', [
            'user_id' => $this->user->id,
            'language' => 'english',
            'word' => 'geese',
            'lemma' => 'goose',
            'base_word' => 'goose',
            'study_base' => 'goose',
            'stage' => 2,
            'translation' => '',
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  B. does not duplicate existing encountered word
    // ════════════════════════════════════════════════════════════════

    public function test_does_not_duplicate_existing_encountered_word(): void
    {
        // Pre-insert an encountered word
        \DB::table('encountered_words')->insert([
            'user_id' => $this->user->id,
            'language' => 'english',
            'word' => 'geese',
            'lemma' => 'goose',
            'base_word' => 'goose',
            'study_base' => 'goose',
            'reading' => '',
            'kanji' => '',
            'base_word_reading' => '',
            'stage' => 2,
            'translation' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = $this->makeService();
        $service->setProcessedWords([
            $this->makeWord('Geese', 'goose'),
        ]);
        $service->uniqueWords = ['geese'];

        $service->createNewEncounteredWords();

        $this->assertDatabaseCount('encountered_words', 1);
    }

    // ════════════════════════════════════════════════════════════════
    //  C. user/language isolation
    // ════════════════════════════════════════════════════════════════

    public function test_user_language_isolation(): void
    {
        // Other user already has 'geese'
        \DB::table('encountered_words')->insert([
            'user_id' => $this->otherUser->id,
            'language' => 'english',
            'word' => 'geese',
            'lemma' => 'goose',
            'base_word' => 'goose',
            'study_base' => 'goose',
            'reading' => '',
            'kanji' => '',
            'base_word_reading' => '',
            'stage' => 2,
            'translation' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = $this->makeService();
        $service->setProcessedWords([
            $this->makeWord('Geese', 'goose'),
        ]);
        $service->uniqueWords = ['geese'];

        $service->createNewEncounteredWords();

        $this->assertDatabaseHas('encountered_words', [
            'user_id' => $this->user->id,
            'language' => 'english',
            'word' => 'geese',
        ]);

        // Other user still has exactly one record
        $this->assertEquals(
            1,
            \DB::table('encountered_words')->where('user_id', $this->otherUser->id)->where('word', 'geese')->count()
        );
    }

    // ════════════════════════════════════════════════════════════════
    //  D. applies UserStudyBaseRule
    // ════════════════════════════════════════════════════════════════

    public function test_applies_user_study_base_rule(): void
    {
        UserStudyBaseRule::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'surface' => 'geese',
            'study_base' => 'goose_custom',
        ]);

        $service = $this->makeService();
        $service->setProcessedWords([
            $this->makeWord('Geese', 'goose'),
        ]);
        $service->uniqueWords = ['geese'];

        $service->createNewEncounteredWords();

        $this->assertDatabaseHas('encountered_words', [
            'user_id' => $this->user->id,
            'word' => 'geese',
            'study_base' => 'goose_custom',
            'base_word' => 'goose',      // base_word is still grammatical lemma
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  E. skips VocabularyTokenFilter tokens
    // ════════════════════════════════════════════════════════════════

    public function test_skips_vocabulary_token_filter_tokens(): void
    {
        $service = $this->makeService();
        $service->setProcessedWords([
            $this->makeWord('hello', 'hello'),
            $this->makeWord('NEWLINE', 'NEWLINE'),
        ]);
        $service->uniqueWords = ['hello', 'newline'];

        $service->createNewEncounteredWords();

        $this->assertDatabaseHas('encountered_words', [
            'user_id' => $this->user->id,
            'word' => 'hello',
        ]);
        $this->assertDatabaseMissing('encountered_words', [
            'user_id' => $this->user->id,
            'word' => 'newline',
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  F. words_to_skip becomes stage 1 and clears base fields
    // ════════════════════════════════════════════════════════════════

    public function test_words_to_skip_are_filtered_by_vocabulary_token_filter(): void
    {
        // '。' is in config('linguacafe.words_to_skip') but VocabularyTokenFilter::shouldSkip()
        // catches it first (no letter characters). The words_to_skip block at line 434 is
        // effectively a secondary safety net; current config entries are all punctuation
        // that fail shouldSkip. This test locks the real behavior: punctuation is skipped
        // entirely, not inserted with stage=1.
        $service = $this->makeService(null, 'japanese');
        $service->setProcessedWords([
            $this->makeWord('。', '。'),
        ]);
        $service->uniqueWords = ['。'];

        $service->createNewEncounteredWords();

        $this->assertDatabaseMissing('encountered_words', [
            'user_id' => $this->user->id,
            'word' => '。',
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  G. CJK clears base fields when base_word equals word
    // ════════════════════════════════════════════════════════════════

    public function test_cjk_clears_base_fields_when_base_word_equals_word(): void
    {
        $service = $this->makeService(null, 'japanese');
        $service->setProcessedWords([
            $this->makeWord('猫', '猫', 'ねこ', 'ねこ'),
        ]);
        $service->uniqueWords = ['猫'];

        $service->createNewEncounteredWords();

        $this->assertDatabaseHas('encountered_words', [
            'user_id' => $this->user->id,
            'language' => 'japanese',
            'word' => '猫',
            'lemma' => '',
            'base_word' => '',
            'study_base' => '',
            'base_word_reading' => '',
            'reading' => 'ねこ',
        ]);
        // kanji should contain 猫 (since it's a kanji character)
        $row = \DB::table('encountered_words')->where('user_id', $this->user->id)->where('word', '猫')->first();
        $this->assertStringContainsString('猫', $row->kanji ?? '');
    }

    // ════════════════════════════════════════════════════════════════
    //  H. English keeps base_word when lemma equals word
    // ════════════════════════════════════════════════════════════════

    public function test_english_keeps_base_word_when_lemma_equals_word(): void
    {
        $service = $this->makeService();
        $service->setProcessedWords([
            $this->makeWord('series', 'series'),
        ]);
        $service->uniqueWords = ['series'];

        $service->createNewEncounteredWords();

        $this->assertDatabaseHas('encountered_words', [
            'user_id' => $this->user->id,
            'word' => 'series',
            'lemma' => 'series',
            'base_word' => 'series',
            'study_base' => 'series',
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  I. uniqueWords / processedWords: uniqueWords controls lookup,
    //     processedWords controls insert candidates
    // ════════════════════════════════════════════════════════════════

    public function test_unique_words_controls_lookup_processed_words_controls_insert(): void
    {
        // Pre-insert 'existing' so it appears in lookup
        \DB::table('encountered_words')->insert([
            'user_id' => $this->user->id,
            'language' => 'english',
            'word' => 'existing',
            'lemma' => 'existing',
            'base_word' => 'existing',
            'study_base' => 'existing',
            'reading' => '',
            'kanji' => '',
            'base_word_reading' => '',
            'stage' => 2,
            'translation' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // uniqueWords includes 'existing' (so lookup finds it)
        // processedWords has both 'hello' and 'existing'
        $service = $this->makeService();
        $service->setProcessedWords([
            $this->makeWord('Hello', 'hello'),
            $this->makeWord('Existing', 'existing'),
        ]);
        $service->uniqueWords = ['hello', 'existing'];

        $service->createNewEncounteredWords();

        // 'hello' should be inserted (new word)
        $this->assertDatabaseHas('encountered_words', [
            'user_id' => $this->user->id,
            'word' => 'hello',
        ]);
        // 'existing' should NOT be duplicated
        $this->assertEquals(
            1,
            \DB::table('encountered_words')->where('user_id', $this->user->id)->where('word', 'existing')->count()
        );
    }

    // ════════════════════════════════════════════════════════════════
    //  J. lowercases inserted word and lemma
    // ════════════════════════════════════════════════════════════════

    public function test_lowercases_inserted_word_and_lemma(): void
    {
        $service = $this->makeService();
        $service->setProcessedWords([
            $this->makeWord('RUNNING', 'Running'),
        ]);
        $service->uniqueWords = ['running'];

        $service->createNewEncounteredWords();

        $this->assertDatabaseHas('encountered_words', [
            'user_id' => $this->user->id,
            'word' => 'running',
            'lemma' => 'running',
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //  K. max 255 word skip
    // ════════════════════════════════════════════════════════════════

    public function test_word_longer_than_255_chars_throws_query_exception(): void
    {
        $longWord = str_repeat('a', 256);
        $service = $this->makeService();
        $service->setProcessedWords([
            $this->makeWord($longWord, $longWord),
        ]);
        $service->uniqueWords = [mb_strtolower($longWord)];

        $this->expectException(QueryException::class);

        $service->createNewEncounteredWords();
    }

    // ════════════════════════════════════════════════════════════════
    //  L. batch insert multiple words
    // ════════════════════════════════════════════════════════════════

    public function test_batch_inserts_multiple_words(): void
    {
        $service = $this->makeService();
        $service->setProcessedWords([
            $this->makeWord('apple', 'apple'),
            $this->makeWord('banana', 'banana'),
            $this->makeWord('cherry', 'cherry'),
        ]);
        $service->uniqueWords = ['apple', 'banana', 'cherry'];

        $service->createNewEncounteredWords();

        $this->assertDatabaseHas('encountered_words', ['user_id' => $this->user->id, 'word' => 'apple']);
        $this->assertDatabaseHas('encountered_words', ['user_id' => $this->user->id, 'word' => 'banana']);
        $this->assertDatabaseHas('encountered_words', ['user_id' => $this->user->id, 'word' => 'cherry']);
        $this->assertDatabaseCount('encountered_words', 3);
    }
}
