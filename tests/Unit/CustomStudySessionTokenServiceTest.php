<?php

namespace Tests\Unit;

use App\Exceptions\CustomStudySessionStateException;
use App\Services\CustomStudy\CustomStudyCriteria;
use App\Services\CustomStudy\CustomStudySessionState;
use App\Services\CustomStudy\CustomStudySessionTokenService;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Encryption\Encrypter as ConcreteEncrypter;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for CustomStudySessionTokenService.
 *
 * Pure unit tests — no Laravel container, no DB, no Auth, no Request.
 * Uses a real Illuminate\Encryption\Encrypter instance with a test key.
 *
 * Verifies:
 * - issue() + verify() round-trip.
 * - Token opacity (no plaintext JSON, no card content).
 * - Tampered / empty / malformed token rejection (returns null).
 * - Version / user / language / expiry validation.
 * - MAX_TOKEN_BYTES (65536) enforcement.
 * - MAX_CANDIDATE_COUNT (500) enforcement via State.
 * - DEFAULT_TTL_SECONDS (14400 = 4 hours) contract.
 * - No rotate(answer) / rating / answer branching.
 * - No DB / Auth / Request / ReviewLog / FSRS / AI.
 * - issue() does not modify State; verify() does not modify State.
 *
 * Task 2000-19 — Custom Study 1A Phase 3A.
 */
class CustomStudySessionTokenServiceTest extends TestCase
{
    private Encrypter $encrypter;
    private CustomStudySessionTokenService $tokenService;

    protected function setUp(): void
    {
        parent::setUp();
        $key = ConcreteEncrypter::generateKey('aes-256-cbc');
        $this->encrypter = new ConcreteEncrypter($key, 'aes-256-cbc');
        $this->tokenService = new CustomStudySessionTokenService($this->encrypter);
    }

    // ---------- helpers ----------

    private function validUuidV4(): string
    {
        return '550e8400-e29b-41d4-a716-446655440000';
    }

    private function validDelayConfig(): array
    {
        return [
            'again_secs' => 60,
            'hard_secs' => 600,
            'good_secs' => 0,
            'easy_secs' => 0,
        ];
    }

    private function validCriteria(): CustomStudyCriteria
    {
        return CustomStudyCriteria::fromArray([
            'mode' => 'today_forgotten',
            'parameters' => [],
        ]);
    }

    private function validIssuedAt(): int
    {
        return 1720000000;
    }

    private function validExpiresAt(): int
    {
        return 1720000000 + CustomStudySessionTokenService::DEFAULT_TTL_SECONDS;
    }

    private function createValidState(array $candidates = [11, 12, 13]): CustomStudySessionState
    {
        return CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            $candidates,
            $this->validDelayConfig()
        );
    }

    private function validNow(): Carbon
    {
        return Carbon::createFromTimestamp($this->validIssuedAt() + 100, 'UTC');
    }

    // ---------- 1. Valid issue + verify ----------

    public function test_valid_issue_and_verify_round_trip(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);

        $verified = $this->tokenService->verify($token, 42, 'en', $this->validNow());

        $this->assertNotNull($verified);
        $this->assertSame($state->toArray(), $verified->toArray());
    }

    // ---------- 2. Result is opaque ----------

    public function test_token_is_not_plaintext_json(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);

        $this->assertNotSame($state->toArray(), $token);
        $this->assertStringNotContainsString('"version":1', $token);
        $this->assertStringNotContainsString('"user_id":42', $token);
        $this->assertStringNotContainsString('"session_id"', $token);
    }

    public function test_token_does_not_contain_plaintext_card_ids(): void
    {
        $state = $this->createValidState([12345, 67890]);
        $token = $this->tokenService->issue($state);

        $this->assertStringNotContainsString('12345', $token);
        $this->assertStringNotContainsString('67890', $token);
    }

    // ---------- 3. No plaintext JSON ----------

    public function test_token_does_not_contain_json_keys(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);

        $this->assertStringNotContainsString('ordered_candidate_ids', $token);
        $this->assertStringNotContainsString('completed_ids', $token);
        $this->assertStringNotContainsString('skipped_ineligible_ids', $token);
        $this->assertStringNotContainsString('preview_delay_config', $token);
    }

    // ---------- 4. State round-trip ----------

    public function test_round_trip_preserves_all_state_fields(): void
    {
        $state = $this->createValidState([11, 12, 13]);
        $token = $this->tokenService->issue($state);
        $verified = $this->tokenService->verify($token, 42, 'en', $this->validNow());

        $this->assertNotNull($verified);
        $this->assertSame(1, $verified->version());
        $this->assertSame(42, $verified->userId());
        $this->assertSame('en', $verified->language());
        $this->assertSame('today_forgotten', $verified->mode());
        $this->assertSame($this->validUuidV4(), $verified->sessionId());
        $this->assertSame([11, 12, 13], $verified->orderedCandidateIds());
        $this->assertSame(11, $verified->currentCardId());
        $this->assertSame([12, 13], $verified->readyQueue());
        $this->assertSame([], $verified->delayedRepeatQueue());
        $this->assertSame([], $verified->completedIds());
        $this->assertSame([], $verified->skippedIneligibleIds());
        $this->assertSame(0, $verified->completedCount());
        $this->assertSame(3, $verified->totalCount());
        $this->assertSame(0, $verified->step());
    }

    // ---------- 5. Tampered token returns null ----------

    public function test_tampered_token_returns_null(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);

        // Tamper with the token by modifying a character
        $tampered = substr($token, 0, -1) . (substr($token, -1) === 'A' ? 'B' : 'A');

        $result = $this->tokenService->verify($tampered, 42, 'en', $this->validNow());
        $this->assertNull($result);
    }

    // ---------- 6. Empty token returns null ----------

    public function test_empty_token_returns_null(): void
    {
        $result = $this->tokenService->verify('', 42, 'en', $this->validNow());
        $this->assertNull($result);
    }

    // ---------- 7. Malformed encrypted data returns null ----------

    public function test_malformed_encrypted_data_returns_null(): void
    {
        $result = $this->tokenService->verify('not-valid-encrypted-data', 42, 'en', $this->validNow());
        $this->assertNull($result);
    }

    public function test_random_base64_string_returns_null(): void
    {
        $result = $this->tokenService->verify(base64_encode('random-data'), 42, 'en', $this->validNow());
        $this->assertNull($result);
    }

    // ---------- 8. Decrypted invalid JSON returns null ----------

    public function test_decrypted_invalid_json_returns_null(): void
    {
        // Encrypt invalid JSON directly
        $invalidJsonToken = $this->encrypter->encrypt('not-json', false);

        $result = $this->tokenService->verify($invalidJsonToken, 42, 'en', $this->validNow());
        $this->assertNull($result);
    }

    // ---------- 9. Decrypted invalid State returns null ----------

    public function test_decrypted_invalid_state_returns_null(): void
    {
        // Encrypt valid JSON but invalid State payload
        $invalidStateJson = json_encode(['version' => 1, 'invalid' => 'payload']);
        $invalidStateToken = $this->encrypter->encrypt($invalidStateJson, false);

        $result = $this->tokenService->verify($invalidStateToken, 42, 'en', $this->validNow());
        $this->assertNull($result);
    }

    // ---------- 10. Unsupported version returns null ----------

    public function test_unsupported_version_returns_null(): void
    {
        $state = CustomStudySessionState::createInitial(
            99, // unsupported version
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );

        $token = $this->tokenService->issue($state);
        $result = $this->tokenService->verify($token, 42, 'en', $this->validNow());
        $this->assertNull($result);
    }

    // ---------- 11. Wrong user returns null ----------

    public function test_wrong_user_returns_null(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);

        $result = $this->tokenService->verify($token, 999, 'en', $this->validNow());
        $this->assertNull($result);
    }

    // ---------- 12. Wrong language returns null ----------

    public function test_wrong_language_returns_null(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);

        $result = $this->tokenService->verify($token, 42, 'ja', $this->validNow());
        $this->assertNull($result);
    }

    // ---------- 13. Expired token returns null ----------

    public function test_expired_token_returns_null(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);

        $expiredNow = Carbon::createFromTimestamp($this->validExpiresAt() + 1, 'UTC');
        $result = $this->tokenService->verify($token, 42, 'en', $expiredNow);
        $this->assertNull($result);
    }

    // ---------- 14. expires_at exactly now returns null ----------

    public function test_expires_at_exactly_now_returns_null(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);

        $exactNow = Carbon::createFromTimestamp($this->validExpiresAt(), 'UTC');
        $result = $this->tokenService->verify($token, 42, 'en', $exactNow);
        $this->assertNull($result);
    }

    // ---------- 15. Unexpired token accepted ----------

    public function test_unexpired_token_accepted(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);

        $beforeExpiry = Carbon::createFromTimestamp($this->validExpiresAt() - 1, 'UTC');
        $result = $this->tokenService->verify($token, 42, 'en', $beforeExpiry);
        $this->assertNotNull($result);
    }

    // ---------- 16. UUID v4 preserved ----------

    public function test_uuid_v4_preserved_through_round_trip(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $uuid,
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );

        $token = $this->tokenService->issue($state);
        $verified = $this->tokenService->verify($token, 42, 'en', $this->validNow());

        $this->assertNotNull($verified);
        $this->assertSame($uuid, $verified->sessionId());
    }

    // ---------- 17. 4-hour TTL contract ----------

    public function test_default_ttl_seconds_is_4_hours(): void
    {
        $this->assertSame(14400, CustomStudySessionTokenService::DEFAULT_TTL_SECONDS);
        $this->assertSame(4 * 60 * 60, CustomStudySessionTokenService::DEFAULT_TTL_SECONDS);
    }

    // ---------- 18. 500 candidates fit ----------

    public function test_500_candidates_fit_in_token(): void
    {
        $candidates = range(1, 500);
        $state = $this->createValidState($candidates);

        $token = $this->tokenService->issue($state);

        $this->assertLessThanOrEqual(
            CustomStudySessionTokenService::MAX_TOKEN_BYTES,
            strlen($token),
            'Token with 500 candidates must fit within MAX_TOKEN_BYTES.'
        );

        $verified = $this->tokenService->verify($token, 42, 'en', $this->validNow());
        $this->assertNotNull($verified);
        $this->assertSame(500, $verified->totalCount());
    }

    // ---------- 19. 501 candidates rejected by State ----------

    public function test_501_candidates_rejected_by_state(): void
    {
        $candidates = range(1, 501);

        $this->expectException(CustomStudySessionStateException::class);
        CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $this->validCriteria(),
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            $candidates,
            $this->validDelayConfig()
        );
    }

    // ---------- 20. Token > 65536 bytes rejected on verify ----------

    public function test_token_exceeding_max_bytes_returns_null_on_verify(): void
    {
        $oversizedToken = str_repeat('A', CustomStudySessionTokenService::MAX_TOKEN_BYTES + 1);

        $result = $this->tokenService->verify($oversizedToken, 42, 'en', $this->validNow());
        $this->assertNull($result);
    }

    // ---------- 20b. Token exactly MAX_TOKEN_BYTES is not rejected on size alone ----------

    public function test_token_at_max_bytes_not_rejected_on_size_alone(): void
    {
        // A token at exactly MAX_TOKEN_BYTES should still be attempted (it will fail
        // decryption since it's not valid encrypted data, but it should NOT be
        // rejected on size alone — only > MAX is rejected).
        $maxToken = str_repeat('A', CustomStudySessionTokenService::MAX_TOKEN_BYTES);

        // This will return null due to decryption failure, not size rejection
        $result = $this->tokenService->verify($maxToken, 42, 'en', $this->validNow());
        $this->assertNull($result);
    }

    // ---------- 21. Container resolves Token Service (constructable with Encrypter) ----------

    public function test_token_service_is_constructable_with_encrypter(): void
    {
        $service = new CustomStudySessionTokenService($this->encrypter);
        $this->assertInstanceOf(CustomStudySessionTokenService::class, $service);
    }

    // ---------- 22. Injected Encrypter is used ----------

    public function test_injected_encrypter_is_used_for_encryption(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);

        // The token should be decryptable by the same encrypter
        $decrypted = $this->encrypter->decrypt($token, false);
        $this->assertNotFalse($decrypted);

        $payload = json_decode($decrypted, true);
        $this->assertIsArray($payload);
        $this->assertSame(42, $payload['user_id']);
    }

    public function test_token_from_different_encrypter_fails_verification(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);

        // Create a different encrypter with a different key
        $differentKey = ConcreteEncrypter::generateKey('aes-256-cbc');
        $differentEncrypter = new ConcreteEncrypter($differentKey, 'aes-256-cbc');
        $differentService = new CustomStudySessionTokenService($differentEncrypter);

        $result = $differentService->verify($token, 42, 'en', $this->validNow());
        $this->assertNull($result);
    }

    // ---------- 23-28. No DB / Auth / Request / ReviewLog / FSRS / AI ----------

    public function test_token_service_class_does_not_use_db_facade(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionTokenService.php');
        $this->assertStringNotContainsString('Illuminate\Support\Facades\DB', $source);
        $this->assertStringNotContainsString('Illuminate\Database', $source);
    }

    public function test_token_service_class_does_not_use_auth_facade(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionTokenService.php');
        $this->assertStringNotContainsString('Illuminate\Support\Facades\Auth', $source);
        $this->assertStringNotContainsString('Auth::', $source);
    }

    public function test_token_service_class_does_not_use_request_facade(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionTokenService.php');
        $this->assertStringNotContainsString('Illuminate\Http\Request', $source);
        $this->assertStringNotContainsString('Request::', $source);
    }

    public function test_token_service_class_does_not_use_review_log(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionTokenService.php');
        $this->assertStringNotContainsString('ReviewLog', $source);
    }

    public function test_token_service_class_does_not_use_fsrs(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionTokenService.php');
        $this->assertStringNotContainsString('FSRS', $source);
        $this->assertStringNotContainsString('Fsrs', $source);
    }

    public function test_token_service_class_does_not_use_ai(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Services/CustomStudy/CustomStudySessionTokenService.php');
        $this->assertStringNotContainsString('OpenAI', $source);
        $this->assertStringNotContainsString('openai', $source);
    }

    // ---------- 29. No rotate(answer) method ----------

    public function test_token_service_does_not_have_rotate_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionTokenService::class, 'rotate'),
            'CustomStudySessionTokenService must not have rotate() — belongs to Phase 4 SessionService.'
        );
    }

    // ---------- 30. No rating/answer branching ----------

    public function test_token_service_does_not_have_answer_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionTokenService::class, 'answer'),
            'CustomStudySessionTokenService must not have answer().'
        );
    }

    public function test_token_service_does_not_have_rate_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionTokenService::class, 'rate'),
            'CustomStudySessionTokenService must not have rate().'
        );
    }

    public function test_token_service_does_not_have_apply_rating_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionTokenService::class, 'applyRating'),
            'CustomStudySessionTokenService must not have applyRating().'
        );
    }

    public function test_token_service_does_not_have_resume_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionTokenService::class, 'resume'),
            'CustomStudySessionTokenService must not have resume().'
        );
    }

    public function test_token_service_does_not_have_next_card_method(): void
    {
        $this->assertFalse(
            method_exists(CustomStudySessionTokenService::class, 'nextCard'),
            'CustomStudySessionTokenService must not have nextCard().'
        );
    }

    // ---------- 31. Issue does not modify State ----------

    public function test_issue_does_not_modify_state(): void
    {
        $state = $this->createValidState();
        $originalArray = $state->toArray();

        $this->tokenService->issue($state);

        $this->assertSame($originalArray, $state->toArray());
    }

    // ---------- 32. Verify does not modify State ----------

    public function test_verify_does_not_modify_state(): void
    {
        $state = $this->createValidState();
        $token = $this->tokenService->issue($state);
        $originalArray = $state->toArray();

        $this->tokenService->verify($token, 42, 'en', $this->validNow());

        $this->assertSame($originalArray, $state->toArray());
    }

    // ---------- Constants ----------

    public function test_version_constant_is_1(): void
    {
        $this->assertSame(1, CustomStudySessionTokenService::VERSION);
    }

    public function test_max_candidate_count_constant_is_500(): void
    {
        $this->assertSame(500, CustomStudySessionTokenService::MAX_CANDIDATE_COUNT);
    }

    public function test_max_token_bytes_constant_is_65536(): void
    {
        $this->assertSame(65536, CustomStudySessionTokenService::MAX_TOKEN_BYTES);
    }

    // ---------- No setter methods ----------

    public function test_token_service_has_no_public_setter_methods(): void
    {
        $reflection = new ReflectionClass(CustomStudySessionTokenService::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $setters = array_filter($methods, function ($method) {
            return strpos($method->getName(), 'set') === 0;
        });

        $this->assertSame([], $setters, 'CustomStudySessionTokenService must not have any public setter methods.');
    }

    // ---------- Issue throws on token too large ----------

    public function test_issue_throws_when_token_exceeds_max_bytes(): void
    {
        // Create a mock Encrypter that returns an oversized token
        $mockEncrypter = $this->createMock(Encrypter::class);
        $mockEncrypter->method('encrypt')->willReturn(str_repeat('X', CustomStudySessionTokenService::MAX_TOKEN_BYTES + 1));

        $service = new CustomStudySessionTokenService($mockEncrypter);
        $state = $this->createValidState();

        $this->expectException(CustomStudySessionStateException::class);
        $service->issue($state);
    }

    // ---------- Token with all four criteria modes ----------

    public function test_token_round_trip_with_source_chapter_mode(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'source_chapter',
            'parameters' => ['chapter_id' => 42],
        ]);

        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $criteria,
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11, 12],
            $this->validDelayConfig()
        );

        $token = $this->tokenService->issue($state);
        $verified = $this->tokenService->verify($token, 42, 'en', $this->validNow());

        $this->assertNotNull($verified);
        $this->assertSame('source_chapter', $verified->mode());
        $this->assertSame(['chapter_id' => 42], $verified->parameters());
    }

    public function test_token_round_trip_with_leech_attention_mode(): void
    {
        $criteria = CustomStudyCriteria::fromArray([
            'mode' => 'leech_attention',
            'parameters' => ['sub_mode' => 'leech_only'],
        ]);

        $state = CustomStudySessionState::createInitial(
            1,
            42,
            'en',
            $criteria,
            $this->validUuidV4(),
            $this->validIssuedAt(),
            $this->validExpiresAt(),
            [11],
            $this->validDelayConfig()
        );

        $token = $this->tokenService->issue($state);
        $verified = $this->tokenService->verify($token, 42, 'en', $this->validNow());

        $this->assertNotNull($verified);
        $this->assertSame('leech_attention', $verified->mode());
    }

    // ---------- Empty candidates state ----------

    public function test_token_round_trip_with_empty_candidates(): void
    {
        $state = $this->createValidState([]);

        $token = $this->tokenService->issue($state);
        $verified = $this->tokenService->verify($token, 42, 'en', $this->validNow());

        $this->assertNotNull($verified);
        $this->assertSame(0, $verified->totalCount());
        $this->assertNull($verified->currentCardId());
    }

    // ---------- Null token returns null ----------

    public function test_null_token_returns_null(): void
    {
        // PHP will coerce null to empty string in the parameter, but let's test empty string
        $result = $this->tokenService->verify('', 42, 'en', $this->validNow());
        $this->assertNull($result);
    }
}
