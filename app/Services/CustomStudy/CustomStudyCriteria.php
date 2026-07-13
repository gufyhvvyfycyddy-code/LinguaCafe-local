<?php

namespace App\Services\CustomStudy;

use InvalidArgumentException;

/**
 * Value object for a Custom Study criteria selection.
 *
 * Pure data object — no DB, no Auth, no Request, no Session, no side effects.
 * Expresses ONE of the four frozen Custom Study criteria modes defined in ADR-0016.
 *
 * This object ONLY carries the user's criteria choice (mode + parameters).
 * It does NOT carry user_id or language — those belong to the service call
 * context (trusted caller) and are validated by CustomStudyCriteriaValidator.
 * Malicious user_id / language keys in the raw input array are silently
 * ignored (unknown keys do not become part of the value object).
 *
 * Task CS-1 of Custom Study 1A Phase 1 (Task 2000-16).
 */
class CustomStudyCriteria
{
    // Frozen modes (ADR-0016 §6)
    public const MODE_TODAY_FORGOTTEN = 'today_forgotten';
    public const MODE_OVERDUE = 'overdue';
    public const MODE_SOURCE_CHAPTER = 'source_chapter';
    public const MODE_LEECH_ATTENTION = 'leech_attention';

    /** @var list<string> */
    public const ALLOWED_MODES = [
        self::MODE_TODAY_FORGOTTEN,
        self::MODE_OVERDUE,
        self::MODE_SOURCE_CHAPTER,
        self::MODE_LEECH_ATTENTION,
    ];

    // leech_attention sub_mode enums
    public const SUB_MODE_LEECH_ONLY = 'leech_only';
    public const SUB_MODE_LEECH_PLUS_STRUGGLING = 'leech_plus_struggling';

    /** @var list<string> */
    public const ALLOWED_SUB_MODES = [
        self::SUB_MODE_LEECH_ONLY,
        self::SUB_MODE_LEECH_PLUS_STRUGGLING,
    ];

    public readonly string $mode;

    /** @var array<string, mixed> */
    public readonly array $parameters;

    /**
     * @param string $mode
     * @param array<string, mixed> $parameters
     */
    private function __construct(string $mode, array $parameters)
    {
        $this->mode = $mode;
        $this->parameters = $parameters;
    }

    /**
     * Creates a CustomStudyCriteria from a raw input array.
     *
     * Unknown top-level keys (including malicious user_id / language / token)
     * are silently ignored — they do not become part of the value object.
     * Unknown parameter keys are also ignored; only the frozen parameter
     * schema per mode is retained.
     *
     * @param array<string, mixed> $input Raw input with keys: mode, parameters (optional)
     * @throws InvalidArgumentException If mode is unknown or required parameters are missing/invalid.
     */
    public static function fromArray(array $input): self
    {
        if (!array_key_exists('mode', $input)) {
            throw new InvalidArgumentException('Custom Study criteria missing required key: mode');
        }

        $mode = $input['mode'];
        if (!is_string($mode) || !in_array($mode, self::ALLOWED_MODES, true)) {
            throw new InvalidArgumentException("Unknown Custom Study criteria mode: " . get_debug_type($mode));
        }

        $rawParameters = $input['parameters'] ?? [];
        if (!is_array($rawParameters)) {
            throw new InvalidArgumentException('Custom Study criteria parameters must be an array');
        }

        $parameters = self::extractParametersForMode($mode, $rawParameters);

        return new self($mode, $parameters);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        // Return a copy so external modification cannot mutate internal state.
        return $this->parameters;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'parameters' => $this->parameters,
        ];
    }

    /**
     * Extracts only the frozen parameter keys for the given mode.
     *
     * @param string $mode
     * @param array<string, mixed> $rawParameters
     * @return array<string, mixed>
     * @throws InvalidArgumentException If required parameters are missing or invalid.
     */
    private static function extractParametersForMode(string $mode, array $rawParameters): array
    {
        switch ($mode) {
            case self::MODE_TODAY_FORGOTTEN:
            case self::MODE_OVERDUE:
                // No parameters required. Unknown keys ignored.
                return [];

            case self::MODE_SOURCE_CHAPTER:
                if (!array_key_exists('chapter_id', $rawParameters)) {
                    throw new InvalidArgumentException('source_chapter criteria requires parameter: chapter_id');
                }
                $chapterId = $rawParameters['chapter_id'];
                // Strict integer check — string "42" is rejected (project has no existing
                // contract that allows numeric-string coercion for criteria parameters).
                if (!is_int($chapterId)) {
                    throw new InvalidArgumentException('chapter_id must be an integer');
                }
                if ($chapterId <= 0) {
                    throw new InvalidArgumentException('chapter_id must be greater than 0');
                }
                return ['chapter_id' => $chapterId];

            case self::MODE_LEECH_ATTENTION:
                if (!array_key_exists('sub_mode', $rawParameters)) {
                    throw new InvalidArgumentException('leech_attention criteria requires parameter: sub_mode');
                }
                $subMode = $rawParameters['sub_mode'];
                if (!is_string($subMode) || !in_array($subMode, self::ALLOWED_SUB_MODES, true)) {
                    throw new InvalidArgumentException('Invalid leech_attention sub_mode: ' . get_debug_type($subMode));
                }
                return ['sub_mode' => $subMode];

            default:
                // Should never reach here because mode is validated above, but keep for safety.
                throw new InvalidArgumentException("Unknown Custom Study criteria mode: {$mode}");
        }
    }
}
