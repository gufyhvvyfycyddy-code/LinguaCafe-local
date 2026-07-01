<?php

namespace Tests\Feature;

use App\Models\Phrase;
use App\Models\User;
use App\Services\TextBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TextBlockPhraseIndexingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Phrase Index User',
            'email' => '__VG_EMAIL_tbs_phrase_1__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->otherUser = User::forceCreate([
            'name' => 'Other Phrase Index User',
            'email' => '__VG_EMAIL_tbs_phrase_2__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    private function makeService(?User $user = null, string $language = 'english'): TextBlockService
    {
        $u = $user ?? $this->user;
        return new TextBlockService($u->id, $language);
    }

    private function makeWord(string $word): \stdClass
    {
        $obj = new \stdClass();
        $obj->user_id = $this->user->id;
        $obj->word_index = 0;
        $obj->sentence_index = 0;
        $obj->word = $word;
        $obj->lemma = mb_strtolower($word, 'UTF-8');
        $obj->reading = '';
        $obj->lemma_reading = '';
        $obj->pos = 'NOUN';
        $obj->is_structure = $word === 'NEWLINE';
        $obj->phrase_ids = [];

        return $obj;
    }

    private function makeReaderWord(string $word, array $phraseIds): \stdClass
    {
        $obj = $this->makeWord($word);
        $obj->phrase_ids = $phraseIds;
        $obj->phraseIndexes = [];

        return $obj;
    }

    private function createPhrase(array $words, ?User $user = null, string $language = 'english'): Phrase
    {
        $u = $user ?? $this->user;

        return Phrase::forceCreate([
            'user_id' => $u->id,
            'language' => $language,
            'words' => json_encode($words),
            'words_searchable' => implode('', $words),
            'reading' => '',
            'translation' => 'test phrase',
            'stage' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_update_phrase_ids_marks_exact_phrase_occurrence(): void
    {
        $phrase = $this->createPhrase(['quick', 'brown']);
        $service = $this->makeService();
        $service->uniqueWords = ['quick', 'brown', 'fox'];
        $service->setProcessedWords([
            $this->makeWord('Quick'),
            $this->makeWord('brown'),
            $this->makeWord('fox'),
        ]);

        $changed = $service->updatePhraseIds($phrase);

        $this->assertTrue($changed);
        $this->assertSame([$phrase->id], $service->processedWords[0]->phrase_ids);
        $this->assertSame([$phrase->id], $service->processedWords[1]->phrase_ids);
        $this->assertSame([], $service->processedWords[2]->phrase_ids);
    }

    public function test_update_phrase_ids_allows_newline_inside_phrase_occurrence(): void
    {
        $phrase = $this->createPhrase(['quick', 'brown']);
        $service = $this->makeService();
        $service->uniqueWords = ['quick', 'brown'];
        $service->setProcessedWords([
            $this->makeWord('quick'),
            $this->makeWord('NEWLINE'),
            $this->makeWord('brown'),
        ]);

        $changed = $service->updatePhraseIds($phrase);

        $this->assertTrue($changed);
        $this->assertSame([$phrase->id], $service->processedWords[0]->phrase_ids);
        $this->assertSame([], $service->processedWords[1]->phrase_ids);
        $this->assertSame([$phrase->id], $service->processedWords[2]->phrase_ids);
    }

    public function test_update_phrase_ids_returns_false_when_phrase_word_is_missing(): void
    {
        $phrase = $this->createPhrase(['quick', 'brown']);
        $service = $this->makeService();
        $service->uniqueWords = ['quick', 'fox'];
        $service->setProcessedWords([
            $this->makeWord('quick'),
            $this->makeWord('fox'),
        ]);

        $changed = $service->updatePhraseIds($phrase);

        $this->assertFalse($changed);
        $this->assertSame([], $service->processedWords[0]->phrase_ids);
        $this->assertSame([], $service->processedWords[1]->phrase_ids);
    }

    public function test_index_phrases_maps_phrase_ids_to_sorted_phrase_indexes(): void
    {
        $firstPhrase = $this->createPhrase(['alpha']);
        $secondPhrase = $this->createPhrase(['beta']);
        $service = $this->makeService();
        $service->words = [
            $this->makeReaderWord('alpha', [$secondPhrase->id, $firstPhrase->id]),
            $this->makeReaderWord('beta', [$secondPhrase->id]),
        ];

        $service->indexPhrases();

        $this->assertCount(2, $service->phrases);
        $this->assertSame([$firstPhrase->id, $secondPhrase->id], $service->phrases->pluck('id')->all());
        $this->assertSame([1, 0], $service->words[0]->phraseIndexes);
        $this->assertSame([1], $service->words[1]->phraseIndexes);
    }

    public function test_index_phrases_ignores_phrase_ids_outside_user_and_language_scope(): void
    {
        $ownPhrase = $this->createPhrase(['alpha']);
        $otherUserPhrase = $this->createPhrase(['alpha'], $this->otherUser);
        $otherLanguagePhrase = $this->createPhrase(['alpha'], $this->user, 'german');
        $service = $this->makeService();
        $service->words = [
            $this->makeReaderWord('alpha', [
                $ownPhrase->id,
                $otherUserPhrase->id,
                $otherLanguagePhrase->id,
            ]),
        ];

        $service->indexPhrases();

        $this->assertCount(1, $service->phrases);
        $this->assertSame([$ownPhrase->id], $service->phrases->pluck('id')->all());
        $this->assertSame([0], $service->words[0]->phraseIndexes);
    }
}
