<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Models\WordSenseOccurrence;
use App\Services\ReviewCardService;
use App\Services\WordSenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class WordSenseLearningEntryTruthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WordSenseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('truth');
        $this->service = app(WordSenseService::class);
    }

    public function test_reading_enrollment_records_the_canonical_source_once_without_review_log(): void
    {
        $sense = $this->sense('bank');
        $source = $this->occurrence($sense, WordSenseOccurrence::SOURCE_READING_OCCURRENCE);

        $card = $this->service->enrollConfirmedSenseFromOccurrence($sense, $source);
        $startedAt = $sense->fresh()->learning_started_at;
        $this->service->createReviewCardForSense($sense);

        $sense->refresh();
        $this->assertSame(ReviewCard::TARGET_SENSE, $card->target_type);
        $this->assertSame(WordSense::LEARNING_ORIGIN_READING, $sense->learning_started_origin);
        $this->assertSame($source->id, $sense->learning_started_source_occurrence_id);
        $this->assertEquals($startedAt, $sense->learning_started_at);
        $this->assertSame(1, ReviewCard::count());
        $this->assertSame(0, ReviewLog::count());
    }

    public function test_non_reading_enrollment_has_no_source_pointer(): void
    {
        $sense = $this->sense('plain');

        $this->service->createReviewCardForSense($sense);

        $sense->refresh();
        $this->assertSame(WordSense::LEARNING_ORIGIN_NON_READING, $sense->learning_started_origin);
        $this->assertNotNull($sense->learning_started_at);
        $this->assertNull($sense->learning_started_source_occurrence_id);
    }

    public function test_generic_sense_creation_cannot_mass_assign_learning_entry_truth(): void
    {
        $sense = $this->service->createSense([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => 'guarded',
            'sense_zh' => '受保护',
            'status' => WordSense::STATUS_CONFIRMED,
            'learning_started_at' => now(),
            'learning_started_origin' => WordSense::LEARNING_ORIGIN_READING,
            'learning_started_source_occurrence_id' => 123,
        ]);

        $sense->refresh();
        $this->assertNull($sense->learning_started_at);
        $this->assertNull($sense->learning_started_origin);
        $this->assertNull($sense->learning_started_source_occurrence_id);
    }

    public function test_preexisting_generic_card_is_classified_as_legacy_unknown(): void
    {
        $sense = $this->sense('legacy');
        app(ReviewCardService::class)->ensureSenseCard($sense);

        $this->service->createReviewCardForSense($sense);

        $sense->refresh();
        $this->assertSame(WordSense::LEARNING_ORIGIN_LEGACY_UNKNOWN, $sense->learning_started_origin);
        $this->assertNull($sense->learning_started_at);
        $this->assertNull($sense->learning_started_source_occurrence_id);
    }

    public function test_canonical_reading_source_cannot_be_deleted_after_enrollment(): void
    {
        $sense = $this->sense('protected-source');
        $source = $this->occurrence($sense, WordSenseOccurrence::SOURCE_READING_OCCURRENCE);
        $this->service->enrollConfirmedSenseFromOccurrence($sense, $source);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $source->delete();
    }

    public function test_reading_enrollment_rejects_foreign_or_noncanonical_sources(): void
    {
        $sense = $this->sense('scope');
        $foreign = $this->occurrence($sense, WordSenseOccurrence::SOURCE_READING_OCCURRENCE, $this->createUser('foreign'));

        try {
            $this->service->enrollConfirmedSense(
                $sense,
                WordSense::LEARNING_ORIGIN_READING,
                $foreign,
            );
            $this->fail('Foreign reading occurrence was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('same-scope', $exception->getMessage());
        }

        $unknown = $this->occurrence($sense, 'unknown_source');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->enrollConfirmedSenseFromOccurrence($sense, $unknown);
    }

    private function createUser(string $label): User
    {
        return User::forceCreate([
            'name' => "Learning {$label}",
            'email' => "learning-{$label}-".Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function sense(string $key): WordSense
    {
        return WordSense::forceCreate([
            'user_id' => $this->user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $key,
            'sense_key' => $key.'-'.Str::uuid(),
            'sense_zh' => '释义',
            'status' => WordSense::STATUS_CONFIRMED,
        ]);
    }

    private function occurrence(
        WordSense $sense,
        string $source,
        ?User $owner = null,
    ): WordSenseOccurrence {
        $owner ??= $this->user;

        return WordSenseOccurrence::forceCreate([
            'user_id' => $owner->id,
            'language' => 'english',
            'language_id' => 'english',
            'word_sense_id' => $sense->id,
            'sentence_id' => '0',
            'sentence_en' => 'The bank reopened.',
            'type' => WordSenseOccurrence::TYPE_WORD,
            'surface' => $sense->lemma,
            'lemma' => $sense->lemma,
            'decision' => 'test',
            'status' => WordSenseOccurrence::STATUS_BOUND,
            'source' => $source,
        ]);
    }
}
