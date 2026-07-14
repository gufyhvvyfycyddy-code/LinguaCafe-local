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
 * completed_count, total_count, current_card_id, step, preview_delay_config.
 *
 * The five-state union + mutual exclusion invariants (current / ready / delayed /
 * completed / skipped_ineligible) are fully verifiable from the state alone.
 * `completed_count` is redundant by construction (=== count(completed_ids)).
 * `total_count` is redundant by construction (=== count(ordered_candidate_ids)).
 *
 * Task 2000-19 — Custom Study 1A Phase 3A.
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
        array $previewDelayConfig
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
        array $previewDelayConfig
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
            count($orderedCandidateIds),
            $currentCardId,
            0,
            $previewDelayConfig
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
            $previewDelayConfig
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
