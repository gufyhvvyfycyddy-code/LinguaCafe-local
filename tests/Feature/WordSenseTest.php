<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\EncounteredWord;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\ReviewCardService;
use App\Services\ReviewService;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

        // Ensure reviewIntervals setting exists for setStage() calls
        if (!\App\Models\Setting::where('name', 'reviewIntervals')->exists()) {
            \App\Models\Setting::forceCreate([
                'name' => 'reviewIntervals',
                'value' => json_encode([
                    '-7' => [0],
                    '-6' => [1],
                    '-5' => [2],
                    '-4' => [3],
                    '-3' => [7],
                    '-2' => [15],
                    '-1' => [30],
                ]),
            ]);
        }

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

    public function test_sense_review_page_route_opens_for_authenticated_user(): void
    {
        $this->actingAs($this->user)->get('/reviews/senses')->assertOk();
    }

    public function test_sense_review_queue_returns_only_current_user_language_confirmed_sense_cards(): void
    {
        $confirmed = $this->createSense(['sense_key' => 'charge-money']);
        $dueSenseCard = $this->wordSenseService->createReviewCardForSense($confirmed);
        $word = $this->createWord('charge');
        app(ReviewCardService::class)->ensureWordCard($word);

        $otherUserSense = $this->createSense([
            'user_id' => $this->otherUser->id,
            'sense_key' => 'other-charge',
        ]);
        $this->wordSenseService->createReviewCardForSense($otherUserSense);

        $spanishSense = $this->createSense([
            'language' => 'spanish',
            'language_id' => 'spanish',
            'sense_key' => 'spanish-charge',
        ]);
        $this->wordSenseService->createReviewCardForSense($spanishSense);

        $rejected = $this->createSense([
            'sense_key' => 'charge-rejected',
            'status' => WordSense::STATUS_REJECTED,
        ]);
        $this->forceSenseCard($rejected);

        $suggested = $this->createSense([
            'sense_key' => 'charge-suggested',
            'status' => WordSense::STATUS_AI_SUGGESTED,
        ]);
        $this->forceSenseCard($suggested);

        $future = $this->createSense(['sense_key' => 'charge-future']);
        $futureCard = $this->wordSenseService->createReviewCardForSense($future);
        $futureCard->update(['fsrs_due_at' => now()->addDay()]);

        $response = $this->actingAs($this->user)->getJson('/reviews/senses');

        $response->assertOk();
        $this->assertCount(1, $response->json('cards'));
        $response->assertJsonPath('cards.0.review_card_id', $dueSenseCard->id);
        $response->assertJsonPath('cards.0.word_sense_id', $confirmed->id);
        $response->assertJsonPath('cards.0.lemma', 'charge');
        $response->assertJsonPath('summary.due_count', 1);
    }

    public function test_sense_review_rating_updates_sense_card_and_writes_logs_without_touching_word_card(): void
    {
        foreach (['again', 'hard', 'good', 'easy'] as $rating) {
            ReviewLog::query()->delete();
            ReviewCard::query()->delete();
            WordSense::query()->delete();
            EncounteredWord::query()->delete();

            $sense = $this->createSense(['sense_key' => "charge-{$rating}"]);
            $senseCard = $this->wordSenseService->createReviewCardForSense($sense);
            $word = $this->createWord("word-{$rating}");
            $wordCard = app(ReviewCardService::class)->ensureWordCard($word);

            $response = $this->actingAs($this->user)->postJson("/reviews/senses/{$senseCard->id}/rate", [
                'rating' => $rating,
            ]);

            $response->assertOk();
            $senseCard->refresh();
            $wordCard->refresh();

            $this->assertSame(1, $senseCard->fsrs_reps);
            $this->assertNotNull($senseCard->fsrs_stability);
            $this->assertSame(0, $wordCard->fsrs_reps);
            $this->assertNull($wordCard->fsrs_stability);

            $log = ReviewLog::first();
            $this->assertNotNull($log);
            $this->assertSame($senseCard->id, $log->review_card_id);
            $this->assertSame($rating, $log->rating);
            $this->assertSame('sense_review', $log->source);
        }
    }

    public function test_sense_review_rating_rejects_word_card_and_cross_scope_sense_card(): void
    {
        $word = $this->createWord('charge');
        $wordCard = app(ReviewCardService::class)->ensureWordCard($word);
        $otherSense = $this->createSense([
            'user_id' => $this->otherUser->id,
            'sense_key' => 'other-sense',
        ]);
        $otherCard = $this->wordSenseService->createReviewCardForSense($otherSense);

        $this->actingAs($this->user)->postJson("/reviews/senses/{$wordCard->id}/rate", [
            'rating' => 'good',
        ])->assertNotFound();

        $this->actingAs($this->user)->postJson("/reviews/senses/{$otherCard->id}/rate", [
            'rating' => 'good',
        ])->assertNotFound();

        $this->assertSame(0, ReviewLog::count());
    }

    public function test_bulk_confirm_only_processes_current_user_language_occurrences(): void
    {
        $sense = $this->createSense(['sense_key' => 'bulk-confirm']);
        $own = $this->createOccurrence(['word_sense_id' => $sense->id]);
        $other = $this->createOccurrence(['user_id' => $this->otherUser->id]);
        $spanish = $this->createOccurrence(['language' => 'spanish', 'language_id' => 'spanish']);

        $response = $this->actingAs($this->user)->postJson('/senses/occurrences/bulk-confirm', [
            'occurrence_ids' => [$own->id, $other->id, $spanish->id, 999999],
        ]);

        $response->assertOk();
        $response->assertJsonPath('requested_count', 4);
        $response->assertJsonPath('processed_count', 1);
        $response->assertJsonPath('skipped_count', 3);
        $own->refresh();
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $own->status);
    }

    public function test_bulk_confirm_confirms_multiple_pending_and_ai_suggested_senses(): void
    {
        $confirmedSense = $this->createSense(['sense_key' => 'bulk-confirmed']);
        $suggestedSense = $this->createSense([
            'sense_key' => 'bulk-suggested',
            'status' => WordSense::STATUS_AI_SUGGESTED,
        ]);
        $first = $this->createOccurrence(['word_sense_id' => $confirmedSense->id]);
        $second = $this->createOccurrence(['sentence_id' => 's2', 'word_sense_id' => $suggestedSense->id]);

        $response = $this->actingAs($this->user)->postJson('/senses/occurrences/bulk-confirm', [
            'occurrence_ids' => [$first->id, $second->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('confirmed_count', 2);
        $suggestedSense->refresh();
        $this->assertSame(WordSense::STATUS_CONFIRMED, $suggestedSense->status);
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $first->refresh()->status);
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $second->refresh()->status);
    }

    public function test_bulk_confirm_with_auto_fsrs_creates_sense_review_cards(): void
    {
        $sense = $this->createSense(['sense_key' => 'bulk-fsrs']);
        $occurrence = $this->createOccurrence(['word_sense_id' => $sense->id]);

        $response = $this->actingAs($this->user)->postJson('/senses/occurrences/bulk-confirm', [
            'occurrence_ids' => [$occurrence->id],
            'auto_fsrs_allowed' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('created_review_cards', 1);
        $this->assertTrue(ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $sense->id)->exists());
    }

    public function test_bulk_ignore_and_bulk_reject_do_not_create_review_cards(): void
    {
        $ignore = $this->createOccurrence(['sentence_id' => 'bulk-ignore']);
        $reject = $this->createOccurrence(['sentence_id' => 'bulk-reject']);

        $this->actingAs($this->user)->postJson('/senses/occurrences/bulk-ignore', [
            'occurrence_ids' => [$ignore->id],
        ])->assertOk()->assertJsonPath('ignored_count', 1);

        $this->actingAs($this->user)->postJson('/senses/occurrences/bulk-reject', [
            'occurrence_ids' => [$reject->id],
        ])->assertOk()->assertJsonPath('rejected_count', 1);

        $this->assertSame(WordSenseOccurrence::STATUS_IGNORED, $ignore->refresh()->status);
        $this->assertSame(WordSenseOccurrence::STATUS_REJECTED, $reject->refresh()->status);
        $this->assertSame(0, ReviewCard::count());
    }

    public function test_bulk_confirm_high_confidence_only_processes_matching_existing_senses_over_threshold(): void
    {
        $sense = $this->createSense(['sense_key' => 'high-confidence']);
        $high = $this->createOccurrence([
            'word_sense_id' => $sense->id,
            'decision' => 'match_existing_sense',
            'confidence' => 0.95,
            'auto_fsrs_allowed' => true,
        ]);
        $low = $this->createOccurrence([
            'sentence_id' => 'low',
            'word_sense_id' => $sense->id,
            'decision' => 'match_existing_sense',
            'confidence' => 0.89,
            'auto_fsrs_allowed' => true,
        ]);
        $uncertain = $this->createOccurrence([
            'sentence_id' => 'uncertain-high',
            'word_sense_id' => $sense->id,
            'decision' => 'uncertain',
            'confidence' => 0.99,
            'auto_fsrs_allowed' => true,
        ]);
        $newSense = $this->createOccurrence([
            'sentence_id' => 'new-high',
            'word_sense_id' => $sense->id,
            'decision' => 'new_sense',
            'confidence' => 0.99,
            'auto_fsrs_allowed' => true,
        ]);
        $phrase = $this->createOccurrence([
            'sentence_id' => 'phrase-high',
            'word_sense_id' => $sense->id,
            'decision' => 'phrase_match',
            'type' => WordSenseOccurrence::TYPE_PHRASE,
            'confidence' => 0.99,
            'auto_fsrs_allowed' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson('/senses/occurrences/bulk-confirm-high-confidence', [
            'confidence_min' => 0.90,
        ]);

        $response->assertOk();
        $response->assertJsonPath('processed_count', 1);
        $response->assertJsonPath('confirmed_count', 1);
        $response->assertJsonPath('created_review_cards', 1);
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $high->refresh()->status);
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $low->refresh()->status);
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $uncertain->refresh()->status);
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $newSense->refresh()->status);
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $phrase->refresh()->status);
    }

    public function test_possible_duplicates_returns_same_lemma_duplicates_without_cross_scope_data(): void
    {
        $first = $this->createSense([
            'sense_key' => 'dup-one',
            'sense_zh' => 'charge money',
            'aliases_zh' => ['fee'],
        ]);
        $second = $this->createSense([
            'sense_key' => 'dup-two',
            'sense_zh' => 'charge money',
            'aliases_zh' => ['bill'],
        ]);
        $this->createSense([
            'sense_key' => 'not-dup',
            'sense_zh' => 'attack',
            'aliases_zh' => ['attack'],
        ]);
        $this->createSense([
            'user_id' => $this->otherUser->id,
            'sense_key' => 'other-dup',
            'sense_zh' => 'charge money',
        ]);
        $this->createSense([
            'language' => 'spanish',
            'language_id' => 'spanish',
            'sense_key' => 'spanish-dup',
            'sense_zh' => 'charge money',
        ]);

        $response = $this->actingAs($this->user)->getJson('/senses/possible-duplicates?lemma=charge');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $senseIds = collect($response->json('0.senses'))->pluck('sense_id')->all();
        $this->assertContains($first->id, $senseIds);
        $this->assertContains($second->id, $senseIds);
        $this->assertCount(2, $senseIds);
    }

    public function test_make_gpt_package_generates_markdown_with_rules_schema_and_current_senses(): void
    {
        $outputPath = 'storage/app/gpt-sense-package-test.md';
        @unlink(base_path($outputPath));
        $materialPath = $this->writeMaterial('new-material-package.txt', 'They charge a fee.');
        $sense = $this->createSense(['sense_key' => 'charge-money', 'sense_zh' => 'charge money']);
        $this->wordSenseService->createReviewCardForSense($sense);
        $this->createSense(['sense_key' => 'charge-rejected', 'status' => WordSense::STATUS_REJECTED]);
        $this->createSense(['sense_key' => 'charge-other-user', 'user_id' => $this->otherUser->id]);
        $this->createSense(['sense_key' => 'charge-spanish', 'language' => 'spanish', 'language_id' => 'spanish']);

        $this->artisan("senses:make-gpt-package --user_id={$this->user->id} --language=english --input={$materialPath} --output={$outputPath}")
            ->assertSuccessful();

        $content = file_get_contents(base_path($outputPath));
        $this->assertStringContainsString('Output strict JSON only', $content);
        $this->assertStringContainsString('confidence < 0.9', strtolower($content));
        $this->assertStringContainsString('auto_fsrs_allowed must be false', $content);
        $this->assertStringContainsString('"sentences"', $content);
        $this->assertStringContainsString('They charge a fee.', $content);
        $this->assertStringContainsString('charge-money', $content);
        $this->assertStringNotContainsString('charge-rejected', $content);
        $this->assertStringNotContainsString('charge-other-user', $content);
        $this->assertStringNotContainsString('charge-spanish', $content);
    }

    public function test_make_gpt_package_generates_json(): void
    {
        $outputPath = 'storage/app/gpt-sense-package-test.json';
        @unlink(base_path($outputPath));
        $materialPath = $this->writeMaterial('new-material-package-json.txt', 'They charge a fee.');
        $sense = $this->createSense(['sense_key' => 'charge-money', 'sense_zh' => 'charge money']);
        $this->wordSenseService->createReviewCardForSense($sense);

        $this->artisan("senses:make-gpt-package --user_id={$this->user->id} --language=english --input={$materialPath} --output={$outputPath} --format=json")
            ->assertSuccessful();

        $payload = json_decode(file_get_contents(base_path($outputPath)), true);
        $this->assertSame(1, $payload['package_schema_version']);
        $this->assertSame($this->user->id, $payload['user_id']);
        $this->assertSame('english', $payload['language']);
        $this->assertArrayHasKey('output_schema', $payload);
        $this->assertArrayHasKey('sentences', $payload['output_schema']);
        $this->assertSame('They charge a fee.', $payload['new_material']);
        $this->assertCount(1, $payload['learned_senses']);
        $this->assertSame($sense->id, $payload['learned_senses'][0]['sense_id']);
    }

    public function test_example_sense_mapping_and_sentences_schema_validate_and_import(): void
    {
        $examplePath = 'docs/examples/sense-mapping.example.json';

        $this->artisan("senses:validate-mapping {$examplePath} --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $this->artisan("senses:import-mapping {$examplePath} --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $this->assertSame(1, WordSenseOccurrence::count());
        $this->assertSame(1, WordSense::where('status', WordSense::STATUS_AI_SUGGESTED)->count());
    }

    public function test_gpt_workflow_prepare_generates_package_and_prompt(): void
    {
        $this->resetWorkflowDirectory();
        File::ensureDirectoryExists(base_path('storage/app/gpt-workflow/input'));
        File::put(base_path('storage/app/gpt-workflow/input/new-material.txt'), 'They charge a fee.');
        $sense = $this->createSense(['sense_key' => 'charge-money', 'sense_zh' => 'charge money']);
        $this->wordSenseService->createReviewCardForSense($sense);

        $this->artisan("senses:gpt-workflow prepare --user_id={$this->user->id} --language=english --input=storage/app/gpt-workflow/input/new-material.txt")
            ->assertSuccessful();

        $this->assertFileExists(base_path('storage/app/gpt-workflow/package/gpt-sense-package.md'));
        $this->assertFileExists(base_path('storage/app/gpt-workflow/package/prompt.txt'));
        $this->assertStringContainsString('sense-mapping.json', File::get(base_path('storage/app/gpt-workflow/package/prompt.txt')));
    }

    public function test_gpt_workflow_validate_latest_copies_success_to_validated(): void
    {
        $this->resetWorkflowDirectory();
        File::ensureDirectoryExists(base_path('storage/app/gpt-workflow/downloads'));
        File::put(base_path('storage/app/gpt-workflow/downloads/sense-mapping.json'), json_encode($this->mappingPayload([[
            'decision' => 'new_sense',
            'sense_en' => 'to ask for money',
            'confidence' => 0.95,
            'auto_fsrs_allowed' => false,
        ]]), JSON_PRETTY_PRINT));

        $this->artisan("senses:gpt-workflow validate-latest --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $this->assertCount(1, File::files(base_path('storage/app/gpt-workflow/validated')));
    }

    public function test_gpt_workflow_validate_latest_copies_failure_to_failed_with_report(): void
    {
        $this->resetWorkflowDirectory();
        File::ensureDirectoryExists(base_path('storage/app/gpt-workflow/downloads'));
        File::put(base_path('storage/app/gpt-workflow/downloads/bad.json'), '{"schema_version":1,"sentences":[');

        $this->artisan("senses:gpt-workflow validate-latest --user_id={$this->user->id} --language=english")
            ->assertFailed();

        $failedFiles = File::files(base_path('storage/app/gpt-workflow/failed'));
        $this->assertGreaterThanOrEqual(2, count($failedFiles));
        $this->assertStringContainsString('errors', collect($failedFiles)->first(fn ($file) => str_ends_with($file->getFilename(), '.errors.json'))->getFilename());
    }

    public function test_gpt_workflow_import_latest_dry_run_does_not_write_database(): void
    {
        $this->resetWorkflowDirectory();
        $this->writeValidatedWorkflowMapping();

        $this->artisan("senses:gpt-workflow import-latest --user_id={$this->user->id} --language=english --dry-run")
            ->assertSuccessful();

        $this->assertSame(0, WordSenseOccurrence::count());
    }

    public function test_gpt_workflow_import_latest_imports_and_copies_to_imported(): void
    {
        $this->resetWorkflowDirectory();
        $this->writeValidatedWorkflowMapping();

        $this->artisan("senses:gpt-workflow import-latest --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $this->assertSame(1, WordSenseOccurrence::count());
        $this->assertCount(1, File::files(base_path('storage/app/gpt-workflow/imported')));
    }

    public function test_gpt_workflow_without_downloads_gives_clear_error(): void
    {
        $this->resetWorkflowDirectory();

        $this->artisan("senses:gpt-workflow validate-latest --user_id={$this->user->id} --language=english")
            ->expectsOutputToContain('No JSON files found')
            ->assertFailed();

        $this->artisan("senses:gpt-workflow import-latest --user_id={$this->user->id} --language=english")
            ->expectsOutputToContain('No validated JSON files found')
            ->assertFailed();
    }

    public function test_gpt_workflow_doctor_runs_with_actionable_status(): void
    {
        $this->resetWorkflowDirectory();

        $this->artisan("senses:gpt-workflow doctor --user_id={$this->user->id} --language=english")
            ->expectsOutputToContain('LinguaCafe FSRS GPT workflow doctor')
            ->expectsOutputToContain('PHP version')
            ->assertSuccessful();
    }

    public function test_demo_gpt_workflow_runs_end_to_end(): void
    {
        $this->resetWorkflowDirectory();
        $sense = $this->createSense([
            'sense_key' => 'charge-money',
            'sense_zh' => '收费；要价',
            'sense_en' => 'to ask for money as a price',
        ]);
        File::ensureDirectoryExists(base_path('storage/app/gpt-workflow/input'));
        File::ensureDirectoryExists(base_path('storage/app/gpt-workflow/downloads'));
        File::put(base_path('storage/app/gpt-workflow/input/demo-material.txt'), implode("\n", [
            'The museum charges a small fee for late-night entry.',
            'Please charge the battery before tomorrow\'s trip.',
            'The committee tabled the proposal after a tense debate.',
            'She kicked the bucket list idea into next year.',
            'I usually drink water with dinner.',
        ]));

        $this->artisan("senses:gpt-workflow prepare --user_id={$this->user->id} --language=english --input=storage/app/gpt-workflow/input/demo-material.txt")
            ->assertSuccessful();
        $this->assertFileExists(base_path('storage/app/gpt-workflow/package/gpt-sense-package.md'));
        $this->assertFileExists(base_path('storage/app/gpt-workflow/package/prompt.txt'));

        File::put(
            base_path('storage/app/gpt-workflow/downloads/demo-sense-mapping.json'),
            json_encode($this->demoMappingPayload($sense->id), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->artisan("senses:gpt-workflow validate-latest --user_id={$this->user->id} --language=english")
            ->assertSuccessful();
        $this->assertCount(1, File::files(base_path('storage/app/gpt-workflow/validated')));

        $this->artisan("senses:gpt-workflow import-latest --user_id={$this->user->id} --language=english --dry-run")
            ->assertSuccessful();
        $this->assertSame(0, WordSenseOccurrence::count());
        $this->assertSame(0, ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->count());

        $this->artisan("senses:gpt-workflow import-latest --user_id={$this->user->id} --language=english")
            ->assertSuccessful();

        $this->assertSame(5, WordSenseOccurrence::count());
        $this->assertSame(1, WordSenseOccurrence::where('status', WordSenseOccurrence::STATUS_BOUND)->count());
        $this->assertSame(3, WordSenseOccurrence::where('status', WordSenseOccurrence::STATUS_PENDING)->count());
        $this->assertSame(1, WordSenseOccurrence::where('status', WordSenseOccurrence::STATUS_IGNORED)->count());
        $this->assertTrue(WordSenseOccurrence::where('type', WordSenseOccurrence::TYPE_PHRASE)->where('status', WordSenseOccurrence::STATUS_PENDING)->exists());
        $this->assertTrue(ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $sense->id)->exists());

        $pendingResponse = $this->actingAs($this->user)->getJson('/senses/occurrences?status=pending');
        $pendingResponse->assertOk();
        $this->assertSame(3, $pendingResponse->json('summary.pending'));

        $senseReviewResponse = $this->actingAs($this->user)->getJson('/reviews/senses');
        $senseReviewResponse->assertOk();
        $this->assertSame(1, $senseReviewResponse->json('summary.due_count'));
    }

    // ─── Vocabulary bridge → WordSense → WordSenseOccurrence ───

    public function test_bridge_creates_word_sense_and_occurrence_when_saving_learning_word(): void
    {
        $chapter = $this->createTestChapter([
            (object) ['word' => 'Brick', 'sentence_index' => 0, 'spaceAfter' => false],
            (object) ['word' => '-', 'sentence_index' => 0, 'spaceAfter' => false],
            (object) ['word' => 'and', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'mortar', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'stores', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'are', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'still', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'important', 'sentence_index' => 0, 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => 0, 'spaceAfter' => false],
        ]);

        $word = $this->createWord('stores');
        $word->update(['stage' => -7, 'translation' => '商店']);

        $this->actingAs($this->user)->post('/vocabulary/word/update', [
            'id' => $word->id,
            'translation' => '商店',
            'stage' => -7,
            'word' => 'stores',
            'chapter_id' => $chapter->id,
            'sentence_index' => 0,
        ])->assertOk();

        // WordSense created
        $sense = WordSense::where('encountered_word_id', $word->id)->first();
        $this->assertNotNull($sense);
        $this->assertSame(WordSense::STATUS_AI_SUGGESTED, $sense->status);
        $this->assertSame('商店', $sense->sense_zh);

        // WordSenseOccurrence created
        $occurrence = WordSenseOccurrence::where('word_sense_id', $sense->id)->first();
        $this->assertNotNull($occurrence);
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $occurrence->status);
        $this->assertSame('manual_vocab_bridge', $occurrence->source);
        $this->assertSame('manual_vocab_bridge', $occurrence->decision);
        $this->assertSame(1.0, $occurrence->confidence);
        $this->assertTrue($occurrence->auto_fsrs_allowed);
        $this->assertSame('stores', $occurrence->surface);
        $this->assertSame('stores', $occurrence->lemma);
        $this->assertSame('0', $occurrence->sentence_id);
        $this->assertNotEmpty($occurrence->sentence_en);
        $this->assertStringContainsString('stores', $occurrence->sentence_en);
    }

    public function test_bridge_does_not_create_duplicate_occurrence(): void
    {
        $chapter = $this->createTestChapter([
            (object) ['word' => 'Stores', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'are', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'important', 'sentence_index' => 0, 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => 0, 'spaceAfter' => false],
        ]);

        $word = $this->createWord('stores');
        $word->update(['stage' => -7, 'translation' => '商店']);

        $postData = [
            'id' => $word->id,
            'translation' => '商店',
            'stage' => -7,
            'word' => 'stores',
            'chapter_id' => $chapter->id,
            'sentence_index' => 0,
        ];

        $this->actingAs($this->user)->post('/vocabulary/word/update', $postData)->assertOk();
        $this->actingAs($this->user)->post('/vocabulary/word/update', $postData)->assertOk();

        $this->assertSame(1, WordSense::where('encountered_word_id', $word->id)->count());
        $this->assertSame(1, WordSenseOccurrence::where('source', 'manual_vocab_bridge')
            ->where('chapter_id', $chapter->id)
            ->where('sentence_id', '0')
            ->where('surface', 'stores')
            ->count());
    }

    public function test_bridge_updates_pending_occurrence_on_repeat_save(): void
    {
        $chapter = $this->createTestChapter([
            (object) ['word' => 'Stores', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'are', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'important', 'sentence_index' => 0, 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => 0, 'spaceAfter' => false],
        ]);

        $word = $this->createWord('stores');
        $word->update(['stage' => -7, 'translation' => '商店']);

        $this->actingAs($this->user)->post('/vocabulary/word/update', [
            'id' => $word->id,
            'translation' => '商店',
            'stage' => -7,
            'word' => 'stores',
            'chapter_id' => $chapter->id,
            'sentence_index' => 0,
        ])->assertOk();

        $occurrence = WordSenseOccurrence::first();
        $this->assertSame('商店', $occurrence->raw_payload['sense_zh']);

        // re-save with different translation
        $word->update(['translation' => '店铺；门店']);
        $this->actingAs($this->user)->post('/vocabulary/word/update', [
            'id' => $word->id,
            'translation' => '店铺；门店',
            'stage' => -7,
            'word' => 'stores',
            'chapter_id' => $chapter->id,
            'sentence_index' => 0,
        ])->assertOk();

        $this->assertSame(1, WordSenseOccurrence::count());
        $occurrence->refresh();
        $this->assertSame(WordSenseOccurrence::STATUS_PENDING, $occurrence->status);
        $this->assertSame('店铺；门店', $occurrence->raw_payload['sense_zh']);
    }

    public function test_bridge_does_not_overwrite_confirmed_occurrence(): void
    {
        $chapter = $this->createTestChapter([
            (object) ['word' => 'Stores', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'are', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'important', 'sentence_index' => 0, 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => 0, 'spaceAfter' => false],
        ]);

        $word = $this->createWord('stores');
        $word->update(['stage' => -7, 'translation' => '商店']);

        $this->actingAs($this->user)->post('/vocabulary/word/update', [
            'id' => $word->id,
            'translation' => '商店',
            'stage' => -7,
            'word' => 'stores',
            'chapter_id' => $chapter->id,
            'sentence_index' => 0,
        ])->assertOk();

        // simulate user confirming the occurrence in /senses/review
        $occurrence = WordSenseOccurrence::first();
        $sense = WordSense::first();
        $sense->update(['status' => WordSense::STATUS_CONFIRMED]);
        $occurrence->update([
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'raw_payload' => ['sense_zh' => '商店', 'source' => 'manual_vocab_bridge'],
        ]);

        // re-save with different translation — should not overwrite
        $word->update(['translation' => '改掉的译法']);
        $this->actingAs($this->user)->post('/vocabulary/word/update', [
            'id' => $word->id,
            'translation' => '改掉的译法',
            'stage' => -7,
            'word' => 'stores',
            'chapter_id' => $chapter->id,
            'sentence_index' => 0,
        ])->assertOk();

        $occurrence->refresh();
        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $occurrence->status);
        $this->assertSame('商店', $occurrence->raw_payload['sense_zh']);
    }

    public function test_bridge_skips_occurrence_without_chapter_context(): void
    {
        $word = $this->createWord('stores');
        $word->update(['stage' => -7, 'translation' => '商店']);

        $this->actingAs($this->user)->post('/vocabulary/word/update', [
            'id' => $word->id,
            'translation' => '商店',
            'stage' => -7,
            'word' => 'stores',
        ])->assertOk();

        // WordSense should still be created
        $this->assertSame(1, WordSense::where('encountered_word_id', $word->id)->count());
        // WordSenseOccurrence should NOT be created (no chapter context)
        $this->assertSame(0, WordSenseOccurrence::count());
    }

    public function test_bridge_occurrence_appears_in_senses_occurrences_api(): void
    {
        $chapter = $this->createTestChapter([
            (object) ['word' => 'Stores', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'are', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'important', 'sentence_index' => 0, 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => 0, 'spaceAfter' => false],
        ]);

        $word = $this->createWord('stores');
        $word->update(['stage' => -7, 'translation' => '商店']);

        $this->actingAs($this->user)->post('/vocabulary/word/update', [
            'id' => $word->id,
            'translation' => '商店',
            'stage' => -7,
            'word' => 'stores',
            'chapter_id' => $chapter->id,
            'sentence_index' => 0,
        ])->assertOk();

        $response = $this->actingAs($this->user)->getJson('/senses/occurrences?status=pending');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('stores', $response->json('data.0.surface'));
        $this->assertSame('pending', $response->json('data.0.status'));
        $this->assertSame('manual_vocab_bridge', $response->json('data.0.decision'));
        $this->assertNotEmpty($response->json('data.0.sentence_en'));
        $this->assertSame(1, $response->json('summary.pending'));
    }

    public function test_confirming_bridge_occurrence_creates_sense_review_card(): void
    {
        $chapter = $this->createTestChapter([
            (object) ['word' => 'Stores', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'are', 'sentence_index' => 0, 'spaceAfter' => true],
            (object) ['word' => 'important', 'sentence_index' => 0, 'spaceAfter' => false],
            (object) ['word' => '.', 'sentence_index' => 0, 'spaceAfter' => false],
        ]);

        $word = $this->createWord('stores');
        $word->update(['stage' => -7, 'translation' => '商店']);

        $this->actingAs($this->user)->post('/vocabulary/word/update', [
            'id' => $word->id,
            'translation' => '商店',
            'stage' => -7,
            'word' => 'stores',
            'chapter_id' => $chapter->id,
            'sentence_index' => 0,
        ])->assertOk();

        $occurrence = WordSenseOccurrence::first();
        $sense = WordSense::first();

        // confirm via /senses/occurrences/{id}/confirm
        $this->actingAs($this->user)->post("/senses/occurrences/{$occurrence->id}/confirm")
            ->assertOk();

        $occurrence->refresh();
        $sense->refresh();

        $this->assertSame(WordSenseOccurrence::STATUS_BOUND, $occurrence->status);
        $this->assertSame(WordSense::STATUS_CONFIRMED, $sense->status);

        // sense review card should be created (auto_fsrs_allowed=true)
        $senseCard = ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)
            ->where('target_id', $sense->id)
            ->first();
        $this->assertNotNull($senseCard);
        $this->assertTrue($senseCard->fsrs_enabled);

        // word card should still exist and be enabled
        $wordCard = ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->first();
        $this->assertNotNull($wordCard);
        $this->assertTrue($wordCard->fsrs_enabled);
    }

    // ─── FSRS Doctor ───

    public function test_fsrs_doctor_detects_missing_word_card(): void
    {
        // Create a learning word without a review card
        $word = $this->createWord('doctor-test');
        $word->update(['stage' => -7]);
        // ensure no card exists
        ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->where('target_id', $word->id)->delete();

        $this->artisan('fsrs:doctor', ['--user_id' => $this->user->id, '--language' => 'english'])
            ->assertExitCode(1); // missing cards → non-zero
    }

    public function test_fsrs_doctor_fix_creates_missing_word_card(): void
    {
        $word = $this->createWord('doctor-fix');
        $word->update(['stage' => -7]);
        ReviewCard::where('target_type', ReviewCard::TARGET_WORD)->where('target_id', $word->id)->delete();

        $this->artisan('fsrs:doctor', [
            '--fix' => true,
            '--user_id' => $this->user->id,
            '--language' => 'english',
        ])->assertSuccessful();

        $this->assertTrue(ReviewCard::where('target_type', ReviewCard::TARGET_WORD)
            ->where('target_id', $word->id)->exists());
    }

    public function test_fsrs_doctor_does_not_duplicate_existing_word_card(): void
    {
        $word = $this->createWord('doctor-existing');
        $word->update(['stage' => -7]);
        // explicitly create the card first
        $card = app(ReviewCardService::class)->ensureWordCard($word);
        $cardId = $card->id;

        $this->artisan('fsrs:doctor', [
            '--fix' => true,
            '--user_id' => $this->user->id,
            '--language' => 'english',
        ])->assertSuccessful();

        $this->assertSame(1, ReviewCard::where('target_type', ReviewCard::TARGET_WORD)
            ->where('target_id', $word->id)->count());
        $this->assertSame($cardId, $card->fresh()->id);
    }

    public function test_fsrs_doctor_detects_missing_sense_card(): void
    {
        $sense = $this->createSense(['sense_key' => 'doctor-sense', 'sense_zh' => '医生']);
        ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $sense->id)->delete();

        $this->artisan('fsrs:doctor', ['--user_id' => $this->user->id, '--language' => 'english'])
            ->assertExitCode(1);
    }

    public function test_fsrs_doctor_fix_creates_missing_sense_card(): void
    {
        $sense = $this->createSense(['sense_key' => 'doctor-fix-sense', 'sense_zh' => '医生']);
        ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)->where('target_id', $sense->id)->delete();

        // should not exist yet
        $this->assertFalse(ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)
            ->where('target_id', $sense->id)->exists());

        $this->artisan('fsrs:doctor', [
            '--fix' => true,
            '--user_id' => $this->user->id,
            '--language' => 'english',
        ])->assertSuccessful();

        $this->assertTrue(ReviewCard::where('target_type', ReviewCard::TARGET_SENSE)
            ->where('target_id', $sense->id)->exists());
    }

    public function test_fsrs_doctor_all_healthy_when_cards_present(): void
    {
        $word = $this->createWord('doctor-healthy');
        $word->update(['stage' => -7]);
        app(ReviewCardService::class)->ensureWordCard($word);

        $sense = $this->createSense(['sense_key' => 'doctor-healthy-sense', 'sense_zh' => '医生']);
        app(ReviewCardService::class)->ensureSenseCard($sense);

        $this->artisan('fsrs:doctor', ['--user_id' => $this->user->id, '--language' => 'english'])
            ->assertSuccessful()
            ->assertExitCode(0);
    }

    // ─── Fallback tokenizer preserves PARAGRAPH_BREAK ───

    public function test_safe_markers_survive_fallback_tokenizer_as_single_tokens(): void
    {
        $textBlock = new \App\Services\TextBlockService($this->user->id, 'english');
        $reflector = new \ReflectionClass($textBlock);
        $method = $reflector->getMethod('fallbackEnglishTokenize');
        $method->setAccessible(true);

        // Use the real safe markers (ZZPARAZZ, ZZSECTxZ) — all uppercase letters
        $tokens = $method->invoke($textBlock, "Hello ZZPARAZZ world ZZSECTAZ Retail");

        $words = array_map(fn ($t) => $t->w, $tokens);

        $this->assertContains('ZZPARAZZ', $words);
        $this->assertContains('ZZSECTAZ', $words);
        $this->assertContains('Hello', $words);
        $this->assertContains('world', $words);
        $this->assertContains('Retail', $words);
    }

    public function test_map_structural_tokens_converts_markers_correctly(): void
    {
        $textBlock = new \App\Services\TextBlockService($this->user->id, 'english');
        $reflector = new \ReflectionClass($textBlock);
        $method = $reflector->getMethod('mapStructuralTokens');
        $method->setAccessible(true);

        $input = [
            (object) ['w' => 'Hello', 'l' => 'hello', 'si' => 0, 'pos' => 'X'],
            (object) ['w' => 'ZZPARAZZ', 'l' => 'ZZPARAZZ', 'si' => 1, 'pos' => 'X'],
            (object) ['w' => 'ZZSECTAZ', 'l' => 'ZZSECTAZ', 'si' => 2, 'pos' => 'X'],
            (object) ['w' => 'World', 'l' => 'world', 'si' => 3, 'pos' => 'X'],
        ];

        $result = $method->invoke($textBlock, $input);

        $outWords = [];
        $outPoses = [];
        foreach ($result as $t) {
            $outWords[] = $t->w;
            $outPoses[] = $t->pos;
        }

        $this->assertSame(['Hello', 'PARAGRAPH_BREAK', '[A]', 'World'], $outWords);
        $this->assertSame(['X', 'STRUCT', 'STRUCT', 'X'], $outPoses);
    }

    public function test_vocabulary_token_filter_skips_structural_tokens(): void
    {
        $this->assertTrue(\App\Services\VocabularyTokenFilter::shouldSkip('PARAGRAPH_BREAK', 'english'));
        $this->assertTrue(\App\Services\VocabularyTokenFilter::shouldSkip('NEWLINE', 'english'));
        $this->assertTrue(\App\Services\VocabularyTokenFilter::shouldSkip('[A]', 'english'));
        $this->assertTrue(\App\Services\VocabularyTokenFilter::shouldSkip('[B]', 'english'));
        $this->assertTrue(\App\Services\VocabularyTokenFilter::shouldSkip('[Z]', 'english'));
        // Backward compat
        $this->assertTrue(\App\Services\VocabularyTokenFilter::shouldSkip('_SECT_A_', 'english'));
        $this->assertTrue(\App\Services\VocabularyTokenFilter::shouldSkip('ZZPARAZZ', 'english'));
        $this->assertTrue(\App\Services\VocabularyTokenFilter::shouldSkip('ZZSECTAZ', 'english'));
        // Real words pass through
        $this->assertFalse(\App\Services\VocabularyTokenFilter::shouldSkip('brick', 'english'));
        $this->assertFalse(\App\Services\VocabularyTokenFilter::shouldSkip('stores', 'english'));
    }

    private function createTestChapter(array $processedWords): Chapter
    {
        return Chapter::forceCreate([
            'user_id' => $this->user->id,
            'book_id' => 1,
            'name' => 'Test Chapter',
            'read_count' => 0,
            'word_count' => count($processedWords),
            'language' => 'english',
            'unique_words' => '[]',
            'unique_word_ids' => '[]',
            'raw_text' => '',
            'type' => 'text',
            'subtitle_timestamps' => '[]',
            'processing_status' => 'processed',
            'processed_text' => gzcompress(json_encode($processedWords), 1),
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

    private function forceSenseCard(WordSense $sense): ReviewCard
    {
        return ReviewCard::forceCreate([
            'user_id' => $sense->user_id,
            'language' => $sense->language,
            'language_id' => $sense->language_id,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ]);
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

    private function writeMaterial(string $fileName, string $content): string
    {
        $relativePath = "storage/app/{$fileName}";
        file_put_contents(base_path($relativePath), $content);

        return $relativePath;
    }

    private function resetWorkflowDirectory(): void
    {
        File::deleteDirectory(base_path('storage/app/gpt-workflow'));
    }

    private function writeValidatedWorkflowMapping(): void
    {
        File::ensureDirectoryExists(base_path('storage/app/gpt-workflow/validated'));
        File::put(base_path('storage/app/gpt-workflow/validated/sense-mapping.json'), json_encode($this->mappingPayload([[
            'decision' => 'new_sense',
            'sense_en' => 'to ask for money',
            'confidence' => 0.95,
            'auto_fsrs_allowed' => false,
        ]]), JSON_PRETTY_PRINT));
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

    private function demoMappingPayload(int $matchedSenseId): array
    {
        return [
            'schema_version' => 1,
            'document_id' => 'demo-material',
            'language' => 'english',
            'sentences' => [
                [
                    'sentence_id' => 'demo-s001',
                    'en' => 'The museum charges a small fee for late-night entry.',
                    'zh' => '博物馆对夜间入场收取少量费用。',
                    'matches' => [[
                        'type' => 'word',
                        'surface' => 'charges',
                        'lemma' => 'charge',
                        'pos' => 'verb',
                        'decision' => 'match_existing_sense',
                        'matched_sense_id' => $matchedSenseId,
                        'sense_key' => 'charge-money',
                        'sense_zh' => '收费；要价',
                        'sense_en' => 'to ask for money as a price',
                        'confidence' => 0.96,
                        'evidence' => 'The sentence uses charge with fee, matching the money sense.',
                        'auto_fsrs_allowed' => true,
                    ]],
                ],
                [
                    'sentence_id' => 'demo-s002',
                    'en' => 'Please charge the battery before tomorrow\'s trip.',
                    'zh' => '请在明天出行前给电池充电。',
                    'matches' => [[
                        'type' => 'word',
                        'surface' => 'charge',
                        'lemma' => 'charge',
                        'pos' => 'verb',
                        'decision' => 'new_sense',
                        'sense_zh' => '充电',
                        'sense_en' => 'to put electrical energy into a battery',
                        'confidence' => 0.94,
                        'evidence' => 'The object is battery, which is a different sense from charging money.',
                        'auto_fsrs_allowed' => false,
                    ]],
                ],
                [
                    'sentence_id' => 'demo-s003',
                    'en' => 'The committee tabled the proposal after a tense debate.',
                    'zh' => '委员会在激烈辩论后搁置了这项提案。',
                    'matches' => [[
                        'type' => 'word',
                        'surface' => 'tabled',
                        'lemma' => 'table',
                        'pos' => 'verb',
                        'decision' => 'uncertain',
                        'sense_zh' => '搁置；提交讨论',
                        'sense_en' => 'context-dependent use of table as a verb',
                        'confidence' => 0.62,
                        'evidence' => 'The verb table can mean different actions by dialect and context.',
                        'auto_fsrs_allowed' => false,
                    ]],
                ],
                [
                    'sentence_id' => 'demo-s004',
                    'en' => 'I usually drink water with dinner.',
                    'zh' => '我晚餐通常喝水。',
                    'matches' => [[
                        'type' => 'word',
                        'surface' => 'water',
                        'lemma' => 'water',
                        'pos' => 'noun',
                        'decision' => 'ignore',
                        'sense_zh' => '水',
                        'sense_en' => 'water',
                        'confidence' => 1.0,
                        'evidence' => 'Common word that does not need a new learning item in this demo.',
                        'auto_fsrs_allowed' => false,
                    ]],
                ],
                [
                    'sentence_id' => 'demo-s005',
                    'en' => 'She kicked the bucket list idea into next year.',
                    'zh' => '她把那个遗愿清单的想法推迟到了明年。',
                    'matches' => [[
                        'type' => 'phrase',
                        'surface' => 'bucket list',
                        'lemma' => 'bucket list',
                        'pos' => 'noun',
                        'decision' => 'phrase_match',
                        'sense_zh' => '遗愿清单',
                        'sense_en' => 'a list of things someone wants to do during their life',
                        'confidence' => 0.93,
                        'evidence' => 'This is a phrase-level meaning and phrase FSRS is deferred.',
                        'auto_fsrs_allowed' => false,
                    ]],
                ],
            ],
        ];
    }
}
