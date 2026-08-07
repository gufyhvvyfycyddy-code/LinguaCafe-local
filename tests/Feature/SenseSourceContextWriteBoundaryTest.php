<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseSourceContextService;
use App\Services\WordSenseService;
use ArrayObject;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Locks the source-context read boundary.
 *
 * Both public source-context routes are GET/HEAD projections. Recovery may
 * locate a chapter and return the recovered location, but observing that
 * projection must never persist WordSense, occurrence, ReviewCard, ReviewLog,
 * or FSRS state.
 */
class SenseSourceContextWriteBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\App\Models\Setting::where('name', 'reviewIntervals')->exists()) {
            \App\Models\Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3],
                    '-3' => [7], '-2' => [15], '-1' => [30],
                ]),
            ]);
        }

        $this->user = User::forceCreate([
            'name' => 'source-boundary@example.com',
            'email' => 'source-boundary@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $this->wordSenseService = app(WordSenseService::class);
    }

    public function test_source_context_service_public_defaults_are_read_only(): void
    {
        $single = new ReflectionMethod(SenseSourceContextService::class, 'sourceContext');
        $list = new ReflectionMethod(SenseSourceContextService::class, 'sourceContextList');

        $this->assertFalse($single->getParameters()[3]->getDefaultValue());
        $this->assertFalse($list->getParameters()[4]->getDefaultValue());
    }

    public function test_source_context_get_exact_recovery_returns_preview_without_writeback(): void
    {
        $sense = $this->createConfirmedSense();
        $occurrence = $this->createExampleOccurrence($sense);
        $chapter = $this->createChapterForSentence('The bureau opened at noon.');
        $mutations = $this->captureSourceBusinessMutations();

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $this->assertSame('chapter_recovered', $response->json('source_kind'));
        $this->assertSame($chapter->id, $response->json('chapter_id'));
        $this->assertSame('0', (string) $response->json('sentence_id'));
        $this->assertNotEmpty($response->json('context_tokens'));
        $this->assertSourceLocationUnchanged($sense, $occurrence);
        $this->assertSame([], $mutations->getArrayCopy(), 'GET source-context must issue zero business-table mutations.');
    }

    public function test_source_context_get_title_recovery_is_read_only(): void
    {
        $title = 'The Best Retailers Combine Bricks and Clicks';
        $sense = $this->createConfirmedSense([
            'lemma' => 'brick',
            'surface_form' => 'Bricks',
            'example_sentence_en' => $title,
        ]);
        $occurrence = $this->createExampleOccurrence($sense, $title);
        $chapter = $this->createChapterForSentence('Unrelated body text.', $title);
        $mutations = $this->captureSourceBusinessMutations();

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $this->assertSame('chapter_title', $response->json('source_kind'));
        $this->assertSame($chapter->id, $response->json('chapter_id'));
        $this->assertSourceLocationUnchanged($sense, $occurrence);
        $this->assertSame([], $mutations->getArrayCopy(), 'Title recovery through GET must not persist its preview.');
    }

    public function test_source_context_get_fuzzy_recovery_is_read_only(): void
    {
        $example = "Walmart's non-store sales rose sharply as online retail continued to grow.";
        $sense = $this->createConfirmedSense([
            'lemma' => 'walmart',
            'surface_form' => 'Walmart',
            'example_sentence_en' => $example,
        ]);
        $occurrence = $this->createExampleOccurrence($sense, $example);
        $chapter = $this->createChapterFromTokens([
            'Walmart', "'s", 'non', '---', 'store', 'sales', 'rose', 'sharply',
            'as', 'online', 'retail', 'continued', 'to', 'grow', '.',
        ], 'Fuzzy Recovery Chapter');
        $mutations = $this->captureSourceBusinessMutations();

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $this->assertSame('chapter_fuzzy', $response->json('source_kind'));
        $this->assertSame($chapter->id, $response->json('chapter_id'));
        $this->assertSourceLocationUnchanged($sense, $occurrence);
        $this->assertSame([], $mutations->getArrayCopy(), 'Fuzzy recovery through GET must not persist its preview.');
    }

    public function test_source_context_list_default_fallback_recovers_without_writeback(): void
    {
        $sense = $this->createConfirmedSense();
        $occurrence = $this->createExampleOccurrence($sense);
        $chapter = $this->createChapterForSentence('The bureau opened at noon.');
        $mutations = $this->captureSourceBusinessMutations();

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list');

        $response->assertOk();
        $this->assertSame(1, $response->json('count'));
        $this->assertSame('chapter_recovered', $response->json('sources.0.source_kind'));
        $this->assertSame($chapter->id, $response->json('sources.0.chapter_id'));
        $this->assertSourceLocationUnchanged($sense, $occurrence);
        $this->assertSame([], $mutations->getArrayCopy(), 'Default list fallback must be read-only without a query flag.');
    }

    public function test_read_only_zero_query_cannot_enable_source_context_list_writeback(): void
    {
        $sense = $this->createConfirmedSense();
        $occurrence = $this->createExampleOccurrence($sense);
        $chapter = $this->createChapterForSentence('The bureau opened at noon.');
        $mutations = $this->captureSourceBusinessMutations();

        $response = $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?read_only=0');

        $response->assertOk();
        $this->assertSame('chapter_recovered', $response->json('sources.0.source_kind'));
        $this->assertSame($chapter->id, $response->json('sources.0.chapter_id'));
        $this->assertSourceLocationUnchanged($sense, $occurrence);
        $this->assertSame([], $mutations->getArrayCopy(), 'A GET query string must never opt into persistence.');
    }

    public function test_source_context_head_is_read_only(): void
    {
        $sense = $this->createConfirmedSense();
        $occurrence = $this->createExampleOccurrence($sense);
        $this->createChapterForSentence('The bureau opened at noon.');
        $mutations = $this->captureSourceBusinessMutations();

        $response = $this->actingAs($this->user)
            ->call('HEAD', '/senses/' . $sense->id . '/source-context');

        $response->assertOk();
        $this->assertSourceLocationUnchanged($sense, $occurrence);
        $this->assertSame([], $mutations->getArrayCopy(), 'HEAD must not inherit a hidden source-location write.');
    }

    public function test_direct_chapter_and_unavailable_projections_remain_read_only(): void
    {
        $sense = $this->createConfirmedSense();
        $chapter = $this->createChapterForSentence('The bureau opened at noon.');
        $occurrence = WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => '0',
            'sentence_en' => 'The bureau opened at noon.',
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'bureau',
            'lemma' => 'bureau',
            'pos' => 'noun',
            'decision' => 'match_existing_sense',
            'confidence' => 1.0,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ]);
        $mutations = $this->captureSourceBusinessMutations();

        $this->actingAs($this->user)
            ->get('/senses/' . $sense->id . '/source-context-list?preferred_occurrence_id=' . $occurrence->id)
            ->assertOk()
            ->assertJsonPath('preferred_occurrence_status', 'matched');

        $this->assertNull($sense->fresh()->source_chapter_id);
        $this->assertSame([], $mutations->getArrayCopy());

        $unavailable = $this->createConfirmedSense([
            'lemma' => 'unavailable',
            'surface_form' => 'unavailable',
            'example_sentence_en' => null,
        ]);
        $this->actingAs($this->user)
            ->get('/senses/' . $unavailable->id . '/source-context')
            ->assertOk()
            ->assertJsonPath('source_available', false);
        $this->assertNull($unavailable->fresh()->source_chapter_id);
    }

    private function captureSourceBusinessMutations(): ArrayObject
    {
        $mutations = new ArrayObject();

        DB::listen(function (QueryExecuted $query) use ($mutations): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)
                && preg_match('/\b(word_senses|word_sense_occurrences|review_cards|review_logs)\b/i', $query->sql)) {
                $mutations->append($query->sql);
            }
        });

        return $mutations;
    }

    private function assertSourceLocationUnchanged(WordSense $sense, WordSenseOccurrence $occurrence): void
    {
        $sense->refresh();
        $occurrence->refresh();

        $this->assertNull($sense->source_chapter_id);
        $this->assertNull($sense->sentence_id);
        $this->assertNull($occurrence->chapter_id);
        $this->assertSame('unresolved', (string) $occurrence->sentence_id);
        $this->assertSame(0, ReviewLog::count());
        $this->assertSame(0, ReviewCard::count());
    }

    private function createConfirmedSense(array $overrides = []): WordSense
    {
        $sense = $this->wordSenseService->createSense(array_merge([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'bureau',
            'surface_form' => 'bureau',
            'pos' => 'noun',
            'sense_zh' => '局',
            'sense_en' => 'an office',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'The bureau opened at noon.',
            'example_sentence_zh' => '',
        ], $overrides));
        $sense->update([
            'status' => WordSense::STATUS_CONFIRMED,
            'source_chapter_id' => null,
            'sentence_id' => null,
        ]);

        return $sense->fresh();
    }

    private function createExampleOccurrence(WordSense $sense, ?string $sentence = null): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'chapter_id' => null,
            'sentence_id' => 'unresolved',
            'sentence_en' => $sentence ?? $sense->example_sentence_en,
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->surface_form,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'decision' => 'match_existing_sense',
            'confidence' => 1.0,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ]);
    }

    private function createChapterForSentence(string $sentence, string $name = 'Recovery Chapter'): Chapter
    {
        preg_match_all('/[A-Za-z]+|[.!?]/', $sentence, $matches);

        return $this->createChapterFromTokens($matches[0] ?? [], $name);
    }

    private function createChapterFromTokens(array $tokens, string $name): Chapter
    {
        $words = [];
        foreach ($tokens as $index => $token) {
            $words[] = (object) [
                'word' => $token,
                'sentence_index' => '0',
                'spaceAfter' => !in_array($token, ['.', '!', '?'], true) && $index < count($tokens) - 1,
            ];
        }

        return Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 1,
            'name' => $name,
            'read_count' => 0,
            'word_count' => count($words),
            'language' => 'english',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'raw_text' => implode(' ', $tokens),
            'type' => 'text',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
            'processed_text' => gzcompress(json_encode($words), 1),
        ]);
    }
}
