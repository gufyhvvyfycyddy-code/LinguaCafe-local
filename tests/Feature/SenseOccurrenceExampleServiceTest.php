<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseOccurrenceExampleService;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SenseOccurrenceExampleServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private WordSenseService $wordSenseService;
    private SenseOccurrenceExampleService $exampleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('examples@example.com', 'english');
        $this->otherUser = $this->createUser('other-examples@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->exampleService = app(SenseOccurrenceExampleService::class);
    }

    public function test_returns_examples_for_owned_sense(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'apple');
        $this->createOccurrence($this->user->id, 'english', $sense->id, 'I like apples.', null);

        $result = $this->exampleService->getExamples($this->user->id, 'english', $sense->id);

        $this->assertSame($sense->id, $result['sense_id']);
        $this->assertSame('apple', $result['lemma']);
        $this->assertCount(1, $result['occurrences']);
    }

    public function test_payload_shape(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'banana');
        $occ = $this->createOccurrence($this->user->id, 'english', $sense->id, 'A banana is yellow.', 'A yellow banana');

        $result = $this->exampleService->getExamples($this->user->id, 'english', $sense->id);

        $this->assertArrayHasKey('sense_id', $result);
        $this->assertArrayHasKey('lemma', $result);
        $this->assertArrayHasKey('occurrences', $result);

        $occurrences = $result['occurrences'];
        $this->assertIsArray($occurrences);
        $this->assertCount(1, $occurrences);
        $o = $occurrences[0];
        $this->assertArrayHasKey('occurrence_id', $o);
        $this->assertArrayHasKey('sentence_en', $o);
        $this->assertArrayHasKey('sentence_zh', $o);
        $this->assertArrayHasKey('surface', $o);
        $this->assertArrayHasKey('chapter_id', $o);
        $this->assertArrayHasKey('status', $o);
        $this->assertArrayHasKey('created_at', $o);

        $this->assertSame('A banana is yellow.', $o['sentence_en']);
        $this->assertSame('A yellow banana', $o['sentence_zh']);
    }

    public function test_isolates_by_user(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'cherry');
        $this->createOccurrence($this->user->id, 'english', $sense->id, 'Cherry pie.', null);

        $otherSense = $this->createSense($this->otherUser->id, 'english', 'cherry');
        $this->createOccurrence($this->otherUser->id, 'english', $otherSense->id, 'Other cherry.', null);

        $result = $this->exampleService->getExamples($this->user->id, 'english', $sense->id);
        $this->assertCount(1, $result['occurrences']);
        $this->assertSame('Cherry pie.', $result['occurrences'][0]['sentence_en']);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->exampleService->getExamples($this->user->id, 'english', $otherSense->id);
    }

    public function test_isolates_by_language(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'dog');
        $this->createOccurrence($this->user->id, 'english', $sense->id, 'The dog runs.', null);

        $chineseSense = $this->createSense($this->user->id, 'chinese', 'gou');
        $this->createOccurrence($this->user->id, 'chinese', $chineseSense->id, 'The small dog runs.', null);

        $result = $this->exampleService->getExamples($this->user->id, 'english', $sense->id);
        $this->assertCount(1, $result['occurrences']);
        $this->assertSame('The dog runs.', $result['occurrences'][0]['sentence_en']);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->exampleService->getExamples($this->user->id, 'english', $chineseSense->id);
    }

    public function test_excludes_empty_sentence_en(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'egg');
        $this->createOccurrence($this->user->id, 'english', $sense->id, 'Egg on toast.', null);
        $this->createOccurrence($this->user->id, 'english', $sense->id, '', null);

        $result = $this->exampleService->getExamples($this->user->id, 'english', $sense->id);

        $this->assertCount(1, $result['occurrences']);
        $this->assertSame('Egg on toast.', $result['occurrences'][0]['sentence_en']);
    }

    public function test_returns_empty_occurrences_when_no_sentences(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'fig');

        $result = $this->exampleService->getExamples($this->user->id, 'english', $sense->id);

        $this->assertSame($sense->id, $result['sense_id']);
        $this->assertSame('fig', $result['lemma']);
        $this->assertCount(0, $result['occurrences']);
    }

    public function test_returns_ordered_by_created_at_desc(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'grape');

        $old = $this->createOccurrence($this->user->id, 'english', $sense->id, 'Old grape.', null, now()->subDays(5));
        $mid = $this->createOccurrence($this->user->id, 'english', $sense->id, 'Mid grape.', null, now()->subDays(2));
        $new = $this->createOccurrence($this->user->id, 'english', $sense->id, 'New grape.', null, now());

        $result = $this->exampleService->getExamples($this->user->id, 'english', $sense->id);

        $this->assertCount(3, $result['occurrences']);
        $this->assertSame('New grape.', $result['occurrences'][0]['sentence_en']);
        $this->assertSame('Mid grape.', $result['occurrences'][1]['sentence_en']);
        $this->assertSame('Old grape.', $result['occurrences'][2]['sentence_en']);
    }

    public function test_limits_to_20_occurrences(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'honey');

        for ($i = 0; $i < 25; $i++) {
            $this->createOccurrence($this->user->id, 'english', $sense->id, "Honey sentence {$i}.", null);
        }

        $result = $this->exampleService->getExamples($this->user->id, 'english', $sense->id);

        $this->assertCount(20, $result['occurrences']);
    }

    public function test_endpoint_returns_expected_json(): void
    {
        $sense = $this->createSense($this->user->id, 'english', 'kiwi');
        $this->createOccurrence($this->user->id, 'english', $sense->id, 'Kiwi fruit.', 'kiwi');

        $this->actingAs($this->user)
            ->get("/senses/{$sense->id}/examples")
            ->assertOk()
            ->assertJson([
                'sense_id' => $sense->id,
                'lemma' => 'kiwi',
                'occurrences' => [
                    [
                        'sentence_en' => 'Kiwi fruit.',
                        'sentence_zh' => 'kiwi',
                    ],
                ],
            ]);
    }

    public function test_endpoint_isolates_sense_by_user(): void
    {
        $sense = $this->createSense($this->otherUser->id, 'english', 'lemon');
        $this->createOccurrence($this->otherUser->id, 'english', $sense->id, 'Sour lemon.', null);

        $this->actingAs($this->user)
            ->get("/senses/{$sense->id}/examples")
            ->assertNotFound();
    }

    public function test_endpoint_isolates_sense_by_language(): void
    {
        $otherLangUser = $this->createUser('chinese-examples@example.com', 'chinese');
        $sense = $this->createSense($otherLangUser->id, 'chinese', 'mango');
        $this->createOccurrence($otherLangUser->id, 'chinese', $sense->id, 'Mango is sweet.', null);

        $this->actingAs($this->user)
            ->get("/senses/{$sense->id}/examples")
            ->assertNotFound();
    }

    // ==================== Helpers ====================

    private function createSense(int $userId, string $language, string $lemma): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'noun',
            'sense_zh' => $lemma,
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);
        return $sense->fresh();
    }

    private function createOccurrence(int $userId, string $language, int $senseId, ?string $sentenceEn, ?string $sentenceZh, $createdAt = null): WordSenseOccurrence
    {
        $data = [
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'word_sense_id' => $senseId,
            'sentence_id' => (string) Str::uuid(),
            'sentence_en' => $sentenceEn,
            'sentence_zh' => $sentenceZh,
            'surface' => 'test',
            'lemma' => 'test',
            'decision' => 'match_existing_sense',
            'status' => WordSenseOccurrence::STATUS_BOUND,
        ];

        if ($createdAt !== null) {
            $data['created_at'] = $createdAt;
        }

        return WordSenseOccurrence::forceCreate($data);
    }

    private function createUser(string $email, string $language): User
    {
        return User::forceCreate([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }
}
