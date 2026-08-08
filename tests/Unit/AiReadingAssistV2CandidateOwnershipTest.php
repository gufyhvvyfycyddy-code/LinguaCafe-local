<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\WordSense;
use App\Services\AiReadingAssistV2Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\PabR3AiReadingAssistV2Harness as V2Harness;
use Tests\TestCase;

/**
 * DB-backed ownership tests. This suite is executable, but the parallel Harness
 * lane only lints/discovers it; Integration runs it under the exclusive testing-DB lease.
 */
class AiReadingAssistV2CandidateOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'id' => V2Harness::USER_ID,
            'name' => 'PAB R3 Candidate Owner',
            'email' => 'pab-r3-candidate-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => V2Harness::LANGUAGE,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    private function makeSense(int $userId, string $language, string $status = WordSense::STATUS_CONFIRMED): WordSense
    {
        $lemma = 'candidate-'.Str::lower(Str::random(8));
        return WordSense::forceCreate([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'NOUN',
            'sense_zh' => '候选义项',
            'sense_en' => 'candidate sense',
            'aliases_zh' => [],
            'collocations' => [],
            'status' => $status,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', "{$userId}|{$language}|{$lemma}|{$status}"),
        ]);
    }

    private function previewWithCandidate(WordSense $sense, ?callable $mutatePayload = null): array
    {
        $catalog = V2Harness::catalog(1, [0 => [(int) $sense->id]]);
        $service = V2Harness::service(fn () => $catalog);
        $package = V2Harness::packages($service)[0];
        $payload = V2Harness::aiPayload($package, 'matched_existing');
        if ($mutatePayload) {
            $payload = $mutatePayload($payload);
        }

        return $service->previewImport(
            V2Harness::USER_ID,
            V2Harness::LANGUAGE,
            V2Harness::CHAPTER_ID,
            [V2Harness::importPart($package, $payload)],
        );
    }

    public function test_matched_existing_accepts_owned_confirmed_candidate(): void
    {
        $sense = $this->makeSense($this->user->id, V2Harness::LANGUAGE);
        $result = $this->previewWithCandidate($sense);

        $this->assertTrue($result['success']);
        $this->assertSame($sense->id, $result['items']['word_results'][0]['matched_word_sense_id']);
        $this->assertSame($sense->sense_zh, $result['items']['word_results'][0]['sense_zh']);
    }

    public function test_matched_existing_rejects_candidate_outside_server_set(): void
    {
        $sense = $this->makeSense($this->user->id, V2Harness::LANGUAGE);
        $result = $this->previewWithCandidate($sense, function (array $payload): array {
            $payload['word_results'][0]['matched_word_sense_id'] = 999999999;
            return $payload;
        });

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_CANDIDATE_MISMATCH, $result['error_code']);
    }

    public function test_matched_existing_rejects_other_user_candidate(): void
    {
        $other = User::forceCreate([
            'name' => 'Other Candidate Owner',
            'email' => 'pab-r3-other-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => V2Harness::LANGUAGE,
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $result = $this->previewWithCandidate($this->makeSense($other->id, V2Harness::LANGUAGE));

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_WORD_SENSE_OWNERSHIP_MISMATCH, $result['error_code']);
    }

    public function test_matched_existing_rejects_other_language_candidate(): void
    {
        $result = $this->previewWithCandidate($this->makeSense($this->user->id, 'french'));

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_WORD_SENSE_OWNERSHIP_MISMATCH, $result['error_code']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonConfirmedStatuses')]
    public function test_matched_existing_rejects_non_confirmed_candidate(string $status): void
    {
        $result = $this->previewWithCandidate($this->makeSense($this->user->id, V2Harness::LANGUAGE, $status));

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_WORD_SENSE_OWNERSHIP_MISMATCH, $result['error_code']);
    }

    public static function nonConfirmedStatuses(): array
    {
        return [
            'ai suggested' => [WordSense::STATUS_AI_SUGGESTED],
            'rejected' => [WordSense::STATUS_REJECTED],
        ];
    }

    public function test_matched_existing_rejects_null_id_and_new_sense_payload(): void
    {
        $sense = $this->makeSense($this->user->id, V2Harness::LANGUAGE);
        foreach ([
            function (array $payload): array {
                $payload['word_results'][0]['matched_word_sense_id'] = null;
                return $payload;
            },
            function (array $payload): array {
                $payload['word_results'][0]['new_sense'] = ['sense_zh' => 'x', 'sense_en' => 'x', 'pos' => 'NOUN'];
                return $payload;
            },
        ] as $mutator) {
            $result = $this->previewWithCandidate($sense, $mutator);
            $this->assertFalse($result['success']);
            $this->assertSame(AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH, $result['error_code']);
        }
    }

    public function test_new_sense_and_ambiguous_reject_matched_id(): void
    {
        $catalog = V2Harness::catalog();
        $service = V2Harness::service(fn () => $catalog);
        $package = V2Harness::packages($service)[0];

        foreach (['new_sense', 'ambiguous'] as $mode) {
            $payload = V2Harness::aiPayload($package, $mode);
            $payload['word_results'][0]['matched_word_sense_id'] = 123;
            $result = $service->previewImport(
                V2Harness::USER_ID,
                V2Harness::LANGUAGE,
                V2Harness::CHAPTER_ID,
                [V2Harness::importPart($package, $payload)],
            );
            $this->assertFalse($result['success']);
            $this->assertSame(AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH, $result['error_code']);
        }
    }

    public function test_phrase_rejects_word_sense_resolution_field(): void
    {
        $catalog = V2Harness::catalog(0, [], true);
        $service = V2Harness::service(fn () => $catalog);
        $package = V2Harness::packages($service)[0];
        $payload = V2Harness::aiPayload($package);
        $payload['phrase_results'][0]['matched_word_sense_id'] = 123;
        $result = $service->previewImport(
            V2Harness::USER_ID,
            V2Harness::LANGUAGE,
            V2Harness::CHAPTER_ID,
            [V2Harness::importPart($package, $payload)],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(AiReadingAssistV2Service::ERROR_SCHEMA_MISMATCH, $result['error_code']);
    }
}
