<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Value object for validated Queue Order configuration.
 *
 * Pure data object — no DB, no Auth, no Request, no Session, no side effects.
 * Expresses the four Anki-aligned Queue Order settings defined in ADR-0015.
 */
class ReviewQueueOrderOptions
{
    // interday_learning_review_order
    public const INTERDAY_MIX = 'mix';
    public const INTERDAY_BEFORE = 'before';
    public const INTERDAY_AFTER = 'after';

    // new_review_order
    public const NEW_MIX = 'mix';
    public const NEW_BEFORE = 'before';
    public const NEW_AFTER = 'after';

    // review_sort_order
    public const REVIEW_SORT_DUE_RANDOM = 'due_random';
    public const REVIEW_SORT_DUE_STABLE = 'due_stable';
    public const REVIEW_SORT_ASCENDING_RETRIEVABILITY = 'ascending_retrievability';
    public const REVIEW_SORT_RANDOM = 'random';

    // new_sort_order
    public const NEW_SORT_CREATED_ASC = 'created_asc';
    public const NEW_SORT_CREATED_DESC = 'created_desc';
    public const NEW_SORT_RANDOM = 'random';

    // Defaults (Anki defaults mapped to LinguaCafe)
    public const DEFAULT_INTERDAY_LEARNING_REVIEW_ORDER = self::INTERDAY_MIX;
    public const DEFAULT_NEW_REVIEW_ORDER = self::NEW_MIX;
    public const DEFAULT_REVIEW_SORT_ORDER = self::REVIEW_SORT_DUE_RANDOM;
    public const DEFAULT_NEW_SORT_ORDER = self::NEW_SORT_CREATED_ASC;

    // Allowed enums
    public const ALLOWED_INTERDAY = [self::INTERDAY_MIX, self::INTERDAY_BEFORE, self::INTERDAY_AFTER];
    public const ALLOWED_NEW_REVIEW = [self::NEW_MIX, self::NEW_BEFORE, self::NEW_AFTER];
    public const ALLOWED_REVIEW_SORT = [
        self::REVIEW_SORT_DUE_RANDOM,
        self::REVIEW_SORT_DUE_STABLE,
        self::REVIEW_SORT_ASCENDING_RETRIEVABILITY,
        self::REVIEW_SORT_RANDOM,
    ];
    public const ALLOWED_NEW_SORT = [
        self::NEW_SORT_CREATED_ASC,
        self::NEW_SORT_CREATED_DESC,
        self::NEW_SORT_RANDOM,
    ];

    public readonly string $interdayLearningReviewOrder;
    public readonly string $newReviewOrder;
    public readonly string $reviewSortOrder;
    public readonly string $newSortOrder;
    public readonly string $scope;
    public readonly bool $presetSupported;

    private function __construct(
        string $interdayLearningReviewOrder,
        string $newReviewOrder,
        string $reviewSortOrder,
        string $newSortOrder
    ) {
        $this->interdayLearningReviewOrder = $interdayLearningReviewOrder;
        $this->newReviewOrder = $newReviewOrder;
        $this->reviewSortOrder = $reviewSortOrder;
        $this->newSortOrder = $newSortOrder;
        $this->scope = 'global';
        $this->presetSupported = false;
    }

    /**
     * Returns an Options instance with all default values.
     */
    public static function defaults(): self
    {
        return new self(
            self::DEFAULT_INTERDAY_LEARNING_REVIEW_ORDER,
            self::DEFAULT_NEW_REVIEW_ORDER,
            self::DEFAULT_REVIEW_SORT_ORDER,
            self::DEFAULT_NEW_SORT_ORDER
        );
    }

    /**
     * Creates an Options instance from a raw input array.
     *
     * Missing keys fall back to defaults. Invalid values throw InvalidArgumentException.
     * Unknown keys are silently ignored (callers should validate separately if needed).
     *
     * @param array $input Raw input with keys: interday_learning_review_order, new_review_order, review_sort_order, new_sort_order
     * @throws InvalidArgumentException If any value is not in the allowed enum.
     */
    public static function fromArray(array $input): self
    {
        $interday = $input['interday_learning_review_order'] ?? self::DEFAULT_INTERDAY_LEARNING_REVIEW_ORDER;
        $newReview = $input['new_review_order'] ?? self::DEFAULT_NEW_REVIEW_ORDER;
        $reviewSort = $input['review_sort_order'] ?? self::DEFAULT_REVIEW_SORT_ORDER;
        $newSort = $input['new_sort_order'] ?? self::DEFAULT_NEW_SORT_ORDER;

        if (!in_array($interday, self::ALLOWED_INTERDAY, true)) {
            throw new InvalidArgumentException("Invalid interday_learning_review_order: {$interday}");
        }
        if (!in_array($newReview, self::ALLOWED_NEW_REVIEW, true)) {
            throw new InvalidArgumentException("Invalid new_review_order: {$newReview}");
        }
        if (!in_array($reviewSort, self::ALLOWED_REVIEW_SORT, true)) {
            throw new InvalidArgumentException("Invalid review_sort_order: {$reviewSort}");
        }
        if (!in_array($newSort, self::ALLOWED_NEW_SORT, true)) {
            throw new InvalidArgumentException("Invalid new_sort_order: {$newSort}");
        }

        return new self($interday, $newReview, $reviewSort, $newSort);
    }

    /**
     * Returns the configuration as an associative array (for serialization).
     */
    public function toArray(): array
    {
        return [
            'interday_learning_review_order' => $this->interdayLearningReviewOrder,
            'new_review_order' => $this->newReviewOrder,
            'review_sort_order' => $this->reviewSortOrder,
            'new_sort_order' => $this->newSortOrder,
            'scope' => $this->scope,
            'preset_supported' => $this->presetSupported,
        ];
    }
}
