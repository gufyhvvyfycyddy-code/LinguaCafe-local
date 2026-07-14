<?php

namespace App\Services\CustomStudy;

use App\Exceptions\CustomStudySessionStateException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use JsonException;

/**
 * Encrypts, decrypts, and verifies Custom Study preview-session tokens.
 *
 * The Token Service is the ONLY component that touches encryption. It:
 * 1. Serializes a valid CustomStudySessionState to JSON.
 * 2. Encrypts the JSON using Laravel's Encrypter.
 * 3. Returns an opaque token string.
 * 4. On verify: decrypts, JSON-decodes, reconstructs State, and validates
 *    version / user / language / expiry.
 *
 * The Token Service does NOT:
 * - apply ratings or answers (Phase 4 PreviewPolicy).
 * - rotate tokens (Phase 4 SessionService calls issue() with a new State).
 * - query the database, use Auth, read the Request, write review logs,
 *   run spaced-repetition scheduling, or call AI services.
 * - know about card content, sense text, or user email.
 *
 * Task 2000-19 — Custom Study 1A Phase 3A.
 */
class CustomStudySessionTokenService
{
    public const VERSION = 1;
    public const MAX_CANDIDATE_COUNT = 500;
    public const MAX_TOKEN_BYTES = 65536;
    public const DEFAULT_TTL_SECONDS = 14400;

    public function __construct(
        private readonly Encrypter $encrypter
    ) {
    }

    /**
     * Issues an opaque encrypted token for the given session state.
     *
     * The token contains the full state payload encrypted with Laravel's
     * Encrypter. No plaintext JSON, card content, or user email appears in
     * the token. The token size is checked against MAX_TOKEN_BYTES.
     *
     * @throws CustomStudySessionStateException If the encrypted token exceeds MAX_TOKEN_BYTES.
     * @throws JsonException If the state cannot be serialized to JSON.
     */
    public function issue(CustomStudySessionState $state): string
    {
        $json = json_encode($state->toArray(), JSON_THROW_ON_ERROR);
        $token = $this->encrypter->encrypt($json, false);

        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new CustomStudySessionStateException(
                'token_too_large',
                'Encrypted token exceeds maximum allowed size of ' . self::MAX_TOKEN_BYTES . ' bytes.'
            );
        }

        return $token;
    }

    /**
     * Verifies an encrypted token and returns the reconstructed session state.
     *
     * Returns null on ANY failure — tampered token, decryption error, invalid
     * JSON, invalid state, unsupported version, wrong user, wrong language,
     * or expired token. The specific failure reason is NOT exposed to prevent
     * information leakage.
     *
     * @param string $token The opaque token from issue().
     * @param int $expectedUserId The authenticated user's ID (trusted, from caller).
     * @param string $expectedLanguage The current language (trusted, from caller).
     * @param Carbon $now The current time (for expiry check).
     * @return CustomStudySessionState|null The verified state, or null on any failure.
     */
    public function verify(
        string $token,
        int $expectedUserId,
        string $expectedLanguage,
        Carbon $now
    ): ?CustomStudySessionState {
        // Reject empty or oversized tokens immediately.
        if ($token === '' || strlen($token) > self::MAX_TOKEN_BYTES) {
            return null;
        }

        // Decrypt — any decryption failure returns null.
        try {
            $json = $this->encrypter->decrypt($token, false);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_string($json)) {
            return null;
        }

        // JSON decode — any parse failure returns null.
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        // Reconstruct State — any invariant violation returns null.
        try {
            $state = CustomStudySessionState::fromArray($payload);
        } catch (CustomStudySessionStateException $e) {
            return null;
        }

        // Version check — only V1 is supported.
        if ($state->version() !== self::VERSION) {
            return null;
        }

        // User binding — token must match the authenticated user.
        if ($state->userId() !== $expectedUserId) {
            return null;
        }

        // Language binding — token must match the current language.
        if ($state->language() !== $expectedLanguage) {
            return null;
        }

        // Expiry check — expires_at <= now means expired (boundary is exclusive).
        if ($state->expiresAt() <= $now->getTimestamp()) {
            return null;
        }

        return $state;
    }
}
