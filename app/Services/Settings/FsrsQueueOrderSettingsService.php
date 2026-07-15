<?php

namespace App\Services\Settings;

use App\Exceptions\QueueOrderValidationException;
use App\Services\ReviewQueueOrderOptions;
use App\Services\Settings\Presets\ReviewSettingsResolver;

class FsrsQueueOrderSettingsService
{
    private const KEY_MAP = [
        'interday_learning_review_order' => 'fsrs_queue_interday_learning_review_order',
        'new_review_order' => 'fsrs_queue_new_review_order',
        'review_sort_order' => 'fsrs_queue_review_sort_order',
        'new_sort_order' => 'fsrs_queue_new_sort_order',
    ];

    public function __construct(private ReviewSettingsResolver $reviewSettings)
    {
    }

    public function get(int $userId, string $language): array
    {
        return $this->loadOptions($userId, $language)->toArray();
    }

    public function update(int $userId, string $language, array $input): array
    {
        $allowedValues = [
            'interday_learning_review_order' => ReviewQueueOrderOptions::ALLOWED_INTERDAY,
            'new_review_order' => ReviewQueueOrderOptions::ALLOWED_NEW_REVIEW,
            'review_sort_order' => ReviewQueueOrderOptions::ALLOWED_REVIEW_SORT,
            'new_sort_order' => ReviewQueueOrderOptions::ALLOWED_NEW_SORT,
        ];

        $errors = [];
        foreach ($allowedValues as $apiName => $allowed) {
            if (array_key_exists($apiName, $input) && !in_array($input[$apiName], $allowed, true)) {
                $errors[$apiName] = '此值不在允许的选项中。';
            }
        }

        if ($errors !== []) {
            throw new QueueOrderValidationException($errors);
        }

        $patch = [];
        foreach (array_keys(self::KEY_MAP) as $apiName) {
            if (array_key_exists($apiName, $input)) {
                $patch[$apiName] = $input[$apiName];
            }
        }
        if ($patch !== []) {
            $this->reviewSettings->mutate($userId, $language, ['queue_order' => $patch]);
        }

        return $this->get($userId, $language);
    }

    private function loadOptions(int $userId, string $language): ReviewQueueOrderOptions
    {
        try {
            return ReviewQueueOrderOptions::fromArray(
                $this->reviewSettings->resolve($userId, $language)->queueOrderForApi()
            );
        } catch (\InvalidArgumentException) {
            return ReviewQueueOrderOptions::defaults();
        }
    }
}
