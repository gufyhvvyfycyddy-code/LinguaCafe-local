<?php

namespace Tests\Feature;

use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\ReviewCardService;
use App\Services\ReviewService;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class WordSenseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private WordSenseService $wordSenseService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('sense@example.com', 'english');
        $this->otherUser = $this->createUser('other-sense@example.com', 'english');
        $this->wordSenseService = app(WordSenseService::class);
    }

    public function test_same_lemma_can_have_multiple_senses(): void
    {
        $chargeMoney = $this->createSense(['sense_key' => 'charge-money', 'sense_zh' => '收费；要价']);
        $chargeAccuse = $this->createSense(['sense_key' => 'charge-accuse', 'sense_zh' => '指控；控告']);
        $chargeBattery = $this->createSense(['sense_key' => 'charge-battery', 'sense_zh' => '充电']);

        $this->assertSame(3, WordSense::where('user_id', $this->user->id)->where('lemma', 'charge')->count());
        $this->assertNotSame($chargeMoney->id, $chargeAccuse->id);
        $this->assertNotSame($chargeAccuse->id, $chargeBattery->id);
    }

    public function test_same_sense_key_is_not_created_twice_by_service(): void
    {
        $first = $this->wordSenseService->createOrFindSense($this->senseData([
            'sense_key' => 'charge-money',
            'sense_zh' => '收费；要价',
        ]));
        $second = $this->wordSenseService->createOrFindSense($this->senseData([
            'sense_key' => 'charge-money',
            'sense_zh' => '收取费用',
        ]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WordSense::where('sense_key', 'charge-money')->count());
    }

    public function test_alias_can_match_existing_sense(): void
    {
        $sense = $this->createSense([
            'sense_key' => 'charge-money',
            'sense_zh' => '收费；要价',
            'aliases_zh' => ['收费', '要价'],
        ]);

        $matched = $this->wordSenseService->findByAlias($this->user->id, 'english', ' 要价 ');

        $this->assertNotNull($matched);
        $this->assertSame($sense->id, $matched->id);
    }

    public function test_sense_can_create_review_card_and_rejected_sense_cannot(): void
    {
        $confirmed = $this->createSense(['sense_key' => 'charge-money', 'sense_zh' => '收费；要价']);
        $rejected = $this->createSense([
            'sense_key' => 'charge-rejected',
            'sense_zh' => '错误释义',
            'status' => WordSense::STATUS_REJECTED,
        ]);

        $card = $this->wordSenseService->createReviewCardForSense($confirmed);
        $rejectedCard = $this->wordSenseService->createReviewCardForSense($rejected);

        $this->assertNotNull($card);
        $this->assertSame(ReviewCard::TARGET_SENSE, $card->target_type);
        $this->assertSame($confirmed->id, $card->target_id);
        $this->assertNull($rejectedCard);
    }

    public function test_sense_card_rating_does_not_affect_word_card_or_other_sense(): void
    {
        $word = $this->createWord('charge');
        $wordCard = app(ReviewCardService::class)->ensureWordCard($word);
        $senseA = $this->createSense(['sense_key' => 'charge-money', 'sense_zh' => '收费；要价']);
        $senseB = $this->createSense(['sense_key' => 'charge-battery', 'sense_zh' => '充电']);
        $senseCardA = $this->wordSenseService->createReviewCardForSense($senseA);
        $senseCardB = $this->wordSenseService->createReviewCardForSense($senseB);

        app(ReviewCardService::class)->recordReview($this->user->id, 'english', $senseCardA->id, 'good');

        $wordCard->refresh();
        $senseCardA->refresh();
        $senseCardB->refresh();

        $this->assertSame(0, $wordCard->fsrs_reps);
        $this->assertNull($wordCard->fsrs_stability);
        $this->assertSame(1, $senseCardA->fsrs_reps);
        $this->assertNotNull($senseCardA->fsrs_stability);
        $this->assertSame(0, $senseCardB->fsrs_reps);
        $this->assertNull($senseCardB->fsrs_stability);
        $this->assertSame(1, ReviewLog::where('review_card_id', $senseCardA->id)->count());
    }

    public function test_export_learned_senses_only_exports_current_user_language_confirmed_senses(): void
    {
        $exportPath = 'storage/app/learned-senses-test.json';
        @unlink(base_path($exportPath));

        $sense = $this->createSense(['sense_key' => 'charge-money', 'sense_zh' => '收费；要价']);
        $this->wordSenseService->createReviewCardForSense($sense);
        $this->createSense(['sense_key' => 'charge-rejected', 'sense_zh' => '错误释义', 'status' => WordSense::STATUS_REJECTED]);
        $this->createSense(['user_id' => $this->otherUser->id, 'sense_key' => 'other-charge', 'sense_zh' => '其他用户']);
        $this->createSense(['language' => 'spanish', 'language_id' => 'spanish', 'sense_key' => 'spanish-charge', 'sense_zh' => '西语']);

        $this->artisan("senses:export-learned --user_id={$this->user->id} --language=english --output={$exportPath}")
            ->assertSuccessful();

        $payload = json_decode(file_get_contents(base_path($exportPath)), true);
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame($this->user->id, $payload['user_id']);
        $this->assertSame('english', $payload['language']);
        $this->assertCount(1, $payload['senses']);
        $this->assertSame($sense->id, $payload['senses'][0]['sense_id']);
        $this->assertSame('charge-money', $payload['senses'][0]['sense_key']);
    }

    public function test_validate_mapping_accepts_valid_file(): void
    {
        $sense = $this->createSense(['sense_key' => 'charge-money', 'sense_zh' => '收费；要价']);
        $path = $this->writeMapping('valid-sense-mapping.json', [
            'schema_version' => 1,
            'items' => [[
                'sentence_id' => 's1',
                'en' => 'They charge a fee.',
                'matches' => [[
                    'decision' => 'match_existing_sense',
                    'matched_sense_id' => $sense->id,
                    'confidence' => 0.95,
                    'auto_fsrs_allowed' => true,
                ]],
            ]],
        ]);

        $this->artisan("senses:validate-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertSuccessful();
    }

    public function test_validate_mapping_rejects_missing_or_cross_language_matched_sense(): void
    {
        $spanishSense = $this->createSense([
            'language' => 'spanish',
            'language_id' => 'spanish',
            'sense_key' => 'spanish-charge',
            'sense_zh' => '西语',
        ]);
        $path = $this->writeMapping('invalid-matched-sense.json', [
            'schema_version' => 1,
            'items' => [[
                'sentence_id' => 's1',
                'en' => 'They charge a fee.',
                'matches' => [[
                    'decision' => 'match_existing_sense',
                    'matched_sense_id' => $spanishSense->id,
                    'confidence' => 0.95,
                    'auto_fsrs_allowed' => true,
                ]],
            ]],
        ]);

        $this->artisan("senses:validate-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertFailed();
    }

    public function test_validate_mapping_rejects_nonexistent_matched_sense(): void
    {
        $path = $this->writeMapping('nonexistent-matched-sense.json', [
            'schema_version' => 1,
            'items' => [[
                'sentence_id' => 's1',
                'en' => 'They charge a fee.',
                'matches' => [[
                    'decision' => 'match_existing_sense',
                    'matched_sense_id' => 999999,
                    'confidence' => 0.95,
                    'auto_fsrs_allowed' => true,
                ]],
            ]],
        ]);

        $this->artisan("senses:validate-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertFailed();
    }

    public function test_validate_mapping_rejects_low_confidence_auto_fsrs(): void
    {
        $path = $this->writeMapping('low-confidence-sense-mapping.json', [
            'schema_version' => 1,
            'items' => [[
                'sentence_id' => 's1',
                'en' => 'They charge a fee.',
                'matches' => [[
                    'decision' => 'new_sense',
                    'sense_zh' => '收费；要价',
                    'confidence' => 0.89,
                    'auto_fsrs_allowed' => true,
                ]],
            ]],
        ]);

        $this->artisan("senses:validate-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertFailed();
    }

    public function test_import_mapping_dry_run_does_not_write_database(): void
    {
        $sense = $this->createSense(['sense_key' => 'charge-money', 'sense_en' => 'to ask for money']);
        $path = $this->writeMapping('dry-run-import-sense-mapping.json', $this->mappingPayload([[
            'decision' => 'match_existing_sense',
            'matched_sense_id' => $sense->id,
            'confidence' => 0.95,
            'auto_fsrs_allowed' => true,
        ]]));

        $this->artisan("senses:import-mapping {$path} --user_id={$this->user->id} --language=english --dry-run")
            ->assertSuccessful();

        $this->assertSame(0, WordSenseOccurrence::count());
        $this->assertSame(0, ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count());
    }

    public function test_import_mapping_binds_high_confidence_existing_sense_and_creates_sense_card(): void
    {
        $sense = $this->createSense(['sense_key' => 'charge-money', 'sense_en' => 'to ask for money']);
        $path = $this->writeMapping('bound-existing-sense-mapping.json', $this->mappingPayload([[
            'decision' => 'match_existing_sense',
            'matched_sense_id' => $sense->id,
            'confidence' => 0.95,
            'auto_fsrs_allowed' => true,
        ]]));

        $this->artisan("senses:import-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $occurrence = WordSenseOccurrence::first();
        $this->assertNotNull($occurrence);
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $occurrence->status);
        $this->assertSame($sense->id, $occurrence->word_sense_id);
        $this->assertTrue($occurrence->auto_fsrs_allowed);
        $this->assertNotNull($occurrence->review_card_id);
        $this->assertTrue(ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $sense->id)->exists());
    }

    public function test_import_mapping_keeps_low_confidence_existing_sense_pending(): void
    {
        $sense = $this->createSense(['sense_key' => 'charge-money', 'sense_en' => 'to ask for money']);
        $path = $this->writeMapping('pending-existing-sense-mapping.json', $this->mappingPayload([[
            'decision' => 'match_existing_sense',
            'matched_sense_id' => $sense->id,
            'confidence' => 0.85,
            'auto_fsrs_allowed' => false,
        ]]));

        $this->artisan("senses:import-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $occurrence = WordSenseOccurrence::first();
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $occurrence->status);
        $this->assertSame($sense->id, $occurrence->word_sense_id);
        $this->assertFalse($occurrence->auto_fsrs_allowed);
        $this->assertNull($occurrence->review_card_id);
    }

    public function test_import_mapping_rejects_other_users_matched_sense(): void
    {
        $otherSense = $this->createSense([
            'user_id' => $this->otherUser->id,
            'sense_key' => 'other-charge',
            'sense_en' => 'other user sense',
        ]);
        $path = $this->writeMapping('other-user-import-sense-mapping.json', $this->mappingPayload([[
            'decision' => 'match_existing_sense',
            'matched_sense_id' => $otherSense->id,
            'confidence' => 0.95,
            'auto_fsrs_allowed' => true,
        ]]));

        $this->artisan("senses:import-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertFailed();

        $this->assertSame(0, WordSenseOccurrence::count());
    }

    public function test_import_mapping_rejects_other_language_matched_sense(): void
    {
        $spanishSense = $this->createSense([
            'language' => 'spanish',
            'language_id' => 'spanish',
            'sense_key' => 'spanish-charge',
            'sense_en' => 'spanish sense',
        ]);
        $path = $this->writeMapping('other-language-import-sense-mapping.json', $this->mappingPayload([[
            'decision' => 'match_existing_sense',
            'matched_sense_id' => $spanishSense->id,
            'confidence' => 0.95,
            'auto_fsrs_allowed' => true,
        ]]));

        $this->artisan("senses:import-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertFailed();

        $this->assertSame(0, WordSenseOccurrence::count());
    }

    public function test_import_mapping_new_sense_creates_ai_suggested_sense_and_pending_occurrence(): void
    {
        $path = $this->writeMapping('new-sense-import-mapping.json', $this->mappingPayload([[
            'decision' => 'new_sense',
            'sense_en' => 'to ask for money',
            'confidence' => 0.92,
            'auto_fsrs_allowed' => false,
        ]]));

        $this->artisan("senses:import-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $sense = WordSense::where('status', WordSense::STATUS_AI_SUGGESTED)->first();
        $occurrence = WordSenseOccurrence::first();
        $this->assertNotNull($sense);
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $occurrence->status);
        $this->assertSame($sense->id, $occurrence->word_sense_id);
        $this->assertFalse(ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->exists());
    }

    public function test_import_mapping_uncertain_creates_pending_occurrence_without_sense(): void
    {
        $path = $this->writeMapping('uncertain-import-mapping.json', $this->mappingPayload([[
            'decision' => 'uncertain',
            'confidence' => 0.50,
            'auto_fsrs_allowed' => false,
        ]]));

        $this->artisan("senses:import-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $occurrence = WordSenseOccurrence::first();
        $this->assertSame(0, WordSense::count());
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $occurrence->status);
        $this->assertNull($occurrence->word_sense_id);
        $this->assertFalse($occurrence->auto_fsrs_allowed);
    }

    public function test_import_mapping_ignore_creates_ignored_occurrence_without_review_card(): void
    {
        $path = $this->writeMapping('ignore-import-mapping.json', $this->mappingPayload([[
            'decision' => 'ignore',
            'confidence' => 1.0,
            'auto_fsrs_allowed' => false,
        ]]));

        $this->artisan("senses:import-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $occurrence = WordSenseOccurrence::first();
        $this->assertSame(WordSenseOccurrence::STATUS_IGNORED, $occurrence->status);
        $this->assertSame(0, ReviewCard::count());
    }

    public function test_import_mapping_phrase_match_creates_pending_phrase_occurrence_without_phrase_card(): void
    {
        $path = $this->writeMapping('phrase-import-mapping.json', $this->mappingPayload([[
            'decision' => 'phrase_match',
            'surface' => 'charge a fee',
            'lemma' => 'charge a fee',
            'confidence' => 0.94,
            'auto_fsrs_allowed' => false,
        ]]));

        $this->artisan("senses:import-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $occurrence = WordSenseOccurrence::first();
        $this->assertSame(WordSenseOccurrence::TYPE_PHRASE, $occurrence->type);
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $occurrence->status);
        $this->assertFalse(ReviewCard::where('target_type', ReviewCard::TARGET_PHRASE)->exists());
    }

    public function test_imported_bound_sense_card_can_be_reviewed(): void
    {
        $sense = $this->createSense(['sense_key' => 'charge-money', 'sense_en' => 'to ask for money']);
        $path = $this->writeMapping('review-imported-sense-card.json', $this->mappingPayload([[
            'decision' => 'match_existing_sense',
            'matched_sense_id' => $sense->id,
            'confidence' => 0.95,
            'auto_fsrs_allowed' => true,
        ]]));

        $this->artisan("senses:import-mapping {$path} --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $card = ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $sense->id)->first();
        app(ReviewCardService::class)->recordReview($this->user->id, 'english', $card->id, 'good');

        $card->refresh();
        $this->assertSame(1, $card->fsrs_reps);
        $this->assertSame(1, ReviewLog::where('review_card_id', $card->id)->count());
    }

    public function test_word_review_queue_stays_word_only_when_sense_cards_exist(): void
    {
        $word = $this->createWord('charge');
        $wordCard = app(ReviewCardService::class)->ensureWordCard($word);
        $sense = $this->createSense(['sense_key' => 'charge-money', 'sense_en' => 'to ask for money']);
        $this->wordSenseService->createReviewCardForSense($sense);

        $reviews = app(ReviewService::class)->getReviewItems($this->user->id, 'english', -1, -1, false, []);

        $this->assertCount(1, $reviews);
        $this->assertSame($word->id, $reviews[0]['id']);
        $this->assertSame($wordCard->id, $reviews[0]['review_card_id']);
        $this->assertSame('word', $reviews[0]['type']);
    }

    public function test_occurrence_list_returns_only_current_user_language(): void
    {
        $own = $this->createOccurrence();
        $this->createOccurrence(['user_id' => $this->otherUser->id]);
        $this->createOccurrence(['language' => 'spanish', 'language_id' => 'spanish']);

        $response = $this->actingAs($this->user)->get('/senses/occurrences?status=pending');

        $response->assertOk();
        $response->assertJsonPath('data.0.occurrence_id', $own->id);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('summary.pending'));
    }

    public function test_sense_mapping_review_page_route_opens_for_authenticated_user(): void
    {
        $this->actingAs($this->user)->get('/senses/review')->assertOk();
    }

    public function test_confirm_can_only_confirm_current_user_language_occurrence(): void
    {
        $ownSense = $this->createSense([
            'sense_key' => 'charge-ai',
            'status' => WordSense::STATUS_AI_SUGGESTED,
        ]);
        $ownOccurrence = $this->createOccurrence([
            'word_sense_id' => $ownSense->id,
            'auto_fsrs_allowed' => true,
        ]);
        $otherOccurrence = $this->createOccurrence(['user_id' => $this->otherUser->id]);

        $this->actingAs($this->user)->post("/senses/occurrences/{$otherOccurrence->id}/confirm")
            ->assertNotFound();
        $this->actingAs($this->user)->post("/senses/occurrences/{$ownOccurrence->id}/confirm")
            ->assertOk();

        $ownSense->refresh();
        $ownOccurrence->refresh();
        $this->assertSame(WordSense::STATUS_CONFIRMED, $ownSense->status);
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $ownOccurrence->status);
        $this->assertTrue(ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $ownSense->id)->exists());
    }

    public function test_bind_cannot_bind_other_user_or_language_sense(): void
    {
        $occurrence = $this->createOccurrence();
        $otherUserSense = $this->createSense([
            'user_id' => $this->otherUser->id,
            'sense_key' => 'other-sense',
        ]);
        $otherLanguageSense = $this->createSense([
            'language' => 'spanish',
            'language_id' => 'spanish',
            'sense_key' => 'spanish-sense',
        ]);

        $this->actingAs($this->user)->post("/senses/occurrences/{$occurrence->id}/bind", [
            'sense_id' => $otherUserSense->id,
        ])->assertNotFound();

        $this->actingAs($this->user)->post("/senses/occurrences/{$occurrence->id}/bind", [
            'sense_id' => $otherLanguageSense->id,
        ])->assertNotFound();

        $occurrence->refresh();
        $this->assertNull($occurrence->word_sense_id);
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $occurrence->status);
    }

    public function test_bind_current_sense_can_create_sense_review_card(): void
    {
        $occurrence = $this->createOccurrence();
        $sense = $this->createSense(['sense_key' => 'charge-money']);

        $this->actingAs($this->user)->post("/senses/occurrences/{$occurrence->id}/bind", [
            'sense_id' => $sense->id,
            'auto_fsrs_allowed' => true,
        ])->assertOk();

        $occurrence->refresh();
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $occurrence->status);
        $this->assertSame($sense->id, $occurrence->word_sense_id);
        $this->assertNotNull($occurrence->review_card_id);
        $this->assertTrue(ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $sense->id)->exists());
    }

    public function test_create_sense_endpoint_creates_confirmed_sense_and_binds_occurrence(): void
    {
        $occurrence = $this->createOccurrence();

        $this->actingAs($this->user)->post("/senses/occurrences/{$occurrence->id}/create-sense", [
            'sense_zh' => 'charge money',
            'sense_en' => 'to ask for money',
            'pos' => 'verb',
            'aliases_zh' => 'fee, bill',
            'collocations' => 'charge a fee',
            'auto_fsrs_allowed' => true,
        ])->assertOk();

        $sense = WordSense::where('status', WordSense::STATUS_CONFIRMED)->first();
        $occurrence->refresh();
        $this->assertNotNull($sense);
        $this->assertSame($sense->id, $occurrence->word_sense_id);
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $occurrence->status);
        $this->assertTrue(ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $sense->id)->exists());
    }

    public function test_reject_and_ignore_do_not_create_review_cards(): void
    {
        $reject = $this->createOccurrence(['sentence_id' => 'reject']);
        $ignore = $this->createOccurrence(['sentence_id' => 'ignore']);

        $this->actingAs($this->user)->post("/senses/occurrences/{$reject->id}/reject")
            ->assertOk();
        $this->actingAs($this->user)->post("/senses/occurrences/{$ignore->id}/ignore")
            ->assertOk();

        $reject->refresh();
        $ignore->refresh();
        $this->assertSame(WordSenseOccurrence::STATUS_REJECTED, $reject->status);
        $this->assertSame(WordSenseOccurrence::STATUS_IGNORED, $ignore->status);
        $this->assertSame(0, ReviewCard::count());
    }

    public function test_candidates_returns_current_user_language_senses_for_lemma(): void
    {
        $sense = $this->createSense(['sense_key' => 'charge-money']);
        $this->createSense(['sense_key' => 'charge-other-user', 'user_id' => $this->otherUser->id]);
        $this->createSense(['sense_key' => 'charge-spanish', 'language' => 'spanish', 'language_id' => 'spanish']);

        $response = $this->actingAs($this->user)->get('/senses/candidates?lemma=charge&pos=verb');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $response->assertJsonPath('0.sense_id', $sense->id);
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

    private function createWord(string $word): EncounteredWord
    {
        return EncounteredWord::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'stage' => -1,
            'word' => $word,
            'kanji' => '',
            'reading' => '',
            'translation' => "{$word} translation",
            'base_word' => '',
            'base_word_reading' => '',
            'lookup_count' => 0,
            'read_count' => 0,
            'lemma' => '',
            'added_to_srs' => now()->toDateString(),
            'next_review' => now()->toDateString(),
            'relearning' => false,
        ]);
    }

    private function createSense(array $overrides): WordSense
    {
        return $this->wordSenseService->createSense($this->senseData($overrides));
    }

    private function createOccurrence(array $overrides = []): WordSenseOccurrence
    {
        return WordSenseOccurrence::forceCreate(array_merge([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'sentence_id' => 's1',
            'sentence_en' => 'They charge a fee.',
            'sentence_zh' => 'They charge a fee.',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => 'charge',
            'lemma' => 'charge',
            'pos' => 'verb',
            'decision' => 'uncertain',
            'confidence' => 0.5,
            'auto_fsrs_allowed' => false,
            'status' => WordSenseOccurrence::STATUS_PENDING,
            'source' => WordSenseOccurrence::SOURCE_SENSE_MAPPING_IMPORT,
            'raw_payload' => ['decision' => 'uncertain'],
        ], $overrides));
    }

    private function senseData(array $overrides): array
    {
        return array_merge([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'charge',
            'surface_form' => 'charge',
            'pos' => 'verb',
            'sense_zh' => '收费；要价',
            'sense_en' => 'to ask for money as a price',
            'aliases_zh' => [],
            'collocations' => ['charge a fee'],
            'example_sentence_en' => 'They charge a fee.',
            'example_sentence_zh' => '他们收费。',
        ], $overrides);
    }

    private function writeMapping(string $fileName, array $payload): string
    {
        $relativePath = "storage/app/{$fileName}";
        file_put_contents(base_path($relativePath), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $relativePath;
    }

    private function mappingPayload(array $matches): array
    {
        return [
            'schema_version' => 1,
            'items' => [[
                'sentence_id' => 's1',
                'en' => 'They charge a fee.',
                'zh' => 'They charge a fee.',
                'matches' => array_map(fn (array $match) => array_merge([
                    'surface' => 'charge',
                    'lemma' => 'charge',
                    'pos' => 'verb',
                ], $match), $matches),
            ]],
        ];
    }
}
