<?php

namespace App\Services\CustomStudy;

use App\Exceptions\CustomStudySessionStateException;
use App\Exceptions\CustomStudyValidationException;

/**
 * Immutable value object holding the full Custom Study preview-session state.
 *
 * Pure value object — NO DB, NO Auth, NO Request, NO Crypt, NO review-log writes,
 * NO spaced-repetition scheduling, NO AI, NO rating/answer/resume/nextCard/transition/rotate logic.
 *
 * Holds the complete V1 session payload: version, user_id, language, mode,
 * parameters, session_id, issued_at, expires_at, ordered_candidate_ids,
 * ready_queue, delayed_repeat_queue, completed_ids, skipped_ineligible_ids,
 * completed_count, total_count, current_card_id, step, preview_delay_config,
 * available_candidate_count.
 *
 * The five-state union + mutual exclusion invariants (current / ready / delayed /
 * completed / skipped_ineligible) are fully verifiable from the state alone.
 * `completed_count` is redundant by construction (=== count(completed_ids)).
 * `total_count` is redundant by construction (=== count(ordered_candidate_ids)).
 * `available_candidate_count` records the pre-card_limit-truncation total
 * (invariant 16: >= 0; invariant 17: >= total_count; immutable after creation).
 *
 * Task 2000-19 — Custom Study 1A Phase 3A.
 * Task 2000-22 — Phase 4B (available_candidate_count + withEligibilityResolution).
 */
class CustomStudySessionState
{
    public const VERSION = 1;
    public const MAX_CANDIDATE_COUNT = 500;

    /** @var list<string> */
    private const REQUIRED_DELAY_KEYS = ['again_secs', 'hard_secs', 'good_secs', 'easy_secs'];

    // Use private readonly properties + public getters to guarantee immutability
    // (arrays are stored as copies, returned as copies — no external mutation possible).
    private readonly int $version;
    private readonly int $userId;
    private readonly string $language;
    private readonly string $mode;
    /** @var array<string, mixed> */
    private readonly array $parameters;
    private readonly string $sessionId;
    private readonly int $issuedAt;
    private readonly int $expiresAt;
    /** @var list<int> */
    private readonly array $orderedCandidateIds;
    /** @var list<int> */
    private readonly array $readyQueue;
    /** @var list<array{card_id: int, available_at: int}> */
    private readonly array $delayedRepeatQueue;
    /** @var list<int> */
    private readonly array $completedIds;
    /** @var list<int> */
    private readonly array $skippedIneligibleIds;
    private readonly int $completedCount;
    private readonly int $totalCount;
    private readonly ?int $currentCardId;
    private readonly int $step;
    /** @var array{again_secs: int, hard_secs: int, good_secs: int, easy_secs: int} */
    private readonly array $previewDelayConfig;
    /**
     * Total number of eligible candidate cards BEFORE card_limit truncation.
     * Invariant 16: >= 0, immutable after creation.
     * Invariant 17: >= total_count (= count(ordered_candidate_ids)).
     *
     * Task 2000-22 — Phase 4B.
     */
    private readonly int $availableCandidateCount;

    /**
     * Private constructor — use createInitial() or fromArray().
     *
     * @param array<string, mixed> $parameters
     * @param list<int> $orderedCandidateIds
     * @param list<int> $readyQueue
     * @param list<array{card_id: int, available_at: int}> $delayedRepeatQueue
     * @param list<int> $completedIds
     * @param list<int> $skippedIneligibleIds
     * @param array{again_secs: int, hard_secs: int, good_secs: int, easy_secs: int} $previewDelayConfig
     */
    private function __construct(
        int $version,
        int $userId,
        string $language,
        string $mode,
        array $parameters,
        string $sessionId,
        int $issuedAt,
        int $expiresAt,
        array $orderedCandidateIds,
        array $readyQueue,
        array $delayedRepeatQueue,
        array $completedIds,
        array $skippedIneligibleIds,
        int $completedCount,
        int $totalCount,
        ?int $currentCardId,
        int $step,
        array $previewDelayConfig,
        int $availableCandidateCount
    ) {
        $this->version = $version;
        $this->userId = $userId;
        $this->language = $language;
        $this->mode = $mode;
        $this->parameters = $parameters;
        $this->sessionId = $sessionId;
        $this->issuedAt = $issuedAt;
        $this->expiresAt = $expiresAt;
        $this->orderedCandidateIds = $orderedCandidateIds;
        $this->readyQueue = $readyQueue;
        $this->delayedRepeatQueue = $delayedRepeatQueue;
        $this->completedIds = $completedIds;
        $this->skippedIneligibleIds = $skippedIneligibleIds;
        $this->completedCount = $completedCount;
        $this->totalCount = $totalCount;
        $this->currentCardId = $currentCardId;
        $this->step = $step;
        $this->previewDelayConfig = $previewDelayConfig;
        $this->availableCandidateCount = $availableCandidateCount;
    }

    /**
     * Creates the initial session state from a freshly-ordered candidate list.
     *
     * The first candidate becomes current_card_id; the rest go into ready_queue.
     * delayed_repeat_queue, completed_ids, skipped_ineligible_ids are empty.
     * step = 0, completed_count = 0, total_count = count(orderedCandidateIds).
     *
     * @param list<int> $orderedCandidateIds
     * @param array{again_secs: int, hard_secs: int, good_secs: int, easy_secs: int} $previewDelayConfig
     * @throws CustomStudySessionStateException If any field is invalid.
     */
    public static function createInitial(
        int $version,
        int $userId,
        string $language,
        CustomStudyCriteria $criteria,
        string $sessionId,
        int $issuedAt,
        int $expiresAt,
        array $orderedCandidateIds,
        array $previewDelayConfig,
        ?int $availableCandidateCount = null
    ): self {
        // Validate scalar fields
        self::validateVersion($version);
        self::validateUserId($userId);
        $language = self::validateLanguage($language);
        self::validateSessionId($sessionId);
        self::validateTimestamps($issuedAt, $expiresAt);
        self::validatePreviewDelayConfig($previewDelayConfig);

        // Validate candidate IDs
        $orderedCandidateIds = self::validateCandidateIds($orderedCandidateIds);

        // Default available_candidate_count to total_count when not provided
        // (no card_limit truncation occurred).
        $totalCount = count($orderedCandidateIds);
        if ($availableCandidateCount === null) {
            $availableCandidateCount = $totalCount;
        }
        self::validateAvailableCandidateCount($availableCandidateCount, $totalCount);

        // Build initial state
        $mode = $criteria->mode();
        $parameters = $criteria->parameters();

        if (empty($orderedCandidateIds)) {
            $currentCardId = null;
            $readyQueue = [];
        } else {
            $currentCardId = $orderedCandidateIds[0];
            $readyQueue = array_values(array_slice($orderedCandidateIds, 1));
        }

        return new self(
            $version,
            $userId,
            $language,
            $mode,
            $parameters,
            $sessionId,
            $issuedAt,
            $expiresAt,
            $orderedCandidateIds,
            $readyQueue,
            [],
            [],
            [],
            0,
            $totalCount,
            $currentCardId,
            0,
            $previewDelayConfig,
            $availableCandidateCount
        );
    }

    /**
     * Reconstructs a session state from a payload array (e.g. decrypted token).
     *
     * Validates ALL invariants: scalar fields, candidate IDs, five-state union +
     * mutual exclusion, completed_count consistency, total_count consistency,
     * step, preview_delay_config.
     *
     * @param array<string, mixed> $payload
     * @throws CustomStudySessionStateException If any invariant is violated.
     */
    public static function fromArray(array $payload): self
    {
        // Validate scalar fields
        $version = self::requireIntKey($payload, 'version');
        self::validateVersion($version);

        $userId = self::requireIntKey($payload, 'user_id');
        self::validateUserId($userId);

        $language = self::requireStringKey($payload, 'language');
        $language = self::validateLanguage($language);

        $sessionId = self::requireStringKey($payload, 'session_id');
        self::validateSessionId($sessionId);

        $issuedAt = self::requireIntKey($payload, 'issued_at');
        $expiresAt = self::requireIntKey($payload, 'expires_at');
        self::validateTimestamps($issuedAt, $expiresAt);

        // Validate mode + parameters
        $mode = self::requireStringKey($payload, 'mode');
        self::validateMode($mode);

        $parameters = self::requireArrayKey($payload, 'parameters');
        self::validateParametersForMode($mode, $parameters);

        // Validate candidate IDs
        $orderedCandidateIds = self::requireArrayKey($payload, 'ordered_candidate_ids');
        $orderedCandidateIds = self::validateCandidateIds($orderedCandidateIds);

        // Validate ready_queue
        $readyQueue = self::requireArrayKey($payload, 'ready_queue');
        $readyQueue = self::validateIdList($readyQueue, 'ready_queue');

        // Validate delayed_repeat_queue
        $delayedRepeatQueue = self::requireArrayKey($payload, 'delayed_repeat_queue');
        $delayedRepeatQueue = self::validateDelayedRepeatQueue($delayedRepeatQueue);

        // Validate completed_ids
        $completedIds = self::requireArrayKey($payload, 'completed_ids');
        $completedIds = self::validateIdList($completedIds, 'completed_ids');

        // Validate skipped_ineligible_ids
        $skippedIneligibleIds = self::requireArrayKey($payload, 'skipped_ineligible_ids');
        $skippedIneligibleIds = self::validateIdList($skippedIneligibleIds, 'skipped_ineligible_ids');

        // Validate current_card_id
        $currentCardId = $payload['current_card_id'] ?? null;
        if ($currentCardId !== null && !is_int($currentCardId)) {
            throw new CustomStudySessionStateException(
                'invalid_current_card_id',
                'current_card_id must be an integer or null.'
            );
        }

        // Validate completed_count
        $completedCount = self::requireIntKey($payload, 'completed_count');
        if ($completedCount < 0) {
            throw new CustomStudySessionStateException(
                'invalid_completed_count',
                'completed_count must be a non-negative integer.'
            );
        }

        // Validate total_count
        $totalCount = self::requireIntKey($payload, 'total_count');
        if ($totalCount < 0) {
            throw new CustomStudySessionStateException(
                'invalid_total_count',
                'total_count must be a non-negative integer.'
            );
        }

        // Validate step
        $step = self::requireIntKey($payload, 'step');
        if ($step < 0) {
            throw new CustomStudySessionStateException(
                'invalid_step',
                'step must be a non-negative integer.'
            );
        }

        // Validate preview_delay_config
        $previewDelayConfig = self::requireArrayKey($payload, 'preview_delay_config');
        self::validatePreviewDelayConfig($previewDelayConfig);

        // Validate available_candidate_count (optional key — defaults to total_count
        // for backward compat with tokens issued before the field was added).
        $availableCandidateCount = $totalCount;
        if (array_key_exists('available_candidate_count', $payload)) {
            $availableCandidateCount = $payload['available_candidate_count'];
            if (!is_int($availableCandidateCount)) {
                throw new CustomStudySessionStateException(
                    'invalid_available_candidate_count',
                    'available_candidate_count must be an integer.'
                );
            }
        }
        self::validateAvailableCandidateCount($availableCandidateCount, $totalCount);

        // Cross-field invariants
        self::validateFiveStateInvariants(
            $orderedCandidateIds,
            $currentCardId,
            $readyQueue,
            $delayedRepeatQueue,
            $completedIds,
            $skippedIneligibleIds
        );

        // completed_count === count(completed_ids)
        if ($completedCount !== count($completedIds)) {
            throw new CustomStudySessionStateException(
                'completed_count_mismatch',
                'completed_count must equal count(completed_ids).'
            );
        }

        // total_count === count(ordered_candidate_ids)
        if ($totalCount !== count($orderedCandidateIds)) {
            throw new CustomStudySessionStateException(
                'total_count_mismatch',
                'total_count must equal count(ordered_candidate_ids).'
            );
        }

        return new self(
            $version,
            $userId,
            $language,
            $mode,
            $parameters,
            $sessionId,
            $issuedAt,
            $expiresAt,
            $orderedCandidateIds,
            $readyQueue,
            $delayedRepeatQueue,
            $completedIds,
            $skippedIneligibleIds,
            $completedCount,
            $totalCount,
            $currentCardId,
            $step,
            $previewDelayConfig,
            $availableCandidateCount
        );
    }

    /**
     * Serializes the state to a plain array for token encryption.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'user_id' => $this->userId,
            'language' => $this->language,
            'mode' => $this->mode,
            'parameters' => $this->parameters,
            'session_id' => $this->sessionId,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'ordered_candidate_ids' => array_values($this->orderedCandidateIds),
            'ready_queue' => array_values($this->readyQueue),
            'delayed_repeat_queue' => array_map(fn ($item) => [
                'card_id' => $item['card_id'],
                'available_at' => $item['available_at'],
            ], $this->delayedRepeatQueue),
            'completed_ids' => array_values($this->completedIds),
            'skipped_ineligible_ids' => array_values($this->skippedIneligibleIds),
            'completed_count' => $this->completedCount,
            'total_count' => $this->totalCount,
            'current_card_id' => $this->currentCardId,
            'step' => $this->step,
            'preview_delay_config' => [
                'again_secs' => $this->previewDelayConfig['again_secs'],
                'hard_secs' => $this->previewDelayConfig['hard_secs'],
                'good_secs' => $this->previewDelayConfig['good_secs'],
                'easy_secs' => $this->previewDelayConfig['easy_secs'],
            ],
            'available_candidate_count' => $this->availableCandidateCount,
        ];
    }

    // ---------- Read-only getters ----------

    public function version(): int
    {
        return $this->version;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function criteria(): CustomStudyCriteria
    {
        return CustomStudyCriteria::fromArray([
            'mode' => $this->mode,
            'parameters' => $this->parameters,
        ]);
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function issuedAt(): int
    {
        return $this->issuedAt;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    /** @return list<int> */
    public function orderedCandidateIds(): array
    {
        return array_values($this->orderedCandidateIds);
    }

    /** @return list<int> */
    public function readyQueue(): array
    {
        return array_values($this->readyQueue);
    }

    /** @return list<array{card_id: int, available_at: int}> */
    public function delayedRepeatQueue(): array
    {
        return array_map(fn ($item) => [
            'card_id' => $item['card_id'],
            'available_at' => $item['available_at'],
        ], $this->delayedRepeatQueue);
    }

    /** @return list<int> */
    public function completedIds(): array
    {
        return array_values($this->completedIds);
    }

    /** @return list<int> */
    public function skippedIneligibleIds(): array
    {
        return array_values($this->skippedIneligibleIds);
    }

    public function completedCount(): int
    {
        return $this->completedCount;
    }

    public function totalCount(): int
    {
        return $this->totalCount;
    }

    public function currentCardId(): ?int
    {
        return $this->currentCardId;
    }

    public function step(): int
    {
        return $this->step;
    }

    /** @return array{again_secs: int, hard_secs: int, good_secs: int, easy_secs: int} */
    public function previewDelayConfig(): array
    {
        return [
            'again_secs' => $this->previewDelayConfig['again_secs'],
            'hard_secs' => $this->previewDelayConfig['hard_secs'],
            'good_secs' => $this->previewDelayConfig['good_secs'],
            'easy_secs' => $this->previewDelayConfig['easy_secs'],
        ];
    }

    /**
     * Returns the total number of eligible candidates BEFORE card_limit truncation.
     *
     * Invariant 16: >= 0, immutable after creation.
     * Invariant 17: >= total_count.
     *
     * Task 2000-22 — Phase 4B.
     */
    public function availableCandidateCount(): int
    {
        return $this->availableCandidateCount;
    }

    // ---------- Phase 3B: immutable progress boundary ----------

    /**
     * Returns a new immutable state with updated progress fields.
     *
     * Identity fields (version, user_id, language, mode, parameters, session_id,
     * issued_at, expires_at, ordered_candidate_ids, preview_delay_config,
     * available_candidate_count) are preserved from the current state. Only the
     * five progress fields are taken from the caller. completed_count,
     * total_count, and step are auto-computed — the caller cannot set them.
     *
     * The original state is NOT mutated (immutable value object semantics).
     *
     * This is the only sanctioned way for the future CustomStudyPreviewPolicy
     * to produce a new state. Policy MUST NOT call toArray() / fromArray() to
     * mutate the payload string-keyed representation.
     *
     * Task 2000-20 — Custom Study 1A Phase 3B.
     *
     * @param list<int> $readyQueue
     * @param list<array{card_id: int, available_at: int}> $delayedRepeatQueue
     * @param list<int> $completedIds
     * @param list<int> $skippedIneligibleIds
     * @throws CustomStudySessionStateException If step would overflow, or any
     *     five-state invariant (mutual exclusion / union completeness / no
     *     unknown IDs / no lost ordered IDs) is violated.
     */
    public function withProgress(
        ?int $currentCardId,
        array $readyQueue,
        array $delayedRepeatQueue,
        array $completedIds,
        array $skippedIneligibleIds
    ): self {
        return $this->copyWithProgress(
            $currentCardId,
            $readyQueue,
            $delayedRepeatQueue,
            $completedIds,
            $skippedIneligibleIds,
            true
        );
    }

    /**
     * Returns a new immutable state with updated five-state fields but WITHOUT
     * incrementing step.
     *
     * This is the same-step eligibility resolution boundary: the eligibility
     * service may move a card from ready/delayed to skipped_ineligible without
     * counting it as a user-visible step. All identity fields (including
     * available_candidate_count and step) are preserved from the current state.
     *
     * Invariant 18: withEligibilityResolution() is a same-step immutable copy.
     * It reuses the same private helper as withProgress() (with incrementStep=false)
     * so there is exactly one validation path for the five-state invariants.
     *
     * Task 2000-22 — Phase 4B.
     *
     * @param list<int> $readyQueue
     * @param list<array{card_id: int, available_at: int}> $delayedRepeatQueue
     * @param list<int> $completedIds
     * @param list<int> $skippedIneligibleIds
     * @throws CustomStudySessionStateException If any five-state invariant is
     *     violated.
     */
    public function withEligibilityResolution(
        ?int $currentCardId,
        array $readyQueue,
        array $delayedRepeatQueue,
        array $completedIds,
        array $skippedIneligibleIds
    ): self {
        return $this->copyWithProgress(
            $currentCardId,
            $readyQueue,
            $delayedRepeatQueue,
            $completedIds,
            $skippedIneligibleIds,
            false
        );
    }

    /**
     * Shared private helper for withProgress() and withEligibilityResolution().
     *
     * Both methods perform the same five-state validation and identity
     * preservation; the only difference is whether step is incremented.
     * Having a single helper ensures there is exactly one validation path
     * (invariant 18: "复用 private helper 禁止两套验证").
     *
     * @param list<int> $readyQueue
     * @param list<array{card_id: int, available_at: int}> $delayedRepeatQueue
     * @param list<int> $completedIds
     * @param list<int> $skippedIneligibleIds
     * @param bool $incrementStep True for withProgress(), false for
     *     withEligibilityResolution().
     */
    private function copyWithProgress(
        ?int $currentCardId,
        array $readyQueue,
        array $delayedRepeatQueue,
        array $completedIds,
        array $skippedIneligibleIds,
        bool $incrementStep
    ): self {
        // Guard against integer overflow on step — only relevant when incrementing.
        if ($incrementStep && $this->step === PHP_INT_MAX) {
            throw new CustomStudySessionStateException(
                'step_overflow',
                'step would overflow PHP_INT_MAX; cannot increment further.'
            );
        }

        // Normalize + validate the five progress fields using the same
        // validators that fromArray() uses.
        $readyQueue = self::validateIdList($readyQueue, 'ready_queue');
        $delayedRepeatQueue = self::validateDelayedRepeatQueue($delayedRepeatQueue);
        $completedIds = self::validateIdList($completedIds, 'completed_ids');
        $skippedIneligibleIds = self::validateIdList($skippedIneligibleIds, 'skipped_ineligible_ids');

        // Validate current_card_id type if non-null.
        if ($currentCardId !== null && !is_int($currentCardId)) {
            throw new CustomStudySessionStateException(
                'invalid_current_card_id',
                'current_card_id must be an integer or null.'
            );
        }

        // Run the full five-state invariants check (mutual exclusion + union
        // completeness + no unknown IDs + no lost ordered IDs).
        self::validateFiveStateInvariants(
            $this->orderedCandidateIds,
            $currentCardId,
            $readyQueue,
            $delayedRepeatQueue,
            $completedIds,
            $skippedIneligibleIds
        );

        // Auto-compute the derived fields — caller cannot supply these.
        $newCompletedCount = count($completedIds);
        $newTotalCount = count($this->orderedCandidateIds);
        $newStep = $incrementStep ? $this->step + 1 : $this->step;

        return new self(
            $this->version,
            $this->userId,
            $this->language,
            $this->mode,
            $this->parameters,
            $this->sessionId,
            $this->issuedAt,
            $this->expiresAt,
            $this->orderedCandidateIds,
            $readyQueue,
            $delayedRepeatQueue,
            $completedIds,
            $skippedIneligibleIds,
            $newCompletedCount,
            $newTotalCount,
            $currentCardId,
            $newStep,
            $this->previewDelayConfig,
            $this->availableCandidateCount
        );
    }

    /**
     * Returns the earliest available_at timestamp in the delayed_repeat_queue,
     * or null if the delayed queue is empty.
     *
     * Pure derived query — does NOT mutate the delayed queue.
     *
     * Task 2000-20 — Custom Study 1A Phase 3B.
     */
    public function waitUntil(): ?int
    {
        if (empty($this->delayedRepeatQueue)) {
            return null;
        }
        $earliest = PHP_INT_MAX;
        foreach ($this->delayedRepeatQueue as $entry) {
            if ($entry['available_at'] < $earliest) {
                $earliest = $entry['available_at'];
            }
        }
        return $earliest;
    }

    /**
     * Returns true when the session has no more active cards to show.
     *
     * True iff: current_card_id === null AND ready_queue empty AND
     * delayed_repeat_queue empty. completed_ids and skipped_ineligible_ids may
     * be non-empty (they represent cards already resolved).
     *
     * Task 2000-20 — Custom Study 1A Phase 3B.
     */
    public function isCompleted(): bool
    {
        return $this->currentCardId === null
            && empty($this->readyQueue)
            && empty($this->delayedRepeatQueue);
    }

    // ---------- Validation helpers ----------

    private static function validateVersion(int $version): void
    {
        if ($version < 1) {
            throw new CustomStudySessionStateException(
                'invalid_version',
                'version must be a positive integer.'
            );
        }
    }

    private static function validateUserId(int $userId): void
    {
        if ($userId < 1) {
            throw new CustomStudySessionStateException(
                'invalid_user_id',
                'user_id must be a positive integer.'
            );
        }
    }

    private static function validateLanguage(string $language): string
    {
        $trimmed = trim($language);
        if ($trimmed === '') {
            throw new CustomStudySessionStateException(
                'invalid_language',
                'language must be a non-empty string.'
            );
        }
        return $trimmed;
    }

    private static function validateSessionId(string $sessionId): void
    {
        if (!self::isValidUuidV4($sessionId)) {
            throw new CustomStudySessionStateException(
                'invalid_session_id',
                'session_id must be a valid UUID v4.'
            );
        }
    }

    private static function validateTimestamps(int $issuedAt, int $expiresAt): void
    {
        if ($issuedAt < 1) {
            throw new CustomStudySessionStateException(
                'invalid_issued_at',
                'issued_at must be a positive integer (UTC Unix seconds).'
            );
        }
        if ($expiresAt < 1) {
            throw new CustomStudySessionStateException(
                'invalid_expires_at',
                'expires_at must be a positive integer (UTC Unix seconds).'
            );
        }
        if ($expiresAt <= $issuedAt) {
            throw new CustomStudySessionStateException(
                'invalid_expiry',
                'expires_at must be greater than issued_at.'
            );
        }
    }

    private static function validateMode(string $mode): void
    {
        if (!in_array($mode, CustomStudyCriteria::ALLOWED_MODES, true)) {
            throw new CustomStudySessionStateException(
                'unknown_mode',
                'Unknown Custom Study criteria mode: ' . $mode
            );
        }
    }

    /** @param array<string, mixed> $parameters */
    private static function validateParametersForMode(string $mode, array $parameters): void
    {
        // Delegate to CustomStudyCriteria::fromArray which validates parameters per mode.
        // If it throws CustomStudyValidationException, translate to CustomStudySessionStateException.
        try {
            CustomStudyCriteria::fromArray([
                'mode' => $mode,
                'parameters' => $parameters,
            ]);
        } catch (CustomStudyValidationException) {
            throw new CustomStudySessionStateException(
                'invalid_parameters',
                'Invalid parameters for mode: ' . $mode
            );
        }
    }

    /**
     * @param list<int> $candidateIds
     * @return list<int>
     */
    private static function validateCandidateIds(array $candidateIds): array
    {
        if (count($candidateIds) > self::MAX_CANDIDATE_COUNT) {
            throw new CustomStudySessionStateException(
                'too_many_candidates',
                'ordered_candidate_ids must not exceed ' . self::MAX_CANDIDATE_COUNT . ' entries.'
            );
        }

        $seen = [];
        foreach ($candidateIds as $id) {
            if (!is_int($id)) {
                throw new CustomStudySessionStateException(
                    'invalid_candidate_id',
                    'ordered_candidate_ids must contain only integers.'
                );
            }
            if ($id <= 0) {
                throw new CustomStudySessionStateException(
                    'invalid_candidate_id',
                    'ordered_candidate_ids must contain only positive integers.'
                );
            }
            if (isset($seen[$id])) {
                throw new CustomStudySessionStateException(
                    'duplicate_candidate_id',
                    'ordered_candidate_ids must not contain duplicates.'
                );
            }
            $seen[$id] = true;
        }

        return array_values($candidateIds);
    }

    /**
     * @param array<int, mixed> $ids
     * @return list<int>
     */
    private static function validateIdList(array $ids, string $fieldName): array
    {
        $result = [];
        $seen = [];
        foreach ($ids as $id) {
            if (!is_int($id)) {
                throw new CustomStudySessionStateException(
                    'invalid_id_list',
                    $fieldName . ' must contain only integers.'
                );
            }
            if ($id <= 0) {
                throw new CustomStudySessionStateException(
                    'invalid_id_list',
                    $fieldName . ' must contain only positive integers.'
                );
            }
            if (isset($seen[$id])) {
                throw new CustomStudySessionStateException(
                    'duplicate_id_in_list',
                    $fieldName . ' must not contain duplicates.'
                );
            }
            $seen[$id] = true;
            $result[] = $id;
        }
        return $result;
    }

    /**
     * @param array<int, mixed> $queue
     * @return list<array{card_id: int, available_at: int}>
     */
    private static function validateDelayedRepeatQueue(array $queue): array
    {
        $result = [];
        $seenCardIds = [];
        foreach ($queue as $item) {
            if (!is_array($item)) {
                throw new CustomStudySessionStateException(
                    'invalid_delayed_item',
                    'delayed_repeat_queue items must be arrays.'
                );
            }
            if (!array_key_exists('card_id', $item)) {
                throw new CustomStudySessionStateException(
                    'invalid_delayed_item',
                    'delayed_repeat_queue items must contain card_id.'
                );
            }
            if (!array_key_exists('available_at', $item)) {
                throw new CustomStudySessionStateException(
                    'invalid_delayed_item',
                    'delayed_repeat_queue items must contain available_at.'
                );
            }
            $cardId = $item['card_id'];
            $availableAt = $item['available_at'];
            if (!is_int($cardId) || $cardId <= 0) {
                throw new CustomStudySessionStateException(
                    'invalid_delayed_item',
                    'delayed_repeat_queue card_id must be a positive integer.'
                );
            }
            if (!is_int($availableAt) || $availableAt < 1) {
                throw new CustomStudySessionStateException(
                    'invalid_delayed_item',
                    'delayed_repeat_queue available_at must be a positive integer (UTC Unix seconds).'
                );
            }
            if (isset($seenCardIds[$cardId])) {
                throw new CustomStudySessionStateException(
                    'duplicate_delayed_card_id',
                    'delayed_repeat_queue must not contain duplicate card_ids.'
                );
            }
            $seenCardIds[$cardId] = true;
            $result[] = ['card_id' => $cardId, 'available_at' => $availableAt];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function validatePreviewDelayConfig(array $config): void
    {
        foreach (self::REQUIRED_DELAY_KEYS as $key) {
            if (!array_key_exists($key, $config)) {
                throw new CustomStudySessionStateException(
                    'invalid_delay_config',
                    'preview_delay_config must contain key: ' . $key
                );
            }
            if (!is_int($config[$key])) {
                throw new CustomStudySessionStateException(
                    'invalid_delay_config',
                    'preview_delay_config.' . $key . ' must be an integer.'
                );
            }
            if ($config[$key] < 0) {
                throw new CustomStudySessionStateException(
                    'invalid_delay_config',
                    'preview_delay_config.' . $key . ' must be non-negative.'
                );
            }
        }
    }

    /**
     * Validates available_candidate_count against invariants 16 and 17.
     *
     * Invariant 16: available_candidate_count >= 0.
     * Invariant 17: available_candidate_count >= total_count.
     *
     * Task 2000-22 — Phase 4B.
     */
    private static function validateAvailableCandidateCount(int $availableCandidateCount, int $totalCount): void
    {
        if ($availableCandidateCount < 0) {
            throw new CustomStudySessionStateException(
                'invalid_available_candidate_count',
                'available_candidate_count must be a non-negative integer.'
            );
        }
        if ($availableCandidateCount < $totalCount) {
            throw new CustomStudySessionStateException(
                'invalid_available_candidate_count',
                'available_candidate_count must be >= total_count (' . $totalCount . ').'
            );
        }
    }

    /**
     * Validates five-state union + mutual exclusion invariants.
     *
     * @param list<int> $orderedCandidateIds
     * @param list<int> $readyQueue
     * @param list<array{card_id: int, available_at: int}> $delayedRepeatQueue
     * @param list<int> $completedIds
     * @param list<int> $skippedIneligibleIds
     */
    private static function validateFiveStateInvariants(
        array $orderedCandidateIds,
        ?int $currentCardId,
        array $readyQueue,
        array $delayedRepeatQueue,
        array $completedIds,
        array $skippedIneligibleIds
    ): void {
        $orderedSet = [];
        foreach ($orderedCandidateIds as $id) {
            $orderedSet[$id] = true;
        }

        // Collect all IDs from the five states
        $allStateIds = [];
        $stateLabels = [];

        // current
        if ($currentCardId !== null) {
            $allStateIds[] = $currentCardId;
            $stateLabels[] = 'current';
        }

        // ready
        foreach ($readyQueue as $id) {
            $allStateIds[] = $id;
            $stateLabels[] = 'ready';
        }

        // delayed
        foreach ($delayedRepeatQueue as $item) {
            $allStateIds[] = $item['card_id'];
            $stateLabels[] = 'delayed';
        }

        // completed
        foreach ($completedIds as $id) {
            $allStateIds[] = $id;
            $stateLabels[] = 'completed';
        }

        // skipped_ineligible
        foreach ($skippedIneligibleIds as $id) {
            $allStateIds[] = $id;
            $stateLabels[] = 'skipped_ineligible';
        }

        // Check mutual exclusion: no ID appears in two states
        $seenIds = [];
        $idToLabel = [];
        for ($i = 0; $i < count($allStateIds); $i++) {
            $id = $allStateIds[$i];
            $label = $stateLabels[$i];
            if (isset($seenIds[$id])) {
                throw new CustomStudySessionStateException(
                    'state_overlap',
                    'Card ' . $id . ' appears in both ' . $idToLabel[$id] . ' and ' . $label . ' states.'
                );
            }
            $seenIds[$id] = true;
            $idToLabel[$id] = $label;
        }

        // Check that every state ID is a member of ordered_candidate_ids
        foreach ($seenIds as $id => $_) {
            if (!isset($orderedSet[$id])) {
                throw new CustomStudySessionStateException(
                    'unknown_state_id',
                    'Card ' . $id . ' appears in state but not in ordered_candidate_ids.'
                );
            }
        }

        // Check that no ordered ID is lost (every ordered ID must appear in exactly one state)
        foreach ($orderedCandidateIds as $id) {
            if (!isset($seenIds[$id])) {
                throw new CustomStudySessionStateException(
                    'lost_ordered_id',
                    'Card ' . $id . ' is in ordered_candidate_ids but not in any of the five states.'
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireIntKey(array $payload, string $key): int
    {
        if (!array_key_exists($key, $payload)) {
            throw new CustomStudySessionStateException(
                'missing_key',
                'Missing required key: ' . $key
            );
        }
        $value = $payload[$key];
        if (!is_int($value)) {
            throw new CustomStudySessionStateException(
                'invalid_type',
                $key . ' must be an integer.'
            );
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireStringKey(array $payload, string $key): string
    {
        if (!array_key_exists($key, $payload)) {
            throw new CustomStudySessionStateException(
                'missing_key',
                'Missing required key: ' . $key
            );
        }
        $value = $payload[$key];
        if (!is_string($value)) {
            throw new CustomStudySessionStateException(
                'invalid_type',
                $key . ' must be a string.'
            );
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function requireArrayKey(array $payload, string $key): array
    {
        if (!array_key_exists($key, $payload)) {
            throw new CustomStudySessionStateException(
                'missing_key',
                'Missing required key: ' . $key
            );
        }
        $value = $payload[$key];
        if (!is_array($value)) {
            throw new CustomStudySessionStateException(
                'invalid_type',
                $key . ' must be an array.'
            );
        }
        return $value;
    }

    /**
     * Strict UUID v4 validation.
     *
     * UUID v4 format: xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx
     * Third group MUST start with '4' (version 4).
     * Fourth group MUST start with 8, 9, a, or b (variant 1).
     */
    private static function isValidUuidV4(string $uuid): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        ) === 1;
    }
}
