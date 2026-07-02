<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\WordSenseKnownSenseService;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Data-layer fixture tests for the morphology matrix lemma bridge.
 *
 * This test class intentionally uses a hand-crafted processed_text fixture
 * (gzcompress + json_encode) to lock the lemma-bridge contract at the data
 * layer: surface/lemma/pos preservation, known-sense lookup isolation,
 * read-only behavior, ambiguous-form handling, and FSRS non-modification.
 *
 * It does NOT exercise the real Python tokenizer or ChapterService importer
 * pipeline. Real-tokenizer end-to-end coverage lives in
 * {@see MorphologyMatrixImportRegressionTest}.
 */
class MorphologyMatrixLemmaBridgeDataLayerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private WordSenseKnownSenseService $knownSenseService;
    private WordSenseService $wordSenseService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('morphology-matrix@example.com', 'english');
        $this->otherUser = $this->createUser('morphology-other@example.com', 'english');
        $this->knownSenseService = app(WordSenseKnownSenseService::class);
        $this->wordSenseService = app(WordSenseService::class);
    }

    public function test_imported_article_fixture_preserves_surface_and_lemma_matrix(): void
    {
        $chapter = $this->createMorphologyArticleChapter();
        $tokens = collect($chapter->getProcessedText())->keyBy('w');

        foreach ($this->morphologyMatrix() as $case) {
            $this->assertTrue($tokens->has($case['surface']), $case['category'] . ' surface missing: ' . $case['surface']);
            $token = $tokens->get($case['surface']);

            $this->assertSame($case['surface'], $token->w);
            $this->assertSame($case['lemma'], $token->l);
            $this->assertSame($case['pos'], $token->pos);
        }

        $this->assertSame('The better researchers watched published authors studying used books while mice and children ran.', $chapter->raw_text);
    }

    public function test_morphology_matrix_known_sense_lookup_is_read_only_and_lemma_based(): void
    {
        $this->createMorphologyArticleChapter();
        $reviewLogBefore = ReviewLog::count();
        $reviewCardBefore = ReviewCard::count();
        $wordSenseBefore = WordSense::count();

        $seenLemmas = [];
        foreach ($this->morphologyMatrix() as $case) {
            if (isset($seenLemmas[$case['lemma']])) {
                continue;
            }
            $seenLemmas[$case['lemma']] = true;

            $sense = $this->createConfirmedSense($case['lemma'], $case['surface'], $case['category']);
            $payload = $this->knownSenseService->knownSenseLookupPayload($this->user->id, 'english', $case['lemma']);

            $this->assertTrue($payload['read_only'], $case['lemma'] . ' payload must be read-only');
            $this->assertTrue($payload['has_confirmed_senses'], $case['lemma'] . ' should find confirmed sense');
            $this->assertSame($case['lemma'], $payload['lemma']);
            $this->assertSame($sense->id, $payload['confirmed_senses'][0]['sense_id']);
            $this->assertSame($case['lemma'], $payload['confirmed_senses'][0]['lemma']);
            $this->assertSame($case['surface'], $payload['confirmed_senses'][0]['surface_form']);
        }

        $this->assertSame($reviewLogBefore, ReviewLog::count(), 'known-sense lookup must not write ReviewLog');
        $this->assertSame($reviewCardBefore, ReviewCard::count(), 'known-sense lookup must not create ReviewCard');
        $this->assertGreaterThan($wordSenseBefore, WordSense::count(), 'setup creates WordSense rows before lookup assertions');
    }

    public function test_ambiguous_forms_remain_user_judgment_not_auto_binding_or_review(): void
    {
        $ambiguousCases = [
            ['surface' => 'published', 'lemma' => 'publish', 'pos' => 'ADJ'],
            ['surface' => 'running', 'lemma' => 'run', 'pos' => 'ADJ'],
            ['surface' => 'used', 'lemma' => 'use', 'pos' => 'ADJ'],
            ['surface' => 'broken', 'lemma' => 'break', 'pos' => 'ADJ'],
        ];

        foreach ($ambiguousCases as $case) {
            $reviewLogBefore = ReviewLog::count();
            $reviewCardBefore = ReviewCard::count();
            $wordSenseBefore = WordSense::count();

            $payload = $this->knownSenseService->knownSenseLookupPayload($this->user->id, 'english', $case['lemma']);

            $this->assertSame($case['lemma'], $payload['lemma']);
            $this->assertFalse($payload['has_confirmed_senses']);
            $this->assertSame([], $payload['confirmed_senses']);
            $this->assertFalse($payload['known_sense_new_meaning_hint']);
            $this->assertTrue($payload['read_only']);
            $this->assertSame($reviewLogBefore, ReviewLog::count(), $case['surface'] . ' must not write ReviewLog');
            $this->assertSame($reviewCardBefore, ReviewCard::count(), $case['surface'] . ' must not create ReviewCard');
            $this->assertSame($wordSenseBefore, WordSense::count(), $case['surface'] . ' must not auto-create WordSense');
        }
    }

    public function test_known_sense_lookup_does_not_modify_review_card_fsrs_fields(): void
    {
        $sense = $this->createConfirmedSense('write', 'written', 'past participle');
        $card = ReviewCard::forceCreate([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDays(3),
            'fsrs_stability' => 2.5,
            'fsrs_difficulty' => 4.25,
            'fsrs_reps' => 7,
            'fsrs_lapses' => 1,
            'fsrs_last_reviewed_at' => now()->subDay(),
        ]);
        $fsrsFields = [
            'fsrs_enabled',
            'fsrs_state',
            'fsrs_due_at',
            'fsrs_stability',
            'fsrs_difficulty',
            'fsrs_reps',
            'fsrs_lapses',
            'fsrs_last_reviewed_at',
        ];
        $before = $this->cardFsrsSnapshot($card, $fsrsFields);

        $payload = $this->knownSenseService->knownSenseLookupPayload($this->user->id, 'english', 'write');

        $this->assertTrue($payload['read_only']);
        $this->assertSame($before, $this->cardFsrsSnapshot($card->fresh(), $fsrsFields));
    }

    public function test_known_sense_lookup_keeps_user_language_and_status_isolation_for_matrix_lemma(): void
    {
        $confirmed = $this->createConfirmedSense('mouse', 'mice', 'current user confirmed');

        $otherUserSense = $this->createSenseForUser($this->otherUser, 'english', 'mouse', 'mice', WordSense::STATUS_CONFIRMED);
        $rejected = $this->createSenseForUser($this->user, 'english', 'mouse', 'mice', WordSense::STATUS_REJECTED);
        $aiSuggested = $this->createSenseForUser($this->user, 'english', 'mouse', 'mice', WordSense::STATUS_AI_SUGGESTED);
        $otherLanguage = $this->createSenseForUser($this->user, 'japanese', 'mouse', 'mice', WordSense::STATUS_CONFIRMED);

        $payload = $this->knownSenseService->knownSenseLookupPayload($this->user->id, 'english', 'mouse');

        $this->assertSame([$confirmed->id], array_column($payload['confirmed_senses'], 'sense_id'));
        $this->assertNotContains($otherUserSense->id, array_column($payload['confirmed_senses'], 'sense_id'));
        $this->assertNotContains($rejected->id, array_column($payload['confirmed_senses'], 'sense_id'));
        $this->assertNotContains($aiSuggested->id, array_column($payload['confirmed_senses'], 'sense_id'));
        $this->assertNotContains($otherLanguage->id, array_column($payload['confirmed_senses'], 'sense_id'));
    }

    private function createMorphologyArticleChapter(): Chapter
    {
        $book = Book::forceCreate([
            'user_id' => $this->user->id,
            'name' => 'Morphology Matrix Fixture',
            'language' => 'english',
        ]);

        $tokens = array_map(function (array $case, int $index) {
            return (object) [
                'w' => $case['surface'],
                'l' => $case['lemma'],
                'r' => '',
                'lr' => '',
                'pos' => $case['pos'],
                'si' => intdiv($index, 4),
                'g' => [],
            ];
        }, $this->morphologyMatrix(), array_keys($this->morphologyMatrix()));

        return Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'name' => 'Morphology Matrix Article',
            'language' => 'english',
            'raw_text' => 'The better researchers watched published authors studying used books while mice and children ran.',
            'word_count' => count($tokens),
            'read_count' => 0,
            'unique_words' => json_encode(array_values(array_unique(array_map(fn ($case) => $case['lemma'], $this->morphologyMatrix())))),
            'unique_word_ids' => '[]',
            'processed_text' => gzcompress(json_encode($tokens), 1),
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
        ]);
    }

    /**
     * @return list<array{category: string, surface: string, lemma: string, pos: string}>
     */
    private function morphologyMatrix(): array
    {
        return [
            ['category' => 'regular plural', 'surface' => 'ways', 'lemma' => 'way', 'pos' => 'NOUN'],
            ['category' => 'regular plural', 'surface' => 'technologies', 'lemma' => 'technology', 'pos' => 'NOUN'],
            ['category' => 'irregular plural', 'surface' => 'mice', 'lemma' => 'mouse', 'pos' => 'NOUN'],
            ['category' => 'irregular plural', 'surface' => 'children', 'lemma' => 'child', 'pos' => 'NOUN'],
            ['category' => 'third person singular', 'surface' => 'studies', 'lemma' => 'study', 'pos' => 'VERB'],
            ['category' => 'third person singular', 'surface' => 'watches', 'lemma' => 'watch', 'pos' => 'VERB'],
            ['category' => 'past tense', 'surface' => 'ran', 'lemma' => 'run', 'pos' => 'VERB'],
            ['category' => 'past tense', 'surface' => 'went', 'lemma' => 'go', 'pos' => 'VERB'],
            ['category' => 'past participle', 'surface' => 'written', 'lemma' => 'write', 'pos' => 'VERB'],
            ['category' => 'past participle', 'surface' => 'published', 'lemma' => 'publish', 'pos' => 'VERB'],
            ['category' => 'progressive', 'surface' => 'running', 'lemma' => 'run', 'pos' => 'VERB'],
            ['category' => 'progressive', 'surface' => 'studying', 'lemma' => 'study', 'pos' => 'VERB'],
            ['category' => 'comparative/superlative', 'surface' => 'better', 'lemma' => 'good', 'pos' => 'ADJ'],
            ['category' => 'comparative/superlative', 'surface' => 'worse', 'lemma' => 'bad', 'pos' => 'ADJ'],
            ['category' => 'adjectival ambiguity', 'surface' => 'used', 'lemma' => 'use', 'pos' => 'ADJ'],
            ['category' => 'adjectival ambiguity', 'surface' => 'broken', 'lemma' => 'break', 'pos' => 'ADJ'],
        ];
    }

    private function createConfirmedSense(string $lemma, string $surfaceForm, string $senseZh): WordSense
    {
        return $this->createSenseForUser($this->user, 'english', $lemma, $surfaceForm, WordSense::STATUS_CONFIRMED, $senseZh);
    }

    private function createSenseForUser(User $user, string $language, string $lemma, string $surfaceForm, string $status, string $senseZh = 'matrix sense'): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $user->id,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $surfaceForm,
            'pos' => 'noun',
            'sense_zh' => $senseZh,
            'sense_en' => '',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => '',
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => $status]);

        return $sense->fresh();
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

    /**
     * @param list<string> $fields
     */
    private function cardFsrsSnapshot(ReviewCard $card, array $fields): array
    {
        $snapshot = $card->only($fields);
        foreach (['fsrs_due_at', 'fsrs_last_reviewed_at'] as $field) {
            if (isset($snapshot[$field]) && $snapshot[$field] instanceof \DateTimeInterface) {
                $snapshot[$field] = $snapshot[$field]->format('Y-m-d H:i:s');
            }
        }

        return $snapshot;
    }
}
