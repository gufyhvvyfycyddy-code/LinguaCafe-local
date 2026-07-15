<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\GoalAchievement;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\GoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualWordSensePosCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'POS Contract Test',
            'email' => 'pos-contract@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        app(GoalService::class)->createGoalsForLanguage($this->user->id, 'english');
    }

    public function test_manual_word_sense_create_normalizes_known_pos_aliases(): void
    {
        $aliases = [
            'adj' => 'adjective',
            'n' => 'noun',
            'v' => 'verb',
            'adv' => 'adverb',
            'prep' => 'preposition',
            'conj' => 'conjunction',
        ];

        foreach ($aliases as $alias => $canonical) {
            $response = $this->postManualSense("alias-{$alias}", $alias);

            $response->assertOk();
            $this->assertSame($canonical, WordSense::findOrFail($response->json('sense_id'))->pos);
        }

        $this->assertSame(count($aliases), ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count());
        $this->assertSame(0, ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->count());
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_manual_word_sense_create_preserves_all_canonical_pos_values(): void
    {
        $canonicalValues = ['noun', 'verb', 'adjective', 'adverb', 'preposition', 'conjunction', 'phrase', 'other'];

        foreach ($canonicalValues as $pos) {
            $response = $this->postManualSense("canonical-{$pos}", $pos);

            $response->assertOk();
            $this->assertSame($pos, WordSense::findOrFail($response->json('sense_id'))->pos);
        }
    }

    public function test_manual_word_sense_update_uses_the_same_pos_alias_contract(): void
    {
        $created = $this->postManualSense('editable-pos', 'noun')->assertOk();
        $senseId = $created->json('sense_id');
        $cardId = WordSense::findOrFail($senseId)->reviewCard->id;

        $this->actingAs($this->user)->putJson("/senses/{$senseId}/manual", [
            'pos' => 'adv',
            'sense_zh' => '更新后的释义',
        ])->assertOk();

        $sense = WordSense::findOrFail($senseId);
        $this->assertSame('adverb', $sense->pos);
        $this->assertSame($cardId, $sense->reviewCard->id);
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_manual_word_sense_unknown_pos_returns_422_without_learning_side_effects(): void
    {
        $word = $this->word('unknown-pos');
        $before = $word->only(['stage', 'next_review', 'added_to_srs', 'relearning']);

        $this->postManualSense($word->word, 'mystery-pos', [
            'encountered_word_id' => $word->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['pos']);

        $this->assertSame(0, WordSense::count());
        $this->assertSame(0, ReviewCard::count());
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, GoalAchievement::count());
        $this->assertEquals($before, $word->fresh()->only(array_keys($before)));
    }

    public function test_manual_word_sense_unknown_pos_update_keeps_existing_sense_and_card(): void
    {
        $created = $this->postManualSense('unchanged-pos', 'verb')->assertOk();
        $sense = WordSense::findOrFail($created->json('sense_id'));
        $card = $sense->reviewCard;

        $this->actingAs($this->user)->putJson("/senses/{$sense->id}/manual", [
            'pos' => 'mystery-pos',
            'sense_zh' => '不得写入',
        ])->assertUnprocessable()->assertJsonValidationErrors(['pos']);

        $sense->refresh();
        $this->assertSame('verb', $sense->pos);
        $this->assertSame('测试释义', $sense->sense_zh);
        $this->assertSame($card->id, $sense->reviewCard->id);
        $this->assertSame(0, ReviewLog::count());
    }

    private function postManualSense(string $lemma, string $pos, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/senses/manual', array_merge([
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => $pos,
            'sense_zh' => '测试释义',
        ], $overrides));
    }

    private function word(string $word): EncounteredWord
    {
        return EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'stage' => 2,
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
        ]);
    }
}
