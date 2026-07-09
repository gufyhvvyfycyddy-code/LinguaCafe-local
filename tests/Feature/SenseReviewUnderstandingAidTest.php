<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\SenseReviewCardSerializerService;
use App\Services\WordSenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SenseReviewUnderstandingAidTest
 *
 * GM52-SenseReviewUnderstandingHelper-1000-9
 *
 * Verifies the sense review understanding-aid contract:
 *  - When a WordSense has understanding_aid data, the serializer
 *    includes it in the payload.
 *  - When understanding_aid is null, the payload still includes the
 *    key with a safe empty structure (no KeyError in frontend).
 *  - The understanding_aid block does NOT affect FSRS fields.
 *  - Reading the payload (which exposes understanding_aid) does NOT
 *    write ReviewLog.
 *  - The understanding_aid is sense-level, so it stays the same
 *    regardless of which occurrence is displayed.
 */
class SenseReviewUnderstandingAidTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $wordSenseService;
    private SenseReviewCardSerializerService $serializerService;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Setting::where('name', 'reviewIntervals')->exists()) {
            Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0], '-6' => [1], '-5' => [2], '-4' => [3],
                    '-3' => [7], '-2' => [15], '-1' => [30],
                ]),
            ]);
        }

        $this->user = $this->createUser('understanding-aid@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
        $this->serializerService = app(SenseReviewCardSerializerService::class);
    }

    public function test_payload_includes_understanding_aid_key_when_null(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertArrayHasKey('understanding_aid', $payload);
        $this->assertIsArray($payload['understanding_aid']);
        $this->assertArrayHasKey('explanation', $payload['understanding_aid']);
        $this->assertArrayHasKey('meaning_boundary', $payload['understanding_aid']);
        $this->assertArrayHasKey('context_hint', $payload['understanding_aid']);
        $this->assertArrayHasKey('usage_keywords', $payload['understanding_aid']);
    }

    public function test_payload_includes_understanding_aid_data_when_set(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $sense->understanding_aid = [
            'explanation' => 'bank = 金融机构',
            'meaning_boundary' => '区别于 bank = 河岸',
            'context_hint' => '用户去银行办理事务',
            'usage_keywords' => ['go to the bank', 'bank account', 'loan'],
        ];
        $sense->save();

        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertSame('bank = 金融机构', $payload['understanding_aid']['explanation']);
        $this->assertSame('区别于 bank = 河岸', $payload['understanding_aid']['meaning_boundary']);
        $this->assertSame('用户去银行办理事务', $payload['understanding_aid']['context_hint']);
        $this->assertSame(['go to the bank', 'bank account', 'loan'], $payload['understanding_aid']['usage_keywords']);
    }

    public function test_understanding_aid_does_not_change_fsrs_fields(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $sense->understanding_aid = [
            'explanation' => 'test',
            'usage_keywords' => ['a', 'b'],
        ];
        $sense->save();

        $card = $this->createSenseCard($sense, [
            'fsrs_stability' => 12.34,
            'fsrs_difficulty' => 5.67,
        ]);

        $dueBefore = $card->fsrs_due_at->toIso8601String();
        $stabilityBefore = $card->fsrs_stability;
        $difficultyBefore = $card->fsrs_difficulty;
        $repsBefore = $card->fsrs_reps;

        for ($i = 0; $i < 5; $i++) {
            $this->serializerService->serialize($card->fresh()->load('sense'));
        }

        $card->refresh();
        $this->assertSame($dueBefore, $card->fsrs_due_at->toIso8601String());
        $this->assertSame($stabilityBefore, $card->fsrs_stability);
        $this->assertSame($difficultyBefore, $card->fsrs_difficulty);
        $this->assertSame($repsBefore, $card->fsrs_reps);
    }

    public function test_understanding_aid_does_not_write_review_log(): void
    {
        $sense = $this->createConfirmedSense('bank');
        $sense->understanding_aid = ['explanation' => 'test'];
        $sense->save();

        $card = $this->createSenseCard($sense);

        $reviewLogBefore = ReviewLog::count();

        for ($i = 0; $i < 5; $i++) {
            $this->serializerService->serialize($card->fresh()->load('sense'));
        }

        $this->assertSame($reviewLogBefore, ReviewLog::count());
    }

    public function test_understanding_aid_is_sense_level_not_occurrence_level(): void
    {
        // The understanding_aid must stay the same regardless of which
        // occurrence is displayed (it is sense-level metadata, not
        // occurrence-level).
        $sense = $this->createConfirmedSense('bank');
        $sense->understanding_aid = ['explanation' => 'sense-level explanation'];
        $sense->save();

        $chapter1 = $this->createTestChapter('Chapter A');
        $chapter2 = $this->createTestChapter('Chapter B');
        $this->createOccurrence($sense, $chapter1, 's1', 'First sentence.');
        $this->createOccurrence($sense, $chapter2, 's2', 'Second sentence.');

        $card = $this->createSenseCard($sense, ['fsrs_reps' => 0]);

        // reps=0 shows one occurrence, reps=1 shows the other
        $card->fsrs_reps = 0;
        $card->save();
        $payload0 = $this->serializerService->serialize($card->fresh()->load('sense'));

        $card->fsrs_reps = 1;
        $card->save();
        $payload1 = $this->serializerService->serialize($card->fresh()->load('sense'));

        // The displayed occurrence may differ, but understanding_aid is identical
        $this->assertSame(
            $payload0['understanding_aid'],
            $payload1['understanding_aid'],
            'understanding_aid must be sense-level (identical across rotations)'
        );
        $this->assertSame('sense-level explanation', $payload0['understanding_aid']['explanation']);
    }

    public function test_understanding_aid_partial_data_still_serializes(): void
    {
        // Only explanation is set; other fields default to null/empty.
        $sense = $this->createConfirmedSense('bank');
        $sense->understanding_aid = ['explanation' => 'only explanation'];
        $sense->save();

        $card = $this->createSenseCard($sense);

        $payload = $this->serializerService->serialize($card->fresh()->load('sense'));

        $this->assertSame('only explanation', $payload['understanding_aid']['explanation']);
        $this->assertNull($payload['understanding_aid']['meaning_boundary']);
        $this->assertNull($payload['understanding_aid']['context_hint']);
        $this->assertSame([], $payload['understanding_aid']['usage_keywords']);
    }

    // ==================== Helpers ====================

    private function createConfirmedSense(string $lemma, string $exampleEn = ''): WordSense
    {
        $sense = $this->wordSenseService->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => ucfirst($lemma),
            'pos' => 'noun',
            'sense_zh' => '测试',
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => $exampleEn,
            'example_sentence_zh' => '',
        ]);
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);
        return $sense->fresh();
    }

    private function createTestChapter(string $name): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 1,
            'name' => $name,
            'read_count' => 0,
            'word_count' => 0,
            'language' => 'english',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'raw_text' => '',
            'type' => 'text',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
            'processed_text' => gzcompress(json_encode([]), 1),
        ]);
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        $data = array_merge([
            'user_id' => $this->user->id,
            'language_id' => 'english',
            'language' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_enabled' => true,
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->subDay(),
            'fsrs_stability' => 1.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_reps' => 0,
            'fsrs_lapses' => 0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDay(),
        ], $overrides);

        return ReviewCard::forceCreate($data);
    }

    private function createOccurrence(WordSense $sense, Chapter $chapter, string $sentenceId, string $sentenceEn): WordSenseOccurrence
    {
        return WordSenseOccurrence::updateOrCreate([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'word_sense_id' => $sense->id,
            'chapter_id' => $chapter->id,
            'sentence_id' => $sentenceId,
            'surface' => $sense->surface_form,
            'source' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
        ], [
            'language' => $sense->language,
            'review_card_id' => null,
            'sentence_en' => $sentenceEn,
            'sentence_zh' => '',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'lemma' => $sense->lemma,
            'pos' => $sense->pos,
            'decision' => WordSenseOccurrence::SOURCE_MANUAL_SENSE_ADD,
            'confidence' => 1.0,
            'evidence' => ['source' => 'test'],
            'auto_fsrs_allowed' => true,
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'raw_payload' => [],
        ]);
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
